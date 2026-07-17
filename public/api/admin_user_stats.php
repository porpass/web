<?php
/**
 * admin_user_stats.php — JSON endpoint for cumulative user registration stats.
 *
 * Returns cumulative user counts over time broken down by active and inactive
 * (pending approval) status.
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
    '30days'   => 'AND created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)',
    '12months' => 'AND created_at >= DATE_SUB(NOW(), INTERVAL 12 MONTH)',
    default    => '',
};

$stmt = $db->prepare("
    SELECT
        DATE_FORMAT(created_at, ?) AS period,
        is_active,
        COUNT(*) AS user_count
    FROM users
    WHERE 1=1 $range_condition
    GROUP BY period, is_active
    ORDER BY period ASC
");
$stmt->execute([$date_format]);
$rows = $stmt->fetchAll();

$periods = [];
foreach ($rows as $row) {
    $periods[$row['period']] = true;
}
$periods = array_keys($periods);

$lookup = [];
foreach ($rows as $row) {
    $key = $row['is_active'] ? 'active' : 'inactive';
    $lookup[$row['period']][$key] = (int)$row['user_count'];
}

// Build cumulative series for active and inactive
$active_cum   = 0;
$inactive_cum = 0;
$active_data  = [];
$inactive_data = [];

foreach ($periods as $period) {
    $active_cum   += $lookup[$period]['active']   ?? 0;
    $inactive_cum += $lookup[$period]['inactive'] ?? 0;
    $active_data[]   = $active_cum;
    $inactive_data[] = $inactive_cum;
}

$datasets = [
    [
        'label'           => 'Active',
        'data'            => $active_data,
        'borderColor'     => '#20c997',
        'backgroundColor' => '#20c99733',
        'tension'         => 0.3,
        'fill'            => false,
        'pointRadius'     => 3,
    ],
    [
        'label'           => 'Inactive / Pending',
        'data'            => $inactive_data,
        'borderColor'     => '#fd7e14',
        'backgroundColor' => '#fd7e1433',
        'tension'         => 0.3,
        'fill'            => false,
        'pointRadius'     => 3,
    ],
];

echo json_encode(['labels' => $periods, 'datasets' => $datasets]);