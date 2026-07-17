<?php
/**
 * processing_queue.php — JSON endpoint for the per-user processing queue.
 *
 * Accepts JSON POST bodies of the form:
 *   {"action": "add",         "observation_ids": [123, 456]}
 *   {"action": "remove",      "queue_id": 42}
 *   {"action": "remove_many", "queue_ids": [42, 43, 44]}
 *   {"action": "remove_observation", "observation_id": 123}
 *   {"action": "clear"}
 *   {"action": "count"}
 *
 * All responses are JSON. On success:  {"ok": true, ...}
 * On failure: {"ok": false, "error": "..."} with an appropriate HTTP status.
 *
 * Session auth via require_login(); ownership is enforced inside every
 * QueueRepository method by scoping to $_SESSION['user_id'].
 */

require_once __DIR__ . '/../../src/auth.php';
require_once __DIR__ . '/../../src/db.php';

use porpass\processing\QueueRepository;

session_start_secure();

header('Content-Type: application/json');

if (!is_logged_in()) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Unauthorized']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Method not allowed']);
    exit;
}

$raw    = file_get_contents('php://input');
$body   = $raw !== '' ? json_decode($raw, true) : [];
$action = is_array($body) ? ($body['action'] ?? '') : '';

if (!is_array($body)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Malformed JSON body']);
    exit;
}

$user_id = (int) $_SESSION['user_id'];
$queue   = new QueueRepository(get_db());

try {
    switch ($action) {

        case 'add':
            $ids = (array) ($body['observation_ids'] ?? []);
            if (empty($ids)) {
                http_response_code(400);
                echo json_encode(['ok' => false, 'error' => 'observation_ids must be a non-empty array']);
                exit;
            }
            $result = $queue->add($user_id, $ids);
            echo json_encode(['ok' => true] + $result);
            break;

        case 'remove':
            $queue_id = (int) ($body['queue_id'] ?? 0);
            if ($queue_id <= 0) {
                http_response_code(400);
                echo json_encode(['ok' => false, 'error' => 'queue_id must be a positive integer']);
                exit;
            }
            $removed = $queue->remove($user_id, $queue_id);
            echo json_encode([
                'ok'          => true,
                'removed'     => $removed,
                'queue_count' => $queue->count($user_id),
            ]);
            break;

        case 'remove_many':
            $ids = (array) ($body['queue_ids'] ?? []);
            if (empty($ids)) {
                http_response_code(400);
                echo json_encode(['ok' => false, 'error' => 'queue_ids must be a non-empty array']);
                exit;
            }
            $removed = $queue->removeMany($user_id, $ids);
            echo json_encode([
                'ok'          => true,
                'removed'     => $removed,
                'queue_count' => $queue->count($user_id),
            ]);
            break;

        case 'remove_observation':
            $observation_id = (int) ($body['observation_id'] ?? 0);
            if ($observation_id <= 0) {
                http_response_code(400);
                echo json_encode(['ok' => false, 'error' => 'observation_id must be a positive integer']);
                exit;
            }
            $removed = $queue->removeObservation($user_id, $observation_id);
            echo json_encode([
                'ok'          => true,
                'removed'     => $removed,
                'queue_count' => $queue->count($user_id),
            ]);
            break;

        case 'clear':
            $cleared = $queue->clear($user_id);
            echo json_encode([
                'ok'          => true,
                'cleared'     => $cleared,
                'queue_count' => 0,
            ]);
            break;

        case 'count':
            echo json_encode([
                'ok'          => true,
                'queue_count' => $queue->count($user_id),
            ]);
            break;

        default:
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'Unknown or missing action']);
    }
} catch (\Throwable $e) {
    http_response_code(500);
    $dev = in_array($_ENV['APP_ENV'] ?? '', ['development-local', 'development'], true);
    echo json_encode([
        'ok'    => false,
        'error' => $dev ? $e->getMessage() : 'Internal server error',
    ]);
}
