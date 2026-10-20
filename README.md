# Flat Designer

A web application for designing flats and houses in 3D. Projects consist of one or more floors with rooms,
walls, doors and windows, finishes and furniture. Custom-made furniture is described as data: parametric
furniture definitions whose parts are sized by expressions. The application produces the 3D model, a floor plan,
a first-person walkthrough and a cut list from them.

All geometry is generated from parameters. The project contains no 3D model files.

## Features

- **3D viewer**: dollhouse overview, architectural plan view (section cut at 1.3 m, adjustable), and a
  first-person walkthrough with collisions and doors you can open (keyboard and pointer lock, or touch controls).
- **Editor**: select and edit rooms, walls, openings and items in an inspector; drag them in the plan view;
  draw runs of walls with snapping and typed lengths, and add a room by clicking inside the walls around it;
  snapping, keyboard shortcuts and undo/redo of your own changes. Changes use optimistic locking and are
  recorded in an activity log.
- **Furniture definitions**: parametric custom furniture (cabinets, wardrobes, worktops, tables, sofas, beds with
  bedding, mirrors, railings, …) defined
  as JSON with parameters, material slots and parts. Definitions can be global, part of a user's library or
  belong to one project. They are edited with a JSON editor that shows a live preview.
- **Measurements**: a room schedule (net area, perimeter, clear height and volume per room, indoor and outdoor
  totals) on the project page, room labels with area and height, dimension lines of the rooms in the plan view
  (split at doors and windows), and a measure tool that snaps to wall ends, corners and faces.
- **Cut list**: boards and fronts by material, thickness and grain direction, with edge banding and per-material
  totals.
- **Groups and variants**: group items into pieces of furniture, and compare alternatives as variants and
  switch between them.
- **Materials and finishes**: a shared material catalogue with procedural or photo textures, floor materials,
  wall finish bands and tile grids aligned across floors, walls and tiled items, ceiling materials, vaulted
  ceilings between beams, and skirting boards.
- **Versions**: immutable snapshots of a project that can be restored at any time.
- **Share links**: a read-only guest view of a floor, with configurable views, start camera and display options.
- **Live collaboration**: open viewers update in real time (Mercure) when someone else changes the project.
- **Project files**: upload plans, renders and photos to S3-compatible storage.
- **AI agents (MCP)**: a built-in MCP server lets agents such as Claude read and edit projects, with the
  same validation and history as the UI.
- **Accounts**: sign-up, JWT authentication, `Admin` and `User` roles. Users own their projects; admins
  see all projects and manage users.

## Tech stack

| Layer | Technology |
| --- | --- |
| Frontend | Angular 22 (standalone components, signals, zoneless), three.js, SCSS, Vitest |
| Backend | PHP 8.5 on FrankenPHP (worker mode), `marekskopal/orm`, `marekskopal/router`, `mcp/sdk` |
| Data | MariaDB 11.8, MinIO / S3 for files, Mercure (built into FrankenPHP) for live updates |
| Infrastructure | Docker Compose, nginx reverse proxy (optional TLS) |

## Getting started

Requirements: Docker with Compose, `make` and `openssl`. To develop the frontend or run lint and tests on the
host, you also need PHP 8.5 with Composer, Node.js and pnpm.

```sh
make up      # build and start the stack on http://localhost:4400
make admin   # create the first admin account (prompts for email and password)
```

On the first run, `make up` creates `.env` from `.env.example` and generates the token and Mercure secrets. The
backend applies pending database migrations on start, so a fresh database includes a sample project and the
global material and furniture catalogues.

New users can sign up at `/sign-up` and get the `User` role. Admins manage accounts under **Users**.

To import reference files (plans, renders, photos) into a project:

```sh
make import-files DIR=path/to/files PROJECT=1
```

### Configuration

All settings are in `.env`. `.env.example` documents each variable. The most important ones:

| Variable | Purpose |
| --- | --- |
| `PROXY_PORT` | HTTP port of the proxy (default `4400`) |
| `PROXY_HOST` | Public hostname. Required for OAuth/MCP behind a domain |
| `AUTHORIZATION_TOKEN_KEY` | JWT signing key (generated). Changing it signs everyone out |
| `MYSQL_*` | Database connection |
| `S3_*` | Object storage. Defaults to the bundled MinIO (console on `MINIO_CONSOLE_PORT`, default `4401`) |
| `FILE_MAX_SIZE_MB` | Largest accepted upload |

**HTTPS**: uncomment `COMPOSE_FILE` in `.env` to include `docker-compose.ssl.yml`, then set `PROXY_SSL_CERT` /
`PROXY_SSL_KEY`, or run `make ssl-cert` to generate a self-signed localhost certificate. The stack then serves
`https://localhost:4443` (`PROXY_PORT_SSL`), and the HTTP port redirects there.

## Development

```sh
make install   # backend and frontend dependencies on the host
make dev       # Angular dev server on http://localhost:4200, API proxied to the running stack
make lint      # phpcs + phpstan (max level), eslint
make test      # phpunit (separate flat_designer_test database), vitest
make reset-db  # drop the database and file volumes and re-seed
```

Schema and seed data changes always go into a new migration in `backend/migrations/`. If you edit a migration
that has already been applied, run `make reset-db`.

CI (GitHub Actions) runs composer and pnpm audits, PHPStan, PHP_CodeSniffer, PHPUnit, ESLint, Vitest and the frontend build.

### Repository layout

| Path | Contents |
| --- | --- |
| `proxy/` | nginx: `/api/*`, `/mcp`, OAuth discovery and Mercure → backend, everything else → frontend |
| `backend/src/` | Controllers, services (`Editor`, `Furniture`, `CutList`, `Project`, `Realtime`, …), entities, MCP tools, OAuth |
| `backend/migrations/` | Schema and seed data; `furniture/*.json` holds the seeded global furniture definitions |
| `backend/tests/` | PHPUnit unit and integration tests; shared fixtures for the expression language |
| `frontend/src/app/` | Angular pages and components (`editor`, `furniture`, `projects`, `viewer-page`, `share`, …) |
| `frontend/src/app/viewer/engine/` | Framework-free three.js engine: building, items, furniture definitions, walkthrough |
| `docs/` | Design notes and plans |

## Conventions

- Lengths are integer **millimetres** in plan coordinates. The origin is the inner south-west corner of the
  floor, x points east and y points north.
- Item rotation is in degrees counter-clockwise; 0 means the front faces south.
- Walls are centre lines with a thickness. Openings are positioned by their offset along the wall from its start.
- Openings are doors (single, double, sliding, pocket), passages without a door, and windows. Doors have a frame style
  (lining, architrave, flush, none), a handle (lever, knob, pull, none) and a flush, panelled or glazed leaf. Windows
  are divided by mullions and transoms, can have sill boards, a solid lower panel and louvred or solid exterior
  shutters, and two windows meeting at a wall corner form a corner window.

## AI agents (MCP)

The backend is an MCP server at `/mcp` (Streamable HTTP, OAuth 2.1 with dynamic client registration and PKCE).
Agents can read projects and floors, add, change and delete rooms, walls, openings and items, manage groups,
variants, share links, versions and furniture definitions, get cut lists and room schedules, and undo or redo their own changes.
Agent changes are logged under the client's name and appear live in open viewers.

Connect Claude Code:

```sh
claude mcp add --transport http flat-designer http://localhost:4400/mcp
```

Approve the connection in the browser that opens. In claude.ai, add the URL as a custom connector (the app has
to be on a public HTTPS host with `PROXY_HOST` set). Connected agents are listed on the **Account** page, where
you can also revoke them.

## API overview

Every endpoint except health, authentication, OAuth and public share links requires a Bearer access token.

| Endpoint | Description |
| --- | --- |
| `/api/authentication/{login,sign-up,refresh-token}` | Sign-in and token refresh |
| `/api/current-user`, `…/password`, `…/mcp-clients` | Account, password change, connected agents |
| `/api/projects`, `/api/projects/{id}` | Projects |
| `…/floors/{floorId}/scene` | Everything the viewer needs to render a floor |
| `…/floors/{floorId}/{items,walls,openings,rooms,groups,variant-sets}` | Element editing (send the revision as `If-Match`; a stale revision returns `409`) |
| `…/floors/{floorId}/walls/polyline`, `…/rooms/detect` | Draw a run of walls (one change); add the room enclosed by walls around a point |
| `/api/projects/{id}/{changes,undo,redo}` | Activity log and undo/redo |
| `/api/projects/{id}/versions` | Saved versions and restore |
| `/api/projects/{id}/share-links`, `/api/shares/{token}` | Share links and the public guest view |
| `/api/projects/{id}/files` | Project files |
| `/api/projects/{id}/cut-list` | Cut list of the project's custom furniture |
| `/api/projects/{id}/room-schedule` | Room areas, perimeters and clear heights with indoor and outdoor totals (also per floor) |
| `/api/furniture-definitions` | Furniture definitions (CRUD, copy, validate) |
| `/api/materials`, `/api/item-kinds` | Catalogues |
| `/api/realtime/subscription` | Cookie for the Mercure live-update stream |
| `/api/admin/users` | User management (admins only) |

## License

[MIT](LICENSE)
