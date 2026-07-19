<?php
/**
 * processing_configure.php — Configure and submit / edit a processing job.
 *
 * Three modes, selected by query parameter (mutually exclusive):
 *
 *   ?queue_id=N        — CREATE (single): render the form for one queued
 *                        observation; on POST create a single-job batch.
 *
 *   ?queue_ids=1,2,3   — CREATE (batch): all selected observations must
 *                        share (instrument, product) and count <=
 *                        WebPolicy::BATCH_MAX_SIZE. Renders the form once
 *                        against the shared schema; on POST creates one
 *                        batch with N job rows, all sharing the same
 *                        sparse config (overrides). Wrapped in a DB
 *                        transaction — filesystem writes that fail
 *                        rollback the DB inserts and clean up dirs so
 *                        the batch is all-or-nothing.
 *
 *   ?job_id=N          — EDIT: load an existing queued job's saved
 *                        config, prefill the form with its values; on
 *                        POST UPDATE the config (row + on-disk
 *                        config.json). Refuses if the job is no longer
 *                        queued (daemon-claimed) or not owned by the user.
 *
 * All schema-driven behaviour (defaults, choices, supported stages,
 * visible_when, disables) is read from the artifact; nothing here is
 * hardcoded per instrument or product.
 */

require_once __DIR__ . '/../src/auth.php';
require_once __DIR__ . '/../src/db.php';
require_once __DIR__ . '/../src/layout.php';

use porpass\processing\ConfigBuilder;
use porpass\processing\JobRepository;
use porpass\processing\QueueRepository;
use porpass\processing\SchemaLoader;
use porpass\processing\WebPolicy;

session_start_secure();
require_login();

$db      = get_db();
$user_id = (int) $_SESSION['user_id'];

// ── Resolve mode: batch create (queue_ids), single create (queue_id), or edit (job_id) ──
$queue_id     = (int) ($_GET['queue_id'] ?? $_POST['queue_id'] ?? 0);
$job_id       = (int) ($_GET['job_id']   ?? $_POST['job_id']   ?? 0);
$queue_ids_in = $_GET['queue_ids'] ?? $_POST['queue_ids'] ?? '';

/** Parse comma-separated or array queue_ids into a deduped positive-int list. */
$queue_ids = [];
if (is_string($queue_ids_in) && $queue_ids_in !== '') {
    $queue_ids = array_values(array_unique(array_filter(
        array_map('intval', explode(',', $queue_ids_in)),
        static fn(int $x) => $x > 0
    )));
} elseif (is_array($queue_ids_in)) {
    $queue_ids = array_values(array_unique(array_filter(
        array_map('intval', $queue_ids_in),
        static fn(int $x) => $x > 0
    )));
}
$is_batch = !empty($queue_ids);
$is_edit  = $job_id > 0;

if (!$is_batch && !$is_edit && $queue_id <= 0) {
    $_SESSION['flash'] = ['kind' => 'danger', 'msg' => 'Missing queue_id, queue_ids, or job_id.'];
    header('Location: /processing.php');
    exit;
}

$queue_repo = new QueueRepository($db);
$job_repo   = new JobRepository($db);

// Observation display info in a shape all branches produce.
//   $item  — single observation (single create / edit modes)
//   $items — multiple observations (batch mode); in that mode $item is the
//            first entry (used for schema loading and single-obs code paths).
$item            = null;
$items           = [];
$existing_config = null;

if ($is_batch) {
    $items = $queue_repo->itemsByIds($user_id, $queue_ids);
    if (empty($items)) {
        $_SESSION['flash'] = [
            'kind' => 'danger',
            'msg'  => 'None of the selected observations are in your queue.',
        ];
        header('Location: /processing.php');
        exit;
    }
    if (count($items) > WebPolicy::BATCH_MAX_SIZE) {
        $_SESSION['flash'] = [
            'kind' => 'danger',
            'msg'  => 'Batch too large. Max ' . WebPolicy::BATCH_MAX_SIZE
                    . ' observations per submission; you selected ' . count($items) . '.',
        ];
        header('Location: /processing.php');
        exit;
    }
    // Shared-schema requirement: all items must have the same
    // instrument_abbr AND product_type. Anything else means the daemon
    // would need different schemas — hard-refuse.
    $instruments = array_unique(array_map(
        static fn(array $r) => (string) $r['instrument_abbr'],
        $items
    ));
    $products = array_unique(array_map(
        static fn(array $r) => (string) ($r['product_type'] ?? ''),
        $items
    ));
    if (count($instruments) > 1 || count($products) > 1) {
        $_SESSION['flash'] = [
            'kind' => 'danger',
            'msg'  => 'Batch requires all observations to share instrument and product type.',
        ];
        header('Location: /processing.php');
        exit;
    }
    // First item drives schema loading + observation summary display fields.
    $item = $items[0];
} elseif ($is_edit) {
    $job = $job_repo->get($job_id, $user_id);
    if ($job === null) {
        $_SESSION['flash'] = ['kind' => 'danger', 'msg' => 'Job not found.'];
        header('Location: /processing.php');
        exit;
    }
    if ($job['status'] !== 'queued') {
        $_SESSION['flash'] = [
            'kind' => 'danger',
            'msg'  => "Job #{$job_id} can no longer be edited (status: {$job['status']}).",
        ];
        header('Location: /processing.php');
        exit;
    }
    try {
        $existing_config = json_decode(
            (string) $job['config'], true, 512, JSON_THROW_ON_ERROR
        );
    } catch (\Throwable $e) {
        $_SESSION['flash'] = ['kind' => 'danger', 'msg' => 'Job config is malformed.'];
        header('Location: /processing.php');
        exit;
    }
    $item = [
        'observation_id'  => (int) $job['observation_id'],
        'native_id'       => $job['native_id'],
        'instrument_id'   => (int) $job['instrument_id'],
        'instrument_abbr' => $job['instrument_abbr'],
        'body_name'       => $job['body_name'],
        'product_type'    => $existing_config['product'] ?? null,
    ];
} else {
    $queue_items = $queue_repo->items($user_id);
    foreach ($queue_items as $q) {
        if ((int) $q['queue_id'] === $queue_id) {
            $item = $q;
            break;
        }
    }
    if ($item === null) {
        $_SESSION['flash'] = [
            'kind' => 'danger',
            'msg'  => 'That queue item is not in your queue (or has already been submitted).',
        ];
        header('Location: /processing.php');
        exit;
    }
}

// ── Load schema for this (instrument, product) ────────────────────────────
try {
    $schema = SchemaLoader::load(
        (string) $item['instrument_abbr'],
        (string) ($item['product_type'] ?? '')
    );
} catch (\Throwable $e) {
    $_SESSION['flash'] = [
        'kind' => 'danger',
        'msg'  => 'Could not load processing schema: ' . $e->getMessage(),
    ];
    header('Location: /processing.php');
    exit;
}

// ── Handle POST submission (create or edit) ───────────────────────────────
$errors      = [];
$is_post_req = $_SERVER['REQUEST_METHOD'] === 'POST';
$form_values = $is_post_req ? (array) ($_POST['overrides'] ?? []) : [];

if ($is_post_req && $is_batch) {
    // ── Batch create flow: one batch, N jobs, shared config ───────────
    //
    // Semantics: the sparse Contract B overrides are computed once from
    // the submitted form; each job row gets an envelope with the same
    // overrides but its own `observation` block. DB inserts run in a
    // single transaction; if any per-job filesystem write fails, we
    // rollback the transaction AND clean up whatever job dirs we did
    // manage to create, so the outcome is all-or-nothing.
    $storage = $_ENV['PORPASS_STORAGE_PATH'] ?? '';
    if ($storage === '') {
        $errors[] = 'PORPASS_STORAGE_PATH is not configured; jobs cannot be written to disk.';
    }

    if (empty($errors)) {
        $created_dirs = [];
        $created_ids  = [];
        try {
            $builder = new ConfigBuilder($schema);
            // Build once with a placeholder observation, then swap the
            // observation block per job. Overrides + envelope metadata
            // are identical across the batch.
            $template = $builder->buildConfig(
                ['instrument_id' => 0, 'native_id' => ''],
                $form_values
            );
            $template = WebPolicy::purgeHidden($template);

            $db->beginTransaction();
            $batch_id = $job_repo->createBatch(
                $user_id,
                'Batch: ' . count($items) . ' observations'
            );

            foreach ($items as $obs) {
                $per_job = $template;
                $per_job['observation'] = [
                    'instrument_id' => (int) $obs['instrument_id'],
                    'native_id'     => (string) $obs['native_id'],
                ];

                $new_job_id = $job_repo->createJob(
                    $batch_id,
                    $user_id,
                    (int) $obs['observation_id'],
                    $per_job
                );
                $created_ids[] = $new_job_id;

                $job_dir = rtrim($storage, '/') . "/processing/{$user_id}/{$new_job_id}";
                if (!is_dir($job_dir) && !@mkdir($job_dir, 0775, true) && !is_dir($job_dir)) {
                    $err = error_get_last();
                    throw new RuntimeException(
                        "Could not create job directory $job_dir: "
                        . ($err['message'] ?? 'unknown error')
                    );
                }
                // mkdir()'s mode is masked by the process umask, so re-assert
                // group-write: the daemon runs as a different user in the shared
                // porpass group and writes job.toml/run.log/manifest.json here.
                @chmod($job_dir, 0775);
                $written = @file_put_contents(
                    "$job_dir/config.json",
                    json_encode(
                        $per_job,
                        JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
                    )
                );
                if ($written === false) {
                    $err = error_get_last();
                    throw new RuntimeException(
                        "Could not write config.json in $job_dir: "
                        . ($err['message'] ?? 'unknown error')
                    );
                }
                $job_repo->setOutputDir($new_job_id, $job_dir);
                $created_dirs[] = $job_dir;
            }

            $queue_repo->removeMany($user_id, $queue_ids);
            $db->commit();

            $_SESSION['flash'] = [
                'kind' => 'success',
                'msg'  => "Batch #{$batch_id} submitted: "
                       . count($created_ids) . " jobs queued for "
                       . $item['instrument_abbr'] . " "
                       . ($item['product_type'] ?? '') . '.',
            ];
            header('Location: /processing.php');
            exit;
        } catch (\Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            // Best-effort cleanup of dirs we managed to create before the
            // failure. Rolled-back inserts mean these dirs have no DB
            // reference; leaving them behind clutters storage.
            foreach ($created_dirs as $d) {
                @unlink("$d/config.json");
                @rmdir($d);
            }
            $errors[] = $e->getMessage();
        }
    }
} elseif ($is_post_req && $is_edit) {
    // ── Edit flow: UPDATE ──────────────────────────────────────────────
    try {
        $builder = new ConfigBuilder($schema);
        $config  = $builder->buildConfig(
            [
                'instrument_id' => (int) $item['instrument_id'],
                'native_id'     => (string) $item['native_id'],
            ],
            $form_values
        );
        $config = WebPolicy::purgeHidden($config);

        $updated = $job_repo->updateQueuedConfig($job_id, $user_id, $config);
        if (!$updated) {
            throw new RuntimeException(
                "Job #{$job_id} could not be updated — it may have been claimed "
                . "by the daemon since this page was loaded."
            );
        }

        // Best-effort rewrite of config.json. DB is the canonical config;
        // a stale on-disk copy is inconvenient but not catastrophic (the
        // daemon reads from the DB), so surface the failure without
        // rolling back the DB update.
        $job_after = $job_repo->get($job_id, $user_id);
        $ondisk_ok = true;
        if ($job_after && !empty($job_after['output_dir'])) {
            $ondisk_ok = @file_put_contents(
                rtrim((string) $job_after['output_dir'], '/') . '/config.json',
                json_encode(
                    $config,
                    JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
                )
            ) !== false;
        }

        $_SESSION['flash'] = [
            'kind' => $ondisk_ok ? 'success' : 'warning',
            'msg'  => $ondisk_ok
                ? "Job #{$job_id} updated."
                : "Job #{$job_id} updated in the database, but rewriting "
                    . "config.json on disk failed. The daemon will use the "
                    . "updated DB config regardless.",
        ];
        header('Location: /processing.php');
        exit;
    } catch (\Throwable $e) {
        $errors[] = $e->getMessage();
    }
} elseif ($is_post_req) {
    // ── Create flow: INSERT batch + job ────────────────────────────────
    $storage = $_ENV['PORPASS_STORAGE_PATH'] ?? '';
    if ($storage === '') {
        $errors[] = 'PORPASS_STORAGE_PATH is not configured; jobs cannot be written to disk.';
    }

    if (empty($errors)) {
        try {
            $builder = new ConfigBuilder($schema);
            $config  = $builder->buildConfig(
                [
                    'instrument_id' => (int) $item['instrument_id'],
                    'native_id'     => (string) $item['native_id'],
                ],
                $form_values
            );
            $config = WebPolicy::purgeHidden($config);

            $batch_id = $job_repo->createBatch($user_id);
            $job_id_new = $job_repo->createJob(
                $batch_id,
                $user_id,
                (int) $item['observation_id'],
                $config
            );

            $job_dir = rtrim($storage, '/') . "/processing/{$user_id}/{$job_id_new}";
            if (!is_dir($job_dir)) {
                if (!@mkdir($job_dir, 0775, true) && !is_dir($job_dir)) {
                    $err = error_get_last();
                    $reason = $err['message'] ?? 'unknown error';
                    $whoami = function_exists('posix_geteuid')
                        ? (posix_getpwuid(posix_geteuid())['name'] ?? '?')
                        : (get_current_user() ?: '?');
                    throw new RuntimeException(
                        "Could not create job directory $job_dir: $reason. "
                        . "PHP is running as user \"$whoami\"; verify that user "
                        . "has write access to " . rtrim($storage, '/') . "."
                    );
                }
            }
            // mkdir()'s mode is masked by the process umask, so re-assert
            // group-write: the daemon runs as a different user in the shared
            // porpass group and writes job.toml/run.log/manifest.json here.
            @chmod($job_dir, 0775);
            $written = @file_put_contents(
                "$job_dir/config.json",
                json_encode(
                    $config,
                    JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
                )
            );
            if ($written === false) {
                $err = error_get_last();
                throw new RuntimeException(
                    "Could not write config.json in $job_dir: "
                    . ($err['message'] ?? 'unknown error')
                );
            }
            $job_repo->setOutputDir($job_id_new, $job_dir);
            $queue_repo->remove($user_id, $queue_id);

            $_SESSION['flash'] = [
                'kind' => 'success',
                'msg'  => "Job #{$job_id_new} submitted for {$item['instrument_abbr']} "
                        . "{$item['native_id']}.",
            ];
            header('Location: /processing.php');
            exit;
        } catch (\Throwable $e) {
            $errors[] = $e->getMessage();
        }
    }
}

// ── Prepare form values for initial render ────────────────────────────────
//
// Semantics: `$is_post` in the render helpers means "form_values is
// authoritative for the fields it contains, absence of a bool key means
// unchecked". That's true in three scenarios: submitted POST (normal /
// re-render on error), and edit-mode GET where we prefill from the saved
// config.
if (!$is_post_req && $is_edit && $existing_config !== null) {
    $form_values = form_values_from_config($schema, $existing_config);
}
$is_post = $is_post_req || ($is_edit && !empty($form_values));

// ────────────────────────────────────────────────────────────────────────────
// Render helpers — kept inline; if a second page needs them they move to a
// FormRenderer class. Keeping them procedural for now matches how the admin
// pages inline their markup.
// ────────────────────────────────────────────────────────────────────────────

/**
 * Reverse-map a saved Contract B config back into the POST-shape the form
 * expects, so edit-mode can pre-fill every field with its currently-saved
 * effective value.
 *
 * Rules (mirroring the same fallback chain fld_value uses when rendering
 * a fresh form): for each renderable field the value comes from —
 *   1. the config's `overrides[section][field]`, if present
 *   2. a WebPolicy conditional default matched by sibling values
 *   3. a WebPolicy static default override
 *   4. the schema's effective default
 *
 * Bools follow POST semantics: emit '1' if true, omit if false (so the
 * checkbox renders unchecked). Hidden sections and hidden fields are
 * always omitted so they can't produce spurious overrides on resubmit.
 *
 * @param array<mixed> $schema
 * @param array<mixed> $config
 * @return array<string, array<string, string>>
 */
function form_values_from_config(array $schema, array $config): array
{
    $overrides = $config['overrides'] ?? [];
    $out       = [];

    foreach (SchemaLoader::sections($schema) as $meta) {
        $section = $meta['section'];
        if (array_key_exists('supported', $section) && $section['supported'] === false) {
            continue;
        }
        $key = $section['key'];
        if (WebPolicy::isSectionHidden($key)) {
            continue;
        }

        $sectionOverrides = $overrides[$key] ?? [];

        // First pass: resolve every field's current value (used both as the
        // emitted form-shape below and as the "sibling state" for evaluating
        // conditional defaults).
        $state = [];
        foreach ($section['fields'] ?? [] as $f) {
            if (!isset($f['name'])) continue;
            $n = $f['name'];
            if (WebPolicy::isFieldHidden($key, $n)) continue;

            if (array_key_exists($n, $sectionOverrides)) {
                $state[$n] = $sectionOverrides[$n];
                continue;
            }
            $webDefault = WebPolicy::defaultOverride($key, $n);
            $eff = $webDefault ?? SchemaLoader::effectiveDefault($f);
            if ($eff !== null) {
                $state[$n] = $eff;
            }
        }

        // Second pass: apply conditional defaults for fields the user
        // didn't override (their default value depends on siblings).
        foreach ($section['fields'] ?? [] as $f) {
            $n = $f['name'] ?? null;
            if ($n === null) continue;
            if (WebPolicy::isFieldHidden($key, $n)) continue;
            if (array_key_exists($n, $sectionOverrides)) continue;
            $cond = WebPolicy::conditionalDefault($key, $n, $state);
            if ($cond !== null) {
                $state[$n] = $cond;
            }
        }

        // Emit POST-shape.
        $data = [];
        foreach ($section['fields'] ?? [] as $f) {
            $n = $f['name'] ?? null;
            if ($n === null) continue;
            if (WebPolicy::isFieldHidden($key, $n)) continue;
            if (!array_key_exists($n, $state)) continue;
            $val = $state[$n];
            if ($val === null) continue;
            if (($f['type'] ?? 'str') === 'bool') {
                if ($val === true) $data[$n] = '1';
                // false: omit (POST semantics for unchecked)
            } else {
                $data[$n] = (string) $val;
            }
        }
        if (!empty($data)) {
            $out[$key] = $data;
        }
    }
    return $out;
}

/**
 * Compute the effective "current values" of a section for the purposes of
 * initial render: use POST values when they exist, otherwise fall back to
 * schema effective defaults per field. Used to evaluate visible_when and
 * conditional-default rules on GET before the user has touched anything.
 *
 * @return array<string, mixed>
 */
function fld_section_state(bool $is_post, array $form_values, array $section): array
{
    $key = $section['key'];
    $state = [];
    foreach ($section['fields'] ?? [] as $f) {
        if (!isset($f['name'])) continue;
        $n = $f['name'];
        if ($is_post && array_key_exists($n, $form_values[$key] ?? [])) {
            $state[$n] = $form_values[$key][$n];
            continue;
        }
        $webDefault = WebPolicy::defaultOverride($key, $n);
        if ($webDefault !== null) {
            $state[$n] = $webDefault;
            continue;
        }
        $eff = SchemaLoader::effectiveDefault($f);
        if ($eff !== null) {
            $state[$n] = $eff;
        }
    }
    return $state;
}

/**
 * The value to render into a single field's widget. Precedence:
 *   1. POST value (user typed / picked something)
 *   2. WebPolicy conditional default matching current sibling values
 *   3. WebPolicy static default override
 *   4. Schema effective default
 */
function fld_value(bool $is_post, array $form_values, array $sectionState, string $sk, array $field): string
{
    $name = $field['name'];
    if ($is_post && array_key_exists($name, $form_values[$sk] ?? [])) {
        return (string) $form_values[$sk][$name];
    }
    $cond = WebPolicy::conditionalDefault($sk, $name, $sectionState);
    if ($cond !== null) {
        return (string) $cond;
    }
    $webDefault = WebPolicy::defaultOverride($sk, $name);
    if ($webDefault !== null) {
        return (string) $webDefault;
    }
    $default = SchemaLoader::effectiveDefault($field);
    return $default === null ? '' : (string) $default;
}

/**
 * Current checked state for a bool field. On POST the checkbox's presence in
 * $form_values is authoritative (absence = unchecked); on GET the default wins.
 */
function fld_checked(bool $is_post, array $form_values, string $sk, array $field): bool
{
    if ($is_post) {
        return array_key_exists($field['name'], $form_values[$sk] ?? []);
    }
    return (bool) ($field['default'] ?? false);
}

/**
 * Effective visibility rule for a field: the schema's own visible_when if
 * set, otherwise a WebPolicy-declared one. Returns null if the field is
 * unconditionally visible.
 *
 * @return array{field: string, equals: mixed}|null
 */
function effective_visible_when(string $sectionKey, array $field): ?array
{
    if (isset($field['visible_when'])) {
        return $field['visible_when'];
    }
    return WebPolicy::conditionalVisibility($sectionKey, $field['name'] ?? '');
}

/**
 * Server-side initial visibility for a field. Matches the client-side rule
 * so there's no flash of hidden content on first paint.
 */
function fld_visible(bool $is_post, array $form_values, array $section, array $field): bool
{
    $rule = effective_visible_when($section['key'], $field);
    if ($rule === null) {
        return true;
    }
    $sibling = $rule['field'];
    $equals  = $rule['equals'];
    $sk      = $section['key'];

    // Sibling's current value: POST > default.
    if ($is_post && array_key_exists($sibling, $form_values[$sk] ?? [])) {
        $current = $form_values[$sk][$sibling];
    } else {
        // Find sibling field to get its default.
        $current = null;
        foreach ($section['fields'] ?? [] as $f) {
            if (($f['name'] ?? null) === $sibling) {
                $current = $f['default'] ?? null;
                break;
            }
        }
    }
    return (string) $current === (string) $equals;
}

/**
 * Emit the input widget for a single field. Adds `data-visible-when-*`
 * attributes so the client-side helper can toggle visibility as siblings
 * change. `$disabled` is passed by the caller when the whole section is
 * unsupported or disabled by another stage's rule.
 */
function render_field(
    bool  $is_post,
    array $form_values,
    array $sectionState,
    array $section,
    array $field,
    bool  $disabled
): void {
    $sk       = $section['key'];
    $name     = $field['name'];

    // WebPolicy hidden fields: no widget, no submission, no override.
    if (WebPolicy::isFieldHidden($sk, $name)) {
        return;
    }

    $type     = $field['type'] ?? 'str';
    $title    = htmlspecialchars($field['title'] ?? $name);
    $help     = trim((string) ($field['help'] ?? ''));
    $note     = trim((string) ($field['default_note'] ?? ''));
    // Web-side placeholder takes precedence over schema default_note.
    $placeholderText = WebPolicy::placeholderOverride($sk, $name) ?? $note;
    $inputId  = 'f-' . preg_replace('/[^A-Za-z0-9_-]/', '_', "$sk-$name");
    $inputNm  = "overrides[$sk][$name]";
    $visible  = fld_visible($is_post, $form_values, $section, $field);

    $wrapperAttrs = ['class' => 'pp-field'];
    $wrapperAttrs['data-section'] = $sk;
    $wrapperAttrs['data-field']   = $name;
    $vw = effective_visible_when($sk, $field);
    if ($vw !== null) {
        $wrapperAttrs['data-visible-when-field']  = $vw['field'];
        $wrapperAttrs['data-visible-when-equals'] = (string) $vw['equals'];
    }
    $condData = WebPolicy::conditionalDefaultDataAttr($sk, $name);
    if ($condData !== null) {
        $wrapperAttrs['data-conditional-default'] = $condData;
    }
    $style = $visible ? '' : ' style="display:none;"';
    $attrStr = '';
    foreach ($wrapperAttrs as $k => $v) {
        $attrStr .= ' ' . $k . '="' . htmlspecialchars((string) $v) . '"';
    }
    ?>
    <div<?= $attrStr . $style ?>>
        <label class="pp-field-label" for="<?= $inputId ?>">
            <?= $title ?>
        </label>
        <?php if ($type === 'bool'): ?>
            <label class="pp-checkbox">
                <input type="checkbox" id="<?= $inputId ?>"
                       name="<?= htmlspecialchars($inputNm) ?>"
                       value="1"
                       <?= fld_checked($is_post, $form_values, $sk, $field) ? 'checked' : '' ?>
                       <?= $disabled ? 'disabled' : '' ?>>
                <span>Enabled</span>
            </label>

        <?php elseif ($type === 'enum'): ?>
            <select id="<?= $inputId ?>"
                    class="pp-select"
                    name="<?= htmlspecialchars($inputNm) ?>"
                    <?= $disabled ? 'disabled' : '' ?>>
                <?php
                $current = fld_value($is_post, $form_values, $sectionState, $sk, $field);
                foreach (SchemaLoader::visibleUiChoices($field) as $choice):
                    $val = (string) $choice['value'];
                ?>
                    <option value="<?= htmlspecialchars($val) ?>"
                            <?= $val === $current ? 'selected' : '' ?>>
                        <?= htmlspecialchars($val) ?>
                    </option>
                <?php endforeach; ?>
            </select>

        <?php else:
            $inputType = match($type) {
                'int', 'float' => 'number',
                default        => 'text',
            };
            $step = match($type) {
                'int'   => 'step="1"',
                'float' => 'step="any"',
                default => '',
            };
            $value       = fld_value($is_post, $form_values, $sectionState, $sk, $field);
            $placeholder = $placeholderText !== '' && $value === ''
                ? ' placeholder="' . htmlspecialchars($placeholderText) . '"'
                : '';
        ?>
            <input type="<?= $inputType ?>" id="<?= $inputId ?>"
                   class="pp-input"
                   name="<?= htmlspecialchars($inputNm) ?>"
                   value="<?= htmlspecialchars($value) ?>"
                   <?= $step ?><?= $placeholder ?>
                   <?= $disabled ? 'disabled' : '' ?>>
        <?php endif; ?>

        <?php if ($help !== ''): ?>
            <small class="pp-field-help"><?= htmlspecialchars($help) ?></small>
        <?php endif; ?>
    </div>
    <?php
}

/**
 * Emit one section panel with its title, supported/disabled badge, and every
 * field. Sections with supported:false get a locked panel that still shows
 * the fields so users can see what would be available on a supported product.
 */
function render_section(
    bool  $is_post,
    array $form_values,
    string $group,
    array $section
): void {
    $key = $section['key'];

    // Deviating from Contract A's "never omitted" rule: unsupported sections
    // are hidden entirely rather than shown disabled. ConfigBuilder already
    // refuses overrides for these sections, so this is a UI-only change.
    if (array_key_exists('supported', $section) && $section['supported'] === false) {
        return;
    }
    // WebPolicy also hides some sections wholesale (e.g. `general`).
    if (WebPolicy::isSectionHidden($key)) {
        return;
    }

    // Count only fields that will actually render (skip WebPolicy-hidden).
    $renderableFields = array_filter(
        $section['fields'] ?? [],
        static fn(array $f) => isset($f['name']) && !WebPolicy::isFieldHidden($key, $f['name'])
    );
    // If every field is web-hidden, don't render the whole section either.
    if (empty($renderableFields)) {
        return;
    }

    $title       = htmlspecialchars($section['title'] ?? $key);
    $help        = trim((string) ($section['help'] ?? ''));
    $fieldCount  = count($renderableFields);
    $disablesAttr = '';
    if (!empty($section['disables'])) {
        $disablesAttr = ' data-disables="'
            . htmlspecialchars(json_encode($section['disables']))
            . '"';
    }
    // Section state used for evaluating visible_when and conditional
    // defaults during initial render.
    $sectionState = fld_section_state($is_post, $form_values, $section);
    // Collapsed by default; on POST re-render, keep sections open so the
    // user can see the values they submitted (and any validation error).
    $openAttr = $is_post ? ' open' : '';
    ?>
    <details class="pp-panel pp-panel--flush pp-accordion"
             data-section="<?= htmlspecialchars($key) ?>"
             data-group="<?= htmlspecialchars($group) ?>"<?= $disablesAttr ?>
             style="margin-bottom: 0.5rem;"<?= $openAttr ?>>
        <summary class="pp-panel-header pp-accordion-header">
            <span class="pp-accordion-chevron" aria-hidden="true">▸</span>
            <span class="pp-panel-header-title" style="flex: 1;">
                <?= $title ?>
            </span>
            <span class="pp-accordion-field-count"
                  style="font-size: 0.75rem; color: var(--text-muted);">
                <?= $fieldCount ?> field<?= $fieldCount === 1 ? '' : 's' ?>
            </span>
        </summary>
        <div class="pp-panel-body">
            <?php if ($help !== ''): ?>
                <p style="color: var(--text-muted); margin-bottom: 1rem;">
                    <?= htmlspecialchars($help) ?>
                </p>
            <?php endif; ?>
            <?php foreach ($section['fields'] ?? [] as $field):
                render_field($is_post, $form_values, $sectionState, $section, $field, false);
            endforeach; ?>
        </div>
    </details>
    <?php
}

// ── Render ─────────────────────────────────────────────────────────────────

$head_extra = '<script defer src="/resources/js/processing_form.js"></script>'
            . '<style>
              .pp-field { margin-bottom: 1rem; }
              .pp-field-label { display: block; font-weight: 500; margin-bottom: 0.25rem; }
              .pp-field-help { display: block; margin-top: 0.25rem; color: var(--text-muted); }
              .pp-section-heading { margin: 1.5rem 0 0.75rem; font-size: 0.85rem;
                text-transform: uppercase; letter-spacing: 0.05em; color: var(--text-muted); }
              .pp-accordion > summary {
                cursor: pointer;
                user-select: none;
                list-style: none;
                display: flex;
                align-items: center;
                gap: 0.5rem;
              }
              .pp-accordion > summary::-webkit-details-marker { display: none; }
              .pp-accordion > summary::marker { display: none; }
              .pp-accordion > summary:hover { background: rgba(0, 0, 0, 0.02); }
              .pp-accordion-chevron {
                display: inline-block;
                transition: transform 0.15s ease-out;
                color: var(--text-muted);
                width: 1em;
                text-align: center;
                font-family: sans-serif;
              }
              .pp-accordion[open] > summary .pp-accordion-chevron {
                transform: rotate(90deg);
              }
              .pp-accordion-toolbar {
                display: flex;
                gap: 0.5rem;
                justify-content: flex-end;
                margin-bottom: 0.5rem;
              }
              </style>';

$queue_ids_csv = implode(',', $queue_ids);
if ($is_batch) {
    $page_title   = 'Configure batch (' . count($items) . ' observations)';
    $submit_label = 'Submit ' . count($items) . ' jobs';
    $form_action  = '/processing_configure.php?queue_ids=' . $queue_ids_csv;
    $back_label   = '← Back to queue';
} elseif ($is_edit) {
    $page_title   = "Edit job #{$job_id}";
    $submit_label = 'Save changes';
    $form_action  = "/processing_configure.php?job_id={$job_id}";
    $back_label   = '← Back to jobs';
} else {
    $page_title   = 'Configure processing job';
    $submit_label = 'Submit job';
    $form_action  = "/processing_configure.php?queue_id={$queue_id}";
    $back_label   = '← Back to queue';
}
open_layout($page_title, $head_extra);
?>

<div class="pp-page-title-row">
    <div>
        <p class="pp-section-label" style="margin-bottom: 0.25rem;">Processing</p>
        <h1 class="pp-page-title-large"><?= htmlspecialchars($page_title) ?></h1>
    </div>
    <div class="pp-page-title-row-actions">
        <a href="/processing.php" class="pp-btn pp-btn-outline"><?= htmlspecialchars($back_label) ?></a>
    </div>
</div>

<!-- Observation summary -->
<div class="pp-panel pp-panel--flush" style="margin-bottom: 1.5rem;">
    <div class="pp-panel-body">
        <div style="display: flex; gap: 2rem; flex-wrap: wrap;">
            <?php if ($is_batch): ?>
                <div>
                    <p class="pp-section-label" style="margin: 0;">Observations</p>
                    <p style="margin: 0.25rem 0 0;"><?= count($items) ?></p>
                </div>
            <?php else: ?>
                <div>
                    <p class="pp-section-label" style="margin: 0;">Observation</p>
                    <p style="margin: 0.25rem 0 0;">
                        <code><?= htmlspecialchars($item['native_id']) ?></code>
                    </p>
                </div>
            <?php endif; ?>
            <div>
                <p class="pp-section-label" style="margin: 0;">Instrument</p>
                <p style="margin: 0.25rem 0 0;">
                    <?= htmlspecialchars($item['instrument_abbr']) ?>
                </p>
            </div>
            <div>
                <p class="pp-section-label" style="margin: 0;">Body</p>
                <p style="margin: 0.25rem 0 0;">
                    <?= htmlspecialchars($item['body_name']) ?>
                </p>
            </div>
            <div>
                <p class="pp-section-label" style="margin: 0;">Product</p>
                <p style="margin: 0.25rem 0 0;">
                    <?= htmlspecialchars($item['product_type'] ?? '—') ?>
                </p>
            </div>
        </div>
        <?php if ($is_batch): ?>
            <p class="pp-section-label" style="margin: 1.25rem 0 0.5rem;">Native IDs in this batch</p>
            <ul style="margin: 0; padding-left: 1.25rem; columns: 2; column-gap: 2rem;">
                <?php foreach ($items as $obs): ?>
                    <li><code><?= htmlspecialchars($obs['native_id']) ?></code></li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </div>
</div>

<?php if (!empty($errors)): ?>
<div class="pp-alert pp-alert-danger" style="margin-bottom: 1.5rem;">
    <strong>
        <?php if ($is_edit): ?>Could not save changes:
        <?php elseif ($is_batch): ?>Could not submit the batch:
        <?php else: ?>Could not submit the job:
        <?php endif; ?>
    </strong>
    <ul style="margin: 0.5rem 0 0 1.25rem; padding: 0;">
        <?php foreach ($errors as $err): ?>
            <li><?= htmlspecialchars($err) ?></li>
        <?php endforeach; ?>
    </ul>
</div>
<?php endif; ?>

<form method="POST" action="<?= htmlspecialchars($form_action) ?>"
      id="processing-form">
    <?php if ($is_batch): ?>
        <input type="hidden" name="queue_ids" value="<?= htmlspecialchars($queue_ids_csv) ?>">
    <?php elseif ($is_edit): ?>
        <input type="hidden" name="job_id" value="<?= $job_id ?>">
    <?php else: ?>
        <input type="hidden" name="queue_id" value="<?= $queue_id ?>">
    <?php endif; ?>

    <div class="pp-accordion-toolbar">
        <button type="button" class="pp-btn pp-btn-sm pp-btn-outline"
                onclick="document.querySelectorAll('#processing-form details').forEach(function (d) { d.open = true; });">
            Expand all
        </button>
        <button type="button" class="pp-btn pp-btn-sm pp-btn-outline"
                onclick="document.querySelectorAll('#processing-form details').forEach(function (d) { d.open = false; });">
            Collapse all
        </button>
    </div>

    <p class="pp-section-heading">Global parameters</p>
    <?php foreach ($schema['globals'] ?? [] as $section):
        render_section($is_post, $form_values, 'globals', $section);
    endforeach; ?>

    <p class="pp-section-heading">Processing pipeline</p>
    <?php foreach ($schema['stages'] ?? [] as $section):
        render_section($is_post, $form_values, 'stages', $section);
    endforeach; ?>

    <div style="display: flex; gap: 0.75rem; justify-content: flex-end;
                margin-top: 2rem; padding-top: 1rem; border-top: 1px solid #eee;">
        <a href="/processing.php" class="pp-btn pp-btn-outline">Cancel</a>
        <button type="submit" class="pp-btn pp-btn-primary"><?= htmlspecialchars($submit_label) ?></button>
    </div>
</form>

<?php close_layout(); ?>
