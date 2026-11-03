# Openable fronts (I-106)

Implemented as planned, with these differences: an assembly cannot `fold` (only a front folds); a front of its own is
keyed `front:<n>` (the n-th in part order); the selection card's button shows while viewing (editing shows the
inspector); in a walk over all floors only the shown floor's fronts open.

Fronts of custom furniture (`front` parts) are fixed boxes today (`buildFront()` in `viewer/engine/custom/custom.ts`),
so nobody can see what is inside a cabinet. This plan lets them open. A front can swing on a side hinge, slide out as a
drawer, or open as one of three lift-up flaps: the whole door swinging up, a door folding in half, or a door rising
while it stays parallel to the cabinet. A soft-close option shapes how a front closes. As with room doors, whether a
front is open lasts for the session only and is never stored.

## 1. Definition format

### `front` options (new)

| Option | Values | Default | Meaning |
| --- | --- | --- | --- |
| `opens` | `swing`, `slide`, `lift`, `fold`, `parallel`, `none` | `swing` for `kind: door`, `slide` for `kind: drawer` | How the front opens. `none` keeps it fixed (a dummy front under a sink, an end panel). |
| `hinge` | `left`, `right` | the side away from `handleSide` (so a handle on the right means a hinge on the left) | `swing` only: which vertical edge is hinged. |
| `angle` | 1–180 (degrees) | `swing` 105, `lift` 100, `fold` 90 | How far the front turns. For `fold` this is the angle of the upper panel. |
| `travel` | mm | `slide`: 0.75 × the front's `z` (about the carcass depth); `parallel`: the front's `h` + 10 | How far a drawer slides out, or how high a parallel flap rises. |
| `foldAt` | mm from the top | `h / 2` (clamped to at most `h / 2`) | `fold` only: where the front folds. |
| `softClose` | bool | false | Closing slows down over the last stretch (see §3). |

Motions, all in the front's own box and all ending outside the cabinet:

- `swing`: the front turns about a vertical axis on its hinge edge at its back face (an overlay door on concealed
  hinges, simplified), so its free edge moves out (+z).
- `slide`: the front moves straight out along +z by `travel`.
- `lift` (lift-up flap, like Blum Aventos HK): the front turns up about a horizontal axis at its top back edge.
- `fold` (bi-fold flap, like Aventos HF): the upper panel (`foldAt` tall) turns up about its top back edge by
  `a = t·angle`. The lower panel hangs from the upper panel's bottom edge, the knuckle
  `K = (top − h₁·cos a, h₁·sin a)`, and its bottom edge is guided up the front plane (z = the back face). That puts the
  bottom edge at `y = K.y − √(h₂² − (h₁·sin a)²)`, which always exists because h₁ ≤ h₂. Fully open, the two panels
  lie folded back to back with the knuckle out in front. The handle goes on the lower panel.
- `parallel` (lift-up parallel, like Aventos HL): the front stays upright and moves on parallel arms, an arc from −45°
  to +45° with radius `travel / √2`. It ends `travel` above where it started and bulges out by about 0.2 × `travel`
  halfway along.

### `assembly` with `opens`: parts that move together

The evaluator flattens assemblies, so a drawer box cannot follow its front today. With this change, an `assembly` may
take `opens` and the motion options above. The whole assembly then moves as one leaf (the front plus the drawer box
for a drawer; a larder door with racks for a door), and its pivot defaults are taken from the assembly's box.

- Evaluator (both twins): the parts of an assembly with `opens` carry `props.leaf`:
  `{key, opens, hinge, angle, travel, foldAt, softClose, box: [x, y, z, w, h, d]}`.
  - `key` is the assembly's path with its copy indices (e.g. `parts[5][2].parts[1][0]`), so it is the same in both
    twins.
  - `box` is given in the item's frame.
  - `{"expr": …}` options are evaluated per copy, as for other options.
- Validation (both twins, same error): an assembly with `opens` cannot contain another one. A `front` inside such an
  assembly cannot have an `opens` of its own other than the default.
- Fixture cases in `furniture-definitions.json`: the propagation (nested, repeated), defaults left unresolved, and the
  nesting error.

### Backend

- `PartTypeEnum::options()`: add the new keys to `Front` and `Assembly`. This needs a new kind `openAngle` (1–180),
  because `angle` means ±90 today.
- `PartEntry::resolve()` passes `leaf` down (the twin of the TS evaluator).
- Cut list: a `fold` front is listed as two pieces, `foldAt − gap/2` and `h − foldAt − gap/2`. It folds at most at
  half, and the joint gap is 3 mm by default (`CutListAccumulator`). Nothing else changes. Drawer boxes are ordinary
  boards and are counted as such.
- `FurnitureTools::Format`: describe `opens` and its options, and the moving assembly.

Defaults are resolved in the viewer only. The backend does not need the angles or travels.

## 2. Viewer: building and posing

- `buildCustomItemBody()` puts each moving front, and each group of parts sharing a `leaf.key`, into a pivot `Group`
  tagged `userData.front = {key, motion…}`.
  - The pivot sits on the motion's axis, and the parts are inside it with the offset taken off.
  - A `fold` front is built as two panels: the upper in the pivot and the lower in a child pivot at the knuckle.
  - Because the group is tagged, `mergeStatic()` merges each leaf on its own (one draw call per material) instead of
    merging it away.
- New file `viewer/engine/custom/fronts.ts` (framework-free):
  - `setFrontPose(pivot, t)` for every motion, where t = 0 means closed and t = 1 means open.
  - `frontObjects(root)`.
  - The kinematics above, unit-tested: t = 0 gives exactly today's geometry, a fold's lower edge stays on the front
    plane, and a parallel flap ends `travel` higher.
- `FrontStates`, beside `LeafStates` in `doors.ts`. Its states are keyed by `itemId` + `key`, so they survive item
  rebuilds and diff reloads, and states whose items have gone are dropped.
  - `sync()` takes the built pivots, `toggle()` flips one front, and `setAll()` opens or closes them all.
  - `update(dt)` advances the motions and reports whether anything moved. `apply()` poses the pivots.
- `ViewerEngine`:
  - The tick updates and applies the fronts, then calls `invalidate()` and `refreshShadows()` while any front is
    moving (fronts cast shadows).
  - Rebuilt items get their pose back from `FrontStates`.
  - The glTF export keeps the current pose, as it does for doors. The plans, elevations and sections keep drawing
    the item's box.

## 3. Soft close

`FrontStates` moves each front at a speed (unlike `LeafStates`, which follows a fixed ease), so a front that is
reversed mid-way does not jump:

- Opening: the front speeds up and then slows down, taking about 0.45 s, the same for every front.
- Closing with `softClose`: the front moves at full speed until it enters the damper zone, then approaches closed
  exponentially (τ ≈ 0.12 s) and snaps shut under 0.5 mm or 0.3°. The zone is the last 50 mm of a drawer
  (at most 20 % of `travel`) or the last 12° of a door or flap.
- Closing without it: the front speeds up all the way and stops hard with a small rebound (2 mm or 1.5°), which reads
  as a slam.
- Tests drive `update()` with fixed time steps and check the profile: monotone, slow at the end, and a reversal is
  continuous.

## 4. Interaction

- **Walkthrough**: E, a click at the view centre and a tap already toggle the door in view (`doorAt()`). The raycast
  also hits front pivots, and the nearest hit within `DoorReach` wins, whether it is a door or a front. In a walk over
  all floors, every context floor gets its own `FrontStates`, as each already has its own `leafStates`.
- **Overview and plan, view mode**: the first click on an item selects it, as today. A click on a front of the
  already-selected item toggles that front. The selection card of a Custom item with moving fronts gets an
  "Open fronts" / "Close fronts" button.
- **Display toggle "Open fronts"**: opens or closes every front of the floor, animated. Session only: saved views
  and share links do not keep it, and every load starts with the fronts closed.
- **Edit mode**: clicks keep selecting and dragging. The card button still works. A drag leaves the fronts as they
  are.
- **Furniture editor preview** (`custom/preview.ts`): a click toggles a front, and an "Open fronts" checkbox opens
  them all. This is where definition authors check the motions.
- Fronts do not collide in the walkthrough, because items do not.

## 5. Seeds (one new migration, `FurnitureSeeds::update()`)

- Kitchen base and tall cabinets, sideboard, bedside table and vessel vanity:
  - Each drawer becomes an `assembly` with `opens: slide`, holding the front and a drawer box: sides, back and bottom
    boards, inside the carcass and kept clear of the side boards.
  - A new `softClose` bool parameter (default true) is fed to the options as `{"expr": "softClose"}`.
- Kitchen wall cabinet: the fronts' `type` select gains `liftUp`, `bifold` and `parallelLift`, each one front over
  the whole slot with its handle at the bottom.
- Doors keep their hinge from `handleSide`. Double doors already mirror `handleSide`, so their hinges come out on the
  outer edges.
- Parity (`custom/parity.spec.ts`) is unaffected: closed fronts are today's boxes, and drawer boxes stay inside the
  carcass's bounds and in carcass materials. Cut lists of existing projects gain the drawer boxes (and the change
  publishes `definitionChanged`).

## Decisions

- A bi-fold flap is one `front` split at `foldAt`; the cut list lists its two pieces.
- In the overview, the first click selects the item and a click on a front of the selected item toggles it.
- Fronts open only for the session: not in saved views, share links or snapshots; they start closed.

## 6. Docs and order of work

1. Format, evaluator twins, validator, cut-list fold split, fixtures and the `Format` text.
2. Viewer: `fronts.ts`, `FrontStates` with soft close, the builder's pivots, the engine tick, the rebuild path, and
   tests.
3. Interaction: walk, the selected item's click and card button, the Display toggle, the preview.
4. Seeds migration, a CLAUDE.md section ("Openable fronts"), and the README tour if it shows it.

## Follow-ups (not in I-106)

- `slidingDoor` parts sliding aside behind their neighbour (they need to know their track and overlap).
- Concealed hinge cups and arm plates drawn on a door's back (the cupboard counterpart of I-105 and I-109).
- Push-to-open (`handle: none` with a tip-on latch) as a front option affecting only the animation.
