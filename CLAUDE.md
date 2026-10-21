# Flat Designer

A 3D designer for flats and houses. A project has 1..N floors with rooms, walls, openings and items. Custom-made
furniture is stored as parametric furniture definitions. The app includes a viewer, an editor, a walkthrough, versions,
share links, a cut list and an MCP server for AI agents. Accounts have the roles Admin or User and authenticate with JWT.
Users own projects, and admins see all of them. The architecture mirrors `../ukolio` (same author), and parts of it
(OAuth, lint configs) were ported from there. See `README.md` for the user-facing overview.

## Services

- `proxy/`: nginx. It sends `/api/*`, `/mcp`, OAuth discovery and Mercure to the backend and everything else to the
  frontend (port `PROXY_PORT`, default 4400). Locations are in `conf/locations.conf`. They are included by
  `http.conf.template`, or by `ssl.conf.template` when `PROXY_SSL_ENABLED=1` (set by `docker-compose.ssl.yml`, which is
  enabled via `COMPOSE_FILE` in `.env`; it serves `PROXY_PORT_SSL` and makes http redirect).
  `docker-entrypoint.d/10-select-template.sh` picks the template at start. The backend reads the scheme from
  `X-Forwarded-Proto`.
- `minio/` (a compose service only): S3-compatible storage for uploaded files.
- `backend/`: FrankenPHP worker, PHP 8.5, `marekskopal/orm`, `marekskopal/router`, MariaDB. Namespace `FlatDesigner\`.
  - Routes are in `FlatDesigner\Route\Routes`. The entrypoint runs `migration:run` before serving.
  - Every route needs a Bearer access token, except `AuthorizationMiddleware::OpenRoutes` and the guest share read (`ShareRoutePattern`).
  - Controllers get the user from `RequestService::getUser()`. For missing permissions they answer 403, not 401
    (a 401 makes the frontend refresh the token or sign out).
  - Request DTOs implement `ArrayFactoryInterface` and read fields via `BodyReader`.
  - `bin/console admin:create` (`make admin`) creates an admin, and `files:import` (`make import-files`) uploads a
    directory as project files.
- `frontend/`: Angular 22 (standalone, signals, zoneless), component prefix `fd`, SCSS tokens in
  `src/styles/_variables.scss`.
  - three.js code lives in `src/app/viewer/engine/` and must stay framework-free (no Angular imports).
  - Routes are in `app.routes.ts`: lazy pages under `LayoutComponent`, guarded by `authGuard` / `guestGuard` /
    `adminGuard`.
  - `jwtInterceptor` adds the token and refreshes it on 401. `errorInterceptor` shows a toast for API errors; skip it
    with the `SkipErrorToast` context token.

## Domain conventions

- All stored lengths are integer **millimetres** in plan coordinates. The origin is the inner south-west corner of the
  floor, x points east and y points north. The viewer converts with `MM` (0.001) and maps plan y to scene −z.
- Item rotation is in degrees counter-clockwise; 0 means the front faces south (−y). Cabinet-local frame: x along the
  width, y up, +z out of the front.
- Walls are centre lines with a thickness. Openings are offsets along the centre line from the wall start. `hinge` is
  Start or End of the opening; `side` is Left or Right of the wall direction.
- Opening types (`OpeningTypeEnum`): `Door`, `DoubleDoor`, `SlidingDoor`, `PocketDoor`, `Passage` (no leaf),
  `Window` and `Niche`. A swing door hangs on `hinge` and swings into `side`. For a double door, `leafWidth` is the leaf on
  `hinge`. Sliding and pocket doors slide open towards `hinge`; a sliding door hangs on the `side` face.
  `OpeningPlacement` keeps the wall beside them free for the open leaf (both the handler and `WallHandler` check it)
  and makes pocket doors need a wall at least 100 mm thick. Doors also have a `frameStyle` and a `handle` (null means
  the type's default). Doors with a leaf have a `leafStyle` (Flush, Panelled, Glazed) with `leafPanels` panels or
  panes (`leafPanels()` in `viewer/engine/opening-looks.ts`). These rules, and the window ones below, are in
  `OpeningLook`.
- A niche is a recess `depth` deep (default half the wall) into its `side` face, leaving at least 30 mm of wall
  behind it (`OpeningPlacement::nicheDepth()`, also checked when the wall changes). It is neither a door nor a gap
  for the walkthrough. `wallPieces()` gives the piece behind it a `recess`; `buildWall()` draws it thinner and tags it
  with the niche's `openingId`. The wall face twins (`WallFaceSpans` / `wallGapsAlongEdge()`) count a niche as an
  opening of its own face only, flagged `niche` (dimension chains skip it). Niches from both faces may share a
  stretch of wall while 30 mm stays between them (`OpeningPlacement::nichesFit()`, also checked by `WallHandler`). A wall offset is a full-height niche, a `Column` against the face, or collinear walls
  of different thickness (room detection steps the outline; fixture cases `stepped` / `flushOuterFace`).
- Windows have `mullions` (vertical bars; 1, two panes, is the default) and `transoms`, plus an optional `sillBoard`
  (the inner board's reach past the wall face on `side`, the interior; exterior walls also get an outer sill). A
  walkable window has one sash on `hinge` (mullions 0) or two. Corner windows have no stored field:
  `cornerWindows()` finds two windows that reach the shared end of an L-joint at overlapping heights, and
  `buildWall()` drops their corner jambs. The wall pieces already leave such a corner open.
- A window may have a solid lower `panelHeight` (mm from the sill) and exterior `shutters` (Louvred or Solid, in
  `shutterMaterialId`, null for painted timber). They are two leaves on the face opposite `side`. They lie folded back
  against the wall beside the window unless `shuttersClosed`. Shutters do not take part in the walkthrough.
- Hierarchy: `Project` → `Floor` → rooms / walls / items. Access goes through `ProjectProvider::findAccessible()`, which
  allows the owner or an admin and answers 404 otherwise.
- Foreign keys cascade on delete (project → floors → content), so deletes go through the parent row.
- Versions: the floors are the working copy. `ProjectVersion::$snapshot` is JSON from `ProjectSnapshotService`, which
  documents the format. **A new floor, room, wall or item field must be added to the snapshot restore too.**

## Editing

- Every change of a floor element goes through `Service\Editor\SceneEditor`, from REST controllers and MCP tools alike.
  It bumps `projects.revision` (optimistic lock via `If-Match`), validates through the type's `ElementHandler` and logs
  a `ProjectChange` whose before/after values are the element's scene JSON.
- **A new element field must be added to its handler's `fields()` / `normalize()` / `apply()` and to the snapshot
  restore.**
- `UndoService` reverts only the current actor's own changes (`ActorContext`: a person or an MCP client), and only
  while the element is unchanged since. It writes back the handler's `restorableFields()`: the fields plus derived
  state. For example, a wall's `openingOffsets` is included because moving the wall's start shifts the offsets, and
  doors and windows have to stay in place on the plan.
- Items, walls, openings and rooms are soft-deleted (`deletedAt`). The repositories' `findByFloor` / `findByWalls`
  skip deleted rows.
- Item groups (`ItemGroup`, `items.group_id`): one level deep, and a group may be empty. Membership is the item field
  `groupId`. `GroupEditor` (group, move, copy, ungroup, delete) changes several elements under one revision as a
  `ChangeBatch` built with `BatchWriter` (`project_changes.batch`; the first change carries the operation's summary).
  `UndoService` reverts a batch as a whole (undo runs backwards) and closes all of it on a conflict.
  `EditResultDto::$batch` lists every change, and `scene-patch.ts` applies them in order. In the viewer, clicking a
  grouped item selects the group (Alt or double-click selects the item), and Shift+click picks items to group.
- Variants (`VariantSet`, `item_groups.variant_set_id`): the alternatives of a piece of furniture are groups of one
  set, and only `activeGroupId` is shown (this applies to items only; shared items stay outside the set).
  `VariantEditor` creates a set, adds a variant (a copy in place or an empty one), switches variants (a logged change
  that is also allowed outside edit mode) and decides a set (deletion keeps the active variant). The active variant
  cannot leave its set, and deleting it activates another. The client hides the other variants' items with
  `hiddenItemIds()` in `viewer/engine/variants.ts`; its PHP twin `SceneDto::hiddenItemIds()` does the same for MCP. The
  sidebar's `VariantsComponent` switches variants.
- Editor UI (`app/editor`):
  - `EditorStore` is provided per viewer page. It holds the selection and edit mode and makes the API calls, sending
    the scene revision as `If-Match`. `scene-patch.ts` patches the returned element into the scene and falls back to a
    reload.
  - `EditorPanelComponent` has the toolbar, the Add menu and the shortcuts.
  - `InspectorComponent` is driven by `ElementFields`; add a field there to make it editable. `when` limits a field
    to certain elements, e.g. the `cabinet` / `wardrobe` forms.
- Engine picking: builders tag objects with `itemId` / `openingId` / `wallId` / `roomId` in `userData`
  (`selection.ts`). In edit mode with the camera looking down, items, walls and openings can be dragged, and the
  selected wall's ends or room's corners get handles (in an overlay scene drawn above the section cut). One drag
  results in one `onEdit` call and one change.
- Drawing tools (`EditorStore.tool`, `viewer/engine/drawing.ts`, `DrawHudComponent`): in the top view, Draw walls
  clicks a run of centre-line points (snapping to wall ends, onto centre lines for T-joints, axes and 15° steps; a
  typed `3200` or `3200<90` places an exact segment). `WallDrawer` (`POST …/walls/polyline`, MCP `add_walls`) adds
  the run as one `ChangeBatch`. Room from walls (`RoomOutliner`, `POST …/rooms/detect`, MCP `detect_room` /
  `find_room_outline`) outlines the inner wall faces around a point. The detection exists twice, `RoomDetector`
  (`Service/Editor/Geometry`) and `room-detect.ts`, kept identical by `backend/tests/fixtures/room-detection.json`.
  **Always change both twins and add a fixture case in the same change.**
- `ViewerEngine.load()` of the same floor diffs the scene and rebuilds only the changed items, walls and rooms, plus
  the mitres of neighbouring walls (`wallJoints()`).

## Furniture definitions

The design notes are in `docs/furniture-definitions-plan.md` and `docs/furniture-definitions-v2-plan.md`. The current
format is the text in `FurnitureTools::Format`.

- A definition describes custom furniture as data: parameters and parts whose sizes are expressions.
- The expression language and the definition evaluator exist twice: in PHP (`Service/Furniture`) and in TS
  (`viewer/engine/custom`). The shared fixtures `backend/tests/fixtures/furniture-{expressions,definitions}.json` keep
  them identical. **Always change both twins and add a fixture case in the same change.**
- `FurnitureDefinition` rows (`/api/furniture-definitions`, soft-deleted) are global (`user` / `project` null, only
  admins write them), part of a user's library, or a project's own. The access rules are in
  `FurnitureDefinitionProvider`.
- `DefinitionValidator` checks what the evaluator does not read (unknown keys, `size`, `PartTypeEnum` options, material
  codes). It stores the JSON with empty objects kept as `{}`.
- A `Custom` item has `items.definition_id` (a live link) and `params` of the form `{values, materials}`.
  `CustomItemValidator` evaluates the item at its size only when its kind, definition, size or params change, so a
  later definition edit does not block moving the item.
- An item may use global definitions, the project owner's library and the project's own definitions
  (`FurnitureDefinitionProvider::isUsableIn`). Deleted definitions are still accepted.
- The scene carries `definitions` (the ones its items use), so versions embed them. A restore links the live
  definition with that id, or recreates it as a project definition (`SnapshotDefinitionRestorer`).
- Rendering: `viewer/engine/custom/custom.ts` builds one mesh per part (`userData.partName`). When a definition does not
  evaluate, the item is a red placeholder with `userData.definitionError`. An item is rebuilt when its definition's
  `updatedAt` changes. A material slot without a default takes the item material, as the first slot does.
- Seeding: global definitions come from `backend/migrations/furniture/*.json`. The seed migrations list their files,
  and `FurnitureSeeds` reads them. Both test suites build them, and `custom/parity.spec.ts` checks the sample project's
  definitions (kitchen cabinets, worktop, backsplash, wardrobe, chair, table, panel) against the built-in kinds.
- Per-copy variety: `hash(n, seed?)` gives a fixed pseudo-random 0 ≤ x < 1 (32-bit integer mixing, identical in both
  twins), and a part's `material` may be `{"expr": …}` choosing a slot per copy, e.g. book spines.
- Pictures: a parameter of type `image` (no default) holds the id of an image file of the item's project, and a
  `picture` part shows it on its front face through its `image` option (cropped to fill, `coverUvs()`).
  `CustomItemValidator` checks the file (`ImageParameters`). The scene lists the files in `pictures`, so share guests
  may load them (`SharePictureContent`). The viewer loads them through `PictureLibrary` (`viewer/engine/pictures.ts`,
  the pages' `pictureFetcher`) and rebuilds the items once an image has loaded.
- The global catalogue also has a sofa, a bed with bedding, a bedside table, a round table, a walk-in shower screen,
  a round mirror and a balcony railing (`SeedFurnitureCatalogueMigration`), and decor (`SeedDecorCatalogueMigration`):
  a framed picture (it can lean against the wall), rugs, books, curtains, roller and venetian blinds and a scatter
  cushion. Curtains and blinds are furniture placed at a window, not fields of the opening. Upholstery uses the `cushion` part, a
  box with rounded edges (`radius`), which the cut list counts among the other parts. Round tops and mirrors are
  `cylinder` parts.
- Built-in furniture kinds: a conversion migration turned the sample project's built-in furniture into Custom items
  in place, keeping the same ids (`BuiltInFurnitureConverter` maps params to values; `down()` maps them back). The
  built-in kinds stay for saved versions and old changes. `ItemKindCatalog` marks them `replacedBy`, and the Add menu
  lists them as "Built-in (old)".
- UI: the Add menu's "Furniture —" groups; the inspector's `definition` / `custom` fields (`CustomParamsComponent`
  generates a form from the definition's parameters and slots; `EditorStore.definitions`); `/furniture` (the library,
  `?projectId=`); and `/furniture/:id` (a JSON editor with `FurniturePreview`, a framework-free one-item scene in
  `viewer/engine/custom/preview.ts`).
- Saving a definition publishes `definitionChanged` (`ProjectUpdatePublisher::markDefinitionChanged`, with no
  revision bump) to the projects that have items of it, and the viewer reloads the scene.
- Cut list: `CutList\CutListService` (REST `…/cut-list`, MCP `get_cut_list`, page `/projects/:id/cut-list`) evaluates
  the shown Custom items. Board and front parts are listed by material, thickness, length along the grain, width and
  banded edges; other parts are listed separately, with area and banding totals per material.

## Item builders

- `viewer/engine/items.ts` dispatches to `kitchen.ts`, `sanitary.ts`, `outdoor.ts`, `furniture.ts` and `structure.ts`.
  Each one builds the item body in its local frame.
- Structural items (`ItemKindEnum::isStructural()`, `structural` in `list_item_kinds`): a `Column` (`params.shape`
  rect or round) and a `Beam` along its width, z being its soffit. They are listed under Building in the Add menu.
  `StructureSize` makes a new one in a room reach its ceiling or hang from it (a vault counts at its springing)
  unless the client gives the height or z. `ElementHandler::defaults()` gets the floor for such lookups. Like wall pieces, their meshes have an
  inside-out twin in `MaterialLibrary.sectionFill()`, so the section plane shows a solid cut. Columns block the
  walkthrough (`columnColliders()`); a beam's body is tagged `ceilingPart` and hides with the ceilings. Quantities
  do not count them yet.
- Custom-made furniture is a furniture definition and a bought product is a model (see below); neither is a new kind.
  A new item kind (e.g. a building element) needs the PHP enum, a migration extending the `items.kind` column enum,
  the TS `ItemKind` union and a builder.

## Product models

- `ModelAsset` rows (`/api/models`, soft-deleted) are uploaded glTF binaries (`.glb`) stored in S3, scoped like
  furniture definitions (global for admins, a user's library, a project's own; `ModelAssetProvider::isUsableIn`).
  `GlbReader` rejects what the viewer cannot decode (Draco, Basis) and `GltfBounds` sizes the model from the accessor
  bounds through the node transforms, normalizing quantized (`KHR_mesh_quantization`) positions.
- A `Model` item has `items.model_id` (`ItemModelLink`). It starts at the model's size and name. The viewer
  (`viewer/engine/models.ts`: `ModelLibrary` with GLTFLoader and the meshopt decoder) scales the model evenly to fit
  the item's box. While loading, or if the model is missing, a box stands in.
- The scene and snapshots carry `models` (the ones its items use). A restore keeps a model the project may still use,
  otherwise the item draws a box. Share guests load models through `ShareModelContent`, but only those of their
  scene.
- `make import-models DIR=… [PROJECT=…]` (`models:import`) uploads every `*.glb` with an optional `<name>.json`
  sidecar (`name`, `license`, `author`, `sourceUrl`). **Third-party models are never committed.** Keep them in the
  gitignored `source_files/` (the demo's CC0 Poly Haven models are in `source_files/demo/models/`, optimised with
  `npx @gltf-transform/cli optimize --compress meshopt --texture-compress webp --texture-size 1024`) and import
  them.

## Materials and tiling

- Materials are a global catalogue seeded by migrations. Admins add and change them with the MCP tools
  `add_material` / `update_material` (`MaterialService`). The material code is permanent because definitions name slot
  defaults by it. `texture` is never set this way, and these changes do not bump a revision. A change publishes
  `materialChanged` to every project, and the viewer reloads the scene. Tests keep the seeded materials and drop the
  ones with `created_by_id`.
- Textures of real products are cut from reference photos into `frontend/src/assets/textures/`, one plank or tile per
  image. The material's `tile_width` / `tile_height` give its real size.
- Patterns without photos are drawn on canvas in `materials.ts`: wood, planks, tiles, grass and gravel, plus
  `Terrazzo` (chips, with slab joints at the tile size), `Brick` (stretcher bond, `tile_width` × `tile_height` per
  brick) and `Herringbone`. A herringbone tile's length is rounded to a whole multiple of its width, and the texture
  is drawn axis-aligned and turned 45°. Tiles, terrazzo and herringbone go on the room's tile grid; bricks keep
  their surface's UVs, which on a vaulted ceiling run along and across the beams.
- Tile grid: a room's `tileOriginX/Y` (plan mm; null means the plan origin) is where the tile joints cross.
  `alignTiles()` (`viewer/engine/tiling.ts`) recomputes the UVs of every mesh with a `Tiles` material from its plan
  position. This way the floor, the wall bands (whose rows start at the floor) and the room's tiled items share one
  grid.
- Wall finishes: a room's `wallFinishes` are bands `{materialId, bottom, top}` lining all its walls. A wall face may have
  its own instead, the wall fields `leftFinishes` / `rightFinishes` (left / right of start → end; null takes the
  room's, `[]` leaves the face bare; validated by `WallFinishBands`; turning a wall round swaps them, so the bands stay
  on the same side of the plan). `faces()` / `wallFacesAlongEdge()` say which wall
  and side covers which stretch of a room edge; `buildWallFinishes()` and `RoomSurfaces` use them.
- Ceilings and skirting are room fields (rules in `RoomCeiling`). A room has a `ceilingMaterialId`. With
  `vaultSpacing`, its ceiling is vaulted: sine arches `vaultRise` high spring between beams spaced at whole
  multiples of `vaultSpacing` from the plan origin, so neighbouring rooms line up. The beams run at `vaultAngle`,
  and the crowns are at `ceilingHeight`. Beams are `beamWidth` wide, hang `beamDrop` below the springing and are in
  `beamMaterialId`. `skirtingHeight` / `skirtingMaterialId` add skirting along the walls, except at doorways.
  The geometry is in `viewer/engine/ceilings.ts`; it is TS only, as the backend does not need it.
- Where rooms meet without a wall and their ceilings differ in height, `ceilingSteps()` closes the gap with an
  upright face from the lower ceiling to the higher one. An example is an open-plan corridor with a lower ceiling
  beside a vaulted living room. The faces are rebuilt whenever rooms or walls change.

## Lighting

- Light sources are furniture definition parts. A `light` part is a point light, or a spot shining down, with
  `lumens`, `kelvin`, optional `shadow` and a `bulb` that glows (a bare bulb, a downlight's lens, or none inside a
  shade). A `cable` part is a pendant's cable: up to the hook, then along `path` to the ceiling rose. It is fixed to
  the real ceiling of the item's room (`ceilingHeightAt()` in `ceilings.ts`, vaults and beams included) and hangs
  between the fixings, sagging by `sag`.
- `viewer/engine/lights.ts` builds the three.js lights (`createFixtureLight()`) and switches them
  (`applyFixtureLights()`). Lights that are off keep intensity 0, so toggling them does not recompile shaders. At most
  `MaxFixtureShadows` lights cast shadows. `LumenScale` maps lumens to the scene's units, where the sun is 2.4, and
  `WhiteBalance` pulls the kelvin colours towards white, as a camera would. LED strips and `led` parts are fixtures
  too.
- `ViewerEngine.setLighting(night, lightsOn)`: at night the sun is gone (along with its shadow pass), the sky is faint
  and the background dark. The pages' Display has Night and Lights on; lights follow Night until switched by hand.
  `ShareSettingsDto.night` starts a share link at night.
- Seeded definitions (`SeedLightingCatalogueMigration`): a bare-bulb pendant with a cable path, a recessed downlight,
  a light source to group with the lamps of the model library, a Schuko socket, a rocker switch, a data socket and a
  plumbing point. Wall accessories stand on the wall face, their elevation being the bottom edge. Their definitions
  carry a `category` (`DefinitionCategoryEnum`: light, socket, switch, data, plumbing; checked by `DefinitionValidator`,
  not read by the evaluators) for the electrical schedule. Lights count only by this tag, not by their `light` parts.

## Render quality

- `ViewerEngine.setQuality('fast' | 'presentation')`: fast (the default) renders straight to the canvas. Presentation
  (`viewer/engine/presentation.ts`, `PresentationPipeline`) renders through an `EffectComposer`: the scene, GTAO
  ambient occlusion, the output pass (tone mapping and colour space, so the renderer's are not applied twice) and SMAA.
  It renders at a pixel ratio of at least 2 and gives the sun a larger, softer shadow map (8192, capped by the GPU).
  The handle and measure overlay still draws after it, on the canvas.
- The pages' Display has "Presentation quality"; `ShareSettingsDto.presentation` opens a share link with it. The
  screenshot capture (FD-44) is meant to use it.

## Saved views and screenshots

- `SavedView` (`saved_views`, `SavedViewProvider`, REST `…/views`, MCP `list_views` / `save_view` (replaces a view of
  the same name) / `delete_view`): a named view of one floor. `settings` (`SavedViewSettingsDto`) holds the view mode
  (`overview` or `top`; an eye-level shot is a 3D camera at eye height, and saving during a walk keeps it as one), the
  camera, the display options (night, lights, quality) and the variant per set. Views are not elements: no revision,
  no undo. They go with their floor; snapshots carry them (`views`, `SnapshotViewRestorer`), and a restore remaps
  their floor, sets and groups.
- The viewer page's Saved views section applies, saves, replaces and deletes views. A view's variants are shown
  locally (`variantOverride`, until the scene's next revision); the project's active variants do not change.
  `?view=<id>` applies a view once the engine has built its floor. Once no model or picture is loading, the page
  sets `data-view-ready`.
- Capture PNG: `ViewerEngine.capture()` renders 1600 × 1000 at 2× in the presentation quality, off the visible size
  and without the panel inset, and downloads it or saves it as a project file. Room labels are HTML, so they are not
  in it.
- `make screenshots PROJECT=… EMAIL=… PASSWORD=… [VIEWS=… ROOM=…]` (`frontend/scripts/screenshots.mjs`, Playwright)
  writes the saved views of the project whose name starts with `VIEWS`, the editor with `ROOM`'s inspector open, the
  project page, cut list, furniture library, furniture editor and a share link to `docs/screenshots/`, with the app's
  UI and the plan underlay hidden. With `cwebp` on the PATH it saves WebP 1600 px wide. The README tour uses the demo
  flat's "Tour · …" views; keep the unlicensed poster and plan out of them.

## Walkthrough

- View → Walk is implemented in `viewer/engine/walkthrough.ts`: first person at an eye height of 1630 mm with pointer
  lock (WASD/arrows, Shift runs). Esc releases the lock and returns to the 3D overview.
- Without a fine pointer (`walkthroughTouchMode()`), a finger drag turns the view and `WalkPadComponent` replaces the
  keyboard: corner arrows act as held W/A/S/D keys, plus a "Back to 3D" button.
- Collisions are computed in plan mm against:
  - `wallColliders()`: wall pieces at floor level. Doorways (passages included) and `walkable` windows are gaps;
    other windows are solid.
  - `leafColliders()`: each door leaf or French window sash as a thin wall at its current angle, or its current
    position along the wall for a sliding or pocket leaf (`Leaf.motion`).
  - Items do not block.
- Doors open and close for the session only. `openingLeaves()` is the leaf geometry shared by `building.ts` (pivots
  tagged `userData.leaf`, posed by `setLeafPose()`) and the colliders, and `LeafStates` (`doors.ts`) animates them. E, a click at the view centre
  (`DoorReach`) or a tap toggles the door in view.
- Outside the walk, doors show open and French windows closed. A walk starts with everything closed except the
  entrance.
- `openings.walkable` (allowed only on a window with sill 0; the sashes swing towards `side`) makes a French window a
  way out.
- The walk starts one step inside the door flagged `openings.entrance` (`entranceWalkStart()`). Fallbacks: a door in an
  exterior wall, then the largest indoor room.

## Measurements

- Room schedule: `Service/Schedule/RoomScheduleService` (REST `…/room-schedule`, MCP `get_room_schedule`, the project
  page's `RoomScheduleComponent`). It lists each room's net area and perimeter (`RoomDto`, computed with `PlanMath` from
  the outline on the inner wall faces), its clear height (`ceilingHeight`) and its volume. Terraces and gardens
  (`RoomTypeEnum::isOutdoor()`) are totalled separately. Only PHP computes it, so there is no TS twin.
- Electrical schedule: `Service/Schedule/ElectricalScheduleService` (REST `…/electrical-schedule`, MCP
  `get_electrical_schedule`, the project page's `ElectricalScheduleComponent`). It counts the shown Custom items whose
  definition has a `category`, in the item's `roomId` or else the room holding its footprint centre (points outside
  every room go under a room with `id` null). Rows group one definition with the same int/bool/select values at the
  same `z`; a socket's `gangs` parameter counts its outlets. PHP only.
- Quantities and cost: `Service/Quantities/QuantitiesService` (REST `…/quantities`, MCP `get_quantities`, page
  `/projects/:id/quantities`, linked from the room schedule). `RoomSurfaces` counts per room and material: the floor,
  the `wallFinishes` bands and the wall paint around and above them up to the ceiling (a vault's mean height), without
  openings, along the wall faces on the room's edges; the ceiling (a vault's arc length and its beams) and skirting.
  Façades (wall faces outside every room) are not counted, and open-air rooms get no paint. Materials with a tile size
  and a pattern laid in pieces are counted in whole pieces plus `projects.waste_percent` (`Costing`).
- Prices are per project (`ProjectMaterialPrice`, `MaterialPriceService`, REST `…/material-prices` replaces the list, MCP
  `set_material_prices` merges): per m² and per running metre (skirting, edge banding), in `projects.currency`. A price
  without a material prices a surface's default look (`QuantitySurfaceEnum::defaultMaterialName()`, e.g. a ceiling
  without a material is white plaster). They are not floor content: no revision, no undo, not in snapshots; saving
  sends `pricesChanged`.
- The wall faces along a room edge exist twice: `WallFaceSpans` (PHP) and `wallSpansAlongEdge()` /
  `wallFacesAlongEdge()` / `wallGapsAlongEdge()` / `skirtingPieces()` (TS), kept identical by
  `backend/tests/fixtures/wall-spans.json`. **Always change both twins and add a fixture case in the same change.**
- Dimension lines (`viewer/engine/dimensions.ts`, the Display toggle and `ShareSettings.dimensions`): every room edge
  gets a line offset into the room. Where `wallGapsAlongEdge()` finds openings, the edge also gets a chain of piers and
  openings. They show only while `isTopDown()`, and labels that are longer than their line on screen are hidden.
- Measure tool (`viewer/engine/measure.ts`, `MeasureHudComponent`, the View section's Measure button, key D): two clicks
  in the top view. It is independent of edit mode, takes clicks before the editor tools, and the pages turn it off
  when an edit tool is picked. It snaps to wall ends and to face corners and faces (`wallFaces()` with the
  `wallJoints()` mitres). Esc first clears the measurement, then ends the tool.

## Plan underlays

- A floor may have one `FloorUnderlay` (`floor_underlays`): an image project file laid under the top view for tracing.
  Pixel (px, py), with py counted down from the top, lies at plan `x + scale·(px·cos r + py·sin r)`,
  `y + scale·(px·sin r − py·cos r)`. It goes with the floor and with its file (both cascade).
- `UnderlayService` sets it (placement given, or calibrated from two pixel/plan point pairs by `placementFromPoints()`,
  twinned in `viewer/engine/underlay.ts`) and removes it. Both bump the revision, but it is not an element: there is no
  `SceneEditor`, no change log and no undo. REST `PUT|DELETE …/floors/{id}/underlay`, MCP `set_floor_underlay` /
  `remove_floor_underlay`.
- The scene carries `underlay`, so versions keep it. `SnapshotUnderlayRestorer` restores it while the file still exists.
- Viewer: `buildUnderlay()` draws the image over the plan at its opacity (no depth test), only while `isTopDown()`.
  `UnderlayPanelComponent` (edit mode) picks the image and sets its placement. Calibrate… uses the measure tool without
  snapping (`EditorStore.calibration`, `ViewerEngine.setCalibrating()`), then `calibrateUnderlay()` applies the real
  distance and an optional axis. Images only; PDF pages have to be uploaded as images.
- MCP `list_project_files` / `get_project_file` let an agent read uploaded plans and photos (and the upload tools,
  see MCP server, add them). Images come back as image
  content, optionally cropped, scaled to `maxSize` and overlaid with a labelled pixel `grid` (`ImagePreviewService`,
  which needs PHP gd).

## Share links

- `ShareLink` / `ShareLinkProvider`: a guest view of one floor at `/s/{token}`, without sign-in. The public
  `GET /api/shares/{token}` is opened via `AuthorizationMiddleware::ShareRoutePattern`, and `jwtInterceptor` sends no
  token there.
- Creating a link saves a version ("Shared: <name>"). Refreshing it (`POST …/share-links/{id}/refresh`, MCP
  `refresh_share_link`, the project page's Update button) saves a new version for the same token. `floorId` is the
  floor's id inside that snapshot.
- `settings` (JSON, `ShareSettingsDto`) holds the allowed views, the start view, an optional 3D camera (plan mm, from
  `ViewerEngine.cameraPose()` / `setCameraPose()`) and the display options.
- `SharedViewerPageComponent` switches variants locally only. Expired or unknown links answer 404.

## Live updates

- `ProjectRevisionService::bump()` marks the project in `ProjectUpdatePublisher`. The worker publishes after
  `frankenphp_finish_request()`, so only committed revisions are sent, to the private Mercure topic
  `flat-designer/projects/{id}`.
- Subscribers get a cookie from `/api/realtime/subscription` and listen on `/.well-known/mercure?match=<topic>`
  (Mercure protocol 1.0 uses `match`, not `topic`).
- Project details (name, address, description) are not floor content: `ProjectProvider::update` sends `detailsChanged`
  (`markDetailsChanged()`, no revision bump), and the project and viewer pages reload the project.
- Each tab sends `X-Origin-Client-Id` so it can skip its own echoes. Tests record updates in `RecordingMercureHub`.

## MCP server

- `McpController` (`/mcp`, `mcp/sdk` Streamable HTTP, sessions in `mcp_sessions`) authenticates the OAuth access
  token (`OAuth\AuthorizationService`), then sets `McpUserContext` (including the base URL used for share links) and
  the agent `ActorContext`.
- Tools live in `src/Mcp/Tool` and are discovered by `#[McpTool]` (this needs symfony/finder). Keep them as thin
  wrappers over `SceneEditor` and the providers, with typed, documented parameters: the docblock becomes the schema,
  and JSON objects need `#[Schema(definition: …)]`.
- Domain `RuntimeException`s reach the agent as tool errors (`ToolErrorTranslatingReferenceHandler`).
- `FurnitureTools::Format` is the format text agents get; a test checks that it names every `PartTypeEnum` case.
- Uploads: `upload_project_file` / `upload_model` take small files as base64. An LLM cannot write megabytes into a
  call, so `request_file_upload` / `request_model_upload` return a one-time URL (`UploadTicket`, `UploadTicketService`)
  that the agent PUTs the file to with curl. `PUT /api/uploads/{token}` is open in `AuthorizationMiddleware` (the
  token authorizes it), stores the file through `ProjectFileProvider` / `ModelAssetProvider` as the ticket's user and
  works once for 15 minutes; a rejected file releases the ticket. `delete_project_file` / `delete_model` remove them.
- `tests/Mcp/McpServerTest` drives the server over JSON-RPC.

## Seed data and files

- Seed data lives in migrations (`Seed*`, `Fix*`, `Convert*`). The early ones predate `AddProjectsAndFloors` and still
  use `flat_id`.
- **Data changes go into a new migration.** Editing an already applied migration requires `make reset-db`, which drops
  the database volume. Do not rename existing migration classes.
- Project files (plans, renders, photos) are uploaded to S3 (MinIO in the stack) through `ProjectFileProvider`. Tests
  swap `FileStorageInterface` for `Tests\Support\InMemoryFileStorage`.
- Reference files of the sample project are not in git. Keep them locally in `source_files/` (gitignored) and import
  them with `make import-files DIR=source_files PROJECT=1`.

## Code style

- PHP: tabs, the slevomat ruleset copied from ukolio, phpstan at max level.
- TS: the eslint config from ukolio, explicit member accessibility and return types, max line length 150.

## Commands

| Command | What it does |
| --- | --- |
| `make up` | Build and start the stack (creates `.env` and secrets on first run) |
| `make admin` | Create an admin account |
| `make import-files DIR=… PROJECT=…` | Upload a directory as project files |
| `make dev` | `ng serve` on 4200 against the running stack |
| `make lint` | phpcs + phpstan, eslint |
| `make test` | phpunit in a one-off backend container against the stack's MariaDB (database `flat_designer_test`; integration tests extend `Tests\Support\IntegrationTestCase`), then vitest |
| `make reset-db` | Drop the database and file volumes and re-seed |
