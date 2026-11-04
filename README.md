# Izbik

A web application for designing flats and houses in 3D. Projects consist of one or more floors with rooms,
walls, doors and windows, finishes and furniture. Custom-made furniture is described as data: parametric
furniture definitions whose parts are sized by expressions. The application produces the 3D model, a floor plan,
a first-person walkthrough and a cut list from them, and AI agents can do all of it over MCP.

> **Beta.** Izbik is in its first public beta: expect rough edges, and back up before every upgrade.
> [Report a problem](https://github.com/marekskopal/izbik/issues/new/choose) when you meet one.

![A flat in Barcelona, cut away at 2.2 m](docs/screenshots/view-tour-01-cut-away.webp)

All geometry is generated from parameters. The repository contains no 3D model files: bought products are glTF
models you upload into the app. The screenshots show the demo flat and the demo house, which an AI agent modelled
over MCP (see [Built over MCP by an AI agent](#built-over-mcp-by-an-ai-agent)).

## Feature tour

### Floor plan with dimensions

![Plan view with dimension lines](docs/screenshots/view-tour-02-plan.webp)

The plan view cuts the walls at 1.3 m (adjustable) and draws every room edge's dimension line, split at doors and
windows. A measure tool snaps to wall ends, corners and faces, and a calibrated image of a plan can lie underneath.

### Room schedule

![Project page with the room schedule](docs/screenshots/project.webp)

The project page lists each room's net area, perimeter, clear height and volume, with indoor and outdoor totals.
Below it, the electrical schedule lists for the electrician each room's lights, sockets (with their gangs), switches,
data sockets (with their ports) and plumbing points with their elevations. A definition's category and what the
schedule counts and lists are set in the furniture editor. In the viewer the Display toggle "Electrical (Plan)" draws
the points as symbols over the plan (IEC 60617 or Czech ČSN, chosen on the account page), and Export → Electrical PDF
prints them with their elevations and a legend. Floors, versions, share links and project files are managed
here too.

### Eye-level views and the walkthrough

![Eye-level view along the corridor](docs/screenshots/view-tour-09-eye-level.webp)

Walk through the flat in first person with collisions and doors you can open, using the keyboard and the mouse or
touch controls. Saved views keep eye-level shots like this one, with their camera and display options.

### Kitchens and built-ins

![The kitchen with its sliding panels open](docs/screenshots/view-tour-03-kitchen-open.webp)

Cabinets, worktops, end panels and appliances come from furniture definitions with parameters for fronts, handles
and materials. The oak sliding panels are a definition the agent wrote for this flat.

### Variants

![The same kitchen with the panels closed](docs/screenshots/view-tour-04-kitchen-closed.webp)

Group items into pieces of furniture and keep alternatives as variants of a set, here "Kitchen open / closed".
Switching variants is one click, and saved views and share links remember the one they show.

### Materials, finishes and ceilings

![Living room with brick vaults, oak floor and furniture](docs/screenshots/view-tour-05-living-room.webp)

A shared material catalogue with procedural and photo textures: planks, tiles, terrazzo, brick and herringbone on
one tile grid per room. Ceilings can be vaulted between beams, with skirting, French windows, shutters and railings. Columns and
downstand beams are cut by the section like walls, and walls can have niches.

### Furniture, textiles and product models

![Master bedroom with bed, bedding and a reading lamp](docs/screenshots/view-tour-06-master-bedroom.webp)

Beds with bedding, sofas, rugs, curtains, books and framed pictures are definitions. Bought products such as the
lamp, chairs and plants are uploaded glTF models that scale to fit their box.

### Bathrooms

![Bathroom with shower screen, vanity, round mirror and herringbone tiles](docs/screenshots/view-tour-07-bathroom.webp)

Wall finish bands, tile grids and sanitary ware: a walk-in shower screen, a wall-hung vanity, a round mirror and
a WC. Doors have panelled, flush or glazed leaves, frame styles and handles.

### Lighting at night

![Living room at night, lit by its pendants](docs/screenshots/view-tour-08-night.webp)

Pendants whose cables follow the vaulted ceiling, downlights and lamp lights have lumens and a colour
temperature. The night view switches off the sun and lights the flat with its own fixtures.

### Houses: floors, roofs, stairs and the site

![A family house on a slope, its garden façade in the morning sun](docs/screenshots/view-house-tour-01-garden-facade.webp)

A project can be a whole house: floors stacked at their levels with slabs between them, roofs over them and the land
around them. The demo house stands on a slope in Brno, so the sun above is where it really is on 21 June at 9:30,
from the site's latitude, longitude and north angle.

![All three floors, cut at the first floor](docs/screenshots/view-house-tour-02-all-floors-cut.webp)

The All floors view draws the floors under the shown one, here cut at the first floor's walls. The plot follows spot
heights, so the ground floor is dug into the hill on one side and opens onto a larch deck on the other; garden walls,
fences and steps stand on the ground.

![The beaver-tail roof with roof windows, snow guards, gutters and the chimney](docs/screenshots/view-house-tour-03-roof.webp)

Roofs are gable, hip, shed or flat, with overhangs, a skewed verge where the gable wall is not square, valleys where
they meet, roof windows, gutters with downpipes, snow guards and ridge tiles. A chimney rises through the floors to
the roof, and the walls under a roof are cut at its underside or rise to it.

![A child's room under the slope, lit by a roof window](docs/screenshots/view-house-tour-04-attic.webp)

Under the roof, ceilings follow the slope and open under the roof windows. The room schedule gives each room's clear
height and its area with at least 1.2 m of headroom.

![Standing on the stairs, looking down into the living room](docs/screenshots/view-house-tour-05-on-the-stairs.webp)

Stairs are straight, L-shaped, U-shaped or spiral, on stringers or solid, with railings; they cut their void through
the floor above. The walkthrough climbs them to the next floor and walks under them where there is headroom.

![The living room at night with the fireplace and the lights on](docs/screenshots/view-house-tour-06-living-room-at-night.webp)

![The first floor's plan with its section lines](docs/screenshots/house-plan.webp)

![Section B–B through the house and the slope](docs/screenshots/house-section.webp)

The exports include the building's façades and sections: a section line drawn on the plan cuts the walls, slabs,
roofs, stairs and the ground, with level marks at the floors, eaves and ridge.

### Editor

![Editor with the kitchen's inspector open](docs/screenshots/editor.webp)

Select rooms, walls, openings and items and edit them in the inspector, drag them in the plan, draw runs of walls
with snapping (straight or curved), bend a wall by its middle, and outline a room by clicking inside it. Every change is logged, and you can undo your own.

### Furniture definitions

![Furniture definition editor with a live preview](docs/screenshots/furniture-editor.webp)

A definition is JSON: parameters, material slots and parts sized by expressions of the item's width, depth and
height. The editor previews it live, and the form on the right is generated from its parameters.

### Cut list

![Cut list of the project's custom furniture](docs/screenshots/cut-list.webp)

Boards and fronts of the custom furniture, listed by material, thickness and grain direction with edge banding,
plus area and banding totals per material. It prints as a sheet for the workshop.

### Quantities and cost

What to buy for each room: floor area, wall finish bands (tiling), the paint around and above them up to the ceiling
with doors and windows left out, ceilings (vaults and beams included) and running metres of skirting. Tiles and
planks are counted in whole pieces, with a waste allowance. Enter a project's prices per m² or running metre, and
the page totals the cost per room and for the whole project, furniture boards from the cut list included.

### Share links

![Guest view of a share link](docs/screenshots/share-link.webp)

A read-only guest view of a floor, without an account, with the allowed views, a start camera and display options.
Guests can orbit, read the plan, walk through and switch variants, and download the plan, elevations, façades, sections and 3D
model unless the link forbids it. A link can also show the floor's open review comments, and let guests add some.

### Review comments

Comments are pinned to a point in the scene: press <kbd>C</kbd>, click a wall, a worktop or the floor, and write.
Each thread has a numbered pin in the viewer, replies and a Resolve button. The project's owner, share link guests and
connected agents all write comments, so an agent can read what people want changed, reply and resolve the thread once
it has made the change.

### And also

- **Versions**: immutable snapshots of a project that can be restored at any time.
- **Saved views and screenshots**: named cameras with display options and variants. `make screenshots` captures
  them (these images are made that way), and the viewer saves a PNG at presentation quality.
- **Exports**: the floor plan on A4 at a standard scale (walls, door swings, windows, room areas, dimensions and
  furniture footprints) as a PDF with the room schedule, or as an SVG; wall elevations of every room for the tiler
  and the joiner (tile joints on the room's grid, openings, fitted furniture, dimensions); the building's façades
  from all four sides and sections along section lines drawn on the plan (cut walls, slabs, roofs, stairs and terrain,
  with level marks); and the floor as a 3D model (glTF) for Blender or SketchUp.
- **Presentation quality**: ambient occlusion, antialiasing and soft sun shadows for renders.
- **Live collaboration**: open viewers update in real time (Mercure) when someone else changes the project.
- **Project files**: plans, renders and photos in S3-compatible storage. Plans can be traced as underlays.
- **Project export and import**: a project downloads as one file (a zip with its plans, photos and product models, or
  the JSON alone) and imports as a new project, on the same Izbik or another one: to move a project, or as your own
  backup. What the other instance cannot take (a material or model it does not have) is left out and listed.
- **AI agents (MCP)**: a built-in MCP server lets agents such as Claude read and edit projects, with the same
  validation and history as the UI.
- **Accounts**: sign-up with email verification, optional Sign in with Google, password reset by email, JWT
  authentication, `Admin` and `User` roles. Users own their projects; admins see all projects and manage users.

## Built over MCP by an AI agent

The demo flat in the screenshots was modelled by Claude Code through the app's MCP tools alone, with no clicks in
the editor. It is modelled after the *Refurbishment of an Apartment in Barcelona* by Allaround Lab (2017), photos
by José Hevia. The original plan and photos are not part of this repository, and the images here are our own
renders.

The input was the published plan, a section and twelve photos uploaded as project files, plus a written
inventory of the flat ([docs/demo-flat.md](docs/demo-flat.md)). The agent worked in phases and saved a version
after each one:

1. Read the plan with `get_project_file` and calibrate it as an underlay from the scale bar (`set_floor_underlay`).
2. Draw the facade, shell and partitions as runs of walls (`add_walls`) and outline the rooms (`detect_room`).
3. Add doors, French windows with shutters, light-well windows, balconies and railings.
4. Set the finishes: oak and terrazzo floors, brick vaults between beams, a dropped ceiling, tiles and skirting.
5. Build the kitchen from definitions, and write a new definition for its oak sliding panels, with an open and a
   closed variant.
6. Furnish every room from the plan, grouping each piece of furniture. Bought products are uploaded models.
7. Add the lights, switches, sockets and decor, then save views at the photos' camera positions and create a
   share link.
8. Compare the views with the photos, fix what was wrong, and log what the app cannot do yet as tasks.

| | |
| --- | --- |
| Time | 22 minutes |
| MCP tool calls | about 190 |
| Logged changes | 212 |
| Walls, openings, rooms | 27, 13, 11 |
| Items | 89 in 8 groups, 1 variant set |

The product models are CC0 models from [Poly Haven](https://polyhaven.com), uploaded into the app and not part of
this repository. Where no model matched (the Cesca and butterfly chairs, the vessel basin, black taps), the
demo uses stand-ins.

### The demo house

The demo house was built the same way, modelled after a house by JRA Jarousek.Rochova.Architekti (*Family House
Žebětín*, 2015), photos Filip Šlapal. As with the flat, the original drawings and photos are not part of this
repository, and the images here are our own renders. The agent read the three floor plans, the section, the
elevations and 22 photos, and worked through nine phases: the site and floors, the shell, the openings, the stairs and
gallery, the roofs, the sloping plot with its garden, the finishes, the furniture, and the saved views with a share
link ([docs/demo-house.md](docs/demo-house.md) has the inventory and the result). What the app could not do yet was
logged as tasks, and several were built along the way (sloping terrain, site walls and garden steps, skewed roof verges,
the building sections).

| | |
| --- | --- |
| Time | 17 minutes |
| MCP tool calls | about 270 |
| Logged changes | 276 |
| Floors | 3, on a slope from +3.240 at the street to −2.000 at the garden's end |
| Walls, openings, rooms | 41, 30, 22 |
| Stairs, roofs, roof windows | 4, 4, 5 |
| Items | 100 in 6 groups |

## Tech stack

| Layer | Technology |
| --- | --- |
| Frontend | Angular 22 (standalone components, signals, zoneless), three.js, SCSS, Vitest |
| Backend | PHP 8.5 on FrankenPHP (worker mode), `marekskopal/orm`, `marekskopal/router`, `mcp/sdk` |
| Data | MariaDB 11.8, MinIO / S3 for files, Mercure (built into FrankenPHP) for live updates |
| Infrastructure | Docker Compose, nginx reverse proxy (optional TLS) |

## Self-hosting

Izbik runs as a Docker Compose stack on one machine: the nginx proxy, the Angular frontend, the PHP backend (API, MCP
server, live updates), MariaDB and MinIO for uploaded files. The full guide, with HTTPS, external S3 and a backup
schedule, is [docs/self-hosting.md](docs/self-hosting.md); what changed between releases is in
[CHANGELOG.md](CHANGELOG.md).

Requirements: Docker with Compose, `make`, `openssl` and `git`; a small server (2 cores, 2 GB of memory) is enough, as
the 3D view runs in the browser.

### Install

```sh
git clone --branch v0.1.0 https://github.com/marekskopal/izbik.git
cd izbik
make env      # .env with random secrets and passwords; review it (see Configuration)
make deploy   # start the published images (ghcr.io/marekskopal/izbik-*) of IZBIK_VERSION
make admin    # create the first admin account (prompts for email and password)
```

`make up` builds the images from the checkout instead: to try Izbik on your own machine
(http://localhost:4400), to serve it under a path (`APP_PATH`) or to run changes of your own. The backend applies
pending database migrations on start, so a fresh database includes a sample project and the global material and
furniture catalogues.

Accounts: sign-up is closed by default, so a server on the internet is not open to everyone. Admins add accounts
under **Users** (and set a new password there for someone locked out), or set `SIGN_UP_ENABLED=1` in `.env` to let
anyone sign up at `/sign-up` with the `User` role. Without mail, an account's password is reset from the server:

```sh
make reset-password EMAIL=jane@example.com   # prompts for the new password; signs Jane out everywhere
```

Users delete their own account, with all their projects, on the account page.

The projects page offers **Start from an example**: a copy of the demo flat (`backend/examples/`) goes into the
user's projects, opened at its guided tour. Its product models are CC0 models from [Poly Haven](https://polyhaven.com),
which the repository does not ship. Download, optimise and import them once (the stack must be running):

```sh
make example-models   # into data/example-models (ignored by git), then imported as global models
```

Without them, the example's lamps, chairs and plants draw as boxes. Running it again only adds what is missing.

Two sign-in extras are off until configured in `.env` (then `make deploy` or `make up` again):

- **Sign in with Google**: set `GOOGLE_CLIENT_ID` to an OAuth web client id whose authorized JavaScript origins include
  the site's origin. The first Google sign-in creates an account without a password, or links the account with the
  email Google verified.
- **Mail** (password reset and email verification): set `SMTP_HOST` (and `SMTP_PORT`, `SMTP_USER`, `SMTP_PASSWORD`,
  `EMAIL_FROM`). The sign-in page then offers *Forgot password?*, which mails a one-time link valid for an hour, and a
  new account gets a link to confirm its email (the app shows a banner until it is confirmed). To try it locally, use
  the bundled Mailpit: `SMTP_HOST=mailpit`, `SMTP_PORT=1025`, `COMPOSE_PROFILES=mail`, and read the mail on
  http://localhost:4402.

### Configuration

All settings are in `.env`, and `.env.example` documents each one. `make env` fills a new `.env` with random values
for the token key, the Mercure keys and the database and MinIO passwords. The most important settings:

| Variable | Purpose |
| --- | --- |
| `PROXY_PORT` | HTTP port of the proxy (default `4400`) |
| `PROXY_HOST` | Public hostname. Required for OAuth/MCP behind a domain |
| `AUTHORIZATION_TOKEN_KEY` | JWT signing key (generated). Changing it signs everyone out |
| `MYSQL_*` | Database connection |
| `S3_*` | Object storage. Defaults to the bundled MinIO (console on `MINIO_CONSOLE_PORT`, default `4401`) |
| `FILE_MAX_SIZE_MB` | Largest accepted upload |
| `IZBIK_VERSION` | The published images `make deploy` runs (`latest`, or a version such as `0.1.0`) |
| `SIGN_UP_ENABLED` | `1` lets anyone sign up; `0` (default) leaves adding accounts to admins |
| `BACKEND_CORS_ALLOWED_ORIGIN` | Empty (default) on a server: the app and the API share one origin |

**HTTPS**: serve Izbik on a domain over HTTPS, with TLS in the proxy or in front of the stack. For TLS in the proxy,
uncomment `COMPOSE_FILE` in `.env` to include `docker-compose.ssl.yml` and set `PROXY_SSL_CERT` / `PROXY_SSL_KEY` (a
certificate's full chain and key; `make ssl-cert` makes a self-signed one for localhost, served on
`https://localhost:4443`); plain HTTP then redirects. Set `PROXY_HOST` either way. The proxy sends a nonce-based
Content-Security-Policy with every page (`proxy/conf/app-headers.conf`).

**External S3**: point the `S3_*` settings at the bucket (`S3_ENDPOINT` empty for AWS, `S3_USE_PATH_STYLE=false`); the
bundled MinIO then stays unused.

### Connecting AI agents on a domain

With `PROXY_HOST` set and HTTPS in place, MCP clients connect to `https://<PROXY_HOST>/mcp` (OAuth discovery
advertises that host), e.g. `claude mcp add --transport http izbik https://izbik.example.com/mcp`, or as a custom
connector in claude.ai. See [AI agents (MCP)](#ai-agents-mcp).

### Backups

```sh
make backup                             # database and every stored file into backups/<date-time>/
make restore BACKUP=backups/<date-time> # put them back (asks first)
```

One project goes into a project file and back with `make export-project PROJECT=…` and
`make import-project FILE=… EMAIL=…`, as with the project page's Download and the projects page's Import.

Copy `backups/` off the machine, and keep `.env` with them: a database without its `AUTHORIZATION_TOKEN_KEY` signs
everyone out. A cron line and the restore to a new machine are in
[docs/self-hosting.md](docs/self-hosting.md#backups).

### Upgrading

1. Read the [changelog](CHANGELOG.md) up to the new version.
2. `make backup BACKUP=backups/before-<version>`.
3. `git fetch --tags && git checkout v<version>`, and with the published images set `IZBIK_VERSION=<version>` in `.env`.
4. `make deploy` (or `make up` when building from source). The new migrations run when the backend starts.

Upgrades go forward from any earlier release; to go back, restore the backup made before the upgrade.

## Development

To develop the frontend or run lint and tests on the host, you also need PHP 8.5 with Composer, Node.js 24 and pnpm.
[CONTRIBUTING.md](CONTRIBUTING.md) describes how to contribute; [SECURITY.md](SECURITY.md) how to report a
vulnerability. Problems and ideas go to the [issues](https://github.com/marekskopal/izbik/issues) (the app's header
links there as *Report a problem*).

```sh
make up        # build and start the stack from the checkout on http://localhost:4400
make install   # backend and frontend dependencies on the host
make dev       # Angular dev server on http://localhost:4200, API proxied to the running stack
make lint      # phpcs + phpstan (max level), eslint
make test      # phpunit (separate izbik_test database), vitest
make smoke     # browser smoke test of the running stack (sign-up open): walls, a room, a door, a share link, the example, its export and import
make reset-db  # drop the database and file volumes and re-seed
```

Schema and seed data changes always go into a new migration in `backend/migrations/`. If you edit a migration
that has already been applied, run `make reset-db`.

Izbik was called Flat Designer before. A checkout from then keeps its data in the volumes of the `flat-designer`
compose project: run `make down` with the old checkout, then `make migrate-volumes` once to copy them into the
`izbik` ones. Its `.env` keeps working, as the database and storage credentials stay what they were.

CI (GitHub Actions) runs composer and pnpm audits, PHPStan, PHP_CodeSniffer, PHPUnit, ESLint, Vitest, the frontend
build and the smoke test against the whole stack. A version tag publishes the images and a GitHub release
(`.github/workflows/release.yml`).

### Data and tooling

To import reference files (plans, renders, photos) into a project:

```sh
make import-files DIR=path/to/files PROJECT=1
```

To upload a directory of `.glb` models (global, or a project's own with `PROJECT`), each with an optional
`<name>.json` sidecar holding `name`, `license`, `author` and `sourceUrl`:

```sh
make import-models DIR=path/to/models [PROJECT=1]
```

The repository ships no third-party models. Keep downloaded ones outside git (e.g. in the ignored
`source_files/`) and upload them into the app.

To refresh the screenshots in `docs/screenshots/` from a project's saved views, the editor and the pages around
the viewer (the stack must be running; with `cwebp` installed they are saved as WebP):

```sh
make screenshots PROJECT=5 EMAIL=you@example.com PASSWORD=… VIEWS="Tour ·" ROOM=Kitchen
make screenshots PROJECT=13 EMAIL=you@example.com PASSWORD=… VIEWS="House tour ·" VIEWS_ONLY=1
```

`VIEWS` keeps only the saved views whose name starts with it, `VIEWS_ONLY=1` leaves out the editor and the pages (the
house's views beside the flat's), and `ROOM` is the room selected for the editor shot.
The plan underlay is hidden in the screenshots.

To measure the rendering cost of the saved views, run the benchmark before and after a change. Add `?stats` to a
viewer URL for a live readout.

```sh
make benchmark PROJECT=5 EMAIL=you@example.com PASSWORD=… VIEWS="Tour ·"
make benchmark PROJECT=5 EMAIL=you@example.com PASSWORD=… VIEWS="Tour ·" BASELINE=frontend/benchmarks/<earlier>.json
```

### Repository layout

| Path | Contents |
| --- | --- |
| `proxy/` | nginx: `/api/*`, `/mcp`, OAuth discovery and Mercure → backend, everything else → frontend |
| `backend/src/` | Controllers, services (`Editor`, `Furniture`, `CutList`, `Project`, `Realtime`, …), entities, MCP tools, OAuth |
| `backend/migrations/` | Schema and seed data; `furniture/*.json` holds the seeded global furniture definitions |
| `backend/tests/` | PHPUnit unit and integration tests; shared fixtures for the expression language |
| `frontend/src/app/` | Angular pages and components (`editor`, `furniture`, `projects`, `viewer-page`, `share`, …) |
| `frontend/src/app/viewer/engine/` | Framework-free three.js engine: building, items, furniture definitions, walkthrough |
| `docs/` | Design notes and plans; `screenshots/` holds the README images |

## Conventions

- Lengths are integer **millimetres** in plan coordinates. The origin is the inner south-west corner of the
  ground floor, x points east and y points north; all floors of a project share it, so they stack. A floor has an
  elevation (its finished floor level above ±0.000) and a slab thickness.
- Item rotation is in degrees counter-clockwise; 0 means the front faces south.
- Walls are centre lines with a thickness, straight or curved (an arc given by its sagitta). Openings are positioned by
  their offset along the wall from its start.
- Openings are doors (single, double, sliding, pocket), passages without a door, windows and niches (recesses into one
  face of the wall). Doors have a frame style
  (lining, architrave, flush, none), a handle (lever, knob, pull, none) and a flush, panelled or glazed leaf. Windows
  are divided by mullions and transoms, can have sill boards, a solid lower panel and louvred or solid exterior
  shutters, and two windows meeting at a wall corner form a corner window.

## AI agents (MCP)

The backend is an MCP server at `/mcp` (Streamable HTTP, OAuth 2.1 with dynamic client registration and PKCE).
Agents can create, rename and delete projects, read projects and floors, add floors, add, change and delete rooms, walls, openings and items, manage groups,
variants, share links, versions and furniture definitions, upload project files and product models, get cut lists, room and electrical schedules and quantities, set prices, read, write and resolve review comments, and undo or redo their own changes.
Agent changes are logged under the client's name and appear live in open viewers.

Connect Claude Code:

```sh
claude mcp add --transport http izbik http://localhost:4400/mcp
```

Approve the connection in the browser that opens. In claude.ai, add the URL as a custom connector (the app has
to be on a public HTTPS host with `PROXY_HOST` set). Connected agents are listed on the **Account** page, where
you can also revoke them.

## API overview

Every endpoint except health, authentication, OAuth, public share links and one-time upload URLs requires a Bearer
access token.

| Endpoint | Description |
| --- | --- |
| `/api/authentication/{login,sign-up,refresh-token}` | Sign-in and token refresh |
| `/api/authentication/{options,google-login}` | What the sign-in page offers, Sign in with Google |
| `/api/authentication/{request,confirm}-password-reset` | Password reset by email |
| `/api/authentication/verify-email`, `/api/current-user/resend-verification` | Email verification |
| `/api/current-user`, `…/password`, `…/mcp-clients` | Account, password change, connected agents |
| `/api/projects`, `/api/projects/{id}` | Projects |
| `…/floors/{floorId}/scene` | Everything the viewer needs to render a floor |
| `…/floors/{floorId}/{items,walls,openings,rooms,groups,variant-sets}` | Element editing (send the revision as `If-Match`; a stale revision returns `409`) |
| `…/floors/{floorId}/walls/polyline`, `…/rooms/detect` | Draw a run of walls (one change); add the room enclosed by walls around a point |
| `/api/projects/{id}/{changes,undo,redo}` | Activity log and undo/redo |
| `/api/projects/{id}/versions` | Saved versions and restore |
| `/api/projects/{id}/share-links`, `/api/shares/{token}` | Share links and the public guest view |
| `/api/projects/{id}/comments`, `/api/shares/{token}/comments` | Review comments, and those a share link's guests read and write |
| `/api/uploads/{token}` | One-time upload URL an MCP agent got (PUT the raw file; the token authorizes it) |
| `/api/projects/{id}/views` | Saved views (camera, display, variants of a floor) |
| `/api/projects/{id}/files` | Project files |
| `/api/projects/{id}/export`, `/api/projects/import` | A project as a project file (zip, or `?files=0` the JSON alone), and a new project from one |
| `/api/projects/{id}/cut-list` | Cut list of the project's custom furniture |
| `/api/projects/{id}/room-schedule` | Room areas, perimeters and clear heights with indoor and outdoor totals (also per floor) |
| `/api/projects/{id}/electrical-schedule` | Lights, sockets, switches, data sockets and plumbing points per room (also per floor) |
| `/api/projects/{id}/quantities`, `…/material-prices` | Quantities and cost estimate (also per floor); the project's price list |
| `/api/furniture-definitions` | Furniture definitions (CRUD, copy, validate) |
| `/api/models`, `/api/models/{id}/content` | Product models (upload, list, rename, delete, the `.glb` file) |
| `/api/materials`, `/api/item-kinds` | Catalogues |
| `/api/realtime/subscription` | Cookie for the Mercure live-update stream |
| `/api/admin/users` | User management (admins only) |

## License

[MIT](LICENSE)
