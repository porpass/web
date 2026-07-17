<?php
/**
 * dashboard.php — PORPASS user and admin dashboard.
 *
 * Displays system announcements, summary statistics, recent observations,
 * the user's recent processing jobs, and an interactive cumulative
 * processing chart. Admin users additionally see pending approval counts,
 * total user stats, and system health indicators.
 */

require_once __DIR__ . '/../src/auth.php';
require_once __DIR__ . '/../src/db.php';
require_once __DIR__ . '/../src/layout.php';

session_start_secure();
require_login();

$db       = get_db();
$user_id  = $_SESSION['user_id'];
$is_admin = $_SESSION['role'] === 'admin';

// ── Announcements (active, not expired, last 90 days) ─────────────────────

$announcements = $db->query(
    'SELECT title, body, created_at
     FROM announcements
     WHERE is_active = 1
       AND expires_at > NOW()
     ORDER BY created_at DESC
     LIMIT 5'
)->fetchAll();

// ── Observation summary by instrument ─────────────────────────────────────

$obs_stats = $db->query(
    'SELECT i.instrument_abbr, COUNT(o.observation_id) AS obs_count
     FROM observations o
     JOIN instruments i ON o.instrument_id = i.instrument_id
     GROUP BY i.instrument_id
     ORDER BY obs_count DESC'
)->fetchAll();

$total_observations = array_sum(array_column($obs_stats, 'obs_count'));

// ── Recent observations (last 5) ──────────────────────────────────────────

$recent_observations = $db->query(
    'SELECT o.native_id, o.start_time, o.stop_time,
            i.instrument_abbr, b.body_name
     FROM observations o
     JOIN instruments i ON o.instrument_id = i.instrument_id
     JOIN bodies b      ON o.body_id       = b.body_id
     ORDER BY o.start_time DESC
     LIMIT 5'
)->fetchAll();

// ── User processing jobs (last 10) ────────────────────────────────────────

$recent_jobs = $db->prepare(
    'SELECT pj.job_id, pj.batch_id, pj.status,
            pj.submitted_at, pj.completed_at,
            o.native_id, i.instrument_abbr
     FROM processing_jobs pj
     JOIN observations o ON pj.observation_id = o.observation_id
     JOIN instruments i  ON o.instrument_id   = i.instrument_id
     WHERE pj.user_id = ?
     ORDER BY pj.submitted_at DESC
     LIMIT 10'
);
$recent_jobs->execute([$user_id]);
$recent_jobs = $recent_jobs->fetchAll();

// ── Processing stats summary for current user ─────────────────────────────

$job_stats = $db->prepare(
    'SELECT pj.status, COUNT(*) AS cnt
     FROM processing_jobs pj
     WHERE pj.user_id = ?
     GROUP BY pj.status'
);
$job_stats->execute([$user_id]);
$job_counts = [];
foreach ($job_stats->fetchAll() as $row) {
    $job_counts[$row['status']] = (int)$row['cnt'];
}
$total_jobs = array_sum($job_counts);

// ── Admin-only stats ──────────────────────────────────────────────────────

if ($is_admin) {
    $pending_users = $db->query(
        'SELECT COUNT(*) FROM users WHERE is_active = 0 AND email_verified = 1'
    )->fetchColumn();

    $unverified_users = $db->query(
        'SELECT COUNT(*) FROM users WHERE email_verified = 0'
    )->fetchColumn();

    $pending_institutions = $db->query(
        'SELECT COUNT(*) FROM institutions WHERE is_approved = 0'
    )->fetchColumn();

    $pending_departments = $db->query(
        'SELECT COUNT(*) FROM departments WHERE is_approved = 0'
    )->fetchColumn();

    $pending_changes = $db->query(
        'SELECT COUNT(*) FROM user_change_requests WHERE status = \'pending\''
    )->fetchColumn();

    $total_users = $db->query(
        'SELECT COUNT(*) FROM users WHERE is_active = 1'
    )->fetchColumn();

    $total_all_jobs = $db->query(
        'SELECT COUNT(*) FROM processing_jobs'
    )->fetchColumn();
}

// Helper: status → badge class mapping. 'cancelled' is a neutral terminal
// state — muted, distinct from the red failure signal.
function pp_job_badge_class(string $status): string {
    return match($status) {
        'succeeded' => 'pp-badge-success',
        'running'   => 'pp-badge-info',
        'queued'    => 'pp-badge-warning',
        'failed'    => 'pp-badge-danger',
        'cancelled' => 'pp-badge-muted',
        default     => 'pp-badge-muted',
    };
}

open_layout('Dashboard');
?>

<!-- ── Page title ─────────────────────────────────────────────────────────── -->
<div class="pp-page-title-row">
    <div>
        <p class="pp-section-label" style="margin-bottom: 0.25rem;">Dashboard</p>
        <h1 class="pp-page-title-large">
            Welcome back, <?= htmlspecialchars($_SESSION['username']) ?>
            <?php if ($is_admin): ?>
                <span class="pp-badge-admin">Admin</span>
            <?php endif; ?>
        </h1>
    </div>
    <div class="pp-page-title-row-actions">
        <a href="/observations.php" class="pp-btn pp-btn-outline">Browse observations</a>
        <a href="/processing.php" class="pp-btn pp-btn-primary">Submit for processing</a>
    </div>
</div>

<!-- ── Announcements ──────────────────────────────────────────────────────── -->
<?php if (!empty($announcements)): ?>
<div style="margin-bottom: 1.5rem;">
    <?php foreach ($announcements as $ann): ?>
    <div class="pp-announcement">
        <span class="pp-announcement-title"><?= htmlspecialchars($ann['title']) ?></span>
        <span class="pp-announcement-date"><?= htmlspecialchars($ann['created_at']) ?></span>
        <div class="pp-announcement-body"><?= $ann['body'] ?></div>
    </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<!-- ── Admin pending approvals ────────────────────────────────────────────── -->
<?php if ($is_admin): ?>
<?php $total_pending = (int)$pending_users + (int)$pending_institutions +
                       (int)$pending_departments + (int)$pending_changes; ?>
<?php if ($total_pending > 0): ?>
<div class="pp-pending-banner">
    <span class="pp-pending-banner-label">Pending actions</span>
    <?php if ($pending_users > 0): ?>
        <a href="/admin/users.php">
            <?= $pending_users ?> user<?= $pending_users > 1 ? 's' : '' ?> awaiting approval
        </a>
    <?php endif; ?>
    <?php if ($unverified_users > 0): ?>
        <a href="/admin/users.php">
            <?= $unverified_users ?> unverified email<?= $unverified_users > 1 ? 's' : '' ?>
        </a>
    <?php endif; ?>
    <?php if ((int)$pending_institutions + (int)$pending_departments > 0): ?>
        <a href="/admin/institutions.php">
            <?= (int)$pending_institutions + (int)$pending_departments ?>
            institution/department<?= ((int)$pending_institutions + (int)$pending_departments) > 1 ? 's' : '' ?>
            pending review
        </a>
    <?php endif; ?>
    <?php if ($pending_changes > 0): ?>
        <a href="/admin/change_requests.php">
            <?= $pending_changes ?> profile change<?= $pending_changes > 1 ? 's' : '' ?> pending
        </a>
    <?php endif; ?>
</div>
<?php endif; ?>
<?php endif; ?>

<!-- ── Stats row ──────────────────────────────────────────────────────────── -->
<div class="pp-stat-grid">

    <!-- Total observations -->
    <div class="pp-stat-card pp-stat-card--accent">
        <p class="pp-stat-value"><?= number_format($total_observations) ?></p>
        <p class="pp-stat-label">Total observations</p>
    </div>

    <!-- Observations by instrument -->
    <?php foreach ($obs_stats as $stat): ?>
    <div class="pp-stat-card">
        <p class="pp-stat-value"><?= number_format((int)$stat['obs_count']) ?></p>
        <p class="pp-stat-label"><?= htmlspecialchars($stat['instrument_abbr']) ?> observations</p>
    </div>
    <?php endforeach; ?>

    <!-- My processing jobs -->
    <div class="pp-stat-card pp-stat-card--amber">
        <p class="pp-stat-value"><?= number_format($total_jobs) ?></p>
        <p class="pp-stat-label">My processing jobs</p>
        <?php if ($total_jobs > 0): ?>
        <div class="pp-stat-meta">
            <?php foreach ($job_counts as $status => $count): ?>
                <span class="pp-badge <?= pp_job_badge_class($status) ?>">
                    <?= $count ?> <?= $status ?>
                </span>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>

    <?php if ($is_admin): ?>
    <div class="pp-stat-card">
        <p class="pp-stat-value"><?= number_format((int)$total_users) ?></p>
        <p class="pp-stat-label">Active users</p>
    </div>
    <div class="pp-stat-card">
        <p class="pp-stat-value"><?= number_format((int)$total_all_jobs) ?></p>
        <p class="pp-stat-label">Total processing jobs (all users)</p>
    </div>
    <?php endif; ?>

</div>

<div class="pp-stack">

    <!-- ── Data Source Status ─────────────────────────────────────────────── -->
    <div class="pp-panel pp-panel--flush">
        <div class="pp-panel-header">
            <h2 class="pp-panel-header-title">Data source status</h2>
            <div class="pp-panel-header-actions">
                <span id="status-checked" class="pp-status-detail" style="display:none;"></span>
                <button id="status-refresh" class="pp-btn-icon" title="Force refresh">
                    &#x21bb; Refresh
                </button>
            </div>
        </div>
        <div class="pp-panel-body">
            <div id="status-loading" class="pp-status-detail">Checking services…</div>
            <div id="status-container" class="pp-status-row" style="display:none;"></div>
        </div>
    </div>

    <!-- ── Processing chart ───────────────────────────────────────────────── -->
    <div class="pp-panel pp-panel--flush">
        <div class="pp-panel-header">
            <h2 class="pp-panel-header-title">My cumulative processing jobs</h2>
            <div class="pp-panel-header-actions">
                <select id="chart-granularity" class="pp-select pp-select--sm">
                    <option value="daily">Daily</option>
                    <option value="weekly">Weekly</option>
                    <option value="monthly" selected>Monthly</option>
                </select>
                <select id="chart-range" class="pp-select pp-select--sm">
                    <option value="30days">Last 30 days</option>
                    <option value="12months">Last 12 months</option>
                    <option value="all" selected>All time</option>
                </select>
            </div>
        </div>
        <div class="pp-panel-body">
            <div id="chart-empty" class="pp-empty" style="display:none;">
                No processing jobs found for the selected range.
            </div>
            <canvas id="processingChart" height="100"></canvas>
        </div>
    </div>

    <!-- ── Recent observations ────────────────────────────────────────────── -->
    <div class="pp-panel pp-panel--flush">
        <div class="pp-panel-header">
            <h2 class="pp-panel-header-title">Recent observations</h2>
            <div class="pp-panel-header-actions">
                <a href="/observations.php" class="pp-btn-icon">Browse all</a>
            </div>
        </div>
        <?php if (empty($recent_observations)): ?>
            <div class="pp-empty">No observations in the database yet.</div>
        <?php else: ?>
        <div class="pp-table-wrap">
            <table class="pp-table">
                <thead>
                    <tr>
                        <th>Native ID</th>
                        <th>Instrument</th>
                        <th>Body</th>
                        <th>Start time</th>
                        <th>End time</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($recent_observations as $obs): ?>
                    <tr>
                        <td><code><?= htmlspecialchars($obs['native_id']) ?></code></td>
                        <td><?= htmlspecialchars($obs['instrument_abbr']) ?></td>
                        <td><?= htmlspecialchars($obs['body_name']) ?></td>
                        <td><?= htmlspecialchars($obs['start_time'] ?? '—') ?></td>
                        <td><?= htmlspecialchars($obs['stop_time']  ?? '—') ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>

    <!-- ── My recent processing jobs ──────────────────────────────────────── -->
    <div class="pp-panel pp-panel--flush">
        <div class="pp-panel-header">
            <h2 class="pp-panel-header-title">My recent processing jobs</h2>
        </div>
        <?php if (empty($recent_jobs)): ?>
            <div class="pp-empty">
                You have not submitted any processing jobs yet.
                <a href="/processing.php">Submit your first job</a>.
            </div>
        <?php else: ?>
        <div class="pp-table-wrap">
            <table class="pp-table">
                <thead>
                    <tr>
                        <th>Job ID</th>
                        <th>Observation</th>
                        <th>Instrument</th>
                        <th>Status</th>
                        <th>Submitted</th>
                        <th>Completed</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($recent_jobs as $job): ?>
                    <tr>
                        <td>
                            <code>#<?= $job['job_id'] ?></code>
                            <?php if ($job['batch_id']): ?>
                                <span class="pp-badge pp-badge-muted" title="Batch <?= htmlspecialchars($job['batch_id']) ?>">
                                    batch
                                </span>
                            <?php endif; ?>
                        </td>
                        <td><code><?= htmlspecialchars($job['native_id']) ?></code></td>
                        <td><?= htmlspecialchars($job['instrument_abbr']) ?></td>
                        <td>
                            <span class="pp-badge <?= pp_job_badge_class($job['status']) ?>">
                                <?= htmlspecialchars($job['status']) ?>
                            </span>
                        </td>
                        <td><?= htmlspecialchars($job['submitted_at']) ?></td>
                        <td><?= $job['completed_at'] ? htmlspecialchars($job['completed_at']) : '—' ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>

</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
// ── Processing chart ──────────────────────────────────────────────────────

let chart = null;

function buildChart(labels, datasets) {
    const ctx   = document.getElementById('processingChart');
    const empty = document.getElementById('chart-empty');

    if (!labels.length) {
        ctx.style.display   = 'none';
        empty.style.display = 'block';
        return;
    }

    ctx.style.display   = 'block';
    empty.style.display = 'none';

    if (chart) chart.destroy();

    chart = new Chart(ctx, {
        type: 'line',
        data: { labels, datasets },
        options: {
            responsive: true,
            interaction: { mode: 'index', intersect: false },
            plugins: {
                legend: { position: 'top' },
                tooltip: { mode: 'index' }
            },
            scales: {
                x: {
                    title: { display: true, text: 'Period' }
                },
                y: {
                    beginAtZero: true,
                    title: { display: true, text: 'Cumulative Jobs' },
                    ticks: { stepSize: 1 }
                }
            }
        }
    });
}

function fetchChartData() {
    const granularity = document.getElementById('chart-granularity').value;
    const range       = document.getElementById('chart-range').value;

    fetch(`/api/processing_stats.php?granularity=${granularity}&range=${range}`)
        .then(r => r.json())
        .then(data => buildChart(data.labels, data.datasets))
        .catch(() => {});
}

document.getElementById('chart-granularity').addEventListener('change', fetchChartData);
document.getElementById('chart-range').addEventListener('change', fetchChartData);

// Initial load
fetchChartData();

// ── Service status ──────────────────────────────────────────────────────

function fetchServiceStatus(forceRefresh) {
    const url = '/api/service_status.php' + (forceRefresh ? '?refresh=1' : '');
    const loading   = document.getElementById('status-loading');
    const container = document.getElementById('status-container');
    const checked   = document.getElementById('status-checked');

    loading.style.display   = 'block';
    container.style.display = 'none';

    fetch(url)
        .then(r => r.json())
        .then(data => {
            container.innerHTML = '';

            for (const [key, svc] of Object.entries(data.services)) {
                const item = document.createElement('div');
                item.className = 'pp-status-item';

                const upClass    = svc.up ? 'up' : 'down';
                const label      = svc.up ? 'Online' : 'Unreachable';
                const timeInfo   = svc.up
                    ? ` &middot; ${svc.response_time_ms} ms`
                    : '';

                item.innerHTML =
                    `<span class="pp-status-dot pp-status-dot--${upClass}"></span>` +
                    `<div>` +
                        `<div class="pp-status-name">${svc.name}</div>` +
                        `<div class="pp-status-detail pp-status-detail--${upClass}">` +
                            `${label}${timeInfo}` +
                        `</div>` +
                    `</div>`;

                container.appendChild(item);
            }

            loading.style.display   = 'none';
            container.style.display = 'grid';

            if (data.checked_at) {
                const dt = new Date(data.checked_at);
                checked.textContent  = 'Last checked: ' + dt.toLocaleString();
                checked.style.display = '';
            }
        })
        .catch(() => {
            loading.textContent     = 'Unable to load service status.';
            loading.style.display   = 'block';
            container.style.display = 'none';
        });
}

document.getElementById('status-refresh').addEventListener('click', function () {
    fetchServiceStatus(true);
});

fetchServiceStatus(false);
</script>

<?php close_layout(); ?>