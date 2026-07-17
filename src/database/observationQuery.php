<?php
/**
 * observationQuery.php — Dynamic query builder for the Browse Observations page.
 *
 * Builds and executes parameterised PDO queries against the observations table
 * and its instrument-specific child tables (lrs_observations, sharad_observations,
 * marsis_observations). Filters are applied only when set, so unset filters do
 * not affect the query.
 *
 * Usage:
 *   $q = new \porpass\database\observationQuery(get_db());
 *   $q->setInstrument(2)
 *     ->setDateRange('2010-01-01', '2012-12-31')
 *     ->setMaxRoll(20.0)
 *     ->setPagination(50, 0);
 *   $results = $q->execute();
 *   $total   = $q->count();
 */

namespace porpass\database;

use PDO;
use InvalidArgumentException;

class observationQuery
{
    // ── Valid instrument IDs ───────────────────────────────────────────────────
    private const INST_LRS    = 1;
    private const INST_SHARAD = 2;
    private const INST_MARSIS = 3;

    // ── Per-instrument archive file tables ─────────────────────────────────────
    // Whitelist used to safely interpolate the table name in fetchFilesForObservations().
    private const FILE_TABLES = [
        self::INST_LRS    => 'lrs_files',
        self::INST_SHARAD => 'sharad_files',
        self::INST_MARSIS => 'marsis_files',
    ];

    // ── Column whitelist for ORDER BY ─────────────────────────────────────────
    private const SORTABLE_COLUMNS = [
        'start_time', 'stop_time', 'duration', 'length_km',
        'native_id', 'orbit_number', 'max_roll', 'mean_sza', 'l_s',
        'start_altitude', 'stop_altitude',
    ];

    private PDO    $pdo;
    private array  $joins   = [];
    private array  $wheres  = [];
    private array  $params  = [];
    private int    $perPage = 50;
    private int    $offset  = 0;
    private string $orderBy = 'o.start_time';
    private string $orderDir = 'DESC';

    // Filter state
    private ?int    $instrumentId   = null;
    private ?int    $bodyId         = null;
    private ?string $productType    = null;
    private ?string $dateStart      = null;
    private ?string $dateEnd        = null;
    private ?float  $lengthMin      = null;
    private ?float  $lengthMax      = null;
    private ?float  $bboxMinLat     = null;
    private ?float  $bboxMaxLat     = null;
    private ?float  $bboxMinLon     = null;
    private ?float  $bboxMaxLon     = null;

    // LRS filters
    private array   $lrsModes       = [];
    private ?float  $szaMin         = null;
    private ?float  $szaMax         = null;

    // SHARAD filters
    private array   $presumValues   = [];
    private ?float  $maxRollMax     = null;
    private ?float  $lsMin          = null;
    private ?float  $lsMax          = null;
    private ?int    $orbitMin       = null;
    private ?int    $orbitMax       = null;

    // MARSIS filters
    private array   $marsisModes    = [];
    private array   $marsisForms    = [];
    private ?float  $altMin         = null;
    private ?float  $altMax         = null;

    /**
     * @param PDO $pdo Shared PDO connection from get_db().
     */
    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    // ── Common filters ────────────────────────────────────────────────────────

    /**
     * Filter by body.
     *
     * @param int $bodyId The body_id from the bodies table.
     * @return static
     */
    public function setBody(int $bodyId): static
    {
        $this->bodyId = $bodyId;
        return $this;
    }

    /**
     * Filter by instrument.
     *
     * @param int $instrumentId The instrument_id from the instruments table.
     * @return static
     */
    public function setInstrument(int $instrumentId): static
    {
        $this->instrumentId = $instrumentId;
        return $this;
    }

    /**
     * Filter by product type.
     *
     * Valid values per instrument:
     *   LRS:    'EDR', 'RDR'
     *   SHARAD: 'EDR', 'RDR', 'US RDR'
     *   MARSIS: 'EDR', 'RDR'
     *
     * @param string $type Product type string matching observation_type ENUM.
     * @return static
     */
    public function setProductType(string $type): static
    {
        $this->productType = $type;
        return $this;
    }

    /**
     * Filter by observation date range.
     *
     * @param string|null $start Start date (YYYY-MM-DD).
     * @param string|null $end   End date (YYYY-MM-DD).
     * @return static
     */
    public function setDateRange(?string $start, ?string $end): static
    {
        $this->dateStart = $start;
        $this->dateEnd   = $end;
        return $this;
    }

    /**
     * Filter by ground track length range (km).
     *
     * @param float|null $min Minimum length in km.
     * @param float|null $max Maximum length in km.
     * @return static
     */
    public function setLengthRange(?float $min, ?float $max): static
    {
        $this->lengthMin = $min;
        $this->lengthMax = $max;
        return $this;
    }

    /**
     * Filter by geographic bounding box using spatial intersection.
     *
     * @param float $minLat Minimum latitude in degrees.
     * @param float $maxLat Maximum latitude in degrees.
     * @param float $minLon Minimum longitude in degrees.
     * @param float $maxLon Maximum longitude in degrees.
     * @return static
     */
    public function setBoundingBox(float $minLat, float $maxLat,
                                   float $minLon, float $maxLon): static
    {
        $this->bboxMinLat = $minLat;
        $this->bboxMaxLat = $maxLat;
        $this->bboxMinLon = $minLon;
        $this->bboxMaxLon = $maxLon;
        return $this;
    }

    // ── LRS-specific filters ──────────────────────────────────────────────────

    /**
     * Filter LRS observations by mode.
     *
     * For EDRs the mode distinguishes waveform type (SW, SA).
     * For RDRs the mode identifies the SAR product (SAR05, SAR05C,
     * SAR10, SAR10C, SAR40).
     *
     * @param array $modes Array of mode names, e.g. ['SW', 'SA'] or ['SAR05', 'SAR10C'].
     * @return static
     */
    public function setLrsModes(array $modes): static
    {
        $this->lrsModes = $modes;
        return $this;
    }

    /**
     * Filter by solar zenith angle range (applies to LRS).
     *
     * @param float|null $min Minimum SZA in degrees.
     * @param float|null $max Maximum SZA in degrees.
     * @return static
     */
    public function setSzaRange(?float $min, ?float $max): static
    {
        $this->szaMin = $min;
        $this->szaMax = $max;
        return $this;
    }

    // ── SHARAD-specific filters ───────────────────────────────────────────────

    /**
     * Filter SHARAD observations by presum values.
     *
     * Only applicable to EDR and RDR product types (not US RDR).
     *
     * @param array $presums Array of presum integers, e.g. [1, 4, 32].
     * @return static
     */
    public function setPresumValues(array $presums): static
    {
        $this->presumValues = $presums;
        return $this;
    }

    /**
     * Filter SHARAD observations by maximum roll angle threshold.
     *
     * @param float|null $max Maximum roll angle in degrees.
     * @return static
     */
    public function setMaxRoll(?float $max): static
    {
        $this->maxRollMax = $max;
        return $this;
    }

    /**
     * Filter by solar longitude range (applies to SHARAD and MARSIS).
     *
     * @param float|null $min Minimum L_s in degrees.
     * @param float|null $max Maximum L_s in degrees.
     * @return static
     */
    public function setLsRange(?float $min, ?float $max): static
    {
        $this->lsMin = $min;
        $this->lsMax = $max;
        return $this;
    }

    /**
     * Filter by orbit number range (applies to SHARAD and MARSIS).
     *
     * Supply both min and max with the same value for an exact match,
     * or supply only one for an open-ended range.
     *
     * @param int|null $min Minimum orbit number (inclusive).
     * @param int|null $max Maximum orbit number (inclusive).
     * @return static
     */
    public function setOrbitRange(?int $min, ?int $max): static
    {
        $this->orbitMin = $min;
        $this->orbitMax = $max;
        return $this;
    }

    // ── MARSIS-specific filters ───────────────────────────────────────────────

    /**
     * Filter MARSIS observations by mode.
     *
     * @param array $modes Array of mode names, e.g. ['SS1', 'SS3'].
     * @return static
     */
    public function setMarsisModes(array $modes): static
    {
        $this->marsisModes = $modes;
        return $this;
    }

    /**
     * Filter MARSIS observations by data form.
     *
     * @param array $forms Array of form names, e.g. ['CMP', 'IND'].
     * @return static
     */
    public function setMarsisForms(array $forms): static
    {
        $this->marsisForms = $forms;
        return $this;
    }

    /**
     * Filter MARSIS observations by spacecraft altitude range.
     *
     * @param float|null $min Minimum altitude in km.
     * @param float|null $max Maximum altitude in km.
     * @return static
     */
    public function setAltitudeRange(?float $min, ?float $max): static
    {
        $this->altMin = $min;
        $this->altMax = $max;
        return $this;
    }

    // ── Sorting and pagination ────────────────────────────────────────────────

    /**
     * Set the sort column and direction.
     *
     * @param string $column    Column name. Must be in the SORTABLE_COLUMNS whitelist.
     * @param string $direction 'ASC' or 'DESC'.
     * @return static
     * @throws InvalidArgumentException If the column is not in the whitelist.
     */
    public function setOrderBy(string $column, string $direction = 'ASC'): static
    {
        if (!in_array($column, self::SORTABLE_COLUMNS, true)) {
            throw new InvalidArgumentException("Invalid sort column: $column");
        }
        $direction = strtoupper($direction) === 'ASC' ? 'ASC' : 'DESC';

        // Prefix with child table alias where needed
        $childCols = ['orbit_number', 'max_roll', 'mean_sza', 'l_s',
                      'start_altitude', 'stop_altitude'];
        $this->orderBy  = in_array($column, $childCols, true)
            ? "child.$column"
            : "o.$column";
        $this->orderDir = $direction;
        return $this;
    }

    /**
     * Set pagination parameters.
     *
     * @param int $perPage Number of results per page (max 100).
     * @param int $offset  Row offset for the current page.
     * @return static
     */
    public function setPagination(int $perPage, int $offset): static
    {
        $this->perPage = min($perPage, 100);
        $this->offset  = max($offset, 0);
        return $this;
    }

    // ── Query assembly ────────────────────────────────────────────────────────

    /**
     * Build shared JOIN, WHERE, and parameter arrays based on current filters.
     * Called internally before execute() and count().
     */
    private function build(): void
    {
        $this->joins  = [];
        $this->wheres = [];
        $this->params = [];

        // Always join instruments and bodies for display columns
        $this->joins[] = 'JOIN instruments i ON i.instrument_id = o.instrument_id';
        $this->joins[] = 'JOIN bodies b ON b.body_id = o.body_id';

        // Instrument child table JOIN
        if ($this->instrumentId === self::INST_LRS) {
            $this->joins[] = 'JOIN lrs_observations child ON child.observation_id = o.observation_id';
            $this->joins[] = 'JOIN lrs_modes lm ON lm.mode_id = child.mode_id';
        } elseif ($this->instrumentId === self::INST_SHARAD) {
            $this->joins[] = 'JOIN sharad_observations child ON child.observation_id = o.observation_id';
            // LEFT JOIN: mode_id is nullable for US RDR observations
            $this->joins[] = 'LEFT JOIN sharad_modes sm ON sm.mode_id = child.mode_id';
        } elseif ($this->instrumentId === self::INST_MARSIS) {
            $this->joins[] = 'JOIN marsis_observations child ON child.observation_id = o.observation_id';
            $this->joins[] = 'JOIN marsis_modes mm ON mm.mode_id = child.mode_id';
            $this->joins[] = 'JOIN marsis_forms mf ON mf.form_id = child.form_id';
        }

        // Common WHERE clauses
        if ($this->instrumentId !== null) {
            $this->wheres[] = 'o.instrument_id = ?';
            $this->params[] = $this->instrumentId;
        }
        if ($this->bodyId !== null) {
            $this->wheres[] = 'o.body_id = ?';
            $this->params[] = $this->bodyId;
        }
        if ($this->productType !== null) {
            $this->wheres[] = 'child.observation_type = ?';
            $this->params[] = $this->productType;
        }
        if ($this->dateStart !== null) {
            $this->wheres[] = 'o.start_time >= ?';
            $this->params[] = $this->dateStart . ' 00:00:00';
        }
        if ($this->dateEnd !== null) {
            $this->wheres[] = 'o.start_time <= ?';
            $this->params[] = $this->dateEnd . ' 23:59:59';
        }
        if ($this->lengthMin !== null) {
            $this->wheres[] = 'o.length_km >= ?';
            $this->params[] = $this->lengthMin;
        }
        if ($this->lengthMax !== null) {
            $this->wheres[] = 'o.length_km <= ?';
            $this->params[] = $this->lengthMax;
        }

        // Bounding box spatial filter
        if ($this->bboxMinLat !== null && $this->bboxMaxLat !== null &&
            $this->bboxMinLon !== null && $this->bboxMaxLon !== null) {
            $this->wheres[] = 'ST_Intersects(o.geometry, ST_GeomFromText(?, 0))';
            $this->params[] = sprintf(
                'POLYGON((%f %f, %f %f, %f %f, %f %f, %f %f))',
                $this->bboxMinLon, $this->bboxMinLat,
                $this->bboxMaxLon, $this->bboxMinLat,
                $this->bboxMaxLon, $this->bboxMaxLat,
                $this->bboxMinLon, $this->bboxMaxLat,
                $this->bboxMinLon, $this->bboxMinLat
            );
        }

        // LRS-specific filters
        if ($this->instrumentId === self::INST_LRS) {
            if (!empty($this->lrsModes)) {
                $placeholders    = implode(',', array_fill(0, count($this->lrsModes), '?'));
                $this->wheres[]  = "lm.mode_name IN ($placeholders)";
                $this->params    = array_merge($this->params, $this->lrsModes);
            }
            if ($this->szaMin !== null) {
                $this->wheres[] = 'child.mean_sza >= ?';
                $this->params[] = $this->szaMin;
            }
            if ($this->szaMax !== null) {
                $this->wheres[] = 'child.mean_sza <= ?';
                $this->params[] = $this->szaMax;
            }
        }

        // SHARAD-specific filters
        if ($this->instrumentId === self::INST_SHARAD) {
            if (!empty($this->presumValues)) {
                $placeholders   = implode(',', array_fill(0, count($this->presumValues), '?'));
                $this->wheres[] = "sm.presum IN ($placeholders)";
                $this->params   = array_merge($this->params, $this->presumValues);
            }
            if ($this->maxRollMax !== null) {
                $this->wheres[] = 'child.max_roll <= ?';
                $this->params[] = $this->maxRollMax;
            }
            if ($this->szaMin !== null) {
                $this->wheres[] = 'child.mean_sza >= ?';
                $this->params[] = $this->szaMin;
            }
            if ($this->szaMax !== null) {
                $this->wheres[] = 'child.mean_sza <= ?';
                $this->params[] = $this->szaMax;
            }
            if ($this->lsMin !== null) {
                $this->wheres[] = 'child.l_s >= ?';
                $this->params[] = $this->lsMin;
            }
            if ($this->lsMax !== null) {
                $this->wheres[] = 'child.l_s <= ?';
                $this->params[] = $this->lsMax;
            }
            if ($this->orbitMin !== null) {
                $this->wheres[] = 'child.orbit_number >= ?';
                $this->params[] = $this->orbitMin;
            }
            if ($this->orbitMax !== null) {
                $this->wheres[] = 'child.orbit_number <= ?';
                $this->params[] = $this->orbitMax;
            }
        }

        // MARSIS-specific filters
        if ($this->instrumentId === self::INST_MARSIS) {
            if (!empty($this->marsisModes)) {
                $placeholders   = implode(',', array_fill(0, count($this->marsisModes), '?'));
                $this->wheres[] = "mm.mode_name IN ($placeholders)";
                $this->params   = array_merge($this->params, $this->marsisModes);
            }
            if (!empty($this->marsisForms)) {
                $placeholders   = implode(',', array_fill(0, count($this->marsisForms), '?'));
                $this->wheres[] = "mf.form_name IN ($placeholders)";
                $this->params   = array_merge($this->params, $this->marsisForms);
            }
            if ($this->szaMin !== null) {
                $this->wheres[] = 'child.mean_sza >= ?';
                $this->params[] = $this->szaMin;
            }
            if ($this->szaMax !== null) {
                $this->wheres[] = 'child.mean_sza <= ?';
                $this->params[] = $this->szaMax;
            }
            if ($this->lsMin !== null) {
                $this->wheres[] = 'child.l_s >= ?';
                $this->params[] = $this->lsMin;
            }
            if ($this->lsMax !== null) {
                $this->wheres[] = 'child.l_s <= ?';
                $this->params[] = $this->lsMax;
            }
            if ($this->altMin !== null) {
                $this->wheres[] = 'child.start_altitude >= ?';
                $this->params[] = $this->altMin;
            }
            if ($this->altMax !== null) {
                $this->wheres[] = 'child.stop_altitude <= ?';
                $this->params[] = $this->altMax;
            }
            if ($this->orbitMin !== null) {
                $this->wheres[] = 'child.orbit_number >= ?';
                $this->params[] = $this->orbitMin;
            }
            if ($this->orbitMax !== null) {
                $this->wheres[] = 'child.orbit_number <= ?';
                $this->params[] = $this->orbitMax;
            }
        }
    }

    /**
     * Assemble the FROM + JOIN + WHERE fragment shared by execute() and count().
     *
     * @return string SQL fragment starting with FROM.
     */
    private function fromClause(): string
    {
        $sql = 'FROM observations o ';
        $sql .= implode(' ', $this->joins);
        if (!empty($this->wheres)) {
            $sql .= ' WHERE ' . implode(' AND ', $this->wheres);
        }
        return $sql;
    }

    // ── Public execution methods ──────────────────────────────────────────────

    /**
     * Execute the search query and return a page of result rows.
     *
     * Each row contains common observation fields plus instrument-specific
     * columns where available. Returns an empty array if no results match.
     *
     * @return array Array of associative arrays, one per observation.
     */
    public function execute(): array
    {
        $this->build();

        // Build instrument-specific SELECT columns
        $extraCols = $this->buildExtraColumns();

        $sql = "SELECT
                    o.observation_id,
                    o.native_id,
                    i.instrument_abbr,
                    b.body_name,
                    o.start_time,
                    o.stop_time,
                    o.duration,
                    o.length_km
                    $extraCols
                " . $this->fromClause() . "
                ORDER BY {$this->orderBy} {$this->orderDir}
                LIMIT ? OFFSET ?";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(array_merge($this->params, [$this->perPage, $this->offset]));
        return $stmt->fetchAll();
    }

    /**
     * Return the total number of observations matching the current filters.
     *
     * Used to calculate pagination controls without fetching all rows.
     *
     * @return int Total matching row count.
     */
    public function count(): int
    {
        $this->build();
        $sql  = 'SELECT COUNT(*) ' . $this->fromClause();
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($this->params);
        return (int) $stmt->fetchColumn();
    }

    /**
     * Fetch archive files (LBL / AUX / SCI / BROWSE, etc.) for a set of
     * observations, grouped by observation_id.
     *
     * The file tables are per-instrument, so this uses the instrument set via
     * setInstrument(). Returns an empty array when no instrument is set, the
     * instrument has no known file table, or no IDs are supplied. Runs a single
     * batched IN (...) query to avoid per-row lookups.
     *
     * @param array $observationIds Observation IDs from a result page.
     * @return array Map of observation_id => list of ['type' => ..., 'url' => ...].
     */
    public function fetchFilesForObservations(array $observationIds): array
    {
        if ($this->instrumentId === null
            || !isset(self::FILE_TABLES[$this->instrumentId])
            || empty($observationIds)) {
            return [];
        }

        // Sanitise IDs to ints; table name comes from the FILE_TABLES whitelist.
        $ids = array_values(array_unique(array_filter(array_map('intval', $observationIds))));
        if (empty($ids)) {
            return [];
        }

        $table        = self::FILE_TABLES[$this->instrumentId];
        $placeholders = implode(',', array_fill(0, count($ids), '?'));

        $sql = "SELECT observation_id, file_type, file_url
                FROM {$table}
                WHERE observation_id IN ($placeholders)";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($ids);

        $map = [];
        foreach ($stmt->fetchAll() as $r) {
            $map[(int) $r['observation_id']][] = [
                'type' => $r['file_type'],
                'url'  => $r['file_url'],
            ];
        }
        return $map;
    }

    /**
     * Build the extra SELECT columns for the active instrument's child table.
     *
     * @return string SQL fragment of additional comma-prefixed columns, or empty string.
     */
    private function buildExtraColumns(): string
    {
        return match ($this->instrumentId) {
            self::INST_LRS    => ",
                    lm.mode_name        AS mode,
                    child.observation_type,
                    child.mean_sza",
            self::INST_SHARAD => ",
                    sm.mode_name        AS mode,
                    child.observation_type,
                    child.orbit_number,
                    child.max_roll,
                    child.mean_sza,
                    child.l_s",
            self::INST_MARSIS => ",
                    mm.mode_name        AS mode,
                    mf.form_name        AS form,
                    child.observation_type,
                    child.orbit_number,
                    child.mean_sza,
                    child.l_s,
                    child.start_altitude,
                    child.stop_altitude",
            default           => '',
        };
    }
}