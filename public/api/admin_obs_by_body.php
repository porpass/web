<?php
/**
 * admin_obs_by_body.php — JSON endpoint for cumulative observations per body.
 *
 * Returns cumulative observation counts per planetary body over time.
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
    '30days'   => 'AND o.created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)',
    '12months' => 'AND o.created_at >= DATE_SUB(NOW(), INTERVAL 12 MONTH)',
    default    => '',
};

$stmt = $db->prepare("
    SELECT
        DATE_FORMAT(o.start_time, ?) AS period,
        b.body_name,
        COUNT(*) AS obs_count
    FROM observations o
    JOIN bodies b ON o.body_id = b.body_id
    WHERE 1=1 $range_condition
    GROUP BY period, b.body_name
    ORDER BY period ASC, b.body_name ASC
");
$stmt->execute([$date_format]);
$rows = $stmt->fetchAll();

$periods = [];
$bodies  = [];
foreach ($rows as $row) {
    $periods[$row['period']]      = true;
    $bodies[$row['body_name']]    = true;
}
$periods = array_keys($periods);
$bodies  = array_keys($bodies);

$lookup = [];
foreach ($rows as $row) {
    $lookup[$row['period']][$row['body_name']] = (int)$row['obs_count'];
}

$colors   = ['#0d6efd', '#d63384', '#fd7e14', '#20c997', '#6f42c1'];
$datasets = [];
foreach ($bodies as $idx => $body) {
    $cumulative = 0;
    $data       = [];
    foreach ($periods as $period) {
        $cumulative += $lookup[$period][$body] ?? 0;
        $data[]      = $cumulative;
    }
    $color      = $colors[$idx % count($colors)];
    $datasets[] = [
        'label'           => $body,
        'data'            => $data,
        'borderColor'     => $color,
        'backgroundColor' => $color . '33',
        'tension'         => 0.3,
        'fill'            => false,
        'pointRadius'     => 3,
    ];
}

echo json_encode(['labels' => $periods, 'datasets' => $datasets]);