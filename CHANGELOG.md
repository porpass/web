# Changelog

All notable changes to porpass/web are documented in this file.

The format is based on
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and this
project adheres to [PEP 440](https://peps.python.org/pep-0440/)
versioning consistent with the rest of the `porpass/*` organization.

## [Unreleased]

## [0.1.0a1] - 2026-07-16

Initial public alpha.

### Added

- Observations catalog browser (`observations.php`) with per-instrument
  filters, spatial bounding-box search, and paginated results.
- Interactive map viewer (`map.php`) with per-instrument track layers,
  configurable basemaps, feature-click popup, and browse-thumbnail
  preview served by the GIS service.
- Per-user selection queue for gathering observations across the
  catalog and map before configuration; bulk-remove and single-item
  actions supported.
- Processing hub (`processing.php`) surfacing the selection queue and
  the user's submitted jobs.
- Schema-driven job configuration (`processing_configure.php`) that
  generates the form from the daemon-published GRaSP schema artifact
  (Contract A, `schema_version 1.1`) and produces a sparse per-job
  config (Contract B, `config_version 1.0`).
- Bulk configuration: select up to `WebPolicy::BATCH_MAX_SIZE` (10)
  queue items that share `(instrument, product)`; one shared config
  creates N job rows in a single batch, transactionally.
- Job detail page (`processing_job.php`) with observation summary,
  pretty-printed config, results manifest with per-file view/download,
  and tailed run log.
- Job actions: **edit** and **cancel** while queued; **cancel** while
  running (signals the daemon via `cancel_requested`); **rerun** and
  **delete results** (reclaim disk while retaining audit artifacts)
  once terminal.
- File download proxy (`api/processing_files.php`) with per-user
  ownership checks and real-path escape guards against manifest
  entries.
- Admin pages for users, instruments, planetary bodies, institutions
  and departments, announcements, change requests, and an analytics
  dashboard.
- Authentication flow with email verification, password reset, and
  session hardening.
- GIS service (`gis/`, FastAPI) exposing per-instrument vector
  features and shared configuration to the frontend.
- Cross-repo contracts (schema artifact, per-job config, result
  manifest) documented in
  [`docs/processing_contracts.md`](docs/processing_contracts.md).

### Known gaps

- Status polling for running jobs is not yet shipped; the endpoint
  scaffolding is in place but the poller waits until daemon
  integration is confirmed end-to-end.
- Aperture-length helper and richer observation-metadata display on
  the configure page are planned follow-ups.
- Database schema files are not in this repo; they will live in the
  planned `porpass/db` sibling repository.

[Unreleased]: https://github.com/porpass/web/compare/v0.1.0a1...HEAD
[0.1.0a1]: https://github.com/porpass/web/releases/tag/v0.1.0a1
