<?php
/**
 * processing.php — Admin: view all users' processing jobs.
 *
 * Read-only browse/search of every user's submitted jobs, with links to a
 * per-job detail view (admin/processing_job.php) where an admin can also
 * cancel, rerun, or delete results on the owner's behalf.
 */

require_once __DIR__ . '/../../src/auth.php';
require_once __DIR__ . '/../../src/db.php';
require_once __DIR__ . '/../../src/layout.php';
require_once __DIR__ . '/../../src/processing/render_helpers.php';

use porpass\processing\JobRepository;

session_start_secure();
require_admin();

$db   = get_db();
$jobs = new JobRepository($db);

$status   = trim((string) ($_GET['status'] ?? ''));
$search   = trim((string) ($_GET['q'] ?? ''));
$per_page = 25;
$page     = max(1, (int) ($_GET['page'] ?? 1));
$offset   = ($page - 1) * $per_page;

$status_filter = in_array($status, ['queued', 'running', 'succeeded', 'failed', 'cancelled'], true)
    ? $status
    : null;
$search_filter = $search !== '' ? $search : null;

$total       = $jobs->countForAdmin($status_filter, $search_filter);
$total_pages = max(1, (int) ceil($total / $per_page));
$page        = min($page, $total_pages);
$offset      = ($page - 1) * $per_page;

$rows = $jobs->listForAdmin($per_page, $offset, $status_filter, $search_filter);

/** Build a pagination link preserving the current filters. */
function pp_admin_processing_link(int $page, string $status, string $search): string {
    $params = ['page' => $page];
    if ($status !== '') $params['status'] = $status;
    if ($search !== '') $params['q']      = $search;
    return '/admin/processing.php?' . http_build_query($params);
}

open_layout('Processing');
?>

<div class="pp-page-title-row">
    <div>
        <p class="pp-section-label" style="margin-bottom: 0.25rem;">Admin</p>
        <h1 class="pp-page-title-large">Processing</h1>
        <p class="pp-lead">Browse and manage processing jobs across all users.</p>
    </div>
</div>

<form method="get" style="display: flex; gap: 0.75rem; align-items: end; flex-wrap: wrap; margin-bottom: 1.5rem;">
    <div>
        <label class="pp-section-label" for="status" style="display: block; margin-bottom: 0.25rem;">Status</label>
        <select name="status" id="status" class="form-select form-select-sm">
            <option value="">All</option>
            <?php foreach (['queued', 'running', 'succeeded', 'failed', 'cancelled'] as $s): ?>
                <option value="<?= htmlspecialchars($s) ?>" <?= $status === $s ? 'selected' : '' ?>>
                    <?= htmlspecialchars(ucfirst($s)) ?>
                </option>
            <?php endforeach; ?>
        </select>
    </div>
    <div>
        <label class="pp-section-label" for="q" style="display: block; margin-bottom: 0.25rem;">User (username, email, or name)</label>
        <input type="text" name="q" id="q" class="form-control form-control-sm"
               value="<?= htmlspecialchars($search) ?>" placeholder="Search…">
    </div>
    <div>
        <button type="submit" class="pp-btn pp-btn-outline">Filter</button>
        <?php if ($status !== '' || $search !== ''): ?>
            <a href="/admin/processing.php" class="pp-btn pp-btn-outline">Clear</a>
        <?php endif; ?>
    </div>
</form>

<div class="pp-panel pp-panel--flush">
    <div class="pp-panel-header">
        <h2 class="pp-panel-header-title"><?= $total ?> job<?= $total === 1 ? '' : 's' ?></h2>
    </div>

    <?php if (empty($rows)): ?>
        <div class="pp-empty">No jobs match the current filters.</div>
    <?php else: ?>
    <div class="pp-table-wrap">
        <table class="pp-table">
            <thead>
                <tr>
                    <th>Job ID</th>
                    <th>User</th>
                    <th>Observation</th>
                    <th>Instrument</th>
                    <th>Body</th>
                    <th>Status</th>
                    <th>Submitted</th>
                    <th>Completed</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($rows as $job): ?>
                <tr>
                    <td>
                        <a href="/admin/processing_job.php?id=<?= (int) $job['job_id'] ?>">
                            <code>#<?= (int) $job['job_id'] ?></code>
                        </a>
                    </td>
                    <td>
                        <?= htmlspecialchars($job['username']) ?>
                        <div style="color: var(--text-muted); font-size: 0.8rem;">
                            <?= htmlspecialchars($job['email']) ?>
                        </div>
                    </td>
                    <td><code><?= htmlspecialchars($job['native_id']) ?></code></td>
                    <td><?= htmlspecialchars($job['instrument_abbr']) ?></td>
                    <td><?= htmlspecialchars($job['body_name']) ?></td>
                    <td>
                        <span class="pp-badge <?= pp_hub_job_badge_class($job['status'], !empty($job['results_deleted'])) ?>">
                            <?= htmlspecialchars($job['status']) ?>
                        </span>
                        <?php if (!empty($job['results_deleted'])): ?>
                            <span class="pp-badge pp-badge-muted" title="Result files have been deleted">deleted</span>
                        <?php endif; ?>
                    </td>
                    <td><?= htmlspecialchars($job['submitted_at']) ?></td>
                    <td><?= $job['completed_at'] ? htmlspecialchars($job['completed_at']) : '—' ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <?php if ($total_pages > 1): ?>
    <div style="padding: 1rem; display: flex; justify-content: center;">
        <ul class="pp-pagination">
            <li class="<?= $page <= 1 ? 'disabled' : '' ?>">
                <a class="pp-page-link" href="<?= htmlspecialchars(pp_admin_processing_link(max(1, $page - 1), $status, $search)) ?>">‹</a>
            </li>
            <?php for ($p = 1; $p <= $total_pages; $p++): ?>
                <li class="<?= $p === $page ? 'active' : '' ?>">
                    <a class="pp-page-link" href="<?= htmlspecialchars(pp_admin_processing_link($p, $status, $search)) ?>"><?= $p ?></a>
                </li>
            <?php endfor; ?>
            <li class="<?= $page >= $total_pages ? 'disabled' : '' ?>">
                <a class="pp-page-link" href="<?= htmlspecialchars(pp_admin_processing_link(min($total_pages, $page + 1), $status, $search)) ?>">›</a>
            </li>
        </ul>
    </div>
    <?php endif; ?>
    <?php endif; ?>
</div>

<?php close_layout(); ?>
