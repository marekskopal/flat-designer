# Flat Designer

3D viewer of one flat (flat no. 50, H1B) with a parametric kitchen. PoC: view only, no accounts, data
seeded by migrations. Architecture mirrors `../ukolio` (same author), trimmed to what a viewer needs.

## Services

- `proxy/` — nginx, `/api/*` → backend, `/` → frontend (http only, port `PROXY_PORT`, default 4400).
- `backend/` — FrankenPHP worker + PHP 8.5 + `marekskopal/orm` + `marekskopal/router` + MariaDB.
  Namespace `FlatDesigner\`. Routes in `FlatDesigner\Route\Routes`. The entrypoint runs
  `migration:run` before serving (unlike ukolio, where migrations are manual).
- `frontend/` — Angular 22 (standalone, signals, zoneless), prefix `fd`, SCSS tokens in
  `src/styles/_variables.scss`. three.js code lives in `src/app/viewer/engine/` and must stay
  framework-free (no Angular imports) so it can be unit-tested with plain vitest.

## Conventions

- All stored lengths are integer **millimetres** in plan coordinates: origin at the inner south-west
  corner of the flat, x east, y north. The viewer converts with `MM` (0.001) and maps plan y → scene −z.
- Item rotation is degrees counter-clockwise; 0 = front facing south (−y). Cabinet-local frame: x along
  width, y up, +z out of the front.
- Walls are centre lines + thickness; openings are offsets along the centre line from the wall start.
  Door `hinge` = Start/End of the opening, `side` = Left/Right of the wall direction.
- Seed data lives in migrations (`SeedFlat50`, `SeedKitchen`). Changing an applied migration needs
  `make reset-db` (drops the DB volume).
- PHP style: tabs, slevomat ruleset copied from ukolio, phpstan at max level. TS: eslint config from
  ukolio, explicit member accessibility and return types, max line length 150.

## Commands

`make up`, `make dev` (ng serve on 4200 against the running stack), `make lint`, `make test`,
`make reset-db`.
