<?php
/**
 * admin_dashboard.php — PORPASS admin analytics dashboard.
 *
 * Displays four charts in a 2x2 grid:
 *   1. Cumulative processing runs per instrument (all users)
 *   2. Cumulative users — active vs inactive
 *   3. Average processing time in minutes per instrument
 *   4. Cumulative observations per planetary body
 *
 * Each chart has independent granularity and range selectors.
 */

require_once __DIR__ . '/../../src/auth.php';
require_once __DIR__ . '/../../src/db.php';
require_once __DIR__ . '/../../src/layout.php';

session_start_secure();
require_admin();

$db = get_db();

// ── Summary stats ─────────────────────────────────────────────────────────

$total_users     = (int)$db->query('SELECT COUNT(*) FROM users')->fetchColumn();
$active_users    = (int)$db->query('SELECT COUNT(*) FROM users WHERE is_active = 1')->fetchColumn();
$total_jobs      = (int)$db->query('SELECT COUNT(*) FROM processing_jobs')->fetchColumn();
$total_obs       = (int)$db->query('SELECT COUNT(*) FROM observations')->fetchColumn();

$pending_users   = (int)$db->query('SELECT COUNT(*) FROM users WHERE is_active = 0 AND email_verified = 1')->fetchColumn();
$pending_inst    = (int)$db->query('SELECT COUNT(*) FROM institutions WHERE is_approved = 0')->fetchColumn();
$pending_dept    = (int)$db->query('SELECT COUNT(*) FROM departments WHERE is_approved = 0')->fetchColumn();
$pending_changes = (int)$db->query("SELECT COUNT(*) FROM user_change_requests WHERE status = 'pending'")->fetchColumn();

$total_pending = $pending_users + $pending_inst + $pending_dept + $pending_changes;

open_layout('Admin Dashboard');
?>

<div class="pp-page-title-row">
    <div>
        <p class="pp-section-label" style="margin-bottom: 0.25rem;">Admin</p>
        <h1 class="pp-page-title-large">Admin dashboard</h1>
    </div>
    <a href="/dashboard.php" class="pp-btn pp-btn-outline pp-btn-sm">My dashboard</a>
</div>

<!-- ── Pending actions banner ─────────────────────────────────────────────── -->
<?php if ($total_pending > 0): ?>
<div class="pp-pending-banner" style="margin-bottom: 1.5rem;">
    <strong>Pending actions:</strong>
    <?php if ($pending_users > 0): ?>
        <a href="/admin/users.php">
            <?= $pending_users ?> user<?= $pending_users > 1 ? 's' : '' ?> awaiting approval
        </a><?php if ($pending_inst + $pending_dept > 0 || $pending_changes > 0): ?> · <?php endif; ?>
    <?php endif; ?>
    <?php if ($pending_inst + $pending_dept > 0): ?>
        <a href="/admin/institutions.php">
            <?= $pending_inst + $pending_dept ?> institution/department<?= ($pending_inst + $pending_dept) > 1 ? 's' : '' ?> pending
        </a><?php if ($pending_changes > 0): ?> · <?php endif; ?>
    <?php endif; ?>
    <?php if ($pending_changes > 0): ?>
        <a href="/admin/change_requests.php">
            <?= $pending_changes ?> profile change<?= $pending_changes > 1 ? 's' : '' ?> pending
        </a>
    <?php endif; ?>
</div>
<?php endif; ?>

<!-- ── Summary stat cards ─────────────────────────────────────────────────── -->
<div class="pp-stat-grid" style="margin-bottom: 1.5rem;">
    <div class="pp-stat-card pp-stat-card--accent">
        <div class="pp-stat-value"><?= number_format($total_users) ?></div>
        <div class="pp-stat-label">Total users</div>
        <div style="margin-top: 0.5rem; display: flex; gap: 0.35rem; justify-content: center;">
            <span class="pp-badge pp-badge-success"><?= $active_users ?> active</span>
            <span class="pp-badge pp-badge-warning"><?= $total_users - $active_users ?> inactive</span>
        </div>
    </div>
    <div class="pp-stat-card">
        <div class="pp-stat-value"><?= number_format($total_jobs) ?></div>
        <div class="pp-stat-label">Total processing jobs</div>
    </div>
    <div class="pp-stat-card">
        <div class="pp-stat-value"><?= number_format($total_obs) ?></div>
        <div class="pp-stat-label">Total observations</div>
    </div>
    <div class="pp-stat-card pp-stat-card--amber">
        <div class="pp-stat-value"><?= number_format($total_pending) ?></div>
        <div class="pp-stat-label">Pending actions</div>
    </div>
</div>

<!-- ── 2x2 Chart grid ─────────────────────────────────────────────────────── -->
<div class="pp-admin-chart-grid">

    <!-- Plot 1: Cumulative processing runs per instrument -->
    <div class="pp-panel pp-panel--flush">
        <div class="pp-panel-header">
            <h2 class="pp-panel-header-title">Processing runs by instrument</h2>
            <div class="pp-panel-header-actions">
                <select id="p1-gran" class="pp-select pp-select--sm">
                    <option value="daily">Daily</option>
                    <option value="weekly">Weekly</option>
                    <option value="monthly" selected>Monthly</option>
                </select>
                <select id="p1-range" class="pp-select pp-select--sm">
                    <option value="30days">30 days</option>
                    <option value="12months">12 months</option>
                    <option value="all" selected>All time</option>
                </select>
            </div>
        </div>
        <div class="pp-panel-body">
            <div id="p1-empty" class="pp-empty" style="display:none;">No data available.</div>
            <canvas id="chart1" height="160"></canvas>
        </div>
    </div>

    <!-- Plot 2: Cumulative users active vs inactive -->
    <div class="pp-panel pp-panel--flush">
        <div class="pp-panel-header">
            <h2 class="pp-panel-header-title">Cumulative users</h2>
            <div class="pp-panel-header-actions">
                <select id="p2-gran" class="pp-select pp-select--sm">
                    <option value="daily">Daily</option>
                    <option value="weekly">Weekly</option>
                    <option value="monthly" selected>Monthly</option>
                </select>
                <select id="p2-range" class="pp-select pp-select--sm">
                    <option value="30days">30 days</option>
                    <option value="12months">12 months</option>
                    <option value="all" selected>All time</option>
                </select>
            </div>
        </div>
        <div class="pp-panel-body">
            <div id="p2-empty" class="pp-empty" style="display:none;">No data available.</div>
            <canvas id="chart2" height="160"></canvas>
        </div>
    </div>

    <!-- Plot 3: Average processing time per instrument -->
    <div class="pp-panel pp-panel--flush">
        <div class="pp-panel-header">
            <h2 class="pp-panel-header-title">Avg. processing time (minutes)</h2>
            <div class="pp-panel-header-actions">
                <select id="p3-gran" class="pp-select pp-select--sm">
                    <option value="daily">Daily</option>
                    <option value="weekly">Weekly</option>
                    <option value="monthly" selected>Monthly</option>
                </select>
                <select id="p3-range" class="pp-select pp-select--sm">
                    <option value="30days">30 days</option>
                    <option value="12months">12 months</option>
                    <option value="all" selected>All time</option>
                </select>
            </div>
        </div>
        <div class="pp-panel-body">
            <div id="p3-empty" class="pp-empty" style="display:none;">
                No completed jobs with timing data available.
            </div>
            <canvas id="chart3" height="160"></canvas>
        </div>
    </div>

    <!-- Plot 4: Cumulative observations per body -->
    <div class="pp-panel pp-panel--flush">
        <div class="pp-panel-header">
            <h2 class="pp-panel-header-title">Observations by planetary body</h2>
            <div class="pp-panel-header-actions">
                <select id="p4-gran" class="pp-select pp-select--sm">
                    <option value="daily">Daily</option>
                    <option value="weekly">Weekly</option>
                    <option value="monthly" selected>Monthly</option>
                </select>
                <select id="p4-range" class="pp-select pp-select--sm">
                    <option value="30days">30 days</option>
                    <option value="12months">12 months</option>
                    <option value="all" selected>All time</option>
                </select>
            </div>
        </div>
        <div class="pp-panel-body">
            <div id="p4-empty" class="pp-empty" style="display:none;">No data available.</div>
            <canvas id="chart4" height="160"></canvas>
        </div>
    </div>

</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
// ── Chart builder ─────────────────────────────────────────────────────────

const charts = {};

function buildChart(id, labels, datasets, yLabel = 'Count') {
    const ctx   = document.getElementById('chart' + id);
    const empty = document.getElementById('p' + id + '-empty');

    if (!labels.length) {
        ctx.style.display   = 'none';
        empty.style.display = 'block';
        return;
    }

    ctx.style.display   = 'block';
    empty.style.display = 'none';

    if (charts[id]) charts[id].destroy();

    charts[id] = new Chart(ctx, {
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
                x: { title: { display: true, text: 'Period' } },
                y: {
                    beginAtZero: true,
                    title: { display: true, text: yLabel },
                    ticks: { stepSize: 1 }
                }
            }
        }
    });
}

// ── Fetch helpers ─────────────────────────────────────────────────────────

function fetchChart(plotId, endpoint, yLabel = 'Count') {
    const gran  = document.getElementById('p' + plotId + '-gran').value;
    const range = document.getElementById('p' + plotId + '-range').value;
    fetch(`/api/${endpoint}?granularity=${gran}&range=${range}`)
        .then(r => r.json())
        .then(d => buildChart(plotId, d.labels, d.datasets, yLabel))
        .catch(() => {});
}

// ── Plot 2: Users — built from inline data ────────────────────────────────

function fetchUsers() {
    const gran  = document.getElementById('p2-gran').value;
    const range = document.getElementById('p2-range').value;
    fetch(`/api/admin_user_stats.php?granularity=${gran}&range=${range}`)
        .then(r => r.json())
        .then(d => buildChart(2, d.labels, d.datasets, 'Users'))
        .catch(() => {});
}

// ── Wire up selectors ─────────────────────────────────────────────────────

['gran', 'range'].forEach(type => {
    document.getElementById(`p1-${type}`).addEventListener('change', () =>
        fetchChart(1, 'admin_processing_stats.php', 'Cumulative Jobs'));
    document.getElementById(`p2-${type}`).addEventListener('change', fetchUsers);
    document.getElementById(`p3-${type}`).addEventListener('change', () =>
        fetchChart(3, 'admin_processing_time.php', 'Avg. Minutes'));
    document.getElementById(`p4-${type}`).addEventListener('change', () =>
        fetchChart(4, 'admin_obs_by_body.php', 'Cumulative Observations'));
});

// ── Initial load ──────────────────────────────────────────────────────────

fetchChart(1, 'admin_processing_stats.php', 'Cumulative Jobs');
fetchUsers();
fetchChart(3, 'admin_processing_time.php', 'Avg. Minutes');
fetchChart(4, 'admin_obs_by_body.php', 'Cumulative Observations');
</script>

<?php close_layout(); ?>