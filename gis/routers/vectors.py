import json
import os
from pathlib import Path

import pymysql
import pymysql.cursors
import yaml
from fastapi import APIRouter, HTTPException, Query, Request

router = APIRouter()

CONFIG_DIR = Path(os.environ.get("CONFIG_DIR", "config"))

DB_HOST     = os.environ.get("DB_HOST", "localhost")
DB_PORT     = int(os.environ.get("DB_PORT", "3306"))
DB_NAME     = os.environ.get("DB_DATABASE", "porpass")
DB_USER     = os.environ.get("DB_USERNAME", "porpass")
DB_PASSWORD = os.environ.get("DB_PASSWORD", "")

# ---------------------------------------------------------------------------
# Helpers
# ---------------------------------------------------------------------------

def _load_instrument(instrument_id: str) -> dict:
    """Return the instruments.yaml entry for the given id, or raise 404."""
    with open(CONFIG_DIR / "instruments.yaml") as f:
        instruments = yaml.safe_load(f)["instruments"]
    for inst in instruments:
        if inst["id"] == instrument_id:
            return inst
    raise HTTPException(status_code=404, detail=f"Instrument '{instrument_id}' not found")


def _connect() -> pymysql.connections.Connection:
    """Open a MariaDB connection using environment-variable credentials."""
    conn = pymysql.connect(
        host=DB_HOST,
        port=DB_PORT,
        db=DB_NAME,
        user=DB_USER,
        password=DB_PASSWORD,
        cursorclass=pymysql.cursors.DictCursor,
        autocommit=False,
    )
    with conn.cursor() as cur:
        cur.execute("SET SESSION TRANSACTION ISOLATION LEVEL READ COMMITTED")
    return conn


def _build_query(inst_config: dict) -> str:
    """
    Build the base SELECT ... FROM ... JOIN string for an instrument.

    Uses the parent/child JOIN pattern:
      observations o
      JOIN {child_table} c  ON o.observation_id = c.observation_id
      [JOIN {extra_table} e ON ...]

    Columns are selected explicitly from the instrument's select_columns
    config entry rather than using SELECT o.*, c.* to avoid duplicate
    column name errors when the parent and child tables share a column
    name (e.g. observation_id). Extra JOIN columns (e.g. mode_name from
    lookup tables) must also be listed in select_columns.

    Always scoped to the correct instrument_id and body_id via
    _build_where(), so instruments sharing a child table (e.g. MARSIS
    Mars vs Phobos) are correctly separated.
    """
    child    = inst_config["child_table"]
    join_col = inst_config["join_column"]

    joins = f"JOIN `{child}` c ON o.`{join_col}` = c.`{join_col}`"
    for xj in inst_config.get("extra_joins", []):
        joins += f"\nJOIN `{xj['table']}` ON {xj['on']}"

    # Optional LEFT JOIN for browse product file URL
    file_table = inst_config.get("file_table")
    if file_table:
        joins += (
            f"\nLEFT JOIN `{file_table}` bf"
            f" ON o.observation_id = bf.observation_id"
            f" AND bf.file_type = 'BROWSE'"
        )

    columns = inst_config.get("select_columns", [])
    select  = ",\n  ".join(columns) if columns else "o.*, c.*"

    return (
        f"SELECT {select}, ST_AsGeoJSON(o.geometry) AS _geojson\n"
        f"FROM observations o\n"
        f"{joins}"
    )


def _build_where(inst_config: dict, query_params: dict, bbox: str | None) -> tuple[str, list]:
    """
    Build a SQL WHERE clause from all active filters.

    Always scopes to the correct instrument_id and body_id.

    Supported filter types:
      range    — field_min / field_max query params (both optional)
      dropdown — field query param (exact match)
      bbox     — minlon,minlat,maxlon,maxlat string via the bbox param

    Returns (where_string, param_list).
    """
    clauses: list[str] = [
        "o.`instrument_id` = %s",
        "o.`body_id` = %s",
    ]
    params: list = [
        inst_config["instrument_id"],
        inst_config["body_id"],
    ]

    # -- Spatial bbox clip -------------------------------------------------
    if bbox:
        try:
            parts = [float(v) for v in bbox.split(",")]
            if len(parts) != 4:
                raise ValueError
        except ValueError:
            raise HTTPException(
                status_code=422,
                detail="bbox must be four comma-separated numbers: minlon,minlat,maxlon,maxlat",
            )
        minlon, minlat, maxlon, maxlat = parts
        wkt = (
            f"POLYGON(({minlon} {minlat},{maxlon} {minlat},"
            f"{maxlon} {maxlat},{minlon} {maxlat},{minlon} {minlat}))"
        )
        clauses.append("ST_Intersects(o.geometry, ST_GeomFromText(%s))")
        params.append(wkt)

    # -- Attribute filters from instruments.yaml ---------------------------
    for f in inst_config.get("filters", []):
        ftype = f["type"]
        field = f.get("field")

        # bbox type is handled above; skip filters with no field
        if not field or ftype == "bbox":
            continue

        if ftype == "range":
            min_val = query_params.get(f"{field}_min")
            max_val = query_params.get(f"{field}_max")
            if min_val is not None:
                clauses.append(f"`{field}` >= %s")
                params.append(float(min_val))
            if max_val is not None:
                clauses.append(f"`{field}` <= %s")
                params.append(float(max_val))

        elif ftype == "dropdown":
            val = query_params.get(field)
            if val is not None:
                clauses.append(f"`{field}` = %s")
                # Cast to int when the value looks like a whole number
                params.append(int(val) if val.lstrip("-").isdigit() else val)

    where = f"WHERE {' AND '.join(clauses)}"
    return where, params


# ---------------------------------------------------------------------------
# Antimeridian splitting
# ---------------------------------------------------------------------------

def _split_linestring(coords: list) -> list[list]:
    """
    Walk a LineString coordinate list and split it every time consecutive
    points have a longitude difference greater than 180°, which indicates
    a crossing of the -180°/180° antimeridian.

    At each crossing the exact latitude is interpolated and the seam is
    represented as a clean [-180, lat] / [180, lat] pair so renderers draw
    two flush segments rather than a line spanning the full planet width.

    Coordinates are assumed to be in -180/180 convention.

    Returns a list of segments (each a list of [lon, lat]).
    Degenerate single-point segments are dropped.
    """
    if len(coords) < 2:
        return [coords]

    segments: list[list] = []
    current: list = [coords[0]]

    for i in range(1, len(coords)):
        lon1, lat1 = coords[i - 1][0], coords[i - 1][1]
        lon2, lat2 = coords[i][0],     coords[i][1]
        diff = lon2 - lon1

        if abs(diff) > 180:
            # Crossing detected. Interpolate the latitude at the seam.
            if diff < 0:
                # e.g. 170 -> -170 : crosses +180/-180 going eastward
                t = (180.0 - lon1) / (lon2 + 360.0 - lon1)
                lat_x = lat1 + t * (lat2 - lat1)
                current.append([180.0, lat_x])
                segments.append(current)
                current = [[-180.0, lat_x], [lon2, lat2]]
            else:
                # e.g. -170 -> 170 : crosses -180/+180 going westward
                t = (-180.0 - lon1) / (lon2 - 360.0 - lon1)
                lat_x = lat1 + t * (lat2 - lat1)
                current.append([-180.0, lat_x])
                segments.append(current)
                current = [[180.0, lat_x], [lon2, lat2]]
        else:
            current.append([lon2, lat2])

    if current:
        segments.append(current)

    # Drop degenerate single-point segments
    return [s for s in segments if len(s) >= 2]


def _fix_antimeridian(geometry: dict | None) -> dict | None:
    """
    Return the geometry with any -180/180 antimeridian crossing fixed.

    A LineString that crosses the seam is returned as a MultiLineString
    whose segments meet cleanly at the map edges.  All other geometry
    types (and None) are passed through unchanged.
    """
    if geometry is None or geometry.get("type") != "LineString":
        return geometry

    segments = _split_linestring(geometry["coordinates"])

    if len(segments) == 1:
        return geometry  # no crossing -- return as-is

    return {"type": "MultiLineString", "coordinates": segments}


# ---------------------------------------------------------------------------
# Endpoints
# ---------------------------------------------------------------------------

@router.get("/vectors/{instrument}/field-values/{field}")
def get_field_values(instrument: str, field: str):
    """
    Return sorted distinct values for a dropdown filter field -- used to
    populate dropdowns (e.g. presum) from real DB contents.

    The field name is validated against the instrument's filter config
    so arbitrary column names cannot be queried.
    """
    inst_config = _load_instrument(instrument)

    # Validate: field must appear in a dropdown filter for this instrument
    allowed = {
        f["field"]
        for f in inst_config.get("filters", [])
        if f["type"] == "dropdown" and "field" in f
    }
    if field not in allowed:
        raise HTTPException(
            status_code=400,
            detail=f"Field '{field}' is not a dropdown filter for instrument '{instrument}'",
        )

    try:
        conn = _connect()
    except pymysql.Error:
        return {"values": []}

    base_query = _build_query(inst_config)
    where, params = _build_where(inst_config, {}, None)

    try:
        with conn.cursor() as cur:
            cur.execute(
                f"SELECT DISTINCT `{field}` FROM ({base_query} {where}) AS sub "
                f"ORDER BY `{field}`",
                params,
            )
            rows = cur.fetchall()
    finally:
        conn.close()

    return {"values": [row[field] for row in rows if row[field] is not None]}


@router.get("/vectors/{instrument}")
def get_vectors(
    instrument: str,
    request: Request,
    bbox: str | None = Query(None, description="minlon,minlat,maxlon,maxlat"),
):
    """
    Return a GeoJSON FeatureCollection filtered by bbox and any attribute
    filters defined for this instrument in instruments.yaml.

    Range filter params:  {field}_min and/or {field}_max
    Dropdown filter param: {field}
    """
    inst_config = _load_instrument(instrument)

    try:
        conn = _connect()
    except pymysql.Error as e:
        return {
            "type": "FeatureCollection",
            "features": [],
            "warning": f"{inst_config['label']} database not reachable: {e}",
        }

    base_query = _build_query(inst_config)
    where, params = _build_where(inst_config, dict(request.query_params), bbox)

    try:
        with conn.cursor() as cur:
            cur.execute(f"{base_query} {where}", params)
            rows = cur.fetchall()
    finally:
        conn.close()

    features = []
    for row in rows:
        geojson_str = row.pop("_geojson", None)
        row.pop("geometry", None)
        geom = _fix_antimeridian(json.loads(geojson_str)) if geojson_str else None
        features.append({
            "type": "Feature",
            "geometry": geom,
            "properties": row,
        })

    return {"type": "FeatureCollection", "features": features}


@router.get("/vectors/{instrument}/count")
def get_vector_count(
    instrument: str,
    request: Request,
    bbox: str | None = Query(None, description="minlon,minlat,maxlon,maxlat"),
):
    """
    Return total feature count and the count after applying all active filters.
    Accepts the same filter params as GET /vectors/{instrument}.
    """
    inst_config = _load_instrument(instrument)

    try:
        conn = _connect()
    except pymysql.Error:
        return {"total": 0, "filtered": 0}

    base_query = _build_query(inst_config)
    where_unfiltered, params_unfiltered = _build_where(inst_config, {}, None)
    where_filtered,   params_filtered   = _build_where(inst_config, dict(request.query_params), bbox)

    try:
        with conn.cursor() as cur:
            cur.execute(
                f"SELECT COUNT(*) AS cnt FROM ({base_query} {where_unfiltered}) AS sub",
                params_unfiltered,
            )
            total = cur.fetchone()["cnt"]

            cur.execute(
                f"SELECT COUNT(*) AS cnt FROM ({base_query} {where_filtered}) AS sub",
                params_filtered,
            )
            filtered = cur.fetchone()["cnt"]
    finally:
        conn.close()

    return {"total": total, "filtered": filtered}