# Changelog

All notable changes to Izbik. The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and
versions follow [semantic versioning](https://semver.org). Upgrading is described in
[docs/self-hosting.md](docs/self-hosting.md#upgrading); a release's notes say what to change in `.env`, if anything.

## [Unreleased]

## [0.4.0] - 2026-11-07

### Added

- A modern kitchen catalogue: handle positions on fronts (edge pulls and bars at the top, bottom or side), steam oven
  and coffee machine fronts with black, white, steel or retro finishes, a sink's drainer side, round range hoods and
  stepped or pyramid canopies, waterfall ends on the straight and L-shaped worktops, and sinks and hobs cutting their
  holes in the worktop under them (sinks with real basins).
- Seating and lighting: a shell chair, chairs and tables with seats and legs in their own materials, oval tables and
  pedestal bases, bar stool options, pendant clusters, cone, teardrop, disc and wire-cage shades, and LED strips shining
  up as a cove or out, which switches can control and the electrical schedule counts.
- 20 materials: stained oak lacquers, painted brick, strip and long metro tiles, compact worktops, smoky glass,
  upholstery colours and fluted panels.
- Room zones: another floor material over part of a room, and dropped ceilings (a bulkhead over a kitchen run) over
  part of its ceiling, over REST and MCP (`add_room` / `update_room`), counted in the quantities.
- Wall finish bands on a stretch of a wall face (`from` / `to` along the wall: a splashback behind one room's run of a
  shared wall), and window reveals lined on the room's side with the band there.
- MCP: `get_floor` for one room (`roomId`) of a big floor; `list_furniture_definitions` gives each definition's
  catalogue and material slots and filters by catalogue.

### Changed

- The kitchen cabinets, worktops, sink, hob, hood, pendants, bar stool and dining furniture seeds gained options; their
  existing items look as before.

## [0.3.0] - 2026-11-05

### Added

- English and Czech: the app, its emails and the API's messages in either language, chosen on the account page (pages
  without an account have a language switch, the shared viewer follows the browser). The seeded furniture, materials
  and example projects are translated; dates and numbers follow the language.
- DXF import: walls and openings from an ASCII DXF plan among the project files, the layers picked and the result
  previewed in the underlay panel, over REST or MCP (`inspect_dxf`, `import_dxf`).
- PDF plans as underlays: a page of a PDF project file can be laid under a floor (a page picker for multi-page PDFs);
  agents read PDF pages through `get_project_file` and lay them with `set_floor_underlay`.
- Switches link to the lights they control: dashed links on the electrical plan (viewer and PDF), lighting circuits
  in the electrical schedule, picked with `L` or the inspector's Pick lights, and `controls` in MCP `add_item` /
  `update_item`.
- Hinges for windows, garage doors and every door frame: barrel, concealed and strap hinges drawn in 3D, a new `Steel`
  door frame style, and a double door's passive leaf opening after the active one. The elevations mark the hinges.

### Changed

- The backend image includes poppler and DejaVu fonts (for PDF underlays); the published images have them, a build
  from source picks them up with `make up`.
- Dependencies updated (minor and patch releases).

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

[Unreleased]: https://github.com/marekskopal/izbik/compare/v0.4.0...HEAD
[0.4.0]: https://github.com/marekskopal/izbik/compare/v0.3.0...v0.4.0
[0.3.0]: https://github.com/marekskopal/izbik/compare/v0.2.0...v0.3.0
[0.2.0]: https://github.com/marekskopal/izbik/compare/v0.1.0...v0.2.0
[0.1.0]: https://github.com/marekskopal/izbik/releases/tag/v0.1.0
