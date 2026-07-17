<?php
/**
 * processing.php — Processing hub landing page.
 *
 * Two sections:
 *   1. Selection queue — observations the user has added but not yet
 *      submitted. Each item has a Configure action that renders the
 *      schema-driven form (processing_configure.php), plus Remove.
 *   2. My jobs        — the user's most recent submitted jobs with status.
 */

require_once __DIR__ . '/../src/auth.php';
require_once __DIR__ . '/../src/db.php';
require_once __DIR__ . '/../src/layout.php';

use porpass\processing\JobRepository;
use porpass\processing\QueueRepository;
use porpass\processing\WebPolicy;

session_start_secure();
require_login();

$db          = get_db();
$user_id     = (int) $_SESSION['user_id'];
$queue_repo  = new QueueRepository($db);
$queue_items = $queue_repo->items($user_id);
$jobs        = (new JobRepository($db))->listForUser($user_id, 50);

// Flash message (set by processing_configure.php on submit).
$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

// Status → badge class mapping. When results have been reclaimed the status
// badge is muted regardless of the underlying value, so the "succeeded" audit
// fact no longer visually reads as "everything is retrievable".
// 'cancelled' is a neutral terminal state — muted, distinct from the red
// failure signal.
function pp_hub_job_badge_class(string $status, bool $results_deleted = false): string {
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

open_layout('Processing');
?>

<div class="pp-page-title-row">
    <div>
        <p class="pp-section-label" style="margin-bottom: 0.25rem;">Processing</p>
        <h1 class="pp-page-title-large">Processing</h1>
    </div>
</div>

<?php if ($flash): ?>
<div class="pp-alert pp-alert-<?= htmlspecialchars($flash['kind']) ?>"
     style="margin-bottom: 1.5rem;">
    <?= htmlspecialchars($flash['msg']) ?>
</div>
<?php endif; ?>

<h2 class="pp-docs-section-heading" style="margin-top: 0;">
    Selection queue
    <?php if (!empty($queue_items)): ?>
        <span class="pp-badge pp-badge-warning"
              style="margin-left: 0.5rem; vertical-align: middle; font-size: 0.7rem;">
            <?= count($queue_items) ?>
        </span>
    <?php endif; ?>
</h2>

<?php if (empty($queue_items)): ?>

<div class="pp-alert pp-alert-info">
    Your processing queue is empty. Add observations from
    <a href="/observations.php">Browse Observations</a> or the
    <a href="/map.php">Map</a>.
</div>

<?php else: ?>

<p class="pp-section-label" style="margin-bottom: 1rem;">Queued observations</p>

<div class="pp-table-wrap">
    <table class="pp-table">
        <thead>
            <tr>
                <th style="width: 2rem; text-align: center;">
                    <input type="checkbox" id="queue-select-all"
                           title="Select all queued observations">
                </th>
                <th>Instrument</th>
                <th>Body</th>
                <th>Native ID</th>
                <th>Product</th>
                <th>Added</th>
                <th>Actions</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($queue_items as $item): ?>
            <tr data-queue-id="<?= (int) $item['queue_id'] ?>">
                <td style="text-align: center;">
                    <input type="checkbox" class="queue-select"
                           data-queue-id="<?= (int) $item['queue_id'] ?>"
                           data-instrument="<?= htmlspecialchars($item['instrument_abbr']) ?>"
                           data-product="<?= htmlspecialchars($item['product_type'] ?? '') ?>">
                </td>
                <td><?= htmlspecialchars($item['instrument_abbr']) ?></td>
                <td><?= htmlspecialchars($item['body_name']) ?></td>
                <td><code><?= htmlspecialchars($item['native_id']) ?></code></td>
                <td><?= htmlspecialchars($item['product_type'] ?? '—') ?></td>
                <td><?= htmlspecialchars($item['added_at']) ?></td>
                <td>
                    <div style="display: flex; gap: 0.35rem;">
                        <a href="/processing_configure.php?queue_id=<?= (int) $item['queue_id'] ?>"
                           class="pp-btn pp-btn-sm pp-btn-primary">
                            Configure
                        </a>
                        <button type="button"
                                class="pp-btn pp-btn-sm pp-btn-danger"
                                onclick="removeFromQueue(<?= (int) $item['queue_id'] ?>, this)">
                            Remove
                        </button>
                    </div>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>

<div style="margin-top: 1rem; display: flex; gap: 0.5rem; align-items: center; flex-wrap: wrap;">
    <button type="button" class="pp-btn pp-btn-primary"
            id="queue-configure-selected" disabled
            onclick="configureSelectedFromQueue()">
        Configure selected
    </button>
    <button type="button" class="pp-btn pp-btn-danger"
            id="queue-remove-selected" disabled
            onclick="removeSelectedFromQueue(this)">
        Remove selected
    </button>
    <button type="button" class="pp-btn pp-btn-outline" onclick="clearQueue()">
        Clear entire queue
    </button>
    <span id="queue-selection-hint"
          style="color: var(--text-muted); font-size: 0.8rem; margin-left: 0.25rem;"></span>
</div>

<?php endif; ?>

<script>
const BATCH_MAX_SIZE = <?= (int) WebPolicy::BATCH_MAX_SIZE ?>;
</script>

<h2 class="pp-docs-section-heading" style="margin-top: 2.5rem;">My jobs</h2>

<?php if (empty($jobs)): ?>
    <div class="pp-alert pp-alert-info">
        You have not submitted any processing jobs yet.
    </div>
<?php else: ?>
<div class="pp-table-wrap">
    <table class="pp-table">
        <thead>
            <tr>
                <th>Job ID</th>
                <th>Observation</th>
                <th>Instrument</th>
                <th>Body</th>
                <th>Status</th>
                <th>Submitted</th>
                <th>Completed</th>
                <th>Actions</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($jobs as $job): ?>
            <tr data-job-id="<?= (int) $job['job_id'] ?>">
                <td>
                    <a href="/processing_job.php?id=<?= (int) $job['job_id'] ?>">
                        <code>#<?= (int) $job['job_id'] ?></code>
                    </a>
                    <?php if ($job['batch_id']): ?>
                        <span class="pp-badge pp-badge-muted"
                              title="Batch <?= (int) $job['batch_id'] ?>">batch</span>
                    <?php endif; ?>
                </td>
                <td><code><?= htmlspecialchars($job['native_id']) ?></code></td>
                <td><?= htmlspecialchars($job['instrument_abbr']) ?></td>
                <td><?= htmlspecialchars($job['body_name']) ?></td>
                <td>
                    <span class="pp-badge <?= pp_hub_job_badge_class($job['status'], !empty($job['results_deleted'])) ?>">
                        <?= htmlspecialchars($job['status']) ?>
                    </span>
                    <?php if (!empty($job['results_deleted'])): ?>
                        <span class="pp-badge pp-badge-muted"
                              title="Result files have been deleted; config and log remain">results deleted</span>
                    <?php endif; ?>
                </td>
                <td><?= htmlspecialchars($job['submitted_at']) ?></td>
                <td><?= $job['completed_at'] ? htmlspecialchars($job['completed_at']) : '—' ?></td>
                <td>
                    <?php if ($job['status'] === 'queued'): ?>
                        <div style="display: flex; gap: 0.35rem; flex-wrap: wrap;">
                            <a href="/processing_configure.php?job_id=<?= (int) $job['job_id'] ?>"
                               class="pp-btn pp-btn-sm pp-btn-outline">Edit</a>
                            <button type="button"
                                    class="pp-btn pp-btn-sm pp-btn-outline"
                                    onclick="cancelJob(<?= (int) $job['job_id'] ?>, this)">
                                Cancel
                            </button>
                            <button type="button"
                                    class="pp-btn pp-btn-sm pp-btn-danger"
                                    onclick="deleteJob(<?= (int) $job['job_id'] ?>, this)">
                                Delete
                            </button>
                        </div>
                    <?php elseif ($job['status'] === 'running'): ?>
                        <div style="display: flex; gap: 0.35rem;">
                            <button type="button"
                                    class="pp-btn pp-btn-sm pp-btn-outline"
                                    onclick="cancelJob(<?= (int) $job['job_id'] ?>, this)">
                                Cancel
                            </button>
                        </div>
                    <?php elseif (in_array($job['status'], ['succeeded', 'failed', 'cancelled'], true)): ?>
                        <div style="display: flex; gap: 0.35rem;">
                            <button type="button"
                                    class="pp-btn pp-btn-sm pp-btn-primary"
                                    onclick="rerunJob(<?= (int) $job['job_id'] ?>)">
                                Rerun
                            </button>
                            <?php if (empty($job['results_deleted'])): ?>
                            <button type="button"
                                    class="pp-btn pp-btn-sm pp-btn-danger"
                                    onclick="deleteResults(<?= (int) $job['job_id'] ?>, this)">
                                Delete results
                            </button>
                            <?php endif; ?>
                        </div>
                    <?php else: ?>
                        —
                    <?php endif; ?>
                </td>
            </tr>
            <?php if (!empty($job['error_message'])): ?>
            <tr>
                <td colspan="8" style="padding-top: 0;">
                    <div class="pp-alert pp-alert-danger" style="margin: 0;">
                        <?= htmlspecialchars($job['error_message']) ?>
                    </div>
                </td>
            </tr>
            <?php endif; ?>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php endif; ?>

<script>
// ── Queue-hub actions (Phase 3 stub) ─────────────────────────────────────
async function removeFromQueue(queueId, btn) {
    if (!confirm('Remove this observation from your queue?')) return;
    btn.disabled = true;
    try {
        const r = await fetch('/api/processing_queue.php', {
            method:  'POST',
            headers: {'Content-Type': 'application/json'},
            body:    JSON.stringify({action: 'remove', queue_id: queueId}),
        });
        const j = await r.json();
        if (!r.ok || !j.ok) throw new Error(j.error || r.statusText);
        // Remove the row; refresh the layout if the queue is now empty.
        const row = btn.closest('tr[data-queue-id="' + queueId + '"]');
        if (row) row.remove();
        updateNavBadge(j.queue_count);
        if (j.queue_count === 0) location.reload();
    } catch (e) {
        btn.disabled = false;
        alert('Failed to remove: ' + e.message);
    }
}

async function clearQueue() {
    if (!confirm('Remove ALL observations from your queue?')) return;
    try {
        const r = await fetch('/api/processing_queue.php', {
            method:  'POST',
            headers: {'Content-Type': 'application/json'},
            body:    JSON.stringify({action: 'clear'}),
        });
        const j = await r.json();
        if (!r.ok || !j.ok) throw new Error(j.error || r.statusText);
        updateNavBadge(0);
        location.reload();
    } catch (e) {
        alert('Failed to clear: ' + e.message);
    }
}

function updateNavBadge(count) {
    const badge = document.getElementById('nav-queue-badge');
    if (!badge) return;
    badge.textContent    = count;
    badge.style.display  = count > 0 ? '' : 'none';
}

// ── Job actions ───────────────────────────────────────────────────────────
async function callJobsApi(action, jobId) {
    const r = await fetch('/api/processing_jobs.php', {
        method:  'POST',
        headers: {'Content-Type': 'application/json'},
        body:    JSON.stringify({action: action, job_id: jobId}),
    });
    const j = await r.json();
    if (!r.ok || !j.ok) throw new Error(j.error || r.statusText);
    return j;
}

async function deleteJob(jobId, btn) {
    if (!confirm('Delete queued job #' + jobId + '? Its config will be removed.')) return;
    btn.disabled = true;
    try {
        await callJobsApi('delete', jobId);
        const row = btn.closest('tr[data-job-id="' + jobId + '"]');
        if (row) row.remove();
    } catch (e) {
        btn.disabled = false;
        alert('Delete failed: ' + e.message);
    }
}

async function rerunJob(jobId) {
    if (!confirm('Rerun job #' + jobId + '? A new queued job will be created with the same config.')) return;
    try {
        const j = await callJobsApi('rerun', jobId);
        location.href = '/processing_job.php?id=' + j.job_id;
    } catch (e) {
        alert('Rerun failed: ' + e.message);
    }
}

async function deleteResults(jobId, btn) {
    if (!confirm('Delete this job’s results? The config, log, and manifest stay; result files are reclaimed.')) return;
    btn.disabled = true;
    try {
        await callJobsApi('delete_results', jobId);
        location.reload();
    } catch (e) {
        btn.disabled = false;
        alert('Delete results failed: ' + e.message);
    }
}

async function cancelJob(jobId, btn) {
    if (!confirm('Cancel job #' + jobId + '?\n\nA queued job is cancelled immediately. A running job may take a few seconds to stop.')) return;
    btn.disabled = true;
    try {
        const j = await callJobsApi('cancel', jobId);
        // If the daemon still owns the job we've only signalled it — a reload
        // won't show the final state right away, but the row will update on
        // the next refresh (or the status-poll widget when it lands).
        if (j.note) alert(j.note);
        location.reload();
    } catch (e) {
        btn.disabled = false;
        alert('Cancel failed: ' + e.message);
    }
}

// ── Selection queue: multi-select + bulk actions ──────────────────────────
(function () {
    const selectAll   = document.getElementById('queue-select-all');
    const removeBtn   = document.getElementById('queue-remove-selected');
    const configBtn   = document.getElementById('queue-configure-selected');
    const hintEl      = document.getElementById('queue-selection-hint');
    if (!removeBtn) return; // queue empty; nothing to wire

    function selectors() { return document.querySelectorAll('.queue-select'); }
    function checked() {
        return Array.from(selectors()).filter(cb => cb.checked);
    }
    function analyzeSelection() {
        const cbs = checked();
        if (!cbs.length) {
            return {count: 0, ids: [], mixed: false, exceedsLimit: false};
        }
        const instruments = new Set(cbs.map(cb => cb.dataset.instrument));
        const products    = new Set(cbs.map(cb => cb.dataset.product));
        return {
            count:        cbs.length,
            ids:          cbs.map(cb => parseInt(cb.dataset.queueId, 10)),
            mixed:        instruments.size > 1 || products.size > 1,
            exceedsLimit: cbs.length > BATCH_MAX_SIZE,
        };
    }
    function refreshBulk() {
        const sel = analyzeSelection();
        const total = selectors().length;

        // Remove-selected: always enabled when >=1 checked.
        removeBtn.disabled    = sel.count === 0;
        removeBtn.textContent = sel.count === 0
            ? 'Remove selected'
            : `Remove ${sel.count} selected`;

        // Configure-selected: additional gates for mixed + limit.
        const configBlocked = sel.count === 0 || sel.mixed || sel.exceedsLimit;
        configBtn.disabled  = configBlocked;
        configBtn.textContent = sel.count === 0
            ? 'Configure selected'
            : `Configure selected (${sel.count})`;

        // Hint text explains blockers when relevant.
        let hint = '';
        if (sel.count > 0 && sel.mixed) {
            hint = 'Mixed instruments or products — select observations that share both.';
        } else if (sel.count > 0 && sel.exceedsLimit) {
            hint = `Batch limit: ${BATCH_MAX_SIZE} observations. Deselect ${sel.count - BATCH_MAX_SIZE} to continue.`;
        }
        if (hintEl) hintEl.textContent = hint;

        if (selectAll) {
            selectAll.checked       = sel.count > 0 && sel.count === total;
            selectAll.indeterminate = sel.count > 0 && sel.count < total;
        }
    }

    selectors().forEach(cb => cb.addEventListener('change', refreshBulk));
    if (selectAll) {
        selectAll.addEventListener('change', () => {
            selectors().forEach(cb => cb.checked = selectAll.checked);
            refreshBulk();
        });
    }
    refreshBulk();
})();

function configureSelectedFromQueue() {
    const ids = Array.from(document.querySelectorAll('.queue-select:checked'))
        .map(cb => parseInt(cb.dataset.queueId, 10));
    if (!ids.length) return;
    location.href = '/processing_configure.php?queue_ids=' + ids.join(',');
}

async function removeSelectedFromQueue(btn) {
    const ids = Array.from(document.querySelectorAll('.queue-select:checked'))
        .map(cb => parseInt(cb.dataset.queueId, 10));
    if (!ids.length) return;
    const label = ids.length === 1
        ? '1 observation'
        : ids.length + ' observations';
    if (!confirm(`Remove ${label} from your queue?`)) return;
    btn.disabled = true;
    try {
        const r = await fetch('/api/processing_queue.php', {
            method:  'POST',
            headers: {'Content-Type': 'application/json'},
            body:    JSON.stringify({action: 'remove_many', queue_ids: ids}),
        });
        const j = await r.json();
        if (!r.ok || !j.ok) throw new Error(j.error || r.statusText);
        updateNavBadge(j.queue_count);
        // Reload for the same reason clearQueue() does — refreshing empty
        // states and re-binding checkbox handlers is easier than surgical
        // DOM patching when a variable number of rows disappear.
        location.reload();
    } catch (e) {
        btn.disabled = false;
        alert('Failed to remove: ' + e.message);
    }
}
</script>

<?php close_layout(); ?>
