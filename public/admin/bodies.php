<?php
/**
 * bodies.php — Admin page for managing planetary bodies.
 *
 * Provides:
 *   1. Add New — add a new body (planet, moon, asteroid, dwarf planet)
 *   2. All Bodies — table with inline edit and hard-delete actions
 *
 * A body may reference a parent body (e.g. a moon's parent planet) via
 * parent_body_id. Deleting a body referenced as a parent is constrained
 * by the foreign key on bodies.parent_body_id.
 */

require_once __DIR__ . '/../../src/auth.php';
require_once __DIR__ . '/../../src/db.php';
require_once __DIR__ . '/../../src/layout.php';

session_start_secure();
require_admin();

$db = get_db();

// Allowed body_type enum values (must match the column definition)
$body_types = ['planet', 'moon', 'asteroid', 'dwarf planet'];

// Helper: normalise an optional numeric field to a value or null
function num_or_null(string $key): ?string {
    $v = trim($_POST[$key] ?? '');
    return $v === '' ? null : $v;
}

// ── Handle POST actions ───────────────────────────────────────────────────

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action  = $_POST['action']  ?? '';
    $item_id = (int)($_POST['item_id'] ?? 0);

    if ($action === 'add') {
        $body_name = trim($_POST['body_name'] ?? '');
        $body_type = $_POST['body_type'] ?? '';
        $parent_id = (int)($_POST['parent_body_id'] ?? 0) ?: null;

        if ($body_name !== '' && in_array($body_type, $body_types, true)) {
            $db->prepare(
                'INSERT INTO bodies
                    (body_name, body_type, iau_designation, parent_body_id,
                     polar_radius_km, equatorial_radius_km, mean_radius_km)
                 VALUES (?, ?, ?, ?, ?, ?, ?)'
            )->execute([
                $body_name,
                $body_type,
                trim($_POST['iau_designation'] ?? '') ?: null,
                $parent_id,
                num_or_null('polar_radius_km'),
                num_or_null('equatorial_radius_km'),
                num_or_null('mean_radius_km'),
            ]);
        }

    } elseif ($action === 'edit' && $item_id > 0) {
        $body_name = trim($_POST['body_name'] ?? '');
        $body_type = $_POST['body_type'] ?? '';
        // Guard against self-reference
        $parent_id = (int)($_POST['parent_body_id'] ?? 0) ?: null;
        if ($parent_id === $item_id) $parent_id = null;

        if ($body_name !== '' && in_array($body_type, $body_types, true)) {
            $db->prepare(
                'UPDATE bodies
                 SET body_name            = ?,
                     body_type            = ?,
                     iau_designation      = ?,
                     parent_body_id       = ?,
                     polar_radius_km      = ?,
                     equatorial_radius_km = ?,
                     mean_radius_km       = ?
                 WHERE body_id = ?'
            )->execute([
                $body_name,
                $body_type,
                trim($_POST['iau_designation'] ?? '') ?: null,
                $parent_id,
                num_or_null('polar_radius_km'),
                num_or_null('equatorial_radius_km'),
                num_or_null('mean_radius_km'),
                $item_id,
            ]);
        }

    } elseif ($action === 'delete' && $item_id > 0) {
        $db->prepare('DELETE FROM bodies WHERE body_id = ?')->execute([$item_id]);
    }

    header('Location: /admin/bodies.php');
    exit;
}

// ── Fetch data ────────────────────────────────────────────────────────────

$bodies = $db->query(
    'SELECT b.body_id, b.body_name, b.body_type, b.iau_designation,
            b.parent_body_id, b.polar_radius_km, b.equatorial_radius_km,
            b.mean_radius_km, b.created_at,
            p.body_name AS parent_name
     FROM bodies b
     LEFT JOIN bodies p ON b.parent_body_id = p.body_id
     ORDER BY b.body_name'
)->fetchAll();

// Parent-body dropdown options
$body_options = $db->query(
    'SELECT body_id, body_name FROM bodies ORDER BY body_name'
)->fetchAll();

open_layout('Manage Bodies');
?>

<div class="pp-page-title-row">
    <div>
        <p class="pp-section-label" style="margin-bottom: 0.25rem;">Admin</p>
        <h1 class="pp-page-title-large">Manage bodies</h1>
        <p class="pp-lead" style="margin-top: 0.5rem; margin-bottom: 0;">
            Planetary bodies and their physical parameters. Radii are used for
            geodesic track-length calculations and basemap projections.
        </p>
    </div>
</div>

<!-- ── Add Body ───────────────────────────────────────────────────────────── -->
<p class="pp-section-label" style="margin-bottom: 1rem;">Add body</p>
<div class="pp-panel pp-panel--flush" style="margin-bottom: 2.5rem;">
    <div class="pp-panel-body">
        <form method="POST" action="/admin/bodies.php">
            <input type="hidden" name="action" value="add">
            <div class="pp-filter-row" style="margin-bottom: 1rem;">
                <div class="pp-filter-cell" style="grid-column: span 2;">
                    <label class="pp-filter-label">Body name <span class="pp-required">*</span></label>
                    <input type="text" class="pp-input" name="body_name"
                           placeholder="e.g. Mars" required>
                </div>
                <div class="pp-filter-cell">
                    <label class="pp-filter-label">Type <span class="pp-required">*</span></label>
                    <select class="pp-select" name="body_type" required>
                        <?php foreach ($body_types as $bt): ?>
                            <option value="<?= $bt ?>"><?= ucfirst($bt) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="pp-filter-cell">
                    <label class="pp-filter-label">IAU designation</label>
                    <input type="text" class="pp-input" name="iau_designation"
                           placeholder="e.g. 499">
                </div>
                <div class="pp-filter-cell">
                    <label class="pp-filter-label">Parent body</label>
                    <select class="pp-select" name="parent_body_id">
                        <option value="">— None —</option>
                        <?php foreach ($body_options as $opt): ?>
                            <option value="<?= $opt['body_id'] ?>">
                                <?= htmlspecialchars($opt['body_name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <div class="pp-filter-row" style="margin-bottom: 1rem;">
                <div class="pp-filter-cell">
                    <label class="pp-filter-label">Polar radius (km)</label>
                    <input type="number" step="any" min="0" class="pp-input"
                           name="polar_radius_km" placeholder="e.g. 3376.200">
                </div>
                <div class="pp-filter-cell">
                    <label class="pp-filter-label">Equatorial radius (km)</label>
                    <input type="number" step="any" min="0" class="pp-input"
                           name="equatorial_radius_km" placeholder="e.g. 3396.190">
                </div>
                <div class="pp-filter-cell">
                    <label class="pp-filter-label">Mean radius (km)</label>
                    <input type="number" step="any" min="0" class="pp-input"
                           name="mean_radius_km" placeholder="e.g. 3389.500">
                </div>
            </div>
            <button type="submit" class="pp-btn pp-btn-sm pp-btn-primary">Add body</button>
        </form>
    </div>
</div>

<!-- ── All Bodies ─────────────────────────────────────────────────────────── -->
<p class="pp-section-label" style="margin-bottom: 1rem;">All bodies</p>

<?php if (empty($bodies)): ?>
    <div class="pp-alert pp-alert-info">No bodies defined yet.</div>
<?php else: ?>
<div class="pp-table-wrap">
    <table class="pp-table">
        <thead>
            <tr>
                <th>Name</th>
                <th>Type</th>
                <th>IAU desig.</th>
                <th>Parent</th>
                <th>Polar r (km)</th>
                <th>Equat. r (km)</th>
                <th>Mean r (km)</th>
                <th>Actions</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($bodies as $b):
            $edit_form_id = 'edit-body-' . (int)$b['body_id'];
        ?>
            <tr>
                <td>
                    <input form="<?= $edit_form_id ?>" type="text"
                           class="pp-input pp-input--sm"
                           name="body_name"
                           value="<?= htmlspecialchars($b['body_name']) ?>"
                           required>
                </td>
                <td>
                    <select form="<?= $edit_form_id ?>"
                            class="pp-select pp-select--sm" name="body_type">
                        <?php foreach ($body_types as $bt): ?>
                            <option value="<?= $bt ?>"
                                <?= $b['body_type'] === $bt ? 'selected' : '' ?>>
                                <?= ucfirst($bt) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </td>
                <td>
                    <input form="<?= $edit_form_id ?>" type="text"
                           class="pp-input pp-input--sm"
                           name="iau_designation"
                           value="<?= htmlspecialchars($b['iau_designation'] ?? '') ?>">
                </td>
                <td>
                    <select form="<?= $edit_form_id ?>"
                            class="pp-select pp-select--sm" name="parent_body_id">
                        <option value="">— None —</option>
                        <?php foreach ($body_options as $opt): ?>
                            <?php if ((int)$opt['body_id'] === (int)$b['body_id']) continue; ?>
                            <option value="<?= $opt['body_id'] ?>"
                                <?= (int)$b['parent_body_id'] === (int)$opt['body_id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($opt['body_name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </td>
                <td>
                    <input form="<?= $edit_form_id ?>" type="number" step="any" min="0"
                           class="pp-input pp-input--sm"
                           name="polar_radius_km"
                           value="<?= htmlspecialchars($b['polar_radius_km'] ?? '') ?>">
                </td>
                <td>
                    <input form="<?= $edit_form_id ?>" type="number" step="any" min="0"
                           class="pp-input pp-input--sm"
                           name="equatorial_radius_km"
                           value="<?= htmlspecialchars($b['equatorial_radius_km'] ?? '') ?>">
                </td>
                <td>
                    <input form="<?= $edit_form_id ?>" type="number" step="any" min="0"
                           class="pp-input pp-input--sm"
                           name="mean_radius_km"
                           value="<?= htmlspecialchars($b['mean_radius_km'] ?? '') ?>">
                </td>
                <td>
                    <div style="display: flex; gap: 0.35rem;">
                        <form id="<?= $edit_form_id ?>" method="POST"
                              action="/admin/bodies.php"
                              style="display: inline;">
                            <input type="hidden" name="action"  value="edit">
                            <input type="hidden" name="item_id" value="<?= $b['body_id'] ?>">
                            <button type="submit" class="pp-btn pp-btn-sm pp-btn-primary">Save</button>
                        </form>
                        <form method="POST" action="/admin/bodies.php"
                              style="display: inline;">
                            <input type="hidden" name="action"  value="delete">
                            <input type="hidden" name="item_id" value="<?= $b['body_id'] ?>">
                            <button type="submit" class="pp-btn pp-btn-sm pp-btn-danger"
                                    onclick="return confirm('Permanently delete <?= htmlspecialchars($b['body_name'], ENT_QUOTES) ?>?')">
                                Delete
                            </button>
                        </form>
                    </div>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php endif; ?>

<?php close_layout(); ?>