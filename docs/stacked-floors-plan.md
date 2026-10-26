# Stacked floors (FD-36)

Floors have only a `level` today, and the viewer shows one floor at a time. This plan stacks them: floors get an
elevation and a slab thickness, the editor shows a ghost of the floor below, the 3D view can show all floors cut at
any of them, and the walkthrough can move between floors.

## Current state

- `Floor` has `name` and `level`; the scene (`GET …/floors/{id}/scene`) is one floor.
- `ViewerEngine` holds one floor's `content` / `parts` / `items` / `labels`, always at y = 0.
- The slab (`buildSlab()`) is a box of the fixed `SlabThickness` (300 mm) round the walls' bounding box.
- The section cut is one plane (`setSectionHeight()`, slider 900–2700 mm).
- The walkthrough's `floorLevels()` and colliders know the active floor only. There are no stairs.
- MCP has `add_floor` only (no `update_floor` / `delete_floor`).

## Decisions

- **Explicit elevation.** A floor stores its `elevation` (mm, finished floor level above the project datum ±0.000),
  not a storey height. It is simple for agents to set and to read.
- **Shared plan origin.** All floors of a project share one plan origin, so they stack without an offset. Existing
  projects whose floors were drawn with their own origins have to be moved (MCP agents can do it); there is no
  per-floor offset.
- **Walk between floors first by PageUp / PageDown**, stairs come as a follow-up.

## Phase 1: floor elevation and slab thickness (FD-36 sub-task)

- New columns `floors.elevation` (mm) and `floors.slab_thickness` (mm, the slab *under* this floor), with a migration
  that fills existing floors in level order: the ground floor (level 0) at 0, each floor above at the floor below's
  elevation + its tallest wall + its own slab, each floor below mirrored.
- Defaults: slab 300 for the ground floor (today's constant), 250 for the others. A new floor without an elevation gets
  the computed one.
- `Floor`, `FloorDto`, `FloorInputDto` (both optional), `FloorProvider` validation (elevation ±100 m, slab 0–1000 mm),
  the snapshot write and restore in `ProjectSnapshotService` (older snapshots take the defaults).
- MCP `update_floor` (name, level, elevation, slabThickness) and `delete_floor`; `add_floor` takes the new fields.
- The project page's floor list edits them. The plan PDF title block, the room schedule and the elevation sheets show
  the level (`+3.000`).
- The viewer's slab uses the floor's `slabThickness`.
- CLAUDE.md: the floor fields and the shared plan origin.

## Phase 2: ghost of the floor below (FD-36 sub-task)

- The viewer page also loads the scene of the next lower level (the existing endpoint; with `?version=` from the same
  version) and reloads it with the scene.
- `viewer/engine/ghost.ts` (framework-free) draws its walls (`wallOutline()`) and room outlines as faint lines in the
  overlay scene at the active floor's level (the active slab would hide the real floor), only while `isTopDown()`.
- A Display toggle, on by default in edit mode.
- The draw walls and measure tools snap to the ghost's wall ends and centre lines.

## Phase 3: "All floors" 3D view (FD-36 sub-task)

- Engine: a `FloorLayer` per floor. The active layer stays as today (y = 0, editable, diffed by `load()`); context
  layers are built by the same builders (`buildBuilding`, `buildItem`, `mergeStatic`) in a group lifted by
  `elevation(other) − elevation(active)` and tagged `userData.contextFloor`, which picking, handles and selection skip.
  Keeping the active floor at 0 leaves the camera, handles, measure, comments, plots and walkthrough code alone.
- Real slabs: each floor's slab is the union of its rooms and wall footprints at its `slabThickness`, not a bounding box
  (an upper slab must not roof terraces or overhangs).
- Display: "This floor" / "All floors". All floors shows the floors below the active one and cuts the active one at the
  section slider; the floors above are hidden. Cutting at floor N means making N active: switching floors keeps the
  camera while the stack is shown (no `setView('overview')`).
- Floors below keep their ceilings; only the active floor follows the ceilings toggle.
- `fitGround()` / `fitSunShadow()` / `lowestGround()` take the bounds and plots of all layers (plots usually belong to
  the ground floor).
- Context floors' fixture lights stay off (FD-58 cost, `MaxFixtureShadows`).
- The client loads the scenes per floor in parallel (no new endpoint); they share the materials catalogue.
- Saved views (`SavedViewSettingsDto`) and share links (`ShareSettingsDto.floors: 'one' | 'all'`, missing: one) keep
  the mode; a share snapshot already has every floor.
- Benchmark a two-storey project with `make benchmark` (draw calls grow with the floors).

## Phase 4: walk between floors (FD-36 sub-task)

- PageUp / PageDown (and the walk pad's floor buttons) move the walker to the same plan point on the next floor up or
  down, falling back to where a walk of that floor starts (`entranceWalkStart()`) when the point lies in none of its
  rooms.
- As built: the page switches the shown floor (as the floor bar does) and the engine keeps the walk going on it, with
  the same `Walkthrough` and pointer lock (which could not be taken again without a click). The colliders,
  `floorLevels()` and door leaves are the shown floor's, so no per-layer state is needed, and the floor above can be
  walked to too. It works with or without the all floors view.

## Follow-up: stairs (FD-37)

- FD-37 scopes them: a new element type (handler, snapshot restore, picking tag, scene patch, MCP tools), straight, L,
  U and spiral, rise and going from the floor-to-floor height, a railing. The plan PDF draws the outline with a
  walking-line arrow.
- The stair's footprint cuts a void into the slab and the room floors of the floor above (holes in `THREE.Shape`).
  The room schedule and quantities do not subtract it at first; if they do later, the void gets a PHP twin with
  fixtures.
- The walkthrough walks up and down it: over a stair `floorLevels()` ramps along its run, and the layer switches where
  the walker reaches the next floor.
