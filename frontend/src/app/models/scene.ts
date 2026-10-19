import {FurnitureDefinitionBody} from './furniture';

// Mirrors the backend DTOs (FlatDesigner\Dto). All lengths are millimetres in plan coordinates:
// origin at the inner south-west corner of the floor, x east, y north.

export type MaterialPattern = 'Plain' | 'Wood' | 'Planks' | 'Tiles' | 'Glass' | 'Grass' | 'Gravel';
export type RoomType = 'Living' | 'Bedroom' | 'Bathroom' | 'Toilet' | 'Hallway' | 'Terrace' | 'Garden';
export type WallType = 'Exterior' | 'Interior' | 'Shaft';
export type OpeningType = 'Door' | 'Window';
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
    area: number;
    ceilingHeight: number | null;
    floorMaterialId: number | null;
    wallFinishes: WallFinish[];
    /** Plan point (mm) where tile joints cross; floor tiles, wall bands and tiled items share the grid. Null: plan origin. */
    tileOriginX: number | null;
    tileOriginY: number | null;
}

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
}

/** A furniture definition as the scene carries it; `updatedAt` changes whenever it is edited. */
export interface SceneDefinition {
    id: number;
    name: string;
    definition: FurnitureDefinitionBody;
    updatedAt: string;
}
