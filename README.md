# Flat Designer

3D viewer of flat no. 50 (H1B), Example Residences, Example City, with the planned kitchen,
bathroom and WC, the chosen floor and tiles, and both gardens.
Proof of concept: view only, data seeded by migrations. Users sign up and sign in and own projects
(1..N floors, saved versions); admins see all projects and manage users.

## Run

```sh
make up                # http://localhost:4400 (creates .env and a token key on first run)
make admin             # create the first admin account (prompts for email and password)
```

Anyone can sign up at `/sign-up` and gets the `User` role. Admins see **Users** in the top bar, where
they change roles and delete accounts. Auth is JWT (access token 1 h, refresh token 7 d, signed with
`AUTHORIZATION_TOKEN_KEY`); a password change signs out every other session.

The backend applies pending migrations on start, so a fresh database comes up with the flat and the
kitchen already in it. After changing a seed migration run `make reset-db`.

Frontend development with hot reload (needs the stack running for the API):

```sh
make install
make dev               # http://localhost:4200, /api proxied to :4400
```

## Using the viewer

- **3D** — dollhouse view from the south-west; drag to orbit, right-drag to pan, scroll to zoom.
- **Plan** — top view with the walls cut at 1.3 m, like an architectural floor plan.
- **Kitchen** — eye-level view from the living room, close to the studio render.
- **Wall cut** slider moves the section plane; **Ceilings** and **Room labels** toggle.
- Click a room in the list (or its floor in 3D) to select and focus it.

## Layout

| Path | What |
| --- | --- |
| `proxy/` | nginx: `/api/*` → backend, everything else → frontend |
| `backend/` | FrankenPHP worker, PHP 8.5, `marekskopal/orm` + `marekskopal/router`, MariaDB |
| `backend/migrations/` | schema + seed data (`SeedFlat50`, `SeedKitchen`) |
| `frontend/` | Angular 22 (standalone, signals, zoneless) |
| `frontend/src/app/viewer/engine/` | framework-free three.js code: walls, doors/windows, floors, kitchen generator, materials |
| `docs/analysis.md` | inputs and analysis of open-source projects |
| `source_files/` | floor plan PDF, kitchen render, bathroom/WC/floor references |

API (all but health and authentication need a Bearer token):

- `/api/authentication/{login,sign-up,refresh-token}`, `/api/current-user`, `/api/current-user/password`
- `/api/projects` (list, create), `/api/projects/{id}` (get, put, delete)
- `/api/projects/{id}/floors` (create), `/api/projects/{id}/floors/{floorId}` (put, delete),
  `…/floors/{floorId}/scene` (everything the viewer needs)
- `/api/projects/{id}/versions` (list, save), `…/versions/{versionId}` (detail with its floors),
  `…/versions/{versionId}/floors/{floorId}/scene`, `…/versions/{versionId}/restore`
- `/api/admin/users`, `/api/admin/users/{id}` (admins only)

## Data model

All lengths are integer millimetres in plan coordinates: origin at the inner south-west corner of the
floor (for flat 50: the living room by the south façade), x east, y north.

- `users` — email, bcrypt password, role (Admin/User), token version and sign-in lockout state.
- `projects` — owner, name, address, description. Flat 50 is the seeded project "Byt č. 50 (H1B)".
- `floors` — the storeys of a project (name, level; 0 = ground floor); rooms, walls and items belong to a
  floor. A project keeps at least one.
- `project_versions` — numbered, immutable JSON snapshots of all floors of a project (with the material
  catalogue). The floors are the working copy; restoring a version replaces them, after auto-saving the
  current state as a new version.
- `materials` — colour, roughness, metalness and a `pattern` (Plain/Wood/Planks/Tiles/Glass). Planks and
  tiles can reference a photo of one real plank/tile (`texture`, under `frontend/src/assets/`) with its
  size (`tile_width` × `tile_height`); the viewer lays the floor or wall out from it. Otherwise the
  pattern is drawn procedurally.
- `rooms` — code, name, type, floor polygon (JSON), ceiling height, floor material and optional
  `wall_finishes` (JSON bands `{materialId, bottom, top}`, e.g. tiling up to 1200 mm), applied around the
  room's walls with door openings left free.
- `walls` — centre line start/end, thickness, height, type (Exterior/Interior/Shaft).
- `openings` — doors and windows on a wall: offset along the wall, width, height, sill, leaf width,
  hinge end and swing side.
- `items` — parametric objects (kitchen cabinets, worktops, sink, hob, LED strip; bathtub, washbasins,
  wall-hung toilet, washing machine, mirror, towel radiator, flush plate, chrome fixtures, tiled boxes
  such as the WC pre-wall; garden fences): footprint centre, elevation,
  size, rotation (0 = front facing south) and kind-specific `params` JSON (fronts from the bottom up,
  handles, plinth, …).

The viewer builds all geometry from these parameters; there are no 3D model files.

## Where the numbers come from

- **Walls, openings, rooms**: read from the vector paths of the AutoCAD PDF
  (`source_files/floor-plan.pdf`, 1:100), about 2 mm resolution, and checked against the
  dimension labels. All room areas match the PDF legend, including the L-shaped H1/B_06 Pokoj (16.5 m²,
  with its 1050 mm niche at the north façade).
- **Terrace** H1/B_10: the 6000 × 2000 paving (400 × 400 slabs) drawn on the plan, 12.0 m².
- **North garden** H1/B_12: 7200 mm between the boundary walls and 58.28 m² as labelled on the plan;
  the sheet ends before the garden does, so its depth (9.9 m from the façade) is derived from the
  area. Panel fence with base boards (SO14) on three sides, gravel eaves strip (SO07) by the façade.
- **South garden**: not dimensioned on the sheet; modelled 3.0 m deep between the boundary axes with
  the 430 mm gravel strip along the façade. **An assumption** — change `SouthEnd` in
  `SeedGardens` (or add a migration) once the real size is known.
- **Kitchen**: the render has no dimensions, so the modules use standard sizes fitted to the real niche
  (see `SeedKitchen`). Replace them once the kitchen studio sends a dimensioned plan.
- **Bathroom and WC**: layout from the top-view renders in `source_files/bathroom` and `source_files/wc`,
  heights from the perspectives, standard product sizes (see `SeedBathroomAndWc`).
- **Textures** in `frontend/src/assets/textures/` are cut from the source files: the laminate from the
  photo of the "Dub Erica" plank (straightened), the wall (600 × 300) and floor (600 × 600) tiles from
  the bathroom/WC renders. The hallway has the same laminate as the rooms.
  The plank size (265 × 1220 mm) is estimated from the sample's aspect ratio; check the datasheet.
  Planks run north–south, towards the windows.

## Checks

```sh
make lint   # phpcs + phpstan (max level), eslint
make test   # phpunit (in a backend container, database flat_designer_test), vitest
```
