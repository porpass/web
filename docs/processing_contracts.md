# PORPASS ⇄ GRaSP Processing Contracts

Interface definition shared by the GRaSP daemon (`porpass-proc`) and the PORPASS
web app (`porpass/web`). Neither side hardcodes the other's knowledge; GRaSP
describes itself and the web renders whatever it's told.

- **Contract A — schema artifact** — `schema_version 1.1` — GRaSP → web
- **Contract B — per-job config** — `config_version 1.0` — web → daemon
- **Contract C — result manifest** — `manifest_version 1.0` — daemon → web

---

## 1. Architecture recap

1. User browses observations (GIS / `observations.php`), selects observations
   into a per-user queue.
2. On the process page, the web renders a form **generated from the schema
   artifact** for the observation's `(instrument, product)`.
3. On submit, the web writes a job row to `porpass-db` (`status = queued`) with
   a **sparse per-job config** capturing only the user's overrides. The config
   is stored both in the job row's `config` column (canonical) and as
   `config.json` in the job's output directory (inspectable).
4. The daemon on `porpass-proc` atomically claims the job, resolves input URLs
   from the DB, downloads inputs to a temp dir, renders the config to a GRaSP
   TOML, and runs it.
5. On completion the daemon writes results + provenance to the job's output
   directory, writes `manifest.json`, and sets terminal status.
6. The user views / downloads / deletes results; delete removes result products
   but retains the DB row and the provenance files.

**Source-of-truth rule:** GRaSP owns all defaults, per-body physics, valid
choices, and the support matrix. Drift is prevented by the daemon publishing
GRaSP's resolved schema rather than the web reimplementing any of it.

---

## 2. Storage layout

`porpass-storage` is mounted on both `porpass-proc` and `porpass/web`.

```
{storage}/schemas/{INSTRUMENT}_{PRODUCT}.schema.json    # published by daemon on startup
{storage}/processing/{user_id}/{job_id}/
    config.json        # the sparse per-job config (Contract B) — inspectable
    job.toml           # the fully-resolved TOML the daemon actually ran — provenance
    run.log            # verbose run log (kind: log — preserved through results-delete)
    manifest.json      # typed list of produced result files (Contract C)
    <result products>  # data / images / segy / state / cluttergram outputs
```

Schema artifacts are regenerated on daemon startup so they always match the
running GRaSP version. `config.json` and `job.toml` are retained even after a
results delete, so the request and the exact run stay auditable by users and
admins. `run.log` is retained because its manifest entry is marked
`kind: "log"` — see Contract C.

Product names with spaces (e.g. SHARAD `US RDR`) are slugged to underscores in
filenames only: `SHARAD_US_RDR.schema.json`. The wire value inside the artifact
and in Contract B keeps the space.

---

## 3. Contract A — schema artifact (GRaSP → web)

One JSON file per `(instrument, product)`. Describes every option the web form
may present. Keyed on **long/canonical section names** (`sar_processing`,
`multilooking`, `ionospheric_compensation`, `clutter_simulation`,
`plot_parameters`, `output_parameters`, `preprocessing`, `range_compression`,
`emi_suppression`, `final_output`, `general`) — 4 globals + 7 stages = 11
sections.

### Top level

```json
{
  "schema_version": "1.1",
  "grasp_version":  "0.6.0a1",
  "generated_at":   "<ISO-8601>",
  "instrument":     "SHARAD",
  "product":        "EDR",
  "target_body":    "MARS",
  "inputs":  { "<file_role>": { "required": true, "help": "..." } },
  "stages":  [ <section>, ... ],   // the 7 pipeline stages, in pipeline order
  "globals": [ <section>, ... ]    // general, output_parameters, plot_parameters, final_output
}
```

`inputs` varies per combo: SHARAD/MARSIS EDR carry `label_file`, `science_file`,
`auxiliary_file`; LRS EDR carries `label_file`, `science_file` only.

### Section object (stage or global)

```json
{
  "key":        "range_compression",   // long/canonical name
  "dataclass":  "RangeCompressionParams",
  "supported":  true,                  // false = show disabled, never omit
  "title":      "Range Compression",
  "help":       "...",
  "fields":     [ <field>, ... ],
  "disables":   [ { "when": {"field":"method","equals":"BACKSCATTER"},
                    "stages": ["multilooking"] } ]   // optional, stage-level
}
```

Unsupported stages are still emitted with their full field list and
`supported: false`, so a strict Contract-A consumer shows them disabled rather
than silently dropping them. See §8 for how `porpass/web` deviates.

### Field object

```json
{
  "name":         "window",
  "type":         "bool|int|float|str|enum",
  "default":      <value|null>,
  "default_note": "n_samp // 2",       // present only when default is null + runtime-computed
  "help":         "...",
  "choices":      [ <choice>, ... ],   // enum only
  "value_type":   "int",               // numeric enums (e.g. sgn) — render UNQUOTED
  "visible_when": { "field": "window", "equals": "TUKEY" }   // optional UI conditional
}
```

- `default: null` + `default_note` ⇒ GRaSP computes at runtime; the form shows a
  placeholder and the user leaves it blank.
- `visible_when` gates a field on a sibling's value in the same section.
- A field's declared `default` is expected to appear in the choice set for enum
  fields. Where it does not (e.g. `output_parameters.byte_order` declares
  `"big"` but choices are `["BIG","LITTLE"]`), consumers should case-normalize
  or fall back to the first `ui: true` choice. Bridging via `alias_of` is
  preferred and lets consumers stay strict.

### Choice object (enum values)

```json
{ "value": "BLACKMAN-HARRIS", "ui": true }
{ "value": "BH", "ui": false, "alias_of": "BLACKMAN-HARRIS" }
```

- `ui: true` options render in the dropdown, **in array order**.
- `ui: false` are accepted-but-hidden aliases; `alias_of` points at the
  canonical value. Used to normalize imported/reloaded TOMLs for display.
- The web validates submissions against the **full** value set (ui true *and*
  false); GRaSP's own validator stays permissive for standalone users.

---

## 4. Contract B — per-job config (web → daemon)

The mirror of Contract A: it records **one user's choices, sparsely**. Stored
canonically in `processing_jobs.config` and mirrored as `config.json` in the
job output directory. The DB is authoritative; the file is for inspection and
manual audit.

```json
{
  "config_version": "1.0",
  "schema_version":  "1.1",
  "grasp_version":   "0.6.0a1",
  "instrument":      "SHARAD",
  "product":         "EDR",
  "observation":     { "instrument_id": 1, "native_id": "S_00123401_..." },
  "overrides": {
    "sar_processing":     { "method": "BACKSCATTER", "number_of_looks": 5 },
    "clutter_simulation": { "enabled": false },
    "final_output":       { "save_segy": true }
  }
}
```

### Rules

- **Sparse.** `overrides` carries only fields whose submitted value differs
  from the schema-effective default. Omitted field ⇒ GRaSP resolves at runtime;
  omitted stage ⇒ all defaults; a fully-default run stores `"overrides": {}`.
- **No `out_dir`, no `[input]` in overrides.** The daemon injects
  `general.out_dir` (the job output dir) and the `[input]` block (local temp
  paths after download) at render time.
- **Input identity.** `observation` carries `instrument_id` + `native_id`; the
  pair uniquely identifies an observation (DB UNIQUE constraint on
  `observations(instrument_id, native_id)`). The daemon resolves PDS/DARTS URLs
  from the `{instrument}_files` tables at fetch time, so authoritative URLs
  stay in the DB and the config never goes stale.
- **Value encoding.** Enum values stored as the canonical `value`. Numeric
  enums (`value_type: int`, e.g. `sgn`) render unquoted. Booleans lowercase,
  strings quoted — standard TOML.
- **Round-trip.**
  - *Render:* `overrides` + injected `out_dir`/`input` → TOML (long section
    names) → GRaSP loads → job.
  - *Import/edit:* TOML → normalize aliases to canonical via `alias_of` → diff
    against schema defaults → `overrides` → repopulate form. Lossless.

---

## 5. Contract C — result manifest (daemon → web)

The daemon writes `manifest.json` in the job output directory before setting
terminal status. The web reads it to render the results table, gate downloads,
and drive the "delete results" reclaim.

```json
{
  "manifest_version": "1.0",
  "created_at":       "<ISO-8601>",
  "grasp_version":    "0.6.0a1",
  "files": [
    {
      "path":         "data/segY_output.sgy",     // relative to the job dir
      "kind":         "segy",                     // see enum below
      "bytes":        12345678,
      "content_type": "application/octet-stream",
      "deleted":      false
    },
    ...
  ]
}
```

### Rules

- **Path is relative** to the job dir. No absolute paths, no `..` segments.
  The web's download proxy rejects anything whose real-path escapes the job
  dir.
- **Kind enum.** Recognised values: `data`, `image`, `segy`, `state`,
  `cluttergram`, `log`, `other`. The web uses `kind` to pick inline preview
  (images) vs attachment download, and to decide what to preserve during
  results reclaim.
- **Preserved kinds.** During "delete results", the web unlinks every file
  whose `kind` is NOT in the preserved set and flips its `deleted` flag on
  the manifest. Preserved kinds today: `log` (so `run.log` survives). The
  daemon should include `run.log` in the manifest with `kind: "log"`.
- **`deleted: false` at write time.** The daemon always emits `false`; the web
  is the only party that flips this flag.
- **`content_type`.** Used verbatim in the download proxy's `Content-Type`
  header. Falls back to `application/octet-stream` if absent.

---

## 6. Resolved decisions

- **Defaults source of truth:** GRaSP only. Physics (per-body `lamb`,
  `campbell_b`, `ct_step`, …) lives in GRaSP's default files / per-body logic;
  the web never hardcodes a default.
- **Reproducibility (hybrid):** the editable job is the sparse `config.json`;
  every completed run also writes the fully-resolved `job.toml` +
  `grasp_version` as a frozen provenance snapshot in the output dir. Re-running
  the sparse config tracks current GRaSP defaults; the snapshot preserves
  exactly what ran.
- **Input fetch:** native_id → DB URL lookup → download to temp on
  `porpass-proc` → cleaned after each run.
- **Atomic claim.** Daemon claims via `UPDATE processing_jobs SET
  status='running', claimed_by=?, claimed_at=NOW() WHERE job_id=? AND
  status='queued'` and proceeds only if `affected_rows = 1`. `claimed_at`
  doubles as a heartbeat so a job left `running` past a timeout can be
  requeued.
- **Results-delete granularity.** Whole-job. Unlinks every file whose `kind`
  is not preserved; retains the row + `config.json` + `job.toml` + `run.log`
  + `manifest.json` (with entries flagged deleted). Flips
  `results_deleted = 1`, `results_deleted_at = NOW()` on the row.
- **Rerun.** Creates a new job row with `rerun_of` pointing at the original,
  same observation, config copied verbatim, its own single-job batch.
  `cancelled` jobs are rerunnable like `succeeded` and `failed` ones.
- **Cancel.** A queued job is atomically flipped to `status='cancelled'` by
  the web (`UPDATE ... WHERE status='queued' AND user_id=?`); if the daemon
  claims it in the same instant the web falls back to setting
  `cancel_requested=1` and lets the daemon handle it. A running job is
  signalled only: the web sets `cancel_requested=1` and the daemon —
  polling that flag every few seconds — terminates GRaSP, discards partial
  products (retaining `run.log`, `job.toml`, `config.json`), writes
  `manifest.json` listing just `run.log`, and sets `status='cancelled'`.
  `cancel_requested` is web-set / daemon-read; the web MUST NEVER write
  `status` on a running row. `cancelled` is a terminal status distinct
  from `failed` — the run did not fail, it was stopped intentionally.
- **Batch submission.** Users can select up to
  `WebPolicy::BATCH_MAX_SIZE` (10) queue items that share
  `(instrument, product)`; `processing_configure.php` renders one form
  and creates N job rows sharing the config, transactionally.
- **Naming:** canonical long section names everywhere in both contracts;
  GRaSP's internal short attribute names never cross the boundary.

---

## 7. Frozen combos (current)

| Instrument | Product | Supported stages | Inputs |
|---|---|---|---|
| SHARAD | EDR | all 7 | label, science, aux |
| MARSIS | EDR | preproc, range_comp, emi, iono, clutter_sim | label, science, aux |
| LRS | EDR | preproc, sar, multilook, clutter_sim | label, science |

Unsupported stages currently: SHARAD EDR — none; MARSIS EDR — `sar_processing`,
`multilooking`; LRS EDR — `range_compression`, `emi_suppression`,
`ionospheric_compensation`.

RDR / US_RDR skeletons collapse to `clutter_simulation` + `final_output` /
SEG-Y until their pipeline stages are developed in GRaSP.

---

## 8. `porpass/web` UI-layer deviations

The Contract A specification says the consumer must render `supported: false`
sections in a disabled state — "never omit". `porpass/web` deliberately
deviates: unsupported sections are **hidden entirely** from the form. Rationale
is UX (74 fields is already dense; showing 15 that can't fire is noise). The
web enforces the same behavior server-side (ConfigBuilder refuses overrides
for `supported: false` sections regardless of what a client posts), so no
contract-B invariant is broken.

The web also layers a small policy file (`WebPolicy`) over Contract A:

- **Hidden sections/fields** — `general` (verbose is always on, out_dir is
  daemon-injected), plus specific fields like `output_parameters.byte_order`
  and `clutter_simulation.dem_path` that end users don't need to touch.
- **Web-side default overrides** — e.g. `plot_parameters.cmap = "gray"`
  overrides the schema's default. Rendered as the initial value; propagates
  into overrides on submit exactly like a user pick would.
- **Conditional defaults** — e.g. `ionospheric_compensation.metric` snaps to
  `PEAK_SNR` for CAMPBELL and `L4` for CONTRAST when method changes. Purely
  UI behavior; the underlying value is a regular Contract B override.
- **Web-side visible_when** — e.g. `sar_processing.coherent` shown only when
  `method = UNFOCUSED`. Same shape as a schema-level rule but declared in
  policy code.

Daemon builders should treat all of the above as consumer preferences that
don't change what a valid Contract B document can contain. The wire format is
untouched.

---

## 9. Still open

- **Status polling.** The `/api/processing_jobs.php?action=status` endpoint
  and its client-side polling widget aren't shipped yet — the web deliberately
  waits until the daemon exists so there's something to poll.
- **Aperture size helper.** A small UI tool that suggests `sar_processing.
  aperture_length` for users unfamiliar with the calculation. Formula TBD;
  interim ball-park before GRaSP exposes an authoritative helper.
- **Observation metadata on configure page.** Surface times, length, orbit,
  mode, and key measurements in the "Observation summary" panel so users see
  what they're processing.
