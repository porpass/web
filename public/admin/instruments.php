<?php
/**
 * instruments.php — Admin page for managing instruments.
 *
 * Provides:
 *   1. Add New — add a new instrument tied to a platform
 *   2. All Instruments — table with inline edit and hard-delete actions
 *
 * Each instrument belongs to a platform (e.g. SHARAD on MRO). Deleting an
 * instrument referenced by observations is constrained by foreign keys on
 * the observation tables.
 */

require_once __DIR__ . '/../../src/auth.php';
require_once __DIR__ . '/../../src/db.php';
require_once __DIR__ . '/../../src/layout.php';

session_start_secure();
require_admin();

$db = get_db();

// ── Handle POST actions ───────────────────────────────────────────────────

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action  = $_POST['action']  ?? '';
    $item_id = (int)($_POST['item_id'] ?? 0);

    if ($action === 'add') {
        $platform_id = (int)($_POST['platform_id'] ?? 0);
        $instrument  = trim($_POST['instrument']      ?? '');
        $abbr        = trim($_POST['instrument_abbr'] ?? '');

        if ($platform_id > 0 && $instrument !== '' && $abbr !== '') {
            $db->prepare(
                'INSERT INTO instruments
                    (platform_id, instrument, instrument_abbr,
                     developer, developer_abbr, description)
                 VALUES (?, ?, ?, ?, ?, ?)'
            )->execute([
                $platform_id,
                $instrument,
                $abbr,
                trim($_POST['developer']      ?? '') ?: null,
                trim($_POST['developer_abbr'] ?? '') ?: null,
                trim($_POST['description']    ?? '') ?: null,
            ]);
        }

    } elseif ($action === 'edit' && $item_id > 0) {
        $platform_id = (int)($_POST['platform_id'] ?? 0);
        $instrument  = trim($_POST['instrument']      ?? '');
        $abbr        = trim($_POST['instrument_abbr'] ?? '');

        if ($platform_id > 0 && $instrument !== '' && $abbr !== '') {
            $db->prepare(
                'UPDATE instruments
                 SET platform_id     = ?,
                     instrument      = ?,
                     instrument_abbr = ?,
                     developer       = ?,
                     developer_abbr  = ?,
                     description     = ?
                 WHERE instrument_id = ?'
            )->execute([
                $platform_id,
                $instrument,
                $abbr,
                trim($_POST['developer']      ?? '') ?: null,
                trim($_POST['developer_abbr'] ?? '') ?: null,
                trim($_POST['description']    ?? '') ?: null,
                $item_id,
            ]);
        }

    } elseif ($action === 'delete' && $item_id > 0) {
        $db->prepare('DELETE FROM instruments WHERE instrument_id = ?')->execute([$item_id]);
    }

    header('Location: /admin/instruments.php');
    exit;
}

// ── Fetch data ────────────────────────────────────────────────────────────

$instruments = $db->query(
    'SELECT i.instrument_id, i.platform_id, i.instrument, i.instrument_abbr,
            i.developer, i.developer_abbr, i.description, i.created_at,
            p.platform, p.platform_abbr
     FROM instruments i
     JOIN platforms p ON i.platform_id = p.platform_id
     ORDER BY p.platform_abbr, i.instrument_abbr'
)->fetchAll();

// Platform dropdown options
$platform_options = $db->query(
    'SELECT platform_id, platform, platform_abbr FROM platforms ORDER BY platform'
)->fetchAll();

open_layout('Manage Instruments');
?>

<div class="pp-page-title-row">
    <div>
        <p class="pp-section-label" style="margin-bottom: 0.25rem;">Admin</p>
        <h1 class="pp-page-title-large">Manage instruments</h1>
        <p class="pp-lead" style="margin-top: 0.5rem; margin-bottom: 0;">
            Radar sounder instruments and the platforms that carry them.
        </p>
    </div>
</div>

<?php if (empty($platform_options)): ?>
    <div class="pp-alert pp-alert-warning" style="margin-bottom: 2rem;">
        No platforms are defined yet. Add a platform before creating instruments.
    </div>
<?php endif; ?>

<!-- ── Add Instrument ─────────────────────────────────────────────────────── -->
<p class="pp-section-label" style="margin-bottom: 1rem;">Add instrument</p>
<div class="pp-panel pp-panel--flush" style="margin-bottom: 2.5rem;">
    <div class="pp-panel-body">
        <form method="POST" action="/admin/instruments.php">
            <input type="hidden" name="action" value="add">
            <div class="pp-filter-row" style="margin-bottom: 1rem;">
                <div class="pp-filter-cell">
                    <label class="pp-filter-label">Platform <span class="pp-required">*</span></label>
                    <select class="pp-select" name="platform_id" required>
                        <option value="">— Select platform —</option>
                        <?php foreach ($platform_options as $p): ?>
                            <option value="<?= $p['platform_id'] ?>">
                                <?= htmlspecialchars($p['platform']) ?>
                                <?= $p['platform_abbr']
                                    ? '(' . htmlspecialchars($p['platform_abbr']) . ')'
                                    : '' ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="pp-filter-cell" style="grid-column: span 2;">
                    <label class="pp-filter-label">Instrument name <span class="pp-required">*</span></label>
                    <input type="text" class="pp-input" name="instrument"
                           maxlength="255"
                           placeholder="e.g. Shallow Radar" required>
                </div>
                <div class="pp-filter-cell">
                    <label class="pp-filter-label">Abbreviation <span class="pp-required">*</span></label>
                    <input type="text" class="pp-input" name="instrument_abbr"
                           maxlength="50" placeholder="e.g. SHARAD" required>
                </div>
            </div>
            <div class="pp-filter-row" style="margin-bottom: 1rem;">
                <div class="pp-filter-cell" style="grid-column: span 2;">
                    <label class="pp-filter-label">Developer</label>
                    <input type="text" class="pp-input" name="developer"
                           maxlength="255" placeholder="e.g. Agenzia Spaziale Italiana">
                </div>
                <div class="pp-filter-cell">
                    <label class="pp-filter-label">Developer abbr.</label>
                    <input type="text" class="pp-input" name="developer_abbr"
                           maxlength="255" placeholder="e.g. ASI">
                </div>
            </div>
            <div class="pp-filter-row" style="margin-bottom: 1rem;">
                <div class="pp-filter-cell" style="grid-column: 1 / -1;">
                    <label class="pp-filter-label">Description</label>
                    <textarea class="pp-textarea" name="description" rows="3"
                              placeholder="Optional description of the instrument."></textarea>
                </div>
            </div>
            <button type="submit" class="pp-btn pp-btn-sm pp-btn-primary"
                    <?= empty($platform_options) ? 'disabled' : '' ?>>
                Add instrument
            </button>
        </form>
    </div>
</div>

<!-- ── All Instruments ────────────────────────────────────────────────────── -->
<p class="pp-section-label" style="margin-bottom: 1rem;">All instruments</p>

<?php if (empty($instruments)): ?>
    <div class="pp-alert pp-alert-info">No instruments defined yet.</div>
<?php else: ?>
<div class="pp-table-wrap">
    <table class="pp-table">
        <thead>
            <tr>
                <th>Platform</th>
                <th>Instrument</th>
                <th>Abbr.</th>
                <th>Developer</th>
                <th>Dev. abbr.</th>
                <th>Description</th>
                <th>Actions</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($instruments as $i):
            $edit_form_id = 'edit-inst-' . (int)$i['instrument_id'];
        ?>
            <tr>
                <td>
                    <select form="<?= $edit_form_id ?>"
                            class="pp-select pp-select--sm" name="platform_id">
                        <?php foreach ($platform_options as $p): ?>
                            <option value="<?= $p['platform_id'] ?>"
                                <?= (int)$i['platform_id'] === (int)$p['platform_id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($p['platform_abbr'] ?: $p['platform']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </td>
                <td>
                    <input form="<?= $edit_form_id ?>" type="text"
                           class="pp-input pp-input--sm"
                           name="instrument" maxlength="255"
                           value="<?= htmlspecialchars($i['instrument']) ?>"
                           required>
                </td>
                <td>
                    <input form="<?= $edit_form_id ?>" type="text"
                           class="pp-input pp-input--sm"
                           name="instrument_abbr" maxlength="50"
                           value="<?= htmlspecialchars($i['instrument_abbr']) ?>"
                           required>
                </td>
                <td>
                    <input form="<?= $edit_form_id ?>" type="text"
                           class="pp-input pp-input--sm"
                           name="developer" maxlength="255"
                           value="<?= htmlspecialchars($i['developer'] ?? '') ?>">
                </td>
                <td>
                    <input form="<?= $edit_form_id ?>" type="text"
                           class="pp-input pp-input--sm"
                           name="developer_abbr" maxlength="255"
                           value="<?= htmlspecialchars($i['developer_abbr'] ?? '') ?>">
                </td>
                <td style="min-width: 18rem;">
                    <textarea form="<?= $edit_form_id ?>"
                              class="pp-textarea" name="description" rows="2"
                              style="min-height: 0;"><?= htmlspecialchars($i['description'] ?? '') ?></textarea>
                </td>
                <td>
                    <div style="display: flex; gap: 0.35rem;">
                        <form id="<?= $edit_form_id ?>" method="POST"
                              action="/admin/instruments.php"
                              style="display: inline;">
                            <input type="hidden" name="action"  value="edit">
                            <input type="hidden" name="item_id" value="<?= $i['instrument_id'] ?>">
                            <button type="submit" class="pp-btn pp-btn-sm pp-btn-primary">Save</button>
                        </form>
                        <form method="POST" action="/admin/instruments.php"
                              style="display: inline;">
                            <input type="hidden" name="action"  value="delete">
                            <input type="hidden" name="item_id" value="<?= $i['instrument_id'] ?>">
                            <button type="submit" class="pp-btn pp-btn-sm pp-btn-danger"
                                    onclick="return confirm('Permanently delete <?= htmlspecialchars($i['instrument_abbr'], ENT_QUOTES) ?>?')">
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