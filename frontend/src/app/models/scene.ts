import {FurnitureDefinitionBody} from './furniture';

// Mirrors the backend DTOs (FlatDesigner\Dto). All lengths are millimetres in plan coordinates:
// origin at the inner south-west corner of the floor, x east, y north.

export type MaterialPattern = 'Plain' | 'Wood' | 'Planks' | 'Tiles' | 'Glass' | 'Grass' | 'Gravel' | 'Terrazzo' | 'Brick' | 'Herringbone';
export type RoomType = 'Living' | 'Bedroom' | 'Bathroom' | 'Toilet' | 'Hallway' | 'Terrace' | 'Garden';
export type WallType = 'Exterior' | 'Interior' | 'Shaft';
export type OpeningType = 'Door' | 'DoubleDoor' | 'SlidingDoor' | 'PocketDoor' | 'Passage' | 'Window';
export type DoorFrameStyle = 'Lining' | 'Architrave' | 'Flush' | 'None';
export type DoorHandle = 'Lever' | 'Knob' | 'Pull' | 'None';
export type DoorLeafStyle = 'Flush' | 'Panelled' | 'Glazed';
export type ShutterStyle = 'Louvred' | 'Solid';
export type ItemKind =
    | 'BaseCabinet'
    | 'WallCabinet'
    | 'TallCabinet'
    | 'Worktop'
    | 'Backsplash'
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
    | 'Wardrobe'
    | 'Chair'
    | 'Table'
    /** Drawn from a furniture definition (`definitionId`, in the scene's `definitions`). */
    | 'Custom';

export type PlanPoint = [number, number];

export interface Floor {
    id: number;
    name: string;
    /** 0 = ground floor, negative below ground. */
    level: number;
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
}

export interface Wall {
    id: number;
    name: string;
    type: WallType;
    startX: number;
    startY: number;
    endX: number;
    endY: number;
    thickness: number;
    height: number;
    materialId: number | null;
    openings: Opening[];
}

export type FrontType = 'door' | 'drawer' | 'oven' | 'microwave';

export interface FrontSpec {
    type: FrontType;
    /** Front height in mm; fronts without a height share the space left over. */
    height?: number;
    /** Side-by-side leaves (double door). */
    count?: number;
    /** Overrides the cabinet's handle for this front (e.g. 'none' for the top door of a tall column). */
    handle?: CabinetHandle;
}

export type CabinetHandle = 'horizontal' | 'vertical' | 'none';

export interface CabinetParams {
    plinth?: number;
    handle?: CabinetHandle;
    /** Edge of a single door that carries a vertical handle, as seen from the front; 'right' by default. */
    handleSide?: 'left' | 'right';
    /** Fronts from the bottom up. */
    fronts?: FrontSpec[];
    /** Width (mm) of the front hidden behind the adjoining run in a corner, at the right or left end as seen from the front. */
    blindRight?: number;
    blindLeft?: number;
}

export interface WardrobeParams {
    /** Sliding leaves, 2–6; by default one per ~900 mm of width. */
    doors?: number;
    /** Doors faced with mirror, numbered from 1 at the left as seen from the front. */
    mirrorDoors?: number[];
    /** Material of the door frames and tracks; aluminium by default. */
    profileMaterialId?: number;
    /** Height (mm) under the doors; 60 by default. */
    plinth?: number;
}

export interface ChairParams {
    /** Top of the seat (mm above the floor); 460 by default. */
    seatHeight?: number;
    /** Width (mm) of the seat at the back; the item width is the front. As wide as the front by default. */
    backWidth?: number;
}

export interface Item {
    id: number;
    roomId: number | null;
    /** The group (piece of furniture) the item is part of. */
    groupId: number | null;
    kind: ItemKind;
    /** The furniture definition of a `Custom` item. */
    definitionId: number | null;
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
