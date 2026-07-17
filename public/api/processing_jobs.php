<?php
/**
 * processing_jobs.php — JSON endpoint for job actions.
 *
 * Supported actions (all POST, body {"action": "...", ...}):
 *
 *   delete         — hard-delete a queued job (row + config.json + dir).
 *                    Refuses if the job is no longer queued.
 *   cancel         — signal a running/queued job to stop. On a queued job
 *                    the row is atomically flipped to status='cancelled'.
 *                    On a running job the web sets cancel_requested=1 and
 *                    the daemon flips the status itself; the web NEVER
 *                    writes status on a running row.
 *   rerun          — clone a succeeded/failed/cancelled job into a new
 *                    queued job in its own single-job batch. Copies the
 *                    config verbatim, sets rerun_of, materialises a fresh
 *                    output_dir with a copy of config.json.
 *   delete_results — reclaim disk for a succeeded/failed/cancelled job.
 *                    Reads the manifest, unlinks all files except
 *                    log-kind (config, job.toml, run.log, manifest.json
 *                    survive), marks the manifest entries deleted, flips
 *                    results_deleted=1.
 *
 * All owner actions enforce ownership via user_id scoping in the SQL.
 * Admin actions resolve the owner via getForAdmin() first, then reuse the
 * same scoped SQL with the owner's user_id. Status checks are applied
 * both in PHP (for clean error messages) and in the WHERE clause (for
 * race safety against the daemon).
 */

require_once __DIR__ . '/../../src/auth.php';
require_once __DIR__ . '/../../src/db.php';

use porpass\processing\JobRepository;
use porpass\processing\Manifest;

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
if (!is_array($body)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Malformed JSON body']);
    exit;
}

$action   = $body['action'] ?? '';
$user_id  = (int) $_SESSION['user_id'];
$is_admin = ($_SESSION['role'] ?? '') === 'admin';
$jobs     = new JobRepository(get_db());

try {
    switch ($action) {

        case 'delete':
            $job_id = (int) ($body['job_id'] ?? 0);
            if ($job_id <= 0) {
                http_response_code(400);
                echo json_encode(['ok' => false, 'error' => 'job_id must be a positive integer']);
                exit;
            }

            // Look the job up first so we know the output_dir for disk cleanup.
            $job = $jobs->get($job_id, $user_id);
            if ($job === null) {
                http_response_code(404);
                echo json_encode(['ok' => false, 'error' => 'Job not found']);
                exit;
            }
            if ($job['status'] !== 'queued') {
                http_response_code(409);
                echo json_encode([
                    'ok'    => false,
                    'error' => "Cannot delete a job with status \"{$job['status']}\"",
                ]);
                exit;
            }

            // Atomic delete — races against the daemon are lost here safely
            // because the WHERE clause requires status='queued'.
            $deleted = $jobs->cancelIfQueued($job_id, $user_id);
            if (!$deleted) {
                http_response_code(409);
                echo json_encode([
                    'ok'    => false,
                    'error' => 'Job could no longer be deleted (already claimed).',
                ]);
                exit;
            }

            // Best-effort disk cleanup. A queued job's directory should only
            // contain config.json; if a manifest / log somehow exists we
            // leave the directory alone rather than blast unexpected files.
            if (!empty($job['output_dir']) && is_dir($job['output_dir'])) {
                @unlink($job['output_dir'] . '/config.json');
                @rmdir($job['output_dir']);
            }

            echo json_encode(['ok' => true, 'job_id' => $job_id]);
            break;

        case 'rerun':
            $job_id = (int) ($body['job_id'] ?? 0);
            if ($job_id <= 0) {
                http_response_code(400);
                echo json_encode(['ok' => false, 'error' => 'job_id must be a positive integer']);
                exit;
            }

            $source = $jobs->get($job_id, $user_id);
            if ($source === null) {
                http_response_code(404);
                echo json_encode(['ok' => false, 'error' => 'Job not found']);
                exit;
            }
            if (!in_array($source['status'], ['succeeded', 'failed', 'cancelled'], true)) {
                http_response_code(409);
                echo json_encode([
                    'ok'    => false,
                    'error' => "Cannot rerun a job with status \"{$source['status']}\"",
                ]);
                exit;
            }

            $storage = $_ENV['PORPASS_STORAGE_PATH'] ?? '';
            if ($storage === '') {
                http_response_code(500);
                echo json_encode([
                    'ok'    => false,
                    'error' => 'PORPASS_STORAGE_PATH is not configured',
                ]);
                exit;
            }

            $new_job_id = $jobs->createRerun($job_id, $user_id);

            // Materialise the new job's directory + config.json using the
            // same policy as the initial-submit path in processing_configure.php.
            $new_dir = rtrim($storage, '/') . "/processing/{$user_id}/{$new_job_id}";
            if (!is_dir($new_dir)) {
                @mkdir($new_dir, 0755, true);
            }
            try {
                $config_data = json_decode((string) $source['config'], true, 512, JSON_THROW_ON_ERROR);
                @file_put_contents(
                    "$new_dir/config.json",
                    json_encode(
                        $config_data,
                        JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
                    )
                );
            } catch (\JsonException) {
                // Source config was malformed; the row still got created,
                // but the daemon won't be able to run it. Surface via the
                // usual failed-run pathway rather than blocking here.
            }
            if (is_dir($new_dir)) {
                $jobs->setOutputDir($new_job_id, $new_dir);
            }

            echo json_encode([
                'ok'         => true,
                'job_id'     => $new_job_id,
                'rerun_of'   => $job_id,
                'output_dir' => is_dir($new_dir) ? $new_dir : null,
            ]);
            break;

        case 'delete_results':
            $job_id = (int) ($body['job_id'] ?? 0);
            if ($job_id <= 0) {
                http_response_code(400);
                echo json_encode(['ok' => false, 'error' => 'job_id must be a positive integer']);
                exit;
            }

            $job = $jobs->get($job_id, $user_id);
            if ($job === null) {
                http_response_code(404);
                echo json_encode(['ok' => false, 'error' => 'Job not found']);
                exit;
            }
            if (!in_array($job['status'], ['succeeded', 'failed', 'cancelled'], true)) {
                http_response_code(409);
                echo json_encode([
                    'ok'    => false,
                    'error' => "Cannot delete results for a job with status \"{$job['status']}\"",
                ]);
                exit;
            }
            if (!empty($job['results_deleted'])) {
                http_response_code(409);
                echo json_encode([
                    'ok'    => false,
                    'error' => 'Results have already been deleted',
                ]);
                exit;
            }

            // Reclaim on disk (best-effort) then flip the DB flag.
            $unlinked = 0;
            $manifest = Manifest::forJob($job);
            if ($manifest !== null) {
                $unlinked = $manifest->reclaim();
            }
            $flipped = $jobs->markResultsDeleted($job_id, $user_id);

            echo json_encode([
                'ok'                => true,
                'job_id'            => $job_id,
                'files_unlinked'    => $unlinked,
                'results_deleted'   => $flipped,
                'manifest_present'  => $manifest !== null,
            ]);
            break;

        case 'cancel':
            $job_id = (int) ($body['job_id'] ?? 0);
            if ($job_id <= 0) {
                http_response_code(400);
                echo json_encode(['ok' => false, 'error' => 'job_id must be a positive integer']);
                exit;
            }

            // Admins may cancel any user's job; owners only their own.
            // Both paths resolve the owner via the fetched row and then
            // use the owner's user_id for the actual UPDATE, so the SQL
            // shape stays scoped in either case.
            $job = $is_admin
                ? $jobs->getForAdmin($job_id)
                : $jobs->get($job_id, $user_id);
            if ($job === null) {
                // 404 for both "doesn't exist" and "not yours" — don't
                // leak existence to a caller who wouldn't otherwise know.
                http_response_code(404);
                echo json_encode(['ok' => false, 'error' => 'Job not found']);
                exit;
            }

            $owner_id = (int) $job['user_id'];
            $status   = (string) $job['status'];

            if (in_array($status, ['succeeded', 'failed', 'cancelled'], true)) {
                http_response_code(409);
                echo json_encode([
                    'ok'      => false,
                    'error'   => "Job is already finished (status: {$status})",
                    'status'  => $status,
                ]);
                exit;
            }

            if ($status === 'queued') {
                $flipped = $jobs->cancelQueued($job_id, $owner_id);
                if ($flipped) {
                    echo json_encode([
                        'ok'      => true,
                        'outcome' => 'cancelled',
                        'status'  => 'cancelled',
                    ]);
                    break;
                }
                // Lost the race — daemon claimed the row between our
                // fetch and update. Fall through to the running-path
                // signal so the daemon still terminates the run.
                $jobs->requestCancel($job_id, $owner_id);
                echo json_encode([
                    'ok'      => true,
                    'outcome' => 'cancelling',
                    'status'  => 'running',
                    'note'    => 'Job was claimed by the daemon just before cancel; the daemon will stop it shortly.',
                ]);
                break;
            }

            // status === 'running'
            $jobs->requestCancel($job_id, $owner_id);
            echo json_encode([
                'ok'      => true,
                'outcome' => 'cancelling',
                'status'  => 'running',
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
