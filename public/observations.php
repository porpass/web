<?php
/**
 * observations.php — Browse and search radar sounder observations.
 *
 * Renders a search form allowing users to filter observations by instrument,
 * body, product type, date range, ground track length, bounding box, and
 * instrument-specific parameters. Results are displayed in a paginated table.
 */

require_once __DIR__ . '/../src/auth.php';
require_once __DIR__ . '/../src/db.php';
require_once __DIR__ . '/../src/layout.php';

use porpass\database\observationQuery;
use porpass\processing\QueueRepository;

session_start_secure();
require_login();

$db      = get_db();
$user_id = (int) $_SESSION['user_id'];

// Observations already in this user's processing queue — used to mark
// results as "queued" instead of offering a redundant checkbox.
$queued_ids_set = array_flip((new QueueRepository($db))->observationIds($user_id));


// ── Populate form dropdowns ────────────────────────────────────────────────

$bodies = $db->query(
    'SELECT b.body_id, b.body_name,
            GROUP_CONCAT(i.instrument_id ORDER BY i.instrument_id) AS instrument_ids,
            GROUP_CONCAT(i.instrument_abbr ORDER BY i.instrument_id) AS instrument_abbrs
     FROM bodies b
     JOIN instrument_bodies ib ON ib.body_id = b.body_id
     JOIN instruments i        ON i.instrument_id = ib.instrument_id
     GROUP BY b.body_id
     ORDER BY b.body_name'
)->fetchAll();

$sharad_modes = $db->query(
    'SELECT mode_id, mode_name, mode_type, presum, bits_per_sample
     FROM sharad_modes
     ORDER BY mode_name'
)->fetchAll();

$marsis_modes = $db->query(
    'SELECT mode_id, mode_name FROM marsis_modes ORDER BY mode_name'
)->fetchAll();

// ── Handle form submission (POST) or GIS link (GET) ─────────────────────

$results      = [];
$files_by_obs = [];
$total        = 0;
$errors       = [];
$searched     = false;
$per_page     = 50;
$current_page = 1;
$offset       = 0;
$sort_col     = 'start_time';
$sort_dir     = 'DESC';

// Accept both POST (form submission) and GET (from GIS "Browse Details" link)
$input = $_SERVER['REQUEST_METHOD'] === 'POST' ? $_POST : $_GET;

if (!empty($input) && (isset($input['instrument_id']) || isset($input['body_id']))) {
    $searched = true;
    // Pagination and sorting
    $per_page     = in_array((int)($input['per_page'] ?? 50), [25, 50, 100]) ? (int)($input['per_page'] ?? 50) : 50;
    $current_page = max(1, (int)($input['page'] ?? 1));
    $offset       = ($current_page - 1) * $per_page;
    $sort_col     = $input['sort_col']  ?? 'start_time';
    $sort_dir     = ($input['sort_dir'] ?? 'DESC') === 'ASC' ? 'ASC' : 'DESC';

    // Core filters
    $instrument_id = !empty($input['instrument_id']) ? (int)$input['instrument_id'] : null;
    $body_id       = !empty($input['body_id'])       ? (int)$input['body_id']       : null;
    $product_type  = !empty($input['product_type'])  ? $input['product_type']        : null;
    $date_start    = !empty($input['date_start'])    ? $input['date_start']          : null;
    $date_end      = !empty($input['date_end'])      ? $input['date_end']            : null;
    $length_min    = ($input['length_min'] ?? '') !== '' ? (float)$input['length_min'] : null;
    $length_max    = ($input['length_max'] ?? '') !== '' ? (float)$input['length_max'] : null;

    // Bounding box
    $bbox_min_lat  = ($input['bbox_min_lat'] ?? '') !== '' ? (float)$input['bbox_min_lat'] : null;
    $bbox_max_lat  = ($input['bbox_max_lat'] ?? '') !== '' ? (float)$input['bbox_max_lat'] : null;
    $bbox_min_lon  = ($input['bbox_min_lon'] ?? '') !== '' ? (float)$input['bbox_min_lon'] : null;
    $bbox_max_lon  = ($input['bbox_max_lon'] ?? '') !== '' ? (float)$input['bbox_max_lon'] : null;

    // Instrument-specific filters
    $lrs_modes    = $input['lrs_modes']    ?? [];
    $sza_min      = ($input['sza_min'] ?? '')  !== '' ? (float)$input['sza_min']  : null;
    $sza_max      = ($input['sza_max'] ?? '')  !== '' ? (float)$input['sza_max']  : null;
    $presums      = $input['presums']      ?? [];
    $max_roll     = ($input['max_roll'] ?? '')  !== '' ? (float)$input['max_roll'] : null;
    $ls_min       = ($input['ls_min'] ?? '')    !== '' ? (float)$input['ls_min']   : null;
    $ls_max       = ($input['ls_max'] ?? '')    !== '' ? (float)$input['ls_max']   : null;
    $marsis_modes_input = $input['marsis_modes'] ?? [];
    $alt_min      = ($input['alt_min'] ?? '')   !== '' ? (float)$input['alt_min']  : null;
    $alt_max      = ($input['alt_max'] ?? '')   !== '' ? (float)$input['alt_max']  : null;
    $orbit_min    = ($input['orbit_min'] ?? '')  !== '' ? (int)$input['orbit_min']  : null;
    $orbit_max    = ($input['orbit_max'] ?? '')  !== '' ? (int)$input['orbit_max']  : null;

    try {
        $q = new observationQuery($db);

        if ($body_id)       $q->setBody($body_id);
        if ($instrument_id) $q->setInstrument($instrument_id);
        if ($product_type)  $q->setProductType($product_type);

        $q->setDateRange($date_start, $date_end);
        $q->setLengthRange($length_min, $length_max);

        if ($bbox_min_lat !== null && $bbox_max_lat !== null &&
            $bbox_min_lon !== null && $bbox_max_lon !== null) {
            $q->setBoundingBox($bbox_min_lat, $bbox_max_lat, $bbox_min_lon, $bbox_max_lon);
        }

        // Instrument-specific
        if ($instrument_id === 1) {
            if (!empty($lrs_modes)) $q->setLrsModes($lrs_modes);
            $q->setSzaRange($sza_min, $sza_max);
        } elseif ($instrument_id === 2) {
            if (!empty($presums))   $q->setPresumValues(array_map('intval', $presums));
            if ($max_roll !== null) $q->setMaxRoll($max_roll);
            $q->setSzaRange($sza_min, $sza_max);
            $q->setLsRange($ls_min, $ls_max);
            $q->setOrbitRange($orbit_min, $orbit_max);
        } elseif ($instrument_id === 3) {
            if (!empty($marsis_modes_input)) $q->setMarsisModes($marsis_modes_input);
            $q->setSzaRange($sza_min, $sza_max);
            $q->setLsRange($ls_min, $ls_max);
            $q->setAltitudeRange($alt_min, $alt_max);
            $q->setOrbitRange($orbit_min, $orbit_max);
        }

        $q->setOrderBy($sort_col, $sort_dir);
        $q->setPagination($per_page, $offset);

        $total   = $q->count();
        $results = $q->execute();

    } catch (\Exception $e) {
        $errors[] = 'Query failed: ' . $e->getMessage();
    }

    // Fetch archive file links (LBL / AUX / SCI / BROWSE) for the result set.
    // File tables are per-instrument, so this only runs when a specific
    // instrument is selected; body-only ("Any instrument") searches show no
    // Files column. Non-fatal: a failure here still renders the results table.
    if ($instrument_id && !empty($results)) {
        try {
            $files_by_obs = $q->fetchFilesForObservations(
                array_column($results, 'observation_id')
            );
        } catch (\Exception $e) {
            $files_by_obs = [];
        }
    }
}

$total_pages = $per_page > 0 ? (int)ceil($total / $per_page) : 1;

open_layout('Browse Observations');
?>

<div class="pp-page-title-row">
    <div>
        <p class="pp-section-label" style="margin-bottom: 0.25rem;">Browse</p>
        <h1 class="pp-page-title-large">Browse observations</h1>
        <p class="pp-lead" style="margin-top: 0.5rem; margin-bottom: 0;">
            Search the PORPASS observation catalogue across all radar sounder instruments.
        </p>
    </div>
</div>

<?php if (!empty($errors)): ?>
<div class="pp-alert pp-alert-danger" style="margin-bottom: 1.5rem;">
    <?php foreach ($errors as $e): ?>
        <div><?= htmlspecialchars($e) ?></div>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<div class="pp-stack">

    <!-- ── Search filters ─────────────────────────────────────────────────── -->
    <div class="pp-panel pp-panel--flush">
        <div class="pp-panel-header">
            <h2 class="pp-panel-header-title">Search filters</h2>
        </div>
        <div class="pp-panel-body">
            <form method="POST" id="search-form">

                <!-- Row 1: Body, Instrument, Product Type, Length -->
                <div class="pp-filter-row">
                    <div class="pp-filter-cell">
                        <label class="pp-filter-label" for="body_id">Body</label>
                        <select name="body_id" id="body_id" class="pp-select">
                            <option value="">— Any —</option>
                            <?php foreach ($bodies as $body): ?>
                            <option value="<?= $body['body_id'] ?>"
                                data-instruments="<?= htmlspecialchars($body['instrument_ids']) ?>"
                                data-instrument-names="<?= htmlspecialchars($body['instrument_abbrs']) ?>"
                                <?= (($input['body_id'] ?? '') == $body['body_id']) ? 'selected' : '' ?>>
                                <?= htmlspecialchars($body['body_name']) ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="pp-filter-cell">
                        <label class="pp-filter-label" for="instrument_id">Instrument</label>
                        <select name="instrument_id" id="instrument_id" class="pp-select">
                            <option value="">— Any —</option>
                            <!-- Populated by JavaScript based on body selection -->
                        </select>
                    </div>
                    <div class="pp-filter-cell">
                        <label class="pp-filter-label" for="product_type">Product type</label>
                        <select name="product_type" id="product_type" class="pp-select">
                            <option value="">— Any —</option>
                            <option value="EDR" <?= (($input['product_type'] ?? '') === 'EDR') ? 'selected' : '' ?>>EDR</option>
                            <option value="RDR" <?= (($input['product_type'] ?? '') === 'RDR') ? 'selected' : '' ?>>RDR</option>
                            <option value="US RDR" <?= (($input['product_type'] ?? '') === 'US RDR') ? 'selected' : '' ?>>US RDR</option>
                        </select>
                    </div>
                    <div class="pp-filter-cell">
                        <label class="pp-filter-label">Ground track length (km)</label>
                        <div class="pp-range-group">
                            <input type="number" name="length_min" class="pp-input"
                                   placeholder="Min" step="0.1" min="0"
                                   value="<?= htmlspecialchars($input['length_min'] ?? '') ?>">
                            <span class="pp-range-group-sep">–</span>
                            <input type="number" name="length_max" class="pp-input"
                                   placeholder="Max" step="0.1" min="0"
                                   value="<?= htmlspecialchars($input['length_max'] ?? '') ?>">
                        </div>
                    </div>
                </div>

                <!-- Row 2: Date range -->
                <div class="pp-filter-row">
                    <div class="pp-filter-cell">
                        <label class="pp-filter-label" for="date_start">Start date</label>
                        <input type="date" name="date_start" id="date_start" class="pp-input"
                               value="<?= htmlspecialchars($input['date_start'] ?? '') ?>">
                    </div>
                    <div class="pp-filter-cell">
                        <label class="pp-filter-label" for="date_end">End date</label>
                        <input type="date" name="date_end" id="date_end" class="pp-input"
                               value="<?= htmlspecialchars($input['date_end'] ?? '') ?>">
                    </div>
                </div>

                <!-- Bounding box (collapsible) -->
                <div style="margin-bottom: 0.5rem;">
                    <a class="pp-collapsible-toggle" data-bs-toggle="collapse"
                       href="#bbox-section" role="button" aria-expanded="<?= !empty($input['bbox_min_lat']) ? 'true' : 'false' ?>">
                        ▸ Geographic bounding box (optional)
                    </a>
                    <div class="collapse <?= (!empty($input['bbox_min_lat'])) ? 'show' : '' ?>"
                         id="bbox-section">
                        <div class="pp-filter-row" style="margin-top: 0.5rem;">
                            <div class="pp-filter-cell">
                                <label class="pp-filter-label">Min latitude</label>
                                <input type="number" name="bbox_min_lat" class="pp-input"
                                       placeholder="-90" step="0.001" min="-90" max="90"
                                       value="<?= htmlspecialchars($input['bbox_min_lat'] ?? '') ?>">
                            </div>
                            <div class="pp-filter-cell">
                                <label class="pp-filter-label">Max latitude</label>
                                <input type="number" name="bbox_max_lat" class="pp-input"
                                       placeholder="90" step="0.001" min="-90" max="90"
                                       value="<?= htmlspecialchars($input['bbox_max_lat'] ?? '') ?>">
                            </div>
                            <div class="pp-filter-cell">
                                <label class="pp-filter-label">Min longitude</label>
                                <input type="number" name="bbox_min_lon" class="pp-input"
                                       placeholder="-180" step="0.001" min="-180" max="180"
                                       value="<?= htmlspecialchars($input['bbox_min_lon'] ?? '') ?>">
                            </div>
                            <div class="pp-filter-cell">
                                <label class="pp-filter-label">Max longitude</label>
                                <input type="number" name="bbox_max_lon" class="pp-input"
                                       placeholder="180" step="0.001" min="-180" max="180"
                                       value="<?= htmlspecialchars($input['bbox_max_lon'] ?? '') ?>">
                            </div>
                        </div>
                    </div>
                </div>

                <!-- ── LRS-specific filters ──────────────────────────────────── -->
                <div id="lrs-filters" class="instrument-filters pp-filter-section" style="display:none;">
                    <p class="pp-filter-section-title">LRS filters</p>
                    <div class="pp-filter-row">
                        <div class="pp-filter-cell">
                            <label class="pp-filter-label">Mode</label>
                            <div id="lrs-edr-modes">
                                <?php foreach (['SW', 'SA'] as $mode): ?>
                                <label class="pp-checkbox">
                                    <input type="checkbox"
                                           name="lrs_modes[]" value="<?= $mode ?>"
                                           id="lrs_<?= $mode ?>"
                                           <?= in_array($mode, $input['lrs_modes'] ?? []) ? 'checked' : '' ?>>
                                    <span><?= $mode ?></span>
                                </label>
                                <?php endforeach; ?>
                            </div>
                            <div id="lrs-rdr-modes" style="display:none;">
                                <?php foreach (['SAR05', 'SAR05C', 'SAR10', 'SAR10C', 'SAR40'] as $mode): ?>
                                <label class="pp-checkbox">
                                    <input type="checkbox"
                                           name="lrs_modes[]" value="<?= $mode ?>"
                                           id="lrs_<?= $mode ?>"
                                           disabled
                                           <?= in_array($mode, $input['lrs_modes'] ?? []) ? 'checked' : '' ?>>
                                    <span><?= $mode ?></span>
                                </label>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        <div class="pp-filter-cell">
                            <label class="pp-filter-label">Solar zenith angle (°)</label>
                            <div class="pp-range-group">
                                <input type="number" name="sza_min" class="pp-input"
                                       placeholder="Min" step="0.1" min="0" max="180"
                                       value="<?= htmlspecialchars($input['sza_min'] ?? '') ?>">
                                <span class="pp-range-group-sep">–</span>
                                <input type="number" name="sza_max" class="pp-input"
                                       placeholder="Max" step="0.1" min="0" max="180"
                                       value="<?= htmlspecialchars($input['sza_max'] ?? '') ?>">
                            </div>
                        </div>
                    </div>
                </div>

                <!-- ── SHARAD-specific filters ───────────────────────────────── -->
                <div id="sharad-filters" class="instrument-filters pp-filter-section" style="display:none;">
                    <p class="pp-filter-section-title">SHARAD filters</p>
                    <div class="pp-filter-row">
                        <div class="pp-filter-cell" id="sharad-presum-wrap" style="grid-column: span 2;">
                            <label class="pp-filter-label">Presum</label>
                            <div>
                                <?php foreach ([1, 2, 4, 8, 16, 28, 32] as $p): ?>
                                <label class="pp-checkbox">
                                    <input type="checkbox"
                                           name="presums[]" value="<?= $p ?>"
                                           id="presum_<?= $p ?>"
                                           <?= in_array($p, $input['presums'] ?? []) ? 'checked' : '' ?>>
                                    <span><?= $p ?></span>
                                </label>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        <div class="pp-filter-cell">
                            <label class="pp-filter-label">Max roll ≤ (°)</label>
                            <input type="number" name="max_roll" class="pp-input"
                                   placeholder="e.g. 20" step="0.1"
                                   value="<?= htmlspecialchars($input['max_roll'] ?? '') ?>">
                        </div>
                        <div class="pp-filter-cell">
                            <label class="pp-filter-label">Orbit number</label>
                            <div class="pp-range-group">
                                <input type="number" name="orbit_min" class="pp-input"
                                       placeholder="Min" step="1" min="0"
                                       value="<?= htmlspecialchars($input['orbit_min'] ?? '') ?>">
                                <span class="pp-range-group-sep">–</span>
                                <input type="number" name="orbit_max" class="pp-input"
                                       placeholder="Max" step="1" min="0"
                                       value="<?= htmlspecialchars($input['orbit_max'] ?? '') ?>">
                            </div>
                        </div>
                        <div class="pp-filter-cell">
                            <label class="pp-filter-label">Solar zenith angle (°)</label>
                            <div class="pp-range-group">
                                <input type="number" name="sza_min" class="pp-input"
                                       placeholder="Min" step="0.1" min="0" max="180"
                                       value="<?= htmlspecialchars($input['sza_min'] ?? '') ?>">
                                <span class="pp-range-group-sep">–</span>
                                <input type="number" name="sza_max" class="pp-input"
                                       placeholder="Max" step="0.1" min="0" max="180"
                                       value="<?= htmlspecialchars($input['sza_max'] ?? '') ?>">
                            </div>
                        </div>
                        <div class="pp-filter-cell">
                            <label class="pp-filter-label">Solar longitude L<sub>s</sub> (°)</label>
                            <div class="pp-range-group">
                                <input type="number" name="ls_min" class="pp-input"
                                       placeholder="Min" step="0.1" min="0" max="360"
                                       value="<?= htmlspecialchars($input['ls_min'] ?? '') ?>">
                                <span class="pp-range-group-sep">–</span>
                                <input type="number" name="ls_max" class="pp-input"
                                       placeholder="Max" step="0.1" min="0" max="360"
                                       value="<?= htmlspecialchars($input['ls_max'] ?? '') ?>">
                            </div>
                        </div>
                    </div>
                </div>

                <!-- ── MARSIS-specific filters ───────────────────────────────── -->
                <div id="marsis-filters" class="instrument-filters pp-filter-section" style="display:none;">
                    <p class="pp-filter-section-title">MARSIS filters</p>
                    <div class="pp-filter-row">
                        <div class="pp-filter-cell" id="marsis-mode-wrap" style="grid-column: span 2;">
                            <label class="pp-filter-label">Mode</label>
                            <div>
                                <?php foreach ($marsis_modes as $m): ?>
                                <label class="pp-checkbox">
                                    <input type="checkbox" class="marsis-mode-cb"
                                           name="marsis_modes[]"
                                           value="<?= htmlspecialchars($m['mode_name']) ?>"
                                           id="marsis_mode_<?= $m['mode_id'] ?>"
                                           data-mode="<?= htmlspecialchars($m['mode_name']) ?>"
                                           <?= in_array($m['mode_name'], $input['marsis_modes'] ?? []) ? 'checked' : '' ?>>
                                    <span><?= htmlspecialchars($m['mode_name']) ?></span>
                                </label>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        <div class="pp-filter-cell">
                            <label class="pp-filter-label">Solar zenith angle (°)</label>
                            <div class="pp-range-group">
                                <input type="number" name="sza_min" class="pp-input"
                                       placeholder="Min" step="0.1" min="0" max="180"
                                       value="<?= htmlspecialchars($input['sza_min'] ?? '') ?>">
                                <span class="pp-range-group-sep">–</span>
                                <input type="number" name="sza_max" class="pp-input"
                                       placeholder="Max" step="0.1" min="0" max="180"
                                       value="<?= htmlspecialchars($input['sza_max'] ?? '') ?>">
                            </div>
                        </div>
                        <div class="pp-filter-cell">
                            <label class="pp-filter-label">Solar longitude L<sub>s</sub> (°)</label>
                            <div class="pp-range-group">
                                <input type="number" name="ls_min" class="pp-input"
                                       placeholder="Min" step="0.1" min="0" max="360"
                                       value="<?= htmlspecialchars($input['ls_min'] ?? '') ?>">
                                <span class="pp-range-group-sep">–</span>
                                <input type="number" name="ls_max" class="pp-input"
                                       placeholder="Max" step="0.1" min="0" max="360"
                                       value="<?= htmlspecialchars($input['ls_max'] ?? '') ?>">
                            </div>
                        </div>
                        <div class="pp-filter-cell">
                            <label class="pp-filter-label">Altitude (km)</label>
                            <div class="pp-range-group">
                                <input type="number" name="alt_min" class="pp-input"
                                       placeholder="Min" step="1" min="0"
                                       value="<?= htmlspecialchars($input['alt_min'] ?? '') ?>">
                                <span class="pp-range-group-sep">–</span>
                                <input type="number" name="alt_max" class="pp-input"
                                       placeholder="Max" step="1" min="0"
                                       value="<?= htmlspecialchars($input['alt_max'] ?? '') ?>">
                            </div>
                        </div>
                        <div class="pp-filter-cell">
                            <label class="pp-filter-label">Orbit number</label>
                            <div class="pp-range-group">
                                <input type="number" name="orbit_min" class="pp-input"
                                       placeholder="Min" step="1" min="0"
                                       value="<?= htmlspecialchars($input['orbit_min'] ?? '') ?>">
                                <span class="pp-range-group-sep">–</span>
                                <input type="number" name="orbit_max" class="pp-input"
                                       placeholder="Max" step="1" min="0"
                                       value="<?= htmlspecialchars($input['orbit_max'] ?? '') ?>">
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Submit row -->
                <div class="pp-form-actions">
                    <button type="submit" class="pp-btn pp-btn-primary">Search</button>
                    <a href="/observations.php" class="pp-btn pp-btn-outline">Reset</a>
                </div>

                <!-- Hidden pagination/sort fields -->
                <input type="hidden" name="page"     value="<?= $current_page ?>">
                <input type="hidden" name="per_page" value="<?= $per_page ?>">
                <input type="hidden" name="sort_col" value="<?= htmlspecialchars($sort_col) ?>">
                <input type="hidden" name="sort_dir" value="<?= htmlspecialchars($sort_dir) ?>">

            </form>
        </div>
    </div>

    <!-- ── Results ────────────────────────────────────────────────────────── -->
    <?php if ($searched): ?>
    <div class="pp-panel pp-panel--flush">
        <div class="pp-panel-header">
            <h2 class="pp-panel-header-title">
                <?= number_format($total) ?> observation<?= $total !== 1 ? 's' : '' ?> found
            </h2>
            <div class="pp-panel-header-actions">
                <?php if ($total > 0): ?>
                <a href="#" id="view-on-map-btn" class="pp-btn-icon" title="View results on GIS map"
                   style="color: var(--amber-dk); border-color: var(--amber); ">
                    🗺 View on map
                </a>
                <?php endif; ?>
                <label class="pp-status-detail" style="margin: 0;">Per page</label>
                <select class="pp-select pp-select--sm"
                        onchange="setPerPage(this.value)">
                    <?php foreach ([25, 50, 100] as $n): ?>
                    <option value="<?= $n ?>" <?= $n === $per_page ? 'selected' : '' ?>><?= $n ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>

        <?php if (empty($results)): ?>
            <div class="pp-empty">No observations matched your search criteria.</div>
        <?php else: ?>
        <div class="pp-table-wrap">
            <table class="pp-table">
                <thead>
                    <tr>
                        <th style="width: 2rem; text-align: center;">
                            <input type="checkbox" id="obs-select-all"
                                   title="Select all on this page">
                        </th>
                        <?php
                        $cols = [
                            'native_id'        => 'Native ID',
                            'instrument_abbr'  => 'Instrument',
                            'body_name'        => 'Body',
                            'start_time'       => 'Start time',
                            'stop_time'        => 'Stop time',
                            'length_km'        => 'Length (km)',
                        ];
                        // Add instrument-specific columns
                        $inst = (int)($input['instrument_id'] ?? 0);
                        if ($inst === 1) {
                            $cols['mode']     = 'Mode';
                            $cols['mean_sza'] = 'Mean SZA (°)';
                        } elseif ($inst === 2) {
                            $cols['mode']          = 'Mode';
                            $cols['orbit_number']  = 'Orbit';
                            $cols['max_roll']      = 'Max roll (°)';
                            $cols['mean_sza']      = 'Mean SZA (°)';
                            $cols['l_s']           = 'L<sub>s</sub> (°)';
                        } elseif ($inst === 3) {
                            $cols['mode']           = 'Mode';
                            $cols['form']           = 'Form';
                            $cols['orbit_number']   = 'Orbit';
                            $cols['mean_sza']       = 'Mean SZA (°)';
                            $cols['l_s']            = 'L<sub>s</sub> (°)';
                            $cols['start_altitude'] = 'Start alt (km)';
                            $cols['stop_altitude']  = 'Stop alt (km)';
                        }
                        // Archive file links — only when a specific instrument is
                        // selected, since the file tables are per-instrument.
                        if ($inst) {
                            $cols['files'] = 'Files';
                        }
                        $sortable = ['start_time', 'stop_time', 'length_km',
                                     'orbit_number', 'max_roll', 'mean_sza', 'l_s',
                                     'start_altitude', 'stop_altitude'];
                        ?>
                        <?php foreach ($cols as $key => $label): ?>
                        <th>
                            <?php if (in_array($key, $sortable)): ?>
                            <a href="#" class="sort-link"
                               data-col="<?= $key ?>"
                               data-dir="<?= $sort_col === $key && $sort_dir === 'ASC' ? 'DESC' : 'ASC' ?>">
                                <?= $label ?>
                                <?php if ($sort_col === $key): ?>
                                    <span class="sort-indicator"><?= $sort_dir === 'ASC' ? '▲' : '▼' ?></span>
                                <?php endif; ?>
                            </a>
                            <?php else: ?>
                                <?= $label ?>
                            <?php endif; ?>
                        </th>
                        <?php endforeach; ?>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($results as $row):
                    $obs_id    = (int) $row['observation_id'];
                    $is_queued = isset($queued_ids_set[$obs_id]);
                ?>
                    <tr>
                        <td style="text-align: center;">
                            <?php if ($is_queued): ?>
                                <span class="pp-badge pp-badge-success"
                                      title="Already in your processing queue">&check;</span>
                            <?php else: ?>
                                <input type="checkbox" class="obs-select"
                                       data-observation-id="<?= $obs_id ?>">
                            <?php endif; ?>
                        </td>
                        <td><code><?= htmlspecialchars($row['native_id']) ?></code></td>
                        <td><?= htmlspecialchars($row['instrument_abbr']) ?></td>
                        <td><?= htmlspecialchars($row['body_name']) ?></td>
                        <td><?= htmlspecialchars($row['start_time'] ?? '—') ?></td>
                        <td><?= htmlspecialchars($row['stop_time']  ?? '—') ?></td>
                        <td><?= $row['length_km'] !== null ? number_format((float)$row['length_km'], 1) : '—' ?></td>
                        <?php if ($inst === 1): ?>
                            <td><?= htmlspecialchars($row['mode']     ?? '—') ?></td>
                            <td><?= $row['mean_sza'] !== null ? number_format((float)$row['mean_sza'], 1) : '—' ?></td>
                        <?php elseif ($inst === 2): ?>
                            <td><?= htmlspecialchars($row['mode']         ?? '—') ?></td>
                            <td><?= htmlspecialchars($row['orbit_number'] ?? '—') ?></td>
                            <td><?= $row['max_roll']  !== null ? number_format((float)$row['max_roll'],  1) : '—' ?></td>
                            <td><?= $row['mean_sza']  !== null ? number_format((float)$row['mean_sza'],  1) : '—' ?></td>
                            <td><?= $row['l_s']       !== null ? number_format((float)$row['l_s'],       1) : '—' ?></td>
                        <?php elseif ($inst === 3): ?>
                            <td><?= htmlspecialchars($row['mode']           ?? '—') ?></td>
                            <td><?= htmlspecialchars($row['form']           ?? '—') ?></td>
                            <td><?= htmlspecialchars($row['orbit_number']   ?? '—') ?></td>
                            <td><?= $row['mean_sza']       !== null ? number_format((float)$row['mean_sza'],       1) : '—' ?></td>
                            <td><?= $row['l_s']            !== null ? number_format((float)$row['l_s'],            1) : '—' ?></td>
                            <td><?= $row['start_altitude'] !== null ? number_format((float)$row['start_altitude'], 1) : '—' ?></td>
                            <td><?= $row['stop_altitude']  !== null ? number_format((float)$row['stop_altitude'],  1) : '—' ?></td>
                        <?php endif; ?>
                        <?php if ($inst): ?>
                            <td>
                                <?php
                                $row_files = $files_by_obs[$row['observation_id']] ?? [];
                                if (!empty($row_files)) {
                                    // Display in a stable, logical order regardless of row order.
                                    $type_rank = ['LBL' => 1, 'AUX' => 2, 'SCI' => 3, 'BROWSE' => 4];
                                    usort($row_files, function ($a, $b) use ($type_rank) {
                                        return ($type_rank[$a['type']] ?? 99) <=> ($type_rank[$b['type']] ?? 99);
                                    });
                                }
                                ?>
                                <?php if (empty($row_files)): ?>
                                    —
                                <?php else: ?>
                                    <span class="pp-file-links">
                                    <?php foreach ($row_files as $i => $f): ?>
                                        <?php if ($i > 0): ?><span class="pp-file-sep">·</span><?php endif; ?>
                                        <a href="<?= htmlspecialchars($f['url']) ?>"
                                           class="pp-file-link"
                                           target="_blank" rel="noopener"
                                           title="<?= htmlspecialchars($f['type']) ?> — opens in a new tab">
                                            <?= htmlspecialchars($f['type']) ?>
                                        </a>
                                    <?php endforeach; ?>
                                    </span>
                                <?php endif; ?>
                            </td>
                        <?php endif; ?>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <!-- Pagination -->
        <?php if ($total_pages > 1): ?>
        <div class="pp-panel-footer">
            <span>
                Showing <?= number_format($offset + 1) ?>–<?= number_format(min($offset + $per_page, $total)) ?>
                of <?= number_format($total) ?>
            </span>
            <nav>
                <ul class="pp-pagination">
                    <li class="<?= $current_page <= 1 ? 'disabled' : '' ?>">
                        <a class="pp-page-link page-nav" href="#" data-page="<?= $current_page - 1 ?>">‹</a>
                    </li>
                    <?php
                    $start_page = max(1, $current_page - 2);
                    $end_page   = min($total_pages, $current_page + 2);
                    if ($start_page > 1): ?>
                        <li>
                            <a class="pp-page-link page-nav" href="#" data-page="1">1</a>
                        </li>
                        <?php if ($start_page > 2): ?>
                            <li class="disabled"><span class="pp-page-link">…</span></li>
                        <?php endif; ?>
                    <?php endif; ?>
                    <?php for ($p = $start_page; $p <= $end_page; $p++): ?>
                    <li class="<?= $p === $current_page ? 'active' : '' ?>">
                        <a class="pp-page-link page-nav" href="#" data-page="<?= $p ?>"><?= $p ?></a>
                    </li>
                    <?php endfor; ?>
                    <?php if ($end_page < $total_pages): ?>
                        <?php if ($end_page < $total_pages - 1): ?>
                            <li class="disabled"><span class="pp-page-link">…</span></li>
                        <?php endif; ?>
                        <li>
                            <a class="pp-page-link page-nav" href="#" data-page="<?= $total_pages ?>">
                                <?= $total_pages ?>
                            </a>
                        </li>
                    <?php endif; ?>
                    <li class="<?= $current_page >= $total_pages ? 'disabled' : '' ?>">
                        <a class="pp-page-link page-nav" href="#" data-page="<?= $current_page + 1 ?>">›</a>
                    </li>
                </ul>
            </nav>
        </div>
        <?php endif; ?>

        <?php endif; ?>
    </div>
    <?php endif; ?>

</div>

<script>
// ── Body → Instrument cascade ─────────────────────────────────────────────

const allInstruments = {
    <?php
    $inst_map = [];
    foreach ($bodies as $body) {
        $ids   = explode(',', $body['instrument_ids']);
        $names = explode(',', $body['instrument_abbrs']);
        $inst_map[$body['body_id']] = array_combine($ids, $names);
    }
    foreach ($inst_map as $bid => $instruments) {
        echo (int)$bid . ': {';
        foreach ($instruments as $id => $name) {
            echo (int)$id . ': ' . json_encode($name) . ',';
        }
        echo '},';
    }
    ?>
}

const savedInstrument = <?= json_encode((int)($input['instrument_id'] ?? 0)) ?>;

function updateInstruments() {
    const bodyId  = parseInt(document.getElementById('body_id').value) || 0;
    const sel     = document.getElementById('instrument_id');
    sel.innerHTML = '<option value="">— Any —</option>';

    if (bodyId && allInstruments[bodyId]) {
        Object.entries(allInstruments[bodyId]).forEach(([id, name]) => {
            const opt    = document.createElement('option');
            opt.value    = id;
            opt.textContent = name;
            if (parseInt(id) === savedInstrument) opt.selected = true;
            sel.appendChild(opt);
        });
    }
    updateInstrumentFilters();
}

// ── Show/hide instrument-specific filter sections ─────────────────────────

function updateInstrumentFilters() {
    const instId = parseInt(document.getElementById('instrument_id').value) || 0;
    document.querySelectorAll('.instrument-filters').forEach(el => {
        el.style.display = 'none';
        el.querySelectorAll('input, select').forEach(inp => inp.disabled = true);
    });
    if (instId === 1) {
        document.getElementById('lrs-filters').style.display = 'block';
        document.getElementById('lrs-filters').querySelectorAll('input, select').forEach(inp => inp.disabled = false);
    }
    if (instId === 2) {
        document.getElementById('sharad-filters').style.display = 'block';
        document.getElementById('sharad-filters').querySelectorAll('input, select').forEach(inp => inp.disabled = false);
    }
    if (instId === 3) {
        document.getElementById('marsis-filters').style.display = 'block';
        document.getElementById('marsis-filters').querySelectorAll('input, select').forEach(inp => inp.disabled = false);
    }
    updateProductTypeOptions();
}

// ── Show/hide product type options and presum based on instrument ────────

function updateProductTypeOptions() {
    const instId      = parseInt(document.getElementById('instrument_id').value) || 0;
    const ptSelect    = document.getElementById('product_type');
    const usRdrOption = ptSelect.querySelector('option[value="US RDR"]');

    // US RDR only applies to SHARAD
    if (usRdrOption) {
        usRdrOption.hidden   = (instId !== 2);
        usRdrOption.disabled = (instId !== 2);
        // Clear selection if US RDR was selected but instrument changed away from SHARAD
        if (instId !== 2 && ptSelect.value === 'US RDR') {
            ptSelect.value = '';
        }
    }

    updateSharadPresumVisibility();
    updateLrsModes();
    updateMarsisModes();
}

function updateSharadPresumVisibility() {
    const presumWrap = document.getElementById('sharad-presum-wrap');
    if (!presumWrap) return;

    const productType = document.getElementById('product_type').value;
    const isUsRdr     = (productType === 'US RDR');

    presumWrap.style.display = isUsRdr ? 'none' : '';
    presumWrap.querySelectorAll('input').forEach(inp => {
        inp.disabled = isUsRdr;
        if (isUsRdr) inp.checked = false;
    });
}

function updateLrsModes() {
    const productType = document.getElementById('product_type').value;
    const isRdr       = (productType === 'RDR');
    const edrDiv      = document.getElementById('lrs-edr-modes');
    const rdrDiv      = document.getElementById('lrs-rdr-modes');
    if (!edrDiv || !rdrDiv) return;

    edrDiv.style.display = isRdr ? 'none' : '';
    edrDiv.querySelectorAll('input').forEach(inp => {
        inp.disabled = isRdr;
        if (isRdr) inp.checked = false;
    });

    rdrDiv.style.display = isRdr ? '' : 'none';
    rdrDiv.querySelectorAll('input').forEach(inp => {
        inp.disabled = !isRdr;
        if (!isRdr) inp.checked = false;
    });
}

function updateMarsisModes() {
    const instId = parseInt(document.getElementById('instrument_id').value) || 0;
    if (instId !== 3) return;  // Only applies to MARSIS

    const productType = document.getElementById('product_type').value;
    const isRdr       = (productType === 'RDR');

    document.querySelectorAll('.marsis-mode-cb').forEach(cb => {
        const isSS3 = (cb.dataset.mode === 'SS3');
        if (isRdr) {
            cb.disabled = !isSS3;
            if (!isSS3) cb.checked = false;
            if (isSS3)  cb.checked = true;
        } else {
            cb.disabled = false;
        }
    });
}

document.getElementById('body_id').addEventListener('change', updateInstruments);
document.getElementById('instrument_id').addEventListener('change', updateInstrumentFilters);
document.getElementById('product_type').addEventListener('change', updateProductTypeOptions);

// Restore state on page load after POST
updateInstruments();

// ── Column sorting ────────────────────────────────────────────────────────

document.querySelectorAll('.sort-link').forEach(link => {
    link.addEventListener('click', function(e) {
        e.preventDefault();
        document.querySelector('[name="sort_col"]').value = this.dataset.col;
        document.querySelector('[name="sort_dir"]').value = this.dataset.dir;
        document.querySelector('[name="page"]').value = 1;
        document.getElementById('search-form').submit();
    });
});

// ── Per-page selector ─────────────────────────────────────────────────────

function setPerPage(n) {
    document.querySelector('[name="per_page"]').value = n;
    document.querySelector('[name="page"]').value = 1;
    document.getElementById('search-form').submit();
}

// ── Pagination links ──────────────────────────────────────────────────────

document.querySelectorAll('.page-nav').forEach(link => {
    link.addEventListener('click', function(e) {
        e.preventDefault();
        document.querySelector('[name="page"]').value = this.dataset.page;
        document.getElementById('search-form').submit();
    });
});

// ── View on Map ──────────────────────────────────────────────────────────

const viewOnMapBtn = document.getElementById('view-on-map-btn');
if (viewOnMapBtn) {
    viewOnMapBtn.addEventListener('click', function(e) {
        e.preventDefault();

        // Map GIS instrument string IDs and body/planet info from PHP instrument_id
        const INST_MAP = {
            1: { gis: 'lrs',    planet: 'moon',   body_id: 3 },
            2: { gis: 'sharad', planet: 'mars',   body_id: 1 },
            3: { gis: 'marsis', planet: 'mars',   body_id: 1 },
        };
        // MARSIS on Phobos
        const MARSIS_PHOBOS = { gis: 'marsis_phobos', planet: 'phobos', body_id: 4 };

        const instId = parseInt(document.getElementById('instrument_id').value) || 0;
        const bodyId = parseInt(document.getElementById('body_id').value) || 0;

        // Determine which GIS instruments to enable
        var gisInstruments = [];
        if (instId > 0) {
            if (instId === 3 && bodyId === 4) {
                gisInstruments.push(MARSIS_PHOBOS);
            } else if (INST_MAP[instId]) {
                gisInstruments.push(INST_MAP[instId]);
            }
        } else if (bodyId > 0) {
            // No specific instrument — enable all for this body
            Object.values(INST_MAP).forEach(function(m) {
                if (m.body_id === bodyId) gisInstruments.push(m);
            });
            if (bodyId === 4) gisInstruments.push(MARSIS_PHOBOS);
        }

        if (gisInstruments.length === 0) {
            alert('Please select a body or instrument before viewing on the map.');
            return;
        }

        // Build query params from the active form fields
        var params = new URLSearchParams();
        params.set('planet', gisInstruments[0].planet);
        params.set('instruments', gisInstruments.map(function(g) { return g.gis; }).join(','));

        // Bounding box
        var bboxMinLat = document.querySelector('[name="bbox_min_lat"]').value;
        var bboxMaxLat = document.querySelector('[name="bbox_max_lat"]').value;
        var bboxMinLon = document.querySelector('[name="bbox_min_lon"]').value;
        var bboxMaxLon = document.querySelector('[name="bbox_max_lon"]').value;
        if (bboxMinLat && bboxMaxLat && bboxMinLon && bboxMaxLon) {
            params.set('bbox', bboxMinLon + ',' + bboxMinLat + ',' + bboxMaxLon + ',' + bboxMaxLat);
        }

        // Instrument-specific filters — translate PHP param names to GIS API names
        if (instId === 2) {  // SHARAD
            var szaMin = document.querySelector('#sharad-filters [name="sza_min"]');
            var szaMax = document.querySelector('#sharad-filters [name="sza_max"]');
            if (szaMin && szaMin.value) params.set('mean_sza_min', szaMin.value);
            if (szaMax && szaMax.value) params.set('mean_sza_max', szaMax.value);

            var lsMin = document.querySelector('#sharad-filters [name="ls_min"]');
            var lsMax = document.querySelector('#sharad-filters [name="ls_max"]');
            if (lsMin && lsMin.value) params.set('l_s_min', lsMin.value);
            if (lsMax && lsMax.value) params.set('l_s_max', lsMax.value);

            var maxRoll = document.querySelector('#sharad-filters [name="max_roll"]');
            if (maxRoll && maxRoll.value) params.set('max_roll_max', maxRoll.value);

            var presums = document.querySelectorAll('#sharad-filters [name="presums[]"]:checked');
            presums.forEach(function(cb) { params.append('presum', cb.value); });

            var orbitMin = document.querySelector('#sharad-filters [name="orbit_min"]');
            var orbitMax = document.querySelector('#sharad-filters [name="orbit_max"]');
            if (orbitMin && orbitMin.value) params.set('orbit_number_min', orbitMin.value);
            if (orbitMax && orbitMax.value) params.set('orbit_number_max', orbitMax.value);

        } else if (instId === 3) {  // MARSIS
            var szaMin = document.querySelector('#marsis-filters [name="sza_min"]');
            var szaMax = document.querySelector('#marsis-filters [name="sza_max"]');
            if (szaMin && szaMin.value) params.set('mean_sza_min', szaMin.value);
            if (szaMax && szaMax.value) params.set('mean_sza_max', szaMax.value);

            var lsMin = document.querySelector('#marsis-filters [name="ls_min"]');
            var lsMax = document.querySelector('#marsis-filters [name="ls_max"]');
            if (lsMin && lsMin.value) params.set('l_s_min', lsMin.value);
            if (lsMax && lsMax.value) params.set('l_s_max', lsMax.value);

        } else if (instId === 1) {  // LRS
            var szaMin = document.querySelector('#lrs-filters [name="sza_min"]');
            var szaMax = document.querySelector('#lrs-filters [name="sza_max"]');
            if (szaMin && szaMin.value) params.set('mean_sza_min', szaMin.value);
            if (szaMax && szaMax.value) params.set('mean_sza_max', szaMax.value);
        }

        window.location.href = '/map.php?' + params.toString();
    });
}

</script>

<!-- ── Processing queue action bar ──────────────────────────────────────────
     Fixed to the viewport bottom; visible only when >=1 result row is checked. -->
<div id="queue-action-bar" role="region" aria-live="polite" style="
    position: fixed; bottom: 1.5rem; left: 50%; transform: translateX(-50%);
    z-index: 1050; box-shadow: 0 6px 20px rgba(0,0,0,0.25);
    display: none; align-items: center; gap: 1rem;
    padding: 0.75rem 1.25rem; min-width: 320px;
    background: #fff; border: 1px solid rgba(0,0,0,0.08); border-radius: 8px;">
  <span><strong id="queue-selected-count">0</strong> selected</span>
  <button type="button" id="queue-clear-btn" class="pp-btn pp-btn-sm pp-btn-outline">Clear</button>
  <button type="button" id="queue-add-btn" class="pp-btn pp-btn-sm pp-btn-primary">
    Add to processing queue
  </button>
</div>

<script>
// ── Processing queue: row selection + POST to /api/processing_queue.php ────
(function () {
    var bar        = document.getElementById('queue-action-bar');
    var countEl    = document.getElementById('queue-selected-count');
    var selectAll  = document.getElementById('obs-select-all');
    var addBtn     = document.getElementById('queue-add-btn');
    var clearBtn   = document.getElementById('queue-clear-btn');
    var checkboxes = Array.prototype.slice.call(document.querySelectorAll('.obs-select'));

    if (!bar || !addBtn) return;

    function selected() {
        return checkboxes.filter(function (cb) { return cb.checked; });
    }
    function updateBar() {
        var n = selected().length;
        countEl.textContent = n;
        bar.style.display = n > 0 ? 'flex' : 'none';
        if (selectAll) {
            selectAll.checked      = n > 0 && n === checkboxes.length;
            selectAll.indeterminate = n > 0 && n < checkboxes.length;
        }
    }
    checkboxes.forEach(function (cb) { cb.addEventListener('change', updateBar); });
    if (selectAll) {
        selectAll.addEventListener('change', function () {
            checkboxes.forEach(function (cb) { cb.checked = selectAll.checked; });
            updateBar();
        });
    }
    clearBtn.addEventListener('click', function () {
        checkboxes.forEach(function (cb) { cb.checked = false; });
        updateBar();
    });
    addBtn.addEventListener('click', function () {
        var sel = selected();
        if (!sel.length) return;
        var ids  = sel.map(function (cb) { return parseInt(cb.dataset.observationId, 10); });
        var orig = addBtn.textContent;
        addBtn.disabled    = true;
        addBtn.textContent = 'Adding…';
        fetch('/api/processing_queue.php', {
            method:  'POST',
            headers: {'Content-Type': 'application/json'},
            body:    JSON.stringify({action: 'add', observation_ids: ids}),
        })
        .then(function (r) { return r.json().then(function (j) { return {r: r, j: j}; }); })
        .then(function (x) {
            addBtn.disabled    = false;
            addBtn.textContent = orig;
            if (!x.r.ok || !x.j.ok) {
                showToast('danger', 'Failed to add to queue: ' + (x.j.error || x.r.statusText));
                return;
            }
            var parts = [x.j.added + ' observation(s) added'];
            if (x.j.skipped > 0) parts.push(x.j.skipped + ' already in queue');
            parts.push('Queue now has ' + x.j.queue_count + '.');
            showToast('success', parts.join(', '));
            updateNavBadge(x.j.queue_count);
            // Replace the checked checkboxes in place with the "queued" badge,
            // matching the pre-queued rendering on next page load.
            sel.forEach(function (cb) {
                var td = cb.parentNode;
                td.innerHTML = '<span class="pp-badge pp-badge-success" '
                    + 'title="Already in your processing queue">✓</span>';
            });
            checkboxes = Array.prototype.slice.call(document.querySelectorAll('.obs-select'));
            updateBar();
        })
        .catch(function (e) {
            addBtn.disabled    = false;
            addBtn.textContent = orig;
            showToast('danger', 'Network error: ' + e.message);
        });
    });

    function updateNavBadge(count) {
        var badge = document.getElementById('nav-queue-badge');
        if (!badge) return;
        badge.textContent   = count;
        badge.style.display = count > 0 ? '' : 'none';
    }

    function showToast(kind, msg) {
        var t = document.createElement('div');
        t.className = 'pp-alert pp-alert-' + kind;
        t.style.cssText = 'position: fixed; top: 5rem; right: 1rem; z-index: 2000; '
            + 'min-width: 280px; max-width: 420px; '
            + 'box-shadow: 0 4px 14px rgba(0,0,0,0.20); '
            + 'transition: opacity 0.4s ease-out;';
        t.innerHTML = msg
            + ' <a href="/processing.php" style="margin-left: 0.5rem; font-weight: 500;">'
            + 'View queue →</a>';
        document.body.appendChild(t);
        setTimeout(function () { t.style.opacity = '0'; }, 3600);
        setTimeout(function () { t.remove(); }, 4100);
    }
})();
</script>

<?php close_layout(); ?>