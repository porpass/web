# PORPASS

**Planetary Orbital Radar Processing and Simulation System**

PORPASS is a web application for browsing, querying, visualizing,
and processing planetary orbital radar observations from
instruments including **SHARAD** (Mars Reconnaissance Orbiter),
**MARSIS** (Mars Express), and **LRS** (Kaguya / SELENE). It provides authenticated users with a
searchable observation catalog, an interactive map viewer, and
administrative tools for managing instruments, institutions, planetary
bodies, and user accounts.

PORPASS is developed at the [Planetary Science Institute](https://www.psi.edu)
and is the front-end companion to the
[GRaSP](https://github.com/porpass/grasp) radar processing library and
the [porpass/daemon](https://github.com/porpass/daemon) processing worker.

---

## Status

PORPASS is under active development and not yet feature-complete.
APIs, schemas, and UI may change without notice prior to the first
tagged release.

## License

PORPASS is released under the [BSD 3-Clause License](LICENSE).

---

## Architecture

PORPASS is a small, self-contained stack:

| Component       | Technology                              | Location          |
|-----------------|-----------------------------------------|-------------------|
| Web frontend    | PHP 8 + Bootstrap 5 + vanilla JS        | `public/`, `src/` |
| GIS service     | Python 3.12 + FastAPI (config + tiles)  | `gis/`            |
| Database        | MariaDB / MySQL                         | external          |
| Map viewer      | Leaflet                                 | `public/map.php`  |
| Transactional mail | PHPMailer over SMTP                  | `src/mailer.php`  |

The PHP application serves the user-facing UI, authentication, and
administrative pages. The FastAPI service in `gis/` exposes the
instrument/filter configuration and serves vector data for the map.

```
┌────────────┐     ┌────────────┐     ┌──────────────┐
│  Browser   │ ──▶ │  PHP app   │ ──▶ │   MariaDB    │
│ (Leaflet)  │ ◀── │ (public/)  │     └──────────────┘
└─────┬──────┘     └────────────┘
      │
      ▼
┌────────────┐
│  FastAPI   │  (gis/config, gis/vectors)
│  (gis/)    │
└────────────┘
```

---

## Feature summary

- **Observation catalog** (`observations.php`) — per-instrument filters,
  spatial bounding-box search, paginated results.
- **Interactive map** (`map.php`) — per-instrument track layers with
  feature-click popups and browse-thumbnail previews.
- **Selection queue** — per-user basket for gathering observations
  across the catalog and map; per-item and bulk removal.
- **Processing hub** (`processing.php`) — the selection queue + the
  user's submitted jobs in one view.
- **Schema-driven job configuration** (`processing_configure.php`) —
  form generated on the fly from the daemon-published GRaSP schema
  artifact; sparse per-job config written on submit.
- **Bulk configuration** — select up to 10 queue items that share
  `(instrument, product)` and submit them as one transactional batch
  under a shared config.
- **Job detail** (`processing_job.php`) — observation summary,
  resolved TOML, results manifest with per-file view/download,
  tailed run log.
- **Job lifecycle** — edit and cancel while queued; cancel while
  running (signals the daemon); rerun and delete-results once
  terminal.
- **Admin console** — users, instruments, planetary bodies,
  institutions and departments, announcements, change requests, and
  an analytics dashboard.

## Repository layout

```
web/
├── public/                       # Web root (Apache DocumentRoot)
│   ├── index.php                 # Public landing page
│   ├── login.php / register.php  # Auth entry points
│   ├── dashboard.php             # Authenticated user dashboard
│   ├── observations.php          # Catalog browser
│   ├── map.php                   # Leaflet-based map viewer
│   ├── processing.php            # Selection queue + My jobs
│   ├── processing_configure.php  # Schema-driven configure form
│   ├── processing_job.php        # Per-job detail
│   ├── account.php
│   ├── admin/                    # Admin pages (users, instruments,
│   │                             # bodies, institutions, announcements,
│   │                             # change_requests, admin_dashboard)
│   ├── api/                      # JSON endpoints (processing_jobs,
│   │                             # processing_queue, processing_files,
│   │                             # processing_stats, admin_*)
│   ├── auth/                     # Email-verify and password-reset flows
│   ├── docs/                     # Per-instrument documentation pages
│   └── resources/                # Static assets (css, js, img)
├── src/                          # PHP application code outside the web root
│   ├── auth.php                  # Session / login helpers
│   ├── db.php                    # PDO connection (reads .env)
│   ├── mailer.php                # PHPMailer wrapper
│   ├── layout.php                # Shared layout / chrome
│   ├── StatusChecker.php         # External-service uptime probes
│   ├── database/                 # Query builders (observationQuery)
│   ├── partials/                 # Reusable view fragments
│   └── processing/               # Job lifecycle: ConfigBuilder,
│                                 # JobRepository, QueueRepository,
│                                 # SchemaLoader, Manifest, WebPolicy
├── gis/                          # FastAPI GIS service
│   ├── main.py
│   ├── routers/                  # config, vectors
│   ├── config/                   # instruments.yaml, basemaps.yaml
│   ├── requirements.txt
│   └── Dockerfile
├── docs/                         # Cross-repo contracts and reference docs
├── private/fixtures/schemas/     # Dev fallback for daemon-published artifacts
├── composer.json                 # PHP dependencies
├── package.json                  # JS dependencies (Bootstrap)
└── .env.example                  # Copy to .env to configure
```

---

## Getting started

### Prerequisites

- PHP 8.1+ with the `pdo_mysql` extension
- Composer
- MariaDB 10.5+ (or MySQL 8+)
- Python 3.12 (for the `gis/` service)
- A web server with PHP support (Apache, nginx, or XAMPP for local dev)
- An SMTP relay for transactional mail (registration, password reset)

### 1. Clone and install dependencies

```bash
git clone https://github.com/porpass/web.git
cd web

# PHP
composer install

# JS (Bootstrap)
npm install

# Python GIS service
cd gis
pip install -r requirements.txt
cd ..
```

### 2. Configure environment

```bash
cp .env.example .env
# Edit .env and fill in database, mail, and APP_URL values.
```

The `.env` file is loaded by `src/db.php` via `vlucas/phpdotenv` and is
read by the GIS service via standard environment variables (export the
same `DB_*` values into the shell that runs `uvicorn`).

### 3. Create the database

Database schema and provisioning live in the sibling
[porpass/db](https://github.com/porpass/db) repository (planned).
Follow its README to bootstrap `porpass_dev` before running the web
app. See [gis/DB_NOTES.md](gis/DB_NOTES.md) for narrative notes on
the schema shape used by the GIS service.

### 4. Run the web server

For local development under XAMPP, point a vhost at `public/`:

```apache
<VirtualHost *:80>
    ServerName porpass.local
    DocumentRoot /path/to/porpass/public
    <Directory /path/to/porpass/public>
        AllowOverride All
        Require all granted
    </Directory>
</VirtualHost>
```

Add `127.0.0.1 porpass.local` to `/etc/hosts`, then visit
`http://porpass.local`.

### 5. Run the GIS service

```bash
cd gis
uvicorn main:app --host 127.0.0.1 --port 8000 --reload
```

Or via Docker:

```bash
cd gis
docker build -t porpass-gis .
docker run --rm -p 8000:8000 \
  -e DB_HOST=... -e DB_NAME=... -e DB_USER=... -e DB_PASSWORD=... \
  porpass-gis
```

The GIS service is intended to sit behind the PHP frontend and is not
hardened for direct internet exposure.

---

## Configuration

All configuration is via environment variables loaded from `.env`.
See [.env.example](.env.example) for the full list and defaults.

The instrument filter panel in the map and observations browser is
driven by [gis/config/instruments.yaml](gis/config/instruments.yaml).
Filter definitions live in YAML and should never be hardcoded in PHP
or JavaScript.

Basemaps are configured in [gis/config/basemaps.yaml](gis/config/basemaps.yaml).

---

## Development

### Branch conventions

- `main` — release-ready
- `feature/<short-name>` — new functionality
- `debug/<short-name>` or `fix/<short-name>` — bug fixes

Pull requests are squash-merged into `main`.

### Commit conventions

This repository follows Conventional Commits:

```
feat(scope): summary
fix(scope): summary
refactor(scope): summary
docs(scope): summary
```

Common scopes used in the project: `admin`, `auth`, `browse`,
`dashboard`, `gis`, `observations`, `ui`.

---

## Contributing

Contributions, bug reports, and feature requests are welcome. See
[CONTRIBUTING.md](CONTRIBUTING.md) for branch conventions, commit
style, and the PR flow. Please open an issue to discuss substantial
changes before submitting a pull request.

## Security

If you discover a security vulnerability, please **do not** open a
public issue. See [SECURITY.md](SECURITY.md) for the responsible-
disclosure process.

## Acknowledgements

- [Planetary Science Institute (PSI)](https://psi.edu)
- [SHARAD at PSI](https://sharad.psi.edu)
- The PDS Geosciences Node, DARTS (JAXA/ISAS), and the USGS Astrogeology
  Science Center for the data sources surfaced through PORPASS.
