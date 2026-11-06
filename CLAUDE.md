# Izbik

A 3D designer for flats and houses. A project has 1..N floors with rooms, walls, openings and items. Custom-made
furniture is stored as parametric furniture definitions. The app includes a viewer, an editor, a walkthrough, versions,
share links, a cut list and an MCP server for AI agents. Accounts have the roles Admin or User and authenticate with JWT.
Users own projects, and admins see all of them. The architecture mirrors `../ukolio` (same author), and parts of it
(OAuth, lint configs) were ported from there. See `README.md` for the user-facing overview.

## Services

- `proxy/`: nginx. It sends `/api/*`, `/mcp`, OAuth discovery and Mercure to the backend and everything else to the
  frontend (port `PROXY_PORT`, default 4400). Locations are in `conf/locations.conf`. They are included by
  `http.conf.template`, or by `ssl.conf.template` when `PROXY_SSL_ENABLED=1` (set by `docker-compose.ssl.yml`, which is
  enabled via `COMPOSE_FILE` in `.env`; it serves `PROXY_PORT_SSL` and makes http redirect).
  `docker-entrypoint.d/10-select-template.sh` picks the template at start. The backend reads the scheme from
  `X-Forwarded-Proto`. The app's own location is `conf/app.conf` (included last), so a deployment with a website at
  the root swaps that file alone. `APP_PATH` (empty: the root; e.g. `/app`) is the path of the app's pages: the
  frontend is built with it as `<base href>` (`BASE_HREF` build argument) and the backend puts it into share links and
  the OAuth consent page's URL (`DiscoveryUrl::appUrl()`); the API, MCP, OAuth discovery and Mercure stay at the root.
  Frontend code is base-href-agnostic: assets and in-app links resolve against `document.baseURI`. The app's location
  includes `conf/app-headers.conf`: the security headers and a nonce-based CSP (`index.html`'s
  `ngCspNonce="random_nonce_value"`, replaced by `sub_filter` with `$request_id`, the header's nonce). **A script,
  style, font, frame or connection from another origin needs adding to that CSP.**
- `minio/` (a compose service only): S3-compatible storage for uploaded files.
- `backend/`: FrankenPHP worker, PHP 8.5, `marekskopal/orm`, `marekskopal/router`, MariaDB. Namespace `Izbik\`.
  - Routes are in `Izbik\Route\Routes`. The entrypoint runs `migration:run` before serving.
  - Every route needs a Bearer access token, except `AuthorizationMiddleware::OpenRoutes` and the guest share read (`ShareRoutePattern`).
  - Controllers get the user from `RequestService::getUser()`. For missing permissions they answer 403, not 401
    (a 401 makes the frontend refresh the token or sign out).
  - Request DTOs implement `ArrayFactoryInterface` and read fields via `BodyReader`.
  - `bin/console admin:create` (`make admin`) creates an admin, and `files:import` (`make import-files`) uploads a
    directory as project files. `storage:backup` / `storage:restore` (`StorageBackup`, through `FileStorageInterface`'s
    `keys()` / `fetch()`) copy every stored object with its content type into a directory and back, for `make backup` /
    `make restore` (with a `mariadb-dump` of the database; `backups/`, gitignored). `projects:export`
    (`make export-project PROJECT=… [WITHOUT_FILES=1]`, into `backups/`) writes one project as a project file
    (`Service/Project/Transfer`: `ProjectBundle` documents the format, `ProjectExporter` a zip with `project.json`, the
    files and the models, or the JSON alone); the zip needs PHP's `zip` extension. `projects:import <file> --owner=…`
    (`make import-project FILE=… EMAIL=…`) makes a new project of it (`ProjectImporter`, see Example projects).
  - Accounts on a self-hosted server: sign-up is closed unless `SIGN_UP_ENABLED` (`SignUpConfig`; closed, sign-up and a
    Google sign-in that would create an account answer 403, and the pages say to ask the admin; tests switch it with
    `AppHarness::signUp()`). Admins add accounts (`POST /api/admin/users`, verified) and set passwords (`PATCH` with
    `password`, not their own: it signs the user out and lifts a lock), `bin/console user:reset-password`
    (`make reset-password EMAIL=…`) does it from the server. `DELETE /api/current-user` (the password, or the email of a
    Google-only account) deletes an account with its projects and library (`AccountDeletion`: rows in one transaction,
    stored files after it, best effort; never the last admin), as the admin's delete does for an account without
    projects.
  - Sign-in extras: password reset by email (`PasswordResetService`, one-time `password_reset_tokens` hashed, an hour,
    capped per account; the reset unlocks and signs out everywhere), email verification (`EmailVerificationService`,
    `users.email_verified`, accounts from before it verified; a day's link mailed on sign-up and from the app's banner,
    `email_verification_tokens` keeping the address it went to, so an admin's email change, which unverifies, voids it;
    an unverified account may use the app) and Sign in with Google (`GoogleAuthService` checks the ID token at Google's
    tokeninfo; `users.google_id`, such an account may have no password; a Google sign-in links the account with the
    verified email, dropping the password and the tokens of an unverified one, which may have been signed up by
    someone else). `GET /api/authentication/options` tells the pages what is on: `GOOGLE_CLIENT_ID` and `SMTP_HOST`
    (`mail`). Mail is queued in `MailOutbox` and sent after the response, like the live updates, laid out by
    `AccountEmail`; tests record it in `RecordingMailer` and fake Google with `FakeGoogleAuthService`; `Fixture` users
    are verified.
- `frontend/`: Angular 22 (standalone, signals, zoneless), component prefix `fd`, SCSS tokens in
  `src/styles/_variables.scss`.
  - three.js code lives in `src/app/viewer/engine/` and must stay framework-free (no Angular imports).
  - Routes are in `app.routes.ts`: lazy pages under `LayoutComponent`, guarded by `authGuard` / `guestGuard` /
    `adminGuard`.
  - `jwtInterceptor` adds the token and refreshes it on 401. `errorInterceptor` shows a toast for API errors; skip it
    with the `SkipErrorToast` context token.

## Translations (I-120)

- The application is in English (the default and fallback) and Czech. The frontend uses `@ngx-translate`: the texts
  are `frontend/src/i18n/{en,cs}.json` (nested keys by area, `common.language`), served as `i18n/` next to the app
  (resolved against `document.baseURI`, `no-cache` in `conf/default.conf`). **A new user-facing text goes into both
  files** (`TranslatePipe` in templates, `TranslateService` in code); `src/i18n/i18n.spec.ts` checks they have the same
  keys and placeholders. Specs that render texts take `provideTestTranslations()` (`src/test-translations.ts`, the real
  files, English). Counts go through `PluralPipe` / `pluralText()` (`shared/i18n`: keys `<key>.one|few|other` in both
  languages, by `Intl.PluralRules`), dates through `DateTextPipe` (`dateText: 'date' | 'dateTime' | 'dayTime' |
  'time'`, each language's own format), never Angular's `date` with an English pattern. A route's `title` is a key
  (`titles.*`, `TranslatedTitleStrategy`). API requests carry the language shown as `Accept-Language`
  (`languageInterceptor`), so the server's messages come in it; `errorInterceptor` gives an error without one a
  translated `errors.unreachable` / `errors.unexpected`. The inspector's `ElementFields` hold keys (`label`, `section`:
  `fields.*`), its hints and parse errors are `FieldText` (`{key, params?, count?}`, `translateFieldText()`); element
  kinds are `KindLabelKeys` (`kinds.*`) and `elementFacts()` takes the `TranslateService`. Default names the editor
  sends (a new room, a group, a variant set) are translated (`editor.defaults.*`).
- Option lists are values named by `enums.<enum>.<value>`: a select field's `enum` in `ElementFields` (every select has
  one), `OpeningTypes`, `FurnitureCatalogues`, the electrical categories (the drawings still take `CategoryLabels`), item
  kinds (`enums.itemKind`, with `enums.itemKindHint` for the Add library). **A new enum value needs its two texts**
  (`src/i18n/enums.spec.ts`). Seeded materials are named by `materials.<code>`, put in where materials load
  (`localizeMaterials()`: the scene, a shared view, the catalogue; one an admin adds keeps its own name);
  `backend/tests/fixtures/material-codes.json` lists the seeded codes (`SeededMaterialCodesTest`), so **a migration
  seeding a material adds its code there and its names to both files**.
- Furniture definitions carry their own texts: an optional `translations` (`{cs: {name, description, parameters,
  options, slots, parts}}`, parameters by key or `list.field`, options by select, parts by English name; checked by
  `DefinitionTranslations`, not read by the evaluators). The editor and the libraries show definitions through
  `localizeDefinition()` (labels, `optionLabels` for selects; the parts stay, the furniture editor edits the stored
  JSON), a new Custom item takes the name shown, and the cut list names the parts in the `Accept-Language` language
  (`CutListService` with a locale; its materials the page names from the catalogue by id). The seeds have Czech
  (`AddFurnitureTranslations`): **a seed's new parameter, option, slot or part needs its Czech text too.** The electrical
  schedule names definitions (and "Not in a room") in the `Accept-Language` language too.
- Numbers written by hand follow the locale's decimal separator (`decimalSeparator()`, `fixed()` in
  `core/number-format.ts`; `levelText(mm, decimal)`): the schedules, the inspector's facts and hints (`FieldDecimal`),
  the 3D room labels (`ViewerEngine.setDecimalSeparator()`). The sheets take a `SheetTexts`
  (`viewer/engine/sheet-texts.ts`: every word they write, the separator and the date format; `EnglishSheetTexts` the
  default, so the engine stays framework-free) that `ExportComponent` builds from `sheets.*`; English sheets keep ISO
  dates. **A new text on a sheet goes into `SheetTexts`**, never as a literal in the engine.
- Example projects may carry `translations` (`{cs: {name, description, credit}}`, written by hand into
  `backend/examples/<slug>.json`; `examples:export` keeps them): the list and a new project from one take the
  `Accept-Language` language. The shared viewer (guests) is in the browser's language, its shortcuts panel has the
  language switch (`LanguageSwitchComponent` with `reload`).
- Guards: `src/i18n/hard-coded-texts.spec.ts` parses every template (inline ones too) and fails on a text node or a
  static `title` / `placeholder` / `aria-label` / `alt` with words of its own (key names, units and the brand allowed);
  ESLint forbids literal texts in `alerts.*()` and `confirm()`. `make smoke` switches an account to Czech and back.
- The language of a page load (`core/locale.ts`: `Locale`, `initialLocale()`: the device's `izbik.language`, else the
  browser's by its primary subtag, else English) is `LOCALE_ID` (`cs` registered), so dates and numbers follow it, and
  its texts load before the first render (`LanguageService.init()` in an app initializer). Signed in, the account's
  `locale` wins: when it differs from the page's, `LanguageService.reloadInto()` remembers it on the device and reloads
  (once; a session guard stops a loop), as the account page does after changing it. Pages without an account show
  `LanguageSwitchComponent` (the device's choice, live, hidden while signed in).
- Backend: `users.locale` (`LocaleEnum`: en, cs; `UserDto`, `PATCH /api/current-user`), set at sign-up and by a
  Google sign-in creating the account from the browser's language (`LocaleEnum::fromBrowser()`), by an admin's
  `POST /api/admin/users` from the admin's. MCP tool descriptions and agent-facing texts stay English. The backend's own
  texts (the account mail, `AccountEmail` by `mail.<kind>.*`; default names it writes, a "Shared: …" version, a new
  variant's name) are `backend/translations/{en,cs}.json` read by `Translator` (dotted keys, `{name}` placeholders,
  English fallback; `TranslationFilesTest` keeps the keys alike), in the user's language for a person and English for
  an agent (`ActorLocale`). Names the frontend sends (a new room, a group) are translated there.
- API messages are thrown in English (agents read them so) and translated on the way out: `LocalizedErrorHandler`
  (around the router) puts an error response's `message`, and the `warnings` of a posted import, into the
  `Accept-Language` language by `ErrorTranslator`, which looks the English message, or the `sprintf` format it came from
  (`Wall %d is not on this floor.`), up in `translations/errors.<locale>.json` (the same placeholders in the same order;
  a filled-in part that is a message of its own is translated too; an unknown message stays English). MCP is left
  alone. **A new user-facing message needs its translation**: `ErrorCatalogueTest` scans `src/` (MCP aside) for thrown
  and answered messages, sentence constants and `warnings[]`, and fails on one missing from the catalogue or an entry
  no longer thrown (`Internal` lists the 5xx ones nobody reads).

## Domain conventions

- All stored lengths are integer **millimetres** in plan coordinates. The origin is the inner south-west corner of the
  ground floor, x points east and y points north; all floors of a project share it, so they stack. The viewer converts
  with `MM` (0.001) and maps plan y to scene −z.
- Stacked floors (I-36): a floor's `level` orders it, `elevation` is its
  finished floor level above the project datum ±0.000 and `slabThickness` the slab under it (the viewer's slab). A new
  floor without them stands on the tallest wall of the floor below plus its slab, or hangs under the one above
  (`FloorStack`; 300 mm slabs at level 0 and below, 250 above); snapshots from before them are stacked the same way. The
  sheets, the schedules and the project page write the level as `levelText()` in `core/level-text.ts` (`+3.000`). REST `…/floors`, MCP
  `add_floor` / `update_floor` / `delete_floor`.
- Ghost of the floor below (I-71): the viewer page loads the scene of the floor with the highest level under the shown
  one (from the same version) while its Display toggle "Floor below" is on (by default in edit mode), and reloads it
  with live updates. `ViewerEngine.setGhost()` draws its wall outlines and dashed room outlines over the plan without a
  depth test, only while `isTopDown()` (`viewer/engine/ghost.ts`, not pickable). Draw walls and the measure tool snap
  to its wall ends and centre lines (`ghostWalls` of `snapDrawPoint()` / `snapMeasurePoint()`; wall drags too).
- All floors (I-72): the Display toggle loads the scenes of every floor under the shown one, and
  `ViewerEngine.setStack()` draws them below it (`viewer/engine/stack.ts`, `buildContextFloor()`: the same builders,
  lifted by `floorOffset()`, outside the pickable `content` and tagged `userData.contextFloor`; ceilings shown, doors as
  outside a walk, their fixture lights off). The shown floor stays at y = 0 and editable, cut by the wall cut as before;
  the floors above are left out, so cutting at another floor means showing it. Switching floors keeps the view as it
  was turned (only the first floor shown starts at the overview); with the stack on, the camera moves with the level. The framing, the sun's shadow and the ground disc fit all layers
  (`floorPoints()`, `stackGround()`); the model and picture libraries keep the stack's assets. A slab lies under its
  floor's rooms (not gardens, paths, driveways) and wall footprints, extruded and merged (`buildSlab()`), so an upper
  slab roofs only what it covers. Saved views and share links keep it as `allFloors`; a share link with it serves the
  floors below in `SharedViewDto::$stack` and, when it offers the walk too, those above in `$above` (for the walk,
  I-102; `ProjectSnapshotService::getFloorScenes()` with `$withAbove`, `SnapshotScenes`) and their models and pictures
  (`SharedAssetIds::others()`).
  The glTF export holds the shown floor only.
- Item rotation is in degrees counter-clockwise; 0 means the front faces south (−y). Cabinet-local frame: x along the
  width, y up, +z out of the front.
- Walls are centre lines with a thickness. Openings are offsets along the centre line from the wall start. `hinge` is
  Start or End of the opening; `side` is Left or Right of the wall direction.
- Curved walls (I-63): `walls.sagitta` (mm from the chord's midpoint to the arc, + to the left of start → end, 0
  straight) makes the centre line an arc of at most a semicircle, its inner face at least 100 mm round (`WallCurve`).
  The geometry twins are `WallLine` (PHP) and `wallFrame()` / `pointOnWall()` / `alongWall()` / `tangentAt()` (TS):
  lengths and opening offsets run along the arc, `across` is radial. Footprints, meshes and room outlines stand in for
  the arc by `arcSegments()` straight pieces (`wallSamples()` / `wallOutline()`, identical in both twins, as room
  detection needs the same vertices). Mitres of a curved wall meet the other face along its circle (`Intersections`).
  Doors and windows stand straight on the tangent at their middle (`openingFrame()`), so they may stand off the arc by
  a quarter of the thickness at most (`OpeningPlacement::fitsCurve()` / `openingFitsCurve()`, sliding leaves'
  travel included); niches follow the curve. Turning a wall round negates its sagitta (as the face finishes swap).
  Corner windows need straight walls.
- Opening types (`OpeningTypeEnum`): `Door`, `DoubleDoor`, `SlidingDoor`, `PocketDoor`, `Passage` (no leaf),
  `Window`, `Niche` and `GarageDoor`. A swing door hangs on `hinge` and swings into `side`. For a double door, `leafWidth` is the leaf on
  `hinge`. Sliding and pocket doors slide open towards `hinge`; a sliding door hangs on the `side` face.
  `OpeningPlacement` keeps the wall beside them free for the open leaf (both the handler and `WallHandler` check it)
  and makes pocket doors need a wall at least 100 mm thick. Doors also have a `frameStyle` and a `handle` (null means
  the type's default). Doors with a leaf have a `leafStyle` (Flush, Panelled, Glazed) with `leafPanels` panels or
  panes (`leafPanels()` in `viewer/engine/opening-looks.ts`). These rules, and the window ones below, are in
  `OpeningLook`.
- Room doors hang on hinges (I-105, I-109; TS only but the `Steel` frame style): a `Door` / `DoubleDoor` with a
  `Lining`, `Architrave` or `Steel` frame is rebated (`doorRebate()` in `plan-geometry.ts`): each jamb's stop reaches
  `stop` in (a single door's `(width − leafWidth) / 2`, a double door's `DoorFrameWidth`), the leaf's core closes
  `CoreGap` short of it and its `LipOverlap` lip (`LipThickness` of the swing face, `Leaf.lip`) lies in the rebate,
  flush with the frame's front (the swing-side casing of an `Architrave`). A `Steel` frame (`steelFrame()`) is that
  profile in `SteelSheet` sheet, its face strips `SteelReturn` onto the wall faces. A double door's leaf on `hinge` laps
  over the other, which opens after it and closes before it (`Leaf.lag`, `PassiveLag`, read through `leafOpen()` by
  `setLeafPose()` and `leafCollider()`). A swinging leaf or sash turns about `Leaf.pin` (the hinge axis):
  `leafPivot()` puts the pivot on it and the parts in its `body`, and `swungLeaf()` turns the walk's colliders and the
  plan's symbol the same way. `Leaf.hinges` says what `leafHinges()` draws on it (the moving parts in the pivot, the
  rest with the frame): `Barrel` two-part barrel hinges at `hingeHeights()` (a third over `ThirdHingeAbove`; the axis
  `HingeBarrelRadius` in front of the face, a rebated leaf's `LipGap` off the lip's edge; an unrebated or bare-hole
  door's shank reaching the jamb or reveal), `Concealed` bodies in the edge and jamb of a `Flush` frame's leaf (which
  turns about its swing face's hinge edge), `Strap` strap hinges of a side-hinged garage door (its axis the reveal's
  corner on the garage face) and `Sash` a walkable French window's corner covers (its axis `SashHingeOut` in front of
  the frame's face). A single rebated door also gets a lock faceplate and a strike plate. The elevations mark the
  hinges on the face the leaves swing to. Sliding, pocket and lifting garage doors have no hinges.
- A niche is a recess `depth` deep (default half the wall) into its `side` face, leaving at least 30 mm of wall
  behind it (`OpeningPlacement::nicheDepth()`, also checked when the wall changes). It is neither a door nor a gap
  for the walkthrough. `wallPieces()` gives the piece behind it a `recess`; `buildWall()` draws it thinner and tags it
  with the niche's `openingId`. The wall face twins (`WallFaceSpans` / `wallGapsAlongEdge()`) count a niche as an
  opening of its own face only, flagged `niche` (dimension chains skip it). Niches from both faces may share a
  stretch of wall while 30 mm stays between them (`OpeningPlacement::nichesFit()`, also checked by `WallHandler`). A wall offset is a full-height niche, a `Column` against the face, or collinear walls
  of different thickness (room detection steps the outline; fixture cases `stepped` / `flushOuterFace`).
- Windows have `mullions` (vertical bars; 1, two panes, is the default) and `transoms`, plus an optional `sillBoard`
  (the inner board's reach past the wall face on `side`, the interior; exterior walls also get an outer sill). A
  walkable window has one sash on `hinge` (mullions 0) or two. Corner windows have no stored field:
  `cornerWindows()` finds two windows that reach the shared end of an L-joint at overlapping heights, and
  `buildWall()` drops their corner jambs. The wall pieces already leave such a corner open.
- A window may have a solid lower `panelHeight` (mm from the sill) and exterior `shutters` (Louvred or Solid, in
  `shutterMaterialId`, null for painted timber). They are two leaves on the face opposite `side`. They lie folded back
  against the wall beside the window unless `shuttersClosed`. Shutters do not take part in the walkthrough.
- Opening looks (I-89, `OpeningLook`; inset and headroom in `OpeningClearances`): `frameMaterialId` (frame and sashes;
  null: `door-white` doors, `window-frame-anthracite` windows), `leafMaterialId` (null: the frame's) and a window's or
  lift-and-slide door's `outsideFrameMaterialId` (a 15 mm outer face, `frameBar()`). Leaf style `Boarded` is
  `leafPanels` boards (up to 24) running `boardDirection` (`boardedLeaf()`); a leaf restyled without a new count takes
  the new style's default. `inset` sets the frame of a door, window or lift-and-slide door (`OpeningClearances::framed()`)
  that far in from the outer face (opposite `side`), `InsetFrameDepth` (80, a lift-and-slide door's `LiftSlideFrameDepth`,
  130) deep: `frameBand()` in `plan-geometry.ts` gives the frame's centre and depth across the wall, which the frame
  bars, leaves, sashes, sill boards, corner windows (`cornerReach()`, assuming both frames set in alike) and the plan's
  glazing follow. A `SlidingDoor` with `fixedPanes` (1–3) is a lift-and-slide door inside its opening (`isLiftSlide()`,
  `liftSlidePanes()`): equal panes on two tracks, the one away from `hinge` sliding a pane's width towards it, the fixed
  ones leaves with no travel (they block the walk), so `occupied()` / `occupiedSpan()` take no wall beside it. A
  `GarageDoor` (`garageDoorStyle`: Sectional, the default, UpAndOver, SideHinged, Roller) hangs behind its opening on
  the `side` face, the garage's, with `frameStyle` None; all but the side-hinged one (a double door's leaves) need
  `GarageHeadroom` (200 mm) above their head under the wall's top (`OpeningHead::check()` takes it, when the door is
  placed or restyled, and `WallHandler` when the wall changes). Their leaf has `motion: 'lift'`
  (`garageLeaves()`): `buildLiftingDoor()` tags its sections (`section`), panel (`tilt`) or slats (`roll`), and
  `setLeafPose()` runs them along the track (`trackPoint()`), tilts or rolls them. Garage doors show closed outside a
  walk, block their opening until `LiftClear` open, draw a dashed overhead outline on the plan and their sections or
  slats in the elevations (`garageSections()` / `rollerSlats()` and `RollerDrum` in `plan-geometry.ts`, shared by the
  builder and the sheets). New room type `Garage` (`isAncillary()` / `isAncillaryRoom()`): indoor, summed apart from
  the living area in the room schedules (`ancillary` totals), a new one on `concrete-floor`.
- Hierarchy: `Project` → `Floor` → rooms / walls / items. Access goes through `ProjectProvider::findAccessible()`, which
  allows the owner or an admin and answers 404 otherwise.
- Foreign keys cascade on delete (project → floors → content), so deletes go through the parent row.
- Versions: the floors are the working copy. `ProjectVersion::$snapshot` is JSON from `ProjectSnapshotService`, which
  documents the format. **A new floor, room, wall or item field must be added to the snapshot restore too.**

## Editing

- Every change of a floor element goes through `Service\Editor\SceneEditor`, from REST controllers and MCP tools alike.
  It bumps `projects.revision` (optimistic lock via `If-Match`), validates through the type's `ElementHandler` and logs
  a `ProjectChange` whose before/after values are the element's scene JSON.
- **A new element field must be added to its handler's `fields()` / `normalize()` / `apply()` and to the snapshot
  restore.**
- `UndoService` reverts only the current actor's own changes (`ActorContext`: a person or an MCP client), and only
  while the element is unchanged since. It writes back the handler's `restorableFields()`: the fields plus derived
  state. For example, a wall's `openingOffsets` is included because moving the wall's start shifts the offsets, and
  doors and windows have to stay in place on the plan.
- Items, walls, openings and rooms are soft-deleted (`deletedAt`). The repositories' `findByFloor` / `findByWalls`
  skip deleted rows.
- Item groups (`ItemGroup`, `items.group_id`): one level deep, and a group may be empty. Membership is the item field
  `groupId`. `GroupEditor` (group, move, copy, ungroup, delete) changes several elements under one revision as a
  `ChangeBatch` built with `BatchWriter` (`project_changes.batch`; the first change carries the operation's summary).
  `UndoService` reverts a batch as a whole (undo runs backwards) and closes all of it on a conflict.
  `EditResultDto::$batch` lists every change, and `scene-patch.ts` applies them in order. In the viewer, clicking a
  grouped item selects the group (Alt or double-click selects the item), and Shift+click picks items to group.
- Variants (`VariantSet`, `item_groups.variant_set_id`): the alternatives of a piece of furniture are groups of one
  set, and only `activeGroupId` is shown (this applies to items only; shared items stay outside the set).
  `VariantEditor` creates a set, adds a variant (a copy in place or an empty one), switches variants (a logged change
  that is also allowed outside edit mode) and decides a set (deletion keeps the active variant). The active variant
  cannot leave its set, and deleting it activates another. The client hides the other variants' items with
  `hiddenItemIds()` in `viewer/engine/variants.ts`; its PHP twin `SceneDto::hiddenItemIds()` does the same for MCP. The
  sidebar's `VariantsComponent` switches variants.
- Editor UI (`app/editor`):
  - `EditorStore` is provided per viewer page. It holds the selection and edit mode and makes the API calls, sending
    the scene revision as `If-Match`. `scene-patch.ts` patches the returned element into the scene and falls back to a
    reload.
  - Layout of the viewer page (mockup `docs/ui/viewer-layout.html`): the 3D view fills it and the controls float over
    it: a rail of panels on the left (one open at a time: rooms, saved views, variants, comments, export; activity and
    the underlay while editing), the floor menu, 3D / Plan / Walk, Share and Edit on top, the tools in a dock at the
    bottom (drawing tools, measure, comment, Add, the wall cut, the Display popover), a level picker for the floors, and
    the selection on the right (viewing, a card: a room's area and finishes, else `elementFacts()` of
    `editor/element-kinds.ts`). Icons are `fd-icon` (`shared/icon`, Lucide-style paths; add one to `Icons`). The shared
    viewer uses the same layout and stylesheet (its rail: comments, variants, export). On phones (≤ 640 px) the top bar
    keeps icons, the panels and the inspector are bottom sheets (a selection closes the panel), and the Display popover
    takes the wall cut.
  - Help in the rail: the first-steps guide (`FirstStepsComponent`, steps from `firstSteps()`: walls, rooms, a door,
    furniture, a walk, each ticked off from the scene; it opens by itself on an empty floor, once per project,
    `izbik.firstStepsShown` in localStorage; a step's button starts its tool and closes the guide) and the shortcuts
    (`ShortcutsComponent`, key `?`, the shared viewer's too). **A new shortcut goes into `ShortcutsComponent`.**
  - Without WebGL 2 (`webglAvailable()` in `viewer/engine/webgl.ts`) `ViewerComponent` and the furniture preview make
    no engine and show `WebglMessageComponent`; a lost context shows it with a Reload button.
  - `EditorPanelComponent` is the inspector panel (shown with a selection) and holds the editing shortcuts;
    `AddLibraryComponent` is the Add library (categories, search), `WallDraftComponent` the Draw walls options.
  - `InspectorComponent` is driven by `ElementFields`; add a field there to make it editable. A field's `section` starts
    a collapsible group (`folded` starts it closed). Materials are picked with swatches (`MaterialPickerComponent`),
    finish bands edited as rows (`finishes`, `FinishBandsComponent`: a wall face's null / own / `[]`), outlines and
    railing points as a table of corners (`points`, `PointsComponent`, `minPoints`, `openRun`). `when` limits a field
    to certain elements, e.g. the `custom` form of Custom items.
- Engine picking: builders tag objects with `itemId` / `openingId` / `wallId` / `roomId` in `userData`
  (`selection.ts`). In edit mode with the camera looking down, items, walls and openings can be dragged, and the
  selected wall's ends and middle (which bends it, `bendWall()`) or room's corners get handles (in an overlay scene
  drawn above the section cut). One drag results in one `onEdit` call and one change.
- Drawing tools (`EditorStore.tool`, `viewer/engine/drawing.ts`, `DrawHudComponent`): in the top view, Draw walls
  clicks a run of centre-line points (snapping to wall ends, onto centre lines for T-joints, axes and 15° steps; a
  typed `3200` or `3200<90` places an exact segment, `~400` curves it). In arc mode (key A) each placed segment waits
  for a click (or a typed value) setting its curve (`sagittaToward()`). `WallDrawer` (`POST …/walls/polyline` with
  `sagittas`, one per wall; MCP `add_walls`) adds the run as one `ChangeBatch`. Room from walls (`RoomOutliner`, `POST …/rooms/detect`, MCP `detect_room` /
  `find_room_outline`) outlines the inner wall faces around a point. The detection exists twice, `RoomDetector`
  (`Service/Editor/Geometry`) and `room-detect.ts`, kept identical by `backend/tests/fixtures/room-detection.json`.
  **Always change both twins and add a fixture case in the same change.**
- `ViewerEngine.load()` of the same floor diffs the scene and rebuilds only the changed items, walls and rooms, plus
  the mitres of neighbouring walls (`wallJoints()`).

## Furniture definitions

The current format is the text in `FurnitureTools::Format`.

- A definition describes custom furniture as data: parameters and parts whose sizes are expressions.
- The expression language and the definition evaluator exist twice: in PHP (`Service/Furniture`) and in TS
  (`viewer/engine/custom`). The shared fixtures `backend/tests/fixtures/furniture-{expressions,definitions}.json` keep
  them identical. **Always change both twins and add a fixture case in the same change.**
- A definition's optional `catalogue` (`DefinitionCatalogueEnum`: kitchen, storage, seating, tables, bedroom, bathroom,
  lighting, electrical, decor, building, outdoor, roof; after `format`, `category` and `schedule` in the JSON) files it
  in the editor's Add library (none: Other; a tagged one without it under lighting or electrical); the evaluators do
  not read it. The furniture editor picks it above the JSON; the seed files carry theirs (`AddFurnitureCatalogue`).
- `FurnitureDefinition` rows (`/api/furniture-definitions`, soft-deleted) are global (`user` / `project` null, only
  admins write them), part of a user's library, or a project's own. The access rules are in
  `FurnitureDefinitionProvider`.
- `DefinitionValidator` checks what the evaluator does not read (unknown keys, `size`, `PartTypeEnum` options, material
  codes). It stores the JSON with empty objects kept as `{}`.
- A `Custom` item has `items.definition_id` (a live link) and `params` of the form `{values, materials}`.
  `CustomItemValidator` evaluates the item at its size only when its kind, definition, size or params change, so a
  later definition edit does not block moving the item.
- An item may use global definitions, the project owner's library and the project's own definitions
  (`FurnitureDefinitionProvider::isUsableIn`). Deleted definitions are still accepted.
- The scene carries `definitions` (the ones its items use), so versions embed them. A restore links the live
  definition with that id, or recreates it as a project definition (`SnapshotDefinitionRestorer`).
- Rendering: `viewer/engine/custom/custom.ts` builds one mesh per part (`userData.partName`). When a definition does not
  evaluate, the item is a red placeholder with `userData.definitionError`. An item is rebuilt when its definition's
  `updatedAt` changes. A material slot without a default takes the item material, as the first slot does.
- Seeding: global definitions come from `backend/migrations/furniture/*.json`. The seed migrations list their files,
  and `FurnitureSeeds` reads them. Both test suites build them, and `custom/parity.spec.ts` checks the sample project's
  definitions (kitchen cabinets, worktop, backsplash, wardrobe, chair, table, panel) against what the removed built-in
  kinds drew (`custom/parity-reference.json`, recorded before they went).
- Slanted parts (I-57): every part may `turn` (degrees counter-clockwise seen from above, ±90) about a vertical line at
  `turnX` / `turnZ` (default its back-left edge; moved with an assembly like the tilt pivot), after its `tilt`, and the
  expressions have `sqrt`, `hypot`, `sin` / `cos` / `tan` (degrees) and `atan2` (giving degrees). The global `Trapezoid
  wardrobe` (`SeedTrapezoidWardrobeMigration`) is built on them: a slanted end panel turned along the diagonal, the
  horizontal boards tapered with `backWidth` / `taper`. Plans and elevations still draw the item's box (I-103).
- Per-copy variety: `hash(n, seed?)` gives a fixed pseudo-random 0 ≤ x < 1 (32-bit integer mixing, identical in both
  twins), and a part's `material` may be `{"expr": …}` choosing a slot per copy, e.g. book spines.
- Pictures: a parameter of type `image` (no default) holds the id of an image file of the item's project, and a
  `picture` part shows it on its front face through its `image` option (cropped to fill, `coverUvs()`).
  `CustomItemValidator` checks the file (`ImageParameters`). The scene lists the files in `pictures`, so share guests
  may load them (`SharePictureContent`). The viewer loads them through `PictureLibrary` (`viewer/engine/pictures.ts`,
  the pages' `pictureFetcher`) and rebuilds the items once an image has loaded.
- The global catalogue also has a sofa, a bed with bedding, a bedside table, a round table, a walk-in shower screen,
  a round mirror and a balcony railing (`SeedFurnitureCatalogueMigration`), and decor (`SeedDecorCatalogueMigration`):
  a framed picture (it can lean against the wall), rugs, books, curtains, roller and venetian blinds and a scatter
  cushion. Curtains and blinds are furniture placed at a window, not fields of the opening. Upholstery uses the `cushion` part, a
  box with rounded edges (`radius`), which the cut list counts among the other parts. Round tops and mirrors are
  `cylinder` parts.
- The demo house's catalogue (`SeedDemoHouseFurnitureMigration`, I-93): a corner fireplace, a bench with an upholstered
  back, a pouf, linear pendant, square ceiling and linear wall lights, a vanity with vessel basins, a walk-in rain shower,
  a freestanding bath, a bin store, a garden shed, stepping slabs and a roller blind in a cassette; a `cylinder` part's
  `radius` rounds the edges of its ends, and a `block` part is a solid box not cut from sheet (stone, concrete, metal,
  a solid bar, fabric), which the cut list counts among the other parts. The kitchen cabinets' `plinthVent` adds a plinth grille; a migration that
  changes an earlier seed file rewrites its rows with `FurnitureSeeds::update()`. Their models and stand-ins are listed in
  `docs/demo-house.md`.
- Removed built-in furniture kinds (`BaseCabinet`, `WallCabinet`, `TallCabinet`, `Worktop`, `Backsplash`, `Wardrobe`,
  `Chair`, `Table`): `ConvertFlat50FurnitureToDefinitions` turned the sample project's into Custom items in place
  (same ids), then `ConvertBuiltInFurniture` the rest in every project, the saved versions and the change log, and
  `RemoveBuiltInFurnitureKinds` dropped them from `items.kind`. `BuiltInFurnitureConverter` (params to values) and
  `BuiltInItemConverter` (scene JSON) are kept only for these migrations.
- UI: the Add library's Furniture, Lights & sockets and My furniture categories; the inspector's `definition` / `custom` fields (`CustomParamsComponent`
  generates a form from the definition's parameters and slots; `EditorStore.definitions`); `/furniture` (the library,
  `?projectId=`); and `/furniture/:id` (a JSON editor with `FurniturePreview`, a framework-free one-item scene in
  `viewer/engine/custom/preview.ts`).
- Saving a definition publishes `definitionChanged` (`ProjectUpdatePublisher::markDefinitionChanged`, with no
  revision bump) to the projects that have items of it, and the viewer reloads the scene.
- Cut list: `CutList\CutListService` (REST `…/cut-list`, MCP `get_cut_list`, page `/projects/:id/cut-list`) evaluates
  the shown Custom items. Board and front parts are listed by material, thickness, length along the grain, width and
  banded edges; other parts are listed separately, with area and banding totals per material.

## Openable fronts (I-106)

- A `front` part opens in the viewer by `opens`: `swing` (a door's default, on its `hinge` edge, default the side away
  from `handleSide`, on `hingeType` hinges, `angle` default 110 concealed / 105 butt), `slide` (a drawer's default,
  `travel` mm out, default ¾ of its reach into the item), `lift` (a flap turning up about its top edge), `fold` (a
  bi-fold flap folding `foldAt` below its top, at most half way; the cut list lists its two panels, `FoldingFront`, twin
  `foldPanels()`), `parallel` (rising `travel` upright, default its height + 10) or `none`; `softClose` damps the
  closing. They change the drawing only (`PartTypeEnum::Motion`).
- An `assembly` with `opens` (not `fold`) moves all its parts as one leaf, e.g. a drawer front with its drawer box: the
  evaluator twins give each part `props.leaf` (`{key, box, …the assembly's options}`, `MovingLeaf` / `leafKey()`, the
  key the entry paths with copy indices), and a part inside one may not open on its own (`opens` other than `none`;
  fixture cases). A front too low to fold in two lifts whole.
- Viewer (`viewer/engine/custom/fronts.ts`, framework-free): `buildCustomItemBody()` puts each front (keyed by its leaf
  key, else `front:<n>`) in a group tagged `front` (its `FrontSpec`, so `mergeStatic()` merges it on its own) with
  pivots tagged `frontPivot` (`main`, or a bi-fold's `upper` / `lower`) on the motion's axis; `setFrontPose()` poses
  them. `FrontStates` (session only, by item id and key, kept across rebuilds; with every front open, fronts of items
  built later open too) moves each front with a speed: opening speeds up and brakes, closing bangs shut with a small
  rebound, or with `softClose` slows exponentially over the last 50 mm / 12° (`advance()`).
- `ViewerEngine`: the tick advances them (`refreshShadows()`); E, the centre click and a tap in the walk use the door or
  front in reach (`usableAt()`, `DoorAim.target`; fronts of the shown floor only); in view mode a click on a front of
  the selected item (or of the selected group's) toggles it; `setFrontsOpen()` (the Display toggle "Open fronts", not kept by views or share links),
  `itemFrontsOpen()` / `setItemFrontsOpen()` (the selection card's button, for an item or a group). The furniture editor's preview toggles a
  front by a click and has Open fronts. Plans and elevations draw the closed box; the glTF export the current pose.
- Hinges (I-128, `viewer/engine/custom/hinges.ts`, framework-free, TS only): a swinging door or assembly hangs on
  `hingeType` `concealed` (the default) or `butt` hinges (an assembly's own, else its front's), at the box of its door
  (`FrontSpec.door`, `leafDoors()`: an assembly's first front, else its part reaching furthest out). A concealed hinge
  is a four-bar linkage (`ArmPivots` on the arm's nose, `CupPivots` in the cup, tuned so a full-overlay door 16–22 mm
  thick never stands out past the side nor back into the carcass): `concealedOffset()` (solved by continuation, a
  table per half degree) carries the door forward and in over the side as it turns. It opens at most
  `ConcealedMaxAngle` (155, a wide-angle hinge): `PartChecker` refuses a literal angle over it, the viewer caps an
  evaluated one. A butt hinge turns the door about its back hinge edge. `buildFrontHinges()` draws
  `frontHingeHeights()` hinges (2–5 by the door's height, 100 mm from its ends), meshes named `Hinge` on shared
  geometries: a concealed one's cup in the pivot, its plate, arm and nose on the inner face of the side behind the
  hinge edge (`sideInner()` in `custom.ts`: an upright board, not turned or tilted) and its two links in the front's
  group tagged `frontLink`, posed by `setFrontPose()` (`poseHingeLinks()`) and hidden while closed; a butt one's
  barrel and leaves, half on the door, half on the carcass. Without a side there, only the door's half is drawn. The
  integrated soft-close damper is not drawn. `AddFurnitureHinges` gives the bin store and garden shed butt hinges.
- Kitchen lighting and seating (I-137, `SeedKitchenLightingAndSeating`): a pendant with a glass shade (globe as a
  round `cushion`, dome or drum as a `cylinder`, a glowing bulb inside, the bare-bulb pendant's cable), a track light
  (spots as `light` parts of kind spot, the schedule counting `spots`) and a bar stool (`seatHeight`, `back`,
  footrest). Plinth and cove LEDs are `LedStrip` items, a fluted pedestal table the round table in a fluted material.
  Light glass draws at a fixed low opacity, so a smoked shade reads only faintly tinted.
- Kitchen appliances (I-136, `SeedKitchenAppliances`): definitions built of the existing part types, no branded
  models: a mixer tap (gooseneck, straight, pull-out), a range hood (chimney, box, integrated strip), a hob (induction,
  gas with grates, downdraft vent) and a sink (inset, undermount, farmhouse apron; 1–2 bowls, drainer) laid on the
  worktop (z = its top; cut-outs are not drawn), a freestanding fridge (modern, retro rounded, American), a coffee
  machine, a range cooker (glazed oven doors, fixed) and a wine cooler. Built-in ovens and microwaves stay the cabinets'
  `appliance` fronts; an integrated dishwasher is a cabinet door.
- Kitchen cabinet options (I-135, `AddKitchenCabinetOptions`): the base cabinet's `sinkUnit` (no top, no shelves),
  the tall cabinet's `interior` drawers (internal drawers behind its doors, a larder), `plinthFinish` own (the plinth in
  the `plinth` slot, by a `material` expression), the wall cabinet's `shelfLight` and the open shelf's `light` (`led`
  parts under the shelves). End panels, fillers, cornices and bridging boxes are the Panel and the cabinets at any size;
  an L-corner is a blind-corner base beside the next one.
- Kitchen worktops (I-134, `SeedKitchenWorktops`): the island worktop (its box from the floor, the top `thickness` at
  its height, `leftEnd` / `rightEnd` square or waterfall, the ends turning down to the floor in the same material), the
  L-shaped worktop (a run `runDepth` along the back and a return `returnWidth` on `returnSide`, two pieces in the cut
  list), the worktop's `roundEnd` (a half-cylinder of its depth, the rest of the slab shortened) and the backsplash's
  `ledge` (standing out in front of its box). Sink and hob cut-outs and stepped splashbacks are not drawn yet.
- Front styles and handles (I-133, `AddKitchenFrontStyles`): a `front`'s `style` (`PartTypeEnum::FrontStyles`: flat,
  shaker, fluted, glass, reeded) and `handle` (`Handles`: horizontal and vertical bars with `handleLength`, knob, edge
  pull, gola channel, push, none) are drawn by `buildFrontBody()` (`custom/front-styles.ts`) and `buildFrontHandle()`
  (`kitchen.ts`); the evaluators only pass them on. The frame is `frame` mm (0: the style's own, `frontFrame()` /
  `FramedFront::width()` twins) in the `frameMaterial` slot, the handle in the `handleMaterial` slot; such a slot the item
  does not fill and without a default keeps the front's material or the black handle (the parity test pins it). The cut
  list cuts a glass front, or a shaker one with its own frame material, as stiles, rails and a pane or recessed panel
  (`FramedFront`). The kitchen cabinets take `frontStyle` (and per front `style`), `frame`, `handleLength` and the slots
  `frames` and `handles`.
- Seeds (`AddOpenableFrontsMigration`, `down()` writes back `furniture/before-openable-fronts`): the kitchen cabinets,
  sideboard, bedside table and vanity have drawers with boxes, shelves behind doors and a `softClose` parameter; the wall
  and tall cabinets' fronts may be `liftUp`, `bifold` or `parallelLift`.

## Item builders

- `viewer/engine/items.ts` dispatches to `custom/custom.ts`, `models.ts`, `kitchen.ts`, `sanitary.ts`, `outdoor.ts` and
  `structure.ts`. Each one builds the item body in its local frame.
- Merged meshes (I-58): `buildItem()`, `buildWall()` and `buildWallFinishes()` end with `mergeStatic()`
  (`viewer/engine/merge.ts`), which merges an element's plain meshes per material and shadow settings, one draw call
  each. An object with any `userData` key other than `partName` is left as it is (a tagged mesh) or merged on its own
  (a tagged group: an opening, a door leaf), as are hidden, mirrored and multi-material meshes. **A mesh the engine
  reads or toggles by itself must carry a `userData` tag, or it is merged away.** `buildItemParts()` is the unmerged
  item (the parity test compares parts); the glTF export names a merged mesh after its parts.
- Structural items (`ItemKindEnum::isStructural()`, `structural` in `list_item_kinds`): a `Column` (`params.shape`
  rect or round) and a `Beam` along its width, z being its soffit. They are listed under Building in the Add menu.
  `StructureSize` makes a new one in a room reach its ceiling or hang from it (a vault counts at its springing)
  unless the client gives the height or z. `ElementHandler::defaults()` gets the floor for such lookups. Like wall pieces, their meshes have an
  inside-out twin in `MaterialLibrary.sectionFill()`, so the section plane shows a solid cut. Columns block the
  walkthrough (`columnColliders()`); a beam's body is tagged `ceilingPart` and hides with the ceilings. Quantities
  do not count them yet.
- Custom-made furniture is a furniture definition and a bought product is a model (see below); neither is a new kind.
  A new item kind (e.g. a building element) needs the PHP enum, a migration extending the `items.kind` column enum,
  the TS `ItemKind` union and a builder.

## Product models

- `ModelAsset` rows (`/api/models`, soft-deleted) are uploaded glTF binaries (`.glb`) stored in S3, scoped like
  furniture definitions (global for admins, a user's library, a project's own; `ModelAssetProvider::isUsableIn`).
  `GlbReader` rejects what the viewer cannot decode (Draco, Basis) and `GltfBounds` sizes the model from the accessor
  bounds through the node transforms, normalizing quantized (`KHR_mesh_quantization`) positions.
- A `Model` item has `items.model_id` (`ItemModelLink`). It starts at the model's size and name. The viewer
  (`viewer/engine/models.ts`: `ModelLibrary` with GLTFLoader and the meshopt decoder) scales the model evenly to fit
  the item's box. While loading, or if the model is missing, a box stands in.
- The scene and snapshots carry `models` (the ones its items use). A restore keeps a model the project may still use,
  otherwise the item draws a box. Share guests load models through `ShareModelContent`, but only those of their
  scene.
- `make import-models DIR=… [PROJECT=…]` (`models:import`) uploads every `*.glb` with an optional `<name>.json`
  sidecar (`name`, `license`, `author`, `sourceUrl`). **Third-party models are never committed.** Keep them in the
  gitignored `source_files/` (the demo's CC0 Poly Haven models are in `source_files/demo/models/`, optimised from
  their originals in `src/` with `npx @gltf-transform/cli optimize --compress meshopt --texture-compress webp
  --texture-size 1024 --simplify-error 0.003`, which keeps their look at about a quarter of the triangles; I-58) and
  import them. A model file never changes: to replace one, import the new file and point the items at it
  (`update_item` `modelId`), then delete the old model.

## Materials and tiling

- Materials are a global catalogue seeded by migrations. Admins add and change them with the MCP tools
  `add_material` / `update_material` (`MaterialService`). The material code is permanent because definitions name slot
  defaults by it. `texture` is never set this way, and these changes do not bump a revision. A change publishes
  `materialChanged` to every project, and the viewer reloads the scene. Tests keep the seeded materials and drop the
  ones with `created_by_id`.
- Textures of real products are cut from reference photos into `frontend/src/assets/textures/`, one plank or tile per
  image. The material's `tile_width` / `tile_height` give its real size.
- Patterns without photos are drawn on canvas in `materials.ts`: wood, planks, tiles, grass and gravel, plus
  `Terrazzo` (chips, with slab joints at the tile size), `Brick` (stretcher bond, `tile_width` × `tile_height` per
  brick) and `Herringbone`. A herringbone tile's length is rounded to a whole multiple of its width, and the texture
  is drawn axis-aligned and turned 45°. Tiles, terrazzo and herringbone go on the room's tile grid; bricks keep
  their surface's UVs, which on a vaulted ceiling run along and across the beams.
- Planks with a tile size and no photo are drawn at that size (`drawSizedPlanks()`, I-92), along v (plan y on floors).
  On upright faces (walls, their finish bands, façades, reveals, void linings: `MaterialLibrary.upright()`) planks run
  along the wall, as cladding does; elsewhere planks ignore the grain direction. `Stone` is random coursed dry-stone
  walling (`drawStones()`: stones about `tile_width` long in courses about `tile_height` high) and `Formwork` cast
  concrete showing its panels (with tie holes) or boards (`drawFormwork()`: `tile_width` along the wall, `tile_height` up
  it); both keep their surface's UVs and are not counted in pieces. Bricks vary in sRGB lightness (`vary()`) with joints
  7 % of the course (5–10 mm), so pavers and facing bricks alike look right. The demo house's materials are listed in `docs/demo-house.md`.
- Kitchen patterns (I-132, `SeedKitchenMaterials`): a material's optional `accent` (#rrggbb, MCP `add_material` /
  `update_material`, null derives a shade, `accentOf()`) is a pattern's second colour. `Marble` is veined stone
  (`drawMarble()`: slabs of 1.5 m or more a patch each, smaller tiles 2 × 2, wandering veins in `accent` kept within each
  slab), `Chevron` parquet (`tileWidth` long, `tileHeight` wide, ends cut at 45°, the zigzag along v), `Zellige`
  handmade glazed tiles, `Encaustic` cement tiles with a rosette motif in `accent`; all four go on the room's tile grid
  (`GridPatterns`), are counted in pieces and, but chevron, draw their joints in the elevations (`JointGridPatterns`).
  `Fluted` is a panel of rounded flutes `tileWidth` apart running up the surface whatever its grain (keeps its UVs, not
  in pieces, flutes drawn in the elevations). Metro tiles are `Brick` (light ones get a light grout), strip tiles `Tiles`.
- Tile grid: a room's `tileOriginX/Y` (plan mm; null means the plan origin) is where the tile joints cross.
  `alignTiles()` (`viewer/engine/tiling.ts`) recomputes the UVs of every mesh with a `Tiles` material from its plan
  position. This way the floor, the wall bands (whose rows start at the floor) and the room's tiled items share one
  grid. A room's `floorAngle` (0–179° counter-clockwise, 0 lays planks along plan y) turns the pattern of its level
  faces (the floor, the tops of its tiled items) about the tile origin: `tileGrid()` carries it to `tileUv()`, and
  `buildFloor()` turns the UVs of the patterns `alignTiles()` does not lay (planks, wood) with `turnLevelUvs()`.
  Upright faces, the wall bands included, keep their own joints.
- Wall finishes: a room's `wallFinishes` are bands `{materialId, bottom, top}` lining all its walls. A wall face may have
  its own instead, the wall fields `leftFinishes` / `rightFinishes` (left / right of start → end; null takes the
  room's, `[]` leaves the face bare; validated by `WallFinishBands`; turning a wall round swaps them, so the bands stay
  on the same side of the plan). `faces()` / `wallFacesAlongEdge()` say which wall
  and side covers which stretch of a room edge; `buildWallFinishes()` and `RoomSurfaces` use them.
- Façades (I-88): a wall face outside every indoor room is a façade (`FacadeSpans::of()` / `facadeSpans()` in
  `ceilings.ts`, twins kept identical by the `facades` cases of `wall-spans.json`: each face's mitred extent less the
  stretches indoor room edges run along; open-air rooms, a garden by the house, cover none). It draws its own
  `leftFinishes` / `rightFinishes` along it (`buildFacades()` in `facades.ts`: a group per wall face tagged `wallId`,
  `FacadeLining` off the face, cut by its openings, every piece between the wall's top and its bottom on a base or the
  ground); a face's bands may start below the floor (a plinth, to −`WallBase::Limit`; inside a room they start on its
  floor), and where they overlap the first keeps the stretch. An exterior wall without a material and with bands null
  (not `[]`, which keeps it bare) takes the project's `exteriorMaterialId` (`facadeBands()`; project details, which a
  REST update replaces like the site fields, carried as the scene's `exteriorMaterialId` and at the snapshot's top
  level, a restore leaving the project's own). A
  room's slab piece is in its `soffitMaterialId`, else the façade material (`buildSlab()`, a group merged per
  material): the underside of an oriel or a balcony. An opening with `revealLining` lines its jambs, head and a
  window's sill with the outer face's band (`revealLining()` in `building.ts`). Quantities: `FacadeSurfaces` (surface
  `Facade`, per floor in `QuantitiesDto::$facades`) sums the faces in 50 mm strips per band material, the rest in the
  wall's own or the façade material.
- Ceilings and skirting are room fields (rules in `RoomCeiling`). A room has a `ceilingMaterialId`. With
  `vaultSpacing`, its ceiling is vaulted: sine arches `vaultRise` high spring between beams spaced at whole
  multiples of `vaultSpacing` from the plan origin, so neighbouring rooms line up. The beams run at `vaultAngle`,
  and the crowns are at `ceilingHeight`. Beams are `beamWidth` wide, hang `beamDrop` below the springing and are in
  `beamMaterialId`. `skirtingHeight` / `skirtingMaterialId` add skirting along the walls, except at doorways.
  The geometry is in `viewer/engine/ceilings.ts`; it is TS only, as the backend does not need it.
- Where rooms meet without a wall and their ceilings differ in height, `ceilingSteps()` closes the gap with an
  upright face from the lower ceiling to the higher one. An example is an open-plan corridor with a lower ceiling
  beside a vaulted living room. The faces are rebuilt whenever rooms or walls change.

## Site and sun (I-39)

- A project's site: `northAngle` (true north, degrees clockwise from plan +y), `latitude` / `longitude` (both or
  neither, entered by hand; there is no geocoding) and `timeZone` (IANA). These are project details, edited with the
  name (`ProjectProvider::applySite()`, MCP `update_project`; `detailsChanged`, no revision). The scene carries them as
  `location` (`LocationDto`), and snapshots keep it at their top level for the version's scenes. A restore leaves the
  project's own.
- `viewer/engine/sun.ts` (framework-free): `sunPosition()` (NOAA), `sunInstant()` (clock time in the zone, else local
  solar time), `sunDirection()` (turned by the north angle) and `daylightAt()` (the sun and sky fade around the
  horizon). `ViewerEngine.setSunTime()` places the sun in `fitSunShadow()`. Without a location or a time, the default
  sun (`DefaultSun`, afternoon from the south-west, turned by the north angle) stays.
- A sun time (`SunTimeDto`, `{month, hour}`: the 21st of the month at that clock time) is a Display option
  (`SunTimeComponent`, shown when the scene has a location), kept by saved views and share links (`sun`; null or
  missing means the default sun). The north arrows of the plan PDF and the elevations' key plans follow `northAngle`.
- Plots (`Plot`, `plots`, element type `Plot`): the land around the building on a floor, a boundary `polygon` and a
  `groundMaterialId` (null means a lawn). A floor element like a room: `PlotHandler` through `SceneEditor` (undo, change
  log; its default boundary is a rectangle 3 m clear of the floor's walls and rooms, and it may reach a kilometre from
  the origin), REST `…/plots`, MCP `add_plot` / `update_plot` / `delete_plot`, the scene's `plots` and the snapshot restore
  (`SnapshotPlotRestorer`). The viewer draws the ground just below the rooms' floors and the boundary as a dashed line
  (`viewer/engine/plots.ts`, `parts.plots`, tagged `plotId`); a selected plot gets corner handles like a room (the
  engine's `Outlined` vertex drag). The plan PDF draws the boundary as a dash-dot property line. Paths and lawns on it
  are rooms (the types `Path` and `Driveway`, with the seeded `paving-concrete-grey` and `asphalt`); fences are
  `Fence` items.
- Sloping terrain (I-86): a plot's `terrain` is spot heights `[x, y, z]` (mm, z against the floor's level, at least
  10 mm apart, `SpotHeights`; MCP `terrain` on `add_plot` / `update_plot`, and `set_terrain` taking points and contour
  lines, `ContourLines`). The ground is their Delaunay triangulation extended to the boundary: each boundary corner
  takes the height the spot heights give it, and all are triangulated together, so the plot is covered and each
  triangle is planar; outside it the nearest point of the outline counts; without spot heights the ground is flat at
  `groundLevel`. The twins are `Terrain` / `Delaunay` (`Service/Editor/Geometry`) and `terrain.ts` (`terrainOf()`,
  `terrainAt()`, `plotTerrain()` cached per plot object, `groundAt()`), kept identical by
  `backend/tests/fixtures/terrain.json` (the same arithmetic in the same order, so co-circular grids triangulate
  alike). **Always change both twins and add a fixture case in the same change.** The viewer cuts the plot and the
  rooms draped on it along the triangles (`drapedGeometry()` in `plots.ts`). Gardens, paths and driveways on a sloping
  plot follow it (`drapedOn()`, `floorElevation` lifting them); other rooms keep their flat floor, and its edge steps
  down to the ground along the outline (`groundBeside()`; an indoor room's never up, as its walls stand there). A room
  is cut out of the ground where its floor lies below it anywhere (`groundHoles()`, together with the footprints of the
  walls round it that are not `onGround`, so no ground shows in their window reveals; a draped one where its elevation
  is negative). An item with `onGround` (`items.on_ground`) counts its z from the ground under its centre
  (`ItemAssets.plots`); a plot change rebuilds those items and every room. The
  walkthrough follows the ground and stops at slopes over 35° between two points on sloping ground
  (`FloorLevels.tooSteep`). The plan PDF draws contours every 0.5 m (the full metres heavier) and the spot heights with
  their levels. Editing: a selected plot's spot heights get blue handles in the top view (dragged); a
  double-click on its ground adds one at the ground's height, on a handle removes it; the inspector's `spots` field
  (`SpotHeightsComponent`) types them.
- Site walls (I-87): a wall's top may slope, `heightEnd` being its height at the end (`height` at the start, both
  against the floor's level), and its bottom lie on a base (`baseStart` → `baseEnd`) or follow the ground (`onGround`,
  the plots' `groundAt()`; PHP `Ground` with `Terrain`). The backend twins are `WallTop` (with `heightEnd`) and
  `WallBottom` (`WallBase` checks the top stands 50 mm over the base, or on the ground over the ground at the wall's ends
  when its top, line or `onGround` changes, so the top may lie below the floor's level, I-98; a wall on the floor's
  level is at least `MinHeight` (100) high; and it keeps the openings' sills on the bottom, when an
  opening is placed anew or the wall gets a new base); the viewer's are `wallTopAt(wall, roofs, frame)` and
  `wallBottomAt()` (`under-roof.ts`). Such a wall is built like one under roofs (`profiledWallPiece()`: `profileRuns()`
  with a bottom per station, `curvedPrismAt()` with per-point bottoms; on the ground the faces are densified every
  250 mm), stands on no slab (`isSiteWall()`), and a plot change rebuilds the walls on the ground. The own top and base
  are linear along the wall, so `roofBreaks()` / `RoofBreaks` take them as `slopes` and the solids, the plan cut
  (`cutStretches()`), the finish bands (`finishLining()` under `wallCap`), the open-air elevation faces and the
  quantities (`RoomFaceUnderRoof`) bend where they cross a level. Turning the wall round turns the slope and the base
  round too, as the finishes (`WallBase::turnedRound()`), and a wall on the ground moved along it keeps its sills on
  it. Garden walls take the wall's `materialId` (exposed concrete, stone).
- Fences (`Fence` items, `outdoor.ts`; a fence along a polyline is a railing, I-100): `params.style` `Mesh` (welded mesh over an optional `baseBoard`, the default) or
  `Boards` (vertical boards on two rails); `follow` `Rake` (the panels run with the ground) or `Step` (each bay level
  over the highest ground under it) stands the fence on the ground (`standsOnGround()` in `items.ts`: `onGround` or
  `fenceFollows()`; the sheets take `itemsOverFloor()`; the ground along its width comes from `ItemAssets.plots`); `gate: true` makes it one sliding leaf of boards on a track, `open` (0–1)
  of its width slid along +x.
- Levels: a room's `floorElevation` (mm, ±3000; a terrace a step down, a raised deck) lifts everything the room draws
  (`buildRoom()`: floor, ceiling and finishes, whose opening gaps are brought down to the room's floor; `buildFloor()`
  adds the upright `floor-edge`, 2 mm inside the outline, stepping to the ground beside the room: its plot's
  `groundUnder()`, else the floor's level). Its `ceilingHeight` counts from it, so `ceilingSteps()`, pendant cables
  (`custom.ts`) and `StructureSize` (a column stands on the room's floor) add it; items keep their own z, and the
  elevations and quantities ignore it. The plan PDF writes it under the room's area (`+0.450`) where it fits. A plot's
  `groundLevel` (mm, ±10000) moves its ground, which is cut out under the rooms lying lower than it (`groundHoles()`, so
  the house's rooms show on a raised plot); the surroundings' disc goes under the lowest plot or room
  (`lowestGround()`). Changed rooms rebuild the plots and changed plots the levelled rooms. The walkthrough's eye stays
  at eye height over the floor underfoot (`floorLevels()`: a stair there, else the smallest room, else the plot, else
  0); steps do not block it.

## Stairs (I-37)

- A `Stair` (`stairs`, element type `Stair`, `StairHandler` through `SceneEditor`:
  undo, change log; REST `…/stairs`, MCP `add_stair` / `update_stair` / `delete_stair`) belongs to the floor it starts
  on and climbs to the next one up. (x, y) is the middle of the first step's front edge and `rotation` the walking-up
  direction (0 north). `shape` is Straight, LShaped (quarter landing), UShaped (half landing, `gap` between the flights),
  Spiral (`width` is then the diameter) or Curved (I-99: one flight curving round a centre, ring-sector treads from
  `innerRadius` out to `innerRadius + width`, the walking line in the middle of the width, `turn` the way it curves;
  `curvedLayout()`, sharing `ringSteps()` with the spiral; fan-shaped garden steps round a curved wall; it turns 330° at
  most and keeps 100 mm treads at its inner edge, checked when its radius, width, going or risers change; it is Solid
  (blocks down to its base, never walked under, cut so in the sections) or Open, a stringer stair made curved becoming
  Solid; other shapes keep `innerRadius`); `firstFlight` is the risers up to the landing (null halves them), `turn`
  the way it turns or winds. Also `going`, `construction` (Solid, Stringers, Open), `railing` and two materials.
- The rise comes from the floors (`StairRise`): the next floor up's elevation minus the floor's (`above()`: the lowest
  of the higher levels), or the tallest wall plus the slab a floor stacked on it would get (`FloorStack::defaultSlab()`)
  on the top floor. It is not a field: the scene carries it as `stairRise`, so a floor's new elevation changes the steps
  without changing (or blocking the undo of) the stair. `risers` null takes as few risers as keep each at most 180 mm; a
  count given must make them 140–220 mm, checked only when the count (or the landing's split) is set anew.
- Garden steps (I-87): a stair with a `rise` of its own (100–6000 mm) climbs that from `baseZ` (its first riser's
  height against the floor's level) instead of to the next floor: `stairGeometry()` takes it over the floor's rise, it
  cuts no void (left out of `sceneVoids().own` and of `stairsBelow` in `SceneProvider` / `SnapshotStairs`), and the walk
  climbs it from its base, open at both ends and leading to no other floor (`StairPath.free`). Deck steps, the steps
  down a slope and a stair to a garden terrace are such stairs.
- The scene's `stairsBelow` (`StairsBelowDto`) are the stairs of the floor below (`below()`: the highest lower floor
  whose stairs climb to this one, so a mezzanine on the same level gets none) with their rise: they cut their voids (the
  stair's footprint) through this floor. Snapshots keep `stairs` (`SnapshotStairRestorer`); a version's scenes derive
  `stairRise` and `stairsBelow` from its other floors (`SnapshotStairs`).
- The geometry is TS only, as the ceilings are; the backend checks ranges. `viewer/engine/stairs.ts`:
  `stairGeometry(stair, rise)` lays out the flights (`StairFlight`: origin, direction, base, first riser, risers),
  landings, every tread with its top, the footprint, the walking line and the handrail paths (`RailHeight` over the
  nosings; the outer rail runs round the landing); `stairRisers()` / `stairFirstFlight()` twin the backend's rules.
  `buildStair()` draws it in a group at (x, y) tagged `stairId` (merged; a solid flight is the sawtooth over a sloping
  soffit, with a section-fill twin; stringers get tread and riser boards; a spiral is wedge treads round a pole; a
  curved flight's treads are solid blocks down to its base, or boards, without stringers).
  `BuildingParts.stairs` holds them; `buildStairsBelow()` draws `stairsBelow` under the floor, untagged and not
  pickable (`parts.stairsBelow`, hidden while the stack draws that floor). The voids are `sceneVoids()`: the floor
  below's footprints are cut out of the slab and the room floors, the floor's own out of its flat ceilings (vaults stay
  whole), by `cutVoids()` (`polygon-clipping`, so a void over an outline's edge notches it; `cutShape()` makes the
  `THREE.Shape`). `applyChanges()` rebuilds a changed stair (all when `stairRise` changed) and the rooms its old or new
  footprint reaches (`roomsCutBy()`); a changed `stairsBelow` rebuilds the slab and those rooms. Stairs are selected,
  dragged (x, y), nudged and turned with R like items; the inspector's `stair` fields get `stairRise` beside them for
  the step hint (`stairStepHint()`, 2R + G).

## Voids and railings (I-90)

- Floor voids (`FloorVoid`, `floor_voids`, element type `FloorVoid`, `FloorVoidHandler` through `SceneEditor`: undo, change
  log; REST `…/voids`, MCP `add_void` / `update_void` / `delete_void`, the scene's `voids`, snapshots' `voids` restored by
  `SnapshotVoidRestorer`): a hole through a floor, a `polygon` like a room's. `sceneVoids()` puts a floor's voids in
  `below` (cut out of its slab and room floors, as the stairs coming up) and the floor above's in `own` (out of the flat
  ceilings): the scene's `voidsAbove` (`VoidsAboveDto`: the floor the stairs climb to, `StairRise::above()`, with its
  `rise`, `slabThickness` and voids; `SnapshotStairs` derives it for a version's scenes). Linings (`buildVoidLinings()` in
  `voids.ts`, a ring `LiningThickness` inside the outline): a void's `liningMaterialId` lines it through its floor's slab,
  `liningDepth` down (null: the whole well), and the floor below lines the rest from its ceilings (`ceilingUnder()`) up to
  the slab above; a stair's `voidLiningMaterialId` lines its footprint alike (in the stack each floor draws its part). In
  the top view a void's dashed outline with the plan's diagonals (`buildVoidOutline()`, `parts.voidOutlines`, `planOnly`)
  picks it, and its corners get handles (`Outlined` kind `void`). The walkthrough stops at its edges except over a stair
  arriving from below (`voidColliders()`). Areas: `VoidArea` / `PolygonOverlap` (ear clipping, `ConvexClip`) take the
  floor's voids off the room schedule's areas (`voidArea`, voids listed in `FloorScheduleDto::$voids`, in no total) and
  the flooring, and the floor above's off a flat ceiling in the quantities (under a roof the volume, headroom and sloped
  ceiling are scaled by the share of the floor left); the viewer's labels, the sidebar and the PDF schedule do the same
  with `netRoomArea()` (`polygon-clipping`), the twins kept identical by `backend/tests/fixtures/void-area.json`.
  **Always change both twins and add a fixture case in the same change.** Voids are taken not to overlap; the stairs'
  own footprints (TS only) are not taken off. The plan PDF crosses a void (`voidDiagonals()`).
- Railings (`Railing`, `railings`, element type `Railing`, `RailingHandler`; REST `…/railings`, MCP `add_railing` /
  `update_railing` / `delete_railing`, the scene's and snapshots' `railings`): a polyline (`points`, 2–200, 10 mm apart; a
  closed one repeats its first point) `height` from its base at `baseZ` to the top of the handrail, `style` (`Balusters`,
  `Rods`, `Glass`, `Panel`, `Cable`) with `spacing` (null: `railingSpacing()`), `postSpacing`, `handrail` (`Round`,
  `Square`, `Flat`, `None`), `materialId` (null: steel) and `infillMaterialId` (a pane's or panel's). `buildRailRun()`
  (`railings.ts`) draws posts, handrail and infill along a top line and is shared with the stairs; `buildRailing()` tags
  the group `railingId` at the plan origin, so a drag moves it (`points` shifted). The walkthrough collides with each
  stretch (`railingColliders()`), the plan PDF draws a thin line. The editor's Draw railing tool (key B, `drawRailing`)
  reuses the wall run (typed lengths, snapping; no arcs) and creates it with `EditorStore.drawRailing()`. The balcony
  railing definition stays a definition (one straight run).
- Fences along polylines (I-100) are railings: `style` `Mesh` or `Boards` (`RailingStyleEnum::isFence()`, TS
  `isFenceStyle()`; no handrail, no spacing; a stair's `railingStyle` may not be one) and `follow` (`Level` at `baseZ`,
  `Rake` / `Step` on the plots' ground, `RailingFollowEnum`; another style is stored level). `buildRailing(railing,
  materials, plots)` draws each stretch as the `Fence` item's run (`buildFenceRun()` in `outdoor.ts`, posts at most
  `postSpacing` apart, one post at each corner: `FenceRun.posts`), the ground under it from `groundAt()`; a plot change
  rebuilds the fences on the ground; the sections stand them on the ground (stepped bays for `Step`). Draw one with the Draw railing tool and set its style. Gates stay `Fence` items.
- Stairs: `railingStyle` (null: posts and a handrail, as before) fills the `railing` sides with `buildRailRun()` in
  `railingMaterialId` (null: the structure's); `wallHandrail` (None, Left, Right, Both) adds a rail on brackets
  `WallRailOffset` in from that side (`buildWallHandrail()`, `offsetLine()`), `RailHeight` over the nosings.

## Chimneys and roof details (I-91)

- Chimneys: the structural item kind `Chimney` (`params.shape` rect or round, `aboveRoof` mm, default 600, `cap` false
  leaves out the metal cap; `StructureParamsValidator`), on the floor it starts from (`StructureSize` stands it on its room's
  floor). Its top is `aboveRoof` over the highest roof covering its outline (`itemFootprint()`) on its floor or higher,
  else its own `height` (`chimneyTop()` in `viewer/engine/chimneys.ts`, TS only). The scene's `chimneyFloors`
  (`ChimneyFloorDto`, empty in a project without chimneys; `SnapshotScenes` derives them for a version) are the other
  floors with roofs or chimneys and their level `offset`. Each floor draws the stretch passing through it
  (`chimneyStretch()`, `sceneChimneys()`): its own from their foot (the item builder, `ItemAssets.chimneys`, the stack's
  context floors with their own), those of the floors at or below its level from its level (`buildChimneysBelow()`,
  `parts.chimneysBelow`, untagged), each up to the next floor's level (`voidsAbove.rise`) or the top with its cap, so the
  stack's floors meet. `buildRoof()` cuts every chimney passing through out of the planes and the ridge and hip caps
  (`RoofSurroundings.chimneys`, upright holes). The plan PDF fills them like columns, the walkthrough collides with them;
  `ItemPlacement` keeps them (and every structural kind) off `onRoof`. Quantities do not count them.
- Roof details (`RoofDetails`, missing in older states and snapshots: none): `gutters` (None, HalfRound, Box) along the
  eaves of a pitched roof (a gable's front and back, a shed's front, a hip's four sides; `RoofEdgeEnum`) and
  `gutterMaterialId` (null `zinc-titanium`); `downpipes` `[{edge, along}]` (mm from the roof's centre line on the eaves line, its middle unless a verge is skewed;
  null at the ends of the front and back eaves, `CornerInset` in), checked only when given anew; `snowGuards` rows per
  plane; `ridgeStyle` (Box, Round half-round ridge tiles, Angled metal). A flat roof given anew gutters or snow guards is
  refused, one made flat leaves them behind. The twins are `RoofGutters` (`Service/Editor/Geometry`) and
  `roofGutters()` / `roofDownpipes()` (`viewer/engine/rainwater.ts`): gutters on the overhang's edge where the roof is
  the top (`topStretches()`, stopping under a higher roof, as the caps do), downpipes `DownpipeOffset` off the eaves line, one whose outlet
  misses a gutter left out; kept identical by `backend/tests/fixtures/roof-gutters.json`. **Always change both twins and
  add a fixture case in the same change.** `buildRoof()` draws them in the roof's group, the downpipes down to the
  ground (`RoofSurroundings.ground`: the floor's plots, else the datum ±0.000; a plot change rebuilds the roofs with
  gutters); the plan PDF dashes the gutters and draws the downpipes as circles. Quantities: surfaces `Gutter` and `Downpipe`, by the running metre (`RoofSurfaces`).
- Items on the roof: `items.on_roof` (`onRoof`, not with `onGround`): `z` counts from the top of the floor's highest
  roof covering under the item's centre and the item is turned onto that plane's slope (`layOnRoof()` / `roofUnder()` in
  `items.ts`; `itemsOverFloor()` adds the roof's top for the sheets). A roof change rebuilds them. The seeded definitions
  `roof-vent-tile`, `roof-step` (its tread levelled by a `pitch` parameter), `solar-collector` and `satellite-dish`
  (`SeedRoofAccessoriesMigration`) go there or on a chimney.
- The material pattern `BeaverTail` (`roof-tiles-beaver-clay`, 180 × 380): round-ended tiles in half-bond rows
  `BeaverTailGauge` (0.4) of a tile's length apart (`drawBeaverTails()`), counted in pieces of that visible gauge.
- `ConvertBuiltInFurnitureTest` puts back the current `items.kind` enum after running an older migration's down/up.

## Roofs (I-38)

- A `Roof` (`roofs`, element type `Roof`, `RoofHandler` through `SceneEditor`: undo,
  change log; REST `…/roofs`, MCP `add_roof` / `update_roof` / `delete_roof`) belongs to the floor it covers, its heights
  counting from that floor's level. It is a rectangle, the eaves line: `width` × `depth` centred on (x, y), the ridge
  along the width, turned by `rotation`. `shape` is Gable, Hip (a pyramid over a square), Shed (rising from the front,
  −y at 0°) or Flat (`parapet`); the underside is `eaveHeight` along the eaves line and rises at `pitch`, reaching
  `overhang` past the eaves and `vergeOverhang` past the gables; `thickness` is square to the slope. A gable's or shed's
  verges may be skewed (I-97, `RoofSkew`): `startSkew` / `endSkew` (degrees counter-clockwise, ±45, 0 square) turn the
  verge at the ridge's start (−along) or end about the ridge's end on the eaves line, the overhang square to it, so the
  eaves line and the planes are trapezoids (`localOutline()` / `vergeAt()`, `roofLocalOutline()` / `roofVergeAt()`; the
  heights do not change, only where the roof reaches, so `covers()`, `RoofBreaks`, the gutters, the roof windows' margin
  and the corner drag follow it; a downpipe stands where both its foot on the eaves line and its outlet on the overhang's
  edge lie between the verges, `downpipeSpan()` / `roofDownpipeSpan()`). A flat roof's ends turn alike (I-101,
  `canSkew()`: over a skewed wall, its parapet one ring between the outline and the outline set in, mitred at the corners, `insetOutline()`). A hip roof given a skew anew is refused,
  one made so leaves it behind; the front and back, of the eaves line and of the outline, stay at least `MinSide` long. A new roof does not take a skew from the walls yet. A new roof without
  a size takes the rectangle round the walls' outer faces, turned along the longest exterior wall, the ridge along the
  longer side, the eaves at the tallest wall. An L- or T-shaped house takes several roofs.
- The geometry exists twice: `RoofGeometry` (`Service/Editor/Geometry`) and `viewer/engine/roof-geometry.ts` (planes in
  plan, `undersideAt` / `topAt`, the highest underside of several roofs, the plane over a point), kept identical by
  `backend/tests/fixtures/roof-geometry.json`. **Always change both twins and add a fixture case in the same change.**
- Roof windows (`RoofWindow`, `roof_windows`, element type `RoofWindow`, REST `…/roof-windows`, MCP `add_roof_window` /
  `update_roof_window` / `delete_roof_window`) are listed in their roof's `windows`, as openings are in their wall's. A
  window lies `along` / `across` the ridge from the roof's centre, in the roof's frame, so it moves with the roof; it is
  `width` across the slope and `length` up it, on a pitched plane, `RoofWindowGeometry::Margin` inside the plane in
  plan (`RoofWindowGeometry::corners()` / `fits()`, twinned as `roofWindowCorners()` / `roofWindowFits()` in the fixture) and
  clear of the plane's other windows (`RoofWindowPlacement`); a new one sits low on the front plane, 400 mm in plan
  above the eaves line (not the overhang's edge), or on the right side of a hip deeper than wide, whose front is a
  triangle. The placement is checked only
  when it is set anew (a changed roof does not check its windows, so one it left behind is still renamed and undone).
  A deleted roof hides its windows until undo brings it back; the scene patch reloads when a roof comes in, as its
  windows are not in the result. A copy of a roof window lands past it along the ridge, a copy of a roof 100 mm east.
  Only a flat roof has a `parapet`; given another shape, it leaves the parapet behind.
- Viewer (`viewer/engine/roofs.ts`): `buildRoof()` draws a roof in its own frame, a group at its centre turned by its
  rotation: each plane a slab (covering on top with UVs in metres along the slope, soffit under it and along its edges),
  caps on the ridge and hips, a flat roof's parapet; tagged `roofId`, merged, with section-fill twins. Roofs go into
  `parts.roofBodies`, shown by the Display toggle Roof (`setRoofsVisible()`, on by default; saved views and share links
  keep it as `roofs`, missing meaning shown; hidden, they still shade the floor, as hidden ceilings do), and the wall cut
  removes them in the plan. `buildRoofOutline()`
  draws the top view's stand-in (the outline with the overhangs dashed, the eaves line, ridge and hips) in
  `parts.roofOutlines`, shown only while `isTopDown()` and lifted to just under the wall cut (`roofOutlineHeight()`: the
  plan camera is a perspective one), with an undrawn band along the outline that picks the roof; it is `planOnly`, left
  out of the glTF export. A selected roof is dragged by its outline and resized by its eaves corners
  (`resizeRoofByCorner()`, the opposite corner fixed), nudged and turned with R like items. `floorPoints()` includes the
  roofs' outlines, so the framing and the sun's shadow cover the overhangs. The Add menu's Building → Roof over the floor
  takes the backend's default rectangle.
- Roof windows in the viewer (I-82): `roofWindowLayout()` (`roof-geometry.ts`, TS only over the twin) is a window
  with every corner on its plane, plus its corners at the soffit: the hole is square to the slope, as the frame is, so
  it lies thickness · sin(pitch) further up the slope there. A window the roof left behind has none and is not drawn.
  `roofWindowLayouts()` caches a roof's per roof object (a changed roof or window is a new one). `buildRoof()` cuts the
  holes out of the plane's slab (triangulated with holes, the sides lined) and adds `buildRoofWindow()`: frame from the
  soffit to above the covering, sash (a centre-pivot handle bar at the top, a top-hung handle at the bottom), glass
  without a shadow, flashing; tagged `roofWindowId` (`RoofParts.windows`). The roof's outline draws each window's
  footprint with an undrawn face that picks it in the top view, where it is dragged along and across the roof, stopping
  where it would no longer fit its plane (`roofWindowFits()`; the plane's other windows are the backend's to refuse) and
  jumping the ridge; `previewRoof()` rebuilds the outline, and the body only while the wall cut leaves something of it
  seen. The arrows nudge it by the plan step turned into the roof's frame. A sloped ceiling is opened under its windows
  at the soffit (`ceilingParts()`). The Add menu's "Roof window in selected roof" (a pitched roof selected) and the
  inspector's `roofWindow` fields edit them.
- Walls: every wall of the floor is cut at the underside of the roofs over it; one with `upToRoof` (the inspector's
  "Up to the roof") rises to it (a gable). `StructureSize` stops a column or beam at the roof where it is lower than
  the ceiling. Snapshots keep `roofs` (`SnapshotRoofRestorer`) and the walls' `upToRoof`. Seeded coverings:
  `roof-tiles-clay`, `roof-slate`, `roof-metal-seam`, `roof-membrane`.
- Under the roofs (I-81, `viewer/engine/under-roof.ts`, TS only): `wallTopAt()` is a wall's top at a plan point.
  Every such height is piecewise linear along a line, and `roofBreaks()` finds where it bends (roof edges, ridges,
  hips, a level reached), so `profileRuns()` cuts a solid there and splits it where the top steps (a wall running out
  from under a roof). `buildWall()` builds a piece the roofs change that way (`curvedPrismAt()` takes a top per
  point), and leaves out an opening whose sill is above the lowest top over it, the wall solid there; finish bands stop
  at the underside (`finishLining()`).
  A flat ceiling slopes where the roof comes lower than its height (`ceilingParts()`, `polygon-clipping`; the highest
  roof where they overlap), `CeilingUnderRoof` (10 mm) under the roof's soffit so the two do not flicker;
  `ceilingHeightAt()` (pendants) and `ceilingSteps()` (with `atB` where a step slopes) follow it. Vaults are not cut.
  The plan PDF's cut follows the wall's top (a wall the roof brings under the cut is drawn as a low wall, as a knee
  wall is). A roof change rebuilds the walls, rooms (with their finishes), ceiling steps and Custom items the roof
  reached before or reaches now (`roofsReach()`). The Roof toggle hides the stack's roofs too (`ContextFloor.roofs`).
  The backend keeps an opening's head under the wall's top over it (`WallTop`: the lower of the wall's two faces,
  sampled along the wall; `OpeningHead`), so a gable takes windows above the wall's height. Like a roof window's
  placement, it is checked only when the opening is placed anew or the wall reshaped, so a roof lowered since does
  not block renaming or undoing them.
- Junctions (I-83): a plane of a roof is the top only where no other roof of the floor is higher (its underside), so
  roofs meeting form valleys. The parts are twinned: `roofPlaneParts()` (`under-roof.ts`, `polygon-clipping`; the other
  plane is clipped by the half-plane first, `clipBelow()`, as huge half-plane polygons break the library) draws them,
  `RoofCover` (`Service/Editor/Geometry`, convex pieces by `ConvexClip`, Sutherland–Hodgman) measures them; the
  junction cases of `roof-geometry.json` keep their areas alike. They cut exactly where planes cross; only parallel
  planes within 0.5 mm are tied, the earlier roof's winning. `buildRoof(…, others)` builds each part (earcut, as a part
  may be concave) and stops the caps there (`topStretches()`); a roof window lying where another roof is higher has no
  slab to sit in and is left out, in the quantities too; a roof change rebuilds the roofs it met before or meets now
  (`roofsMeet()`). The plan PDF draws each part's edges once: dashed above the cut (outline with the overhangs, ridges,
  hips, valleys), solid where a slab lies wholly under `PlanCutHeight` (seen from above) and the slab's section filled
  and outlined where the cut passes through it (an attic's eaves); and the roof windows. Elevation faces follow the
  ceiling under the roofs (`faceTop()` at the `roofBreaks()` of the ceiling height plus `CeilingUnderRoof`).
- Rooms under roofs: `RoomUnderRoof` (PHP) and `roomUnderRoofs()` (TS), kept identical by the room cases of
  `roof-geometry.json`, give a room's clear height range, its volume under the slope, its area with at least 1.2 m
  of headroom and its ceiling's area along the slope, on the roofs' underside itself (not the viewer's 10 mm gap); null
  for open-air, vaulted and roofless rooms. The room schedule (`minClearHeight`, `headroomArea`, the volume) and the
  PDF room schedule use them. Quantities: `RoomSurfaces` counts that ceiling area and takes the bands and paint up to
  the wall's top under the roofs (cut by them, or with `upToRoof` rising to them) and the ceiling (`RoomFaceUnderRoof`:
  exact, summed between the openings' edges and `RoofBreaks`, the twin of `roofBreaks()`); `RoofSurfaces` gives each
  roof's covering (its material, else
  the viewer's default tiles or membrane) and soffit along the slope without its windows, in `QuantitiesDto::$roofs`
  with the number of windows (surfaces `RoofCovering` and `Soffit`, priced like the others).

## Lighting

- Light sources are furniture definition parts. A `light` part is a point light, or a spot shining down, with
  `lumens`, `kelvin`, optional `shadow` and a `bulb` that glows (a bare bulb, a downlight's lens, or none inside a
  shade). A `cable` part is a pendant's cable: up to the hook, then along `path` to the ceiling rose. It is fixed to
  the real ceiling of the item's room (`ceilingHeightAt()` in `ceilings.ts`, vaults and beams included) and hangs
  between the fixings, sagging by `sag`.
- `viewer/engine/lights.ts` builds the three.js lights (`createFixtureLight()`) and switches them
  (`applyFixtureLights()`). Lights are created, and switched off, hidden: that leaves them out of the shaders (kept at
  intensity 0, they cost about 70% of a frame; I-58). The first switch recompiles the shaders, a one-off 0.1–0.3 s.
  At most `MaxFixtureShadows` lights cast shadows. `LumenScale` maps lumens to the scene's units, where the sun is
  2.4, and `WhiteBalance` pulls the kelvin colours towards white, as a camera would. LED strips and `led` parts are
  fixtures too.
- `ViewerEngine.setLighting(night, lightsOn)`: at night the sun is gone (along with its shadow pass), the sky is faint
  and the background dark. The pages' Display has Night and Lights on; lights follow Night until switched by hand.
  `ShareSettingsDto.night` starts a share link at night.
- Seeded definitions (`SeedLightingCatalogueMigration`): a bare-bulb pendant with a cable path, a recessed downlight,
  a light source to group with the lamps of the model library, a Schuko socket, a rocker switch, a data socket and a
  plumbing point. Wall accessories stand on the wall face, their elevation being the bottom edge. Their definitions
  carry a `category` (`DefinitionCategoryEnum`: light, socket, switch, data, plumbing; checked by `DefinitionValidator`,
  not read by the evaluators) for the electrical schedule. Lights count only by this tag, not by their `light` parts.
  With it, `schedule` (I-64, `DefinitionSchedule`, TS `DefinitionScheduleSettings`; checked, not read by the
  evaluators) says what the schedule counts and lists: `count` an int parameter multiplying the item (a socket's
  `gangs`, a data socket's `ports`; 1 without), `values` the int/bool/select parameters it groups by and shows (all
  without). A definition with no `schedule` at all (older JSON in versions and share links) counts a socket's int
  `gangs` and a data socket's int `ports` (`DefinitionSchedule::Defaults`, TS `scheduleCount()`). The furniture editor sets both above the JSON (`ScheduleSettingsComponent`), the library shows the
  category; `AddElectricalScheduleSettings` gave the seeds theirs and tagged copies their `count`.

## Render quality

- `ViewerEngine.setQuality('fast' | 'presentation')`: fast (the default) renders straight to the canvas. Presentation
  (`viewer/engine/presentation.ts`, `PresentationPipeline`) renders through an `EffectComposer`: the scene, GTAO
  ambient occlusion, the output pass (tone mapping and colour space, so the renderer's are not applied twice) and SMAA.
  It renders at a pixel ratio of at least 2 and gives the sun a larger, softer shadow map (8192, capped by the GPU).
  The handle and measure overlay still draws after it, on the canvas. GTAO runs at half the canvas resolution
  (`HalfResolutionGtaoPass`). While the camera moves (`viewChanged()`, until it has stood still for
  `PresentationSettleMs`), `renderMoving()` renders the plain scene at the fast quality's pixel ratio into a
  multisampled buffer stretched over the canvas. `capture()` and the benchmark always render the full pipeline.
- The pages' Display has "Presentation quality"; `ShareSettingsDto.presentation` opens a share link with it. The
  screenshot capture (I-44) is meant to use it.
- Shadow maps are static (`shadowMap.autoUpdate` off): the sun's and the fixtures' shadow passes run only after
  `refreshShadows()`. `load()`, item rebuilds, ceilings, the section cut, lighting, quality and moving door leaves
  call it, and the maps update every frame while a drag, preview or drawing is under way. **Engine code that moves,
  shows or hides anything casting a shadow outside these paths must call `refreshShadows()`.**
- Frames are rendered on demand: `tick()` draws only after `invalidate()` and while something moves (a walk, a camera
  tween, a drag or preview, a presentation's moving frames until its still one). The controls' `change` event (damping
  included), canvas input, resizes, texture, model and picture loads, `refreshShadows()` and every public setter of
  `ViewerEngine` invalidate. **Engine code that changes what is drawn outside these paths must call `invalidate()`.**
  The furniture preview (`custom/preview.ts`) still renders every frame.
- Measuring it (I-58): `?stats` in a viewer URL shows `StatsHudComponent` (frame CPU time, draw calls, triangles,
  programs, meshes, lights, build time; `viewer/engine/frame-stats.ts`) and exposes `window.fdBench`. The renderer's
  `info.autoReset` is off, and `tick()` resets it once a frame, so the counts cover the shadow, post-processing and
  overlay passes together. `ViewerEngine.benchmark()` pauses the loop and renders frames back to back in a scenario
  (quality, night, lights, or the presentation's moving frames), orbiting the camera. It reads a pixel after each
  frame, so the GPU is waited for and vsync does not cap the time. `make benchmark PROJECT=5 … [VIEWS=… BASELINE=…]` (`frontend/scripts/benchmark.mjs`) runs
  it over the saved views and writes `frontend/benchmarks/<date>-<commit>.json` (gitignored). A baseline makes it print
  `old → new` per metric. Compare results from the same machine only (the file names the GPU).

## Saved views and screenshots

- `SavedView` (`saved_views`, `SavedViewProvider`, REST `…/views`, MCP `list_views` / `save_view` (replaces a view of
  the same name) / `delete_view`): a named view of one floor. `settings` (`SavedViewSettingsDto`) holds the view mode
  (`overview` or `top`; an eye-level shot is a 3D camera at eye height, and saving during a walk keeps it as one), the
  camera, the display options (night, lights, quality) and the variant per set. Views are not elements: no revision,
  no undo. A camera may have a lens (I-54, `ShareCameraDto::$focalLength`, 10–200 mm on 35 mm film across the view's
  longer side, `lensFov()` in `viewer/engine/lens.ts`; null is the standard 45° vertical `OrbitFov`): `setCameraPose()`
  applies it (tweening the field of view), `setLens()` sets it (the View section's Lens select, 3D only; the page
  gives the plan the standard lens, as a wide one only distorts it); a walk has its own, which a view saved during it keeps as its focal length. They go with their floor; snapshots carry them (`views`, `SnapshotViewRestorer`), and a restore remaps
  their floor, sets and groups.
- The viewer page's Saved views section applies, saves, replaces and deletes views. A view's variants are shown
  locally (`variantOverride`, until the scene's next revision); the project's active variants do not change.
  `?view=<id>` applies a view once the engine has built its floor. Once no model or picture is loading, the page
  sets `data-view-ready`.
- Capture PNG: `ViewerEngine.capture()` renders 1600 × 1000 at 2× in the presentation quality, off the visible size
  and without the panel inset, and downloads it or saves it as a project file. Room labels are HTML, so they are not
  in it.
- `make screenshots PROJECT=… [EMAIL=… PASSWORD=…] [VIEWS=… ROOM=…]` (`frontend/scripts/screenshots.mjs`, Playwright;
  the login defaults to `SCREENSHOTS_EMAIL` / `SCREENSHOTS_PASSWORD` from `.env`, as for `make benchmark`, and the
  recipes are silent so it is not printed) writes the saved views of the project whose name starts with `VIEWS`, the
  editor with `ROOM`'s inspector open, the project page, cut list, furniture library, furniture editor and a share link
  to `docs/screenshots/`, with the plan underlay hidden (Display popover) and no tooltip or focus left from its clicks;
  `VIEWS_ONLY=1` writes the views alone. With `cwebp` on the PATH it saves WebP 1600 px
  wide. The README tour uses the demo flat's "Tour · …" views and the demo house's "House tour · …" views (project 13
  locally, `VIEWS_ONLY=1`), plus its first floor's plan PDF and section B–B rendered from the exports
  (`house-plan.webp`, `house-section.webp`); keep the unlicensed poster and plan, and the house's source drawings and
  photos, out of them.

## Review comments

- `Comment` (`comments`, `Service/Comment`): a thread pinned to a point of a floor (x, y plan mm, z above the floor),
  with one level of replies (`parent`; a reply takes the thread's point). Only a thread is resolved (`resolvedAt`) and
  numbered (`number`, unique per floor and never reused: the pin's label, the same for owner, guests and agents). The
  count is `floors.last_comment_number`, which `CommentNumbering` bumps under the project row lock (taken first, as the
  editor does) and the floor's; it is not mapped on
  `Floor`, as persisting a floor would write a stale count back. The author is a user, an agent (`createdBy` plus the MCP client, `CommentActor::of()` from `ActorContext`) or a share link
  guest (`guestName`, `shareLink`). Only the author edits the text; a person deletes any comment, an agent only its own.
- They are not floor content: no revision, no undo, not in snapshots. A change sends `commentsChanged`. A version
  restore carries them over to the restored floors (`CommentKeeper`, with `FloorMatcher`: the same level and name, else
  the same level; `SnapshotViewRestorer` uses it too). The numbering continues after the floors it came from
  (`RestoredThreadNumbers`); where two floors meet on one, a thread whose number is taken there gets the next one.
  The viewer shows them on the working copy only.
- REST `…/comments` (`?floorId=`, `?resolved=0`), MCP `list_comments` / `add_comment` / `resolve_comment` /
  `delete_comment`.
- Share links: `ShareSettingsDto.comments` is `hidden` (also older links), `read` or `write`. `GET|POST
  /api/shares/{token}/comments` (`SharedCommentController`, open in `AuthorizationMiddleware`) reads the open threads
  of the link's floor in the working copy and takes a guest's comment, at most `SharedComments::GuestCommentsPerHour`
  per link. The posts are counted in `guest_comment_posts` (`GuestCommentLog`, under the project row lock), so deleting
  the comments, or a restore dropping them, does not refill the limit. After a restore the floor is found by the link's
  `floorName`.
- Viewer: `viewer/engine/comments.ts` draws the pins (CSS2D badges, dimmed above the wall cut). The comment tool
  (`setCommenting()`, key C) reports the surface point under a click and takes clicks before the measure and edit
  tools. `app/comments/CommentsComponent` is the panel of both viewer pages. Agents are told that guest comments are
  unauthenticated input.

## Walkthrough

- View → Walk is implemented in `viewer/engine/walkthrough.ts`: first person at an eye height of 1630 mm with pointer
  lock (WASD/arrows, Shift runs). Esc releases the lock and returns to the 3D overview.
- Without a fine pointer (`walkthroughTouchMode()`), a finger drag turns the view and `WalkPadComponent` replaces the
  keyboard: corner arrows act as held W/A/S/D keys, plus a "Back to 3D" button.
- Collisions are computed in plan mm against:
  - `wallColliders()`: wall pieces at floor level. Doorways (passages included) and `walkable` windows are gaps;
    other windows are solid.
  - `leafColliders()`: each door leaf or French window sash as a thin wall at its current angle, or its current
    position along the wall for a sliding or pocket leaf (`Leaf.motion`).
  - Items do not block.
- All floors at once (I-102): the viewer page loads the project's other floors while walking (`walkData`, the same
  version) and passes them as `walkFloors` (`ViewerEngine.setWalkFloors()`; a share link with `allFloors` its `stack`
  and `above`; a null during a walk is ignored). During the walk `rebuildStack()` draws all of them as context floors
  (above the shown one too), each with its own `leafStates`, so their doors open and close (`doorAt()` picks them,
  `doorStates()` finds the floor). `building-walk.ts` (framework-free) walks them in the shown floor's frame:
  `walkFloors()` gives each a band of walker heights from `BandBelow` (600) under its level to as far under the next
  one's; `floorColliders()` moves a floor's colliders (walls, stairs, railings, voids, door leaves) by its offset and
  keeps them within its band (`WallCollider.levels`); `buildingLevels()` reads what is underfoot on the band's floor
  (`floorSurface()`: its stairs, then its rooms) and else the ground of any floor's plots (sloping ground stops only the
  walker on the plot's floor). A floor's band starts under its lowest room (sunken ones), above the floor below's level;
  two floors at one elevation share no band. So a stair's ramp carries on from floor to floor, with no switch.
  `onWalkFloorChange` tells the page the floor the walker is on (the walk's hint names it; `walkPlace` for the floor
  buttons). Ending the walk on another floor moves the camera to the walker's place against the shown floor's level
  (`shiftView()`), and the page navigates to that floor unless it moved on meanwhile (`walkStartFloorId`); its `load()`
  then leaves the view as it is (`walkEndedOn`). PageUp / PageDown stand the walker on the next floor in the engine
  (`walkToBuildingFloor()`); E opens the doors of the walker's floor (`doorAt()` raycasts only that layer). Drawing all three floors of the demo house costs about 0.7 ms of CPU a
  frame more than the shown floor alone.
- Between floors without the others loaded (I-73; a share link without `allFloors`, or before they load): PageUp /
  PageDown (the walk pad's floor buttons on touch) ask the page for the next floor up or down (`walkFloorHandler`); it
  navigates there, and `load()` of that floor keeps the walk going (`ViewerEngine.walkToFloor()`, `Walkthrough.moveTo()`):
  the walker stands at the same plan point if it lies in one of the floor's rooms, else where a walk of the floor
  starts, with its doors closed as at a walk's start. Any other floor change ends the walk.
- Stairs (I-78, `stairs.ts`): `stairPaths()` are the floor's own stairs and those coming up through it (`stairsBelow`,
  under the floor by their rise), laid out once per `load()` (`walkStairs`) for the colliders and `floorLevels()`. Over
  one, the eye follows the walking line (`stairSpot()`, a smooth ramp; where lines pass beside or over one another, a
  U's flights, a spiral's windings, an upper stair over the void of the lower one, the stretch nearest the walker's
  last height wins: `Walkthrough` passes it, and `setFloorLevels(levels, level)` carries it over to a floor walked
  onto). `stairColliders()` block a stair's sides, open where the floor's own stairs start and where those from below
  arrive (`StairPath.open`: half the flight's width, or a tread's turn round a spiral; the stairwell's other edges are
  its railing, and an upper floor's walls over a stairwell block too). Colliders may have `levels`, the walker's levels
  they stop (`collideCircle(…, level)`): a stairwell's edges only the walker on its floor (and not where the floor's own
  stair flies over it), a flight's sides only up to a step (`StairStepUp`) over the flight, and where it is over
  `StairHeadroom` not the walker underneath, who is stopped across the flight where it comes down to the headroom
  (`headroomCrossing()`), unless deep in a stairwell below; `stairSpot()` takes no stair over a step above the walker. Within `StairLanding` of the top, or of the
  bottom of a stair from below, `ViewerEngine.followStairs()` walks to the next floor as PageUp / PageDown do; the
  walker keeps the spot they have reached when it loads, and no further floor until they leave the landing zone
  (`stairArrival`). While climbing, the floor above is not drawn until it loads. In a walk over all floors (I-102) none
  of this is needed: the stairs are walked across the floors' bands.
- Doors open and close for the session only. `openingLeaves()` is the leaf geometry shared by `building.ts` (pivots
  tagged `userData.leaf`, posed by `setLeafPose()`) and the colliders, and `LeafStates` (`doors.ts`) animates them. E, a click at the view centre
  (`DoorReach`) or a tap toggles the door in view.
- Outside the walk, doors show open and French windows closed. A walk starts with everything closed except the
  entrance.
- `openings.walkable` (allowed only on a window with sill 0; the sashes swing towards `side`) makes a French window a
  way out.
- The walk starts one step inside the door flagged `openings.entrance` (`entranceWalkStart()`). Fallbacks: a door in an
  exterior wall, then the largest indoor room.

## Measurements

- Room schedule: `Service/Schedule/RoomScheduleService` (REST `…/room-schedule`, MCP `get_room_schedule`, the project
  page's `RoomScheduleComponent`). It lists each room's net area and perimeter (`RoomDto`, computed with `PlanMath` from
  the outline on the inner wall faces), its clear height (`ceilingHeight`) and its volume. Open-air rooms (terraces,
  gardens, paths and driveways: `RoomTypeEnum::isOutdoor()`, in TS `isOutdoorRoom()`) and garages (`isAncillary()`)
  are totalled separately; kitchens (`Kitchen`, I-131) are ordinary indoor rooms. Only
  PHP computes it, except under roofs (`RoomUnderRoof`, twinned for the PDF's schedule; see Roofs).
- Electrical schedule: `Service/Schedule/ElectricalScheduleService` (REST `…/electrical-schedule`, MCP
  `get_electrical_schedule`, the project page's `ElectricalScheduleComponent`). It counts the shown Custom items whose
  definition has a `category`, in the item's `roomId` or else the room holding its footprint centre (points outside
  every room go under a room with `id` null). Rows group one definition with the same `schedule.values` at the same
  `z`, their `units` summing `schedule.count` (`DefinitionSchedule::units()`); the totals give the sockets' `outlets`
  and the data sockets' `dataPorts`. The rows are PHP only; what an item counts as is twinned by `scheduleUnits()` in
  `electrical.ts` (the plan's gang marks, the PDF legend), kept identical by `backend/tests/fixtures/electrical-units.json`.
  **Always change both twins and add a fixture case in the same change.**
- Electrical plan (I-64): `viewer/engine/electrical.ts` (framework-free) gives the
  points (`electricalPoints()`: wall points on the middle of the item's back edge, the wall face, facing out; lights at
  the centre; hidden variants left out; `hasElectricalPoints()` only tells whether there are any) and their symbols as unit-frame polylines in two sets, `Iec` and `Csn`
  (`ElectricalSymbolSets`, `symbolPolylines()`). The user picks the set on the account page (`users.electrical_symbols`,
  `UserDto::$electricalSymbols`, `PATCH /api/current-user`); share guests get the owner's (`SharedViewDto`). The
  Display toggle "Electrical (Plan)" (shown when the floor has points; `electrical` in saved views and share links)
  draws them over the top view like the dimension lines (`ViewerEngine.setElectricalVisible()`, `buildElectrical()`:
  no depth test, not pickable, the wall points' elevations as CSS2D labels; hidden while items are previewed, and
  from an item drag until the edit comes back or `restore()` puts them back). `planSheet(scene, info, symbols)` is the
  electrical plan PDF (Export → Electrical PDF): the furniture faint without names or dimensions, the symbols, the
  elevations (each at the first free spot round its symbol, never left out) and a legend with the counts in a strip
  `planLayout(bounds, reserved)` keeps above the title band (shrunk to fit, every category with its plain symbol).
- Switch links (I-104): a switch item's `controls` (`items.controls`, JSON ids, `ItemDto::$controls`) are the lights it
  controls, a light in two switches' lists being two-way switching. **Same floor only: a switch cannot control lights
  on another floor** (a stair's two-way switch upstairs and downstairs is not covered). `SwitchControls` normalises the
  list (distinct, sorted, at most 50) and checks only the ids added: the item must be a `switch`, each id a `light` of
  its floor (a deleted one passes, so undoing an unlink does not conflict); an item that stops being a switch drops the
  list. Deleted, hidden-variant or recategorised lights stay listed and are skipped where the links are read. A copied
  switch controls the same lights; `GroupEditor::copyGroup()` (group copies, new variants) points copied switches at
  the copies of the group's lights, and for a new variant (`asVariant`) the switches outside the set controlling the
  source's lights get the copies too, so a shared switch works with every variant; restores and imports map the ids
  (`SnapshotSwitchControls`). MCP `add_item` / `update_item` `controls` (`clear`
  takes it). The schedule's `FloorElectricalDto::$lighting` (`LightingCircuits`): circuits L1, L2, … as connected
  parts of the switch–light graph, plus `unswitched` lights and `unlinked` switches (the project page's "Lighting
  circuits"). Drawing (`switchLinks()` / `linkArcs()` in `electrical.ts`): each distinct light set is chained by a
  minimum spanning tree and each switch gets one dashed arc to its set's nearest light, bulging left, between the
  symbols' circles; the viewer dims the arcs away from the selected item, the PDF dashes them with a legend row.
  Editing: with a switch selected, `L` or the inspector's Pick lights (`SwitchLightsComponent`) starts
  `EditorStore.linking`; the page switches to the plan and turns the measure and comment tools off (turning one on
  ends the picking); the engine (`setLinking()`, `onLinkPick`) shows the overlay with the pending links and turns a
  click near a light symbol (or on a light, or an item grouped with one) into a toggle, double-clicks selecting
  nothing; Enter / `L` saves one change, Esc cancels, no other editing key acts meanwhile. The overlay rebuilds on a
  selection only when the highlighted switch or light changes. The selection card says what a switch controls and which switches a light has.
- Quantities and cost: `Service/Quantities/QuantitiesService` (REST `…/quantities`, MCP `get_quantities`, page
  `/projects/:id/quantities`, linked from the room schedule). `RoomSurfaces` counts per room and material: the floor,
  the `wallFinishes` bands and the wall paint around and above them up to the ceiling (a vault's mean height), without
  openings, along the wall faces on the room's edges; the ceiling (a vault's arc length and its beams) and skirting.
  Façades (wall faces outside every room) are counted apart (`FacadeSurfaces`), and open-air rooms get no paint. Materials with a tile size
  and a pattern laid in pieces are counted in whole pieces plus `projects.waste_percent` (`Costing`).
- Prices are per project (`ProjectMaterialPrice`, `MaterialPriceService`, REST `…/material-prices` replaces the list, MCP
  `set_material_prices` merges): per m² and per running metre (skirting, edge banding), in `projects.currency`. A price
  without a material prices a surface's default look (`QuantitySurfaceEnum::defaultMaterialName()`, e.g. a ceiling
  without a material is white plaster). They are not floor content: no revision, no undo, not in snapshots; saving
  sends `pricesChanged`.
- The wall faces along a room edge exist twice: `WallFaceSpans` with `EdgeWall` (PHP) and `wallSpansAlongEdge()` /
  `wallFacesAlongEdge()` / `wallGapsAlongEdge()` / `skirtingPieces()` with `edgeWall()` (TS), kept identical by
  `backend/tests/fixtures/wall-spans.json`. A curved wall's face matches each room edge of its outline pieces (the
  edge's ends and middle on the face's circle). **Always change both twins and add a fixture case in the same change.**
- `edgeRuns()` (`ceilings.ts`) joins the room edges along one curved wall face: the dimensions draw it as one line along
  the arc (`DimensionLine.path`), the elevations as one face unrolled (`ElevationFace.curve`). Its wall finishes get
  UVs along the arc (`arcFaceUvs()`, flagged `ownUvs` so `alignTiles()` leaves them).
- Dimension lines (`viewer/engine/dimensions.ts`, the Display toggle and `ShareSettings.dimensions`): every room edge
  gets a line offset into the room. Where `wallGapsAlongEdge()` finds openings, the edge also gets a chain of piers and
  openings. They show only while `isTopDown()`, and labels that are longer than their line on screen are hidden.
- Measure tool (`viewer/engine/measure.ts`, `MeasureHudComponent`, the View section's Measure button, key D): two clicks
  in the top view. It is independent of edit mode, takes clicks before the editor tools, and the pages turn it off
  when an edit tool is picked. It snaps to wall ends and to face corners and faces (`wallFaces()` with the
  `wallJoints()` mitres). Esc first clears the measurement, then ends the tool.

## Exports

- `viewer/engine/plan-drawing.ts` (framework-free; the geometry helpers, not a three.js scene) draws the floor as SVG text in paper mm: `planSheet()`
  is the plan on A4 at the largest of `PlanScales` that fits (`planLayout()` turns the paper for a tall plan), with a
  title block, scale bar and north arrow; `roomScheduleSheets()` are the room schedule pages, built from the scene's
  rooms (no endpoint, so share guests have it too).
- The walls are cut at `PlanCutHeight` (1300 mm, as the plan view): the `wallPieces()` reaching it, with the
  `wallJoints()` mitres. An opening the cut passes through is a gap with its symbol (`openingLeaves()` opened square,
  windows with glazing lines and sill board), one above or below it is dashed in the solid wall. Items are footprint
  rectangles with names (`hiddenItemIds()` hides other variants); items from the cut up are dashed and unnamed,
  columns filled. Dimensions are `roomDimensions()` at paper offsets, `walledOnly` (no lines where open-plan rooms
  meet). Text widths are estimated (`CharWidth`): room names wrap to the room, and dimensions and item names that
  would overlap text placed before them are left out.
- Wall elevations (`viewer/engine/elevations.ts`, framework-free): `elevationSheets()` gives an A4 page (or more) per
  room with walls. `roomFaces()` letters the room's outline edges that have a wall along them (A, B, … counter-clockwise),
  each seen from inside, so its x runs from the edge's end to its start; the top follows a vaulted ceiling
  (`ceilingHeightAt()`). A face draws the finish bands of `finishSegments()` round the openings (`clipGap()`, as
  `buildWallFinishes()` does) with their joints as the viewer lays them: Tiles and Terrazzo as a grid on the room's
  tile grid (u as `tileUv()`, the phase is −(b − origin)·dir; v from the floor), Brick in stretcher bond from the
  edge's start (`worldUvBox()`; even courses from the floor shifted half a brick), Herringbone reflected (the canvas
  is sampled with `flipY`), turned 45° and clipped; joints closer than `MinJointPitch` on paper are left out. Then
  the skirting, the openings (doors with dashed lines to the hinge, a double door's leaf on `hinge` `leafWidth` wide,
  windows with panes, transoms and panel), the items standing against the face (`itemsAgainst()`, by
  `itemFootprint()`), all clipped to the outline (a vault), the width chain below and the band levels at the right up
  to the crown. `ElevationFace` carries the edge's spans, gaps, openings and finish segments, computed once.
  `layoutFaces()` flows the faces in rows at the largest of `ElevationScales` that fits one page with every face
  within the paper (a wider one halves the scale); rows beside the key plan and legend start right of them, and a
  face too wide for that starts below. Open stretches of an edge are hatched.
- The sheets share `sheet-svg.ts` (paper size, frame, title block, SVG writing).
- `app/export/`: `sheetsPdf()` turns the sheets into PDF pages with `jspdf` and `svg2pdf.js` (loaded lazily) and
  embeds Inter from `assets/fonts/` (SIL OFL; jsPDF's own fonts lack Czech letters). `ExportComponent` (Plan PDF,
  Plan SVG, Elevations PDF, Façades PDF / SVG, Sections PDF / SVG, 3D glTF) exports the scene as shown on the viewer
  page and the shared viewer page; `ShareSettingsDto.exports` (missing in older links: allowed) lets a share link
  forbid it.
- Building drawings (I-94, `viewer/engine/sections.ts`, framework-free): the façades and sections of the building,
  the shown floor with the floors below it (`ExportComponent.building`: the viewer page loads them, or takes the All
  floors stack; a share link has its stack only with `allFloors`). `buildingView()` collects what a `ViewPlane` sees
  (u along its `right`, levels against ±0.000, depth along `look`) as groups drawn far to near by their nearest
  point, each filled so it covers what lies behind (painter's order): wall faces facing the viewer from bottom to top
  (`wallTopAt()` / `wallBottomAt()`, bent at `roofBreaks()` with the wall's own slopes) with their openings and, on a
  face outside every indoor room, their `facadeBands()`, slab edges
  (rooms not on the ground and walls joined, cached per scene), roof planes' parts (`roofPlaneParts()`; the covering, hatched every
  350 mm up the slope, where the plane rises away from the viewer, else the soffit), roof windows, gutters,
  downpipes, chimneys, stair treads and railings. The extent is the house (site walls, garden steps and the ground
  do not widen it) plus `GroundMargin`, and each drawing is clipped to it. A façade (`facadeSheets()`: plan south,
  north, east and west, named by the true compass, `facadeName()`) adds the terrain along the line in front of the
  house; four to an A3 sheet, or two where that gives a larger scale. A section (`sectionSheets()`, A4) keeps what
  lies 0 to `depth` beyond its line (`clipRange()` / `clipRing()`) and fills the cut over it (`sectionCutShapes()`):
  walls less the opening the line passes through (glass in a window, lift-and-slide or glazed door), slabs, roof
  planes, stair treads and landings, and the ground (`cutGround()`: down to the lowest slab's underside where the
  building stands). Level marks (`levelText()`): every floor, the eaves and ridges, a flat roof's top. Scales from
  `BuildingScales`. polygon-clipping trips over some slab unions; `slabOutline()` rounds to 0.1 mm and joins piece by
  piece then.
- Section lines (`SectionLine`, `section_lines`, element type `SectionLine`, `SectionLineHandler` through `SceneEditor`:
  undo, change log; REST `…/sections`, MCP `add_section_line` / `update_section_line` / `delete_section_line`, the
  scene's and snapshots' `sections`, `SnapshotSectionRestorer`): start → end in plan mm, looking to the left of it,
  `depth` beyond (null: all); a new one runs west to east through the middle of the floor's walls, looking north,
  named by the next free name (A … Z, AA …; up to two characters it is drawn as `A–A`); a copy lies a metre the way it
  looks under the next name (`SectionLineHandler::copied()`). The top view draws it dash-dot with arrows the way it
  looks (`section-lines.ts`, `parts.sectionLines`, `planOnly`, tagged `sectionId`), lifted just over the roof outlines
  so a click picks it before the walls and furniture; it is dragged like a railing, the arrows nudge it and R turns it
  round (swaps its ends). The plan PDF marks it (`sectionMarks()`).
- glTF: `ViewerEngine.exportGlb()` writes the built floor through `GLTFExporter` (loaded lazily). `exportTree()`
  (`viewer/engine/gltf-export.ts`) copies the content with the ceilings shown and leaves out the room labels, the
  section-fill twins, the selection highlight and hidden variants; nodes are named after their elements and
  `userData` is cleared. Doors keep their current pose. Every fixture light goes in as `KHR_lights_punctual`, on, in
  candela and its real colour (`LumenScale` and `WhiteBalance` undone), spots turned to shine along −z; LED strips
  (area lights) are left out. The export waits for the models and pictures still loading.

## Plan underlays

- A floor may have one `FloorUnderlay` (`floor_underlays`): an image project file laid under the top view for tracing.
  Pixel (px, py), with py counted down from the top, lies at plan `x + scale·(px·cos r + py·sin r)`,
  `y + scale·(px·sin r − py·cos r)`. It goes with the floor and with its file (both cascade).
- `UnderlayService` sets it (placement given, or calibrated from two pixel/plan point pairs by `placementFromPoints()`,
  twinned in `viewer/engine/underlay.ts`) and removes it. Both bump the revision, but it is not an element: there is no
  `SceneEditor`, no change log and no undo. REST `PUT|DELETE …/floors/{id}/underlay`, MCP `set_floor_underlay` /
  `remove_floor_underlay`.
- The scene carries `underlay`, so versions keep it. `SnapshotUnderlayRestorer` restores it while the file still exists.
- Viewer: `buildUnderlay()` draws the image over the plan at its opacity (no depth test), only while `isTopDown()`.
  `UnderlayPanelComponent` (edit mode) picks the image and sets its placement. Calibrate… uses the measure tool without
  snapping (`EditorStore.calibration`, `ViewerEngine.setCalibrating()`), then `calibrateUnderlay()` applies the real
  distance and an optional axis. A PDF page is made into a PNG project file first (`Service/Project/Pdf`:
  `PdfRasterizer` runs poppler's `pdfinfo` / `pdftoppm` from the backend image, at `dpi()`: 150 dpi, held to 6000 px
  along the longer side and 28 megapixels; only the first `MaxPages` (500) pages; `PdfPageImageService`, REST
  `GET …/files/{id}/pdf` (`pageCount` and the pages), `GET …/pdf/pages/{n}?size=` (a thumbnail, `pdftoppm -scale-to`
  without `pdfinfo`) and `POST …/pdf/pages/{n}/image`), which becomes the underlay; the stored underlay stays an image.
  That PNG is not held to the upload size limit, and the panel deletes it again when the underlay is not saved. The panel lists PDFs beside the images and shows `PdfPagePickerComponent` for one of several
  pages (`EditorStore.layPdfPage()`).
- DXF import (`Service/Dxf`, PHP only; REST `GET …/files/{id}/dxf` (layers), `POST …/floors/{id}/dxf-import/preview`
  and `…/dxf-import`, MCP `inspect_dxf` / `import_dxf` with `dryRun`): an ASCII DXF among the project files (DWG and
  binary DXF refused) becomes walls and openings as one `ChangeBatch`. `DxfReader` (`Read/`) flattens the model space
  into lines and arcs in world coordinates (blocks expanded, object coordinate systems, bulges, hatch boundaries,
  `$INSUNITS`, the codepage); `DxfPlanner` (`Plan/`) pairs parallel faces of the wall layers 50–700 mm apart into
  wall pieces (`FaceSet`, `WallPairer`; concentric arcs: `ArcPairer`), joins collinear pieces with the gaps as openings
  where both faces stop (`CollinearMerger`), meets ends at L and T joints (`EndJoiner`), reads each opening's kind from
  the opening layers (a swing arc: a door, its hinge and side; glazing lines: a window; door / window block names;
  nothing: a passage) and finds doors and windows drawn over unbroken walls (`OpeningFinder`), then places and rounds
  them (`Placement`: the walls' south-west corner or the drawing's origin at the plan origin, plus an offset and a
  turn). Walls come in `Interior`; heights come from the import's options; an opening the editor refuses is left out
  with a warning; `replaceExisting` deletes the floor's walls in the same batch. No rooms. The underlay panel's
  `DxfImportComponent` picks the layers and draws the preview (`EditorStore.dxfPreview`, `viewer/engine/dxf-preview.ts`,
  top view, no depth test). `backend/tests/fixtures/dxf/third-party/` holds public sample plans (their licences in its
  README) that `DxfPlannerTest` pins.
- MCP `list_project_files` / `get_project_file` let an agent read uploaded plans and photos (and the upload tools,
  see MCP server, add them). A PDF comes back as one `page` rendered as its underlay would be, so `set_floor_underlay`
  with that PDF and `page` (which makes the PNG file and lays it) takes points read off it. Images come back as image
  content, optionally cropped, scaled to `maxSize` and overlaid with a labelled pixel `grid` (`ImagePreviewService`,
  which needs PHP gd).

## Share links

- `ShareLink` / `ShareLinkProvider`: a guest view of one floor at `/s/{token}`, without sign-in. The public
  `GET /api/shares/{token}` is opened via `AuthorizationMiddleware::ShareRoutePattern`, and `jwtInterceptor` sends no
  token there.
- Creating a link saves a version ("Shared: <name>"). Refreshing it (`POST …/share-links/{id}/refresh`, MCP
  `refresh_share_link`, the project page's Update button) saves a new version for the same token. `floorId` is the
  floor's id inside that snapshot.
- `settings` (JSON, `ShareSettingsDto`) holds the allowed views, the start view, an optional 3D camera (plan mm, from
  `ViewerEngine.cameraPose()` / `setCameraPose()`) and the display options.
- `SharedViewerPageComponent` switches variants locally only. Expired or unknown links answer 404.

## Live updates

- `ProjectRevisionService::bump()` marks the project in `ProjectUpdatePublisher`. The worker publishes after
  `frankenphp_finish_request()`, so only committed revisions are sent, to the private Mercure topic
  `izbik/projects/{id}`.
- Subscribers get a cookie from `/api/realtime/subscription` and listen on `/.well-known/mercure?match=<topic>`
  (Mercure protocol 1.0 uses `match`, not `topic`).
- Project details (name, address, description) are not floor content: `ProjectProvider::update` sends `detailsChanged`
  (`markDetailsChanged()`, no revision bump), and the project and viewer pages reload the project.
- Each tab sends `X-Origin-Client-Id` so it can skip its own echoes. Tests record updates in `RecordingMercureHub`.

## MCP server

- `McpController` (`/mcp`, `mcp/sdk` Streamable HTTP, sessions in `mcp_sessions`) authenticates the OAuth access
  token (`OAuth\AuthorizationService`), then sets `McpUserContext` (including the base URL used for share links) and
  the agent `ActorContext`.
- Tools live in `src/Mcp/Tool` and are discovered by `#[McpTool]` (this needs symfony/finder). Keep them as thin
  wrappers over `SceneEditor` and the providers, with typed, documented parameters: the docblock becomes the schema,
  and JSON objects need `#[Schema(definition: …)]`.
- Domain `RuntimeException`s reach the agent as tool errors (`ToolErrorTranslatingReferenceHandler`).
- `FurnitureTools::Format` is the format text agents get; a test checks that it names every `PartTypeEnum` case.
- Uploads: `upload_project_file` / `upload_model` take small files as base64. An LLM cannot write megabytes into a
  call, so `request_file_upload` / `request_model_upload` return a one-time URL (`UploadTicket`, `UploadTicketService`)
  that the agent PUTs the file to with curl. `PUT /api/uploads/{token}` is open in `AuthorizationMiddleware` (the
  token authorizes it), stores the file through `ProjectFileProvider` / `ModelAssetProvider` as the ticket's user and
  works once for 15 minutes; a rejected file releases the ticket. `delete_project_file` / `delete_model` remove them.
- `tests/Mcp/McpServerTest` drives the server over JSON-RPC.

## Example projects

- `backend/examples/<slug>.json` (`ExampleCatalogue`: `{format, name, description, credit, startView, snapshot}`) are
  projects every user may start from: `GET /api/examples`, `POST /api/examples/{slug}/projects` (MCP `list_examples` /
  `create_project_from_example`; the projects page's "Start from an example") makes a project of the user's
  (`ExampleProjectService`) and answers the floor and saved view to open it at. `bin/console examples:export <project>
  <slug> [--name … --description … --credit … --start-view … --without-views PREFIX]` writes one from a working copy,
  without its files (`SnapshotFiles::withoutFiles()`: underlays, pictures, image parameters). The demo flat is
  `demo-flat` (its "Photo …" views left out); re-export it after a change of the demo project.
- The snapshot comes from another instance, so it is imported, not restored: `ProjectSnapshotService::import()` puts
  it onto this one first (`SnapshotLocalizer`): materials by code (`SnapshotMaterials`: every `materialId` /
  `…MaterialId` key and a Custom item's slot materials; a finish band or slot without one dropped), models by source URL
  among the global ones and the owner's library (`SnapshotModels`; none: a box), files left out, and definitions by name and
  content among the global ones (`SnapshotDefinitionRestorer` with `$foreign`; else a project copy). The project takes the
  snapshot's name, details, site and façade material. `import()` takes untrusted input: `SnapshotLimits` caps it, and a
  value the restore cannot take (`ValueError` / `TypeError`) is an `InvalidSnapshotException`; elements are not
  validated as edits are.
- Project files (`Service/Project/Transfer`, REST `GET …/export` (`?files=0`: the JSON alone) and `POST
  /api/projects/import` in `ProjectTransferController`; nginx takes 512 MB there and streams the export): `ProjectImporter` makes a new project of the user's from one
  (`ProjectBundleReader`: zip or JSON by the first bytes, at most `IMPORT_MAX_SIZE_MB` unpacked, `MaxEntries`; entries
  read only by the manifest's paths, each once, never extracted; `SnapshotLimits` checked before anything is stored) in
  one transaction: `BundleAssetImporter` uploads the files and
  the models no model matches by source URL as the project's own (`ImportedAssets`, the new ids, which
  `SnapshotFiles::withFiles()` and `SnapshotModels` put into the snapshot), `BundleLibraryImporter` the own definitions
  (validated) and the prices (materials by code). What this instance cannot take is left out as a `warnings` entry of
  `ProjectImportDto`; on a failure the stored objects are deleted again. UI: the project page's Download and Without
  files, the projects page's Import (opens the new project, the warnings as toasts). MCP `request_project_import`: an
  upload ticket of kind `ProjectImport` whose body goes to a temporary file (not memory) before the import.
- Models: `make example-models` runs `frontend/scripts/example-models.mjs` in a Node container: the Poly Haven models
  the examples list (`sourceUrl` `https://polyhaven.com/a/<asset>`), fetched through the Poly Haven API (a User-Agent
  naming Izbik, md5 checked; its terms allow this, crediting Poly Haven), optimised with gltf-transform as in Product
  models, into `data/example-models/` (gitignored), then `make import-models`. `models:import` skips a model whose
  source the scope has already, so both run again safely.

## Seed data and files

- Seed data lives in migrations (`Seed*`, `Fix*`, `Convert*`). The early ones predate `AddProjectsAndFloors` and still
  use `flat_id`.
- **Data changes go into a new migration.** Editing an already applied migration requires `make reset-db`, which drops
  the database volume. Do not rename existing migration classes.
- Project files (plans, renders, photos) are uploaded to S3 (MinIO in the stack) through `ProjectFileProvider`. Tests
  swap `FileStorageInterface` for `Tests\Support\InMemoryFileStorage`.
- Reference files of the sample project are not in git. Keep them locally in `source_files/` (gitignored) and import
  them with `make import-files DIR=source_files PROJECT=1`.

## Code style

- Plans (`docs/*-plan.md`) are local working notes, gitignored: never commit them.
- Never mention ukolio task numbers (`I-…`) in code, comments, test names or fixtures; describe the feature instead.
  Ticket numbers belong in commit messages, branch names and the docs.
- PHP: tabs, the slevomat ruleset copied from ukolio, phpstan at max level.
- TS: the eslint config from ukolio, explicit member accessibility and return types, max line length 150.

## Commands

| Command | What it does |
| --- | --- |
| `make up` | Build and start the stack (creates `.env` and secrets on first run) |
| `make deploy` | Start the published GHCR images of `IZBIK_VERSION` instead of building (`.github/workflows/release.yml` pushes them on a `v*` tag; `docs/self-hosting.md`) |
| `make admin` | Create an admin account |
| `make backup [BACKUP=…]` / `make restore BACKUP=…` | Back up / restore the database and the stored files |
| `make reset-password EMAIL=…` | Set a user's password from the server (no mail needed) |
| `make import-files DIR=… PROJECT=…` | Upload a directory as project files |
| `make export-project PROJECT=… [WITHOUT_FILES=1]` | Write a project as a project file into `backups/` |
| `make dev` | `ng serve` on 4200 against the running stack |
| `make lint` | phpcs + phpstan, eslint |
| `make test` | phpunit in a one-off backend container against the stack's MariaDB (database `izbik_test`; integration tests extend `Tests\Support\IntegrationTestCase`), then vitest |
| `make smoke` | Browser smoke test of the running stack (`frontend/scripts/smoke.mjs`, sign-up open; CI's E2E workflow): sign up, walls, a room, a door, a share link, the example, its export and import |
| `make benchmark PROJECT=… EMAIL=… PASSWORD=… [BASELINE=…]` | Time the rendering of a project's saved views (I-58) |
| `make reset-db` | Drop the database and file volumes and re-seed |
