# Demo flat

The demo project (FD-40) recreates a real flat over MCP only, from its published plan and photos. This file is the
reference for the modelling (FD-45): dimensions, rooms, the photos' camera positions and an inventory of everything in
the flat, mapped to what Flat Designer has or to the task that adds it.

## Source

- *Refurbishment of an Apartment in Barcelona*, Allaround Lab, 2017. Ciutadella, Barcelona. Photos by José Hevia.
  <https://www.archdaily.com/879835/refurbishment-of-an-apartment-in-barcelona-allaround-lab>
- The page gives no licence for its images. The plan, section, axonometric and 12 photos are kept locally in
  `source_files/demo/` (gitignored, see `calibration.md` there) and are never committed or shown. The README shows only
  our own renders, credited "modelled after a flat by Allaround Lab, photos José Hevia".

## Calibration

- The plan's scale bar (0–1–2–5 m) gives 134.6 px per metre on the 2000 px image (1 px = 7.43 mm). The bed (1.49 m),
  the worktop depth (580 mm) and the door leaves (800 × 2150 mm in the section) confirm it.
- Plan coordinates: +y is page up (plan north is not true north). The origin is the living room's inner face of the
  west facade (x) and bedroom 3's inner south wall face (y). The inner extent is about 12 760 × 7 090 mm.
- True north is about 148° clockwise from plan +y (from the plan's north arrow, unverified).
- Net floor area 55.5 m², gross with walls 66 m², plus two balconies of about 1.2 m² each. ArchDaily's 78 m² is most
  likely the built area including common parts.

### Walls

| Wall | Thickness |
| --- | --- |
| West street facade, straight, 14.1° off the plan's y axis, three piers with two balcony doors | 300 |
| Other outer walls, light-well walls, party walls | 150 |
| The "service box" partitions (bathroom, kitchen, bedroom 2 east, bedroom 3 west) | 80 |
| Master bedroom / living room partition, diagonal, with a sliding door | about 80 |

### Heights (from the section)

| | mm |
| --- | --- |
| Floor to the crown of the brick vaults | 2920 |
| Floor to the beam soffits | 2790 |
| Dropped ceiling over the service band (corridor, bathroom, kitchen, hall) | 2510 |
| Beam spacing (vault span) | about 550 |
| Interior door leaves | 800 × 2150 |

### Check points (plan mm)

| Point | x | y |
| --- | --- | --- |
| Living room SW inner corner | 0 | 1100 |
| Master bedroom NW inner corner (facade) | 1510 | 7090 |
| Master bedroom NE inner corner | 4810 | 7050 |
| Bathroom N inner face | 7130 | 5210 |
| Hall NE inner corner | 12 760 | 5140 |
| Bedroom 3 SE inner corner | 12 760 | 0 |
| Bedroom 2 SW inner corner | 4960 | 90 |
| Kitchen run, W / E end (front line y) | 7220 / 10 860 | 4050 |
| Dining table centre | 8750 | 1670 |
| Master bed centre | 2840 | 6080 |

## Rooms

| Room | Net m² | Floor | Ceiling |
| --- | --- | --- | --- |
| Living room (west, two balcony doors) | part of 30.2 | oak planks, laid along the diagonal | brick vaults |
| Dining area (south-east of the corridor, window to a light well) | part of 30.2 | oak planks | brick vaults |
| Corridor and entrance hall (terrazzo band, curved edge to the oak) | part of 30.2 | terrazzo, 600 grid | dropped ceiling 2510 |
| Kitchen (galley in the service box, oak sliding panels in front) | about 2.8 | terrazzo | dropped ceiling, black |
| Master bedroom (NW, balcony door, sliding door to the living room) | 10.7 | oak planks | brick vaults |
| Bathroom (walk-in shower, basin, WC, window to a light well) | 3.4 | terrazzo | dropped ceiling |
| Bedroom 2 (south, window to a light well) | 5.1 | oak planks | brick vaults |
| Bedroom 3 (south-east, window to a light well) | 6.1 | oak planks | brick vaults |
| Two balconies (west, wrought-iron railings) | 2 × 1.2 | tiles | — |

The open plan (living, dining, corridor, hall) has no walls between its parts. It becomes separate rooms whose shared
edges follow the floor change, including the curved terrazzo edge drawn as a polyline.

## Photos

Camera position → the point it looks at, in plan mm.

| Photo | Shows | Camera | Looks at |
| --- | --- | --- | --- |
| 01, 02 | Corridor to the living room. Kitchen open (01) or closed by oak panels (02), Cesca chair and poster in the dining corner | 10 640, 3120 | 4850, 3500 |
| 03, 04 | Kitchen closed (03) and open (04), entrance door and bedroom 3 door beyond | 8710, 1640 | 12 420, 4460 |
| 05 | From the hall to the dining corner, past bedroom 3's door | 12 130, 3940 | 7970, 1040 |
| 06 | Corridor to the living room: oak wall, bathroom door, TMM lamp and butterfly chair | 9450, 2750 | 1280, 2980 |
| 07 | Corridor, oak cladding with flush doors, bathroom door | 8340, 2900 | 4250, 3120 |
| 08 | Living room (left) and master bedroom (right) through the sliding door: balcony doors, vaults, pendants | 4250, 2680 | 540, 4610 |
| 09 | Master bedroom through the pocket door: bed, headboard ledge, chair with plant, AC grille | 3660, 3120 | 3210, 6620 |
| 10 | From the living room along the corridor to the hall | 2390, 2530 | 10 200, 2830 |
| 11 | Bathroom door: vanity, round mirror, WC, herringbone tiles | 6410, 2900 | 6410, 5200 |
| 12 | Bathroom: shower screen, rain shower, vessel basin | 5510, 3610 | 6930, 5060 |

The photos are staged sparsely. Furniture they do not show (sofa, side tables, dining table and chairs, beds, desks)
comes from the plan.

## Inventory

Legend: **have** = an existing kind, definition, material or opening option. **FD-nn** = the task that adds it.

### Shell

| Element | Notes | Maps to |
| --- | --- | --- |
| Walls 300 / 150 / 80, non-orthogonal facade and diagonal partition | | have (walls at any angle) |
| Balcony doors, French, 2 leaves, glazed with panelled lower parts, about 1300 × 2450 | white timber | Window walkable + **FD-48** (panelled lower part) |
| Exterior louvred shutters on the balcony doors | brown timber | **FD-48** |
| Balconies, about 2150 × 560, wrought-iron railing 1000 high | | Terrace room; railing **FD-28** (balusters definition) |
| Small windows to the light wells (bathroom, kitchen, dining, bedrooms 2 and 3) | white casements, 2 sashes | have (Window, mullions) |
| Entrance door, restored panelled, with architrave | white | Door + **FD-48** (panelled leaf) |
| Bathroom and bedroom 3 doors, restored panelled, with architrave | white | Door, Architrave + **FD-48** |
| Bedroom 2 door (swing) | white | Door |
| Master bedroom sliding door in the diagonal partition, flush, round pull | white | SlidingDoor (or PocketDoor), handle Pull |
| Brick Catalan vaults between white beams, spacing 550, crown 2920 | exposed brick, white beams | **FD-35** beams + **FD-30** vaulted ceiling |
| Dropped ceiling 2510 over the service band, curved edge towards the living room | white plaster; black over the kitchen | **FD-30** (partial dropped ceiling with its own outline) |
| Skirting 70 mm | white | **FD-30** |
| Oak cladding of the service box with flush doors (storage, bathroom side) | oak veneer | Panel definition + Door frameStyle None |

### Kitchen

| Element | Notes | Maps to |
| --- | --- | --- |
| Base run 3640 long, 6 modules of 600 | matt black fronts, no handles | Kitchen base cabinet (have) + matt black material (**FD-47**) |
| Oven in a base module | stainless | Kitchen base cabinet, appliance part (have) |
| Induction hob | black glass | Hob (have) |
| Inset sink and black tap | black | Sink (have); black tap **FD-29** |
| Wall cabinets over the left 4 modules, extractor below them | matt black | Kitchen wall cabinet (have); built-in extractor **FD-29** |
| Worktop and backsplash | black compact | Worktop, Backsplash (have) + **FD-47** |
| Tall oak side panels at both ends | oak veneer | Panel (have) |
| Oak sliding panels closing the galley (5–6 leaves into pockets) | oak veneer, round pulls | a definition with slidingDoor parts (have), made in FD-45 |
| Portable lamp on the worktop (Santa & Cole Cestita style) | | **FD-29** / **FD-31** |

### Bathroom

| Element | Notes | Maps to |
| --- | --- | --- |
| Walk-in shower, glass screen 1000 × 2000, floor drain | | screen definition **FD-28** |
| Rain shower set | matt black | **FD-29** (Fixture is chrome only) |
| Wall-hung vanity 580 × 420 with a vessel basin and a black tap | white | vanity: base cabinet definition (have); basin and tap **FD-29** |
| Round mirror Ø 600 with a black frame | | **FD-28** (round mirror definition) |
| WC, floor-standing, close-coupled | white | **FD-29** (Toilet is wall-hung) |
| Walls: terrazzo slabs (shower), charcoal herringbone tiles (basin and WC wall), paint above 2.1 m | | **FD-47** |

### Lights and electrics

| Element | Where | Maps to |
| --- | --- | --- |
| Bare-bulb pendants on black fabric cable, swagged to a ceiling hook | living ×2, master bedroom, bedrooms 2 and 3, dining | **FD-31** (pendant with a cable path) |
| Recessed round downlights | dropped ceiling: corridor ×4, hall, bathroom, kitchen | **FD-31** |
| Floor lamp (Santa & Cole TMM style), timber stem, white shade | living | **FD-29** + **FD-31** |
| Wall reading lamp (Tolomeo style) on the headboard ledge | master bedroom | **FD-29** + **FD-31** |
| LED strip under the wall cabinets | kitchen | LedStrip (have) |
| Sockets and switches, white | every room | **FD-31** |
| AC grilles in the dropped ceiling edge and walls | living, master bedroom, corridor | Box / definition (made in FD-45) |

### Furniture and decor

| Element | Room | Maps to |
| --- | --- | --- |
| Double bed 1500 × 2000, low, white linen | master | Bed frame (have) + mattress and bedding **FD-28** |
| Bedside tables 450 × 420 ×2 | master | **FD-28** |
| Headboard ledge, 1000 high, full wall width | master | Wall shelf / Panel (have) |
| Hinged wardrobe 2000 × 560, full height | master | Kitchen tall cabinet definition (have) |
| Wall safe | master | Box (have) |
| Single beds 900 × 2000 | bedrooms 2 and 3 | Bed frame (have) + **FD-28** |
| Small desks or bedside tables 450 × 450 | bedrooms 2 and 3 | Desk (have) / **FD-28** |
| Wardrobes 980 × 520 and a trapezoid one 700 deep | bedrooms 2 and 3 | tall cabinet definition (have); trapezoid **FD-28** |
| Three-seat sofa 2100 × 850 | living | **FD-28** |
| Round side tables Ø 560 and Ø 450 | living | **FD-28** (round top) |
| Butterfly (BKF) chair, leather | living | **FD-29** |
| Dining table 1400 × 800, 4 cantilever chairs (Cesca style) | dining | Dining table (have); chairs **FD-29** |
| Poster in a black frame leaning on the wall, 700 × 1000 | dining | **FD-42** |
| Plants (pot on the floor, fern on a chair) | living, master | **FD-29** / **FD-42** |
| Low strip along the living room's south wall (about 500 deep) | living | unclear in the photos; bench or radiator cover (Box) |

## Gaps → tasks

- **FD-25** Plan underlay: the agent traces `Propuesta.jpg`, calibrated with the scale bar.
- **FD-28** Furniture catalogue: sofa, mattress and bedding, bedside tables, round tables, trapezoid wardrobe, shower
  screen, round mirror, balcony railing.
- **FD-29** glTF models: design chairs (BKF, Cesca), lamps (TMM, Tolomeo, Cestita), vessel basin, black taps, rain
  shower, close-coupled WC, extractor, plants.
- **FD-30** Ceilings: a vaulted ceiling type, a partial dropped ceiling with its own outline (curved), skirting.
- **FD-31** Lighting: pendants with swagged cables, recessed downlights, lamp light sources, sockets and switches.
- **FD-35** Beams: exposed beams under the vaults at 550 spacing.
- **FD-42** Decor: framed poster, plants, bedding textiles.
- **FD-47** Materials: terrazzo, exposed brick, charcoal herringbone tiles (a new pattern), matt black fronts, black
  compact worktop, oak veneer.
- **FD-48** Door and window leaf styles: panelled leaves, French doors with panelled lower parts, exterior shutters.

## Result (FD-45)

The flat was modelled by Claude Code over the flat-designer MCP server only. The human part was creating the empty
project and importing the plan, photos and poster with `make import-files`. Positions came from the plan underlay,
read with `get_project_file` and small local measuring scripts.

| | |
| --- | --- |
| Wall-clock time, first tool call to the last change | 22 minutes (2026-10-20, 18:32 to 18:54 UTC) |
| MCP tool calls | about 190 (counted by hand; reads included) |
| Logged changes (`list_changes`) | 212 |
| Walls, openings, rooms | 27, 13, 11 |
| Items | 89: 70 from furniture definitions, 13 models, 6 built-in kinds |
| Groups, variant sets | 8 groups, 1 set ("Kitchen open / closed") |
| Versions | 4 phase versions and 2 saved by the share link |

### Phases

1. Underlay calibrated from the scale bar, then the walls (`add_walls`) and the rooms (`detect_room`). Version 1.
2. Doors, French windows with panels, transoms and shutters, light-well windows, balconies and railings. Version 2.
3. Finishes: oak planks, terrazzo, herringbone band, black kitchen ceiling, brick vaults with beams, skirting.
4. The kitchen (6 base modules, 4 wall cabinets, worktop, backsplashes, hob, sink, oak end panels) as one group, and
   **Oak sliding panels**, a project definition of six `slidingDoor` leaves on a ceiling track with an `open`
   parameter. The two states are the variants of "Kitchen open / closed".
5. Furniture from the plan, grouped per piece: master bed with bedside tables and lamp, wardrobes, sofa with cushions,
   nesting side tables, dining set, single beds, desk, vanity with basin and mirror, WC, shower screen. Version 3.
6. Six pendants with swagged cables, seven downlights in the dropped ceiling, the reading lamp's light, the LED strip,
   switches and sockets, decor (poster leaning in the dining corner, rugs, books, plants, kettle, bowl). Version 4.
7. Saved views "Photo 01" to "Photo 12" at the photos' cameras (with the kitchen variant each photo shows), "Plan
   with dimensions" and "Living room at night". One share link (3D, plan and walk) with a start camera.

### Compared with the photos

The shell, heights, vaults, service box, kitchen, sliding panels and bathroom wall match the photos closely
(photos 02 and 12 side by side with their saved views). Placing the poster outside the dining room was caught
in the comparison and fixed. These mismatches remain, each logged as a task:

- **FD-51** The planks run along the plan axes, not along the diagonal.
- **FD-52** Wall finishes are per room, so the shower walls are herringbone instead of terrazzo.
- **FD-53** Missing product models: vessel basin, black taps, close-coupled WC, rain shower, extractor, Cesca and
  butterfly chairs, TMM lamp. Stand-ins are named "… stand-in".
- **FD-54** Saved views have no lens setting, so they show less than the wide-angle photos.
- **FD-56** The dropped ceiling's curved edge is a polyline shared with the floor edge.
- **FD-57** Bedroom 3's trapezoid wardrobe is a rectangle.
- **FD-55** Under `make dev`, live updates do not arrive (the dev proxy has no Mercure route).

The open variant stacks the sliding leaves at both ends of the run in front of the end cabinets. The real panels
slide into pockets, which the model does not have.

### Persistence

The demo is kept as data, not as a seed migration. Its plan, photos and poster are unlicensed, and its product models
are third-party downloads, so all of them stay out of git in `source_files/demo/`. A seed could not restore the
underlay, the poster or the model items, and their ids change on every import.

- The final version's snapshot is exported to `source_files/demo/work/demo-flat-version-6.json`.
- To rebuild it after `make reset-db`, import the files and models again and let an agent model the flat from this
  file, as above. The phases and the inventory are the recipe.
