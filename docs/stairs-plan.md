# Stairs (FD-37)

Parametric stairs between stacked floors (FD-36): straight, L, U and spiral, with the rise from the floor-to-floor
height and a railing. A stair cuts a void through the slab above, and the walkthrough follows its steps between floors.

## What FD-36 gives

- Floor elevations: the rise between two floors is known.
- Slabs built from the room and wall outlines (`buildSlab()`), and room floors as `THREE.Shape`s: a void is a hole in
  them.
- The all floors view draws the floors under the shown one (`stack.ts`).
- Walking between floors (`ViewerEngine.walkToFloor()`, `Walkthrough.moveTo()`) keeps the walk and pointer lock.

## Decisions

- **A new element type `Stair`, owned by the floor it starts on.** Not an item kind: it spans two floors, cuts the
  slab above and needs the floor-to-floor height. It follows Plot's path: entity, migration, `ElementTypeEnum::Stair`,
  `StairHandler` through `SceneEditor` (undo, change log), REST `…/stairs`, snapshot and restore, MCP tools.
- **The rise comes from the floors.** It is the next floor up's elevation minus the stair's floor's; the scene carries
  it as `stairRise`. Without a floor above, the tallest wall plus the slab a floor stacked on it would get. `risers`
  null means as many as keep each riser at most 180 mm. Changing a floor's elevation changes the stair (and never
  blocks editing or undoing it: a riser count is checked only when set).
- **The void is the stair's whole footprint**, in the slab and room floors of the floor above and in the ceiling of the
  room it stands in below. A smaller headroom-based opening can come later.
- **Landings first, winders later:** L with a quarter landing, U with a half landing.
- **Geometry is TS only** (`viewer/engine/stairs.ts`), as the ceilings are; the backend checks ranges. The room
  schedule and quantities do not subtract the void at first (that would need a PHP twin of the footprint with a
  fixture).

## Fields

| Field | Meaning |
| --- | --- |
| `shape` | `Straight`, `LShaped`, `UShaped`, `Spiral` |
| `x`, `y`, `rotation` | The middle of the first step's front edge, and the walking-up direction (degrees counter-clockwise) |
| `width` | Flight width (default 900); a spiral's diameter (default 1600) |
| `going` | Tread depth (default 270) |
| `risers` | Null: automatic |
| `firstFlight` | L and U: risers before the landing (null: half) |
| `turn` | `Left`, `Right` (L, U, and a spiral's direction) |
| `gap` | U: between the two flights (default 100) |
| `construction` | `Solid` (concrete), `Stringers`, `Open` (floating treads) |
| `treadMaterialId`, `structureMaterialId` | Materials |
| `railing` | `None`, `Left`, `Right`, `Both` (walking up); also round the void upstairs |

Validation: riser 140–220 mm, going 200–350 mm, within the floor's coordinate limit. The inspector warns when
2R + G falls outside 600–650 mm.

## Phase 1: data (backend)

- `Stair` entity, `stairs` table (soft-deleted), `StairDto`, `StairHandler` (`fields()`, `normalize()`, `apply()`,
  `restorableFields()`).
- The scene's `stairs`, `stairRise` and `stairsBelow` (the floor below's stairs reaching this floor, with their rise).
- Snapshots keep `stairs` (`SnapshotStairRestorer`); a version's scenes derive `stairRise` and `stairsBelow` from its
  other floors (`SnapshotStairs`, called by `SnapshotScenes`).
- REST through `ElementController`; MCP `add_stair` / `update_stair` / `delete_stair`; `get_floor` lists them.
- CLAUDE.md. Tests: handler validation, undo, snapshot round trip, MCP.

## Phase 2: geometry and 3D (frontend)

- `stairGeometry(stair, rise)` (pure): treads (outline, height), landings, footprint, walk line, railing paths and the
  open edges (where you step on and off). Specs per shape.
- `buildStair()`: treads and risers, solid flight, stringers or open treads, railing; tagged `stairId`; merged, with a
  section fill for the wall cut.
- The void: holes in the upper floor's slab and room floors and in the lower room's flat ceiling (a vaulted one stays
  whole at first).
- The upper floor always draws its `stairsBelow` (not pickable), so the hole shows the stair.
- Selection, scene patch, inspector fields per shape, Add menu "Building → Stairs", drag and rotate in the top view.

## Phase 3: plan PDF and ghost

- The stair symbol: treads solid below the 1300 mm cut and dashed above it, the break line where the cut crosses, a
  walking line with an arrow and "UP".
- The upper floor's plan: the void outline with the treads coming up from below.
- The ghost of the floor below shows its stairs.

## Phase 4: walkthrough

- Over a stair the walker's height follows the treads, smoothed along the walk line; upstairs the void follows the
  same stair down.
- The stair's sides and the void's edges block the walker, except where you step on and off.
- Reaching the top switches to the floor above, reaching the bottom from upstairs to the floor below (the FD-73
  mechanism), keeping the spot and the height. As built, the floor is loaded when the walker reaches the end of the
  stair (not ahead), and the floor above is not drawn while climbing.

## Not included

Winders; subtracting the void from room areas and quantities; stairs in the cut list and elevations; a stair across
more than one storey; share guests walking up (a share link has one floor).
