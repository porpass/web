<?php
/**
 * departments.php — JSON endpoint returning approved departments for a given institution.
 *
 * Called via fetch() from the registration and account settings forms when a
 * user selects an institution. Returns an array of approved department rows
 * belonging to the specified institution.
 *
 * Query parameters:
 *   institution_id (int) — the institution_id to filter departments by
 *
 * Returns:
 *   JSON array of objects with keys: department_id, department, department_abbr
 */

require_once __DIR__ . '/../../src/db.php';

header('Content-Type: application/json');

$institution_id = (int)($_GET['institution_id'] ?? 0);

if ($institution_id <= 0) {
    echo json_encode([]);
    exit;
}

$db   = get_db();
$stmt = $db->prepare(
    'SELECT department_id, department, department_abbr
     FROM departments
     WHERE institution_id = ?
       AND is_approved = 1
     ORDER BY department'
);
$stmt->execute([$institution_id]);
echo json_encode($stmt->fetchAll());