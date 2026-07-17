<?php
/**
 * announcements.php — Admin page for managing system announcements.
 *
 * Allows admins to create, edit, activate/deactivate, and delete
 * announcements that appear on the user dashboard. Announcements
 * expire after 90 days by default but the expiry date can be overridden.
 */

require_once __DIR__ . '/../../src/auth.php';
require_once __DIR__ . '/../../src/db.php';
require_once __DIR__ . '/../../src/layout.php';

session_start_secure();
require_admin();

$db     = get_db();
$errors = [];
$success = '';

// ── Handle POST actions ───────────────────────────────────────────────────

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action          = $_POST['action']          ?? '';
    $announcement_id = (int)($_POST['announcement_id'] ?? 0);

    if ($action === 'add') {
        $title      = trim($_POST['title']      ?? '');
        $body       = trim($_POST['body']       ?? '');
        $expires_at = trim($_POST['expires_at'] ?? '');
        $is_active  = isset($_POST['is_active']) ? 1 : 0;

        if (empty($title))  $errors['title'] = 'Title is required.';
        if (empty($body))   $errors['body']  = 'Body is required.';

        if (empty($errors)) {
            $expires = !empty($expires_at)
                ? $expires_at
                : date('Y-m-d H:i:s', strtotime('+90 days'));

            $db->prepare(
                'INSERT INTO announcements (title, body, is_active, created_by, expires_at)
                 VALUES (?, ?, ?, ?, ?)'
            )->execute([$title, $body, $is_active, $_SESSION['user_id'], $expires]);
            $success = 'Announcement added.';
        }

    } elseif ($action === 'edit' && $announcement_id > 0) {
        $title      = trim($_POST['title']      ?? '');
        $body       = trim($_POST['body']       ?? '');
        $expires_at = trim($_POST['expires_at'] ?? '');
        $is_active  = isset($_POST['is_active']) ? 1 : 0;

        if (empty($title))  $errors['title'] = 'Title is required.';
        if (empty($body))   $errors['body']  = 'Body is required.';

        if (empty($errors)) {
            $db->prepare(
                'UPDATE announcements
                 SET title = ?, body = ?, is_active = ?, expires_at = ?
                 WHERE announcement_id = ?'
            )->execute([$title, $body, $is_active, $expires_at, $announcement_id]);
            $success = 'Announcement updated.';
        }

    } elseif ($action === 'toggle' && $announcement_id > 0) {
        $db->prepare(
            'UPDATE announcements SET is_active = NOT is_active
             WHERE announcement_id = ?'
        )->execute([$announcement_id]);
        header('Location: /admin/announcements.php');
        exit;

    } elseif ($action === 'delete' && $announcement_id > 0) {
        $db->prepare(
            'DELETE FROM announcements WHERE announcement_id = ?'
        )->execute([$announcement_id]);
        header('Location: /admin/announcements.php');
        exit;
    }
}

// ── Fetch all announcements ───────────────────────────────────────────────

$announcements = $db->query(
    'SELECT a.announcement_id, a.title, a.body, a.is_active,
            a.created_at, a.expires_at,
            u.username AS created_by
     FROM announcements a
     JOIN users u ON a.created_by = u.user_id
     ORDER BY a.created_at DESC'
)->fetchAll();

open_layout('Manage Announcements');
?>

<div class="pp-page-title-row">
    <div>
        <p class="pp-section-label" style="margin-bottom: 0.25rem;">Admin</p>
        <h1 class="pp-page-title-large">Manage announcements</h1>
        <p class="pp-lead" style="margin-top: 0.5rem; margin-bottom: 0;">
            Announcements appear on the user dashboard. They expire after 90 days
            by default and are only shown to users when active and not expired.
        </p>
    </div>
</div>

<!-- ── Add Announcement ───────────────────────────────────────────────────── -->
<div class="pp-panel pp-panel--flush" style="margin-bottom: 2.5rem;">
    <div class="pp-panel-header">
        <h2 class="pp-panel-header-title">Add announcement</h2>
    </div>
    <div class="pp-panel-body">

        <?php if ($success): ?>
            <div class="pp-alert pp-alert-success"><?= htmlspecialchars($success) ?></div>
        <?php endif; ?>

        <form method="POST" action="/admin/announcements.php">
            <input type="hidden" name="action" value="add">

            <div class="pp-field">
                <label for="title" class="pp-label">
                    Title <span class="pp-required">*</span>
                </label>
                <input type="text"
                       class="pp-input <?= isset($errors['title']) ? 'is-invalid' : '' ?>"
                       id="title" name="title"
                       value="<?= htmlspecialchars($_POST['title'] ?? '') ?>"
                       required>
                <?php if (isset($errors['title'])): ?>
                    <div class="pp-field-error"><?= htmlspecialchars($errors['title']) ?></div>
                <?php endif; ?>
            </div>

            <div class="pp-field">
                <label for="body" class="pp-label">
                    Body <span class="pp-required">*</span>
                </label>
                <textarea class="pp-textarea <?= isset($errors['body']) ? 'is-invalid' : '' ?>"
                          id="body" name="body" rows="4"
                          placeholder="Announcement text. Basic HTML is supported."
                          required><?= htmlspecialchars($_POST['body'] ?? '') ?></textarea>
                <?php if (isset($errors['body'])): ?>
                    <div class="pp-field-error"><?= htmlspecialchars($errors['body']) ?></div>
                <?php endif; ?>
            </div>

            <div class="row g-3 mb-3">
                <div class="col-md-4">
                    <label for="expires_at" class="pp-label">
                        Expires At
                    </label>
                    <input type="datetime-local"
                           class="pp-input"
                           id="expires_at" name="expires_at"
                           value="<?= date('Y-m-d\TH:i', strtotime('+90 days')) ?>">
                    <div class="pp-field-hint">Defaults to 90 days from now.</div>
                </div>
                <div class="col-md-4 d-flex align-items-center">
                    <div class="form-check mt-3">
                        <input class="form-check-input" type="checkbox"
                               id="is_active" name="is_active" checked>
                        <label class="form-check-label" for="is_active">
                            Active (visible to users immediately)
                        </label>
                    </div>
                </div>
            </div>

            <button type="submit" class="pp-btn pp-btn-primary pp-btn-sm">
                Post Announcement
            </button>
        </form>
    </div>
</div>

<!-- ── Existing Announcements ─────────────────────────────────────────────── -->
<h2 class="pp-panel-title" style="font-size: 1.3rem; margin: 0 0 1rem;">All announcements</h2>

<?php if (empty($announcements)): ?>
    <div class="pp-alert pp-alert-info">No announcements yet.</div>
<?php else: ?>

<?php foreach ($announcements as $ann): ?>
<?php
    $is_expired = strtotime($ann['expires_at']) < time();
    $panel_mod  = ($ann['is_active'] && !$is_expired) ? 'pp-panel--success' : '';
?>
<div class="pp-panel <?= $panel_mod ?>" style="margin-bottom: 1rem;">

    <div style="display: flex; justify-content: space-between; align-items: center; gap: 1rem; flex-wrap: wrap; margin-bottom: 1.25rem;">
        <span>
            <strong><?= htmlspecialchars($ann['title']) ?></strong>
            <span style="margin-left: 0.5rem;">
                <?php if ($is_expired): ?>
                    <span class="pp-badge pp-badge-muted">Expired</span>
                <?php elseif ($ann['is_active']): ?>
                    <span class="pp-badge pp-badge-success">Active</span>
                <?php else: ?>
                    <span class="pp-badge pp-badge-warning">Inactive</span>
                <?php endif; ?>
            </span>
        </span>
        <span class="pp-status-detail">
            Posted by <?= htmlspecialchars($ann['created_by']) ?>
            on <?= htmlspecialchars($ann['created_at']) ?>
            &mdash; Expires <?= htmlspecialchars($ann['expires_at']) ?>
        </span>
    </div>

    <!-- Edit form -->
    <form method="POST" action="/admin/announcements.php"
          id="ann-edit-<?= $ann['announcement_id'] ?>">
        <input type="hidden" name="action"          value="edit">
        <input type="hidden" name="announcement_id" value="<?= $ann['announcement_id'] ?>">

        <div class="pp-field">
            <input type="text" class="pp-input"
                   name="title"
                   value="<?= htmlspecialchars($ann['title']) ?>"
                   required>
        </div>
        <div class="pp-field">
            <textarea class="pp-textarea"
                      name="body" rows="3"
                      required><?= htmlspecialchars($ann['body']) ?></textarea>
        </div>
        <div class="row g-2 mb-2">
            <div class="col-md-4">
                <input type="datetime-local"
                       class="pp-input"
                       name="expires_at"
                       value="<?= date('Y-m-d\TH:i', strtotime($ann['expires_at'])) ?>">
            </div>
            <div class="col-md-4 d-flex align-items-center">
                <div class="form-check">
                    <input class="form-check-input" type="checkbox"
                           name="is_active"
                           <?= $ann['is_active'] ? 'checked' : '' ?>>
                    <label class="form-check-label small">Active</label>
                </div>
            </div>
        </div>
    </form>

    <!-- Action buttons (forms kept un-nested; Save targets the edit form by id) -->
    <div class="d-flex gap-2" style="margin-top: 1rem;">
        <button type="submit" form="ann-edit-<?= $ann['announcement_id'] ?>"
                class="pp-btn pp-btn-primary pp-btn-sm">Save</button>

        <!-- Toggle active/inactive -->
        <form method="POST" action="/admin/announcements.php" style="display:inline;">
            <input type="hidden" name="action"          value="toggle">
            <input type="hidden" name="announcement_id" value="<?= $ann['announcement_id'] ?>">
            <button type="submit" class="pp-btn pp-btn-outline pp-btn-sm">
                <?= $ann['is_active'] ? 'Deactivate' : 'Activate' ?>
            </button>
        </form>

        <!-- Delete -->
        <form method="POST" action="/admin/announcements.php" style="display:inline;">
            <input type="hidden" name="action"          value="delete">
            <input type="hidden" name="announcement_id" value="<?= $ann['announcement_id'] ?>">
            <button type="submit" class="pp-btn pp-btn-danger pp-btn-sm"
                    onclick="return confirm('Permanently delete this announcement?')">
                Delete
            </button>
        </form>
    </div>

</div>
<?php endforeach; ?>
<?php endif; ?>

<?php close_layout(); ?>