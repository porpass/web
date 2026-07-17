<?php
/**
 * processing_job.php — Detail view for a single processing job.
 *
 * Shows the observation summary, the sparse Contract B config, the manifest
 * of produced files (with per-file view/download links), a tail of run.log,
 * and status-appropriate actions (Edit / Delete for queued, Rerun / Delete
 * results for succeeded / failed).
 *
 * Reads state entirely from the DB + disk; nothing is polled here. Once the
 * daemon exists and status polling ships, this page will grow a tiny JS
 * interval that refreshes badges without a reload.
 */

require_once __DIR__ . '/../src/auth.php';
require_once __DIR__ . '/../src/db.php';
require_once __DIR__ . '/../src/layout.php';

use porpass\processing\JobRepository;
use porpass\processing\Manifest;

session_start_secure();
require_login();

$db      = get_db();
$user_id = (int) $_SESSION['user_id'];
$job_id  = (int) ($_GET['id'] ?? 0);

if ($job_id <= 0) {
    $_SESSION['flash'] = ['kind' => 'danger', 'msg' => 'Missing job id.'];
    header('Location: /processing.php');
    exit;
}

$jobs = new JobRepository($db);
$job  = $jobs->get($job_id, $user_id);
if ($job === null) {
    $_SESSION['flash'] = ['kind' => 'danger', 'msg' => 'Job not found.'];
    header('Location: /processing.php');
    exit;
}

// Decode the config; a malformed config is unusual but we handle it
// gracefully so the page can still render other info + actions.
$config = null;
try {
    $config = json_decode((string) $job['config'], true, 512, JSON_THROW_ON_ERROR);
} catch (\Throwable) {
    $config = null;
}

$manifest = Manifest::forJob($job);

// Resolved parameters (job.toml). Written by the daemon at claim time as a
// frozen provenance snapshot of what GRaSP actually ran with. Only shown
// once a job has left the `queued` state; the daemon hasn't touched a
// queued job's directory yet.
$toml_path    = null;
$toml_content = null;
if ($job['status'] !== 'queued' && !empty($job['output_dir'])) {
    $toml_path = rtrim((string) $job['output_dir'], '/') . '/job.toml';
    if (is_file($toml_path) && is_readable($toml_path)) {
        $toml_content = @file_get_contents($toml_path);
    }
}

// run.log tail (last ~500 lines). Empty until the daemon writes it.
$log_tail = null;
$log_path = null;
if (!empty($job['output_dir'])) {
    $log_path = rtrim((string) $job['output_dir'], '/') . '/run.log';
    if (is_file($log_path) && is_readable($log_path)) {
        $log_tail = pp_tail_log($log_path, 500);
    }
}

/**
 * Read up to $lines lines from the end of a text file. Reads in 8 KiB
 * chunks from the tail so we don't slurp gigabyte log files into RAM.
 */
function pp_tail_log(string $path, int $lines): string
{
    $fp = @fopen($path, 'rb');
    if ($fp === false) return '';
    fseek($fp, 0, SEEK_END);
    $size = ftell($fp);
    $chunk = 8192;
    $buf   = '';
    $count = 0;
    while ($size > 0 && $count <= $lines) {
        $read = min($chunk, $size);
        $size -= $read;
        fseek($fp, $size);
        $buf   = fread($fp, $read) . $buf;
        $count = substr_count($buf, "\n");
    }
    fclose($fp);
    $bufLines = explode("\n", $buf);
    if (count($bufLines) > $lines) {
        $bufLines = array_slice($bufLines, -$lines);
    }
    return implode("\n", $bufLines);
}

function pp_hub_job_badge_class(string $status, bool $results_deleted = false): string
{
    if ($results_deleted) return 'pp-badge-muted';
    return match($status) {
        'succeeded' => 'pp-badge-success',
        'running'   => 'pp-badge-info',
        'queued'    => 'pp-badge-warning',
        'failed'    => 'pp-badge-danger',
        'cancelled' => 'pp-badge-muted',
        default     => 'pp-badge-muted',
    };
}

function pp_human_bytes(int $bytes): string
{
    if ($bytes < 1024) return $bytes . ' B';
    $units = ['KiB', 'MiB', 'GiB', 'TiB'];
    $val = $bytes / 1024;
    foreach ($units as $u) {
        if ($val < 1024) return sprintf('%.1f %s', $val, $u);
        $val /= 1024;
    }
    return sprintf('%.1f PiB', $val);
}

$head_extra = '<style>
    .pp-code-block {
        background: #f7f7f4;
        border: 1px solid #eee;
        border-radius: 6px;
        padding: 1rem;
        font-family: ui-monospace, "SF Mono", Menlo, Consolas, monospace;
        font-size: 0.8rem;
        overflow-x: auto;
        max-height: 500px;
        overflow-y: auto;
        margin: 0;
    }
    .pp-log-block {
        background: #1e1e1e;
        color: #d4d4d4;
        border-radius: 6px;
        padding: 1rem;
        font-family: ui-monospace, "SF Mono", Menlo, Consolas, monospace;
        font-size: 0.8rem;
        max-height: 400px;
        overflow-y: auto;
        white-space: pre;
        margin: 0;
    }
    .pp-kind-badge {
        display: inline-block;
        padding: 0.1rem 0.4rem;
        border-radius: 3px;
        background: #eef1f5;
        color: #4a5568;
        font-size: 0.7rem;
        font-weight: 500;
        text-transform: uppercase;
        letter-spacing: 0.03em;
    }
</style>';

open_layout("Job #{$job_id}", $head_extra);
?>

<div class="pp-page-title-row">
    <div>
        <p class="pp-section-label" style="margin-bottom: 0.25rem;">Processing</p>
        <h1 class="pp-page-title-large">
            Job #<?= (int) $job_id ?>
            <span class="pp-badge <?= pp_hub_job_badge_class($job['status'], !empty($job['results_deleted'])) ?>"
                  style="margin-left: 0.5rem; vertical-align: middle; font-size: 0.7rem;">
                <?= htmlspecialchars($job['status']) ?>
            </span>
            <?php if (!empty($job['results_deleted'])): ?>
                <span class="pp-badge pp-badge-muted"
                      style="margin-left: 0.35rem; vertical-align: middle; font-size: 0.7rem;">
                    results deleted
                </span>
            <?php endif; ?>
        </h1>
    </div>
    <div class="pp-page-title-row-actions">
        <a href="/processing.php" class="pp-btn pp-btn-outline">← Back to jobs</a>
    </div>
</div>

<!-- ── Observation summary ────────────────────────────────────────────── -->
<div class="pp-panel pp-panel--flush" style="margin-bottom: 1.5rem;">
    <div class="pp-panel-body">
        <div style="display: flex; gap: 2rem; flex-wrap: wrap;">
            <div>
                <p class="pp-section-label" style="margin: 0;">Observation</p>
                <p style="margin: 0.25rem 0 0;"><code><?= htmlspecialchars($job['native_id']) ?></code></p>
            </div>
            <div>
                <p class="pp-section-label" style="margin: 0;">Instrument</p>
                <p style="margin: 0.25rem 0 0;"><?= htmlspecialchars($job['instrument_abbr']) ?></p>
            </div>
            <div>
                <p class="pp-section-label" style="margin: 0;">Body</p>
                <p style="margin: 0.25rem 0 0;"><?= htmlspecialchars($job['body_name']) ?></p>
            </div>
            <div>
                <p class="pp-section-label" style="margin: 0;">Batch</p>
                <p style="margin: 0.25rem 0 0;">#<?= (int) $job['batch_id'] ?></p>
            </div>
            <?php if (!empty($job['rerun_of'])): ?>
            <div>
                <p class="pp-section-label" style="margin: 0;">Rerun of</p>
                <p style="margin: 0.25rem 0 0;">
                    <a href="/processing_job.php?id=<?= (int) $job['rerun_of'] ?>">
                        Job #<?= (int) $job['rerun_of'] ?>
                    </a>
                </p>
            </div>
            <?php endif; ?>
            <div>
                <p class="pp-section-label" style="margin: 0;">Submitted</p>
                <p style="margin: 0.25rem 0 0;"><?= htmlspecialchars($job['submitted_at']) ?></p>
            </div>
            <?php if (!empty($job['completed_at'])): ?>
            <div>
                <p class="pp-section-label" style="margin: 0;">Completed</p>
                <p style="margin: 0.25rem 0 0;"><?= htmlspecialchars($job['completed_at']) ?></p>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- ── Error message (failed) ─────────────────────────────────────────── -->
<?php if (!empty($job['error_message'])): ?>
<div class="pp-alert pp-alert-danger" style="margin-bottom: 1.5rem;">
    <strong>Job failed:</strong>
    <?= htmlspecialchars($job['error_message']) ?>
</div>
<?php endif; ?>

<!-- ── Config ─────────────────────────────────────────────────────────── -->
<h2 class="pp-docs-section-heading" style="margin-top: 0;">Configuration</h2>
<?php if ($config === null && $toml_content === null): ?>
    <div class="pp-alert pp-alert-warning">Saved config is malformed and could not be parsed.</div>
<?php else: ?>
    <p style="color: var(--text-muted); margin-bottom: 0.5rem;">
        <?php if ($config !== null): ?>
            Schema v<?= htmlspecialchars($config['schema_version'] ?? '?') ?>
            · GRaSP v<?= htmlspecialchars($config['grasp_version']  ?? '?') ?>
            <?php if ($toml_content !== null): ?>
                · Resolved parameters below (from <code>job.toml</code>).
            <?php else: ?>
                · Your submitted choices — full parameters are resolved when the job is claimed.
            <?php endif; ?>
        <?php endif; ?>
    </p>
    <?php if ($toml_content !== null): ?>
        <pre class="pp-code-block"><?= htmlspecialchars($toml_content) ?></pre>
    <?php else: ?>
        <pre class="pp-code-block"><?= htmlspecialchars(json_encode(
            $config,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
        )) ?></pre>
    <?php endif; ?>
<?php endif; ?>

<!-- ── Results manifest ───────────────────────────────────────────────── -->
<h2 class="pp-docs-section-heading" style="margin-top: 2rem;">Results</h2>
<?php if ($manifest === null): ?>
    <div class="pp-alert pp-alert-info">
        No manifest yet. Results appear here after the daemon runs the job
        and writes <code>manifest.json</code>.
    </div>
<?php elseif (!empty($job['results_deleted'])): ?>
    <div class="pp-alert pp-alert-info">
        Results have been reclaimed. <code>config.json</code>, <code>job.toml</code>,
        <code>run.log</code>, and <code>manifest.json</code> are retained.
    </div>
<?php else: ?>
    <?php $files = $manifest->files(); ?>
    <?php if (empty($files)): ?>
        <div class="pp-alert pp-alert-info">Manifest present but lists no files.</div>
    <?php else: ?>
    <p style="color: var(--text-muted); margin-bottom: 0.5rem;">
        <?= count($files) ?> file<?= count($files) === 1 ? '' : 's' ?>
        <?php if ($manifest->created_at()): ?>
            · produced <?= htmlspecialchars($manifest->created_at()) ?>
        <?php endif; ?>
    </p>
    <div class="pp-table-wrap">
        <table class="pp-table">
            <thead>
                <tr>
                    <th>Path</th>
                    <th>Kind</th>
                    <th>Size</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($files as $f):
                $path      = (string) ($f['path'] ?? '');
                $kind      = (string) ($f['kind'] ?? 'other');
                $bytes     = (int)    ($f['bytes'] ?? 0);
                $deleted   = !empty($f['deleted']);
                $ctype     = (string) ($f['content_type'] ?? '');
                $is_image  = $kind === 'image' || str_starts_with($ctype, 'image/');
                $dl_url    = '/api/processing_files.php?job_id=' . $job_id
                           . '&file=' . urlencode($path);
            ?>
                <tr<?= $deleted ? ' style="opacity: 0.55;"' : '' ?>>
                    <td><code><?= htmlspecialchars($path) ?></code></td>
                    <td><span class="pp-kind-badge"><?= htmlspecialchars($kind) ?></span></td>
                    <td><?= $bytes > 0 ? htmlspecialchars(pp_human_bytes($bytes)) : '—' ?></td>
                    <td>
                        <?php if ($deleted): ?>
                            <span class="pp-badge pp-badge-muted">reclaimed</span>
                        <?php else: ?>
                            <div style="display: flex; gap: 0.35rem;">
                                <?php if ($is_image): ?>
                                    <a href="<?= htmlspecialchars($dl_url) ?>"
                                       target="_blank" rel="noopener"
                                       class="pp-btn pp-btn-sm pp-btn-outline">View</a>
                                <?php endif; ?>
                                <a href="<?= htmlspecialchars($dl_url) ?>"
                                   class="pp-btn pp-btn-sm pp-btn-outline"
                                   download>Download</a>
                            </div>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
<?php endif; ?>

<!-- ── run.log tail ───────────────────────────────────────────────────── -->
<h2 class="pp-docs-section-heading" style="margin-top: 2rem;">
    Run log
    <?php if ($log_tail !== null): ?>
        <span style="font-size: 0.75rem; color: var(--text-muted); margin-left: 0.5rem;">
            (last 500 lines)
        </span>
    <?php endif; ?>
</h2>
<?php if ($log_tail === null): ?>
    <div class="pp-alert pp-alert-info">
        No log available yet. The daemon writes <code>run.log</code> while the job runs.
    </div>
<?php elseif (trim($log_tail) === ''): ?>
    <div class="pp-alert pp-alert-info">Log is empty.</div>
<?php else: ?>
    <pre class="pp-log-block"><?= htmlspecialchars($log_tail) ?></pre>
<?php endif; ?>

<!-- ── Actions ────────────────────────────────────────────────────────── -->
<div style="display: flex; gap: 0.5rem; justify-content: flex-end;
            margin-top: 2rem; padding-top: 1rem; border-top: 1px solid #eee;">
    <?php if ($job['status'] === 'queued'): ?>
        <a href="/processing_configure.php?job_id=<?= (int) $job_id ?>"
           class="pp-btn pp-btn-outline">Edit</a>
        <button type="button" class="pp-btn pp-btn-outline"
                onclick="cancelJob(<?= (int) $job_id ?>)">
            Cancel
        </button>
        <button type="button" class="pp-btn pp-btn-danger"
                onclick="deleteQueuedJob(<?= (int) $job_id ?>)">
            Delete
        </button>
    <?php elseif ($job['status'] === 'running'): ?>
        <button type="button" class="pp-btn pp-btn-outline"
                onclick="cancelJob(<?= (int) $job_id ?>)">
            Cancel
        </button>
    <?php elseif (in_array($job['status'], ['succeeded', 'failed', 'cancelled'], true)): ?>
        <button type="button" class="pp-btn pp-btn-primary"
                onclick="rerunJob(<?= (int) $job_id ?>)">
            Rerun
        </button>
        <?php if (empty($job['results_deleted'])): ?>
        <button type="button" class="pp-btn pp-btn-danger"
                onclick="deleteResults(<?= (int) $job_id ?>)">
            Delete results
        </button>
        <?php endif; ?>
    <?php endif; ?>
</div>

<script>
async function callJobsApi(action, jobId, confirmMsg) {
    if (confirmMsg && !confirm(confirmMsg)) return null;
    const r = await fetch('/api/processing_jobs.php', {
        method:  'POST',
        headers: {'Content-Type': 'application/json'},
        body:    JSON.stringify({action: action, job_id: jobId}),
    });
    const j = await r.json();
    if (!r.ok || !j.ok) throw new Error(j.error || r.statusText);
    return j;
}

async function deleteQueuedJob(jobId) {
    try {
        const j = await callJobsApi(
            'delete', jobId,
            'Delete queued job #' + jobId + '? Its config will be removed.'
        );
        if (j) location.href = '/processing.php';
    } catch (e) { alert('Delete failed: ' + e.message); }
}

async function rerunJob(jobId) {
    try {
        const j = await callJobsApi(
            'rerun', jobId,
            'Rerun job #' + jobId + '? A new queued job will be created with the same config.'
        );
        if (j) location.href = '/processing_job.php?id=' + j.job_id;
    } catch (e) { alert('Rerun failed: ' + e.message); }
}

async function deleteResults(jobId) {
    try {
        const j = await callJobsApi(
            'delete_results', jobId,
            'Delete this job’s results? The config, log, and manifest stay; result files are reclaimed.'
        );
        if (j) location.reload();
    } catch (e) { alert('Delete results failed: ' + e.message); }
}

async function cancelJob(jobId) {
    try {
        const j = await callJobsApi(
            'cancel', jobId,
            'Cancel this job?\n\nA queued job is cancelled immediately. A running job may take a few seconds to stop.'
        );
        if (!j) return;
        // If the daemon still owns the job, we've only signalled it; the row
        // won't show "cancelled" until the daemon flips it in a few seconds.
        if (j.note) alert(j.note);
        location.reload();
    } catch (e) { alert('Cancel failed: ' + e.message); }
}
</script>

<?php close_layout(); ?>
