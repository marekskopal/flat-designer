# Electrical plan (FD-64)

A follow-up to FD-50, the electrical schedule (commit 6f4c025). It adds a plan overlay with electrical symbols in the
top view and an electrical plan PDF for the electrician. It also makes the schedule count and list only what matters,
using settings declared on the definition instead of parameter names.

## What FD-50 gives

- A definition's optional `category` (`DefinitionCategoryEnum`, TS `ElectricalCategory`): light, socket, switch, data,
  plumbing. `DefinitionValidator` checks it, and the evaluators ignore it (fixture case "the schedule's category is not
  read by the evaluator").
- `ElectricalScheduleService` groups the shown Custom items per room by definition, int/bool/select values and `z`.
  A socket's outlets are `values['gangs']`, found by name.
- The seven seeded definitions (`socket`, `switch`, `data-socket`, `pendant`, `downlight`, `light-source`,
  `plumbing-point`) are tagged; `TagElectricalDefinitions` tagged the global rows only. The demo house's lights
  (`linear-pendant`, `linear-wall-light`, `square-ceiling-light`) are tagged in their seed files too.
- The scene carries `definitions`, so the viewer can read the category without a request.

## Decisions

- **One `schedule` object on the definition**, next to `category`:
  `"schedule": {"count": "gangs", "values": ["gangs"]}`.
  - `count` names an int parameter that multiplies the item, for example a socket's `gangs` or a data socket's
    `ports`. Without it an item counts once.
  - `values` lists the parameter keys (int, bool or select) that tell points apart and that the schedule shows.
    Without it, all int/bool/select parameters count, as now, so untouched definitions behave the same.
  - `DefinitionValidator` checks that `schedule` comes with a `category`, that `count` is an int parameter and that the
    `values` are existing int/bool/select parameters. The evaluators ignore it (extend the fixture case, in both twins'
    tests).
  - One object rather than two loose keys (`countBy`, `scheduleValues`): it keeps the top level of the format small and
    leaves room for more later (a circuit, a symbol override).
- **No more name-based counting for definitions with settings.** The service reads `schedule.count`; a definition
  without any `schedule` (older JSON in versions and share links) still counts a socket's int `gangs` and a data
  socket's int `ports` (`DefinitionSchedule::Defaults`, TS `scheduleCount()`), and the editor shows that as "Default". A migration adds the block to the seeds and to
  the rows that need it (see Migration), so a library copy of the socket keeps counting its gangs.
- **The totals gain `dataPorts`.** The row's `outlets` becomes `units`: the count × the `count` parameter, for every
  category. The totals keep `outlets` (sockets) and add `dataPorts` (data). Only the frontend and MCP read the DTO, so
  the rename is safe, but `get_electrical_schedule`'s docblock changes with it.
- **Untagged copies: show and set the category in the UI; no guessing migration.** Definitions record no copy source,
  and matching by name or parts is fragile. Instead:
  - The furniture editor gets a Category select and the schedule's `count` / `values` pickers above the JSON. They
    write into the JSON, so the JSON stays the source.
  - The library shows a category chip.
  - `copy_furniture_definition` already copies the whole JSON, so new copies keep the tag.
- **The overlay is TS only, and its symbols are shared.** `viewer/engine/electrical.ts` (framework-free) gives the
  points and the symbols as 2D polylines in a unit size. The viewer scales them to plan mm and the PDF to paper mm, so
  the screen and the sheet look alike. There is no PHP twin, as the backend draws nothing.
- **Two symbol sets, IEC 60617 and ČSN, chosen per user.** The account page's new setting `electricalSymbols`
  (`Iec`, the default, or `Csn`) picks the set for the overlay and the electrical plan PDF. See Symbol sets.
- **Printable as its own sheet, not a layer of the plan PDF:** "Electrical plan PDF" in `ExportComponent`. The plan
  with the furniture greyed, the symbols with their elevations and a legend with counts. The plan PDF stays as it is.

## Schedule settings of the seeds

| Seed | `count` | `values` |
| --- | --- | --- |
| `socket` | `gangs` | `gangs` |
| `switch` | – | `rockers` |
| `data-socket` | `ports` | `ports` |
| `pendant`, `linear-pendant`, `linear-wall-light`, `square-ceiling-light` | – | `lumens`, `kelvin` |
| `downlight` | – | `lumens`, `kelvin`, `angle` |
| `light-source` | – | `kind`, `lumens`, `kelvin` |
| `plumbing-point` | – | `kind` |

Pendants that differ only in `sag`, `bulb` or `shadow` then share a row.

## Migration

`AddElectricalScheduleSettings`:

- Global rows (created by no one): the seed files get the `schedule` block, and the migration patches it into the rows
  in place (as `TagElectricalDefinitions` did), so an admin's later edits stay.
- Library and project rows that have a `category` and the parameter a seed counts (`socket` + int `gangs`, `data` +
  int `ports`) get `{"count": …}` only, without `values`, so their rows stay as they were. Untagged rows stay untouched.
- Saved versions are not rewritten: a snapshot embeds its definitions, and an old one without `schedule` counts each
  item once. That is acceptable for history (the schedule reads the working copy anyway).

## Overlay

- `electricalPoints(scene)`: one point per shown Custom item whose definition has a category (`hiddenItemIds()` hides
  the other variants). Each point has the item id, category, the plan point, the outward direction, `z`, the
  multiplier (the `count` value) and a short label.
  - Wall-mounted points (socket, switch, data, plumbing) sit at the middle of the item's front edge and face out of
    its front (rotation 0 faces −y).
  - Lights sit at the footprint centre.
- `symbolPolylines(point, set, size)`: the set's unit-size polylines (lines, not text, `ElectricalSymbolSets`) placed in
  plan mm (see Symbol sets).
- `buildElectrical(points, set)` (engine): one `LineSegments` per category colour at the floor, outside the pickable
  `content` (clicks still pick the item) and without a depth test, so neither the wall cut nor the furniture hides them;
  the 3D items are not dimmed. A CSS2D label with the elevation (`levelText()`, `+1.100` above the floor) sits beside
  each wall-mounted point. The viewer shows every label; the PDF leaves out those that would overlap text placed
  before them.
- `ViewerEngine.setElectricalVisible(visible, set)` works like `setDimensionsVisible()`: built lazily, marked stale on `load()`,
  rebuilt when next shown, visible only while `isTopDown()`. It calls `invalidate()`; it casts no shadow.
- Display toggle "Electrical (Plan)" on both viewer pages (shown only when the floor has points), kept as `electrical` (missing: off) by `SavedViewSettingsDto`,
  `ShareSettingsDto`, the TS `SavedViewSettings` / `ShareSettings` and the share link form.
- `Capture PNG` stays as it is (it renders the 3D view; the PDF is the printable form).

## Symbol sets

- `ElectricalSymbolsEnum` (`Iec`, `Csn`) and the TS `ElectricalSymbols` type. The setting is `users.electrical_symbols`
  (a migration, default `Iec`). `UserDto` returns it, and `PATCH /api/current-user` (`CurrentUserUpdateDto`) saves it along with the name.
  The account page's profile form gets a select for it.
- Both viewer pages pass the set to the engine and to the electrical plan PDF. A share guest has no account, so
  `SharedViewDto` carries the project owner's setting.
- `electrical.ts` holds both sets as data, `ElectricalSymbolSets: Record<ElectricalSymbols, Record<ElectricalCategory, …>>`.
  The specs check that every set draws every category, and a socket at 1 to 4 gangs.
  - IEC 60617, simplified: socket a semicircle on a stem, one more arc per gang; switch a circle with a slanted stroke,
    two strokes for `rockers: double`; light a circle with an X; data a triangle; plumbing a circle with a W.
  - ČSN (ČSN EN 60617 as Czech electrical plans draw it): socket a semicircle with the protective-earth bar, gangs
    as ticks across the stem; switch a circle with the stroke and its end tick (series switch two parallel ones);
    light a circle with an X; data a square with a bar across; plumbing as IEC. These are approximations, still to be
    checked against a Czech electrical drawing.
- Changing the setting sends nothing live: the pages read it when they load.

## Electrical plan PDF

- `planSheet(scene, info, symbols)`: the plan sheet with a symbol set is the electrical plan. The walls and openings are
  drawn as usual, the items as thin grey footprints without names, the room names and no dimensions. Over them go the
  symbols at a fixed paper size (3.6 mm a unit) and the elevation beside each wall point.
- A legend in a 6 mm strip `planLayout()` keeps above the title band (one row, symbols drawn as on the plan), lists each category on
  the floor with its symbol and count, a socket's outlets and a data socket's ports in brackets. It is computed from
  the points, so share guests get it without an endpoint.
- `ExportComponent` adds the item when the scene has electrical points. `ShareSettingsDto.exports` gates it as it does
  the other exports.

## Shared frontend helpers

- `core/room-code.ts`: `roomCodeText(code)` replaces the four `room.code.replace('H1/', '')` uses (the two schedules,
  the viewer page's room list, the room label in `viewer-engine.ts`). The `H1/` prefix is the sample project's house
  code; it still strips exactly that prefix, as codes like `1/01` must stay apart.
- `core/revision-loader.ts`: `revisionLoader(projectId, revision, fetch)` returns a signal and drops late responses
  (the `loads` counter). `RoomScheduleComponent` and `ElectricalScheduleComponent` use it. `QuantitiesComponent` keeps its own loader, as it
  also filters by floor and follows live updates.
  Angular's `resource()` would do the same, but nothing in the app uses it yet; switching is a separate decision.
- `electrical-labels.ts` `rowValues()` keeps working unchanged, as the row now carries only the listed values.

## Steps

1. Shared helpers (`roomCodeText`, `revisionLoader`). A pure refactor that can ship alone.
2. `schedule` block: validator, both evaluators' fixture case, the TS definition type, `FurnitureTools::Format`,
   the service (`units`, `dataPorts`, `values` filter), DTOs and TS models, seeds and the migration, tests
   (`ElectricalScheduleControllerTest`, the migration test).
3. The symbol set setting: user field, migration, `UserDto` and `CurrentUserUpdateDto`, account page, `SharedViewDto`.
4. Furniture editor: Category and schedule pickers; the library chip.
5. Overlay: `electrical.ts` with specs (points of wall and ceiling items, hidden variants, the multiplier), the engine
   toggle, the Display toggles, saved view and share settings (backend DTOs + tests).
6. Electrical plan PDF: `planSheet(…, symbols)` with a spec (symbols present, legend counts), the export entry.
7. CLAUDE.md (the Electrical schedule paragraph, the format) and README.

## Out of scope

- Switch-to-light links (which switch controls which light): FD-104.
