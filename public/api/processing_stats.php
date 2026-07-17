<?php
/**
 * processing_stats.php — JSON endpoint for user processing job statistics.
 *
 * Returns cumulative processing job counts per instrument over time for the
 * authenticated user. Used by the dashboard Chart.js graph.
 *
 * Query parameters:
 *   granularity (string) — 'daily', 'weekly', or 'monthly' (default: 'monthly')
 *   range       (string) — '30days', '12months', or 'all' (default: 'all')
 *
 * Returns:
 *   JSON object with keys:
 *     labels   (array of date strings)
 *     datasets (array of Chart.js dataset objects, one per instrument)
 */

require_once __DIR__ . '/../../src/auth.php';
require_once __DIR__ . '/../../src/db.php';

session_start_secure();

header('Content-Type: application/json');

if (!is_logged_in()) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$db      = get_db();
$user_id = $_SESSION['user_id'];

$granularity = $_GET['granularity'] ?? 'monthly';
$range       = $_GET['range']       ?? 'all';

// ── Date format and grouping ──────────────────────────────────────────────

$date_format = match($granularity) {
    'daily'   => '%Y-%m-%d',
    'weekly'  => '%x-W%v',   // ISO year-week e.g. 2026-W12
    'monthly' => '%Y-%m',
    default   => '%Y-%m',
};

// ── Date range filter ─────────────────────────────────────────────────────

$range_condition = match($range) {
    '30days'   => 'AND pj.submitted_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)',
    '12months' => 'AND pj.submitted_at >= DATE_SUB(NOW(), INTERVAL 12 MONTH)',
    default    => '', // all time
};

// ── Fetch raw counts per period per instrument ────────────────────────────

$stmt = $db->prepare("
    SELECT
        DATE_FORMAT(pj.submitted_at, ?) AS period,
        i.instrument_abbr,
        COUNT(*) AS job_count
    FROM processing_jobs pj
    JOIN observations o ON pj.observation_id = o.observation_id
    JOIN instruments  i ON o.instrument_id   = i.instrument_id
    WHERE pj.user_id = ?
    $range_condition
    GROUP BY period, i.instrument_abbr
    ORDER BY period ASC, i.instrument_abbr ASC
");
$stmt->execute([$date_format, $user_id]);
$rows = $stmt->fetchAll();

// ── Build labels and per-instrument series ────────────────────────────────

$periods     = [];
$instruments = [];

foreach ($rows as $row) {
    $periods[$row['period']]              = true;
    $instruments[$row['instrument_abbr']] = true;
}

$periods     = array_keys($periods);
$instruments = array_keys($instruments);

// Build a lookup: period -> instrument -> count
$lookup = [];
foreach ($rows as $row) {
    $lookup[$row['period']][$row['instrument_abbr']] = (int)$row['job_count'];
}

// ── Build cumulative datasets ─────────────────────────────────────────────

$colors = [
    '#0d6efd', // blue   — instrument 1
    '#d63384', // pink   — instrument 2
    '#fd7e14', // orange — instrument 3
    '#20c997', // teal   — instrument 4
    '#6f42c1', // purple — instrument 5
];

$datasets = [];
foreach ($instruments as $idx => $abbr) {
    $cumulative = 0;
    $data       = [];
    foreach ($periods as $period) {
        $cumulative += $lookup[$period][$abbr] ?? 0;
        $data[]      = $cumulative;
    }
    $color      = $colors[$idx % count($colors)];
    $datasets[] = [
        'label'           => $abbr,
        'data'            => $data,
        'borderColor'     => $color,
        'backgroundColor' => $color . '33', // 20% opacity fill
        'tension'         => 0.3,
        'fill'            => false,
        'pointRadius'     => 3,
    ];
}

echo json_encode([
    'labels'   => $periods,
    'datasets' => $datasets,
]);