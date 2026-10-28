# Demo house

The demo house (FD-84) recreates a real family house over MCP only, from its published drawings and photos, as the demo
flat did (`docs/demo-flat.md`). This file is the reference for the modelling (FD-95): levels, walls, rooms, openings,
the photos' camera positions and an inventory of everything in the house, mapped to what Flat Designer has or to the
task that adds it.

## Source

- *Family House Žebětín*, JRA Jarousek.Rochova.Architekti, 2015. Brno-Žebětín, Czech Republic. Photos by Filip Šlapal.
  <https://www.archdaily.com/779304/family-house-zebetin-jra>
- The page gives no licence for its images. The site plan, three floor plans, the section, four elevations and 22
  photos are kept locally in `source_files/demo-house/` (gitignored, see `calibration.md` there) and are never committed
  or shown. The README shows only our own renders, credited "modelled after a house by JRA Jarousek.Rochova.Architekti,
  photos Filip Šlapal".

## Calibration

- All drawings except the site plan share one scale bar: 81.0 px per metre on the 2000 px images (1 px = 12.35 mm). The
  outer walls (447 mm), the stair (17 risers of 174 mm and a 298 mm going over 2957 mm) and the net area (206 m² without
  the garage, ArchDaily states 210 m²) confirm it. The site plan has 23.9 px per metre.
- Plan coordinates: +y is page up, which is the house's north façade (plan north is not true north). The origin is
  the ground floor's inner south-west corner: x is the west wall's inner face, the same line on all three floors, and
  y is the south wall's inner face there.
- The south wall is not square to the others. Its inner face is the line y = 0.0875 x (5.0° counter-clockwise from plan
  x), on all three floors and the garage. Every other wall is orthogonal.
- **True north is 20° counter-clockwise from plan +y: `northAngle` 340.** This comes from the plans' north arrow and from
  the house's edges on the site plan, which agree within a degree. The elevations' names (NE, SE, SW, NW) are rounded.
- Site: Brno-Žebětín, about 49.21 N, 16.48 E, `Europe/Prague`. The street (U Hájovny) is to the west, and the plot
  falls from it to the east.

### Levels (from the section; ±0.000 = ground floor finished floor)

| | mm |
| --- | --- |
| Ground floor (garden level), finished floor | ±0 |
| Ground floor clear height (to the slab soffit) | 2570 |
| First floor (street level), garage floor | +2960 |
| First floor clear height | 2620 |
| Second floor (attic) | +5920 |
| Floor to floor | 2960, slabs with floors about 380 (GF/FF) and 340 (FF/SF) |
| Attic: roof interior line at the inner wall faces (knee) | SF + 880 |
| Attic: interior apex | SF + 4240 (+10 160) |
| Ridge, top of the ridge tiles | +10 750 |
| Eaves gutter | about +6900 |
| Garage ceiling / top of its flat roof | FF + 2300 / FF + 2730 |
| Oriel box (study), underside / top | +2400 / +6160 |
| Chimney top | +10 340 |
| Roof pitch | 38° both slopes, ridge along plan y at x = 4335 |
| Roof build-up (covering to interior line, square to the slope) | about 390 |

### Walls

| Wall | Thickness |
| --- | --- |
| Outer walls of the house, all floors (Porotherm 44 and render) | 450 |
| Ground floor walls against the slope (west, the service block and technical room) | 450, earth behind (the drawing shows a second line 300 further out) |
| Garage west and south walls | 290 / 280 |
| Load-bearing spine: corridor walls of FF and SF, GF service block, entrance walls | 215 |
| Bathroom / cloakroom (FF) and bathroom / kids room 1 (SF): installation wall | 300 |
| Partitions: bedroom 1 / 2, cloakroom / study, GF service room / WC / lobby | 150 |
| Oriel (study box): timber, larch-clad, north and south | 300 |

The house is 9565 × 9100 (outer, at its west side) and stands on all three floors on the same outline. Only the
garage and entrance (first floor, west) and the oriel (first floor, east) stick out of it.

### Check points (plan mm)

| Point | x | y |
| --- | --- | --- |
| Inner SW corner (origin) | 0 | 0 |
| Inner NW corner | 0 | 8650 |
| Inner NE corner | 8670 | 8650 |
| Inner SE corner | 8670 | 760 |
| Outer NW / NE corner | −445 / 9120 | 9100 |
| Outer SE corner | 9120 | 345 |
| GF living room NW corner (service block east face) | 2400 | 8650 |
| GF kitchen niche SW corner | 1810 | 160 |
| FF entrance, NW / SW inner corner | −2360 | 5640 / 2890 |
| FF garage inner NW / SW corner | −6385 | 5650 / −720 |
| FF oriel inner NE / SE corner (glazing) | 10 800 | 8590 / 5910 |
| Stairs: first riser of the GF flight / of the FF flight (middle) | 7200 / 7470 | 4110 |
| Chimney flue (in the north wall) | 6630 | 8840 |

## Rooms

| Floor | Room | Net m² | Floor | Ceiling |
| --- | --- | --- | --- | --- |
| GF | Living, dining and kitchen (open plan, fireplace, glazed east wall to the deck) | 54.1 | wide oak planks | white, 2570 |
| GF | Lobby with a hand basin (from the living room to the WC and service room) | 3.4 | oak | white |
| GF | WC | 2.3 | quartzite tiles | white |
| GF | Service room (laundry) | 4.7 | tiles | white |
| GF | Technical room | 5.3 | tiles | white |
| FF | Entrance (in the garage wing, open to the hall) | 6.5 | oak | white, 2620 |
| FF | Hall (stair down and up, window east) | 17.8 | oak | white |
| FF | Bathroom (double vanity, walk-in shower, WC) | 6.6 | quartzite tiles, walls too | white |
| FF | Cloakroom (wardrobes on both sides) | 6.7 | oak | white |
| FF | Study (the oriel box, corner glazing) | 15.2 | oak | white |
| FF | Bedroom 1 (SW) | 10.5 | oak | white |
| FF | Bedroom 2 (SE) | 15.0 | oak | white |
| FF | Garage (single, sectional door north, basin) | 23.6 | concrete | white, 2300 |
| SF | Hall (stair, roof window) | 18.3 | oak | sloped |
| SF | Bathroom (freestanding bath, basin, WC, roof window) | 7.7 | quartzite tiles | sloped |
| SF | Kids room 1 (north, gable window, roof window) | 15.6 | oak | sloped |
| SF | Kids room 2 (south, gable window, roof window) | 16.5 | oak | sloped |
| SF | Void over the FF hall (roof window) | 5.3 | — | sloped |

Outlines, in plan mm (s(x) = 0.0875 x is the south wall's inner face):

- GF: service room 0–2185 × 6480–8650; WC 0–2185 × 5295–6330; lobby 0–2185 × 3590–5155; technical room 0–1595 ×
  s(x)–3375; living (1810, s), (8800, s), (8800, 8650), (2400, 8650), (2400, 3375), (1810, 3375) (the kitchen niche is
  x 1810–2400; the glazing stands at x 8815).
- FF: entrance −2360–0 × 2890–5640; hall 0–8670 × 3585–5640; bathroom 25–2415 × 5870–8650; cloakroom 2710–5115 ×
  5860–8650; study 5260–8670 × 5860–8650 plus the oriel 8670–10 800 × 5910–8590; bedroom 1 0–3245 × s(x)–3370;
  bedroom 2 3390–8670 × s(x)–3370; garage −6385 to −2575 × (−160 + s(x))–5650.
- SF: bathroom 25–2795 × 5880–8650; kids room 1 3095–8670 × 5860–8650; kids room 2 2915–8670 × s(x)–3370; hall
  0–2700 × s(x)–5640 plus 2700–6110 × 4635–5640; void 6110–8670 × 3585–5640.

## Openings

Sills and heads are above the floor's finished level. Positions are the openings' extent along their wall.

| Floor | Opening | Wall | Extent (mm) | Sill / head |
| --- | --- | --- | --- | --- |
| GF | Lift-and-slide glazing, 3 panels, to the deck | east (x 8815, the wall is glazing between corner piers) | y 815–8640 | 0 / 2380 |
| GF | Strip window, living room | north | x 2480–5270 | 1520 / 2460 |
| GF | Kitchen window to the light well, set in the outer face | south | x 2400–5370 | about 1000 / 1800 (from photo 12, unverified) |
| GF | Doors: living → lobby, lobby → WC, WC → service room, lobby → technical room | | 800 leaves | 0 / 2000 |
| FF | Front door, larch boards, flush with the garage door | entrance north (y 5640) | x −2360 to −1460 | 0 / 2150 |
| FF | Fixed side panel beside it (boarded or glazed, check photo 03) | entrance north | x −1460 to −420 | |
| FF | Garage sectional door, larch boards | garage north (y 5650) | x −6360 to −2550 | 0 / 2150 |
| FF | Garage window | garage west | y 170–1110 | 1330 / 1880 |
| FF | Bathroom window | west | y 6710–7820 | about 900 / 2200 |
| FF | Cloakroom window | north | x 3380–4480 | 820 / 2520 |
| FF | Hall window | east | y 4120–5210 | 820 / 2520 |
| FF | Bedroom 2 windows | east / south | y 1590–2665 / x 6500–7650 | 820 / 2520 |
| FF | Bedroom 1 window | south | x 960–2160 | 820 / 2520 |
| FF | Study: open full width to the oriel | east wall absent | y 5860–8650 | |
| FF | Oriel glazing, frameless corner east + south, a narrow pane north | oriel | east y 5910–8590, south x 9140–10 740, north x 9925–10 730 | 0 / 2510 |
| FF | Doors: bathroom, cloakroom, study (corridor wall), bedrooms 1 and 2 (bedroom wall), oak, frame to the ceiling | | x 960–1790, 3520–4320, 6445–7285 / x 125–960, 7690–8555 | 0 / 2570 |
| SF | Gable windows | north (kids 1) / south (kids 2) | x 5160–6110 / x 3800–4800 | 830 / 2270 |
| SF | Doors: bathroom, kids room 1 (corridor wall), kids room 2 (hall wall x 2810) | | x 1605–2470, 4690–5555 / y 2170–3010 | 0 / 2000 |

Roof windows (5; 1340 × 1400 unless noted). Centres in plan mm (along the ridge y, across x of the window's plan
footprint):

| Slope | Over | Centre y | Footprint x | Size |
| --- | --- | --- | --- | --- |
| West | SF bathroom | 7300 | 0–1330 | 1170 × 1400 |
| West | SF hall | 3970 | 0–1330 | 1170 × 1400 |
| East | Kids room 1 | 7290 | 7345–8670 | |
| East | Void (higher up) | 4590 | 6300–7470 | 940 × 1400 |
| East | Kids room 2 | 2070 | 7345–8670 | |

## Stairs

Two stacked straight flights on oak stringers with open oak treads (about 50 thick), 17 risers of 174 and a going of
298, about 1050 wide, rising west. Vertical oak balusters at 150 centres with an oak handrail.

- GF → FF: first riser at x 7200, arriving at x 2400 (the FF hall by the entrance), centred on y 4110. Open both sides in
  the living room, balusters on both.
- FF → SF: first riser at x 7470, arriving at x 2700, centred on y 4110, against the bedroom wall (y 3585), so a wall
  handrail on the south and balusters on the north.
- Floor openings: in the FF floor over the GF flight (x 2400–7200, y 3585–4635); in the SF floor over the FF flight plus
  the void (x 2700–7470 × 3585–4635 and 6110–8670 × 3585–5640). They are lined with oak, and the SF edges have the same
  balustrade.

## Roof

- Gable, ridge along plan y at x 4335, pitch 38°, beaver-tail clay tiles in a double lap, small eaves overhang (the
  gutter about 180 past the wall face) and the verges nearly flush. The north and south walls are gables up to the roof;
  the east and west walls end at the knee (interior line SF + 880 at the inner face).
- As a `Roof` on the SF: width (along the ridge) from the south gable's outer face (y −490 at the west eaves, 345 at the
  east eaves) to the north one (9100), so 9590 at the west and 8755 at the east, plus the verges; depth
  9565 + 2 × 150, `eaveHeight` about SF + 520 at the outer wall faces, `thickness` 390, `overhang` 150,
  `vergeOverhang` 50, rotation so the ridge runs along plan y.
- The south gable follows the skewed south wall, so its verge is 5° off square to the ridge: at the east eaves it lies
  about 840 further north than at the west eaves. A rectangular roof cannot follow it (see Gaps).
- Chimney in the north wall at x 6410–7075, top +10 340, with a satellite dish on it. Gutters and downpipes on both
  eaves, snow guards in the tiling, three vent tiles on the west slope.
- Garage and entrance: flat roof, top FF + 2730, white rendered parapet, gravel (site plan), reaching about 480 past the
  door line as a canopy over the entrance and the garage door.
- Oriel: its own flat top at +6160, larch-clad on all sides, underside included.

## Site

- Plot: about 70 m long (east–west) and 11 m wide, the house at its street end. Corners from the site plan (plan mm,
  ±1 m): street NW (−7180, 11 030), far NE (59 320, 10 840), far SE (58 310, −370), a kink on the south boundary
  (31 190, 40), street SW (−10 650, −3180).
- Terrain: the street and the forecourt are at the FF level (+2960 at the garage, up to +3240 at the street). Along the
  north side, concrete steps go down eastwards (about 11 risers from x 0 to 3000), then the ground slopes to ±0 at the
  east façade. On the south side, the ground slopes from +2960 at the garage to ±0 at the SE corner past the kitchen's
  light well (x 2400–5400, y −1800 to −200, concrete walls). East of the house a larch deck at ±0 (x 9120–12 400,
  y 380–9050) has a step down; the lawn then falls east (about −0.5 m at x 14 000; further down the garden the fall is
  only visible in photo 01, to be settled in FD-86).
- Garden: a stepping-slab path along y ≈ 5500 from the deck east to x ≈ 24 000; a curved dry-stone wall with fan-shaped
  steps around (23 000, 7500); a stepping-slab path along the north side (y ≈ 10 000); about 25 trees; a shed with a
  deck at the far east end, centred about (55 200, 6900).
- Street side: clay paver forecourt and driveway to the garage, a raking concrete retaining wall along the street with
  a bin store (four grey doors), a sliding larch-board gate, larch-board fences on the north and south boundaries
  following the slope.

## Photos

Camera position → the point it looks at, in plan mm; z in mm above ±0. All positions are estimates from the views.
They become saved views "Photo 01" and so on in FD-95.

| Photo | Shows | Camera (x, y, z) | Looks at (x, y, z) |
| --- | --- | --- | --- |
| 01 | Far down the garden, up the stepping-slab path to the east façade | 32 000, 5000, −1000 | 4500, 5000, 5000 |
| 02 | From across the street: retaining wall, bin store, gate, garage wall, house | −22 000, 1500, 4600 | −2000, 3500, 4500 |
| 03 | Forecourt over the gate: bathroom block's west wall, garage parapet, boarded front door | −6500, 10 500, 4600 | −1000, 6500, 4500 |
| 04 | The east façade square on, stepping slabs and dry-stone wall in front | 21 000, 5000, 300 | 4500, 5000, 4000 |
| 05 | The east and south façades from the south-east of the garden | 17 000, −3500, 300 | 5000, 5500, 4000 |
| 06 | Close up of the east façade from the lawn: glazing, deck, oriel | 15 000, 6000, 200 | 9000, 6000, 3500 |
| 06b | The north gable with the oriel, fence and concrete wall on the slope (from the neighbour's side) | 5000, 25 000, 1500 | 4500, 9100, 5000 |
| 07 | FF hall from the entrance eastwards: doors left, the FF flight overhead, the GF flight going down | −1500, 4500, 4560 | 8000, 4300, 3500 |
| 08 | GF living southwards: the stair crossing, glazing left, dining table and linear pendant beyond, oak-lined opening | 6000, 7800, 1400 | 5500, 1000, 1000 |
| 09b | GF living northwards past the stair: fireplace, glazing right, poufs | 7600, 4900, 1500 | 6200, 8650, 1200 |
| 10 | Fireplace and the glazing behind it | 3500, 5500, 1500 | 8000, 8000, 1200 |
| 11 | Living room eastwards to the glazing: fireplace left, stair right, dining chairs | 3000, 6000, 1500 | 9000, 5500, 1300 |
| 12 | Dining and kitchen westwards: oak table and bench, light-well window, tall units | 8000, 1800, 1600 | 1800, 1500, 1200 |
| 13 | The same, wider | 8300, 2500, 1400 | 2000, 1200, 1000 |
| 14 | From the kitchen eastwards: stair, fireplace, dining table, glazing | 2600, 1500, 1600 | 9000, 4500, 1200 |
| 15 | Up the GF flight from its foot: kitchen tall units left | 8400, 4100, 1500 | 2400, 4100, 2500 |
| 16 | GF stair with the SF balustrade overhead, fireplace beyond (unverified) | 2600, 3000, 1600 | 8000, 6500, 1600 |
| 17a | Study: the oriel's corner glazing over the garden, poufs | 6000, 7200, 4360 | 10 800, 7000, 4160 |
| 18 | Cloakroom through its open door to the hall and stair | 3900, 8300, 4460 | 3900, 3500, 4200 |
| 19 | FF bathroom northwards: double vanity under the window, shower, WC | 1300, 6100, 4560 | 1200, 8650, 4160 |
| 20 | FF bathroom southwards: door, vanity and window | 1500, 8200, 4500 | 700, 6000, 4000 |
| 21 | SF hall down the FF flight: balustrade, roof window, hall window at the end, wall handrail | 2200, 4100, 7520 | 8670, 4600, 4500 |

The photos show no beds, desks or wardrobes in the bedrooms and kids' rooms. Those come from the plans.

## Inventory

Legend: **have** = an existing kind, definition, material or option. **FD-nn** = the task that adds it.

### Shell

| Element | Notes | Maps to |
| --- | --- | --- |
| Three floors on one outline, the slope between them | | have (stacked floors, `elevation`) |
| Outer walls 450, rendered white | white render, a grey plinth | walls (have); render and façade faces **FD-88**, material **FD-92** |
| Skewed south wall (5°) | | have (walls at any angle) |
| Ground floor retaining walls against the slope | concrete | walls (have); earth behind **FD-86** |
| Oriel box cantilevered 1.9 m east, larch boards outside, under it too | | walls and room (have, the slab follows the room); cladding **FD-88**, larch **FD-92**; the box below the FF floor (570) Box item + soffit **FD-88** |
| Garage and entrance wing with a flat roof, parapet, canopy | | Roof Flat with parapet (have) |
| Garage room type | | **FD-89** |
| Kitchen window set in the wall's outer face | | position across the wall `inset` **FD-89** |
| Light well, lift-and-slide glazing, 3 panels | oak-stained frames | SlidingDoor (have) + fixed panes, frame material **FD-89** |
| Timber windows, oak stained, 2 sashes | | Window (have) + frame material **FD-89** |
| Frameless corner glazing of the oriel | | corner windows (have) + frame material **FD-89** |
| Front door and garage door in larch boards | | Door leaf Boarded, GarageDoor Sectional **FD-89** |
| Interior oak flush doors, oak frames to the ceiling | | Door (have) + leaf and frame material **FD-89** |
| Outer sills, grey metal | | have (outer sill) |
| External blind boxes over the GF glazing and the oriel | | **FD-93** |

### Roof

| Element | Notes | Maps to |
| --- | --- | --- |
| Gable roof 38°, small overhangs | | Roof Gable (have) |
| South verge skewed 5° with the wall | | **FD-97** (skewed verge) |
| Beaver-tail clay tiles | | **FD-91** pattern, `roof-tiles-beaver-clay` |
| Gables up to the roof | | `upToRoof` (have) |
| Sloped attic ceilings, knee walls | | have (FD-81) |
| 5 roof windows | | RoofWindow (have) |
| Chimney with a cap, satellite dish | rendered | **FD-91** chimney; dish **FD-93** |
| Gutters and downpipes, snow guards, vent tiles | zinc | **FD-91** |
| Flat roofs: garage (gravel), oriel | | Roof Flat (have); gravel or membrane material **FD-92** |

### Interior

| Element | Room | Maps to |
| --- | --- | --- |
| Wide oak planks | all living rooms | **FD-92** (wide oak) |
| Quartzite tiles, large format, floor and walls | bathrooms, WC | **FD-92** + room wall finishes (have) |
| White walls and ceilings | everywhere | have |
| Stacked straight stairs, open oak treads on stringers | GF, FF | Stair Straight, Stringers (have) |
| Vertical oak balusters with an oak handrail; a wall handrail | stairs, SF void | **FD-90** |
| Floor openings lined with oak, the SF void | FF, SF | **FD-90** (floor voids) |
| Fireplace: oak hood cabinet, black plinth with a log store, fire | living | **FD-93** |
| Kitchen: white handleless base run under the window, tall units with oven and microwave, solid-surface worktop with integrated sink, tap | kitchen | kitchen definitions (have) + solid surface **FD-92** |
| Built-in oak bench along the dining wall, white plinth band | dining | **FD-93** |
| Long oak table 3000 × 900, oak chairs | dining | Dining table (have) at 3000; chairs Model **FD-93** |
| Felt poufs (round) | living, study | **FD-93** |
| Linear oak pendant over the table, square oak-framed ceiling lights, wall lights | GF, FF | **FD-93** |
| Floor convector grilles along the glazing | living | Box item (have) |
| Double vanity, white, with two vessel basins and tall taps | FF bathroom | **FD-93** vanity + **FD-53** basin and tap models |
| Walk-in shower with a ceiling rain head, glass screen | FF bathroom | shower screen (have) + rain head **FD-93** |
| Wall-hung WCs | bathrooms, GF WC | Toilet (have) |
| Freestanding oval bath | SF bathroom | **FD-93** |
| Hand basin | GF lobby, garage | Washbasin (have) |
| Wardrobes on both sides | cloakroom | sliding wardrobe / tall cabinet (have) |
| Beds, desks | bedrooms, kids' rooms (from the plans) | have |
| Sockets and switches | everywhere | have |

### Site

| Element | Notes | Maps to |
| --- | --- | --- |
| Sloping plot, street 3 m above the garden | | **FD-86** |
| Larch deck at ±0 with a step down | | Terrace room with `floorElevation` (have); decking **FD-92** |
| Concrete retaining walls with sloping tops, light-well walls | | **FD-87** |
| Concrete steps along the north side | | **FD-87** (outdoor steps) |
| Larch-board fences following the slope, sliding gate | | **FD-87** |
| Clay paver forecourt and driveway | | Driveway room (have) + pavers **FD-92** |
| Stepping-slab paths | | **FD-92** (stepping slabs) |
| Curved dry-stone wall with fan steps | | curved wall (have) + `Stone` **FD-92**; steps **FD-87** |
| Bin store with four doors, garden shed | | **FD-93** |
| Car in the garage, trees | | **FD-93** (CC0 models) |
| Elevations and sections to check the model against the drawings | | **FD-94** |

## Gaps → tasks

- **FD-86** Sloping terrain: spot heights, the ground under the house, contours.
- **FD-87** Site walls with sloping tops, outdoor steps, fences on the slope, sliding gate.
- **FD-88** Façade finishes on the outer faces (render, plinth, larch cladding), slab and box soffits.
- **FD-89** Frame and leaf materials, Boarded leaves, `GarageDoor`, fixed panes of sliding doors, the position across the
  wall (`inset`), room type Garage.
- **FD-90** Floor voids with linings, railings, stair railing styles and wall handrails.
- **FD-91** Chimney, gutters and downpipes, snow guards, items on the roof (vent tiles), beaver-tail tiles.
- **FD-92** Materials: render, larch, exposed concrete, zinc, gravel, wide oak, solid surface, quartzite, decking,
  stepping slabs, clay pavers, stone.
- **FD-93** Catalogue: fireplace, bench, poufs, lights, vanity, rain shower, freestanding bath, blind boxes, bin store,
  shed, dish; CC0 models of a car, chairs and trees.
- **FD-53** Product models: vessel basins and taps.
- **FD-94** Sections and façade elevations (optional).
- **FD-97** (added by this inventory): a roof's verge not square to its ridge, as the south gable follows the 5° wall.
