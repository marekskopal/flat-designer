# Roofs (FD-38)

Gable, hip, shed and flat roofs over a floor, walls that rise to the roof (gables) or are cut by it, sloped ceilings
and knee walls for attics, and roof windows.

## What exists

- Walls are rectangles in elevation: `wallPieces(length, height, openings)` gives pieces with a flat `top`, and
  `buildWall()` stands them up as boxes or mitred prisms (curved walls as `curvedPrismAt()`), each with a section-fill
  twin.
- Ceilings are flat at `ceilingHeight` or vaulted (`vaultProfile()`); `ceilingHeightAt(room, x, y)` is the one
  question the pendants (`custom.ts`), the elevations' outline and `StructureSize` ask. `ceilingSteps()` closes the
  gaps between ceilings of different heights.
- Knee walls are already possible: a wall 1200 mm high, and room detection follows its faces.
- `cutVoids()` (`polygon-clipping`) cuts holes into room floors, slabs and flat ceilings (the stair voids).
- The section plane clips the whole renderer, so anything above the wall cut disappears; ceilings hide with
  `userData.ceilingPart`.
- Plots and stairs set the pattern for a new floor element: entity, `ElementTypeEnum`, handler through `SceneEditor`,
  REST through `ElementController`, scene field, snapshot restorer, MCP tools, picking tag, scene patch, inspector.

## Decisions

- **A new element type `Roof`, owned by the floor it covers** (the top floor, or the ground floor for a one-storey
  wing). Its heights count from that floor's level, so the stack and the stair rise need no change.
- **A roof is a rectangle with a shape** — centre `x`, `y`, `width` (along the ridge), `depth`, `rotation` — like an
  item, so dragging, turning with R and resizing come from the item code. An L- or T-shaped house gets two or three
  roofs; where they meet, each is clipped by the others (phase 5). Hip roofs on arbitrary outlines (a straight
  skeleton) and flat roofs on a polygon are left for later.
- **"Generated from the top floor outline"** means the defaults: without a size, `add_roof` / the Add menu take the
  smallest rectangle round the floor's walls' outer faces (oriented along its longest wall), the ridge along its longer
  side, the eaves at the tallest wall's height.
- **One geometry, twinned.** Unlike stairs and vaults, the roof has a PHP twin from the start: quantities (roof area,
  sloped ceilings, wall paint under the slope), the room schedule's clear heights, `StructureSize` and the roof
  window placement all need the roof's height at a plan point. The twin is small (planes over a rectangle):
  `roof-geometry.ts` and `Service/Editor/Geometry/RoofGeometry`, kept identical by
  `backend/tests/fixtures/roof-geometry.json`. The meshes stay TS only.
- **Walls: clipping by the roof, plus "up to the roof".** Every wall of the roof's floor is cut by the underside of
  the roofs over it (a partition never pokes through). A wall flagged `upToRoof` rises to the underside instead of
  stopping at its `height`: that is a gable (a pentagon under a gable roof) or an eaves wall raised to the plate. A
  height per wall end was considered and dropped: it cannot draw a gable's peak, and the roof gives the right slope
  for free. `height` stays the wall's height where no roof is over it.
- **Sloped ceilings are the roof's underside, capped by the room's `ceilingHeight`.** The ceiling at a point is
  `min(ceilingHeight, roof underside)`: an attic room with `ceilingHeight` 2500 gets slopes up to a flat collar
  ceiling at 2500; a high value (up to the ridge) makes it open to the ridge; a room under a cold roof keeps its flat
  ceiling because the roof is higher everywhere. No new room field. Vaults under a roof are not clipped (not
  included).
- **Roof windows are a new element type `RoofWindow`, children of a roof** as openings are of a wall (listed in
  `RoofDto::$windows`, soft-deleted with their roof). The opening table keeps its non-null `wall_id`: making the
  opening host polymorphic would touch every reader of `wall.openings` (wall pieces, spans, finishes, walkthrough,
  plan, elevations) for a host that shares none of that logic.
- **Visibility.** The roof is above the wall cut, so the section plane already removes it in the plan view and while
  cutting. It is tagged like the ceilings and hides with the ceilings toggle. In edit mode, the top view draws its
  footprint (with overhang) as a dashed outline in the overlay, tagged `roofId`, to select and drag it, as plots are.

## Fields

`Roof` (`roofs`, soft-deleted):

| Field | Meaning |
| --- | --- |
| `shape` | `Gable`, `Hip`, `Shed`, `Flat` |
| `x`, `y`, `rotation` | Centre of the footprint; the ridge runs along `rotation` (Shed: the slope falls towards the front, −y at 0°) |
| `width`, `depth` | The footprint at the eaves line (the wall plate), without the overhang. Hip with width ≤ depth is a pyramid |
| `eaveHeight` | mm above the floor's level of the underside at the eaves line (default the tallest wall) |
| `pitch` | Degrees, 5–75 (Flat: ignored; a flat roof is level, its fall is not drawn) |
| `overhang` | Eaves overhang beyond the footprint (default 400) |
| `vergeOverhang` | Gable / shed verge overhang (default 200) |
| `thickness` | Rafters and covering, measured square to the slope (default 250) |
| `parapet` | Flat only: a parapet's height above the roof (null none) |
| `coveringMaterialId`, `soffitMaterialId` | Materials (seed tiles, slate, standing seam metal, membrane, a timber soffit) |

`RoofWindow` (`roof_windows`, soft-deleted): `roofId`, `x`, `y` (plan centre; the plane under it is found by the
point), `width` (across the slope), `length` (along it), `type` (`CentrePivot`, `TopHung`: the look of the sash),
`frameMaterialId`. Validated by `RoofWindowPlacement`: on a sloped plane, inside it with 300 mm to its edges, ridge
and hips, not overlapping another roof window.

`Wall.upToRoof` (bool, default false).

## Phase 1: data (backend)

- Entities, migrations, `ElementTypeEnum::Roof` / `RoofWindow`, `RoofHandler` / `RoofWindowHandler` (`fields()`,
  `normalize()`, `apply()`, `restorableFields()`, `defaults()` computing the outline rectangle from the floor's walls).
  A deleted roof hides its windows until undo brings it back, as a deleted wall hides its openings.
- `WallHandler`: `upToRoof` in `fields()` / `normalize()` / `apply()` / restorable fields.
- `RoofGeometry` (PHP) and `roof-geometry.ts` with the fixture: `roofPlanes(roof)` (each plane's plan polygon with the
  overhang, its normal and height function; gable ends as vertical triangles), `roofUndersideAt(roofs, x, y)` (the
  highest underside over the point, null outside), `roofTopAt()`, the ridge / hip / eaves lines.
- Scene: `roofs` with their `windows`; snapshots keep them (`SnapshotRoofRestorer`, remapping roof window parents).
- REST through `ElementController` (`…/roofs`, `…/roof-windows`); MCP `add_roof` / `update_roof` / `delete_roof`,
  `add_roof_window` / `update_roof_window` / `delete_roof_window`, `update_wall` `upToRoof`; `get_floor` lists them;
  the server instructions say how to roof a floor (add_roof, then `upToRoof` on the gable walls).
- `RoomCeiling` / `StructureSize`: a column under a roof reaches the ceiling at its point.
- Seed migration for the roof materials.
- CLAUDE.md. Tests: handler validation and defaults, undo (roof with windows), snapshot round trip, MCP, the fixture.

## Phase 2: the roof in 3D (frontend)

- `buildRoof()` (`viewer/engine/roofs.ts`): each plane as a slab `thickness` thick (covering on top, soffit below),
  fascia along the eaves, verge boards at gables, ridge and hip caps; a flat roof as a slab with an optional parapet.
  Tagged `roofId`, merged, with a section-fill twin, casting shadows.
- `BuildingParts.roofs`, diffed by `load()`; scene patch; `fitSunShadow()`, the framing and `floorPoints()` include
  the roofs' bounds; `stack.ts` draws the roofs of the floors below (a wing's roof under the shown floor).
- Editor: Add menu "Building → Roof", the inspector's `roof` fields (shape, pitch, overhangs, heights, materials, with
  the ridge height shown), drag / nudge / R in the top view via the dashed outline, resize handles on its corners.
- glTF export includes it.

## Phase 3: walls and ceilings under the roof

- `wallPieces()` gets the wall's top profile: `wallTop(wall, frame, roofs)` samples the roofs' underside along the
  centre line at the planes' break points (ridge, hips) and returns a polyline; a piece's `top` becomes that profile
  clipped to the piece (`WallPiece.top` stays the highest point for the callers that only need it, `profile` is new).
  `upToRoof` walls follow the profile; others take `min(height, profile)`.
- `wallPieceMesh()` / `curvedWallPieceMesh()` build a piece with a sloped top as the elevation outline extruded across
  the thickness (the mitred ends keep their footprint); the section fill follows. Openings above the profile are
  clipped away by the builder; `OpeningPlacement` checks the head against the roof when an opening is set (a later roof
  change does not re-validate, as definition edits do not).
- `buildWallFinishes()` / skirting: bands clipped to the profile.
- `ceilingHeightAt()` takes the roofs (pendants, elevations, `StructureSize` twin follow); `buildCeiling()` splits the
  room by the roof planes with `polygon-clipping`: the parts where a plane is below `ceilingHeight` are laid on the
  plane, the rest flat. `ceilingSteps()` samples the heights along the shared edge.
- Plan-cut code (`PlanCutHeight`, `plan-drawing.ts`) uses the profile at the cut: a knee wall under 1300 mm is drawn
  as a low wall, as today.

## Phase 4: roof windows

- `RoofWindowHandler` with `RoofWindowPlacement` (twinned plane lookup).
- 3D: frame, sash (by `type`), glass that casts no shadow, flashing; a hole in the covering and soffit (`cutVoids()` on
  the plane's polygon) and in the sloped ceiling under it, with a lining square to the slope. Tagged `roofWindowId`,
  selectable and draggable along the roof in the top view.
- Inspector, Add menu (enabled with a roof selected), MCP tools from phase 1.

## Phase 5: roof junctions, exports and measurements

- Junctions: each plane of a roof is clipped (in plan, `polygon-clipping`) where another roof of the floor is higher,
  along the planes' intersection line, giving valleys; the twin's `roofUndersideAt()` already takes the highest roof.
- Plan PDF: the roof outline with the overhang and the ridge / hip lines dashed (above the cut), roof windows as
  dashed rectangles. A separate roof plan sheet is left for later.
- Elevations: the faces' top follows `ceilingHeightAt()` (sampled at the profile's break points); gable openings show.
- Quantities: the covering area per material (plane areas), the soffit, sloped ceiling areas, wall paint under the
  slope (`RoomSurfaces` with `RoofGeometry`), roof windows as a count.
- Room schedule: a room's clear height becomes a range (min–max) under a roof, its volume follows the slope, and the
  area with a clear height of at least 1.2 m is listed beside the net area (the share usually counted in attics).

## Not included

Dormers, mansard and gambrel roofs, hips on non-rectangular outlines, flat roofs on a polygon, a roof's fall drawn,
gutters and downpipes, chimneys (a `Column` item stands in), vaults clipped by a roof, roof windows that open in the
walkthrough, a roof plan sheet.
