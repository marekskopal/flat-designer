/** Identical boards; `length` runs along the grain. Mirrors `CutListBoardDto`. */
export interface CutListBoard {
    materialId: number | null;
    material: string;
    thickness: number;
    length: number;
    width: number;
    count: number;
    /** Banded sides of the part's box: left, right, bottom, top, back, front. */
    edges: string[];
    parts: string[];
    items: string[];
}

/** Identical parts that are not boards (legs, rods, glass, LEDs). */
export interface CutListOther {
    type: string;
    materialId: number | null;
    material: string;
    w: number;
    h: number;
    d: number;
    count: number;
    parts: string[];
    items: string[];
}

export interface CutListMaterial {
    materialId: number | null;
    material: string;
    /** m², one face of every board. */
    area: number;
    /** m of edge banding. */
    edgeLength: number;
}

/** What a carpenter cuts for the Custom furniture of a project or floor (`CutListDto`). */
export interface CutList {
    boards: CutListBoard[];
    others: CutListOther[];
    materials: CutListMaterial[];
    skipped: {itemId: number; itemName: string; floorName: string; error: string}[];
}
