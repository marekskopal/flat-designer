# Third-party sample floor plans

Floor plans in DXF published by other open-source projects, kept unchanged as test input for the DXF import. Each
was made by a CAD program rather than drawn by hand, and together they cover the usual ways of drawing walls and
openings. They keep their own licences; they are not covered by Izbik's licence.

| File | Source | Licence | Copyright |
| --- | --- | --- | --- |
| `cadcraft-apartment.dxf` | [storytold/cadcraft `examples/apartment.dxf`](https://github.com/storytold/cadcraft/blob/a599137356b5613255dab53adf33a18811555961/examples/apartment.dxf) | MIT ([`LICENSE-MIT-cadcraft`](LICENSE-MIT-cadcraft); the project is MIT or Apache-2.0 at the user's option) | Copyright (c) 2026 ArtCraft Team and the CADCraft contributors |
| `bimcraft-house.dxf` | [getcadcraft/bimcraft `fixtures/showcase/cadcraft-sample-house.dxf`](https://github.com/getcadcraft/bimcraft/blob/cfa9f775920557805cd5ce07479dc97be200f490/fixtures/showcase/cadcraft-sample-house.dxf) | Apache-2.0 ([`LICENSE-APACHE-2.0`](LICENSE-APACHE-2.0)) | Copyright 2026 The Data Design Group, Inc. |
| `kestrel-courtyard.dxf` | [wieslawsoltes/KestrelCAD `examples/courtyard.dxf`](https://github.com/wieslawsoltes/KestrelCAD/blob/447e76990e0e2c3bbb0ec12741a713588d4930b5/examples/courtyard.dxf) | MIT ([`LICENSE-MIT-kestrelcad`](LICENSE-MIT-kestrelcad)) | Copyright (c) 2026 Kestrel CAD contributors |
| `kjdraw-plan.dxf` | [KanJieTeam/kjdraw `docs/latest/showcase/assets/architecture-floor-plan.dxf`](https://github.com/KanJieTeam/kjdraw/blob/c45d743f39edacfc73eac6c22803bc4235692c8f/docs/latest/showcase/assets/architecture-floor-plan.dxf) | Apache-2.0 ([`LICENSE-APACHE-2.0`](LICENSE-APACHE-2.0)) | Copyright 2026 KanJieTeam and KJDraw contributors |
| `villa-gf.dxf` | [Pdhruv15/LV-Design-Suit `electron/samples/sample-villa-gf.dxf`](https://github.com/Pdhruv15/LV-Design-Suit/blob/486cf613c3fb9b6f4129202c61ed21e7e7d1c94b/electron/samples/sample-villa-gf.dxf) | MIT ([`LICENSE-MIT-lv-design-suit`](LICENSE-MIT-lv-design-suit); the project is MIT or Apache-2.0 at the user's option) | Copyright (c) 2026 Palanisami Govindasamy |

The names CADCraft, BimCraft, Kestrel CAD, KJDraw and LV Design Studio belong to their projects; their use here only
identifies where the files came from and implies no endorsement.

## What each file shows

| File | Units | Walls | Openings |
| --- | --- | --- | --- |
| `cadcraft-apartment.dxf` | mm | outer and inner ring of the outline (closed polylines), interior walls as rectangles touching the inner ring | doors as lines and arcs on `A-DOOR`, windows on `A-GLAZ` over unbroken walls |
| `bimcraft-house.dxf` | inch (`$INSUNITS` 1) | rings and rectangles, 6" and 4" | inserts of the blocks `DOOR-32` and `WIN-48` over unbroken walls |
| `kestrel-courtyard.dxf` | mm | a rectangle per wall piece, overlapping at the corners, gaps at the openings | lines and arcs on `A-OPENING` |
| `kjdraw-plan.dxf` | mm | line pairs on `A-WALL`, columns on `A-COLUMN` | arcs on `A-DOOR`, lines on `A-WINDOW` |
| `villa-gf.dxf` | mm | R12 (`AC1009`), line pairs on `A-WALL` | lines and arcs on `A-DOOR` |
