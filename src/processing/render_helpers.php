<?php
/**
 * render_helpers.php — Shared rendering helpers for processing job pages.
 *
 * Used by both the owner-facing (processing.php, processing_job.php) and
 * admin-facing (admin/processing.php, admin/processing_job.php) views so
 * status badges, byte formatting, and log tailing stay consistent.
 */

/**
 * Status → badge class mapping. When results have been reclaimed the status
 * badge is muted regardless of the underlying value, so the "succeeded"
 * audit fact no longer visually reads as "everything is retrievable".
 * 'cancelled' is a neutral terminal state — muted, distinct from the red
 * failure signal.
 */
function pp_hub_job_badge_class(string $status, bool $results_deleted = false): string {
    if ($results_deleted) return 'pp-badge-muted';
    return match($status) {
        'succeeded' => 'pp-badge-success',
        'running'   => 'pp-badge-info',
        'queued'    => 'pp-badge-warning',
        'failed'    => 'pp-badge-danger',
        'cancelled' => 'pp-badge-muted',
        default     => 'pp-badge-muted',
    };
}

function pp_human_bytes(int $bytes): string {
    if ($bytes < 1024) return $bytes . ' B';
    $units = ['KiB', 'MiB', 'GiB', 'TiB'];
    $val = $bytes / 1024;
    foreach ($units as $u) {
        if ($val < 1024) return sprintf('%.1f %s', $val, $u);
        $val /= 1024;
    }
    return sprintf('%.1f PiB', $val);
}

/**
 * Read up to $lines lines from the end of a text file. Reads in 8 KiB
 * chunks from the tail so we don't slurp gigabyte log files into RAM.
 */
function pp_tail_log(string $path, int $lines): string {
    $fp = @fopen($path, 'rb');
    if ($fp === false) return '';
    fseek($fp, 0, SEEK_END);
    $size = ftell($fp);
    $chunk = 8192;
    $buf   = '';
    $count = 0;
    while ($size > 0 && $count <= $lines) {
        $read = min($chunk, $size);
        $size -= $read;
        fseek($fp, $size);
        $buf   = fread($fp, $read) . $buf;
        $count = substr_count($buf, "\n");
    }
    fclose($fp);
    $bufLines = explode("\n", $buf);
    if (count($bufLines) > $lines) {
        $bufLines = array_slice($bufLines, -$lines);
    }
    return implode("\n", $bufLines);
}
