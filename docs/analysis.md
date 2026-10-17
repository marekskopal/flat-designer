# Flat Designer: analysis of inputs and open-source options

Date: 2026-10-17

## 1. Inputs

### Floor plan (`source_files/floor-plan.pdf`)

- Exported from **AutoCAD LT 2022**, 1 page, A4, scale **1:100**. It is a **vector** PDF:
  `pdftocairo -svg` gives about 9,800 `<path>` elements. Walls, door swings and dimension
  lines are real geometry, not a scan.
- Room schedule (from the legend):

| Code    | Room                         | Area m² | Clear height m |
|---------|------------------------------|--------:|---------------:|
| H1/B_01 | Hallway (chodba)             | 11.0    | 2.4            |
| H1/B_02 | WC                           | 1.6     | 2.4            |
| H1/B_03 | Bathroom (koupelna)          | 4.2     | 2.4            |
| H1/B_04 | Living room + kitchen        | 38.2    | 2.6            |
| H1/B_05 | Bedroom (ložnice)            | 14.3    | 2.6            |
| H1/B_06 | Room (pokoj)                 | 16.5    | 2.6            |
| H1/B_10 | Terrace (on ground)          | 12.0    | –              |
|         | **Total**                    | 97.8    |                |

- The main dimensions are written on the plan, for example living/kitchen 6150 × 6015 mm,
  bedroom 3495 wide, room 2540 wide, door openings 800/900 × 1970, French windows 1800 × 2350.
  That is enough to build the model by hand to within about a centimetre.
- **Best option:** ask the developer or architect for the **DWG/DXF** of this unit. Since it was
  drawn in AutoCAD, the DWG exists. With DXF layers (walls, openings) the import can be done
  with a script instead of tracing.
- Fallback: PDF → SVG (`pdftocairo`) → use it as a scaled underlay in a small 2D tracing tool
  and enter walls by their dimension values. Automatic raster recognition (CubiCasa5k,
  FloorplanToBlender3d) isn't needed: it is less accurate than the vector data we already have.

### Kitchen render (`source_files/kitchen/Render 1.png`)

- L-shaped kitchen with an island and a tall appliance column on the right.
- Base cabinets: matte sage/dark green fronts, black bar handles, oak worktop.
- Wall: oak tall-panel wall cabinets with oak backsplash, green top row up to the ceiling,
  LED strip under the wall cabinets.
- Tall column: green, built-in microwave above built-in oven (black glass), fridge column
  next to it.
- Island: green body, oak top with overhang.
- Sink: black granite 1.5-bowl at the left run, induction hob at the corner of the right run.
- **The render has no dimensions.** To model it accurately we need the kitchen studio's
  plan or quote (module widths, heights, worktop depth). Otherwise we estimate from standard
  modules (base 600 deep / 720 carcass + 100–150 plinth, worktop 38, wall cabinets 350 deep)
  and fit them to the room walls.

## 2. Open-source projects

| Project | License | Stack | Status | Useful for us |
|---|---|---|---|---|
| [Pascal Editor](https://github.com/pascalorg/editor) | MIT | React 19, Next 16, React Three Fiber, WebGPU, Zustand, Zod, three-bvh-csg | Very active (2025–26), ~20k★, npm packages, MCP server | **Best reference for the data model and wall geometry**: flat `Record<id, Node>` with `parentId`; Site › Building › Level › Wall › Item (door/window), Slab, Ceiling, Zone; wall mitering plus CSG openings; plugin node kinds. The packages are React-only, so they can't be dropped into Angular. |
| [openPlan3D](https://github.com/laanlabs/openPlan3D) | MIT | SvelteKit, TypeScript, three.js | Active (460 commits, docs from Sept 2026) | 2D-drawing-to-3D workflow, furniture catalog incl. kitchen, DXF/SVG/PDF export, documented JSON project format. Good UX reference. Local-first, no backend. |
| [blueprint3d](https://github.com/furnishup/blueprint3d) and forks ([blueprint-js](https://github.com/jan0991/blueprint-js)) | MIT | three.js, TypeScript (old) | Original unmaintained since about 2016, forks have patchy activity | Classic, small codebase for corners/walls/rooms → 3D and wall/floor item snapping. Easy to read for algorithms. Don't depend on it. |
| [react-planner](https://github.com/cvdlab/react-planner) ([react-floorplanner](https://www.jsdelivr.com/package/npm/react-floorplanner) fork) | MIT | React, Redux, three.js | Abandoned (last push 2024), community TS revival | Catalog concept (element = 2D SVG plus 3D builder plus properties). Good idea, outdated code. |
| [Sweet Home 3D](https://www.sweethome3d.com/) / SweetHome3DJS | GPL | Java desktop + JS viewer | Mature, maintained | Very fast way to get a model today: import the plan as a background image, trace walls, Cabinet Maker plugin, export OBJ/glTF. GPL, so use it as a tool, not as code. |
| [Blender](https://www.blender.org/) + [Archimesh](https://www.blendernation.com/2013/12/04/archimesh-0-6-adds-kitchen-generator/) | GPL | Desktop | Maintained | Modelling detailed assets (handles, sink, appliances) and exporting **glTF** for the web viewer. Archimesh has a basic kitchen-cabinet generator. |
| [That Open Engine / web-ifc](https://github.com/ThatOpen/engine_components) | MIT | three.js, IFC | Active | Only relevant if we want IFC (BIM) interchange later. Overkill now. |
| [three-bvh-csg](https://github.com/gkjohnson/three-bvh-csg), [three-gpu-pathtracer](https://github.com/gkjohnson/three-gpu-pathtracer) | MIT | three.js | Active | CSG for door/window cut-outs. Path-traced "photo mode" to get close to the render quality. |
| [Poly Haven](https://polyhaven.com/), [ambientCG](https://ambientcg.com/) | CC0 | Assets | – | HDRI lighting, PBR textures (oak, matte paint, plaster, laminate floor), some furniture models. |

No usable open-source **parametric kitchen cabinet library for three.js** came up. The good
ones are commercial Blender add-ons. Writing our own generator is simple and is the core value
of the app.

## 3. Recommendation

**Build our own small app. Take ideas from Pascal (data model, wall system) and openPlan3D/blueprint3d
(editor UX, snapping), but don't fork any of them.** Reasons: all the maintained editors
are React or Svelte with no backend, and what we need first is a precise, data-driven model of one
flat plus a parametric kitchen, not a general CAD tool.

### Architecture (same layout as ukolio)

- `proxy/`: nginx, `/api/*` → backend, `/` → frontend.
- `backend/`: FrankenPHP + PHP 8.5, `marekskopal/orm` + `marekskopal/router`, MariaDB.
  Stores the scene and serves uploaded assets (glTF, textures).
- `frontend/`: Angular 22 (standalone + signals), **plain three.js** wrapped in an Angular
  component and services. `angular-three` exists, but it trails Angular releases
  (it targets Angular 18–20), and imperative three.js gives us full control over geometry
  generation.

### Domain model (first version)

Following Pascal's flat node model, normalized for MariaDB:

- `Flat` (name, address) › `Level` (elevation, default height)
- `Wall` (level, start x/y, end x/y [mm], thickness, height, type ∈ Exterior/Interior/Partition)
- `Opening` (wall, kind ∈ Door/Window/FrenchWindow, offset along wall, width, height, sill height, swing)
- `Room` (level, code `H1/B_04`, name, polygon, floor height, ceiling height, floor/wall materials)
- `Material` (name, PBR texture set, color, roughness)
- `Kitchen` (room) › `CabinetRun` (wall-aligned origin, direction) › `Cabinet`
  (type ∈ Base/Wall/Tall/Island/Filler/Corner, width, height, depth, fronts layout
  [doors/drawers], front/carcass material, handle, appliance?)
- `Worktop`, `Backsplash`, `Appliance` (glTF asset reference), `Light` (LED strip, spots)

Geometry is generated on the client from these parameters. The DB holds only parameters,
so every change (module width, front color) can be edited in the UI and re-rendered right away.
Scene snapshots or versions can be a JSON column later ("variant A vs B" kitchens).

### Rendering

three.js `WebGLRenderer` (WebGPU optional later), PBR materials, Poly Haven HDRI, soft
shadows, SSAO, an orbit camera and a first-person walk mode. Optional three-gpu-pathtracer
"photo" button for render-like stills.

### Getting the geometry in

1. Ask for the DWG/DXF. If we get it, write a one-off import script (DXF → walls JSON → seed migration).
2. If not, hand-enter walls and openings from the plan dimensions into a seed migration.
   It's fast for about 7 rooms, and we can check it by overlaying the PDF-derived SVG in the 2D view.

## 4. Proposed phases

1. **Skeleton:** docker-compose (proxy, frontend, backend, mariadb) copied from ukolio's
   conventions, health endpoints, CI checks (phpstan, phpcs, eslint, vitest).
2. **Flat shell:** domain + migrations + seed of this flat; read-only 3D viewer of walls,
   floors, openings (CSG), room labels; 2D plan view with SVG underlay for verification.
3. **Kitchen:** parametric cabinet generator, materials (green matte, oak), worktop,
   backsplash, appliances (glTF), LED strip; recreate `Render 1.png`.
4. **Editing:** property panel for cabinets and materials, kitchen variants, saving to the backend.
5. **Nice-to-have:** furniture placement, walk mode, path-traced photo mode, sharing link,
   MCP endpoint (as in ukolio/Pascal) so an agent can edit the flat.

## 5. Open questions

- Can you get the **DWG/DXF** of the unit from the developer?
- Do you have the **kitchen studio's dimensioned plan or quote** for the render?
- Single user (just you) or accounts and sharing like ukolio?
- Any editing in v1, or a viewer only at first?
