/** What a quantity line covers. Mirrors `QuantitySurfaceEnum`. */
export type QuantitySurface =
    | 'Floor'
    | 'WallFinish'
    | 'Wall'
    | 'Ceiling'
    | 'Beams'
    | 'Skirting'
    | 'Furniture'
    | 'RoofCovering'
    | 'Soffit'
    | 'Facade';

/** One material on one surface of a room (`QuantityLineDto`); `materialId` null is the surface's default look. */
export interface QuantityLine {
    surface: QuantitySurface;
    materialId: number | null;
    material: string;
    /** m²; null for skirting. */
    area: number | null;
    /** Running m, skirting only. */
    length: number | null;
    /** Whole tiles or planks, waste included. */
    pieces: number | null;
    /** Null without a price. */
    cost: number | null;
}

export interface QuantityRoom {
    id: number;
    code: string;
    name: string;
    floorId: number;
    floorName: string;
    lines: QuantityLine[];
    /** The priced lines only. */
    cost: number;
    complete: boolean;
}

/** A roof's covering and soffit, along the slope without its windows, and how many windows it has (`QuantityRoofDto`). */
export interface QuantityRoof {
    id: number;
    name: string;
    floorId: number;
    floorName: string;
    lines: QuantityLine[];
    windows: number;
    /** The priced lines only. */
    cost: number;
    complete: boolean;
}

/** A floor's façades: the wall faces outside every room per material, without the openings (`QuantityFacadeDto`, FD-88). */
export interface QuantityFacade {
    floorId: number;
    floorName: string;
    lines: QuantityLine[];
    /** The priced lines only. */
    cost: number;
    complete: boolean;
}

/** What to buy of one material (`QuantityMaterialDto`); without a material, `surface` says whose default look it is. */
export interface QuantityMaterial {
    materialId: number | null;
    surface: QuantitySurface | null;
    material: string;
    surfaces: QuantitySurface[];
    /** m². */
    area: number | null;
    /** Running m: skirting, or edge banding of furniture boards. */
    length: number | null;
    pieces: number | null;
    /** m² of the whole pieces. */
    orderedArea: number | null;
    pricePerArea: number | null;
    pricePerLength: number | null;
    cost: number | null;
}

/** Quantities and cost estimate of a project or floor (`QuantitiesDto`). */
export interface Quantities {
    currency: string;
    wastePercent: number;
    rooms: QuantityRoom[];
    materials: QuantityMaterial[];
    furniture: QuantityMaterial[];
    roofs: QuantityRoof[];
    /** One per floor with façades; missing from older servers. */
    facades?: QuantityFacade[];
    total: number;
    /** False when something has no price. */
    complete: boolean;
}

/** A project's price of a material, or of a surface's default look (`MaterialPriceDto`). */
export interface MaterialPrice {
    materialId: number | null;
    surface: QuantitySurface | null;
    pricePerArea: number | null;
    pricePerLength: number | null;
}

/** A project's price list (`MaterialPricesDto`). */
export interface MaterialPrices {
    currency: string;
    wastePercent: number;
    prices: MaterialPrice[];
}

export const SurfaceLabels: Record<QuantitySurface, string> = {
    Floor: 'Floor',
    WallFinish: 'Wall finish',
    Wall: 'Walls',
    Ceiling: 'Ceiling',
    Beams: 'Beams',
    Skirting: 'Skirting',
    Furniture: 'Furniture boards',
    RoofCovering: 'Roof covering',
    Soffit: 'Soffit',
    Facade: 'Façade',
};
