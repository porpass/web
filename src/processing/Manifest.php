<?php
/**
 * Manifest.php — Reader / writer for a job's manifest.json.
 *
 * The manifest is the daemon's declaration of what a completed run produced.
 * The web reads it to render the results table, gate downloads, and drive
 * the "delete results" reclaim. Its shape is a frozen contract:
 *
 *   {
 *     "manifest_version": "1.0",
 *     "created_at":       "2026-07-09T12:34:56Z",
 *     "grasp_version":    "0.6.0a1",
 *     "files": [
 *       {
 *         "path":         "segY_output.sgy",   // relative to job dir
 *         "kind":         "segy",              // enum below
 *         "bytes":        12345678,
 *         "content_type": "application/octet-stream",
 *         "deleted":      false
 *       },
 *       ...
 *     ]
 *   }
 *
 * `kind` enum values recognised by the web:
 *   data | image | segy | state | cluttergram | log | other
 *
 * The `log` kind is preserved during "delete results" — everything else
 * is fair game to reclaim.
 */

namespace porpass\processing;

use RuntimeException;

class Manifest
{
    public const VERSION = '1.0';

    /** Kinds preserved when a job's results are reclaimed. */
    public const PRESERVED_KINDS = ['log'];

    private string $path;
    private array $data;

    private function __construct(string $path, array $data)
    {
        $this->path = $path;
        $this->data = $data;
    }

    /**
     * Locate the manifest for a job. Returns null if the job has no
     * output_dir yet, or the manifest file doesn't exist.
     */
    public static function forJob(array $job): ?self
    {
        $dir = $job['output_dir'] ?? '';
        if ($dir === '' || !is_dir($dir)) {
            return null;
        }
        $path = rtrim((string) $dir, '/') . '/manifest.json';
        if (!is_file($path)) {
            return null;
        }
        $raw = @file_get_contents($path);
        if ($raw === false) {
            return null;
        }
        try {
            $data = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }
        return is_array($data) ? new self($path, $data) : null;
    }

    /** All entries, deleted or not. */
    public function files(): array
    {
        return array_values($this->data['files'] ?? []);
    }

    /** Entries that still have a file on disk. */
    public function liveFiles(): array
    {
        return array_values(array_filter(
            $this->files(),
            static fn(array $f) => empty($f['deleted'])
        ));
    }

    public function grasp_version(): ?string
    {
        return $this->data['grasp_version'] ?? null;
    }

    public function created_at(): ?string
    {
        return $this->data['created_at'] ?? null;
    }

    /**
     * Find a specific entry by exact path match. Used by the download
     * proxy to validate that a requested filename is on the manifest.
     */
    public function findByPath(string $relPath): ?array
    {
        foreach ($this->files() as $f) {
            if (($f['path'] ?? null) === $relPath) {
                return $f;
            }
        }
        return null;
    }

    /**
     * Reclaim disk: unlink every file whose `kind` is NOT in PRESERVED_KINDS
     * and isn't already marked deleted; then rewrite the manifest with
     * `deleted: true` on each affected entry.
     *
     * Returns the count of files actually unlinked. File-system failures
     * don't abort — they're tolerated so a partial reclaim can be
     * completed by a rerun. The web layer flips `results_deleted=1` on
     * the job row regardless.
     */
    public function reclaim(): int
    {
        $dir = dirname($this->path);
        $unlinked = 0;
        foreach ($this->data['files'] ?? [] as $i => $entry) {
            if (!empty($entry['deleted'])) {
                continue;
            }
            $kind = $entry['kind'] ?? 'other';
            if (in_array($kind, self::PRESERVED_KINDS, true)) {
                continue;
            }
            $rel = $entry['path'] ?? null;
            if (!is_string($rel) || $rel === '') {
                continue;
            }
            $abs = $dir . '/' . $rel;
            if (@unlink($abs)) {
                $unlinked++;
            }
            $this->data['files'][$i]['deleted'] = true;
        }
        @file_put_contents(
            $this->path,
            json_encode(
                $this->data,
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
            )
        );
        return $unlinked;
    }

    public function dir(): string
    {
        return dirname($this->path);
    }
}
