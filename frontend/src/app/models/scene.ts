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
    | 'Fence';

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
}

export interface CabinetParams {
    plinth?: number;
    handle?: 'horizontal' | 'vertical' | 'none';
    /** Fronts from the bottom up. */
    fronts?: FrontSpec[];
    /** Width (mm) of the front hidden behind the adjoining run in a corner. */
    blindRight?: number;
}

export interface Item {
    id: number;
    roomId: number | null;
    kind: ItemKind;
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

export interface Scene {
    floor: Floor;
    /** Project revision the scene was read at (absent for saved versions); edits send it as `If-Match`. */
    revision?: number;
    materials: Material[];
    rooms: Room[];
    walls: Wall[];
    items: Item[];
}
