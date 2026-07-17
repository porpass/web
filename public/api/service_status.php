<?php
/**
 * service_status.php — JSON API for external data source status.
 *
 * Returns the cached connectivity status of PDS, DARTS, and Planetary Maps.
 * Triggers a fresh check if the cache is stale (>5 minutes old).
 *
 * Query parameters:
 *   refresh=1  Force a fresh check regardless of cache age.
 *
 * Response format:
 *   {
 *     "services": {
 *       "pds":      { "name": "...", "url": "...", "up": true, ... },
 *       "darts":    { ... },
 *       "astrogeo": { ... }
 *     },
 *     "checked_at": "2026-05-28T12:00:00Z",
 *     "cached": true
 *   }
 */

require_once __DIR__ . '/../../vendor/autoload.php';

use porpass\StatusChecker;

header('Content-Type: application/json');

$checker = new StatusChecker();

if (($_GET['refresh'] ?? '') === '1') {
    $status = $checker->refresh();
} else {
    $status = $checker->getStatus();
}

echo json_encode($status, JSON_UNESCAPED_SLASHES);