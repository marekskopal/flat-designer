# Flat Designer

3D viewer of projects (1..N floors each) with a parametric kitchen; the seeded project is flat no. 50
(H1B). PoC: view only, data seeded by migrations. Accounts (roles Admin/User) with JWT auth; users own
projects, admins see all. Architecture mirrors `../ukolio` (same author), trimmed to what a viewer needs.

## Services

- `proxy/` — nginx, `/api/*` → backend, `/` → frontend (http only, port `PROXY_PORT`, default 4400).
- `minio/` (compose service only) — S3-compatible storage for uploaded files.
- `backend/` — FrankenPHP worker + PHP 8.5 + `marekskopal/orm` + `marekskopal/router` + MariaDB.
  Namespace `FlatDesigner\`. Routes in `FlatDesigner\Route\Routes`. The entrypoint runs
  `migration:run` before serving (unlike ukolio, where migrations are manual). Every route needs a
  Bearer access token except `AuthorizationMiddleware::OpenRoutes`; controllers get the user from
  `RequestService::getUser()` and answer 403 (not 401, which makes the frontend refresh/sign out) for
  missing permissions. Request DTOs implement `ArrayFactoryInterface` and read fields via `BodyReader`.
  `bin/console admin:create` (`make admin`) creates an admin.
- `frontend/` — Angular 22 (standalone, signals, zoneless), prefix `fd`, SCSS tokens in
  `src/styles/_variables.scss`. three.js code lives in `src/app/viewer/engine/` and must stay
  framework-free (no Angular imports). Routes in `app.routes.ts` (lazy pages under `LayoutComponent`,
  `authGuard`/`guestGuard`/`adminGuard`); `jwtInterceptor` adds the token and refreshes on 401,
  `errorInterceptor` toasts API errors (skip with the `SkipErrorToast` context token).

## Conventions

- All stored lengths are integer **millimetres** in plan coordinates: origin at the inner south-west
  corner of the floor, x east, y north. The viewer converts with `MM` (0.001) and maps plan y → scene −z.
- Item rotation is degrees counter-clockwise; 0 = front facing south (−y). Cabinet-local frame: x along
  width, y up, +z out of the front.
- Walls are centre lines + thickness; openings are offsets along the centre line from the wall start.
  Door `hinge` = Start/End of the opening, `side` = Left/Right of the wall direction.
- `Project` → `Floor` (was `Flat`) → rooms/walls/items. Access goes through
  `ProjectProvider::findAccessible()` (owner or admin, otherwise 404). Versions: the floors are the
  working copy, `ProjectVersion::$snapshot` is JSON from `ProjectSnapshotService` (format documented
  there); a new floor/room/wall/item field must be added to the snapshot restore too. Foreign keys cascade
  on delete (project → floors → content), so deletes go through the parent row.
- Seed data lives in migrations (`SeedFlat50`, `SeedKitchen`, `FixRoomNiche`, `SeedFinishes`,
  `SeedBathroomAndWc`, `FixHallwayDoorAndTiles`, `SeedGardens`; they predate `AddProjectsAndFloors` and still
  say `flat_id`). Data changes go into a new migration; editing an applied one needs
  `make reset-db` (drops the DB volume).
- Item builders: `viewer/engine/items.ts` dispatches to `kitchen.ts`, `sanitary.ts` and `outdoor.ts`; each builds the
  item body in its local frame. A new item kind needs the PHP enum, a migration extending the
  `items.kind` column enum, the TS `ItemKind` union and a builder.
- Project files (plans, renders, photos) are uploaded to S3 (MinIO in the stack) through
  `ProjectFileProvider`; tests swap `FileStorageInterface` for `Tests\Support\InMemoryFileStorage`.
  The flat 50 references are no longer in git: keep a local `source_files/` and run
  `make import-files DIR=source_files PROJECT=1`.
- Textures for real products are cut from the reference photos into `frontend/src/assets/textures/` (one
  plank or tile per image); the material's `tile_width`/`tile_height` give its real size.
- PHP style: tabs, slevomat ruleset copied from ukolio, phpstan at max level. TS: eslint config from
  ukolio, explicit member accessibility and return types, max line length 150.

## Commands

`make up`, `make admin`, `make import-files DIR=… PROJECT=…`, `make dev` (ng serve on 4200 against the running stack), `make lint`,
`make test` (phpunit runs in a one-off backend container against the stack's MariaDB, database
`flat_designer_test`; integration tests extend `Tests\Support\IntegrationTestCase`), `make reset-db`.
