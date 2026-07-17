# MariaDB Connection Notes

This document records what is known and what is still TBC regarding the
MariaDB database that the PORPASS backend connects to.

---

## Connection Details

The backend reads all connection parameters from environment variables.
These are set in `docker-compose.yml` (replace placeholders before deployment):

| Variable      | Description                        | Default in compose     |
|---------------|------------------------------------|------------------------|
| `DB_HOST`     | Hostname/IP of the MariaDB server  | `placeholder_host`     |
| `DB_PORT`     | Port (usually 3306)                | `3306`                 |
| `DB_NAME`     | Database name                      | `placeholder_db`       |
| `DB_USER`     | Database user                      | `placeholder_user`     |
| `DB_PASSWORD` | Database password                  | `placeholder_password` |

---

## Expected Table Structure

The schema below is based on what is confirmed so far.
Items marked **TBC** need to be checked on.

### Parent table

A shared `observations` table is expected to hold fields common to all
Sounder instruments, with per-instrument child tables joined to it.

| Column      | Type    | Notes                              |
|-------------|---------|------------------------------------|
| `id`        | INT     | Primary key                        |
| ...         | ...     | Other shared fields TBC            |

### Child tables (one per instrument)

Each sounder has its own table joined to `observations` (note 
That Marsis Phobos observations are assumed to be a separate 
Instrument - this might be incorrect?).
The JOIN column name is **TBC**.

| Table                   | Instrument       | Status          |
|-------------------------|------------------|-----------------|
| `sharad_tracks`         | SHARAD (Mars)    | Schema TBC      |
| `marsis_tracks`         | MARSIS (Mars)    | Schema TBC      |
| `lrs_tracks`            | LRS/Kaguya (Moon)| Schema TBC      |
| `marsis_phobos_tracks`  | MARSIS (Phobos)  | Schema TBC      |

> **Note:** Table names in the backend are set via the `table` field in
> `instruments.yaml`. Update those values once the real names are confirmed.

---

## Geometry Column

- Column name: `geom`
- Type: `LINESTRING` (confirmed)
- The backend calls `AsGeoJSON(geom)` to serialise geometry for the API.
- Coordinate convention: **0–360 longitude** throughout. No mixed conventions.
- The antimeridian split (tracks crossing the 0°/360° seam) is handled
  in Python after the DB returns GeoJSON — no DB-side processing needed.

---

## Current Query Limitation

The backend currently queries each instrument table directly:

```sql
SELECT *, AsGeoJSON(geom) AS _geojson
FROM `{table}` WHERE ...
```

When the parent/child JOIN structure is confirmed, `vectors.py` will need
updating to:

```sql
SELECT o.*, s.*, AsGeoJSON(o.geom) AS _geojson
FROM observations o
JOIN {child_table} s ON o.id = s.{join_column}
WHERE ...
```

The `join_column` name (TBC) is the only additional piece of
information needed to make this work. Everything else in the backend stays
identical.

---

## Attribute Columns (per instrument)

### SHARAD
Current filter and popup fields expected in `sharad_tracks` (or joined via
`observations`):

| Column      | Type    | Notes                                 |
|-------------|---------|---------------------------------------|
| `ProdID`    | VARCHAR | Product identifier                    |
| `Date`      | VARCHAR | Observation date                      |
| `Ls`        | FLOAT   | Solar longitude (0–360)               |
| `maxSZA`    | FLOAT   | Maximum solar zenith angle (0–180)    |
| `Presum`    | INT     | Presumming value (dropdown filter)    |
| `maxRoll`   | FLOAT   | Maximum roll angle (0–120)            |
| `Error`     | VARCHAR | Error flag                            |
| `ErrorNote` | VARCHAR | Error description                     |
| `geom`      | LINESTRING | Track geometry                     |

### MARSIS, LRS/Kaguya, MARSIS (Phobos)
Attribute columns **TBC**. Filter and popup field lists in
`instruments.yaml` are currently empty placeholders and will be populated
once the schema is delivered.

---

## Spatial Indexing

For acceptable query performance at ~40k features, a spatial index on the
`geom` column is recommended:

```sql
ALTER TABLE sharad_tracks ADD SPATIAL INDEX(geom);
```

Apply the same to each instrument table once created.
