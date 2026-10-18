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
- Editing: every change of a floor element goes through `Service\Editor\SceneEditor` (REST controllers
  and MCP tools alike), which bumps `projects.revision` (optimistic lock via `If-Match`), validates via
  the type's `ElementHandler` and logs a `ProjectChange` (before/after = the element's scene JSON).
  `UndoService` reverts the current actor's (`ActorContext`: person or MCP client) own changes only
  while the element is unchanged since; it writes back the handler's `restorableFields()` (fields plus
  derived state: a wall's `openingOffsets`, which moving its start shifts so doors/windows stay put on the
  plan). Items, walls, openings and rooms are soft-deleted (`deletedAt`);
  repositories' `findByFloor`/`findByWalls` skip deleted rows. A new element field must be added to its
  handler's `fields()`/`normalize()`/`apply()` and to the snapshot restore.
- Item groups (`ItemGroup`, `items.group_id`, one level, may be empty): membership is the item field
  `groupId`; `GroupEditor` (group, move, copy, ungroup, delete) changes several elements under one revision as
  a `ChangeBatch` built with `BatchWriter` (`project_changes.batch`; the first change carries the operation's
  summary). `UndoService` reverts a batch as a whole (backwards for undo) and closes all of it on a conflict;
  `EditResultDto::$batch` lists every change, which `scene-patch.ts` applies in order. In the viewer, clicking
  a grouped item selects the group (Alt/double-click: the item), Shift+click picks items to group.
- Variants (`VariantSet`, `item_groups.variant_set_id`): alternatives of a piece of furniture are groups of a
  set; only `activeGroupId` is shown (items only, shared items stay outside). `VariantEditor` makes a set,
  adds a variant (copy in place or empty), switches (a logged change, also outside edit mode) and decides it
  (delete keeps the active variant). The active variant cannot leave its set; deleting it activates another.
  The client hides the other variants' items (`hiddenItemIds()` in `viewer/engine/variants.ts`, PHP twin
  `SceneDto::hiddenItemIds()` for MCP); the sidebar's `VariantsComponent` switches them.
- MCP: `McpController` (`/mcp`, `mcp/sdk` Streamable HTTP, sessions in `mcp_sessions`) authenticates the
  OAuth access token (`OAuth\AuthorizationService`, ported from ukolio), sets `McpUserContext` and the
  agent `ActorContext`. Tools live in `src/Mcp/Tool` (discovered by `#[McpTool]`, needs symfony/finder);
  keep them thin wrappers over `SceneEditor`/providers with typed, documented parameters (the docblock
  becomes the schema; JSON objects need `#[Schema(definition: …)]`). Domain `RuntimeException`s reach the
  agent as tool errors (`ToolErrorTranslatingReferenceHandler`). `tests/Mcp/McpServerTest` drives the
  server over JSON-RPC.
- Live updates: `ProjectRevisionService::bump()` marks the project in `ProjectUpdatePublisher`; the worker
  publishes after `frankenphp_finish_request()` (only revisions that were committed) to the private
  Mercure topic `flat-designer/projects/{id}`. Subscribers get a cookie from `/api/realtime/subscription`
  and listen on `/.well-known/mercure?match=<topic>` (Mercure protocol 1.0 uses `match`, not `topic`).
  Each tab sends `X-Origin-Client-Id` so it can skip its own echoes. Tests record updates in
  `RecordingMercureHub`.
- Editor UI: `app/editor` — `EditorStore` (provided per viewer page: selection, edit mode, API calls with
  the scene revision as `If-Match`; the result's element is patched into the scene by `scene-patch.ts`,
  falling back to a reload), `EditorPanelComponent` (toolbar, Add menu, shortcuts), `InspectorComponent`
  driven by `ElementFields` (add a field there to make it editable; `when` limits a field by element, e.g.
  the `cabinet` / `wardrobe` forms for cabinet and wardrobe params). Engine picking: builders tag objects with
  `itemId`/`openingId`/`wallId`/`roomId` in `userData` (`selection.ts`). In edit mode with the camera
  looking down, items, walls and openings drag, and the selected wall's ends / room's corners get handles
  (overlay scene, drawn above the section cut); one drag = one `onEdit` = one change. `ViewerEngine.load()`
  of the same floor diffs the scene and rebuilds only changed items/walls/rooms (and neighbouring wall
  mitres, `wallJoints()`).
- Walkthrough (View → Walk): `viewer/engine/walkthrough.ts`, first person at 1630 mm eye height with pointer
  lock (WASD/arrows, Shift runs, Esc — i.e. losing the lock — returns to the 3D overview). It collides in plan mm
  against `wallColliders()` (wall pieces at floor level; doors are gaps, windows solid), items do not block.
  It starts a step inside the door flagged `openings.entrance` (`entranceWalkStart()`; fallback: a door in an
  exterior wall, then the largest indoor room).
- Seed data lives in migrations (`SeedFlat50`, `SeedKitchen`, `FixRoomNiche`, `SeedFinishes`,
  `SeedBathroomAndWc`, `FixHallwayDoorAndTiles`, `SeedGardens`; they predate `AddProjectsAndFloors` and still
  say `flat_id`). Data changes go into a new migration; editing an applied one needs
  `make reset-db` (drops the DB volume).
- Item builders: `viewer/engine/items.ts` dispatches to `kitchen.ts`, `sanitary.ts`, `outdoor.ts` and `furniture.ts`; each builds the
  item body in its local frame. A new item kind needs the PHP enum, a migration extending the
  `items.kind` column enum, the TS `ItemKind` union and a builder.
- Project files (plans, renders, photos) are uploaded to S3 (MinIO in the stack) through
  `ProjectFileProvider`; tests swap `FileStorageInterface` for `Tests\Support\InMemoryFileStorage`.
  The flat 50 references are no longer in git: keep a local `source_files/` and run
  `make import-files DIR=source_files PROJECT=1`.
- Textures for real products are cut from the reference photos into `frontend/src/assets/textures/` (one
  plank or tile per image); the material's `tile_width`/`tile_height` give its real size.
- Tile grid: a room's `tileOriginX/Y` (plan mm, null = plan origin) is where tile joints cross. `alignTiles()`
  (`viewer/engine/tiling.ts`) recomputes the UVs of every `Tiles` material mesh from its plan position, so the
  floor, the wall bands (rows start at the floor) and tiled items of that room (bath panel, pre-wall box) share
  the grid, as in the bathroom/WC renders.
- PHP style: tabs, slevomat ruleset copied from ukolio, phpstan at max level. TS: eslint config from
  ukolio, explicit member accessibility and return types, max line length 150.

## Commands

`make up`, `make admin`, `make import-files DIR=… PROJECT=…`, `make dev` (ng serve on 4200 against the running stack), `make lint`,
`make test` (phpunit runs in a one-off backend container against the stack's MariaDB, database
`flat_designer_test`; integration tests extend `Tests\Support\IntegrationTestCase`), `make reset-db`.
