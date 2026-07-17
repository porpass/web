<?php
/**
 * processing_files.php — Auth-gated download proxy for job result files.
 *
 * Query:
 *   GET ?job_id=N&file=<manifest-path>
 *
 * The file argument must exactly match an entry's `path` on the job's
 * manifest.json — no basename manipulation, no absolute paths. The
 * resolved on-disk location must also live inside the job directory
 * (real-path check catches symlink escapes).
 *
 * Response:
 *   - image kinds or image/* content types: streamed inline (browser
 *     renders in-page or in a new tab)
 *   - everything else: streamed with Content-Disposition: attachment
 *
 * Refuses when:
 *   - user is not signed in
 *   - job is not owned by the caller
 *   - job's results have been reclaimed (results_deleted=1)
 *   - the manifest entry is marked deleted
 *   - the requested file is not on the manifest
 *   - the resolved path escapes the job directory
 */

require_once __DIR__ . '/../../src/auth.php';
require_once __DIR__ . '/../../src/db.php';

use porpass\processing\JobRepository;
use porpass\processing\Manifest;

session_start_secure();

function abort(int $code, string $msg): never
{
    http_response_code($code);
    header('Content-Type: text/plain; charset=utf-8');
    echo $msg;
    exit;
}

if (!is_logged_in()) {
    abort(401, 'Unauthorized');
}
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    abort(405, 'Method not allowed');
}

$user_id = (int) $_SESSION['user_id'];
$job_id  = (int) ($_GET['job_id'] ?? 0);
$file    = (string) ($_GET['file'] ?? '');

if ($job_id <= 0 || $file === '') {
    abort(400, 'job_id and file are required');
}

$jobs = new JobRepository(get_db());
$job  = $jobs->get($job_id, $user_id);
if ($job === null) {
    abort(404, 'Job not found');
}
if (!empty($job['results_deleted'])) {
    abort(410, 'Results for this job have been reclaimed');
}

$manifest = Manifest::forJob($job);
if ($manifest === null) {
    abort(404, 'No manifest available for this job');
}

$entry = $manifest->findByPath($file);
if ($entry === null) {
    abort(404, 'File is not on the manifest');
}
if (!empty($entry['deleted'])) {
    abort(410, 'This file has been reclaimed');
}

// Resolve to an absolute path, then verify it lives inside the job dir.
// realpath() canonicalises symlinks and `..` segments.
$job_dir = $manifest->dir();
$abs     = realpath($job_dir . '/' . $file);
$root    = realpath($job_dir);
if ($abs === false || $root === false || !str_starts_with($abs, $root . DIRECTORY_SEPARATOR)) {
    abort(404, 'File not found');
}
if (!is_file($abs) || !is_readable($abs)) {
    abort(404, 'File not readable');
}

$content_type = (string) ($entry['content_type'] ?? 'application/octet-stream');
$kind         = (string) ($entry['kind'] ?? 'other');
$basename     = basename($abs);

$is_inline = $kind === 'image' || str_starts_with($content_type, 'image/');
$disposition = $is_inline
    ? 'inline;      filename="' . addslashes($basename) . '"'
    : 'attachment;  filename="' . addslashes($basename) . '"';

header('Content-Type: ' . $content_type);
header('Content-Length: ' . filesize($abs));
header('Content-Disposition: ' . $disposition);
header('X-Content-Type-Options: nosniff');
// Cache for a short window — job artifacts don't change once produced,
// but we keep this brief so a rerun-that-replaces cannot serve stale bytes.
header('Cache-Control: private, max-age=60');

// Streamed read so large SEG-Y / cluttergram files don't materialise in memory.
readfile($abs);
