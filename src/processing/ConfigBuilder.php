<?php
/**
 * ConfigBuilder.php — Assemble a Contract B config from a submitted form.
 *
 * Takes a schema artifact (Contract A v1.1) plus the raw submitted values map
 * and produces:
 *   - a sparse `overrides` map that omits fields whose value equals the
 *     schema default, hidden fields (visible_when), fields in unsupported
 *     stages, and fields in stages disabled by another stage's config;
 *   - a full Contract B document wrapping that map with the required
 *     metadata (config_version, schema_version, grasp_version,
 *     instrument, product, observation).
 *
 * All submitted values are coerced to the field's declared type and
 * validated against the full choice set for enums (ui:true + ui:false
 * aliases), so imported TOML values round-trip cleanly. Enum values are
 * canonicalised via `alias_of` so what lands in storage is the schema's
 * canonical form even when the user or an import used an alias.
 *
 * The class is intentionally decoupled from the DB, HTTP, and $_POST —
 * callers hand it plain arrays. This keeps the submission flow testable
 * and lets the same builder power future non-web submission paths
 * (CLI, API) without change.
 */

namespace porpass\processing;

use InvalidArgumentException;

class ConfigBuilder
{
    /** Contract B version this builder emits. */
    public const CONFIG_VERSION = '1.0';

    private array $schema;

    /** @var array<string, array<string, mixed>> Precomputed defaults map. */
    private array $defaults;

    /**
     * @param array<mixed> $schema Parsed Contract A artifact.
     */
    public function __construct(array $schema)
    {
        $this->schema   = $schema;
        $this->defaults = SchemaLoader::defaultsFor($schema);
    }

    /**
     * Build a full Contract B document ready to store on the job row and
     * write to config.json.
     *
     * @param array{instrument_id:int, native_id:string} $observation
     * @param array<string, array<string, mixed>>        $submitted   Raw
     *        [sectionKey => [fieldName => value]] captured from the form.
     *
     * @return array<string, mixed>
     *
     * @throws InvalidArgumentException On any invalid value.
     */
    public function buildConfig(array $observation, array $submitted): array
    {
        return [
            'config_version' => self::CONFIG_VERSION,
            'schema_version' => $this->schema['schema_version'] ?? SchemaLoader::SUPPORTED_SCHEMA_VERSION,
            'grasp_version'  => $this->schema['grasp_version']  ?? null,
            'instrument'     => $this->schema['instrument']     ?? null,
            'product'        => $this->schema['product']        ?? null,
            'observation'    => [
                'instrument_id' => (int)   $observation['instrument_id'],
                'native_id'     => (string) $observation['native_id'],
            ],
            'overrides'      => $this->buildOverrides($submitted),
        ];
    }

    /**
     * Build only the sparse overrides map. Extracted so tests and future
     * batch-import paths can exercise the diff logic without touching the
     * outer envelope.
     *
     * @param array<string, array<string, mixed>> $submitted
     * @return array<string, array<string, mixed>>
     *
     * @throws InvalidArgumentException
     */
    public function buildOverrides(array $submitted): array
    {
        $overrides = [];

        // First pass: per-section, per-field diff-against-default.
        foreach (SchemaLoader::sections($this->schema) as $meta) {
            $section = $meta['section'];
            $key     = $section['key'];

            // Unsupported stages: their fields can't run; nothing to override.
            if (array_key_exists('supported', $section) && $section['supported'] === false) {
                continue;
            }

            $sectionValues = $submitted[$key] ?? [];

            foreach ($section['fields'] ?? [] as $field) {
                if (!isset($field['name'])) {
                    continue;
                }
                // A field hidden by visible_when is never emitted.
                if (!SchemaLoader::isFieldVisible($field, $sectionValues)) {
                    continue;
                }

                $result = $this->processField($key, $field, $sectionValues);
                if ($result !== self::OMIT) {
                    $overrides[$key][$field['name']] = $result;
                }
            }
        }

        // Second pass: drop overrides for any stage that is disabled by
        // another stage's `disables` rule under the CURRENT submission.
        // (Cross-section, so it must run after all sections are visited.)
        foreach (SchemaLoader::sections($this->schema) as $meta) {
            if ($meta['group'] !== 'stages') {
                continue;
            }
            $section       = $meta['section'];
            $sectionValues = $submitted[$section['key']] ?? [];
            foreach (SchemaLoader::disabledStages($section, $sectionValues) as $disabledKey) {
                unset($overrides[$disabledKey]);
            }
        }

        return $overrides;
    }

    /** Sentinel returned by processField() when the field should be omitted. */
    private const OMIT = "\x00__CONFIGBUILDER_OMIT__\x00";

    /**
     * Coerce, validate, canonicalise, and diff a single submitted field.
     *
     * Returns the value to store in `overrides`, or `self::OMIT` if the
     * field should be dropped (default match, or absent-with-null-default).
     *
     * @param array<mixed>         $field
     * @param array<string, mixed> $sectionValues
     *
     * @return mixed
     * @throws InvalidArgumentException
     */
    private function processField(string $sectionKey, array $field, array $sectionValues): mixed
    {
        $name         = $field['name'];
        $type         = $field['type'] ?? 'str';
        $submittedRaw = $sectionValues[$name] ?? null;
        $hasSubmitted = array_key_exists($name, $sectionValues)
                        && $submittedRaw !== null
                        && $submittedRaw !== '';

        $hasDefaultKey = array_key_exists('default', $field);
        // Compare against the effective default (the value the form actually
        // presents to the user), not the raw declared default. Schema quirks
        // where the raw default isn't in the choice set would otherwise cause
        // bogus overrides for values the user didn't touch. Coerce to the
        // field's declared type so a float-typed field with an integer JSON
        // literal default (`"default": 0` for a `type: float`) still compares
        // equal to the submitted 0.0.
        $default       = $hasDefaultKey
            ? $this->coerceToType($field, SchemaLoader::effectiveDefault($field))
            : null;

        // Null-default field: only emit if user entered something.
        if ($hasDefaultKey && $default === null) {
            if (!$hasSubmitted) {
                return self::OMIT;
            }
            return $this->coerceAndValidate($sectionKey, $field, $submittedRaw);
        }

        // Bool: absent checkbox = false; present = true.
        if ($type === 'bool') {
            $value = $hasSubmitted;
            if ($hasDefaultKey && $value === (bool) $default) {
                return self::OMIT;
            }
            return $value;
        }

        // Any other type: an absent submission for a non-null default
        // means the user left it as-is → default match → omit.
        if (!$hasSubmitted) {
            return self::OMIT;
        }

        $value = $this->coerceAndValidate($sectionKey, $field, $submittedRaw);

        if ($hasDefaultKey && $value === $default) {
            return self::OMIT;
        }
        return $value;
    }

    /**
     * Coerce a raw value to the field's declared type. Used for both submitted
     * values and defaults so their PHP types line up when compared. Non-numeric
     * inputs for numeric-typed fields fall through unchanged so the subsequent
     * validation can throw a legible error.
     *
     * @param array<mixed> $field
     */
    private function coerceToType(array $field, mixed $raw): mixed
    {
        if ($raw === null) {
            return null;
        }
        return match ($field['type'] ?? 'str') {
            'bool'  => (bool) $raw,
            'int'   => is_numeric($raw) ? (int)   $raw : $raw,
            'float' => is_numeric($raw) ? (float) $raw : $raw,
            'str'   => (string) $raw,
            'enum'  => $this->coerceEnumRaw($field, $raw),
            default => $raw,
        };
    }

    /**
     * Type-coerce, canonicalise (enums), and validate a single value.
     *
     * @param array<mixed> $field
     * @throws InvalidArgumentException
     */
    private function coerceAndValidate(string $sectionKey, array $field, mixed $raw): mixed
    {
        $name = $field['name'];
        $type = $field['type'] ?? 'str';

        $value = $this->coerceToType($field, $raw);

        if ($type === 'enum') {
            $legal = SchemaLoader::validValueSet($field);
            if (!in_array($value, $legal, true)) {
                throw new InvalidArgumentException(
                    "Invalid choice for {$sectionKey}.{$name}: "
                    . var_export($raw, true)
                );
            }
            $canonical = SchemaLoader::canonicalizeChoice($field, $value);
            return $canonical ?? $value;
        }

        // Numeric range / type shape checks.
        if ($type === 'int' && !is_int($value)) {
            throw new InvalidArgumentException(
                "{$sectionKey}.{$name} must be an integer; got "
                . var_export($raw, true)
            );
        }
        if ($type === 'float' && !is_float($value) && !is_int($value)) {
            throw new InvalidArgumentException(
                "{$sectionKey}.{$name} must be a number; got "
                . var_export($raw, true)
            );
        }

        return $value;
    }

    /**
     * Coerce a raw enum submission to the field's declared value type.
     * Numeric-valued enums (`value_type: int`) become ints so array
     * equality against the choice set works even when the browser
     * submits "1" as a string.
     */
    private function coerceEnumRaw(array $field, mixed $raw): mixed
    {
        $valueType = $field['value_type'] ?? 'str';
        if ($valueType === 'int' && is_numeric($raw)) {
            return (int) $raw;
        }
        return $raw;
    }
}
