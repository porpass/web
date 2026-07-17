<?php
/**
 * change_requests.php — Admin page for reviewing pending user change requests.
 *
 * Displays all pending institution and department change requests submitted
 * by users via Account Settings. Admins can approve or reject each request.
 * Approved institution/department changes are applied immediately to the
 * users table. Name and email changes are handled automatically and will
 * appear in the log with status 'approved' for audit purposes.
 */

require_once __DIR__ . '/../../src/auth.php';
require_once __DIR__ . '/../../src/db.php';
require_once __DIR__ . '/../../src/layout.php';

session_start_secure();
require_admin();

$db = get_db();

// ── Handle POST actions ───────────────────────────────────────────────────

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action     = $_POST['action']     ?? '';
    $request_id = (int)($_POST['request_id'] ?? 0);
    $notes      = trim($_POST['notes'] ?? '');

    if ($request_id > 0) {
        // Fetch the request
        $stmt = $db->prepare(
            'SELECT * FROM user_change_requests WHERE request_id = ? AND status = ?'
        );
        $stmt->execute([$request_id, 'pending']);
        $request = $stmt->fetch();

        if ($request) {
            if ($action === 'approve') {
                // Apply the change to the users table
                if ($request['field'] === 'institution_id') {
                    $db->prepare(
                        'UPDATE users SET institution_id = ? WHERE user_id = ?'
                    )->execute([$request['new_value'], $request['user_id']]);
                } elseif ($request['field'] === 'department_id') {
                    $db->prepare(
                        'UPDATE users SET department_id = ? WHERE user_id = ?'
                    )->execute([$request['new_value'], $request['user_id']]);
                }

                // Mark request as approved
                $db->prepare(
                    'UPDATE user_change_requests
                     SET status      = ?,
                         reviewed_by = ?,
                         reviewed_at = NOW(),
                         notes       = ?
                     WHERE request_id = ?'
                )->execute(['approved', $_SESSION['user_id'], $notes, $request_id]);

            } elseif ($action === 'reject') {
                $db->prepare(
                    'UPDATE user_change_requests
                     SET status      = ?,
                         reviewed_by = ?,
                         reviewed_at = NOW(),
                         notes       = ?
                     WHERE request_id = ?'
                )->execute(['rejected', $_SESSION['user_id'], $notes, $request_id]);
            }
        }
    }

    header('Location: /admin/change_requests.php');
    exit;
}

// ── Fetch pending institution/department change requests ──────────────────

$pending = $db->query(
    'SELECT cr.request_id, cr.user_id, cr.field, cr.old_value, cr.new_value,
            cr.requested_at,
            u.username, u.first_name, u.last_name, u.email,
            i_old.institution    AS old_institution,
            i_old.institution_abbr AS old_institution_abbr,
            d_old.department     AS old_department,
            i_new.institution    AS new_institution,
            i_new.institution_abbr AS new_institution_abbr,
            d_new.department     AS new_department
     FROM user_change_requests cr
     JOIN users u ON cr.user_id = u.user_id
     LEFT JOIN institutions i_old ON cr.field = \'institution_id\'
                                  AND cr.old_value = i_old.institution_id
     LEFT JOIN institutions i_new ON cr.field = \'institution_id\'
                                  AND cr.new_value = i_new.institution_id
     LEFT JOIN departments d_old  ON cr.field = \'department_id\'
                                  AND cr.old_value = d_old.department_id
     LEFT JOIN departments d_new  ON cr.field = \'department_id\'
                                  AND cr.new_value = d_new.department_id
     WHERE cr.status = \'pending\'
       AND cr.field IN (\'institution_id\', \'department_id\')
     ORDER BY cr.requested_at ASC'
)->fetchAll();

// ── Fetch full change log (all statuses, all fields) ──────────────────────

$log = $db->query(
    'SELECT cr.request_id, cr.field, cr.old_value, cr.new_value,
            cr.status, cr.requested_at, cr.reviewed_at, cr.notes,
            u.username,
            r.username AS reviewed_by_username
     FROM user_change_requests cr
     JOIN users u ON cr.user_id = u.user_id
     LEFT JOIN users r ON cr.reviewed_by = r.user_id
     ORDER BY cr.requested_at DESC
     LIMIT 100'
)->fetchAll();

open_layout('Change Requests');
?>

<div class="pp-page-title-row">
    <div>
        <p class="pp-section-label" style="margin-bottom: 0.25rem;">Admin</p>
        <h1 class="pp-page-title-large">Change requests</h1>
        <p class="pp-lead" style="margin-top: 0.5rem; margin-bottom: 0;">
            Review pending institution and department changes, and audit the full change log.
        </p>
    </div>
</div>

<!-- ── Pending institution/department changes ────────────────────────────── -->
<h2 class="pp-panel-title" style="font-size: 1.3rem; margin: 0 0 1rem;">
    Pending approval
    <?php if (!empty($pending)): ?>
        <span class="pp-badge pp-badge-warning" style="margin-left: 0.5rem; vertical-align: middle;"><?= count($pending) ?></span>
    <?php endif; ?>
</h2>

<?php if (empty($pending)): ?>
    <div class="pp-alert pp-alert-info" style="margin-bottom: 2.5rem;">
        No institution or department changes pending approval.
    </div>
<?php else: ?>

<div style="margin-bottom: 2.5rem;">
<?php foreach ($pending as $req): ?>
<div class="pp-panel pp-panel--warning" style="margin-bottom: 1rem;">

    <div style="display: flex; justify-content: space-between; align-items: center; gap: 1rem; flex-wrap: wrap; margin-bottom: 1.25rem;">
        <span>
            <strong><?= htmlspecialchars($req['username']) ?></strong>
            (<?= htmlspecialchars($req['first_name'] . ' ' . $req['last_name']) ?>)
            &mdash; <?= htmlspecialchars($req['email']) ?>
        </span>
        <span class="pp-status-detail">
            <?= htmlspecialchars($req['requested_at']) ?>
        </span>
    </div>

    <dl class="pp-info-grid" style="margin-bottom: 1.5rem;">
        <dt>Field</dt>
        <dd>
            <span class="pp-badge pp-badge-muted">
                <?= htmlspecialchars($req['field']) ?>
            </span>
        </dd>

        <dt>Current value</dt>
        <dd>
            <?php if ($req['field'] === 'institution_id'): ?>
                <?= $req['old_institution']
                    ? htmlspecialchars($req['old_institution'])
                      . ($req['old_institution_abbr']
                          ? ' (' . htmlspecialchars($req['old_institution_abbr']) . ')'
                          : '')
                    : '<em class="pp-status-detail">None</em>' ?>
            <?php else: ?>
                <?= $req['old_department']
                    ? htmlspecialchars($req['old_department'])
                    : '<em class="pp-status-detail">None</em>' ?>
            <?php endif; ?>
        </dd>

        <dt>Requested value</dt>
        <dd>
            <?php if ($req['field'] === 'institution_id'): ?>
                <?php if ($req['new_institution']): ?>
                    <?= htmlspecialchars($req['new_institution']) ?>
                    <?= $req['new_institution_abbr']
                        ? '(' . htmlspecialchars($req['new_institution_abbr']) . ')'
                        : '' ?>
                <?php else: ?>
                    <em class="pp-status-detail">Institution ID: <?= htmlspecialchars($req['new_value']) ?></em>
                <?php endif; ?>
            <?php else: ?>
                <?= $req['new_department']
                    ? htmlspecialchars($req['new_department'])
                    : '<em class="pp-status-detail">Department ID: ' . htmlspecialchars($req['new_value']) . '</em>' ?>
            <?php endif; ?>
        </dd>
    </dl>

    <form method="POST" action="/admin/change_requests.php">
        <input type="hidden" name="request_id" value="<?= $req['request_id'] ?>">
        <div class="pp-field">
            <label for="notes_<?= $req['request_id'] ?>" class="pp-label">
                Notes (optional)
            </label>
            <input type="text"
                   class="pp-input"
                   id="notes_<?= $req['request_id'] ?>"
                   name="notes"
                   placeholder="Reason for approval or rejection">
        </div>
        <div class="d-flex gap-2">
            <button type="submit" name="action" value="approve"
                    class="pp-btn pp-btn-success pp-btn-sm">
                Approve
            </button>
            <button type="submit" name="action" value="reject"
                    class="pp-btn pp-btn-danger pp-btn-sm"
                    onclick="return confirm('Reject this change request?')">
                Reject
            </button>
        </div>
    </form>

</div>
<?php endforeach; ?>
</div>

<?php endif; ?>

<!-- ── Change log ─────────────────────────────────────────────────────────── -->
<h2 class="pp-panel-title" style="font-size: 1.3rem; margin: 0 0 1rem;">Change log</h2>

<div class="pp-panel pp-panel--flush">
    <div class="pp-panel-header">
        <h3 class="pp-panel-header-title">
            <?= count($log) ?> entr<?= count($log) !== 1 ? 'ies' : 'y' ?>
        </h3>
    </div>

<?php if (empty($log)): ?>
    <div class="pp-empty">No change requests recorded yet.</div>
<?php else: ?>
<div class="pp-table-wrap">
    <table class="pp-table">
        <thead>
            <tr>
                <th>User</th>
                <th>Field</th>
                <th>Old Value</th>
                <th>New Value</th>
                <th>Status</th>
                <th>Requested</th>
                <th>Reviewed By</th>
                <th>Reviewed At</th>
                <th>Notes</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($log as $entry): ?>
            <tr>
                <td><?= htmlspecialchars($entry['username']) ?></td>
                <td>
                    <span class="pp-badge pp-badge-muted">
                        <?= htmlspecialchars($entry['field']) ?>
                    </span>
                </td>
                <td><?= htmlspecialchars($entry['old_value'] ?? '—') ?></td>
                <td><?= htmlspecialchars($entry['new_value'] ?? '—') ?></td>
                <td>
                    <?php
                    $badge = match($entry['status']) {
                        'approved' => 'pp-badge-success',
                        'rejected' => 'pp-badge-danger',
                        'expired'  => 'pp-badge-muted',
                        default    => 'pp-badge-warning',
                    };
                    ?>
                    <span class="pp-badge <?= $badge ?>">
                        <?= htmlspecialchars($entry['status']) ?>
                    </span>
                </td>
                <td class="pp-status-detail"><?= htmlspecialchars($entry['requested_at']) ?></td>
                <td><?= htmlspecialchars($entry['reviewed_by_username'] ?? '—') ?></td>
                <td class="pp-status-detail"><?= htmlspecialchars($entry['reviewed_at'] ?? '—') ?></td>
                <td><?= htmlspecialchars($entry['notes'] ?? '—') ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div><!-- /.pp-table-wrap -->
<?php endif; ?>
</div><!-- /.pp-panel -->

<?php close_layout(); ?>