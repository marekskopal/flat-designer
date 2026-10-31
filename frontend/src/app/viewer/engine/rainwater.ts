import {Downpipe, GutterProfile, Roof, RoofEdge} from '@app/models/scene';

import {Vec2} from './plan-geometry';
import {roofHalfExtent, roofLocal, RoofShapeFields, roofSlope, roofToPlan} from './roof-geometry';
import {FloorRoofs, topStretches} from './under-roof';

/**
 * A roof's rainwater goods (FD-91): its gutters run along the outer edge of the overhang at the eaves (a gable's front
 * and back, a shed's front, a hip's four sides), where the roof is the top (`topStretches()`: stopping where another roof
 * of the floor is higher, as the caps do), and its downpipes stand `DownpipeOffset` off the eaves line, the walls' outer
 * face. Without a list (null) the downpipes stand `CornerInset` in from the ends of the front and back eaves; a downpipe whose outlet
 * misses a gutter is left out. Heights are the underside at the overhang's edge. Twin of `RoofGutters` (PHP), kept
 * identical by `backend/tests/fixtures/roof-gutters.json`.
 */

/** mm from the eaves line (the walls' outer face) out to a downpipe's centre. */
export const DownpipeOffset = 100;

/** mm in from the eaves' corners, along the front and back, to a default downpipe. */
export const CornerInset = 150;

/** mm: a half-round gutter's radius, a box gutter's width and depth, and a downpipe's radius (drawn, not measured). */
export const GutterRadius = 70;
export const BoxGutterWidth = 120;
export const BoxGutterDepth = 90;
export const DownpipeRadius = 45;

/** mm out from the eaves' edge (the fascia) to a gutter's middle: it hangs in front of the fascia, 10 mm off it. */
export function gutterOffset(profile: Exclude<GutterProfile, 'None'>): number {
    return (profile === 'Box' ? BoxGutterWidth / 2 : GutterRadius) + 10;
}

export interface GutterRun {
    edge: RoofEdge;
    from: Vec2;
    to: Vec2;
    /** mm, the roof's underside at the overhang's edge. */
    height: number;
}

export interface PlacedDownpipe {
    edge: RoofEdge;
    /** In plan, by the wall. */
    at: Vec2;
    /** In plan, on the gutter's line. */
    outlet: Vec2;
    /** mm, the gutter's height (`GutterRun.height`). */
    top: number;
}

/** Whether a roof of the shape has eaves (and so a gutter) along the edge (`RoofEdgeEnum::isEavesOf()`). */
export function isEavesOf(edge: RoofEdge, shape: Roof['shape']): boolean {
    switch (shape) {
        case 'Gable':
            return edge === 'Front' || edge === 'Back';
        case 'Hip':
            return true;
        case 'Shed':
            return edge === 'Front';
        case 'Flat':
            return false;
    }
}

const alongWidth = (edge: RoofEdge): boolean => edge === 'Front' || edge === 'Back';

/** The gutters of the roof `roofs[index]`, among the floor's roofs. */
export function roofGutters(roofs: FloorRoofs, index: number): GutterRun[] {
    const roof = roofs[index];
    const rest = roofs.filter((_, i) => i !== index);
    const height = roof.eaveHeight - roof.overhang * roofSlope(roof);
    const runs: GutterRun[] = [];
    for (const [edge, [from, to]] of eaves(roof)) {
        const p = roofToPlan(roof, from.x, from.y);
        const q = roofToPlan(roof, to.x, to.y);
        for (const [a, b] of topStretches(p, q, roof, rest)) {
            runs.push({edge, from: lerp(p, q, a), to: lerp(p, q, b), height});
        }
    }
    return runs;
}

/** The roof's downpipes (`downpipes`, null at the corners) on its gutters (`roofGutters()`). */
export function roofDownpipes(
    roof: RoofShapeFields,
    downpipes: readonly Downpipe[] | null | undefined,
    runs: readonly GutterRun[],
): PlacedDownpipe[] {
    const halfW = roof.width / 2;
    const halfD = roof.depth / 2;
    const overhang = roofHalfExtent(roof);
    const result: PlacedDownpipe[] = [];
    for (const {edge, along} of downpipes ?? corners(roof)) {
        if (!isEavesOf(edge, roof.shape) || Math.abs(along) > (alongWidth(edge) ? halfW : halfD)) {
            continue;
        }
        const [wall, outlet]: [[number, number], [number, number]] = {
            Front: [[along, -halfD - DownpipeOffset], [along, -overhang.y]],
            Back: [[along, halfD + DownpipeOffset], [along, overhang.y]],
            Left: [[-halfW - DownpipeOffset, along], [-overhang.x, along]],
            Right: [[halfW + DownpipeOffset, along], [overhang.x, along]],
        }[edge] as [[number, number], [number, number]];
        const outletPlan = roofToPlan(roof, outlet[0], outlet[1]);
        const run = runAt(runs, edge, outletPlan);
        if (run !== null) {
            result.push({edge, at: roofToPlan(roof, wall[0], wall[1]), outlet: outletPlan, top: run.height});
        }
    }
    return result;
}

/** The eaves edges of a roof's shape on the overhang's edge, in its frame: front, right, back, left. */
function eaves(roof: RoofShapeFields): [RoofEdge, [Vec2, Vec2]][] {
    const {x: a, y: b} = roofHalfExtent(roof);
    const edges: [RoofEdge, [Vec2, Vec2]][] = [
        ['Front', [{x: -a, y: -b}, {x: a, y: -b}]],
        ['Right', [{x: a, y: -b}, {x: a, y: b}]],
        ['Back', [{x: a, y: b}, {x: -a, y: b}]],
        ['Left', [{x: -a, y: b}, {x: -a, y: -b}]],
    ];
    return edges.filter(([edge]) => isEavesOf(edge, roof.shape));
}

function corners(roof: RoofShapeFields): Downpipe[] {
    if (roof.shape === 'Flat') {
        return [];
    }
    const along = Math.max(0, Math.trunc(roof.width / 2) - CornerInset);
    const edges: RoofEdge[] = roof.shape === 'Shed' ? ['Front'] : ['Front', 'Back'];
    return edges.flatMap((edge) => [
        {edge, along: -along},
        {edge, along},
    ]);
}

/** The run of an edge an outlet lies on, within a tenth of a mm. */
function runAt(runs: readonly GutterRun[], edge: RoofEdge, point: Vec2): GutterRun | null {
    for (const run of runs) {
        const length = Math.hypot(run.to.x - run.from.x, run.to.y - run.from.y);
        const along = Math.hypot(point.x - run.from.x, point.y - run.from.y) + Math.hypot(run.to.x - point.x, run.to.y - point.y);
        if (run.edge === edge && along - length < 0.1) {
            return run;
        }
    }
    return null;
}

const lerp = (a: Vec2, b: Vec2, t: number): Vec2 => ({x: a.x + (b.x - a.x) * t, y: a.y + (b.y - a.y) * t});

/** A plan point in a roof's frame; re-exported for the builders. */
export const localOf = (roof: RoofShapeFields, p: Vec2): Vec2 => roofLocal(roof, p.x, p.y);
