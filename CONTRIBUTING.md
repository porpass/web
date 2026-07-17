# Contributing to porpass/web

Thanks for your interest in improving PORPASS. Bug reports, feature
requests, and pull requests are all welcome.

## Before you start

- Read the [Code of Conduct](CODE_OF_CONDUCT.md).
- For substantial changes, open an issue first so we can align on
  the approach before you invest coding time.
- Security-sensitive issues follow the process in
  [SECURITY.md](SECURITY.md) — please do not open a public issue
  for those.

## Development setup

See the "Getting started" section of [README.md](README.md) for the
full local-dev setup: PHP 8.1+, Composer, MariaDB, and a running
GIS service. PORPASS runs as a classic PHP web application under
Apache or nginx — there is no Docker or containerized dev flow in
this repo.

## The porpass/* organization

This repo is one of four under [github.com/porpass](https://github.com/porpass):

- `porpass/web` — this repo, the PHP web application
- `porpass/daemon` — the processing worker
- `porpass/grasp` — the radar processing library
- `porpass/db` — database schema and provisioning (planned)

Three frozen cross-repo contracts (the schema artifact, per-job
config, and result manifest) are documented in
[`docs/processing_contracts.md`](docs/processing_contracts.md).
Contract changes are coordinated across repos and require an issue
discussion — please do not propose contract changes in a PR without
prior alignment.

## Branches and pull requests

- `main` is the release-ready branch.
- Feature branches: `feature/<short-name>`
- Bug fixes: `debug/<short-name>` or `fix/<short-name>`
- PRs are squash-merged into `main`.

Target `main` and describe the change in the PR body. Small,
independent commits are appreciated but not required — the squash
merge collapses them.

## Commit style

The project uses [Conventional Commits](https://www.conventionalcommits.org/):

```
feat(scope): summary
fix(scope): summary
refactor(scope): summary
docs(scope): summary
chore(scope): summary
```

Common scopes: `admin`, `auth`, `browse`, `dashboard`, `gis`,
`map`, `observations`, `processing`, `ui`.

## Versioning

PORPASS uses [PEP 440](https://peps.python.org/pep-0440/)
versioning consistent with the rest of the `porpass/*` org. The
current line is `0.1.0aN` (alpha). See [CHANGELOG.md](CHANGELOG.md)
for the release log in
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/) format.

## Reviews and merging

The maintainers will review PRs; there is no formal multi-reviewer
sign-off requirement during the alpha. Please be patient — the
project is small and volunteer-driven.
