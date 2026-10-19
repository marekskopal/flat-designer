# Plan: furniture definitions (custom furniture as data)

Goal: new custom-made furniture without a deploy. A **furniture definition** (stored in the DB) describes a piece
as parameters + parts (boards, legs, fronts, …) whose positions and sizes are expressions of those parameters. A new
item kind `Custom` points to a definition and a generic builder renders it. The existing kinds (`BaseCabinet`,
`Wardrobe`, `Chair`, …) stay as they are.

Decisions taken:

- Editing a definition changes every item using it (live link, no per-item copy).
- Three scopes: **global** (admins manage), **user** library, **project**.
- UI: JSON editor with a live preview at first; a visual part editor later.

Status: done (phases 1–7, PR #1). v2 (`docs/furniture-definitions-v2-plan.md`) extended the format — null, lists and
records in expressions, list parameters, `repeat.over`, assemblies, `{expr}` options, appliance / sliding door parts —
and converted flat 50's custom-made furniture, so the format below is the v1 subset. The current format is the text of
`FurnitureTools::Format` (what agents get); the shared fixtures are the reference for the expression language.

## 1. Definition format (v1)

Stored as JSON in `furniture_definitions.definition`. The local frame is the cabinet frame from CLAUDE.md, but for
authoring the origin is the **left-bottom-back corner**: x ∈ [0, W] along the width, y ∈ [0, H] up, z ∈ [0, D] with
z = D at the front. The builder shifts the result by (−W/2, 0, −D/2) so it matches `buildItem()` (bottom centre origin).

```json
{
  "format": 1,
  "size": {"width": 800, "depth": 300, "height": 1800},
  "parameters": [
    {"key": "t", "label": "Board thickness", "type": "int", "default": 18, "min": 10, "max": 40, "unit": "mm"},
    {"key": "shelves", "label": "Shelves", "type": "int", "default": 4, "min": 2, "max": 12},
    {"key": "back", "label": "Back panel", "type": "bool", "default": true},
    {"key": "base", "label": "Base", "type": "select", "default": "plinth", "options": ["plinth", "legs"]}
  ],
  "derived": {"innerW": "W - 2*t", "pitch": "(H - t) / (shelves - 1)"},
  "materialSlots": [
    {"key": "carcass", "label": "Carcass", "default": "oak-veneer"},
    {"key": "back", "label": "Back", "default": "white-hdf"}
  ],
  "parts": [
    {"type": "board", "name": "Left side", "x": "0", "y": "0", "z": "0", "w": "t", "h": "H", "d": "D", "material": "carcass", "grain": "vertical"},
    {"type": "board", "name": "Right side", "x": "W - t", "y": "0", "z": "0", "w": "t", "h": "H", "d": "D", "material": "carcass", "grain": "vertical"},
    {"type": "board", "name": "Shelf", "repeat": {"count": "shelves", "as": "i"},
     "x": "t", "y": "i * pitch", "z": "0", "w": "innerW", "h": "t", "d": "D", "material": "carcass", "grain": "horizontal"},
    {"type": "board", "name": "Back", "when": "back", "x": "t", "y": "0", "z": "0", "w": "innerW", "h": "H", "d": "4", "material": "back"}
  ]
}
```

- Built-in variables: `W`, `D`, `H` (the item's width/depth/height), the parameters, then `derived` (evaluated in
  order; each may use the ones before it), and the `repeat` index inside a repeated part.
- `size` = the default size of a new item. `materialSlots[].default` is a material **code**, so definitions don't
  depend on DB ids. An item fills slots by id.
- v1 part types: `board` (box with grain and optional `edges` banding flags), `cylinder` (rod, round leg), `taperedLeg`
  (reuses the chair leg code), `front` (door/drawer leaf with a handle, reusing the kitchen front builder: `kind`, `handle`,
  `handleSide`), `glass`, `led`. Each part has `x, y, z, w, h, d` plus its own props. `when` (boolean expression) and
  `repeat` work on any part.
- Later: `{"type": "assembly", "parts": [...]}` for nested sub-assemblies (a drawer box), `rotation` per part.
- Limits (checked on save): JSON ≤ 64 KB, ≤ 200 part entries, ≤ 500 parts after `repeat`, `repeat` ≤ 100,
  expression ≤ 500 chars.

### Expression language

A small safe parser, no `eval`: numbers, identifiers, `+ - * / %`, parentheses, unary `-`, comparisons
`< <= > >= == !=`, `&& || !`, ternary `a ? b : c`, string literals (only to compare `select` values), functions
`min max round floor ceil abs clamp`. Results are numbers or booleans; lengths are rounded to whole mm in the builder.
Division by zero, unknown identifiers and wrong types are errors with a path (`parts[2].y`).

There are two twin implementations (PHP validates and makes the cut list; TS renders), kept in sync by **one shared
fixture** `backend/tests/fixtures/furniture-expressions.json` (expression, variables, expected result or error).
PHPUnit reads it in the backend container, vitest reads it via a relative path (`../backend/...`; the frontend tests run
on the host, so the separate Docker build contexts don't matter).

## 2. Backend

### Data model (migration `AddFurnitureDefinitions`)

`furniture_definitions`:

| column | notes |
|---|---|
| `id` | |
| `name`, `description` | |
| `user_id` | nullable FK users, cascade: set = user library |
| `project_id` | nullable FK projects, cascade: set = project only |
| `definition` | TEXT, the JSON above |
| `deleted_at` | soft delete: items that still use it keep rendering, but it can't be picked for new items |
| `created_by_id` | nullable FK users, set null |
| `created_at`, `updated_at` | `updated_at` also serves as the cache key in the client |

Scope = both null → global; `user_id` → user; `project_id` → project (at most one is set; checked in the provider).
Enum `FurnitureScopeEnum` (Global/User/Project) is derived, not stored.

`items`: add `definition_id` (nullable FK, **restrict**: definitions are only soft-deleted) and `Custom` to the
`kind` enum (same pattern as `AddChairAndTableItemKinds`). `ItemKindEnum::Custom` + an `ItemKindCatalog` entry
(params: `values` = parameter overrides, `materials` = slot → material id; the item's `materialId` fills the first
slot).

### Access rules (`FurnitureDefinitionProvider`)

- Reading: global for everyone; user scope for its owner and admins; project scope for whoever passes
  `ProjectProvider::findAccessible()`.
- Writing: global for admins only; user scope for the owner (admins too); project scope for anyone with project access.
  Missing permission → 403, unknown or not visible → 404 (as elsewhere).
- **An item may reference** a global definition, one from the **project owner's** library, or one of the project's.
  This keeps a project renderable for its owner even when an admin edits it. If an admin wants to use their own
  library definition in someone else's project, the UI offers "copy to project".
- `copy(definition, targetScope)` covers promote/demote: project → my library → global (admin).

### Validation (`Service/Furniture`)

- `ExpressionParser` / `ExpressionEvaluator` (AST, parsed once per definition and cached for the request).
- `DefinitionValidator`: JSON structure, unique keys, known part types and props, all expressions parse, identifiers
  resolve, material slot codes exist (`MaterialRepository`). Then a **dry run** with the defaults: every part must
  have positive sizes.
- `DefinitionEvaluator`: definition + W/D/H + values → list of resolved parts in mm. It's used by the dry run, by
  `ItemHandler` and by the cut list.
- `ItemHandler`: new field `definitionId` in `fields()`/`normalize()`/`apply()` (`restorableFields()` follows `fields()`).
  For `Custom`, `normalize()` requires an accessible definition, checks `params.values` against the parameter types/ranges
  and `params.materials` against the slots (`MaterialResolver`), and evaluates the parts at the item's size (a too
  small item → `EditException::invalid('… "Shelf" gets negative height')`). `defaults()` takes the size from
  `definition.size` when `definitionId` is given.

### API (`FurnitureDefinitionController`, `Routes`)

- `GET /api/furniture-definitions?projectId=` lists what is visible: global + mine (+ the project's and the project
  owner's when `projectId` is given), each with `scope`.
- `POST /api/furniture-definitions` with `{name, description, scope, projectId?, definition}`.
- `GET|PUT|DELETE /api/furniture-definitions/{id}`.
- `POST /api/furniture-definitions/{id}/copy` with `{scope, projectId?}`.
- `POST /api/furniture-definitions/validate` returns errors with paths (the editor uses it before saving; the client
  evaluator gives instant feedback while typing).
- `GET /api/projects/{id}/floors/{floorId}/cut-list` (phase 7).

DTOs: `FurnitureDefinitionDto`, `FurnitureDefinitionInputDto` (implements `ArrayFactoryInterface`, `BodyReader`).

### Scene, snapshot, versions

- `SceneDto::$definitions`: the definitions used by the floor's items (including soft-deleted ones), like `materials`.
  `ItemDto::$definitionId`.
- The snapshot takes floor entries from the scene, so **versions and share links embed a copy of the definitions** and
  render frozen, as they were when saved. This is the natural consequence of the format; the live link applies to the
  working copy. Document `definitions` in the `ProjectSnapshotService` format comment (optional key, format stays 1).
- Restore: if the definition id is still there and accessible, link to it. Otherwise recreate it from the embedded
  copy as a project-scope definition and remap the items.
- `SharedViewDto` already serves the snapshot scene, so it works without changes.

### Live updates

A definition change doesn't go through `SceneEditor` (it isn't a floor element and has no undo in v1), but it
changes how other projects look. After saving, find the projects with non-deleted items using it
(`ItemRepository::findProjectIdsByDefinition`) and publish a `{type: "definition", definitionId}` message on each
project's Mercure topic (extend `ProjectUpdatePublisher`, still after `frankenphp_finish_request()`). Revisions are not
bumped, so open editors' `If-Match` stays valid. The client refetches the definition and rebuilds the affected items.

## 3. Viewer engine (framework-free, `viewer/engine/custom/`)

- `expression.ts`: parser + evaluator (fixture-tested twin).
- `evaluate.ts`: `evaluateDefinition(def, item) → ResolvedPart[]` (mm, local frame), shared with the editor (errors
  for the preview).
- `custom.ts`: `buildCustomItemBody(item, materials, definitions)`, one mesh per part. It uses `meshes.ts` helpers with
  UVs in metres so wood grain and tiles keep their real size (`board.grain` picks the `GrainDirection`), `front` calls the
  extracted kitchen front helper, `taperedLeg` the chair leg. Parts get `userData.partName` for the inspector hover
  later.
- `DefinitionLibrary` (like `MaterialLibrary`) built from `scene.definitions`. `buildItem()` gets it as a parameter and
  dispatches `Custom` before the other builders.
- `ViewerEngine.load()` diff: an item counts as changed when its definition's `updatedAt` changed as well, so a
  reloaded scene (e.g. after the Mercure message) rebuilds just the items using an edited definition.
- If a definition fails to evaluate (e.g. after an edit), show a red placeholder box of the item's size so the viewer
  never breaks.

## 4. Editor UI

- `models/furniture.ts`: types; `Item.definitionId`, `Scene.definitions`, `ItemKind` gets `'Custom'`.
- `FurnitureDefinitionService` (HTTP).
- **Add menu**: a "Custom furniture" submenu with sections Project / My library / Global plus a search. Picking one adds a
  `Custom` item with `definition.size`, in the same way as the existing kinds.
- **Inspector**: a new field type `custom` (`when: isCustom`) renders a form generated from `parameters` (int → number
  input with min/max, bool → checkbox, select → select) and `materialSlots` (material pickers), and stores it into
  `params.values` / `params.materials`. The JSON params field gets `!isCustom`. The definition name has links to "Edit
  definition" and "Copy to project".
- **Library page** `/furniture` (in `LayoutComponent`, `authGuard`): tabs Global / My library / per project, with list,
  create, copy and delete. Global is editable for admins only.
- **Definition editor** `/furniture/:id`: JSON textarea (CodeMirror later), a parameter panel to try values and W/D/H,
  and a live preview (a small `PreviewRenderer` in the engine: one item, orbit camera, same materials). The error list
  links to paths. Save → `PUT`. If the definition is used by items, the editor shows "used by N items in M projects"
  before saving.
- Project detail page: a "Project furniture" section listing that project's definitions.

## 5. MCP (`src/Mcp/Tool/FurnitureTools.php`)

- `list_furniture_definitions(projectId?)`, `get_furniture_definition(id)`, `create_furniture_definition(name,
  description, scope, projectId?, definition)`, `update_furniture_definition`, `delete_furniture_definition`,
  `copy_furniture_definition`, `validate_furniture_definition` (errors + evaluated parts at default size, so an agent
  can check geometry without rendering).
- `add_item` / `update_item`: `definitionId` parameter; `list_item_kinds` describes `Custom`.
- The format documentation (section 1, condensed) goes into the tool description / `#[Schema(definition: …)]`, so
  agents can write definitions directly. Tests in `tests/Mcp/McpServerTest`.

## 6. Seed data

Migration `SeedFurnitureDefinitions` with 4–5 global definitions: open shelf, sideboard with doors + drawers, desk,
wall shelf, bed frame. They serve as a smoke test and as examples for people and agents.

## 7. Phases

Each phase is a separate commit and leaves the app working.

1. **Expression + evaluator twins**: PHP + TS, shared fixture, unit tests. No UI.
2. **Backend core**: migration, entity/repository, provider + access rules, validator, REST API, integration tests
   (`IntegrationTestCase`: scopes, 403/404, validation errors).
3. **Items + scene**: `Custom` kind, `definition_id`, `ItemHandler`, `SceneDto`/snapshot/restore, tests (undo of a
   custom item change, version restore with a deleted definition).
4. **Viewer builder**: `custom.ts`, `DefinitionLibrary`, diffing, error placeholder; `furniture.spec.ts`-style tests
   for evaluated geometry; seed migration. Also `scene-patch.ts`: an edit result whose item has a `definitionId` the
   scene's `definitions` lack falls back to a reload (the result carries the item only).
5. **Editor**: Add menu, inspector form, library page, definition editor with preview. The inspector's Kind select
   lists `Custom` already; a Custom item's `definitionId` needs its own field (clearing it when the kind changes).
6. **MCP tools** + tests; `ItemKindCatalog`'s `Custom` entry then points at `list_furniture_definitions`, and
   `get_floor` returns the scene's `definitions`.
7. **Live updates** for definition changes; **cut list** (PHP `CutListService` from `DefinitionEvaluator`: board size,
   material, edges, count per floor/project; REST + MCP `get_cut_list` + a viewer panel).

Later: assemblies, per-part rotation, a visual part editor, definition history/undo, possibly rewriting `Wardrobe` /
cabinets as definitions. (Assemblies, part tilt and the cabinets / wardrobe / dining set as definitions came with v2.)

## 8. CLAUDE.md updates (with the phases)

The convention for `Custom` items / definitions, scopes and the access rule; the "new item kind" checklist now
starts with "consider a definition instead"; the snapshot `definitions` key; the twin expression evaluator and its
shared fixture.
