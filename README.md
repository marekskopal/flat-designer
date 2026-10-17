# Flat Designer

3D viewer of flat no. 50 (H1B), Example Residences, Example City, with the planned kitchen,
bathroom and WC, and the chosen floor and tiles.
Proof of concept: view only, data seeded by migrations, no accounts.

## Run

```sh
cp .env.example .env   # once
make up                # http://localhost:4400
```

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

API: `GET /api/health`, `GET /api/flats`, `GET /api/flats/{id}/scene` (everything the viewer needs).

## Data model

All lengths are integer millimetres in plan coordinates: origin at the inner south-west corner of the
flat (living room by the south façade), x east, y north.

- `flats` — the flat.
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
  such as the WC pre-wall): footprint centre, elevation,
  size, rotation (0 = front facing south) and kind-specific `params` JSON (fronts from the bottom up,
  handles, plinth, …).

The viewer builds all geometry from these parameters; there are no 3D model files.

## Where the numbers come from

- **Walls, openings, rooms**: read from the vector paths of the AutoCAD PDF
  (`source_files/floor-plan.pdf`, 1:100), about 2 mm resolution, and checked against the
  dimension labels. All room areas match the PDF legend, including the L-shaped H1/B_06 Pokoj (16.5 m²,
  with its 1050 mm niche at the north façade).
- **Terrace** outline is approximated (12.0 m², 6000 mm paving width).
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
make test   # phpunit, vitest (geometry helpers)
```
