<?php
/**
 * JobRepository.php — DB access layer for processing_batches + processing_jobs.
 *
 * Covers the submission side (create a batch and its job rows atomically),
 * the read side used by the processing hub and dashboard (list jobs for a
 * user), and the small set of write operations the web performs against a
 * running job. The daemon owns lifecycle transitions (queued → running →
 * succeeded|failed|cancelled) and writes them directly; those aren't wrapped
 * here.
 *
 * Every method that mutates or reads job data scopes to a user_id so a
 * malicious caller cannot touch another user's rows by guessing IDs. The
 * admin-cancel path goes through `getForAdmin()` to resolve the owner, then
 * calls the same scoped methods with the owner's user_id — one SQL shape,
 * no bypass code path.
 */

namespace porpass\processing;

use PDO;
use RuntimeException;

class JobRepository
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    /**
     * Create a new batch. Every job belongs to a batch, so single-observation
     * submissions get a single-job batch with $label left null.
     *
     * @return int New batch_id.
     */
    public function createBatch(int $user_id, ?string $label = null): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO processing_batches (user_id, label) VALUES (?, ?)'
        );
        $stmt->execute([$user_id, $label]);
        return (int) $this->pdo->lastInsertId();
    }

    /**
     * Insert a single queued job.
     *
     * @param array<mixed> $config Full Contract B document; will be JSON-encoded.
     *
     * @return int New job_id.
     */
    public function createJob(int $batch_id, int $user_id, int $observation_id, array $config): int
    {
        $json = json_encode($config, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $stmt = $this->pdo->prepare(
            'INSERT INTO processing_jobs
                (batch_id, user_id, observation_id, config, status)
             VALUES (?, ?, ?, ?, \'queued\')'
        );
        $stmt->execute([$batch_id, $user_id, $observation_id, $json]);
        return (int) $this->pdo->lastInsertId();
    }

    /**
     * Persist the storage directory the daemon (or the web at submit time)
     * will use for the job's artifacts. Kept separate from createJob so the
     * caller can materialise output_dir only after it has been mkdir'd.
     */
    public function setOutputDir(int $job_id, string $output_dir): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE processing_jobs SET output_dir = ? WHERE job_id = ?'
        );
        $stmt->execute([$output_dir, $job_id]);
    }

    /**
     * Fetch one job row (with joined instrument and observation display
     * fields), scoped to the user. Returns null if the job doesn't exist
     * or belongs to another user.
     *
     * @return array<string, mixed>|null
     */
    public function get(int $job_id, int $user_id): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT pj.job_id, pj.batch_id, pj.user_id, pj.observation_id,
                    pj.config, pj.status, pj.output_dir,
                    pj.claimed_by, pj.claimed_at,
                    pj.submitted_at, pj.started_at, pj.completed_at,
                    pj.error_message, pj.results_deleted, pj.results_deleted_at,
                    pj.rerun_of,
                    o.native_id,
                    i.instrument_id, i.instrument_abbr,
                    b.body_name
             FROM processing_jobs pj
             JOIN observations o ON pj.observation_id = o.observation_id
             JOIN instruments  i ON o.instrument_id   = i.instrument_id
             JOIN bodies       b ON o.body_id         = b.body_id
             WHERE pj.job_id = ? AND pj.user_id = ?'
        );
        $stmt->execute([$job_id, $user_id]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    /**
     * Admin-scoped fetch: same shape as get() but without the user_id
     * filter. Callers must have already established that the current
     * session has the admin role — this method does no auth of its own.
     * Used for cross-user actions (e.g. an admin cancelling another
     * user's job).
     *
     * @return array<string, mixed>|null
     */
    public function getForAdmin(int $job_id): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT pj.job_id, pj.batch_id, pj.user_id, pj.observation_id,
                    pj.config, pj.status, pj.output_dir,
                    pj.claimed_by, pj.claimed_at,
                    pj.submitted_at, pj.started_at, pj.completed_at,
                    pj.error_message, pj.cancel_requested,
                    pj.results_deleted, pj.results_deleted_at,
                    pj.rerun_of,
                    o.native_id,
                    i.instrument_id, i.instrument_abbr,
                    b.body_name
             FROM processing_jobs pj
             JOIN observations o ON pj.observation_id = o.observation_id
             JOIN instruments  i ON o.instrument_id   = i.instrument_id
             JOIN bodies       b ON o.body_id         = b.body_id
             WHERE pj.job_id = ?'
        );
        $stmt->execute([$job_id]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    /**
     * List the user's most recent jobs, newest first. Used by the
     * processing hub's "My jobs" table and the dashboard summary.
     *
     * @return array<int, array<string, mixed>>
     */
    public function listForUser(int $user_id, int $limit = 50): array
    {
        $limit = max(1, min($limit, 500));
        $stmt = $this->pdo->prepare(
            "SELECT pj.job_id, pj.batch_id, pj.status,
                    pj.submitted_at, pj.started_at, pj.completed_at,
                    pj.error_message, pj.results_deleted,
                    o.native_id,
                    i.instrument_abbr,
                    b.body_name
             FROM processing_jobs pj
             JOIN observations o ON pj.observation_id = o.observation_id
             JOIN instruments  i ON o.instrument_id   = i.instrument_id
             JOIN bodies       b ON o.body_id         = b.body_id
             WHERE pj.user_id = ?
             ORDER BY pj.submitted_at DESC
             LIMIT $limit"
        );
        $stmt->execute([$user_id]);
        return $stmt->fetchAll();
    }

    /**
     * Cancel a job that has not yet been claimed by the daemon.
     * Refuses (returns false) if the job is already running or finished.
     */
    public function cancelIfQueued(int $job_id, int $user_id): bool
    {
        $stmt = $this->pdo->prepare(
            "DELETE FROM processing_jobs
             WHERE job_id = ? AND user_id = ? AND status = 'queued'"
        );
        $stmt->execute([$job_id, $user_id]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Update a queued job's config JSON. Fails if the job is no longer
     * queued (daemon claimed it between page load and submit) or is owned
     * by a different user. Race protection is baked into the WHERE clause.
     *
     * @param array<mixed> $config Full Contract B document; will be JSON-encoded.
     * @return bool True if a row was updated.
     */
    public function updateQueuedConfig(int $job_id, int $user_id, array $config): bool
    {
        $json = json_encode($config, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $stmt = $this->pdo->prepare(
            "UPDATE processing_jobs
                SET config = ?
              WHERE job_id = ? AND user_id = ? AND status = 'queued'"
        );
        $stmt->execute([$json, $job_id, $user_id]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Clone an existing job into a new queued job. Used for the Rerun
     * action on succeeded / failed jobs. The new job:
     *
     *   - lives in its own single-job batch
     *   - copies the original config JSON verbatim (same schema/version,
     *     same overrides — bit-identical intent)
     *   - carries `rerun_of` pointing at the original job
     *   - starts fresh with status='queued', no timestamps, no output_dir
     *
     * Ownership is enforced by the caller-supplied user_id — the source
     * job must belong to the same user.
     *
     * @return int New job_id.
     * @throws RuntimeException If the source job is not found / not owned.
     */
    public function createRerun(int $source_job_id, int $user_id): int
    {
        $source = $this->get($source_job_id, $user_id);
        if ($source === null) {
            throw new RuntimeException("Cannot rerun: job #{$source_job_id} not found");
        }
        $batch_id = $this->createBatch($user_id, "Rerun of #{$source_job_id}");

        $stmt = $this->pdo->prepare(
            'INSERT INTO processing_jobs
                (batch_id, user_id, observation_id, config, status, rerun_of)
             VALUES (?, ?, ?, ?, \'queued\', ?)'
        );
        $stmt->execute([
            $batch_id,
            $user_id,
            (int) $source['observation_id'],
            (string) $source['config'],
            $source_job_id,
        ]);
        return (int) $this->pdo->lastInsertId();
    }

    /**
     * Mark a job's heavy products as reclaimed. The row and its provenance
     * artifacts (config, job.toml, run.log, manifest.json) are preserved;
     * only `results_deleted` and `results_deleted_at` are touched here.
     * Actual on-disk file removal is handled by the caller so the two
     * operations can fail independently.
     *
     * @return bool True if the flag was flipped (was previously 0).
     */
    public function markResultsDeleted(int $job_id, int $user_id): bool
    {
        $stmt = $this->pdo->prepare(
            'UPDATE processing_jobs
                SET results_deleted = 1,
                    results_deleted_at = NOW()
              WHERE job_id = ? AND user_id = ? AND results_deleted = 0'
        );
        $stmt->execute([$job_id, $user_id]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Atomically cancel a queued job. Race-safe against the daemon's claim
     * query — the AND status='queued' clause ensures we never overwrite a
     * status the daemon has already advanced. On a lost race the caller
     * should fall back to requestCancel() so the daemon terminates the run
     * it just claimed.
     *
     * Sets status='cancelled', completed_at, and cancel_requested in one
     * statement to keep the row internally consistent for observers.
     *
     * @return bool True if the queued row was flipped to cancelled.
     */
    public function cancelQueued(int $job_id, int $user_id): bool
    {
        $stmt = $this->pdo->prepare(
            "UPDATE processing_jobs
                SET status           = 'cancelled',
                    completed_at     = NOW(),
                    cancel_requested = 1
              WHERE job_id = ? AND user_id = ? AND status = 'queued'"
        );
        $stmt->execute([$job_id, $user_id]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Set the cancel_requested flag on a queued or running job. Idempotent
     * — safe to call repeatedly. The daemon polls the flag every few
     * seconds and, when set on a running job, terminates GRaSP and flips
     * status to 'cancelled' itself.
     *
     * Scoped to non-terminal statuses so a stale UI click on an
     * already-finished job doesn't decorate the row with a meaningless
     * flag.
     *
     * @return bool True if a row was updated.
     */
    public function requestCancel(int $job_id, int $user_id): bool
    {
        $stmt = $this->pdo->prepare(
            "UPDATE processing_jobs
                SET cancel_requested = 1
              WHERE job_id = ? AND user_id = ?
                AND status IN ('queued', 'running')"
        );
        $stmt->execute([$job_id, $user_id]);
        return $stmt->rowCount() > 0;
    }
}
