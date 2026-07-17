<?php
/**
 * QueueRepository.php — Per-user pre-submit selection queue.
 *
 * Backs the "add to processing queue" workflow on observations.php and the
 * GIS map popup. Rows are keyed by (user_id, observation_id) and constrained
 * to be unique per user, so a re-add is a silent no-op instead of a duplicate.
 *
 * observation_id uniquely identifies (instrument, native_id, product_type) via
 * observations + its child table's observation_type ENUM, so no denormalisation
 * lives here. The Phase 4 form generator resolves what it needs by joining.
 *
 * Usage:
 *   $q = new \porpass\processing\QueueRepository(get_db());
 *   $q->add($_SESSION['user_id'], [123, 456, 789]);
 *   $q->count($_SESSION['user_id']);
 */

namespace porpass\processing;

use PDO;

class QueueRepository
{
    private PDO $pdo;

    /**
     * @param PDO $pdo Shared PDO connection from get_db().
     */
    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    /**
     * Insert one or more observations into the user's queue.
     *
     * Uses INSERT IGNORE so re-adding an already-queued observation is a
     * silent skip rather than an error. Invalid observation_ids (e.g. a
     * deleted row hit by a race) are also silently skipped via the FK
     * constraint. Duplicates within $observation_ids are collapsed before
     * insert so `added + skipped` reflects unique inputs.
     *
     * @param int   $user_id
     * @param int[] $observation_ids
     * @return array{added:int, skipped:int, queue_count:int}
     */
    public function add(int $user_id, array $observation_ids): array
    {
        $ids = array_values(array_unique(array_filter(
            array_map('intval', $observation_ids),
            static fn(int $x) => $x > 0
        )));

        if (empty($ids)) {
            return ['added' => 0, 'skipped' => 0, 'queue_count' => $this->count($user_id)];
        }

        $placeholders = implode(',', array_fill(0, count($ids), '(?, ?)'));
        $params       = [];
        foreach ($ids as $oid) {
            $params[] = $user_id;
            $params[] = $oid;
        }

        $sql  = "INSERT IGNORE INTO processing_queue (user_id, observation_id) VALUES $placeholders";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        $added   = $stmt->rowCount();
        $skipped = count($ids) - $added;

        return [
            'added'       => $added,
            'skipped'     => $skipped,
            'queue_count' => $this->count($user_id),
        ];
    }

    /**
     * Remove a single queue entry for the user.
     *
     * Enforces ownership by including user_id in the WHERE clause, so a
     * malicious client cannot delete another user's items by guessing IDs.
     *
     * @return bool True if a row was removed.
     */
    public function remove(int $user_id, int $queue_id): bool
    {
        $stmt = $this->pdo->prepare(
            'DELETE FROM processing_queue WHERE queue_id = ? AND user_id = ?'
        );
        $stmt->execute([$queue_id, $user_id]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Remove multiple queue entries for the user in a single statement.
     *
     * Ownership is enforced the same way as the single-row remove — the
     * user_id filter guards against ID guessing. Duplicates and invalid
     * IDs in the input are silently dropped before the query so the
     * caller doesn't have to sanitise.
     *
     * @param int[] $queue_ids
     * @return int Rows actually removed.
     */
    public function removeMany(int $user_id, array $queue_ids): int
    {
        $ids = array_values(array_unique(array_filter(
            array_map('intval', $queue_ids),
            static fn(int $x) => $x > 0
        )));
        if (empty($ids)) {
            return 0;
        }
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $sql  = "DELETE FROM processing_queue
                 WHERE user_id = ? AND queue_id IN ($placeholders)";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(array_merge([$user_id], $ids));
        return $stmt->rowCount();
    }

    /**
     * Remove any queue entry the user has for a given observation.
     *
     * Convenience for "undo add" flows that only know the observation_id.
     *
     * @return bool True if a row was removed.
     */
    public function removeObservation(int $user_id, int $observation_id): bool
    {
        $stmt = $this->pdo->prepare(
            'DELETE FROM processing_queue WHERE user_id = ? AND observation_id = ?'
        );
        $stmt->execute([$user_id, $observation_id]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Clear the user's entire queue.
     *
     * @return int Number of rows removed.
     */
    public function clear(int $user_id): int
    {
        $stmt = $this->pdo->prepare('DELETE FROM processing_queue WHERE user_id = ?');
        $stmt->execute([$user_id]);
        return $stmt->rowCount();
    }

    /**
     * Count of items currently in the user's queue.
     */
    public function count(int $user_id): int
    {
        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*) FROM processing_queue WHERE user_id = ?'
        );
        $stmt->execute([$user_id]);
        return (int) $stmt->fetchColumn();
    }

    /**
     * Return the user's queue items, oldest first, with enough joined
     * columns to render the Phase 4 processing hub without extra lookups.
     *
     * @return array<int, array<string, mixed>>
     */
    public function items(int $user_id): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT pq.queue_id, pq.observation_id, pq.added_at,
                    o.native_id,
                    i.instrument_id, i.instrument_abbr,
                    b.body_name,
                    COALESCE(lo.observation_type,
                             so.observation_type,
                             mo.observation_type) AS product_type
             FROM processing_queue pq
             JOIN observations o        ON pq.observation_id = o.observation_id
             JOIN instruments  i        ON o.instrument_id   = i.instrument_id
             JOIN bodies       b        ON o.body_id         = b.body_id
             LEFT JOIN lrs_observations    lo ON lo.observation_id = o.observation_id
             LEFT JOIN sharad_observations so ON so.observation_id = o.observation_id
             LEFT JOIN marsis_observations mo ON mo.observation_id = o.observation_id
             WHERE pq.user_id = ?
             ORDER BY pq.added_at ASC'
        );
        $stmt->execute([$user_id]);
        return $stmt->fetchAll();
    }

    /**
     * observation_ids currently in the user's queue.
     *
     * Cheap lookup used by the observations table to render already-queued
     * checkboxes as pre-checked (or disabled) on page load.
     *
     * @return int[]
     */
    public function observationIds(int $user_id): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT observation_id FROM processing_queue WHERE user_id = ?'
        );
        $stmt->execute([$user_id]);
        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    /**
     * Fetch a specific set of the user's queue items by queue_id.
     *
     * Same joined shape as items() — everything the batch-configure page
     * needs to display and validate the selection without extra lookups.
     * IDs the user doesn't own are silently dropped, so the caller can
     * detect a partial fetch by comparing input vs. output counts.
     *
     * @param int[] $queue_ids
     * @return array<int, array<string, mixed>>
     */
    public function itemsByIds(int $user_id, array $queue_ids): array
    {
        $ids = array_values(array_unique(array_filter(
            array_map('intval', $queue_ids),
            static fn(int $x) => $x > 0
        )));
        if (empty($ids)) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $this->pdo->prepare(
            "SELECT pq.queue_id, pq.observation_id, pq.added_at,
                    o.native_id,
                    i.instrument_id, i.instrument_abbr,
                    b.body_name,
                    COALESCE(lo.observation_type,
                             so.observation_type,
                             mo.observation_type) AS product_type
             FROM processing_queue pq
             JOIN observations o        ON pq.observation_id = o.observation_id
             JOIN instruments  i        ON o.instrument_id   = i.instrument_id
             JOIN bodies       b        ON o.body_id         = b.body_id
             LEFT JOIN lrs_observations    lo ON lo.observation_id = o.observation_id
             LEFT JOIN sharad_observations so ON so.observation_id = o.observation_id
             LEFT JOIN marsis_observations mo ON mo.observation_id = o.observation_id
             WHERE pq.user_id = ? AND pq.queue_id IN ($placeholders)
             ORDER BY pq.added_at ASC"
        );
        $stmt->execute(array_merge([$user_id], $ids));
        return $stmt->fetchAll();
    }
}
