# Changelog

All notable changes to Izbik. The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and
versions follow [semantic versioning](https://semver.org). Upgrading is described in
[docs/self-hosting.md](docs/self-hosting.md#upgrading); a release's notes say what to change in `.env`, if anything.

## [Unreleased]

## [0.2.0] - 2026-11-04

### Added

- Project files: download a project with its files and models as one zip (or the JSON alone) from its page, and
  import one as a new project from the projects page, over REST or MCP, or with `make export-project` /
  `make import-project`.
- Cupboard doors hang on concealed hinges (or butt hinges), drawn in 3D and moving as the door opens.

### Fixed

- A deleted project is reported as deleted even when some of its stored files cannot be removed.

### Changed

- Dependencies updated (minor and patch releases).

## [0.1.0] - 2026-11-04

The first public beta, for running on a server of your own.

### Added

- 3D designer for flats and houses: floors, walls (curved too), rooms, doors, windows, stairs, roofs, voids, railings,
  plots with sloping terrain, furniture from parametric definitions and product models, materials and finishes,
  lighting, variants, a walkthrough, saved views, share links, review comments, versions, plans, elevations, sections
  and façades as PDF, a cut list, room and electrical schedules, quantities with costs, and an MCP server for AI agents.
- Start from an example: a copy of the demo flat, opened at its tour (`make example-models` adds its CC0 models).
- First steps guide on an empty floor, a shortcuts overlay (`?`) and a message where the browser lacks WebGL 2.
- Accounts on a self-hosted server: sign-up closed unless `SIGN_UP_ENABLED=1`, admins add users and set passwords,
  `make reset-password`, users delete their own account.
- Production setup: published images on GHCR (`make deploy`, `IZBIK_VERSION`), `make backup` / `make restore` for
  the database and the stored files, a nonce-based Content-Security-Policy, and a
  [self-hosting guide](docs/self-hosting.md).
- For contributors: CONTRIBUTING.md, SECURITY.md, issue and pull request templates, a browser smoke test in CI
  (`make smoke`) and *Report a problem* in the app's header.

### Changed

- `make env` gives a new `.env` random database and MinIO passwords.
- `BACKEND_CORS_ALLOWED_ORIGIN` defaults to empty (no cross-origin calls). Clear `*` in an existing `.env`.

[Unreleased]: https://github.com/marekskopal/izbik/compare/v0.2.0...HEAD
[0.2.0]: https://github.com/marekskopal/izbik/compare/v0.1.0...v0.2.0
[0.1.0]: https://github.com/marekskopal/izbik/releases/tag/v0.1.0
