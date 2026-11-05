import {EditResult} from './editor';
import {OpeningType} from './scene';

/** A layer of a DXF plan: what is drawn on it and the role its name suggests. */
export interface DxfLayer {
    name: string;
    hidden: boolean;
    lines: number;
    arcs: number;
    inserts: number;
    hatches: number;
    /** Entities the import does not read: texts, dimensions, splines… */
    other: number;
    suggestedRole: 'wall' | 'opening' | null;
}

/** A DXF plan's layers, and its units (`$INSUNITS`; null when it does not say). */
export interface DxfSummary {
    units: number | null;
    layers: DxfLayer[];
}

/** Drawing units the import offers, as `$INSUNITS` codes. */
export const DxfUnits: readonly {code: number; label: string}[] = [
    {code: 4, label: 'mm'},
    {code: 5, label: 'cm'},
    {code: 14, label: 'dm'},
    {code: 6, label: 'm'},
    {code: 1, label: 'inch'},
    {code: 2, label: 'foot'},
];

/** How to read a DXF and lay it on the floor (the preview's body). */
export interface DxfPlanInput {
    fileId: number;
    wallLayers: string[];
    openingLayers: string[];
    /** null: the drawing's own. */
    units: number | null;
    /** Degrees counter-clockwise about the drawing's origin. */
    rotation: number;
    /** `Walls`: the walls' south-west corner at the plan origin; `Drawing`: the drawing's origin there. Then the offset. */
    anchor: 'Walls' | 'Drawing';
    offsetX: number;
    offsetY: number;
}

/** What a plan does not say (null: the editor's defaults), and whether the floor's walls make way. */
export interface DxfImportInput extends DxfPlanInput {
    wallHeight: number | null;
    doorHeight: number | null;
    windowSill: number | null;
    windowHeight: number | null;
    replaceExisting: boolean;
}

export interface DxfPlanWall {
    startX: number;
    startY: number;
    endX: number;
    endY: number;
    sagitta: number;
    thickness: number;
}

export interface DxfPlanOpening {
    /** Index into the plan's walls. */
    wall: number;
    type: OpeningType;
    /** mm from the wall's start. */
    offset: number;
    width: number;
    leafWidth: number | null;
    hinge: 'Start' | 'End' | null;
    side: 'Left' | 'Right' | null;
}

/** The walls and openings a DXF makes on the plan, before anything is written. */
export interface DxfPlan {
    units: number;
    walls: DxfPlanWall[];
    openings: DxfPlanOpening[];
    /** Wall-layer lines that made no wall: [x1, y1, x2, y2] in plan mm. */
    unpaired: [number, number, number, number][];
    warnings: string[];
}

export interface DxfImportResult {
    result: EditResult;
    walls: number;
    openings: number;
    warnings: string[];
}
