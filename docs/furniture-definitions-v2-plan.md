# Plan: furniture definitions v2 — convert flat 50's furniture

Goal: the custom-made furniture of flat 50 (kitchen, wardrobe, dining set, hall stand) becomes `Custom` items of furniture
definitions, so it is edited like any other definition and shows up in the cut list. Bought products (sink, hob,
washbasin, toilet, bathtub, mirror, towel radiator, flush plate, fixtures, washing machine) and building elements
(fences, gravel strips, pre-wall, tiled ledge) stay built-in kinds.

Builds on `docs/furniture-definitions-plan.md` (v1, phases 1–7).

Status: done (phases 1–7, ukolio FD-16…FD-22). Removing the built-in furniture kinds is a follow-up task (see the
decisions at the end).

## What flat 50 needs that format 1 cannot express

From the items of project 1 (25 wall, 13 base and 5 tall cabinets, 6 worktops, 4 backsplashes, 5 LED strips, a
wardrobe, a table, 4 chairs, the hall stand):

| Need | Where | Format 1 |
|---|---|---|
| A list of fronts from the bottom up, each `door` / `drawer` / `oven` / `microwave` with an optional height (the rest share what is left), leaves side by side (`count`), a per-front handle | every cabinet | only single-value parameters; `repeat` over a number |
| Doors of a pair with handles at the meeting edges, the handle side of a single door | cabinets, tall fridge | part options are fixed strings |
| Built-in oven and microwave fronts | tall oven columns | no appliance part |
| Blind corners (`blindLeft` / `blindRight`) | corner bases | fine with parameters + expressions |
| Recessed plinth in a darker shade of the carcass | bases, tall, hall stand | no shading option |
| Sliding doors on two tracks in aluminium frames, some faced with mirror (`mirrorDoors: [3]`), leaves overlapping | wardrobe | no sliding door part, no per-door choice |
| A seat narrower at the back (`backWidth`) | chairs | boards are boxes only |
| Several parts per copy (a front slot of 2 leaves, a door with its frame) | cabinets, wardrobe | parts are flat; no nesting |

## 1. Expression language v2

Backward compatible: every format 1 definition keeps working (fixtures stay green).

- **Values** gain `null`, lists and records (from list parameters). Literals: `null`; no list or record literals.
- **Operators:** `a ?? b` (b when a is null), indexing `list[i]`, member access `record.field`.
- **Functions:**
  - `len(list)`;
  - `sum(list)` (of numbers);
  - `contains(list, value)`;
  - `field(list, "name")`, which gives the list of one field of each record;
  - `share(sizes, total, gap?)`, which returns the list with each null replaced by an equal share of what the given sizes and gaps leave of `total`. This is how front heights are stacked;
  - `offsets(sizes, gap?)`, the running starts.
- Type rules stay strict: no conversions, `null` only where `??` or a null check expects it, index out of range is an error.
- New error codes: `index`, `null`. Both twins (PHP `Service/Furniture/Expression`, TS `viewer/engine/custom/expression.ts`) and
  the shared fixture `furniture-expressions.json` gain cases for each.

**List parameters:** `{key, type: "list", fields: [{key, type: int|bool|select, default?, min?, max?, options?, optional?}],
default: [...], minItems?, maxItems?}`. A value is a list of records, and each field is checked like a parameter. An optional field may be
null. Example: `fronts` with fields `type` (select door/drawer/oven/microwave), `height` (int, optional), `count` (int,
default 1), `handle` (select, optional).

## 2. Definition structure v2

- **`repeat.over`:** a part entry repeats over a list. `{over: "fronts", as: "f"}` gives `f` (the record) and `f_index`; `count` stays
  for numbers.
- **`assembly` parts:** `{type: "assembly", x, y, z, w, h, d, parts: [...], repeat?, when?}`. The children are placed in the assembly's
  frame and see its variables plus `AW`, `AH`, `AD` (its size). Nesting is limited to depth 4, and the limits on part entries and parts count all levels.
  For example: a front slot (assembly over `fronts`) with `count` leaves (front parts repeated inside).
- **Expression options:** an option may be `{"expr": "…"}`, evaluated per copy. It must give a value the option accepts, e.g.
  `"handleSide": {"expr": "k == 0 ? 'right' : 'left'"}` or `"mirror": {"expr": "contains(mirrorDoors, k + 1)"}`.
- **Validator:**
  - per-type option checks also apply to `{expr}` values;
  - `DefinitionValidator::encode()` learns the nested objects (assemblies, list defaults, `{expr}`);
  - the shared fixture `furniture-definitions.json` gains cases;
  - `ResolvedPart` stays flat. Assemblies resolve into their children, and each child records its assembly path (`parts[3].parts[0]`, copy indexes) for the
    error messages, the inspector hover and the cut list.

## 3. New part types and options

- `appliance`: `kind: "oven" | "microwave"` (the black glass front, window and steel handle of `kitchen.ts` `buildAppliance`).
  In the cut list it goes under other parts, as an appliance to buy.
- `slidingDoor`: a leaf in an aluminium frame (`profile` slot), `track: "front" | "back"`, `mirror: bool`. It reuses the wardrobe
  builder's leaf code.
- Board options: `shade` (0–1, darker colour: plinths in the carcass shade), `backWidth` (mm, a trapezoid board: the chair
  seat).
- Front options: `handleSide` and `handle` accept `{expr}`. The handle placement keeps the reach-height rule of
  `buildHandle()`.
- `PartTypeEnum`, `FurnitureTools::Format` (the test checks it names every type), the viewer builder and the cut list
  are updated together.

## 4. Editor UI

- `CustomParamsComponent`: a list parameter shows as a table of rows (one per record) with an input per field, plus add,
  remove and move up/down. It replaces what `CabinetParamsComponent` does for cabinets today, so it must be as quick to use.
- The definition editor's tryout panel gets the same form. Its preview and errors need no change.
- The Add menu offers the new global definitions under "Furniture — global".

## 5. Global definitions for flat 50's furniture

New seed files in `backend/migrations/furniture/`, built on 1–3. These are real carcasses rather than the single box the built-in
cabinet draws, so the cut list is worth something:

- **Kitchen base cabinet:** sides, bottom, back, top rails, plinth (`shade`), `fronts` list with shared heights, blind
  left/right, handle, handle side.
- **Kitchen wall cabinet:** as above, without a plinth.
- **Tall cabinet:** as the base cabinet, with `appliance` fronts.
- **Worktop, Backsplash:** one board each, cut to the item.
- **Sliding wardrobe:** carcass, `doors` list (`mirror` per door), plinth, overlap as the built-in one (`slidingLeaves()`).
- **Dining chair:** tapered legs, trapezoid seat (`backWidth`), two-rail back, seat height parameter.
- **Dining table:** boards of the top along the width, tapered legs, apron.
- **Panel:** one board (the hall stand's back panel, niche side, soffit), and **Rail / peg:** a cylinder.

**Parity check:** a test builds each converted flat 50 item both ways (built-in kind, definition) and compares the
bounding boxes of the visible fronts, handles and carcass (within a few mm). Before and after screenshots of the
kitchen, wardrobe, dining set and hall stand go in the PR.

## 6. Converting the items (migration)

`ConvertFlat50FurnitureToDefinitions`:

- **Mapping:** for each listed item of project 1, `kind` becomes `Custom`, and `definition_id` and `params.values` are mapped from the
  old params (plinth, handle, handleSide, fronts, blindLeft/Right, doors, mirrorDoors, seatHeight, backWidth). The material
  stays the item material, i.e. the first slot. Size, position, rotation, room, **id**, group and variant set stay, so groups, variants,
  the change history and share links keep working.
- **Reversible:** the mapping is lossless and `down()` maps back.
- **Old versions and history:** snapshots of versions saved before the migration still say `BaseCabinet` etc. and keep rendering
  with the built-in builders. Undo of a change from before the migration restores the old kind.
- So the built-in kinds and their builders **stay**:
  - `list_item_kinds` marks them "prefer the furniture definitions" for new items;
  - the Add menu lists the definitions first and the built-in furniture kinds under "Built-in (old)".
  - Whether to drop them later is a separate decision.

## 7. Phases

Each phase is its own commit and leaves the app working.

1. **Expression language v2**: null, `??`, lists, records, indexing, member access, list functions. Twins + fixtures.
2. **List parameters and structure v2**: `repeat.over`, `assembly`, `{expr}` options, validator/encode, limits. Twins +
   fixtures.
3. **New part types**: `appliance`, `slidingDoor`, board `shade` / `backWidth`, expression handles. Viewer, validator,
   format doc, cut list.
4. **Editor UI for list parameters**: inspector and tryout.
5. **Flat 50 global definitions** + parity tests and screenshots.
6. **Conversion migration** (in place, reversible) + Add menu / catalog changes, cut list of flat 50 checked by hand.
7. **Docs and clean-up**: CLAUDE.md, plan v1 notes, MCP instructions; decide on hiding or removing the built-in furniture kinds.

## Decisions taken

- The flat 50 definitions are **global** (`SeedFlat50FurnitureDefinitions`), usable in every project.
- Gaps, thicknesses and recesses that were constants in `kitchen.ts` (front gap 3 mm, front 19 mm, plinth recess) are
  parameters of the definitions with those defaults.
- The built-in furniture kinds (`BaseCabinet`, `WallCabinet`, `TallCabinet`, `Worktop`, `Backsplash`, `Wardrobe`,
  `Chair`, `Table`) **stay for now**: versions saved before the conversion and the change log still use them (undo of an
  older change restores the built-in kind). `ItemKindCatalog` marks them `replacedBy`, the Add menu lists them as
  "Built-in (old)", and `CabinetParamsComponent` / `WardrobeParamsComponent` stay to edit them.
- Removing them is a **separate follow-up**: it needs saved versions and change-log JSON converted too (or the snapshot
  restore and undo mapping built-in items through `BuiltInFurnitureConverter`), then drops the builders
  (`kitchen.ts` / `furniture.ts` parts), the inspector forms, `parity.spec.ts`' built-in side and the enum values.
