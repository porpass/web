<?php
/**
 * SchemaLoader.php — Reader for GRaSP schema artifacts (Contract A, v1.1).
 *
 * The processing subsystem never hardcodes defaults, choices, supported-stage
 * lists, or per-instrument physics. GRaSP publishes a resolved schema JSON per
 * (instrument, product); this class is the sole place the web tier reads it,
 * caches it, and hands normalised views to the form generator and Contract B
 * builder.
 *
 * File location:
 *   Production: {PORPASS_STORAGE_PATH}/schemas/{INSTRUMENT}_{PRODUCT}.schema.json
 *   Dev/no daemon: private/fixtures/schemas/{INSTRUMENT}_{PRODUCT}.schema.json
 *
 * Spaces in product names (e.g. "US RDR") are replaced with underscores in the
 * filename ("SHARAD_US_RDR.schema.json"); values on the wire keep their space.
 *
 * All methods are static. The in-memory cache is per-request and invalidates on
 * mtime change; there is no cross-request caching (no APCu dependency).
 */

namespace porpass\processing;

use RuntimeException;

class SchemaLoader
{
    /** Contract A version this loader understands. */
    public const SUPPORTED_SCHEMA_VERSION = '1.1';

    /** @var array<string, array<mixed>> Parsed schema keyed by canonical key. */
    private static array $cache = [];

    /** @var array<string, int> mtime the cache entry was populated from. */
    private static array $cacheMtime = [];

    /**
     * Load the resolved schema artifact for an (instrument, product) pair.
     *
     * @param string $instrument Instrument abbreviation (case-insensitive).
     * @param string $product    Product type (case-insensitive, spaces preserved on the wire).
     *
     * @return array<mixed> The full parsed schema.
     * @throws RuntimeException If the file is missing, unreadable, malformed,
     *                         or reports an unsupported schema_version.
     */
    public static function load(string $instrument, string $product): array
    {
        $key  = self::keyFor($instrument, $product);
        $path = self::locate($key);

        $mtime = @filemtime($path);
        if ($mtime === false) {
            throw new RuntimeException("Schema artifact for $key not found (checked: $path)");
        }

        // mtime-based cache invalidation
        if (isset(self::$cache[$key]) && (self::$cacheMtime[$key] ?? null) === $mtime) {
            return self::$cache[$key];
        }

        $raw = file_get_contents($path);
        if ($raw === false) {
            throw new RuntimeException("Failed to read schema artifact at $path");
        }

        try {
            $schema = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new RuntimeException("Malformed JSON in schema artifact at $path: " . $e->getMessage(), 0, $e);
        }

        if (!is_array($schema)) {
            throw new RuntimeException("Schema artifact at $path did not decode to an object");
        }

        $got = $schema['schema_version'] ?? '(missing)';
        if ($got !== self::SUPPORTED_SCHEMA_VERSION) {
            throw new RuntimeException(
                "Schema artifact at $path reports schema_version=$got; "
                . "this loader supports " . self::SUPPORTED_SCHEMA_VERSION
            );
        }

        // Cheap structural sanity — enough to fail fast on hand-written fixtures.
        foreach (['instrument', 'product', 'stages', 'globals'] as $required) {
            if (!array_key_exists($required, $schema)) {
                throw new RuntimeException("Schema artifact at $path is missing required key: $required");
            }
        }

        self::$cache[$key]      = $schema;
        self::$cacheMtime[$key] = $mtime;
        return $schema;
    }

    /**
     * Iterate every field across globals + stages.
     *
     * The callback receives (sectionKey, section, field) for each field in
     * document order. Used by defaultsFor() and the Phase 4 form renderer to
     * walk the schema without duplicating the globals+stages traversal.
     *
     * @param array<mixed> $schema
     * @param callable(string, array<mixed>, array<mixed>): void $fn
     */
    public static function walkFields(array $schema, callable $fn): void
    {
        foreach (['globals', 'stages'] as $group) {
            foreach ($schema[$group] ?? [] as $section) {
                if (!isset($section['key'])) {
                    continue;
                }
                foreach ($section['fields'] ?? [] as $field) {
                    if (!isset($field['name'])) {
                        continue;
                    }
                    $fn($section['key'], $section, $field);
                }
            }
        }
    }

    /**
     * Return every section (globals + stages) in document order, tagged with
     * its group so callers can distinguish "always-on" globals from stage
     * pipeline members. Preserves the order from the artifact.
     *
     * @param array<mixed> $schema
     * @return array<int, array{group: string, section: array<mixed>}>
     */
    public static function sections(array $schema): array
    {
        $out = [];
        foreach (['globals', 'stages'] as $group) {
            foreach ($schema[$group] ?? [] as $section) {
                if (!isset($section['key'])) {
                    continue;
                }
                $out[] = ['group' => $group, 'section' => $section];
            }
        }
        return $out;
    }

    /**
     * Build the full defaults map keyed by section → field for a schema.
     *
     * Fields with `default: null` and a `default_note` are omitted — the daemon
     * fills those at runtime, and "not submitted" is a valid state. This is the
     * baseline that Contract B is diffed against: any submitted value equal to
     * an entry here is dropped from `overrides`.
     *
     * Uses effectiveDefault() so the entry reflects the value the form will
     * actually present, not the raw declared default that may not appear in
     * the field's choice set.
     *
     * @param array<mixed> $schema
     * @return array<string, array<string, mixed>>
     */
    public static function defaultsFor(array $schema): array
    {
        $defaults = [];
        self::walkFields($schema, function (string $key, array $section, array $field) use (&$defaults): void {
            if (!array_key_exists('default', $field)) {
                return;
            }
            if ($field['default'] === null) {
                // null default with default_note ⇒ daemon-computed; omit.
                return;
            }
            $defaults[$key][$field['name']] = self::effectiveDefault($field);
        });
        return $defaults;
    }

    /**
     * Resolve a field's default to the value the form will actually present.
     *
     * For non-enum fields this is the raw declared default. For enum fields
     * the declared default sometimes does not match any choice value verbatim
     * (e.g. `default: "big"` with choices `["BIG", "LITTLE"]` and no
     * `alias_of` bridging them). Rather than reject the schema, we resolve
     * to the value the user's browser will submit:
     *
     *   1. Exact match against a choice value → return that (canonical form).
     *   2. Case-insensitive string match → return that choice's canonical form.
     *   3. Fall back to the first ui:true choice's value.
     *   4. If no ui:true choices exist, return the raw default.
     *
     * Callers should treat this as the authoritative baseline for diffing.
     */
    public static function effectiveDefault(array $field): mixed
    {
        $default = $field['default'] ?? null;
        if ($default === null || ($field['type'] ?? '') !== 'enum') {
            return $default;
        }

        $choices = $field['choices'] ?? [];

        // Direct match. Return the canonical form so submitted values (also
        // canonicalised) can be compared with strict equality.
        foreach ($choices as $c) {
            if (($c['value'] ?? null) === $default) {
                return $c['alias_of'] ?? $c['value'];
            }
        }

        // Case-insensitive string match.
        if (is_string($default)) {
            foreach ($choices as $c) {
                $v = $c['value'] ?? null;
                if (is_string($v) && strcasecmp($v, $default) === 0) {
                    return $c['alias_of'] ?? $v;
                }
            }
        }

        // First ui:true choice — matches what the browser will present when
        // the raw default isn't reachable from the visible options.
        foreach ($choices as $c) {
            if (!empty($c['ui'])) {
                return $c['value'];
            }
        }

        return $default;
    }

    /**
     * Enum choices to show in a dropdown: `ui: true` only, in array order.
     *
     * @param array<mixed> $field
     * @return array<int, array<mixed>>
     */
    public static function visibleUiChoices(array $field): array
    {
        $choices = $field['choices'] ?? [];
        $out = [];
        foreach ($choices as $c) {
            if (!empty($c['ui'])) {
                $out[] = $c;
            }
        }
        return $out;
    }

    /**
     * Full set of legal values for an enum field, including `ui: false`
     * aliases. Use this to validate submissions: users only pick from ui:true
     * choices, but imported TOML may present canonical or aliased values that
     * must still be accepted.
     *
     * @param array<mixed> $field
     * @return array<int, mixed>
     */
    public static function validValueSet(array $field): array
    {
        $choices = $field['choices'] ?? [];
        return array_map(static fn(array $c) => $c['value'] ?? null, $choices);
    }

    /**
     * Normalise an enum value to its canonical form.
     *
     * If the choice has an `alias_of`, returns the alias target; otherwise
     * returns the value itself. Returns null when the value is not in the
     * choice set (caller should treat that as a validation failure).
     *
     * @param array<mixed> $field
     */
    public static function canonicalizeChoice(array $field, mixed $value): mixed
    {
        foreach ($field['choices'] ?? [] as $c) {
            if (($c['value'] ?? null) === $value) {
                return $c['alias_of'] ?? $c['value'];
            }
        }
        return null;
    }

    /**
     * Evaluate a field's `visible_when` clause against the values submitted
     * in its own section.
     *
     * Fields without `visible_when` are always visible. A field whose
     * `visible_when.field` sibling has not been submitted is considered
     * hidden (the trigger has no established value).
     *
     * @param array<mixed>         $field
     * @param array<string, mixed> $sectionValues Submitted values for this section.
     */
    public static function isFieldVisible(array $field, array $sectionValues): bool
    {
        if (!isset($field['visible_when'])) {
            return true;
        }
        $ref     = $field['visible_when'];
        $sibling = $ref['field']  ?? null;
        $equals  = $ref['equals'] ?? null;
        if ($sibling === null || !array_key_exists($sibling, $sectionValues)) {
            return false;
        }
        return $sectionValues[$sibling] === $equals;
    }

    /**
     * Evaluate a section's `disables` clauses against submitted values,
     * returning the set of stage keys that are currently disabled.
     *
     * The web must not emit overrides for a disabled stage, and the form
     * should render its inputs as disabled. `disables` is stage-level; the
     * `when` clause references a field within the same section as the
     * `disables` entry.
     *
     * @param array<mixed>         $section
     * @param array<string, mixed> $sectionValues
     * @return array<int, string> Disabled stage keys.
     */
    public static function disabledStages(array $section, array $sectionValues): array
    {
        $disabled = [];
        foreach ($section['disables'] ?? [] as $rule) {
            $when    = $rule['when']   ?? [];
            $sibling = $when['field']  ?? null;
            $equals  = $when['equals'] ?? null;
            if ($sibling === null || !array_key_exists($sibling, $sectionValues)) {
                continue;
            }
            if ($sectionValues[$sibling] === $equals) {
                foreach ($rule['stages'] ?? [] as $stageKey) {
                    $disabled[$stageKey] = true;
                }
            }
        }
        return array_keys($disabled);
    }

    /**
     * Reset the in-memory cache. Test hook — never call from production code.
     */
    public static function resetCache(): void
    {
        self::$cache      = [];
        self::$cacheMtime = [];
    }

    // ── Internal ───────────────────────────────────────────────────────────

    /**
     * Canonical cache/file key: uppercase instrument + underscore + product
     * with spaces normalised to underscores.
     *
     * Examples:
     *   ('sharad', 'EDR')     => 'SHARAD_EDR'
     *   ('SHARAD', 'US RDR')  => 'SHARAD_US_RDR'
     */
    private static function keyFor(string $instrument, string $product): string
    {
        $inst = strtoupper(trim($instrument));
        $prod = strtoupper(trim(str_replace(' ', '_', $product)));
        return "{$inst}_{$prod}";
    }

    /**
     * Resolve the on-disk path for a schema key. Prefers PORPASS_STORAGE_PATH
     * from the environment; falls back to the bundled dev fixtures directory.
     */
    private static function locate(string $key): string
    {
        $filename = "{$key}.schema.json";
        $storage  = $_ENV['PORPASS_STORAGE_PATH'] ?? '';
        if ($storage !== '') {
            $candidate = rtrim($storage, '/') . '/schemas/' . $filename;
            if (is_file($candidate)) {
                return $candidate;
            }
        }
        return dirname(__DIR__, 2) . '/private/fixtures/schemas/' . $filename;
    }
}
