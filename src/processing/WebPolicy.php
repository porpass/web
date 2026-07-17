<?php
/**
 * WebPolicy.php — PORPASS-specific overrides on top of the GRaSP schema.
 *
 * The GRaSP schema is authoritative for what CAN be configured; this class
 * decides what the PORPASS web application chooses to expose. Anything hidden
 * here is either handled automatically by the daemon (out_dir), fixed by
 * project convention (verbose), or considered too low-level to expose to
 * end users (dem_path, target, sgn).
 *
 * All entries are keyed by section → field so they map 1:1 to the sparse
 * Contract B structure and are easy to audit. Keep this file the single
 * source of "what the web decides"; do NOT sprinkle overrides through the
 * render / submit code.
 */

namespace porpass\processing;

class WebPolicy
{
    /**
     * Maximum number of observations one submission may configure as a batch.
     * Enforced client-side as UX (grey out the "Configure selected" button)
     * and server-side as a hard gate (refuse the POST). Raise here when the
     * usage pattern demands it.
     */
    public const BATCH_MAX_SIZE = 10;

    /**
     * Sections not rendered in the form and stripped from any resulting
     * config. Users cannot influence them; the daemon uses schema defaults
     * (or injected values in the case of out_dir).
     */
    public const HIDDEN_SECTIONS = ['general'];

    /**
     * Fields not rendered inside otherwise-visible sections. Same treatment
     * as HIDDEN_SECTIONS: no widget, no override entry, no submission.
     */
    public const HIDDEN_FIELDS = [
        'output_parameters'        => ['data_output_type', 'byte_order'],
        'preprocessing'            => ['n_center'],
        'ionospheric_compensation' => ['sgn'],
        'sar_processing'           => ['sgn', 'interp_cval'],
        'clutter_simulation'       => ['dem_path', 'target', 'bin_size', 'n_samples', 'n_center'],
    ];

    /**
     * Web-side defaults that override the schema default for form rendering.
     * Only affects the pre-selected value; the submitted value goes through
     * ConfigBuilder's normal diff-against-schema-default flow, so if the web
     * default differs from the schema default it will appear in overrides.
     */
    public const DEFAULT_OVERRIDES = [
        'plot_parameters' => [
            'cmap' => 'gray',
        ],
    ];

    /**
     * Sibling-dependent defaults. When a sibling in the same section has a
     * given value, the field's default becomes the matching entry. Applied
     * both server-side (initial render) and client-side (when the sibling
     * changes). Format: [section][field] => [[sibling, value, default], ...].
     */
    public const CONDITIONAL_DEFAULTS = [
        'ionospheric_compensation' => [
            'metric' => [
                ['method', 'CAMPBELL', 'PEAK_SNR'],
                ['method', 'CONTRAST', 'L4'],
            ],
        ],
    ];

    /**
     * Custom placeholder text shown when a field has no value. Used to add
     * short-form hints ("PROJ4 string required") that don't come from the
     * schema. Overrides the schema's `default_note` if both are present.
     */
    public const PLACEHOLDER_OVERRIDES = [
        'output_parameters' => [
            'tgt_crs' => 'PROJ4 string required',
        ],
    ];

    /**
     * Web-side `visible_when` equivalents: field only shown when a sibling
     * has the specified value. Same shape as the schema's own visible_when.
     * Used when the constraint is a PORPASS policy decision rather than
     * something GRaSP already encodes.
     *
     * If a field already has a schema-level visible_when, the schema wins
     * (single-rule model — no AND merging).
     *
     * @var array<string, array<string, array{field: string, equals: mixed}>>
     */
    public const CONDITIONAL_VISIBILITY = [
        'sar_processing' => [
            // `coherent` only applies to the unfocused processor.
            'coherent' => ['field' => 'method', 'equals' => 'UNFOCUSED'],
        ],
    ];

    // ── Query helpers ─────────────────────────────────────────────────────

    public static function isSectionHidden(string $key): bool
    {
        return in_array($key, self::HIDDEN_SECTIONS, true);
    }

    public static function isFieldHidden(string $sectionKey, string $fieldName): bool
    {
        return in_array($fieldName, self::HIDDEN_FIELDS[$sectionKey] ?? [], true);
    }

    /**
     * Returns the web-side default for a field, or null if none is configured.
     * Does NOT fall back to the schema default — callers should combine this
     * with SchemaLoader::effectiveDefault().
     */
    public static function defaultOverride(string $sectionKey, string $fieldName): mixed
    {
        return self::DEFAULT_OVERRIDES[$sectionKey][$fieldName] ?? null;
    }

    /**
     * Evaluate conditional-default rules for a field against the current
     * values in its section. Returns the matched default, or null if no
     * rule fires (or none are configured).
     *
     * @param array<string, mixed> $sectionValues Current values in the section.
     */
    public static function conditionalDefault(string $sectionKey, string $fieldName, array $sectionValues): mixed
    {
        $rules = self::CONDITIONAL_DEFAULTS[$sectionKey][$fieldName] ?? [];
        foreach ($rules as [$sibling, $value, $default]) {
            $actual = $sectionValues[$sibling] ?? null;
            if ($actual !== null && (string) $actual === (string) $value) {
                return $default;
            }
        }
        return null;
    }

    public static function placeholderOverride(string $sectionKey, string $fieldName): ?string
    {
        return self::PLACEHOLDER_OVERRIDES[$sectionKey][$fieldName] ?? null;
    }

    /**
     * Web-side visible_when rule for a field, or null. Callers should prefer
     * the schema's own visible_when when both are set; see
     * effective_visible_when() in processing_configure.php.
     *
     * @return array{field: string, equals: mixed}|null
     */
    public static function conditionalVisibility(string $sectionKey, string $fieldName): ?array
    {
        return self::CONDITIONAL_VISIBILITY[$sectionKey][$fieldName] ?? null;
    }

    /**
     * Client-side payload of conditional-default rules for a field, ready to
     * embed in a `data-conditional-default` attribute. Returns null when no
     * rules apply. The shape is:
     *
     *   {"field":"method","cases":{"CAMPBELL":"PEAK_SNR","CONTRAST":"L4"}}
     */
    public static function conditionalDefaultDataAttr(string $sectionKey, string $fieldName): ?string
    {
        $rules = self::CONDITIONAL_DEFAULTS[$sectionKey][$fieldName] ?? [];
        if (empty($rules)) {
            return null;
        }
        $siblingField = $rules[0][0];
        $cases = [];
        foreach ($rules as [$sibling, $value, $default]) {
            if ($sibling !== $siblingField) {
                // Multi-sibling rules aren't supported client-side; skip.
                continue;
            }
            $cases[(string) $value] = $default;
        }
        return json_encode(['field' => $siblingField, 'cases' => $cases]);
    }

    /**
     * Strip hidden sections and hidden fields from a built config's overrides
     * map. Safety net: even if a bug ever puts something under a hidden key,
     * this ensures nothing hidden leaks into the daemon-facing JSON.
     *
     * @param array<mixed> $config
     * @return array<mixed>
     */
    public static function purgeHidden(array $config): array
    {
        if (!isset($config['overrides']) || !is_array($config['overrides'])) {
            return $config;
        }

        foreach (self::HIDDEN_SECTIONS as $sec) {
            unset($config['overrides'][$sec]);
        }
        foreach (self::HIDDEN_FIELDS as $sec => $fields) {
            if (!isset($config['overrides'][$sec])) {
                continue;
            }
            foreach ($fields as $f) {
                unset($config['overrides'][$sec][$f]);
            }
            if (empty($config['overrides'][$sec])) {
                unset($config['overrides'][$sec]);
            }
        }
        return $config;
    }
}
