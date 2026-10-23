import {Item, PlanPoint, Plot, Room, Scene, SunTime, Underlay, Wall} from '@app/models/scene';
import {CameraPoint, CameraPose} from '@app/models/share';
import * as THREE from 'three';
import {OrbitControls} from 'three/examples/jsm/controls/OrbitControls.js';
import {RoomEnvironment} from 'three/examples/jsm/environments/RoomEnvironment.js';
import {RectAreaLightUniformsLib} from 'three/examples/jsm/lights/RectAreaLightUniformsLib.js';
import {CSS2DObject, CSS2DRenderer} from 'three/examples/jsm/renderers/CSS2DRenderer.js';

import {
    addRoom,
    buildBuilding,
    buildCeilingSteps,
    buildFloor,
    BuildingParts,
    buildRoom,
    buildRoomFinishes,
    buildSlab,
    buildWall,
    hasFaceFinishes,
} from './building';
import {CommentPin, CommentPins, CommentPoint, commentPointOf} from './comments';
import {changedDefinitionIds, definitionLibrary} from './custom/custom';
import {alignDimensionLabels, buildDimensions, roomDimensions} from './dimensions';
import {leafObjects, LeafStates} from './doors';
import {closesRun, directionOf, EditorTool, pointAt, sagittaToward, snapDrawPoint, ToolStatus} from './drawing';
import {
    BenchmarkOptions,
    BenchmarkResult,
    FrameStats,
    gpuName,
    median,
    percentile,
    RenderCounters,
    renderCounters,
    sceneCounters,
    ViewerStats,
} from './frame-stats';
import {exportTree, toGlb} from './gltf-export';
import {buildItem, ItemAssets} from './items';
import {applyFixtureLights} from './lights';
import {MaterialLibrary} from './materials';
import {measureClick, Measurement, MeasureStatus, measureStatus, NoMeasurement, snapMeasurePoint} from './measure';
import {ModelFetcher, ModelLibrary} from './models';
import {PictureFetcher, PictureLibrary} from './pictures';
import {
    alongWall,
    bendWall,
    Bounds,
    clampSagitta,
    columnColliders,
    cornerWindows,
    edgeMidpoints,
    entranceWalkStart,
    insertVertex,
    isSimplePolygon,
    leafColliders,
    MM,
    moveWallEnds,
    pointInPolygon,
    pointOnWall,
    polygonBounds,
    polygonCentroid,
    polygonSignedArea,
    removeVertex,
    slideOpening,
    snapPoint,
    SnapResult,
    snapToGrid,
    Vec2,
    WalkStart,
    WallCollider,
    wallColliders,
    WallCorners,
    WallEnds,
    wallFaces,
    wallFrame,
    wallJoints,
} from './plan-geometry';
import {buildPlot, highlightPlot} from './plots';
import {
    PresentationPipeline,
    PresentationPixelRatio,
    PresentationSettleMs,
    RenderQuality,
    sunShadowMapSize,
    SunShadowRadius,
    viewChanged,
} from './presentation';
import {pickRoom, prepareRoomFaces, RoomDetection, RoomFaces} from './room-detect';
import {sameSelection, Selection, selectionOf} from './selection';
import {daylightAt, DefaultSun, SunAngles, sunAt, sunDirection} from './sun';
import {buildUnderlay, disposeUnderlay, updateUnderlay} from './underlay';
import {hiddenItemIds} from './variants';
import {Walkthrough, WalkthroughFov} from './walkthrough';

export type ViewPreset = 'overview' | 'top';

interface CameraTween {
    fromPosition: THREE.Vector3;
    fromTarget: THREE.Vector3;
    toPosition: THREE.Vector3;
    toTarget: THREE.Vector3;
    start: number;
    duration: number;
}

const BackgroundColor = '#e8e4dd';
/** The sky after dark. */
const NightBackgroundColor = '#1b2029';
/**
 * Daylight (sun, sky, reflections) by day and by night: at night only a faint blue sky light is left. The sun's height
 * blends between them (`daylightAt()`).
 */
const Daylight = {sun: 2.4, sky: 1.4, skyColor: '#fbf8f2', environment: 0.45};
const Nightlight = {sky: 0.05, skyColor: '#5a6f99', environment: 0.03};
/** The sun's distance from the plan centre beyond the radius its shadow covers, m. */
const SunDistance = 20;
const HighlightColor = '#d9822b';
const TweenDuration = 900;
/** Framing of a floor with nothing on it yet (plan mm). */
const EmptyFloorBounds: Bounds = {minX: 0, minY: 0, maxX: 10000, maxY: 10000};
const OrbitFov = 45;

/** How far away a door can be opened in the walkthrough, metres from the eye. */
const DoorReach = 2.2;

/** Shortest wall the backend accepts, mm. */
const MinWallLength = 100;
/** Input on the canvas that may change the view; pointer moves invalidate only where they draw something. */
const InputEvents = ['pointerdown', 'pointerup', 'pointerleave', 'dblclick', 'wheel'] as const;
/** The floor level, where the drawing tools place points. */
const FloorPlane = new THREE.Plane(new THREE.Vector3(0, 1, 0), 0);
/** Snap grid for dragged elements, mm (Shift: whole mm, no snapping). */
const DragSnap = 10;
/** Dragging works only while the camera looks down steeply (Plan view), where plan moves are unambiguous. */
const DragMaxCameraTilt = -0.9;
/** The pointer has to travel this far (px) before a press becomes a drag. */
const DragThreshold = 4;
/** Snapping onto points and axes reaches this far (px on screen). */
const SnapPixels = 12;
/** On-screen diameters of the handles, px. */
const HandlePixels = 14;
const MidpointPixels = 10;

const HandleColors = {
    normal: HighlightColor,
    snapped: '#2f9e6b',
    invalid: '#d64545',
    midpoint: '#f3c08f',
} as const;
type HandleState = keyof typeof HandleColors;

/** Commits an edit made by dragging; resolves true when it was applied (the scene then reloads). */
export type EditHandler = (selection: Selection, fields: Record<string, unknown>) => Promise<boolean>;

/** What a handle of the selected wall or room stands for; `draw` marks the points of a run of walls (not draggable). */
type HandleRole =
    | {role: 'wallStart' | 'wallEnd' | 'wallCurve'}
    | {role: 'vertex'; index: number}
    | {role: 'midpoint'; index: number}
    | {role: 'draw'};

/** Wall fields of a run being drawn, for its preview. */
export interface WallDraftShape {
    thickness: number;
    height: number;
}

/**
 * The run of walls being drawn: the points placed, the curve of each segment between them, and where the pointer would
 * put the next one. In arc mode a placed segment waits for its curve (`bend`: the last one, or the closing one).
 */
interface WallRun {
    points: Vec2[];
    sagittas: number[];
    cursor: Vec2 | null;
    snap: SnapResult['kind'] | null;
    bend: {closing: boolean; sagitta: number} | null;
}

/** An element whose outline gets corner handles: a room or a plot. */
type Outlined = {kind: 'room'; element: Room} | {kind: 'plot'; element: Plot};

interface DragBase {
    plane: THREE.Plane;
    /** Plan point (mm) under the pointer when the drag started. */
    grab: Vec2;
    /** Past the drag threshold. */
    moved: boolean;
}

type Drag = DragBase &
    (
        | {kind: 'item'; item: Item; object: THREE.Object3D; x: number; y: number}
        | {kind: 'group'; groupId: number; members: {item: Item; object: THREE.Object3D}[]; dx: number; dy: number}
        | {kind: 'wall'; wall: Wall; mode: 'start' | 'end' | 'curve' | 'move'; current: Wall; fits: boolean; snap: SnapResult['kind'] | null}
        | {kind: 'opening'; wall: Wall; openingId: number; grabAlong: number; offset: number; fits: boolean}
        | {kind: 'vertex'; outline: Outlined; index: number; inserted: boolean; polygon: PlanPoint[]; valid: boolean; snap: SnapResult['kind'] | null}
    );

/** The door (or walkable French window) the walker looks at, and whether it is open or opening. */
export interface DoorAim {
    openingId: number;
    open: boolean;
}

/** Objects shown instead of the originals while a drag (or its commit) is in progress. */
interface Preview {
    hidden: THREE.Object3D[];
    added: THREE.Object3D[];
}

/**
 * Framework-free three.js viewer for one floor: owns the renderer, camera, controls and the scene graph
 * built from a `Scene`. The Angular component only forwards options and events.
 */
export class ViewerEngine {
    /**
     * A click selected an element (or empty space: null); `additive` for Shift+click, which picks items to group.
     * Clicking an item of a group selects the group, unless Alt is held, the item is double-clicked or another
     * item of the same group is selected.
     */
    public onSelect: (selection: Selection | null, additive: boolean) => void = () => undefined;
    /**
     * Commits a drag in edit mode: an item, group or wall moved, a wall end, door / window or room corner dragged.
     * Until it resolves the dragged state stays on screen; when it was not applied the element snaps back.
     */
    public onEdit: EditHandler = () => Promise.resolve(false);
    /** The walkthrough ended (Esc); the camera is on its way back to the overview. */
    public onWalkthroughEnd: () => void = () => undefined;
    /** The walkthrough captured the mouse, or lost it / was refused it (the walker has to click the view). */
    public onWalkthroughLock: (locked: boolean) => void = () => undefined;
    /** The walker started or stopped looking at a door (or walkable French window) within reach, or it opened / closed. */
    public onWalkthroughAim: (aim: DoorAim | null) => void = () => undefined;
    /** A run of walls was drawn (centre-line points); resolves true when added. Until then the run stays on screen. */
    public onDrawWalls: (points: PlanPoint[], closed: boolean, sagittas: number[] | null) => Promise<boolean> = () => Promise.resolve(false);
    /** A click with the room tool inside walls that close around the point. */
    public onDetectRoom: (point: PlanPoint) => void = () => undefined;
    /** The readout of the drawing tool changed; null when no tool is active or the pointer left the view. */
    public onToolStatus: (status: ToolStatus | null) => void = () => undefined;
    /** The readout of the measure tool changed; null when it is off. */
    public onMeasureStatus: (status: MeasureStatus | null) => void = () => undefined;
    /** Both calibration points were clicked (plan mm, where they lie on the underlay as placed now). */
    public onCalibrate: (a: Vec2, b: Vec2) => void = () => undefined;
    /** A comment pin was clicked: its thread. */
    public onCommentPick: (id: number) => void = () => undefined;
    /** With the comment tool on, a click on the view: the point of the surface under the pointer. */
    public onCommentPlace: (point: CommentPoint) => void = () => undefined;

    private readonly renderer: THREE.WebGLRenderer;
    private readonly labelRenderer: CSS2DRenderer;
    private readonly scene = new THREE.Scene();
    private readonly camera = new THREE.PerspectiveCamera(OrbitFov, 1, 0.05, 300);
    private readonly controls: OrbitControls;
    private readonly sun = new THREE.DirectionalLight('#fff3e0', Daylight.sun);
    private readonly sky = new THREE.HemisphereLight('#fbf8f2', '#a9a196', Daylight.sky);
    /** After dark: no sun, the flat lit by its own lights. */
    private night = false;
    /** The clock time on site the sun stands at; null: the default afternoon sun. */
    private sunTime: SunTime | null = null;
    private sunAngles: SunAngles = DefaultSun;
    /** The fixture lights (light parts, LED strips) are on. */
    private lightsOn = false;
    private quality: RenderQuality = 'fast';
    /** The post-processing of the presentation quality; null in the fast one. */
    private presentation: PresentationPipeline | null = null;
    /** The camera as last rendered, and when it last moved: the presentation pipeline waits for it to stand still. */
    private readonly renderedView = new THREE.Matrix4();
    private readonly renderedProjection = new THREE.Matrix4();
    private cameraMovedAt = 0;
    /** Every frame through the presentation pipeline, moving or not: a capture, a benchmark. */
    private stillFrames = false;
    private readonly sectionPlane = new THREE.Plane(new THREE.Vector3(0, -1, 0), 10);
    private readonly resizeObserver: ResizeObserver;
    private readonly raycaster = new THREE.Raycaster();
    private readonly pointerDown = new THREE.Vector2();

    /** Rendered after the scene without the section plane: handles stay visible above the cut. */
    private readonly overlay = new THREE.Scene();
    private readonly handles = new THREE.Group();
    private readonly handleGeometry = new THREE.CircleGeometry(0.5, 24).rotateX(-Math.PI / 2);
    private readonly handleCoreGeometry = new THREE.CircleGeometry(0.28, 24).rotateX(-Math.PI / 2);
    private readonly handleMaterials = new Map<string, THREE.MeshBasicMaterial>();
    /** The measure tool's line and points, drawn above the cut like the handles. */
    private readonly measureLayer = new THREE.Group();
    private readonly measureLabel = new CSS2DObject(document.createElement('div'));
    /** The pins of the review comments, kept over loads of the floor. */
    private readonly commentPins = new CommentPins();
    /** The comment tool: a click pins a new comment to the surface under the pointer (before the measure and edit tools). */
    private commenting = false;

    private content: THREE.Group | null = null;
    private materials: MaterialLibrary | null = null;
    private parts: BuildingParts | null = null;
    private labels = new Map<number, CSS2DObject>();
    private labelsVisible = true;
    /** Dimension lines of the rooms, shown in the top view when turned on. */
    private dimensions: THREE.Group | null = null;
    private dimensionsVisible = false;
    /** The shown dimensions belong to an earlier state of the floor; rebuilt when next shown. */
    private dimensionsStale = true;
    /** The measure tool: on in any top view (edit mode or not), a click places the points. */
    private measuring = false;
    private measurement: Measurement = NoMeasurement;
    /** The measure tool picks the two calibration points of the underlay: no snapping, and both are reported. */
    private calibrating = false;
    /** The plan image under the top view, shown there only. */
    private underlay: THREE.Mesh | null = null;
    /** Loads the 3D models of `Model` items; none until the host supplies a fetcher. */
    private modelLibrary: ModelLibrary | null = null;
    private pictureLibrary: PictureLibrary | null = null;
    private underlayImage: TexImageSource | null = null;
    private underlayVisible = true;
    private highlight: THREE.Mesh | null = null;
    /** Translucent box with edges around the selected item, wall, opening or group; follows it while dragged. */
    private outline: THREE.Mesh | null = null;
    private outlinedObjects: THREE.Object3D[] = [];
    /** Items picked with Shift+click, each with its own outline box. */
    private picked: number[] = [];
    private pickedOutlines: {mesh: THREE.Mesh; object: THREE.Object3D}[] = [];
    private items = new Map<number, THREE.Object3D>();
    private walls = new Map<number, THREE.Object3D>();
    private openings = new Map<number, THREE.Object3D>();
    /** Door leaves and French window sashes (`userData.leaf`), swung by `leafStates`. */
    private leaves: THREE.Object3D[] = [];
    private readonly leafStates = new LeafStates();
    /** The door in view during a walk, as last reported to `onWalkthroughAim`. */
    private aim: DoorAim | null = null;
    private selection: Selection | null = null;
    private editMode = false;
    private tool: EditorTool = 'select';
    private wallDraft: WallDraftShape = {thickness: 100, height: 2600};
    private run: WallRun | null = null;
    /** A drawn run sent off and not yet loaded back, still shown. */
    private pendingRun: {points: Vec2[]; closed: boolean; sagittas: number[]} | null = null;
    /** Arc mode of the draw tool: each segment is bent by one more click. */
    private drawArc = false;
    /** The room the room tool would add at the pointer (kept while the pointer stays inside it). */
    private detection: RoomDetection | null = null;
    /** The faces between the loaded walls, for the room tool; computed when first needed after a load. */
    private roomFaces: RoomFaces | null = null;
    /** Previews of the drawing tools: the walls of a run, the outline of a room. */
    private readonly drawLayer = new THREE.Group();
    private drag: Drag | null = null;
    private preview: Preview | null = null;
    /** Bumped by every load; a commit whose load never comes (refused, no-op) restores its preview. */
    private loads = 0;
    private sectionHeight: number | null = null;
    private ceilingsVisible = true;
    private joints = new Map<number, WallEnds>();
    /** Corner windows by wall id (`cornerWindows()`), as the walls were built. */
    private corners = new Map<number, WallCorners>();
    private bounds: Bounds = {minX: 0, minY: 0, maxX: 1, maxY: 1};
    private insetLeft = 0;
    private tween: CameraTween | null = null;
    /** Floor currently shown; reloading the same floor (a live update) keeps the camera where it is. */
    private loadedFloorId: number | null = null;
    private sceneData: Scene | null = null;
    private walkthrough: Walkthrough | null = null;
    private lastFrame = performance.now();
    /** Something was dragged, previewed or drawn in the last frame: its shadow follows it, and is redrawn once it ends. */
    private moving = false;
    /**
     * The view has to be drawn again (`invalidate()`). Frames are rendered on demand: when something has changed, while
     * the camera, a door or a walk moves, and until the presentation quality's still frame follows the moving ones.
     */
    private needsRender = true;
    /** The last frame was a presentation's moving frame: the still one is still to come. */
    private renderedMoving = false;
    /** CPU time of the last frames, for the `?stats` readout. */
    private readonly frameStats = new FrameStats();
    /** What the last frame drew, all its passes together. */
    private lastCounters: RenderCounters | null = null;
    /** Time the last full build took, ms. */
    private buildMs = 0;

    public constructor(private readonly container: HTMLElement) {
        RectAreaLightUniformsLib.init();

        this.renderer = new THREE.WebGLRenderer({antialias: true, powerPreference: 'high-performance'});
        this.renderer.setPixelRatio(Math.min(window.devicePixelRatio, 2));
        this.renderer.outputColorSpace = THREE.SRGBColorSpace;
        this.renderer.toneMapping = THREE.ACESFilmicToneMapping;
        this.renderer.toneMappingExposure = 1.0;
        this.renderer.shadowMap.enabled = true;
        this.renderer.shadowMap.type = THREE.PCFShadowMap;
        // The shadow passes draw the scene again for the sun and six times per shadow-casting fixture: they run only when
        // something casting a shadow has changed (`refreshShadows()`), or every frame while something is being moved.
        this.renderer.shadowMap.autoUpdate = false;
        // Counters per frame rather than per render call: `tick()` resets them, so the shadow and post-processing passes add up.
        this.renderer.info.autoReset = false;
        container.appendChild(this.renderer.domElement);

        this.labelRenderer = new CSS2DRenderer();
        this.labelRenderer.domElement.classList.add('viewer-labels');
        container.appendChild(this.labelRenderer.domElement);

        this.controls = new OrbitControls(this.camera, this.renderer.domElement);
        this.controls.enableDamping = true;
        this.controls.dampingFactor = 0.08;
        this.controls.maxPolarAngle = THREE.MathUtils.degToRad(88);
        this.controls.minDistance = 0.5;
        this.controls.maxDistance = 60;
        this.controls.addEventListener('start', () => {
            this.tween = null;
            this.controls.enableDamping = true;
        });

        this.setupEnvironment();
        this.handles.name = 'handles';
        this.overlay.add(this.handles);
        this.drawLayer.name = 'drawing';
        this.scene.add(this.drawLayer);
        this.measureLayer.name = 'measure';
        this.overlay.add(this.measureLayer);
        this.measureLabel.element.className = 'measure-label';
        this.measureLabel.visible = false;
        this.scene.add(this.measureLabel);
        this.scene.add(this.commentPins.group);
        this.commentPins.onPick = (id): void => this.onCommentPick(id);

        // The controls report every change of the camera, damping included, as they update it.
        this.controls.addEventListener('change', this.invalidate);
        for (const type of InputEvents) {
            this.renderer.domElement.addEventListener(type, this.invalidate);
        }
        document.addEventListener('visibilitychange', this.invalidate);
        this.renderer.domElement.addEventListener('pointerdown', this.handlePointerDown);
        this.renderer.domElement.addEventListener('pointermove', this.handlePointerMove);
        this.renderer.domElement.addEventListener('pointerup', this.handlePointerUp);
        this.renderer.domElement.addEventListener('pointerleave', this.handlePointerLeave);
        this.renderer.domElement.addEventListener('dblclick', this.handleDoubleClick);
        // Capturing, so Esc cancels a drag before the editor's shortcut clears the selection.
        window.addEventListener('keydown', this.handleKeyDown, true);

        this.resizeObserver = new ResizeObserver(() => this.resize());
        this.resizeObserver.observe(container);
        this.resize();

        this.renderer.setAnimationLoop(() => this.tick());
    }

    /**
     * Shows a scene. Another state of the floor already shown (an edit, a live update) only rebuilds the
     * elements that changed; a different floor, or changed materials, builds everything anew.
     */
    public load(scene: Scene): void {
        this.invalidate();
        this.cancelDrag();
        this.clearPreview();
        this.loads++;
        const previous = this.sceneData;
        // Pictures of files deleted since go plain, and a fresh scene listing a file lets a failed load try again: their
        // items are redrawn. Models the same way.
        const changedPictures = new Set(this.pictureLibrary?.sync(scene.pictures ?? []) ?? []);
        const changedModels = new Set(this.modelLibrary?.sync((scene.models ?? []).map((m) => m.id)) ?? []);
        if (previous !== null && this.parts !== null && previous.floor.id === scene.floor.id && sameJson(previous.materials, scene.materials)) {
            this.applyChanges(previous, scene, changedPictures, changedModels);
        } else {
            const started = performance.now();
            this.build(scene);
            this.buildMs = performance.now() - started;
        }
        this.sceneData = scene;
        this.showVariants(scene);
        this.applyLights();

        const wallPoints = scene.walls.flatMap((w) => [[w.startX, w.startY], [w.endX, w.endY]] as [number, number][]);
        const points = [...wallPoints, ...scene.rooms.flatMap((r) => r.polygon)];
        this.bounds = points.length > 0 ? polygonBounds(points) : EmptyFloorBounds;
        this.fitSunShadow();
        if (this.loadedFloorId !== scene.floor.id) {
            if (this.walkthrough !== null) {
                this.stopWalkthrough(false);
                this.onWalkthroughEnd();
            }
            this.setView('overview', false);
        }
        if (this.walkthrough === null) {
            this.leafStates.reset(scene.walls);
        } else {
            this.leafStates.sync(scene.walls);
        }
        this.leafStates.apply(this.leaves);
        this.refreshShadows();
        this.walkthrough?.setColliders(sceneColliders(scene));
        this.updateLeafColliders();
        this.loadedFloorId = scene.floor.id;
        this.setSelection(this.selection);
        this.setPicked(this.picked);
        if (this.pendingRun !== null && this.hasWallsAlong(scene, this.pendingRun)) {
            // The drawn walls arrived; a refused run is restored by `commitRun` instead.
            this.pendingRun = null;
        }
        this.detection = null;
        this.roomFaces = null;
        this.updateDrawing();
        this.dimensionsStale = true;
        this.updateMeasure();
    }

    private removeDimensions(): void {
        this.dimensions?.traverse((o) => disposeLine(o));
        this.dimensions?.removeFromParent();
        this.dimensions = null;
    }

    /** Dimension lines of the shown rooms, built when they are first shown after a load (walls and rooms change them). */
    private updateDimensions(scene: Scene): void {
        this.removeDimensions();
        this.dimensions = buildDimensions(roomDimensions(scene.rooms, scene.walls));
        this.scene.add(this.dimensions);
        this.dimensionsStale = false;
    }

    /** Hides the items of variants that are not shown; switching a variant rebuilds nothing. */
    private showVariants(scene: Scene): void {
        const hidden = hiddenItemIds(scene);
        this.items.forEach((object, id) => (object.visible = !hidden.has(id)));
    }

    private build(scene: Scene): void {
        this.clearContent();

        const anisotropy = this.renderer.capabilities.getMaxAnisotropy();
        this.materials = new MaterialLibrary(scene.materials, anisotropy);
        this.materials.onTextureLoaded = this.invalidate;

        const content = new THREE.Group();
        content.name = `floor:${scene.floor.name}`;

        this.parts = buildBuilding(scene, this.materials);
        this.parts.ceilings.visible = this.ceilingsVisible;
        content.add(this.parts.root);
        this.joints = wallJoints(scene.walls);
        this.corners = cornerWindows(scene.walls);

        const rooms = new Map(scene.rooms.map((room) => [room.id, room]));
        const definitions = definitionLibrary(scene.definitions);
        const assets = this.itemAssets(scene);
        for (const item of scene.items) {
            const object = buildItem(item, this.materials, rooms.get(item.roomId ?? 0), definitions, assets);
            this.showCeilingParts(object);
            this.items.set(item.id, object);
            content.add(object);
        }
        for (const room of scene.rooms) {
            const label = this.createLabel(room);
            this.labels.set(room.id, label);
            content.add(label);
        }

        this.content = content;
        this.scene.add(content);
        this.indexWalls();
    }

    /**
     * Rebuilds what differs between two states of the shown floor, and the items whose picture or model changed in the
     * libraries (dropped, or failed and listed again).
     */
    private applyChanges(previous: Scene, scene: Scene, changedPictures: ReadonlySet<number>, changedModels: ReadonlySet<number>): void {
        const parts = this.parts;
        const materials = this.materials;
        const content = this.content;
        if (parts === null || materials === null || content === null) {
            return;
        }

        const rooms = diffById(previous.rooms, scene.rooms);
        const items = diffById(previous.items, scene.items);
        // Tiled items follow their room's tile grid; Custom items their definition.
        const changedRooms = new Set(rooms.changed.map((r) => r.id));
        const changedItems = new Set(items.changed.map((i) => i.id));
        const definitions = definitionLibrary(scene.definitions);
        const changedDefinitions = changedDefinitionIds(definitionLibrary(previous.definitions), definitions);
        const rebuiltItems = scene.items.filter(
            (i) =>
                changedItems.has(i.id) ||
                (i.roomId !== null && changedRooms.has(i.roomId)) ||
                (i.definitionId !== null && changedDefinitions.has(i.definitionId)) ||
                this.showsPicture(i, changedPictures) ||
                (i.kind === 'Model' && i.modelId != null && changedModels.has(i.modelId)),
        );
        for (const id of [...items.removed, ...rebuiltItems.map((i) => i.id)]) {
            const object = this.items.get(id);
            if (object !== undefined) {
                this.disposeObject(object);
            }
            this.items.delete(id);
        }
        const roomsById = new Map(scene.rooms.map((room) => [room.id, room]));
        const assets = this.itemAssets(scene);
        for (const item of rebuiltItems) {
            const object = buildItem(item, materials, roomsById.get(item.roomId ?? 0), definitions, assets);
            this.items.set(item.id, object);
            content.add(object);
        }

        const walls = diffById(previous.walls, scene.walls);
        const wallsChanged = walls.removed.length > 0 || walls.changed.length > 0;
        if (wallsChanged) {
            const joints = wallJoints(scene.walls);
            const corners = cornerWindows(scene.walls);
            const changedIds = new Set(walls.changed.map((w) => w.id));
            for (const id of walls.removed) {
                this.removeWall(id);
            }
            for (const wall of scene.walls) {
                // A moved wall also changes the mitres of the walls it meets, a window the corner windows.
                if (
                    changedIds.has(wall.id) ||
                    !sameJson(this.joints.get(wall.id), joints.get(wall.id)) ||
                    !sameJson(this.corners.get(wall.id), corners.get(wall.id))
                ) {
                    this.removeWall(wall.id);
                    const group = buildWall(wall, materials, parts.sectionMaterial, joints.get(wall.id), corners.get(wall.id));
                    parts.walls.set(wall.id, group);
                    parts.root.add(group);
                }
            }
            this.joints = joints;
            this.corners = corners;
            this.disposeObject(parts.slab);
            parts.slab = buildSlab(scene.walls, materials);
            parts.root.add(parts.slab);
            this.indexWalls();
        }

        const plots = diffById(previous.plots ?? [], scene.plots ?? []);
        for (const id of [...plots.removed, ...plots.changed.map((p) => p.id)]) {
            const group = parts.plots.get(id);
            if (group !== undefined) {
                this.disposeObject(group);
                parts.plots.delete(id);
            }
        }
        for (const plot of plots.changed) {
            const group = buildPlot(plot, materials);
            parts.plots.set(plot.id, group);
            parts.root.add(group);
        }
        this.highlightPlots();

        for (const id of [...rooms.removed, ...rooms.changed.map((r) => r.id)]) {
            this.removeRoom(id);
        }
        for (const room of rooms.changed) {
            addRoom(parts, buildRoom(room, scene.walls, materials), room.id);
            const label = this.createLabel(room);
            this.labels.set(room.id, label);
            content.add(label);
        }
        if (wallsChanged || rooms.changed.length > 0 || rooms.removed.length > 0) {
            // Steps between ceilings depend on the neighbouring rooms and on which edges have walls.
            this.disposeObject(parts.ceilingSteps);
            parts.ceilingSteps = buildCeilingSteps(scene.rooms, scene.walls, materials);
            parts.ceilings.add(parts.ceilingSteps);
        }
        if (wallsChanged) {
            // Wall finishes and skirting leave the doors free and take a wall face's own bands, so they follow the
            // walls of unchanged rooms too. A room drawing none is left alone unless such bands came or went.
            const previousWalls = new Map(previous.walls.map((wall) => [wall.id, wall]));
            const facesChanged = [
                ...walls.changed,
                ...walls.changed.map((wall) => previousWalls.get(wall.id)),
                ...walls.removed.map((id) => previousWalls.get(id)),
            ].some((wall) => wall !== undefined && hasFaceFinishes(wall));
            for (const room of scene.rooms) {
                const roomParts = parts.rooms.get(room.id);
                if (roomParts === undefined || changedRooms.has(room.id) || (roomParts.finishes === null && !facesChanged)) {
                    continue;
                }
                if (roomParts.finishes !== null) {
                    this.disposeObject(roomParts.finishes);
                }
                roomParts.finishes = buildRoomFinishes(room, scene.walls, materials);
                if (roomParts.finishes !== null) {
                    parts.root.add(roomParts.finishes);
                }
            }
        }
    }

    private removeWall(id: number): void {
        const group = this.parts?.walls.get(id);
        if (group !== undefined) {
            this.disposeObject(group);
            this.parts?.walls.delete(id);
        }
    }

    private removeRoom(id: number): void {
        const roomParts = this.parts?.rooms.get(id);
        if (roomParts !== undefined) {
            for (const object of [roomParts.floor, roomParts.ceiling, roomParts.finishes]) {
                if (object !== null) {
                    this.disposeObject(object);
                }
            }
            this.parts?.rooms.delete(id);
        }
        const label = this.labels.get(id);
        if (label !== undefined) {
            label.element.remove();
            label.removeFromParent();
            this.labels.delete(id);
        }
    }

    /** Wall and opening objects by id, for the selection outline. */
    private indexWalls(): void {
        this.walls.clear();
        this.openings.clear();
        this.leaves = [];
        this.parts?.walls.forEach((group, id) => {
            this.walls.set(id, group);
            this.leaves.push(...leafObjects(group));
            group.traverse((object) => {
                const openingId = (object.userData as Record<string, unknown>)['openingId'];
                if (typeof openingId === 'number') {
                    this.openings.set(openingId, object);
                }
            });
        });
    }

    /** Takes an object out of the scene and frees its geometry (and materials the library does not share). */
    private itemAssets(scene: Scene): ItemAssets {
        return {models: new Map((scene.models ?? []).map((m) => [m.id, m])), modelLibrary: this.modelLibrary, pictures: this.pictureLibrary};
    }

    /** Draws the `Model` items of a model that finished loading (all of them with null), in place of their placeholders. */
    private rebuildModelItems(modelId: number | null): void {
        this.rebuildItems((item) => item.kind === 'Model' && (modelId === null || item.modelId === modelId));
    }

    /** Draws the items showing an image that finished loading (all pictures with null), in place of plain ones. */
    private rebuildPictureItems(fileId: number | null): void {
        this.rebuildItems((item) => this.showsPicture(item, fileId === null ? null : new Set([fileId])));
    }

    /** Whether the item as drawn has a picture of one of the files (of any file with null); only Custom items have them. */
    private showsPicture(item: Item, fileIds: ReadonlySet<number> | null): boolean {
        if (item.kind !== 'Custom' || fileIds?.size === 0) {
            return false;
        }
        let shows = false;
        this.items.get(item.id)?.traverse((o) => {
            const id = o.userData['pictureId'] as number | null | undefined;
            shows ||= typeof id === 'number' && (fileIds === null || fileIds.has(id));
        });
        return shows;
    }

    private rebuildItems(rebuild: (item: Item) => boolean): void {
        const scene = this.sceneData;
        const content = this.content;
        const materials = this.materials;
        if (scene === null || content === null || materials === null) {
            return;
        }
        const rooms = new Map(scene.rooms.map((room) => [room.id, room]));
        const definitions = definitionLibrary(scene.definitions);
        const hidden = hiddenItemIds(scene);
        const assets = this.itemAssets(scene);
        for (const item of scene.items) {
            if (!rebuild(item)) {
                continue;
            }
            const previous = this.items.get(item.id);
            if (previous !== undefined) {
                this.disposeObject(previous);
            }
            const object = buildItem(item, materials, rooms.get(item.roomId ?? 0), definitions, assets);
            object.visible = !hidden.has(item.id);
            this.showCeilingParts(object);
            this.items.set(item.id, object);
            content.add(object);
            this.refreshShadows();
        }
        this.applyLights();
        if (this.selection?.kind === 'item' || this.selection?.kind === 'group') {
            this.setSelection(this.selection);
        }
    }

    private disposeObject(object: THREE.Object3D): void {
        object.removeFromParent();
        object.traverse((o) => {
            // Model instances share their geometry and materials with the model library's template. Lines: a plot's boundary.
            if ((o instanceof THREE.Mesh || o instanceof THREE.Line) && o.userData['shared'] !== true) {
                (o.geometry as THREE.BufferGeometry).dispose();
                for (const material of Array.isArray(o.material) ? o.material : [o.material]) {
                    if (this.materials?.owns(material) !== true && material !== this.parts?.sectionMaterial) {
                        material.dispose();
                    }
                }
            }
            if (o instanceof CSS2DObject) {
                o.element.remove();
            }
        });
    }

    /** Highlights the selected element: a room's floor is tinted, other elements get an outline box. */
    public setSelection(selection: Selection | null): void {
        this.invalidate();
        this.selection = selection;
        this.setSelectedRoom(selection?.kind === 'room' ? selection.id : null);
        this.highlightPlots();

        this.removeOutline();
        const objects = this.objectsOf(selection);
        if (objects.length > 0) {
            this.outline = createOutline();
            this.outlinedObjects = objects;
            this.scene.add(this.outline);
            this.updateOutline();
        }
        this.updateHandles();
    }

    /** Outlines the items picked with Shift+click. */
    public setPicked(itemIds: number[]): void {
        this.invalidate();
        this.picked = itemIds;
        this.removePickedOutlines();
        for (const id of itemIds) {
            const object = this.items.get(id);
            if (object !== undefined) {
                const mesh = createOutline();
                this.scene.add(mesh);
                this.pickedOutlines.push({mesh, object});
            }
        }
        this.updateOutline();
    }

    /**
     * In edit mode, in a top-down view, items, walls and doors / windows can be dragged, and the selected
     * wall's ends and the selected room's corners get handles.
     */
    public setEditMode(enabled: boolean): void {
        this.invalidate();
        this.editMode = enabled;
        if (!enabled) {
            this.cancelDrag();
            this.setTool('select');
        }
        this.updateHandles();
    }

    /**
     * What a click in the top view does in edit mode: select and drag (`select`), place the points of a run
     * of walls (`drawWalls`: a click on the first point closes it, a double-click or `finishRun()` ends it
     * open), or add the room around the pointer (`detectRoom`).
     */
    public setTool(tool: EditorTool): void {
        this.invalidate();
        if (tool === this.tool) {
            return;
        }
        this.tool = tool;
        this.run = null;
        // A run still on its way is left to the server; its answer no longer concerns the view.
        this.pendingRun = null;
        this.detection = null;
        this.updateToolCursor();
        this.updateDrawing();
    }

    /** Thickness and height of the walls drawn next. */
    public setWallDraft(draft: WallDraftShape): void {
        this.invalidate();
        this.wallDraft = draft;
        this.updateDrawing();
    }

    /**
     * Places the next point of the run `length` mm from the last one, in the direction `angle` (degrees
     * counter-clockwise from east), or towards the pointer when null. Typed lengths come in this way.
     */
    public placeRunPoint(length: number, angle: number | null, sagitta: number | null = null): void {
        this.invalidate();
        const run = this.run;
        const previous = run?.points.at(-1);
        if (run === null || previous === undefined || run.bend !== null) {
            return;
        }
        const direction = angle ?? (run.cursor === null ? 0 : directionOf(previous, run.cursor));
        const point = pointAt(previous, length, direction);
        this.addRunPoint(point, sagitta === null ? null : clampSagitta(previous, point, sagitta));
    }

    /** Arc mode of the draw tool: every segment drawn next waits for its curve. */
    public setDrawArc(arc: boolean): void {
        this.invalidate();
        this.drawArc = arc;
        this.updateDrawing();
    }

    /** Gives the segment waiting for its curve a typed sagitta (mm, + to the left). */
    public bendRun(sagitta: number): void {
        this.invalidate();
        if (this.run?.bend != null) {
            const [a, b] = this.bendingSegment(this.run);
            this.run.bend.sagitta = clampSagitta(a, b, sagitta);
            this.fixBend();
        }
    }

    /** Ends the run being drawn as it is (open); a run of one point is dropped. */
    public finishRun(): void {
        this.invalidate();
        this.commitRun(false);
    }

    /** Drops the run being drawn; false when there was none. */
    public cancelRun(): boolean {
        this.invalidate();
        if (this.run === null) {
            return false;
        }
        this.run = null;
        this.updateDrawing();
        return true;
    }

    /** The point the camera looks at, in plan mm: where new elements are placed. */
    public viewCenter(): {x: number; y: number} {
        return {x: Math.round(this.controls.target.x / MM), y: Math.round(-this.controls.target.z / MM)};
    }

    public setCeilingsVisible(visible: boolean): void {
        this.ceilingsVisible = visible;
        if (this.parts !== null) {
            this.parts.ceilings.visible = visible;
        }
        this.items.forEach((object) => this.showCeilingParts(object));
        this.refreshShadows();
    }

    /** Shows or hides the parts of an item that belong to the ceiling (a beam) with the ceilings. */
    private showCeilingParts(object: THREE.Object3D): void {
        object.traverse((o) => {
            if (o.userData['ceilingPart'] === true) {
                o.visible = this.ceilingsVisible;
            }
        });
    }

    public setLabelsVisible(visible: boolean): void {
        this.invalidate();
        this.labelsVisible = visible;
        this.labels.forEach((label) => (label.visible = visible));
    }

    /** Dimension lines along the room edges, split at doors and windows; drawn in the top view only. */
    /**
     * Day or night, and whether the fixture lights are on. At night the sun is gone and the sky almost dark; the lights
     * keep their shaders when switched off (intensity 0), so toggling compiles nothing.
     */
    /**
     * Fast for working, or presentation for screenshots and shared views: ambient occlusion and SMAA through a
     * post-processing pipeline, a supersampled canvas, a larger and softer sun shadow. The fast quality stays the
     * default; switching rebuilds the shadow map, nothing of the scene.
     */
    public setQuality(quality: RenderQuality): void {
        if (quality === this.quality) {
            return;
        }
        this.quality = quality;
        this.presentation?.dispose();
        this.presentation = null;
        const device = window.devicePixelRatio;
        const fastRatio = Math.min(device, 2);
        const pixelRatio = quality === 'presentation' ? Math.max(device, PresentationPixelRatio) : fastRatio;
        this.renderer.setPixelRatio(pixelRatio);
        if (quality === 'presentation') {
            this.presentation = new PresentationPipeline(this.renderer, this.scene, this.camera, fastRatio);
        }
        const size = sunShadowMapSize(quality, this.renderer.capabilities.maxTextureSize);
        this.sun.shadow.mapSize.set(size, size);
        this.sun.shadow.radius = SunShadowRadius[quality];
        // A new size needs a new map.
        this.sun.shadow.map?.dispose();
        this.sun.shadow.map = null;
        this.refreshShadows();
        this.resize();
    }

    public getQuality(): RenderQuality {
        return this.quality;
    }

    /** The scene into the canvas: directly, or through the presentation pipeline (its plain frames while the camera moves). */
    private renderMain(): void {
        const now = performance.now();
        this.camera.updateMatrixWorld();
        if (viewChanged(this.renderedView, this.camera.matrixWorld) || viewChanged(this.renderedProjection, this.camera.projectionMatrix)) {
            this.renderedView.copy(this.camera.matrixWorld);
            this.renderedProjection.copy(this.camera.projectionMatrix);
            this.cameraMovedAt = now;
        }
        this.renderedMoving = false;
        if (this.presentation === null) {
            this.renderer.render(this.scene, this.camera);
        } else if (this.stillFrames || now - this.cameraMovedAt >= PresentationSettleMs) {
            this.presentation.render();
        } else {
            this.presentation.renderMoving();
            this.renderedMoving = true;
        }
    }

    public setLighting(night: boolean, lightsOn: boolean): void {
        this.night = night;
        this.lightsOn = lightsOn;
        this.applyDaylight();
        this.applyLights();
        this.refreshShadows();
    }

    /**
     * The sun at a clock time on site (the 21st of the month), from the scene's location and north angle; null, or a
     * scene without a location, keeps the default afternoon sun from the south-west. Below the horizon it is gone and
     * the sky fades as at dusk.
     */
    public setSunTime(time: SunTime | null): void {
        this.sunTime = time;
        this.fitSunShadow();
        this.refreshShadows();
    }

    /** The sun, sky and background for the night switch and the sun's height. */
    private applyDaylight(): void {
        const daylight = this.night ? {sun: 0, sky: 0} : daylightAt(this.sunAngles.elevation);
        this.sun.intensity = Daylight.sun * daylight.sun;
        // No sun, no shadow pass for it.
        this.sun.castShadow = daylight.sun > 0;
        this.sky.intensity = THREE.MathUtils.lerp(Nightlight.sky, Daylight.sky, daylight.sky);
        this.sky.color.set(Nightlight.skyColor).lerp(new THREE.Color(Daylight.skyColor), daylight.sky);
        this.scene.environmentIntensity = THREE.MathUtils.lerp(Nightlight.environment, Daylight.environment, daylight.sky);
        this.scene.background = new THREE.Color(NightBackgroundColor).lerp(new THREE.Color(BackgroundColor), daylight.sky);
    }

    private applyLights(): void {
        if (this.content !== null) {
            applyFixtureLights(this.content, this.lightsOn);
        }
    }

    public setDimensionsVisible(visible: boolean): void {
        this.invalidate();
        this.dimensionsVisible = visible;
    }

    /**
     * The measure tool: in a top view, clicks place two points and the distance between them is shown
     * (snapping to wall ends, face corners and faces; Shift measures freely). Independent of edit mode and its
     * tools, and takes the clicks before them.
     */
    public setMeasuring(on: boolean): void {
        this.invalidate();
        if (on === this.measuring) {
            return;
        }
        this.measuring = on;
        this.measurement = NoMeasurement;
        if (on) {
            this.cancelDrag();
        }
        this.updateToolCursor();
        this.updateHandles();
        this.updateMeasure();
    }

    /** The pins of the review comments; `activeId` is the thread shown in the panel. */
    public setComments(pins: readonly CommentPin[], activeId: number | null): void {
        this.invalidate();
        this.commentPins.set(pins, activeId);
    }

    public setCommentsVisible(visible: boolean): void {
        this.invalidate();
        this.commentPins.setVisible(visible);
    }

    /** The comment tool: in any view, a click reports the point under the pointer (`onCommentPlace`). */
    public setCommenting(on: boolean): void {
        this.invalidate();
        this.commenting = on;
        if (on) {
            this.cancelDrag();
        }
        this.updateToolCursor();
    }

    /** Turns the camera to a commented point: centred from above in the top view, closer in 3D. */
    public focusPoint(point: CommentPoint): void {
        this.invalidate();
        const target = new THREE.Vector3(point.x * MM, point.z * MM, -point.y * MM);
        const offset = this.camera.position.clone().sub(this.controls.target);
        if (!this.isTopDown()) {
            offset.setLength(Math.min(offset.length(), 6));
        }
        this.moveCamera(target.clone().add(offset), target, true);
    }

    /** Clears the measurement; false when there was none. */
    public clearMeasurement(): boolean {
        this.invalidate();
        if (this.measurement.a === null) {
            return false;
        }
        this.measurement = NoMeasurement;
        this.updateMeasure();
        return true;
    }

    /** How the 3D models of `Model` items are fetched (with the page's sign-in, or through a share link). */
    public setModelFetcher(fetcher: ModelFetcher): void {
        this.modelLibrary?.dispose();
        this.modelLibrary = new ModelLibrary(fetcher);
        this.modelLibrary.onLoaded = (modelId): void => this.rebuildModelItems(modelId);
        if (this.sceneData !== null) {
            this.rebuildModelItems(null);
        }
    }

    public setPictureFetcher(fetcher: PictureFetcher): void {
        this.pictureLibrary?.dispose();
        this.pictureLibrary = new PictureLibrary(fetcher);
        this.pictureLibrary.onLoaded = (fileId): void => this.rebuildPictureItems(fileId);
        if (this.sceneData !== null) {
            this.rebuildPictureItems(null);
        }
    }

    /**
     * The plan image under the top view, drawn over the plan at its opacity; null (or no image yet) removes it.
     * The image is the decoded file of `underlay.fileId`.
     */
    public setUnderlay(underlay: Underlay | null, image: TexImageSource | null): void {
        this.invalidate();
        if (this.underlay !== null && underlay !== null && image === this.underlayImage) {
            // The same image moved or faded: no new texture upload.
            updateUnderlay(this.underlay, underlay);
            return;
        }
        if (this.underlay !== null) {
            disposeUnderlay(this.underlay);
            this.underlay = null;
        }
        this.underlayImage = image;
        if (underlay !== null && image !== null) {
            this.underlay = buildUnderlay(underlay, image);
            this.underlay.visible = false;
            this.scene.add(this.underlay);
        }
    }

    public setUnderlayVisible(visible: boolean): void {
        this.invalidate();
        this.underlayVisible = visible;
    }

    /** With the measure tool on: the two points clicked next calibrate the underlay (`onCalibrate`), without snapping. */
    public setCalibrating(on: boolean): void {
        this.invalidate();
        this.calibrating = on;
        this.measurement = NoMeasurement;
        this.updateMeasure();
    }

    /** Cuts everything above `height` (mm) away; `null` shows the full height. */
    public setSectionHeight(height: number | null): void {
        this.sectionHeight = height;
        this.updateHandles();
        this.commentPins.setSectionHeight(height);
        this.refreshShadows();
        if (height === null) {
            this.renderer.clippingPlanes = [];
            return;
        }
        this.sectionPlane.constant = height * MM;
        this.renderer.clippingPlanes = [this.sectionPlane];
    }

    /** Width (px) covered by UI on the left; the projection centre shifts right so the model is framed in the free area. */
    public setInsetLeft(pixels: number): void {
        this.invalidate();
        this.insetLeft = pixels;
        this.resize();
    }

    public setView(preset: ViewPreset, animate = true): void {
        this.invalidate();
        const center = this.planCenter();
        const width = (this.bounds.maxX - this.bounds.minX) * MM;
        const depth = (this.bounds.maxY - this.bounds.minY) * MM;

        switch (preset) {
            case 'overview':
                // From the south-west, above the living room, looking towards the kitchen corner.
                this.moveCamera(new THREE.Vector3(center.x - width * 1.1, Math.max(width, depth) * 0.95, center.z + depth * 0.7), center, animate);
                break;
            case 'top': {
                const halfFov = THREE.MathUtils.degToRad(this.camera.fov / 2);
                const distance = Math.max(depth / 2 / Math.tan(halfFov), width / 2 / (Math.tan(halfFov) * this.camera.aspect)) * 1.12;
                this.moveCamera(new THREE.Vector3(center.x, distance, center.z + 0.001), center, animate);
                break;
            }
        }
    }

    /**
     * The camera in plan mm (where a tween is heading, if one runs), e.g. to save it in a share link or a view. During a
     * walk it is the walker's eye, looking 2 m ahead: the same shot as a 3D view.
     */
    public cameraPose(): CameraPose {
        const toPlan = (v: THREE.Vector3): CameraPoint => ({x: Math.round(v.x / MM), y: Math.round(-v.z / MM), z: Math.round(v.y / MM)});
        if (this.walkthrough !== null) {
            const ahead = this.camera.getWorldDirection(new THREE.Vector3()).multiplyScalar(2).add(this.camera.position);
            return {position: toPlan(this.camera.position), target: toPlan(ahead)};
        }
        return {
            position: toPlan(this.tween?.toPosition ?? this.camera.position),
            target: toPlan(this.tween?.toTarget ?? this.controls.target),
        };
    }

    /**
     * The floor as shown, as a binary glTF: the building with its ceilings, the shown items and their lights, doors
     * posed as now (see `exportTree()`). Waits for the models and pictures still loading (up to half a minute), so
     * the file has them rather than their stand-ins.
     */
    public async exportGlb(): Promise<Blob> {
        for (let waited = 0; this.pendingAssets() > 0 && waited < 200; waited++) {
            await new Promise((resolve) => setTimeout(resolve, 150));
        }
        if (this.content === null || this.sceneData === null || this.parts === null) {
            throw new Error('The floor is not loaded yet.');
        }
        const glb = await toGlb(exportTree(this.content, this.sceneData, this.parts.sectionMaterial));
        return new Blob([glb], {type: 'model/gltf-binary'});
    }

    /** Models and pictures still loading: a screenshot waits for 0. */
    public pendingAssets(): number {
        return (this.modelLibrary?.pending() ?? 0) + (this.pictureLibrary?.pending() ?? 0);
    }

    /** Frame times and what the frames draw, for the `?stats` readout. */
    public stats(): ViewerStats {
        return {
            frame: this.frameStats.summary(),
            rendersPerSecond: this.frameStats.rendersPerSecond(performance.now()),
            render: this.lastCounters ?? renderCounters(this.renderer),
            scene: sceneCounters(this.scene),
            buildMs: this.buildMs,
        };
    }

    public gpu(): string {
        return gpuName(this.renderer);
    }

    /**
     * Renders frames back to back in a scenario (quality, night, lights) and times each one with the GPU waited for (a
     * pixel read), so the time is what the frame costs rather than the display's refresh interval. With `orbit`, the
     * camera turns a full circle about its target. The scenario and the camera are restored afterwards.
     */
    public async benchmark(options: BenchmarkOptions = {}): Promise<BenchmarkResult> {
        if (this.walkthrough !== null) {
            throw new Error('End the walk before a benchmark.');
        }
        const frames = Math.max(1, options.frames ?? 120);
        const warmup = Math.max(0, options.warmup ?? 20);
        const previous = {quality: this.quality, night: this.night, lightsOn: this.lightsOn};
        const position = this.camera.position.clone();
        this.renderer.setAnimationLoop(null);
        this.tween = null;
        // The presentation scenarios measure the frame the pipeline renders once the camera stops, orbit or not, unless
        // asked for the frames rendered while it moves.
        this.stillFrames = options.moving !== true;
        try {
            this.setQuality(options.quality ?? previous.quality);
            this.setLighting(options.night ?? previous.night, options.lightsOn ?? previous.lightsOn);
            const target = this.controls.target;
            const offset = position.clone().sub(target);
            const step = options.orbit === true ? (2 * Math.PI) / frames : 0;
            const gl = this.renderer.getContext();
            const pixel = new Uint8Array(4);
            const times: number[] = [];
            let counters: RenderCounters | null = null;
            for (let i = 0; i < warmup + frames; i++) {
                const measured = i - warmup;
                this.camera.position.copy(offset).applyAxisAngle(new THREE.Vector3(0, 1, 0), Math.max(measured, 0) * step).add(target);
                const started = performance.now();
                this.renderer.info.reset();
                this.frame();
                this.renderer.setRenderTarget(null);
                gl.readPixels(0, 0, 1, 1, gl.RGBA, gl.UNSIGNED_BYTE, pixel);
                if (measured >= 0) {
                    times.push(performance.now() - started);
                    counters = renderCounters(this.renderer);
                }
                if (i % 10 === 9) {
                    await new Promise((resolve) => setTimeout(resolve, 0));
                }
            }
            const size = this.renderer.getDrawingBufferSize(new THREE.Vector2());
            return {
                median: median(times),
                p95: percentile(times, 95),
                min: Math.min(...times),
                frames,
                render: counters ?? renderCounters(this.renderer),
                scene: sceneCounters(this.scene),
                buildMs: this.buildMs,
                width: size.x,
                height: size.y,
            };
        } finally {
            this.stillFrames = false;
            this.camera.position.copy(position);
            this.setQuality(previous.quality);
            this.setLighting(previous.night, previous.lightsOn);
            this.lastFrame = performance.now();
            this.renderer.setAnimationLoop(() => this.tick());
        }
    }

    /**
     * The view as a PNG of `width` × `height` CSS pixels at `pixelRatio`, rendered off the visible size (the panel inset is
     * left out, so the floor is framed in the whole image). The room labels are HTML over the canvas and not in it.
     */
    public capture(width: number, height: number, pixelRatio = 2, quality: RenderQuality = 'presentation'): Promise<Blob> {
        const previousQuality = this.quality;
        this.setQuality(quality);
        const previousRatio = this.renderer.getPixelRatio();
        this.renderer.setPixelRatio(pixelRatio);
        this.renderer.setSize(width, height, false);
        this.camera.aspect = width / height;
        this.camera.clearViewOffset();
        this.camera.updateProjectionMatrix();
        this.presentation?.setSize(width, height, pixelRatio);
        this.stillFrames = true;
        this.renderMain();
        this.stillFrames = false;
        // The drawing buffer is read now, before the browser composites (and clears) it.
        const blob = new Promise<Blob>((resolve, reject) =>
            this.renderer.domElement.toBlob((b) => (b === null ? reject(new Error('The view could not be captured.')) : resolve(b)), 'image/png'),
        );
        this.renderer.setPixelRatio(previousRatio);
        this.setQuality(previousQuality);
        this.resize();
        return blob;
    }

    public setCameraPose(pose: CameraPose, animate = true): void {
        this.invalidate();
        const toScene = (p: CameraPoint): THREE.Vector3 => new THREE.Vector3(p.x * MM, p.z * MM, -p.y * MM);
        this.moveCamera(toScene(pose.position), toScene(pose.target), animate);
    }

    /**
     * Switches to a first-person walk at eye level, starting just inside the main door (or in the largest
     * indoor room, facing the way the camera does). Call it from a click: it captures the mouse.
     */
    public startWalkthrough(): void {
        this.invalidate();
        if (this.walkthrough !== null || this.sceneData === null) {
            return;
        }
        this.cancelDrag();
        this.tween = null;
        this.controls.enabled = false;
        this.camera.fov = WalkthroughFov;
        this.camera.updateProjectionMatrix();

        const start = this.walkStart(this.sceneData);
        // The view looks along plan (−sin yaw, cos yaw).
        const yaw = Math.atan2(-start.direction.x, start.direction.y);
        const walkthrough = new Walkthrough(this.camera, this.renderer.domElement, sceneColliders(this.sceneData), start.position, yaw);
        walkthrough.onExit = (): void => {
            this.stopWalkthrough();
            this.onWalkthroughEnd();
        };
        walkthrough.onLockChange = (locked): void => this.onWalkthroughLock(locked);
        walkthrough.onUse = (): void => this.toggleDoor(this.aim?.openingId ?? null);
        this.walkthrough = walkthrough;
        // Every door starts closed but the entrance, open at once: swinging open it could sweep the walker aside.
        this.leafStates.closeAll();
        if (start.openingId !== undefined) {
            this.leafStates.setOpen(start.openingId, true, false);
        }
        this.leafStates.apply(this.leaves);
        this.updateLeafColliders();
        this.refreshShadows();
        walkthrough.lock();
    }

    /** Keys held on the on-screen arrows of a touch walk (`KeyboardEvent.code` values). */
    public setWalkKeys(codes: readonly string[]): void {
        this.walkthrough?.setVirtualKeys(codes);
    }

    /** Ends the walk as Esc does: `onWalkthroughEnd` follows. */
    public endWalkthrough(): void {
        this.invalidate();
        this.walkthrough?.end();
    }

    /** Back to the orbit controls; with `animate` the camera flies from the walker's eye to the overview. */
    public stopWalkthrough(animate = true): void {
        this.invalidate();
        if (this.walkthrough === null) {
            return;
        }
        this.walkthrough.dispose();
        this.walkthrough = null;
        this.setAim(null);
        // Back to the display state: doors open, French windows closed.
        this.leafStates.reset(this.sceneData?.walls ?? [], true);
        this.camera.fov = OrbitFov;
        this.camera.updateProjectionMatrix();
        // Orbit around a point ahead of the walker, so the hand-over does not jerk the view.
        const ahead = this.camera.getWorldDirection(new THREE.Vector3()).setY(0).normalize().multiplyScalar(2);
        this.controls.target.copy(this.camera.position).add(ahead);
        this.controls.enabled = true;
        this.setView('overview', animate);
    }

    /** Start of a walk: inside the entrance; without one, the middle of the largest indoor room (or the view centre). */
    private walkStart(scene: Scene): WalkStart {
        const entrance = entranceWalkStart(scene.walls, scene.rooms);
        if (entrance !== null) {
            return entrance;
        }
        const view = this.camera.getWorldDirection(new THREE.Vector3());
        const direction = Math.hypot(view.x, view.z) > 1e-3 ? {x: view.x, y: -view.z} : {x: 0, y: 1};
        const indoor = scene.rooms.filter((r) => r.type !== 'Terrace' && r.type !== 'Garden');
        if (indoor.length === 0) {
            return {position: this.viewCenter(), direction};
        }
        const largest = indoor.reduce((a, b) => (b.area > a.area ? b : a));
        return {position: polygonCentroid(largest.polygon), direction};
    }

    public focusRoom(room: Room): void {
        this.invalidate();
        const c = polygonCentroid(room.polygon);
        const b = polygonBounds(room.polygon);
        const size = Math.max(b.maxX - b.minX, b.maxY - b.minY) * MM;
        const target = new THREE.Vector3(c.x * MM, 0.8, -c.y * MM);
        const offset = new THREE.Vector3(0.55, 1.1, 0.75).normalize().multiplyScalar(size * 1.4 + 3);
        this.moveCamera(target.clone().add(offset), target, true);
    }

    /** The selected room or plot, whose corners get handles. */
    private selectedOutline(): Outlined | null {
        const selection = this.selection;
        const scene = this.sceneData;
        if (selection === null || scene === null) {
            return null;
        }
        if (selection.kind === 'room') {
            const room = scene.rooms.find((r) => r.id === selection.id);
            return room === undefined ? null : {kind: 'room', element: room};
        }
        if (selection.kind === 'plot') {
            const plot = scene.plots?.find((p) => p.id === selection.id);
            return plot === undefined ? null : {kind: 'plot', element: plot};
        }
        return null;
    }

    /** The selected plot's boundary in the highlight colour. */
    private highlightPlots(): void {
        const selection = this.selection;
        this.parts?.plots.forEach((group, id) => highlightPlot(group, selection?.kind === 'plot' && selection.id === id));
    }

    /** Tints the room's floor; `floor` overrides the room's own mesh (the preview while a corner is dragged). */
    private setSelectedRoom(roomId: number | null, floor?: THREE.Mesh): void {
        this.labels.forEach((label, id) => label.element.classList.toggle('selected', id === roomId));

        if (this.highlight !== null) {
            this.highlight.removeFromParent();
            (this.highlight.material as THREE.Material).dispose();
            this.highlight = null;
        }

        const target = floor ?? (roomId === null ? undefined : this.parts?.rooms.get(roomId)?.floor);
        if (target === undefined) {
            return;
        }
        const material = new THREE.MeshBasicMaterial({color: HighlightColor, transparent: true, opacity: 0.22, depthWrite: false});
        this.highlight = new THREE.Mesh(target.geometry, material);
        this.highlight.userData = {highlight: true};
        this.highlight.position.y = target.position.y + 0.002;
        this.highlight.renderOrder = 1;
        target.parent?.add(this.highlight);
    }

    public dispose(): void {
        this.renderer.setAnimationLoop(null);
        this.presentation?.dispose();
        this.resizeObserver.disconnect();
        this.controls.removeEventListener('change', this.invalidate);
        for (const type of InputEvents) {
            this.renderer.domElement.removeEventListener(type, this.invalidate);
        }
        document.removeEventListener('visibilitychange', this.invalidate);
        this.renderer.domElement.removeEventListener('pointerdown', this.handlePointerDown);
        this.renderer.domElement.removeEventListener('pointermove', this.handlePointerMove);
        this.renderer.domElement.removeEventListener('pointerup', this.handlePointerUp);
        this.renderer.domElement.removeEventListener('pointerleave', this.handlePointerLeave);
        this.renderer.domElement.removeEventListener('dblclick', this.handleDoubleClick);
        window.removeEventListener('keydown', this.handleKeyDown, true);
        this.walkthrough?.dispose();
        this.walkthrough = null;
        this.clearContent();
        this.removeDimensions();
        this.setUnderlay(null, null);
        this.modelLibrary?.dispose();
        this.pictureLibrary?.dispose();
        this.measureLayer.children.slice().forEach((o) => disposeLine(o));
        this.measureLabel.element.remove();
        this.commentPins.clear();
        this.handles.clear();
        this.handleGeometry.dispose();
        this.handleCoreGeometry.dispose();
        this.handleMaterials.forEach((m) => m.dispose());
        this.scene.environment?.dispose();
        this.controls.dispose();
        this.renderer.dispose();
        this.renderer.domElement.remove();
        this.labelRenderer.domElement.remove();
    }

    private setupEnvironment(): void {
        this.scene.background = new THREE.Color(BackgroundColor);

        // Soft image-based lighting for reflections on glass, steel and glossy fronts.
        const pmrem = new THREE.PMREMGenerator(this.renderer);
        this.scene.environment = pmrem.fromScene(new RoomEnvironment(), 0.04).texture;
        this.scene.environmentIntensity = Daylight.environment;
        pmrem.dispose();

        this.scene.add(this.sky);

        // Placed by `fitSunShadow()`: by default an afternoon sun from the south-west.
        this.sun.castShadow = true;
        const shadowSize = sunShadowMapSize('fast', this.renderer.capabilities.maxTextureSize);
        this.sun.shadow.mapSize.set(shadowSize, shadowSize);
        this.sun.shadow.bias = -0.0004;
        this.sun.shadow.normalBias = 0.02;
        this.sun.shadow.radius = SunShadowRadius.fast;
        this.scene.add(this.sun, this.sun.target);

        const ground = new THREE.Mesh(
            new THREE.CircleGeometry(60, 64),
            new THREE.MeshStandardMaterial({color: '#cfd2c4', roughness: 1}),
        );
        ground.rotation.x = -Math.PI / 2;
        // Just below the floor level: the gardens and the terrace lie on it, the slab is buried.
        ground.position.y = -0.02;
        ground.receiveShadow = true;
        ground.name = 'ground';
        this.scene.add(ground);
    }

    /** The sun towards its direction at the clock time, its shadow camera covering the plan. */
    private fitSunShadow(): void {
        this.sunAngles = sunAt(this.sceneData?.location, this.sunTime, new Date().getFullYear());
        const direction = sunDirection(this.sceneData?.location?.northAngle ?? 0, this.sunAngles);
        const center = this.planCenter();
        const radius = Math.hypot(this.bounds.maxX - this.bounds.minX, this.bounds.maxY - this.bounds.minY) * MM * 0.6 + 2;
        const distance = radius + SunDistance;
        this.sun.position.set(center.x + direction.x * distance, center.y + direction.y * distance, center.z + direction.z * distance);
        this.sun.target.position.copy(center);
        const cam = this.sun.shadow.camera;
        cam.left = -radius;
        cam.right = radius;
        cam.top = radius;
        cam.bottom = -radius;
        cam.near = 1;
        cam.far = distance + radius + 10;
        cam.updateProjectionMatrix();
        this.applyDaylight();
    }

    private createLabel(room: Room): CSS2DObject {
        const element = document.createElement('div');
        element.className = 'room-label';
        const name = document.createElement('span');
        name.className = 'room-label__name';
        name.textContent = room.name;
        const meta = document.createElement('span');
        meta.className = 'room-label__meta';
        const height = room.ceilingHeight === null ? '' : ` · h ${(room.ceilingHeight / 1000).toFixed(2)} m`;
        meta.textContent = `${room.code.replace('H1/', '')} · ${room.area.toFixed(1)} m²${height}`;
        element.append(name, meta);

        const label = new CSS2DObject(element);
        const c = polygonCentroid(room.polygon);
        label.position.set(c.x * MM, 0.05, -c.y * MM);
        label.visible = this.labelsVisible;
        return label;
    }

    private planCenter(): THREE.Vector3 {
        return new THREE.Vector3(((this.bounds.minX + this.bounds.maxX) / 2) * MM, 0, (-(this.bounds.minY + this.bounds.maxY) / 2) * MM);
    }

    private moveCamera(position: THREE.Vector3, target: THREE.Vector3, animate: boolean): void {
        // Without damping, update() also discards any rotation momentum left from earlier input, which
        // would otherwise keep turning the camera away from the requested view.
        this.controls.enableDamping = false;
        if (!animate) {
            this.tween = null;
            this.camera.position.copy(position);
            this.controls.target.copy(target);
            this.controls.update();
            this.controls.enableDamping = true;
            return;
        }
        this.tween = {
            fromPosition: this.camera.position.clone(),
            fromTarget: this.controls.target.clone(),
            toPosition: position,
            toTarget: target,
            start: performance.now(),
            duration: TweenDuration,
        };
    }

    /** The shadow maps are redrawn with the next frame. */
    private refreshShadows(): void {
        this.renderer.shadowMap.needsUpdate = true;
        this.invalidate();
    }

    /** The view is drawn again with the next frame. */
    public readonly invalidate = (): void => {
        this.needsRender = true;
    };

    /** One frame of the animation loop, timed and counted for `stats()`. */
    private tick(): void {
        const active = this.walkthrough !== null || this.tween !== null || this.drag !== null || this.preview !== null || this.moving;
        if (!this.needsRender && !active && !this.renderedMoving) {
            // Nothing to draw; the time without frames does not count as one long frame for the animations.
            this.lastFrame = performance.now();
            return;
        }
        this.needsRender = false;
        const started = performance.now();
        this.renderer.info.reset();
        this.frame();
        this.frameStats.record(performance.now() - started, started);
        this.lastCounters = renderCounters(this.renderer);
    }

    private frame(): void {
        const now = performance.now();
        const dt = (now - this.lastFrame) / 1000;
        this.lastFrame = now;
        if (this.leafStates.update(dt)) {
            this.leafStates.apply(this.leaves);
            this.updateLeafColliders();
            this.refreshShadows();
        }
        const moving = this.drag !== null || this.preview !== null || this.drawLayer.children.length > 0;
        if (moving || this.moving) {
            this.refreshShadows();
        }
        this.moving = moving;
        if (this.walkthrough !== null) {
            this.walkthrough.update(dt);
            this.updateAim();
            this.renderMain();
            // Labels are not rendered in the walk; their last positions would float over the view.
            this.labelRenderer.domElement.style.display = 'none';
            return;
        }
        this.labelRenderer.domElement.style.display = '';

        if (this.tween !== null) {
            // performance.now() rather than the loop's timestamp: the two need not share a time origin.
            const t = THREE.MathUtils.clamp((performance.now() - this.tween.start) / this.tween.duration, 0, 1);
            const k = t < 0.5 ? 4 * t * t * t : 1 - Math.pow(-2 * t + 2, 3) / 2;
            this.camera.position.lerpVectors(this.tween.fromPosition, this.tween.toPosition, k);
            this.controls.target.lerpVectors(this.tween.fromTarget, this.tween.toTarget, k);
            if (t >= 1) {
                this.tween = null;
                this.controls.enableDamping = true;
            }
        }
        this.controls.update();
        this.updateOutline();
        const topDown = this.isTopDown();
        if (this.dimensionsVisible && topDown && this.dimensionsStale && this.sceneData !== null) {
            this.updateDimensions(this.sceneData);
        }
        if (this.dimensions !== null) {
            this.dimensions.visible = this.dimensionsVisible && topDown;
            if (this.dimensions.visible) {
                alignDimensionLabels(this.dimensions, this.camera, this.container.clientWidth, this.container.clientHeight);
            }
        }
        if (this.underlay !== null) {
            this.underlay.visible = this.underlayVisible && topDown;
        }
        this.measureLabel.visible = this.measuring && topDown && this.measureLabel.element.textContent !== '';
        this.renderMain();
        this.renderOverlay();
        this.labelRenderer.render(this.scene, this.camera);
        // The renderer stacks labels by distance; the measured distance stays above the room labels.
        this.measureLabel.element.style.zIndex = String(this.labels.size + (this.dimensions?.children.length ?? 0) + 1);
        this.commentPins.raise(this.labels.size + (this.dimensions?.children.length ?? 0) + 1);
    }

    /** Handles on top of everything, at a constant size on screen and only where they can be dragged. */
    private renderOverlay(): void {
        const topDown = this.isTopDown();
        this.handles.visible = this.editMode && topDown && this.handles.children.length > 0;
        this.measureLayer.visible = this.measuring && topDown && this.measureLayer.children.length > 0;
        if (!this.handles.visible && !this.measureLayer.visible) {
            return;
        }
        const perPixel = this.metresPerPixel();
        for (const handle of [...this.handles.children, ...this.measureLayer.children]) {
            const pixels = (handle.userData as {pixels?: number}).pixels;
            if (pixels !== undefined || handle.parent === this.handles) {
                handle.scale.setScalar((pixels ?? HandlePixels) * perPixel);
            }
        }
        const planes = this.renderer.clippingPlanes;
        this.renderer.autoClear = false;
        this.renderer.clippingPlanes = [];
        this.renderer.render(this.overlay, this.camera);
        this.renderer.clippingPlanes = planes;
        this.renderer.autoClear = true;
    }

    /** Size of a screen pixel at the orbit target, metres. */
    private metresPerPixel(): number {
        const distance = this.camera.position.distanceTo(this.controls.target);
        const height = Math.max(this.container.clientHeight, 1);
        return (2 * distance * Math.tan(THREE.MathUtils.degToRad(this.camera.fov / 2))) / height;
    }

    private updateToolCursor(): void {
        this.renderer.domElement.style.cursor = this.tool === 'select' && !this.measuring && !this.commenting ? '' : 'crosshair';
    }

    private isTopDown(): boolean {
        return this.walkthrough === null && this.camera.getWorldDirection(new THREE.Vector3()).y <= DragMaxCameraTilt;
    }

    private resize(): void {
        const width = Math.max(this.container.clientWidth, 1);
        const height = Math.max(this.container.clientHeight, 1);
        this.camera.aspect = width / height;
        const inset = width > 2 * this.insetLeft ? this.insetLeft : 0;
        if (inset > 0) {
            this.camera.setViewOffset(width + inset, height, 0, 0, width, height);
        } else {
            this.camera.clearViewOffset();
        }
        this.camera.updateProjectionMatrix();
        this.renderer.setSize(width, height);
        this.presentation?.setSize(width, height, this.renderer.getPixelRatio());
        this.labelRenderer.setSize(width, height);
        // A resized canvas is cleared.
        this.invalidate();
    }

    private readonly handlePointerDown = (event: PointerEvent): void => {
        this.pointerDown.set(event.clientX, event.clientY);
        if (this.walkthrough !== null) {
            if (this.walkthrough.isLocked()) {
                // Looking around with the mouse: a click opens or closes the door in view.
                if (event.button === 0) {
                    this.toggleDoor(this.aim?.openingId ?? null);
                }
            } else {
                // The lock was refused or lost without ending the walk: a click captures the mouse again.
                this.walkthrough.lock();
            }
            return;
        }
        if (
            this.commenting
            || this.measuring
            || !this.editMode
            || event.button !== 0
            || !this.isTopDown()
            || this.preview !== null
            || this.tool !== 'select'
        ) {
            return;
        }

        const drag = this.handleDrag(event) ?? this.elementDrag(event);
        if (drag === null) {
            return;
        }
        this.drag = drag;
        this.controls.enabled = false;
        this.renderer.domElement.setPointerCapture(event.pointerId);
        if (drag.kind === 'vertex' && drag.inserted) {
            // A new corner pulled out of an edge shows up right away.
            drag.moved = true;
            this.previewOutline(drag);
        }
    };

    /** A drag of one of the selected element's handles. */
    private handleDrag(event: PointerEvent): Drag | null {
        const hit = this.pickHandle(event);
        const role = hit === null ? null : (hit.object.userData as {handle: HandleRole}).handle;
        const scene = this.sceneData;
        if (hit === null || role === null || scene === null || this.selection === null) {
            return null;
        }
        const plane = new THREE.Plane(new THREE.Vector3(0, 1, 0), -hit.object.position.y);
        const grab = this.planPointOn(event, plane);
        if (grab === null) {
            return null;
        }

        if (this.selection.kind === 'wall' && (role.role === 'wallStart' || role.role === 'wallEnd' || role.role === 'wallCurve')) {
            const wall = scene.walls.find((w) => w.id === this.selection?.id);
            const mode = role.role === 'wallStart' ? 'start' : role.role === 'wallEnd' ? 'end' : 'curve';
            return wall === undefined ? null : {kind: 'wall', plane, grab, moved: false, wall, mode, current: wall, fits: true, snap: null};
        }
        const outline = this.selectedOutline();
        if (outline === null) {
            return null;
        }
        const original = outline.element.polygon;
        if (role.role === 'vertex') {
            const index = role.index;
            return {kind: 'vertex', plane, grab, moved: false, outline, index, inserted: false, polygon: original, valid: true, snap: null};
        }
        if (role.role === 'midpoint') {
            const midpoint = edgeMidpoints(original)[role.index];
            const polygon = insertVertex(original, role.index, midpoint);
            const at = {x: polygon[role.index + 1][0], y: polygon[role.index + 1][1]};
            const valid = isSimplePolygon(polygon);
            return {kind: 'vertex', plane, grab: at, moved: false, outline, index: role.index + 1, inserted: true, polygon, valid, snap: null};
        }
        return null;
    }

    /** A drag of the item, group, wall or door / window under the pointer, which also gets selected. */
    private elementDrag(event: PointerEvent): Drag | null {
        if (event.shiftKey) {
            // Shift+click picks items; Shift while dragging only switches off snapping.
            return null;
        }
        const hit = this.pick(event);
        const selection = this.groupOf(selectionOf(hit?.object ?? null), event);
        const scene = this.sceneData;
        if (hit === undefined || selection === null || scene === null || selection.kind === 'room' || selection.kind === 'plot') {
            return null;
        }
        // Drag in the horizontal plane through the grabbed point; the element keeps its elevation.
        const plane = new THREE.Plane(new THREE.Vector3(0, 1, 0), -hit.point.y);
        const grab = {x: hit.point.x / MM, y: -hit.point.z / MM};
        let drag: Drag | null = null;

        if (selection.kind === 'item') {
            const item = scene.items.find((i) => i.id === selection.id);
            const object = this.items.get(selection.id);
            if (item !== undefined && object !== undefined) {
                drag = {kind: 'item', plane, grab, moved: false, item, object, x: item.x, y: item.y};
            }
        } else if (selection.kind === 'group') {
            const members = scene.items
                .filter((i) => i.groupId === selection.id)
                .map((item) => ({item, object: this.items.get(item.id)}))
                .filter((m): m is {item: Item; object: THREE.Object3D} => m.object !== undefined);
            drag = {kind: 'group', plane, grab, moved: false, groupId: selection.id, members, dx: 0, dy: 0};
        } else if (selection.kind === 'wall') {
            const wall = scene.walls.find((w) => w.id === selection.id);
            if (wall !== undefined) {
                drag = {kind: 'wall', plane, grab, moved: false, wall, mode: 'move', current: wall, fits: true, snap: null};
            }
        } else {
            const wall = scene.walls.find((w) => w.openings.some((o) => o.id === selection.id));
            const opening = wall?.openings.find((o) => o.id === selection.id);
            if (wall !== undefined && opening !== undefined) {
                const grabAlong = alongWall(wallFrame(wall), grab) - opening.offset;
                drag = {kind: 'opening', plane, grab, moved: false, wall, openingId: opening.id, grabAlong, offset: opening.offset, fits: true};
            }
        }
        if (drag !== null && !sameSelection(selection, this.selection)) {
            this.onSelect(selection, false);
        }
        return drag;
    }

    private readonly handlePointerMove = (event: PointerEvent): void => {
        const drag = this.drag;
        // Hovering changes only the cursor, except for the measure and drawing tools' previews and drags.
        if (drag !== null || this.measuring || (this.editMode && this.tool !== 'select')) {
            this.invalidate();
        }
        if (drag === null && this.commenting) {
            return;
        }
        if (drag === null && this.measuring) {
            this.trackMeasure(event);
            return;
        }
        if (drag === null && this.editMode && this.tool !== 'select') {
            this.trackTool(event);
            return;
        }
        if (drag === null) {
            this.updateCursor(event);
            return;
        }
        if (!drag.moved && this.pointerDown.distanceTo(new THREE.Vector2(event.clientX, event.clientY)) <= DragThreshold) {
            return;
        }
        const point = this.planPointOn(event, drag.plane);
        if (point === null) {
            return;
        }
        drag.moved = true;
        const free = event.shiftKey;
        const delta = {x: point.x - drag.grab.x, y: point.y - drag.grab.y};

        switch (drag.kind) {
            case 'item': {
                const step = free ? 1 : DragSnap;
                drag.x = snapToGrid(drag.item.x + delta.x, step);
                drag.y = snapToGrid(drag.item.y + delta.y, step);
                drag.object.position.x = drag.x * MM;
                drag.object.position.z = -drag.y * MM;
                break;
            }
            case 'group': {
                // The members keep their positions relative to each other (they need not be on the grid).
                const step = free ? 1 : DragSnap;
                drag.dx = snapToGrid(delta.x, step);
                drag.dy = snapToGrid(delta.y, step);
                for (const {item, object} of drag.members) {
                    object.position.x = (item.x + drag.dx) * MM;
                    object.position.z = -(item.y + drag.dy) * MM;
                }
                break;
            }
            case 'wall':
                this.dragWall(drag, delta, free);
                break;
            case 'opening': {
                const along = alongWall(wallFrame(drag.wall), point) - drag.grabAlong;
                const slid = slideOpening(drag.wall, drag.openingId, snapToGrid(along, free ? 1 : DragSnap));
                drag.offset = slid.offset;
                drag.fits = slid.fits;
                const wall = {...drag.wall, openings: drag.wall.openings.map((o) => (o.id === drag.openingId ? {...o, offset: slid.offset} : o))};
                this.previewWall(wall, slid.fits);
                break;
            }
            case 'vertex': {
                const [x0, y0] = drag.inserted ? [drag.grab.x, drag.grab.y] : drag.outline.element.polygon[drag.index];
                const snapped = this.snapVertex(drag, {x: x0 + delta.x, y: y0 + delta.y}, free);
                drag.polygon = drag.polygon.map((p, i): PlanPoint => (i === drag.index ? [snapped.point.x, snapped.point.y] : p));
                drag.valid = isSimplePolygon(drag.polygon);
                drag.snap = snapped.kind;
                this.previewOutline(drag);
                break;
            }
        }
    };

    /**
     * Moves a wall end (snapping onto other wall ends, or into line with them) or the whole wall, or bends it by its
     * middle (snapping to straight, else to the grid).
     */
    private dragWall(drag: Extract<Drag, {kind: 'wall'}>, delta: Vec2, free: boolean): void {
        const wall = drag.wall;
        if (drag.mode === 'curve') {
            const bent = bendWall(wall, this.dragSagitta(drag, delta, free));
            drag.current = bent.wall;
            drag.fits = bent.fits;
            this.previewWall(bent.wall, bent.fits);
            return;
        }
        const others = (this.sceneData?.walls ?? [])
            .filter((w) => w.id !== wall.id)
            .flatMap((w) => [
                {x: w.startX, y: w.startY},
                {x: w.endX, y: w.endY},
            ]);
        const start = {x: wall.startX, y: wall.startY};
        const end = {x: wall.endX, y: wall.endY};
        const radius = free ? 0 : (SnapPixels * this.metresPerPixel()) / MM;
        const grid = free ? 1 : DragSnap;
        let next: {start: Vec2; end: Vec2};

        if (drag.mode === 'move') {
            let shift = {x: snapToGrid(delta.x, grid), y: snapToGrid(delta.y, grid)};
            drag.snap = 'grid';
            // Either end landing near another wall's end joins it there.
            for (const own of [start, end]) {
                const target = snapPoint({x: own.x + delta.x, y: own.y + delta.y}, {grid, radius, points: others});
                if (target.kind === 'point') {
                    shift = {x: target.point.x - own.x, y: target.point.y - own.y};
                    drag.snap = 'point';
                    break;
                }
            }
            next = {start: {x: start.x + shift.x, y: start.y + shift.y}, end: {x: end.x + shift.x, y: end.y + shift.y}};
        } else {
            const [moving, fixed] = drag.mode === 'start' ? [start, end] : [end, start];
            const snapped = snapPoint({x: moving.x + delta.x, y: moving.y + delta.y}, {grid, radius, points: others, anchors: [fixed, ...others]});
            drag.snap = snapped.kind;
            next = drag.mode === 'start' ? {start: snapped.point, end} : {start, end: snapped.point};
        }

        const moved = moveWallEnds(wall, next.start, next.end);
        drag.current = moved.wall;
        drag.fits = moved.fits;
        this.previewWall(moved.wall, moved.fits);
    }

    /** The sagitta a wall's middle dragged by `delta` gives (`sagittaToward()`). */
    private dragSagitta(drag: Extract<Drag, {kind: 'wall'}>, delta: Vec2, free: boolean): number {
        const wall = drag.wall;
        const frame = wallFrame(wall);
        const middle = pointOnWall(frame, frame.length / 2);
        const point = {x: middle.x + delta.x, y: middle.y + delta.y};
        const radius = free ? 0 : (SnapPixels * this.metresPerPixel()) / MM;
        const sagitta = sagittaToward({x: wall.startX, y: wall.startY}, {x: wall.endX, y: wall.endY}, point, free ? 1 : DragSnap, radius);
        drag.snap = sagitta === 0 && !free ? 'point' : 'grid';
        return sagitta;
    }

    /** A room's or plot's corner snaps onto the other outlines' corners and wall corners, or into line with its neighbours. */
    private snapVertex(drag: Extract<Drag, {kind: 'vertex'}>, point: Vec2, free: boolean): SnapResult {
        const scene = this.sceneData;
        if (free || scene === null) {
            return snapPoint(point, {grid: 1, radius: 0});
        }
        const n = drag.polygon.length;
        const neighbours = [drag.polygon[(drag.index + n - 1) % n], drag.polygon[(drag.index + 1) % n]].map(([x, y]) => ({x, y}));
        const own = (kind: Outlined['kind'], id: number): boolean => drag.outline.kind === kind && drag.outline.element.id === id;
        // The other outlines' corners, not the dragged one's (by id: the scene may have been reloaded meanwhile).
        const outlines = [
            ...scene.rooms.filter((r) => !own('room', r.id)).flatMap((r) => r.polygon),
            ...(scene.plots ?? []).filter((p) => !own('plot', p.id)).flatMap((p) => p.polygon),
        ].map(([x, y]) => ({x, y}));
        const corners = [...outlines, ...scene.walls.flatMap((w) => wallCorners(w, this.joints.get(w.id)))];
        return snapPoint(point, {grid: DragSnap, radius: (SnapPixels * this.metresPerPixel()) / MM, points: corners, anchors: neighbours});
    }

    /** Commits a drag that moved something; a click (no drag) selects the element under the pointer. */
    private readonly handlePointerUp = (event: PointerEvent): void => {
        const drag = this.drag;
        if (drag !== null) {
            this.drag = null;
            this.controls.enabled = true;
            this.commit(drag);
            return;
        }
        if (this.pointerDown.distanceTo(new THREE.Vector2(event.clientX, event.clientY)) > DragThreshold) {
            return;
        }
        if (this.walkthrough !== null) {
            // Touch walk: tapping a door within reach opens or closes it (the mouse uses the view centre instead).
            if (event.pointerType !== 'mouse') {
                this.toggleDoor(this.doorAt(this.toNdc(event)));
            }
            return;
        }
        if (this.commenting) {
            if (event.button === 0) {
                const hit = this.pick(event);
                const floor = hit === undefined ? this.planPointOn(event, FloorPlane) : null;
                const point = hit !== undefined ? commentPointOf(hit.point) : floor === null ? null : {...floor, z: 0};
                if (point !== null) {
                    this.onCommentPlace(point);
                }
            }
            return;
        }
        if (this.measuring) {
            if (this.calibrating && this.measurement.b !== null) {
                // Both calibration points are placed and reported; the panel takes it from here (or cancels).
                return;
            }
            const point = event.button === 0 && this.isTopDown() ? this.planPointOn(event, FloorPlane) : null;
            if (point !== null) {
                this.measurement = measureClick(this.measurement, this.snapMeasure(point, event.shiftKey).point);
                this.updateMeasure();
                const {a, b} = this.measurement;
                if (this.calibrating && a !== null && b !== null) {
                    this.onCalibrate(a, b);
                }
            }
            return;
        }
        if (this.editMode && this.tool !== 'select') {
            if (event.button === 0 && this.isTopDown()) {
                this.toolClick(event);
            }
            return;
        }
        if (this.editMode && this.isTopDown() && this.pickHandle(event) !== null) {
            return;
        }
        const picked = selectionOf(this.pick(event)?.object ?? null);
        // A plot's ground surrounds the building: outside the plan view, where it has no handles, a click on it is a
        // click on the background, which clears the selection as it did before the plot was there.
        const selection = picked?.kind === 'plot' && !this.isTopDown() ? null : picked;
        this.onSelect(this.groupOf(selection, event), event.shiftKey);
    };

    /**
     * The group of a clicked item: an item that is part of a group stands for the whole group, unless Alt is
     * held or an item of the same group is selected already (then its items are picked one by one).
     */
    private groupOf(selection: Selection | null, event: MouseEvent): Selection | null {
        const items = this.sceneData?.items ?? [];
        const groupId = selection?.kind === 'item' ? (items.find((i) => i.id === selection.id)?.groupId ?? null) : null;
        if (groupId === null || event.altKey) {
            return selection;
        }
        const current = this.selection;
        const inside = current?.kind === 'item' && items.find((i) => i.id === current.id)?.groupId === groupId;
        return inside ? selection : {kind: 'group', id: groupId};
    }

    /** Sends the dragged change; the preview stays until the scene reloads, or snaps back when it was refused. */
    private commit(drag: Drag): void {
        let edit: {selection: Selection; fields: Record<string, unknown>} | null = null;
        switch (drag.kind) {
            case 'item':
                if (drag.x !== drag.item.x || drag.y !== drag.item.y) {
                    edit = {selection: {kind: 'item', id: drag.item.id}, fields: {x: drag.x, y: drag.y}};
                }
                break;
            case 'group':
                if (drag.dx !== 0 || drag.dy !== 0) {
                    edit = {selection: {kind: 'group', id: drag.groupId}, fields: {dx: drag.dx, dy: drag.dy}};
                }
                break;
            case 'wall': {
                const {startX, startY, endX, endY, sagitta} = drag.current;
                if (drag.mode === 'curve') {
                    if ((sagitta ?? 0) !== (drag.wall.sagitta ?? 0) && drag.fits) {
                        edit = {selection: {kind: 'wall', id: drag.wall.id}, fields: {sagitta}};
                    }
                    break;
                }
                const changed = startX !== drag.wall.startX || startY !== drag.wall.startY || endX !== drag.wall.endX || endY !== drag.wall.endY;
                if (changed && drag.fits) {
                    edit = {selection: {kind: 'wall', id: drag.wall.id}, fields: {startX, startY, endX, endY}};
                }
                break;
            }
            case 'opening': {
                const original = drag.wall.openings.find((o) => o.id === drag.openingId);
                if (drag.fits && original !== undefined && original.offset !== drag.offset) {
                    edit = {selection: {kind: 'opening', id: drag.openingId}, fields: {offset: drag.offset}};
                }
                break;
            }
            case 'vertex':
                if (drag.valid && (drag.inserted || !sameJson(drag.polygon, drag.outline.element.polygon))) {
                    edit = {selection: {kind: drag.outline.kind, id: drag.outline.element.id}, fields: {polygon: drag.polygon}};
                }
                break;
        }

        if (edit === null) {
            this.restore(drag);
            return;
        }
        const loads = this.loads;
        void this.onEdit(edit.selection, edit.fields).then(
            (applied) => {
                // Applied edits reload the scene, which replaces the preview; otherwise put things back.
                if (!applied && this.loads === loads) {
                    this.restore(drag);
                }
            },
            () => this.loads === loads && this.restore(drag),
        );
    }

    /** Puts a dragged element back where the scene has it. */
    private restore(drag: Drag): void {
        const members = drag.kind === 'item' ? [drag] : drag.kind === 'group' ? drag.members : [];
        for (const {item, object} of members) {
            object.position.x = item.x * MM;
            object.position.z = -item.y * MM;
        }
        this.clearPreview();
        this.setSelection(this.selection);
    }

    /** Ends a drag without committing it. */
    private cancelDrag(): void {
        const drag = this.drag;
        if (drag === null) {
            return;
        }
        this.drag = null;
        this.controls.enabled = true;
        this.restore(drag);
    }

    /** Double-clicking an item of a group selects just that item; double-clicking a room corner removes it (a room keeps at least three). */
    private readonly handleDoubleClick = (event: MouseEvent): void => {
        if (this.measuring) {
            return;
        }
        if (this.editMode && this.tool === 'drawWalls') {
            this.commitRun(false);
            return;
        }
        if (this.editMode && this.tool !== 'select') {
            return;
        }
        const clicked = selectionOf(this.pick(event)?.object ?? null);
        const grouped = clicked?.kind === 'item' && (this.sceneData?.items.find((i) => i.id === clicked.id)?.groupId ?? null) !== null;
        if (clicked !== null && grouped && this.walkthrough === null) {
            this.onSelect(clicked, false);
            return;
        }
        const outline = this.editMode && this.isTopDown() ? this.selectedOutline() : null;
        if (outline === null) {
            return;
        }
        const role = (this.pickHandle(event)?.object.userData as {handle?: HandleRole} | undefined)?.handle;
        if (role?.role !== 'vertex') {
            return;
        }
        const polygon = removeVertex(outline.element.polygon, role.index);
        if (polygon !== null && isSimplePolygon(polygon)) {
            void this.onEdit({kind: outline.kind, id: outline.element.id}, {polygon});
        }
    };

    private readonly handleKeyDown = (event: KeyboardEvent): void => {
        this.invalidate();
        if (event.key === 'Escape' && this.drag !== null) {
            event.stopPropagation();
            this.cancelDrag();
            return;
        }
        const typing = event.target instanceof HTMLInputElement || event.target instanceof HTMLTextAreaElement;
        // Esc clears a measurement first; without one it is the page's (turning the tool off, or cancelling a calibration).
        if (event.key === 'Escape' && this.measuring && !typing && !this.calibrating && this.clearMeasurement()) {
            event.stopPropagation();
            return;
        }
        if (this.editMode && this.tool === 'drawWalls' && !typing && !event.ctrlKey && !event.metaKey && event.key.toLowerCase() === 'a') {
            event.stopPropagation();
            this.setDrawArc(!this.drawArc);
            return;
        }
        if (this.run === null || typing) {
            return;
        }
        // The run being drawn takes these keys before the editor's shortcuts (Esc would end the tool, Backspace delete).
        if (event.key === 'Escape') {
            event.stopPropagation();
            this.cancelRun();
        } else if (event.key === 'Backspace') {
            event.stopPropagation();
            event.preventDefault();
            // Back before the last click: the curve waiting for its click, or the last point (and its segment's curve).
            if (this.run.bend?.closing === true) {
                this.run.bend = null;
            } else {
                this.run.bend = null;
                this.run.points.pop();
                this.run.sagittas.splice(Math.max(this.run.points.length - 1, 0));
            }
            if (this.run.points.length === 0) {
                this.run = null;
            }
            this.updateDrawing();
        } else if (event.key === 'Enter') {
            event.stopPropagation();
            if (this.run.bend !== null) {
                this.fixBend();
            }
            if (this.run !== null) {
                this.commitRun(false);
            }
        }
    };

    /** The pointer left the view: the readout no longer follows it. */
    private readonly handlePointerLeave = (): void => {
        if (this.measuring) {
            this.measurement = {...this.measurement, cursor: this.measurement.b, snap: null};
            this.updateMeasure();
            return;
        }
        if (!this.editMode || this.tool === 'select') {
            return;
        }
        if (this.run !== null) {
            this.run.cursor = null;
            this.run.snap = null;
        }
        this.detection = null;
        this.updateDrawing(null, null);
    };

    /** Follows the pointer with the measure tool: the second point until it is placed, else just the snap marker. */
    private trackMeasure(event: PointerEvent): void {
        const point = this.isTopDown() ? this.planPointOn(event, FloorPlane) : null;
        if (point === null) {
            this.measurement = {...this.measurement, cursor: this.measurement.b, snap: null};
        } else {
            const snapped = this.snapMeasure(point, event.shiftKey);
            this.measurement = {...this.measurement, cursor: snapped.point, snap: snapped.kind};
        }
        this.updateMeasure();
    }

    private snapMeasure(point: Vec2, free: boolean): SnapResult {
        const from = this.measurement.b === null ? this.measurement.a : null;
        // Calibration points are features of the image, which the drawn walls would only pull them away from.
        const radius = free || this.calibrating ? 0 : (SnapPixels * this.metresPerPixel()) / MM;
        return snapMeasurePoint(point, {radius, walls: this.sceneData?.walls ?? [], joints: this.joints, from});
    }

    /** Redraws the measurement (line, points, distance label) and reports the readout. */
    private updateMeasure(): void {
        // The markers share the handles' geometry and materials; only the line is the layer's own.
        this.measureLayer.children.slice().forEach((o) => {
            disposeLine(o);
            o.removeFromParent();
        });
        this.measureLabel.element.textContent = '';
        if (!this.measuring) {
            this.onMeasureStatus(null);
            return;
        }
        const {a, b, cursor, snap} = this.measurement;
        const end = b ?? cursor;
        const y = 0.03;
        if (a !== null && end !== null && (end.x !== a.x || end.y !== a.y)) {
            const geometry = new THREE.BufferGeometry().setFromPoints([
                new THREE.Vector3(a.x * MM, y, -a.y * MM),
                new THREE.Vector3(end.x * MM, y, -end.y * MM),
            ]);
            const line = new THREE.Line(geometry, new THREE.LineBasicMaterial({color: HighlightColor, depthTest: false, transparent: true}));
            line.renderOrder = 9;
            this.measureLayer.add(line);
            const status = measureStatus(this.measurement);
            this.measureLabel.element.textContent = `${status.distance ?? 0} mm`;
            this.measureLabel.position.set(((a.x + end.x) / 2) * MM, y, (-(a.y + end.y) / 2) * MM);
        }
        for (const point of [a, b]) {
            if (point !== null) {
                this.addMarker(point, 'normal');
            }
        }
        if (cursor !== null && b === null) {
            this.addMarker(cursor, handleState(true, snap));
        }
        this.onMeasureStatus(measureStatus(this.measurement));
    }

    private addMarker(at: Vec2, state: HandleState): void {
        const disc = new THREE.Mesh(this.handleGeometry, this.handleMaterial(HandleColors[state]));
        disc.position.set(at.x * MM, 0.03, -at.y * MM);
        disc.renderOrder = 10;
        disc.userData = {pixels: MidpointPixels};
        this.measureLayer.add(disc);
    }

    /** Follows the pointer with the active tool: the next point of a run, or the room that a click would add. */
    private trackTool(event: PointerEvent): void {
        const point = this.isTopDown() ? this.planPointOn(event, FloorPlane) : null;
        if (point === null) {
            // Tilted away from the plan: the tools do nothing here, and the readout says nothing either.
            this.onToolStatus(null);
            return;
        }
        if (this.tool === 'drawWalls' && this.run?.bend != null) {
            this.run.bend.sagitta = this.bendTowards(this.run, point, event.shiftKey);
            this.run.cursor = null;
            this.updateDrawing();
            return;
        }
        if (this.tool === 'drawWalls') {
            const snapped = this.snapRunPoint(point, event.shiftKey);
            if (this.run !== null) {
                this.run.cursor = snapped.point;
                this.run.snap = snapped.kind;
            }
            this.updateDrawing(snapped.point, snapped.kind);
            return;
        }
        const current = this.detection;
        const inside = current !== null && 'polygon' in current && pointInPolygon(point, current.polygon);
        if (!inside) {
            this.detection = this.roomAt(point);
            this.updateDrawing();
        }
    }

    private roomAt(point: Vec2): RoomDetection {
        this.roomFaces ??= prepareRoomFaces(this.sceneData?.walls ?? []);
        return pickRoom(this.roomFaces, point);
    }

    /** Whether the scene has a wall along every segment of the run (the run arrived). */
    private hasWallsAlong(scene: Scene, run: {points: Vec2[]; closed: boolean}): boolean {
        const {points} = run;
        const ends = points.slice(1).map((p, i): [Vec2, Vec2] => [points[i], p]);
        if (run.closed) {
            ends.push([points[points.length - 1], points[0]]);
        }
        return ends.every(([a, b]) => scene.walls.some((w) => w.startX === a.x && w.startY === a.y && w.endX === b.x && w.endY === b.y));
    }

    private toolClick(event: PointerEvent): void {
        const point = this.planPointOn(event, FloorPlane);
        if (point === null || this.pendingRun !== null) {
            return;
        }
        if (this.tool === 'drawWalls' && this.run?.bend != null) {
            this.run.bend.sagitta = this.bendTowards(this.run, point, event.shiftKey);
            this.fixBend();
            return;
        }
        if (this.tool === 'drawWalls') {
            this.addRunPoint(this.snapRunPoint(point, event.shiftKey).point);
            return;
        }
        const detection = this.roomAt(point);
        this.detection = detection;
        this.updateDrawing();
        if ('polygon' in detection) {
            this.onDetectRoom([Math.round(point.x), Math.round(point.y)]);
        }
    }

    private snapRunPoint(point: Vec2, free: boolean): SnapResult {
        const radius = free ? 0 : (SnapPixels * this.metresPerPixel()) / MM;
        return snapDrawPoint(point, {grid: free ? 1 : DragSnap, radius, walls: this.sceneData?.walls ?? [], points: this.run?.points ?? []});
    }

    /** The segment of the run waiting for its curve: the last one, or the one closing the run. */
    private bendingSegment(run: WallRun): [Vec2, Vec2] {
        const last = run.points[run.points.length - 1];
        return run.bend?.closing === true ? [last, run.points[0]] : [run.points[run.points.length - 2], last];
    }

    /** The curve that puts the middle of the waiting segment at `point`'s distance off it. */
    private bendTowards(run: WallRun, point: Vec2, free: boolean): number {
        const [a, b] = this.bendingSegment(run);
        const radius = free ? 0 : (SnapPixels * this.metresPerPixel()) / MM;
        return sagittaToward(a, b, point, free ? 1 : DragSnap, radius);
    }

    /** Fixes the waiting segment's curve; a closing one sends the run. */
    private fixBend(): void {
        const run = this.run;
        const bend = run?.bend;
        if (run === null || bend == null) {
            return;
        }
        run.bend = null;
        if (bend.closing) {
            run.sagittas[run.points.length - 1] = bend.sagitta;
            this.commitRun(true);
            return;
        }
        run.sagittas[run.points.length - 2] = bend.sagitta;
        this.updateDrawing();
    }

    /**
     * Adds a point to the run (starting one) with the curve of the segment to it; back at the first point the run
     * closes and is sent. In arc mode, without a typed curve, the segment then waits for its curve.
     */
    private addRunPoint(point: Vec2, sagitta: number | null = null): void {
        const run = this.run;
        if (run === null) {
            this.run = {points: [point], sagittas: [], cursor: point, snap: null, bend: null};
            this.updateDrawing();
            return;
        }
        if (closesRun(run.points, point)) {
            if (sagitta === null && this.drawArc) {
                run.bend = {closing: true, sagitta: 0};
                this.updateDrawing();
                return;
            }
            run.sagittas[run.points.length - 1] = sagitta ?? 0;
            this.commitRun(true);
            return;
        }
        if (run.points.length < 3 && point.x === run.points[0].x && point.y === run.points[0].y) {
            // Back at the start with nothing to close: a wall there and back would lie on itself.
            return;
        }
        // Too short for a wall (the backend's minimum): a second click of a double-click, or a slip.
        const last = run.points[run.points.length - 1];
        if (Math.hypot(point.x - last.x, point.y - last.y) < MinWallLength) {
            return;
        }
        run.points.push(point);
        run.sagittas[run.points.length - 2] = sagitta ?? 0;
        if (sagitta === null && this.drawArc) {
            run.bend = {closing: false, sagitta: 0};
        }
        this.updateDrawing();
    }

    /** Sends the run; it stays on screen until the result loads, and comes back to be fixed when refused. */
    private commitRun(closed: boolean): void {
        const run = this.run;
        if (run === null || this.pendingRun !== null) {
            return;
        }
        this.run = null;
        if (run.points.length < (closed ? 3 : 2)) {
            this.updateDrawing();
            return;
        }
        const segments = closed ? run.points.length : run.points.length - 1;
        const sagittas = Array.from({length: segments}, (_, i) => run.sagittas[i] ?? 0);
        const pending = {points: run.points, closed, sagittas};
        this.pendingRun = pending;
        this.updateDrawing();
        void this.onDrawWalls(
            run.points.map((p): PlanPoint => [p.x, p.y]),
            closed,
            sagittas.some((s) => s !== 0) ? sagittas : null,
        ).then((added) => {
            if (this.pendingRun !== pending) {
                return;
            }
            this.pendingRun = null;
            if (!added) {
                this.run = {...run, cursor: null, snap: null};
            }
            this.updateDrawing();
        });
    }

    /**
     * Rebuilds the previews of the active tool (the walls of the run, up to the pointer at `cursor`; the room
     * outline under the pointer), their markers, and reports the readout.
     */
    private updateDrawing(cursor: Vec2 | null = this.run?.cursor ?? null, snap: SnapResult['kind'] | null = this.run?.snap ?? null): void {
        this.drawLayer.children.slice().forEach((o) => this.disposeObject(o));
        this.updateHandles();
        if (!this.editMode || this.tool === 'select') {
            this.onToolStatus(null);
            return;
        }
        if (this.tool === 'detectRoom') {
            const detection = this.detection;
            if (detection !== null && 'polygon' in detection) {
                this.drawLayer.add(outlineMesh(detection.polygon));
            }
            this.onToolStatus({
                tool: 'detectRoom',
                area: detection !== null && 'polygon' in detection ? polygonSignedArea(detection.polygon) / 1e6 : null,
                error: detection !== null && 'error' in detection ? detection.error : null,
            });
            return;
        }

        const pending = this.pendingRun;
        const run = this.run;
        const points = pending?.points ?? run?.points ?? [];
        const sagittas = pending?.sagittas ?? run?.sagittas ?? [];
        const bend = pending === null ? (run?.bend ?? null) : null;
        const last = points.at(-1);
        const segments: [Vec2, Vec2, number][] = points.slice(1).map((p, i) => [points[i], p, sagittas[i] ?? 0]);
        if (bend !== null && !bend.closing && segments.length > 0) {
            segments[segments.length - 1][2] = bend.sagitta;
        }
        if ((pending?.closed === true || bend?.closing === true) && last !== undefined) {
            segments.push([last, points[0], bend?.closing === true ? bend.sagitta : (sagittas[points.length - 1] ?? 0)]);
        }
        if (pending === null && bend === null && last !== undefined && cursor !== null && Math.hypot(cursor.x - last.x, cursor.y - last.y) >= 1) {
            segments.push([last, cursor, 0]);
        }
        this.addRunWalls(segments);
        this.markRun(points, pending === null ? cursor : null, snap);
        const length = last !== undefined && cursor !== null ? Math.round(Math.hypot(cursor.x - last.x, cursor.y - last.y)) : null;
        this.onToolStatus({
            tool: 'drawWalls',
            points: points.length,
            length,
            angle: last !== undefined && cursor !== null && length !== 0 ? Math.round(directionOf(last, cursor) * 10) / 10 : null,
            snap: cursor === null ? null : snap,
            arc: this.drawArc,
            sagitta: bend?.sagitta ?? null,
        });
    }

    /** Preview walls of a run (start, end and curve each), mitred with each other and with the walls of the floor. */
    private addRunWalls(segments: readonly [Vec2, Vec2, number][]): void {
        const parts = this.parts;
        const materials = this.materials;
        if (parts === null || materials === null || segments.length === 0) {
            return;
        }
        const drawn: Wall[] = segments.map(([a, b, sagitta], i) => ({
            id: -1 - i,
            name: 'New wall',
            type: 'Interior',
            startX: a.x,
            startY: a.y,
            endX: b.x,
            endY: b.y,
            sagitta,
            thickness: this.wallDraft.thickness,
            height: this.wallDraft.height,
            materialId: null,
            leftFinishes: null,
            rightFinishes: null,
            openings: [],
        }));
        const joints = wallJoints([...(this.sceneData?.walls ?? []), ...drawn]);
        drawn.forEach((wall) => this.drawLayer.add(buildWall(wall, materials, parts.sectionMaterial, joints.get(wall.id))));
    }

    /** Markers of a run in the overlay: its points, and the pointer (green when snapped). */
    private markRun(points: readonly Vec2[], cursor: Vec2 | null, snap: SnapResult['kind'] | null): void {
        const y = 0.02;
        points.forEach((p) => this.addHandle(p, y, {role: 'draw'}, 'midpoint'));
        if (cursor !== null) {
            this.addHandle(cursor, y, {role: 'draw'}, handleState(true, snap));
        }
    }

    private updateCursor(event: PointerEvent): void {
        const overHandle = this.editMode && this.isTopDown() && this.handles.children.length > 0 && this.pickHandle(event) !== null;
        this.renderer.domElement.style.cursor = overHandle ? 'grab' : '';
    }

    /** Shows `wall` (a dragged state) in place of the wall's own group; `fits` false tints it as refused. */
    private previewWall(wall: Wall, fits: boolean): void {
        const scene = this.sceneData;
        const parts = this.parts;
        const materials = this.materials;
        if (scene === null || parts === null || materials === null) {
            return;
        }
        const walls = scene.walls.map((w) => (w.id === wall.id ? wall : w));
        const group = buildWall(wall, materials, parts.sectionMaterial, wallJoints(walls).get(wall.id), cornerWindows(walls).get(wall.id));
        const original = parts.walls.get(wall.id);
        this.setPreview(original === undefined ? [] : [original], [group]);

        if (this.selection?.kind === 'wall' && this.selection.id === wall.id) {
            this.outlinedObjects = [group];
        } else if (this.selection?.kind === 'opening') {
            const openingId = this.selection.id;
            group.traverse((o) => {
                if ((o.userData as Record<string, unknown>)['openingId'] === openingId) {
                    this.outlinedObjects = [o];
                }
            });
        }
        this.setOutlineValid(fits);
        this.updateHandles();
    }

    /** Shows the dragged outline of a room (its floor, highlight, label) or plot (its ground and boundary), and the handles. */
    private previewOutline(drag: Extract<Drag, {kind: 'vertex'}>): void {
        const parts = this.parts;
        const materials = this.materials;
        if (parts === null || materials === null) {
            return;
        }
        if (drag.outline.kind === 'plot') {
            const plot = buildPlot({...drag.outline.element, polygon: drag.polygon}, materials);
            highlightPlot(plot, true);
            const original = parts.plots.get(drag.outline.element.id);
            this.setPreview(original === undefined ? [] : [original], [plot]);
            this.updateHandles();
            return;
        }
        const room = {...drag.outline.element, polygon: drag.polygon};
        const floor = buildFloor(room, materials);
        const original = parts.rooms.get(room.id);
        this.setPreview(original === undefined ? [] : [original.floor], [floor]);
        this.setSelectedRoom(room.id, floor);
        const label = this.labels.get(room.id);
        if (label !== undefined) {
            const c = polygonCentroid(room.polygon);
            label.position.set(c.x * MM, 0.05, -c.y * MM);
        }
        this.updateHandles();
    }

    private setPreview(hidden: THREE.Object3D[], added: THREE.Object3D[]): void {
        const previous = this.preview;
        if (previous !== null) {
            previous.added.forEach((o) => this.disposeObject(o));
        }
        const all = [...new Set([...(previous?.hidden ?? []), ...hidden])];
        all.forEach((o) => (o.visible = false));
        added.forEach((o) => this.parts?.root.add(o));
        this.preview = {hidden: all, added};
    }

    private clearPreview(): void {
        const preview = this.preview;
        if (preview === null) {
            return;
        }
        this.preview = null;
        preview.added.forEach((o) => this.disposeObject(o));
        preview.hidden.forEach((o) => (o.visible = true));
        // Labels moved with a dragged room go back to their room.
        this.sceneData?.rooms.forEach((room) => {
            const label = this.labels.get(room.id);
            const c = polygonCentroid(room.polygon);
            label?.position.set(c.x * MM, 0.05, -c.y * MM);
        });
    }

    /** Recreates the handles of the selected wall (its ends), room or plot (corners, plus edge midpoints to pull new corners out). */
    private updateHandles(): void {
        this.handles.clear();
        const scene = this.sceneData;
        if (!this.editMode || scene === null || this.selection === null || this.tool !== 'select' || this.measuring) {
            return;
        }
        const drag = this.drag;
        if (this.selection.kind === 'wall') {
            const id = this.selection.id;
            const wall = drag?.kind === 'wall' && drag.wall.id === id ? drag.current : scene.walls.find((w) => w.id === id);
            if (wall === undefined) {
                return;
            }
            const y = Math.min(this.sectionHeight ?? wall.height, wall.height) * MM + 0.01;
            const state = drag?.kind === 'wall' && drag.mode !== 'move' ? handleState(drag.fits, drag.snap) : 'normal';
            const active = drag?.kind === 'wall' ? drag.mode : null;
            this.addHandle({x: wall.startX, y: wall.startY}, y, {role: 'wallStart'}, active === 'start' ? state : 'normal');
            this.addHandle({x: wall.endX, y: wall.endY}, y, {role: 'wallEnd'}, active === 'end' ? state : 'normal');
            // The middle of the centre line bends the wall.
            const frame = wallFrame(wall);
            this.addHandle(pointOnWall(frame, frame.length / 2), y, {role: 'wallCurve'}, active === 'curve' ? state : 'normal');
        } else {
            const outline = this.selectedOutline();
            if (outline === null) {
                return;
            }
            const dragged = drag?.kind === 'vertex' && drag.outline.kind === outline.kind && drag.outline.element.id === outline.element.id;
            const vertexDrag = dragged ? drag : null;
            const polygon = vertexDrag?.polygon ?? outline.element.polygon;
            polygon.forEach(([x, y], index) => {
                let state: HandleState = 'normal';
                if (vertexDrag?.index === index) {
                    state = handleState(vertexDrag.valid, vertexDrag.snap);
                }
                this.addHandle({x, y}, 0.02, {role: 'vertex', index}, state);
            });
            if (vertexDrag === null) {
                edgeMidpoints(polygon).forEach((p, index) => this.addHandle(p, 0.02, {role: 'midpoint', index}, 'midpoint'));
            }
        }
    }

    private addHandle(at: Vec2, y: number, handle: HandleRole, state: HandleState): void {
        const disc = new THREE.Mesh(this.handleGeometry, this.handleMaterial(HandleColors[state]));
        disc.position.set(at.x * MM, y, -at.y * MM);
        disc.renderOrder = 10;
        disc.userData = {handle, pixels: handle.role === 'midpoint' || handle.role === 'draw' ? MidpointPixels : HandlePixels};
        if (handle.role === 'draw') {
            disc.raycast = (): void => undefined;
        }
        const core = new THREE.Mesh(this.handleCoreGeometry, this.handleMaterial('#ffffff'));
        core.renderOrder = 11;
        core.raycast = (): void => undefined;
        disc.add(core);
        this.handles.add(disc);
    }

    private handleMaterial(color: string): THREE.MeshBasicMaterial {
        let material = this.handleMaterials.get(color);
        if (material === undefined) {
            material = new THREE.MeshBasicMaterial({color, depthTest: false, depthWrite: false, side: THREE.DoubleSide, transparent: true});
            this.handleMaterials.set(color, material);
        }
        return material;
    }

    private pickHandle(event: MouseEvent): THREE.Intersection | null {
        if (this.handles.children.length === 0) {
            return null;
        }
        // Scaled for the current view, as the next frame will draw them.
        const perPixel = this.metresPerPixel();
        this.handles.children.forEach((h) => h.scale.setScalar(((h.userData as {pixels?: number}).pixels ?? HandlePixels) * perPixel));
        this.handles.updateMatrixWorld(true);
        this.raycaster.setFromCamera(this.toNdc(event), this.camera);
        return this.raycaster.intersectObjects(this.handles.children, false)[0] ?? null;
    }

    /** Plan point (mm) under the pointer on a horizontal plane. */
    private planPointOn(event: MouseEvent, plane: THREE.Plane): Vec2 | null {
        this.raycaster.setFromCamera(this.toNdc(event), this.camera);
        const point = this.raycaster.ray.intersectPlane(plane, new THREE.Vector3());
        return point === null ? null : {x: point.x / MM, y: -point.z / MM};
    }

    private pick(event: MouseEvent): THREE.Intersection | undefined {
        return this.pickAt(this.toNdc(event));
    }

    /** The nearest visible object at a screen point (NDC), up to `far` metres from the camera. */
    private pickAt(ndc: THREE.Vector2, far = Infinity): THREE.Intersection | undefined {
        this.raycaster.setFromCamera(ndc, this.camera);
        this.raycaster.far = far;
        const hits = this.content === null ? [] : this.raycaster.intersectObject(this.content, true);
        this.raycaster.far = Infinity;
        const clip = this.renderer.clippingPlanes[0];

        return hits.find((h) => isVisible(h.object) && (clip === undefined || clip.distanceToPoint(h.point) >= 0));
    }

    /** The door or walkable French window within reach at a screen point (NDC), unless something else is in front. */
    private doorAt(ndc: THREE.Vector2): number | null {
        const selection = selectionOf(this.pickAt(ndc, DoorReach)?.object ?? null);
        return selection?.kind === 'opening' && this.leafStates.has(selection.id) ? selection.id : null;
    }

    /** Opens or closes a door (nothing for null). */
    private toggleDoor(openingId: number | null): void {
        if (openingId !== null) {
            this.leafStates.toggle(openingId);
            this.updateAim();
        }
    }

    /** Follows the door at the centre of the walker's view. */
    private updateAim(): void {
        const openingId = this.doorAt(new THREE.Vector2(0, 0));
        this.setAim(openingId === null ? null : {openingId, open: this.leafStates.isOpen(openingId)});
    }

    private setAim(aim: DoorAim | null): void {
        if (aim?.openingId !== this.aim?.openingId || aim?.open !== this.aim?.open) {
            this.aim = aim;
            this.onWalkthroughAim(aim);
        }
    }

    /** The walker bumps into door leaves and sashes as they are swung now. */
    private updateLeafColliders(): void {
        if (this.walkthrough !== null && this.sceneData !== null) {
            this.walkthrough.setLeafColliders(leafColliders(this.sceneData.walls, (id) => this.leafStates.swing(id)));
        }
    }

    private toNdc(event: MouseEvent): THREE.Vector2 {
        const rect = this.renderer.domElement.getBoundingClientRect();
        return new THREE.Vector2(((event.clientX - rect.left) / rect.width) * 2 - 1, -((event.clientY - rect.top) / rect.height) * 2 + 1);
    }

    private objectsOf(selection: Selection | null): THREE.Object3D[] {
        // Hidden objects (items of variants not shown) get no outline.
        const objects = (ids: number[], map: Map<number, THREE.Object3D>): THREE.Object3D[] =>
            ids.map((id) => map.get(id)).filter((o): o is THREE.Object3D => o !== undefined && o.visible);
        switch (selection?.kind) {
            case 'item':
                return objects([selection.id], this.items);
            case 'group':
                return objects(
                    (this.sceneData?.items ?? []).filter((i) => i.groupId === selection.id).map((i) => i.id),
                    this.items,
                );
            case 'wall':
                return objects([selection.id], this.walls);
            case 'opening':
                return objects([selection.id], this.openings);
            default:
                return [];
        }
    }

    /** Fits the outline boxes to the selected and picked objects' current bounds (cheap: a few small objects). */
    private updateOutline(): void {
        if (this.outline !== null) {
            fitOutline(this.outline, this.outlinedObjects);
        }
        this.pickedOutlines.forEach(({mesh, object}) => fitOutline(mesh, [object]));
    }

    private removePickedOutlines(): void {
        this.pickedOutlines.forEach(({mesh}) => disposeOutline(mesh));
        this.pickedOutlines = [];
    }

    /** Tints the outline red while a dragged state would be refused. */
    private setOutlineValid(valid: boolean): void {
        const color = valid ? HighlightColor : HandleColors.invalid;
        this.outline?.traverse((o) => {
            if (o instanceof THREE.Mesh || o instanceof THREE.LineSegments) {
                (o.material as THREE.MeshBasicMaterial | THREE.LineBasicMaterial).color.set(color);
            }
        });
    }

    private removeOutline(): void {
        if (this.outline !== null) {
            disposeOutline(this.outline);
            this.outline = null;
        }
        this.outlinedObjects = [];
    }

    private clearContent(): void {
        this.cancelDrag();
        this.clearPreview();
        this.setSelectedRoom(null);
        this.removeOutline();
        this.removePickedOutlines();
        this.handles.clear();
        if (this.content !== null) {
            const geometries = new Set<THREE.BufferGeometry>();
            const materials = new Set<THREE.Material>();
            this.content.traverse((object) => {
                if (object instanceof THREE.Mesh || object instanceof THREE.Line) {
                    geometries.add(object.geometry);
                    (Array.isArray(object.material) ? object.material : [object.material]).forEach((m) => materials.add(m));
                }
                if (object instanceof CSS2DObject) {
                    object.element.remove();
                }
            });
            geometries.forEach((g) => g.dispose());
            materials.forEach((m) => m.dispose());
            this.content.removeFromParent();
            this.content = null;
        }
        this.materials?.dispose();
        this.materials = null;
        this.parts = null;
        this.labels.clear();
        this.items.clear();
        this.walls.clear();
        this.openings.clear();
        this.joints.clear();
        this.corners.clear();
    }
}

/** What the walker collides with: the walls and the shown columns (door leaves come on top, as they move). */
function sceneColliders(scene: Scene): WallCollider[] {
    const hidden = hiddenItemIds(scene);
    return [...wallColliders(scene.walls), ...columnColliders(scene.items.filter((item) => !hidden.has(item.id)))];
}

/** Scales an outline box to the joint bounds of the objects, a few mm larger so it does not z-fight with their faces. */
function fitOutline(outline: THREE.Mesh, objects: THREE.Object3D[]): void {
    const bounds = new THREE.Box3();
    objects.forEach((object) => bounds.expandByObject(object));
    if (bounds.isEmpty()) {
        return;
    }
    const size = bounds.getSize(new THREE.Vector3()).addScalar(0.01);
    bounds.getCenter(outline.position);
    outline.scale.copy(size);
}

function disposeOutline(outline: THREE.Mesh): void {
    outline.removeFromParent();
    outline.traverse((o) => {
        if (o instanceof THREE.Mesh || o instanceof THREE.LineSegments) {
            o.geometry.dispose();
            (o.material as THREE.Material).dispose();
        }
    });
}

/** Unit box (scaled to the selection's bounds each frame): translucent faces plus edges, drawn on top. */
function createOutline(): THREE.Mesh {
    const geometry = new THREE.BoxGeometry(1, 1, 1);
    const box = new THREE.Mesh(
        geometry,
        new THREE.MeshBasicMaterial({color: HighlightColor, transparent: true, opacity: 0.18, depthTest: false, depthWrite: false}),
    );
    box.renderOrder = 2;
    const edges = new THREE.LineSegments(
        new THREE.EdgesGeometry(geometry),
        new THREE.LineBasicMaterial({color: HighlightColor, depthTest: false, transparent: true}),
    );
    edges.renderOrder = 3;
    box.add(edges);
    return box;
}

/** Visible itself and in every ancestor (hidden ceilings must not catch clicks). */
function isVisible(object: THREE.Object3D): boolean {
    for (let o: THREE.Object3D | null = object; o !== null; o = o.parent) {
        if (!o.visible) {
            return false;
        }
    }
    return true;
}

/** Elements added or changed (by content) and ids removed between two lists. */
function diffById<T extends {id: number}>(previous: readonly T[], next: readonly T[]): {changed: T[]; removed: number[]} {
    const before = new Map(previous.map((e) => [e.id, e]));
    const changed = next.filter((e) => {
        const old = before.get(e.id);
        return old === undefined || (old !== e && !sameJson(old, e));
    });
    const ids = new Set(next.map((e) => e.id));
    return {changed, removed: previous.filter((e) => !ids.has(e.id)).map((e) => e.id)};
}

/** A flat outline of a room-to-be on the floor, drawn over the floor finishes. */
function outlineMesh(polygon: readonly PlanPoint[]): THREE.Mesh {
    const shape = new THREE.Shape(polygon.map(([x, y]) => new THREE.Vector2(x * MM, y * MM)));
    const geometry = new THREE.ShapeGeometry(shape).rotateX(-Math.PI / 2);
    const material = new THREE.MeshBasicMaterial({
        color: HighlightColor,
        transparent: true,
        opacity: 0.35,
        depthWrite: false,
        side: THREE.DoubleSide,
    });
    const mesh = new THREE.Mesh(geometry, material);
    mesh.position.y = 0.025;
    mesh.renderOrder = 5;
    return mesh;
}

/** A dragged handle: red when the result would be refused, green when snapped onto anything but the grid. */
function handleState(valid: boolean, snap: SnapResult['kind'] | null): HandleState {
    if (!valid) {
        return 'invalid';
    }
    return snap !== null && snap !== 'grid' ? 'snapped' : 'normal';
}

/** Frees the geometry and material of a line (dimension or measure lines); other objects are left alone. */
function disposeLine(object: THREE.Object3D): void {
    if (object instanceof THREE.Line) {
        object.geometry.dispose();
        (object.material as THREE.Material).dispose();
    }
    if (object instanceof CSS2DObject) {
        object.element.remove();
    }
}

function sameJson(a: unknown, b: unknown): boolean {
    return a === b || JSON.stringify(a) === JSON.stringify(b);
}

/** The four corners of a wall's footprint (mitred with `ends`), where room outlines usually meet it. */
function wallCorners(wall: Wall, ends?: WallEnds): Vec2[] {
    const {left, right} = wallFaces(wall, ends);
    return [left[0], left[left.length - 1], right[0], right[right.length - 1]].map((p) => ({x: Math.round(p.x), y: Math.round(p.y)}));
}
