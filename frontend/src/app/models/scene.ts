import {FurnitureDefinitionBody} from './furniture';

// Mirrors the backend DTOs (FlatDesigner\Dto). All lengths are millimetres in plan coordinates:
// origin at the inner south-west corner of the floor, x east, y north.

export type MaterialPattern = 'Plain' | 'Wood' | 'Planks' | 'Tiles' | 'Glass' | 'Grass' | 'Gravel' | 'Terrazzo' | 'Brick' | 'Herringbone';
export type RoomType = 'Living' | 'Bedroom' | 'Bathroom' | 'Toilet' | 'Hallway' | 'Terrace' | 'Garden' | 'Path' | 'Driveway';

/** Open-air room types (`RoomTypeEnum::isOutdoor()`): totalled apart from the indoor area. */
export const OutdoorRoomTypes: readonly RoomType[] = ['Terrace', 'Garden', 'Path', 'Driveway'];

export const isOutdoorRoom = (room: {type: RoomType}): boolean => OutdoorRoomTypes.includes(room.type);
export type WallType = 'Exterior' | 'Interior' | 'Shaft';
export type OpeningType = 'Door' | 'DoubleDoor' | 'SlidingDoor' | 'PocketDoor' | 'Passage' | 'Window' | 'Niche';
export type DoorFrameStyle = 'Lining' | 'Architrave' | 'Flush' | 'None';
export type DoorHandle = 'Lever' | 'Knob' | 'Pull' | 'None';
export type DoorLeafStyle = 'Flush' | 'Panelled' | 'Glazed';
export type ShutterStyle = 'Louvred' | 'Solid';
export type ItemKind =
    | 'Sink'
    | 'Hob'
    | 'LedStrip'
    | 'Box'
    | 'Bathtub'
    | 'Washbasin'
    | 'Toilet'
    | 'WashingMachine'
    | 'Mirror'
    | 'TowelRadiator'
    | 'FlushPlate'
    | 'Fixture'
    | 'Fence'
    /** A structural pillar, square or round (`params.shape`). */
    | 'Column'
    /** A structural downstand beam along its width; z is its soffit. */
    | 'Beam'
    /** Drawn from a furniture definition (`definitionId`, in the scene's `definitions`). */
    | 'Custom'
    /** A bought product drawn from a 3D model (`modelId`, in the scene's `models`), scaled to fit the item's box. */
    | 'Model';

export type PlanPoint = [number, number];

export interface Floor {
    id: number;
    name: string;
    /** 0 = ground floor, negative below ground. */
    level: number;
    /** mm, the finished floor level above the project datum ±0.000. All floors share the plan coordinates. */
    elevation: number;
    /** mm, the slab under the floor. */
    slabThickness: number;
}

export interface Material {
    id: number;
    code: string;
    name: string;
    pattern: MaterialPattern;
    color: string;
    roughness: number;
    metalness: number;
    /** Image of one plank or tile, relative to the app's assets/ folder. */
    texture: string | null;
    /** Real size (mm) of one plank or tile: width across, height along the plank / up the wall. */
    tileWidth: number | null;
    tileHeight: number | null;
}

/** Horizontal band of tiling (or other finish) around a room's walls, mm above the floor. */
export interface WallFinish {
    materialId: number;
    bottom: number;
    top: number;
}

/** The land the building stands on (`PlotDto`): its boundary in plan mm and the ground inside it. */
export interface Plot {
    id: number;
    name: string;
    polygon: PlanPoint[];
    /** m². */
    area: number;
    /** Null: a lawn. */
    groundMaterialId: number | null;
    /** mm the ground lies above (+) or below (−) the floor's level; missing in older versions: 0. */
    groundLevel?: number;
}

export interface Room {
    id: number;
    code: string;
    name: string;
    type: RoomType;
    polygon: PlanPoint[];
    /** Net floor area, m². */
    area: number;
    /** Length of the outline, m. Missing in versions saved before it existed. */
    perimeter?: number;
    ceilingHeight: number | null;
    floorMaterialId: number | null;
    wallFinishes: WallFinish[];
    /** Plan point (mm) where tile joints cross; floor tiles, wall bands and tiled items share the grid. Null: plan origin. */
    tileOriginX: number | null;
    tileOriginY: number | null;
    /**
     * The floor's pattern turned about the tile origin, degrees counter-clockwise (0–179); 0 lays planks along plan y.
     * Only level faces turn, the wall bands keep their joints. Missing in versions saved before it existed.
     */
    floorAngle?: number;
    /**
     * mm the room's floor lies above (+) or below (−) the floor's level (a raised deck, a terrace a step down); its
     * ceiling, finishes and skirting go with it, items keep their own z. Missing in older versions: 0.
     */
    floorElevation?: number;
    /** Ceiling surface; null: white plaster (also when missing, in older versions). */
    ceilingMaterialId?: number | null;
    /**
     * Vaulted ceiling: shallow vaults `vaultRise` high between parallel beams `vaultSpacing` apart, running at
     * `vaultAngle` degrees (counter-clockwise from plan x). The crowns are at `ceilingHeight`; the beams, `beamWidth`
     * wide, hang `beamDrop` below the springing, in `beamMaterialId` (null: white paint). Null spacing: flat.
     */
    vaultSpacing?: number | null;
    vaultAngle?: number;
    vaultRise?: number;
    beamWidth?: number;
    beamDrop?: number;
    beamMaterialId?: number | null;
    /** Skirting boards along the room's walls (doors left free), mm high; null: none. */
    skirtingHeight?: number | null;
    skirtingMaterialId?: number | null;
}

/**
 * A door, passage or window in a wall. `side` is a side of the wall (walking from its start to end), `hinge` an
 * edge of the opening: a door's leaf hangs on `hinge` and swings into `side`; a double door's leaves swing into
 * `side` and `leafWidth` is the one on `hinge`; a sliding door hangs on the `side` face and a pocket door's leaf
 * goes into the wall, both sliding open towards `hinge`.
 */
export interface Opening {
    id: number;
    name: string;
    type: OpeningType;
    offset: number;
    width: number;
    height: number;
    sillHeight: number;
    leafWidth: number | null;
    hinge: 'Start' | 'End' | null;
    side: 'Left' | 'Right' | null;
    /** Main door of the floor: a walkthrough starts just inside it. */
    entrance: boolean;
    /**
     * Window down to the floor whose sashes open in the walkthrough, so it can be walked through. Missing in
     * snapshots saved before the flag existed.
     */
    walkable?: boolean;
    /** Missing in snapshots saved before doors had a frame style (then `Lining`) and handle. */
    frameStyle?: DoorFrameStyle;
    /** Null: the type's default, a lever (a pull on sliding and pocket doors). */
    handle?: DoorHandle | null;
    /**
     * Window: vertical glazing bars (1, two panes, when missing: older snapshots) and horizontal ones; a walkable
     * window has one sash without a mullion. `sillBoard`: how far the inner sill board reaches past the wall face
     * on `side` (the interior), mm; null: none.
     */
    mullions?: number;
    transoms?: number;
    sillBoard?: number | null;
    /** Door leaves: a plain slab, raised panels or glass panes (`leafPanels` of them, stacked). Missing in older snapshots: Flush. */
    leafStyle?: DoorLeafStyle;
    leafPanels?: number;
    /** Window: solid lower panel of the glazing, mm from the sill; null: glass down to the bottom rail. */
    panelHeight?: number | null;
    /**
     * Window: exterior shutters, two leaves on the outer face (opposite `side`), in `shutterMaterialId` (null: painted
     * timber), folded back against the wall unless `shuttersClosed`.
     */
    shutters?: ShutterStyle | null;
    shutterMaterialId?: number | null;
    shuttersClosed?: boolean;
    /** Niche: how deep it is cut into the `side` face (mm); null for other types. */
    depth?: number | null;
}

export interface Wall {
    id: number;
    name: string;
    type: WallType;
    startX: number;
    startY: number;
    endX: number;
    endY: number;
    /**
     * A curved wall: how far (mm) the middle of its centre line lies off the chord start → end, + to the left; an arc of at
     * most a semicircle. 0: straight. Missing in snapshots saved before walls could curve.
     */
    sagitta?: number;
    thickness: number;
    height: number;
    materialId: number | null;
    /** Finish bands on the face left of start → end, instead of the room's `wallFinishes`; null: the room's, []: none. */
    leftFinishes: WallFinish[] | null;
    /** The same on the face right of start → end. */
    rightFinishes: WallFinish[] | null;
    openings: Opening[];
}

export interface Item {
    id: number;
    roomId: number | null;
    /** The group (piece of furniture) the item is part of. */
    groupId: number | null;
    kind: ItemKind;
    /** The furniture definition of a `Custom` item. */
    definitionId: number | null;
    /** The 3D model of a `Model` item; missing in versions saved before models existed. */
    modelId?: number | null;
    name: string;
    x: number;
    y: number;
    z: number;
    width: number;
    depth: number;
    height: number;
    rotation: number;
    materialId: number | null;
    params: Record<string, unknown>;
}

/** Items forming one piece of furniture (they share its `groupId`); they move, copy and delete together. */
export interface Group {
    id: number;
    name: string;
    /** Set for a variant: only the set's active group is shown. */
    variantSetId: number | null;
}

/** Alternatives of one piece of furniture (e.g. with or without a kitchen island): groups of which one is shown. */
export interface VariantSet {
    id: number;
    name: string;
    /** The variant shown; null shows none. */
    activeGroupId: number | null;
}

export interface Scene {
    floor: Floor;
    /** Project revision the scene was read at (absent for saved versions); edits send it as `If-Match`. */
    revision?: number;
    materials: Material[];
    rooms: Room[];
    walls: Wall[];
    items: Item[];
    /** Absent in versions saved before groups existed. */
    groups?: Group[];
    /** Absent in versions saved before variant sets existed. */
    variantSets?: VariantSet[];
    /** The furniture definitions of the `Custom` items (also deleted ones still in use); absent in older versions. */
    definitions?: SceneDefinition[];
    /** The plan image under the top view, for tracing; absent in older versions. */
    underlay?: Underlay | null;
    /** The 3D models of the `Model` items; absent in older versions. */
    models?: SceneModel[];
    /** The project files the `Custom` items show as pictures; absent in older versions. */
    pictures?: number[];
    /** The land around the building; absent in older versions. */
    plots?: Plot[];
    /** Where the building stands, for the sun; absent in older versions. */
    location?: SiteLocation | null;
}

/** The project's orientation and place on the earth (`LocationDto`). */
export interface SiteLocation {
    /** Degrees clockwise from plan north (+y) to true north, 0–359. */
    northAngle: number;
    /** Degrees, north positive; null with `longitude` when the place is not set. */
    latitude: number | null;
    /** Degrees, east positive. */
    longitude: number | null;
    /** IANA name (e.g. Europe/Prague) the sun's clock time is read in; null: local solar time. */
    timeZone: string | null;
}

/** The scene says where its building stands (a latitude and longitude), so the sun can be placed at a time. */
export const hasSiteLocation = (scene: Scene | null): boolean =>
    scene?.location != null && scene.location.latitude !== null && scene.location.longitude !== null;

/** A moment to place the sun at: the 21st of `month` (1–12) at `hour` (0–24, fractional) clock time on site. */
export interface SunTime {
    month: number;
    hour: number;
}

/** A 3D model as the scene carries it: its native size (mm) to scale it by, and its credits. */
export interface SceneModel {
    id: number;
    name: string;
    width: number;
    depth: number;
    height: number;
    license: string;
    author: string;
    sourceUrl: string;
    /** Changes when the model is edited; the file itself never changes. */
    updatedAt: string;
}

/** How an underlay image lies in the plan: its top-left pixel at (x, y), `scale` mm per pixel, turned `rotation` degrees counter-clockwise. */
export interface UnderlayPlacement {
    x: number;
    y: number;
    scale: number;
    rotation: number;
}

/** A plan image (a project file) laid under a floor's top view. */
export interface Underlay extends UnderlayPlacement {
    fileId: number;
    filename: string;
    widthPx: number;
    heightPx: number;
    opacity: number;
}

/** A furniture definition as the scene carries it; `updatedAt` changes whenever it is edited. */
export interface SceneDefinition {
    id: number;
    name: string;
    definition: FurnitureDefinitionBody;
    updatedAt: string;
}
