<?php
/**
 * admin_processing_time.php — JSON endpoint for average processing time.
 *
 * Returns average processing time in minutes per instrument over time. Only
 * includes jobs that ran to completion (status = 'succeeded' with both
 * started_at and completed_at set).
 *
 * Query parameters:
 *   granularity (string) — 'daily', 'weekly', or 'monthly' (default: 'monthly')
 *   range       (string) — '30days', '12months', or 'all' (default: 'all')
 */

require_once __DIR__ . '/../../src/auth.php';
require_once __DIR__ . '/../../src/db.php';

session_start_secure();
header('Content-Type: application/json');

if (!is_logged_in() || $_SESSION['role'] !== 'admin') {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$db          = get_db();
$granularity = $_GET['granularity'] ?? 'monthly';
$range       = $_GET['range']       ?? 'all';

$date_format = match($granularity) {
    'daily'   => '%Y-%m-%d',
    'weekly'  => '%x-W%v',
    'monthly' => '%Y-%m',
    default   => '%Y-%m',
};

$range_condition = match($range) {
    '30days'   => 'AND pj.submitted_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)',
    '12months' => 'AND pj.submitted_at >= DATE_SUB(NOW(), INTERVAL 12 MONTH)',
    default    => '',
};

$stmt = $db->prepare("
    SELECT
        DATE_FORMAT(pj.submitted_at, ?) AS period,
        i.instrument_abbr,
        ROUND(AVG(TIMESTAMPDIFF(SECOND, pj.started_at, pj.completed_at) / 60.0), 2) AS avg_minutes
    FROM processing_jobs pj
    JOIN observations o ON pj.observation_id = o.observation_id
    JOIN instruments  i ON o.instrument_id   = i.instrument_id
    WHERE pj.status = 'succeeded'
      AND pj.started_at   IS NOT NULL
      AND pj.completed_at IS NOT NULL
      $range_condition
    GROUP BY period, i.instrument_abbr
    ORDER BY period ASC, i.instrument_abbr ASC
");
$stmt->execute([$date_format]);
$rows = $stmt->fetchAll();

$periods     = [];
$instruments = [];
foreach ($rows as $row) {
    $periods[$row['period']]              = true;
    $instruments[$row['instrument_abbr']] = true;
}
$periods     = array_keys($periods);
$instruments = array_keys($instruments);

$lookup = [];
foreach ($rows as $row) {
    $lookup[$row['period']][$row['instrument_abbr']] = (float)$row['avg_minutes'];
}

$colors   = ['#0d6efd', '#d63384', '#fd7e14', '#20c997', '#6f42c1'];
$datasets = [];
foreach ($instruments as $idx => $abbr) {
    $data = [];
    foreach ($periods as $period) {
        $data[] = $lookup[$period][$abbr] ?? null; // null = no data for that period
    }
    $color      = $colors[$idx % count($colors)];
    $datasets[] = [
        'label'                => $abbr,
        'data'                 => $data,
        'borderColor'          => $color,
        'backgroundColor'      => $color . '33',
        'tension'              => 0.3,
        'fill'                 => false,
        'pointRadius'          => 3,
        'spanGaps'             => true, // connect across periods with no data
    ];
}

echo json_encode(['labels' => $periods, 'datasets' => $datasets]);