import {Roof} from '@app/models/scene';

import {Vec2} from './plan-geometry';

/**
 * The planes of a roof over its rectangle and the heights (mm above the floor's level) of its underside and top at a plan
 * point. Twin of `RoofGeometry` (PHP), kept identical by `backend/tests/fixtures/roof-geometry.json`.
 *
 * The roof's own frame: s along the ridge (`rotation` counter-clockwise from plan x), t across it towards the back, both
 * from the centre (x, y). The underside is `eaveHeight` along the eaves line (the rectangle's edges) and rises at `pitch`
 * inwards; past the eaves the planes go on down over the overhang. Every pitched roof's underside is the lowest of its
 * planes, so a point's height needs no lookup of the plane under it.
 */

/** The fields the geometry reads. */
export type RoofShapeFields = Pick<
    Roof,
    'shape' | 'x' | 'y' | 'rotation' | 'width' | 'depth' | 'eaveHeight' | 'pitch' | 'overhang' | 'vergeOverhang' | 'thickness'
>;

/** A plane in plan: its outline and the underside over it, `height` at the roof's centre plus `gradient` · (p − centre). */
export interface RoofPlane {
    polygon: Vec2[];
    /** mm of rise per mm in plan x and y. */
    gradient: Vec2;
    height: number;
}

/** A plane in the roof's frame: outline, the underside's rise per mm along s and t, and its height at the centre. */
export interface LocalRoofPlane {
    polygon: Vec2[];
    gs: number;
    gt: number;
    base: number;
}

/** mm, how far off an edge a point still counts as on it. */
const Epsilon = 1e-6;

const isPitched = (roof: RoofShapeFields): boolean => roof.shape !== 'Flat';

/** The rise of the planes per mm inwards; 0 for a flat roof. */
export function roofSlope(roof: RoofShapeFields): number {
    return isPitched(roof) ? Math.tan((roof.pitch * Math.PI) / 180) : 0;
}

/** Half the roof's size in its own frame, overhangs included: along the ridge (x) and across it (y). */
export function roofHalfExtent(roof: RoofShapeFields): Vec2 {
    const along = roof.shape === 'Gable' || roof.shape === 'Shed' ? roof.vergeOverhang : roof.overhang;
    return {x: roof.width / 2 + along, y: roof.depth / 2 + roof.overhang};
}

function axes(roof: RoofShapeFields): {cos: number; sin: number} {
    const angle = (roof.rotation * Math.PI) / 180;
    return {cos: Math.cos(angle), sin: Math.sin(angle)};
}

/** A plan point in the roof's frame: x is s, y is t. */
export function roofLocal(roof: RoofShapeFields, x: number, y: number): Vec2 {
    const {cos, sin} = axes(roof);
    const dx = x - roof.x;
    const dy = y - roof.y;
    return {x: dx * cos + dy * sin, y: -dx * sin + dy * cos};
}

/** A point of the roof's frame (s, t) in plan. */
export function roofToPlan(roof: RoofShapeFields, s: number, t: number): Vec2 {
    const {cos, sin} = axes(roof);
    return {x: roof.x + s * cos - t * sin, y: roof.y + s * sin + t * cos};
}

export function roofCovers(roof: RoofShapeFields, x: number, y: number): boolean {
    const local = roofLocal(roof, x, y);
    const half = roofHalfExtent(roof);
    return Math.abs(local.x) <= half.x + Epsilon && Math.abs(local.y) <= half.y + Epsilon;
}

/** How far a point of the roof's frame lies inside the eaves, along the slope of the plane over it (negative over the overhang). */
function inwards(roof: RoofShapeFields, s: number, t: number): number {
    const halfW = roof.width / 2;
    const halfD = roof.depth / 2;
    switch (roof.shape) {
        case 'Gable':
            return halfD - Math.abs(t);
        case 'Hip':
            return Math.min(halfD - Math.abs(t), halfW - Math.abs(s));
        case 'Shed':
            return t + halfD;
        case 'Flat':
            return 0;
    }
}

/** Height of the underside at a plan point; null where the roof does not reach. */
export function roofUndersideAt(roof: RoofShapeFields, x: number, y: number): number | null {
    if (!roofCovers(roof, x, y)) {
        return null;
    }
    const local = roofLocal(roof, x, y);
    return roof.eaveHeight + inwards(roof, local.x, local.y) * roofSlope(roof);
}

/** The thickness measured upright. */
export function roofVerticalThickness(roof: RoofShapeFields): number {
    return isPitched(roof) ? roof.thickness / Math.cos((roof.pitch * Math.PI) / 180) : roof.thickness;
}

/** Height of the top of the covering at a plan point (the thickness is square to the slope); null where the roof does not reach. */
export function roofTopAt(roof: RoofShapeFields, x: number, y: number): number | null {
    const underside = roofUndersideAt(roof, x, y);
    return underside === null ? null : underside + roofVerticalThickness(roof);
}

/**
 * The highest underside over a point of the roofs covering it (where roofs meet, the space reaches the higher); null
 * where none does.
 */
export function roofsUndersideAt(roofs: readonly RoofShapeFields[], x: number, y: number): number | null {
    let highest: number | null = null;
    for (const roof of roofs) {
        const height = roofUndersideAt(roof, x, y);
        if (height !== null && (highest === null || height > highest)) {
            highest = height;
        }
    }
    return highest;
}

/** Height of the underside at the ridge (a shed's top edge; a flat roof's eaves). */
export function roofRidgeHeight(roof: RoofShapeFields): number {
    const rise = {Gable: roof.depth / 2, Hip: Math.min(roof.width, roof.depth) / 2, Shed: roof.depth, Flat: 0}[roof.shape];
    return roof.eaveHeight + rise * roofSlope(roof);
}

/**
 * The index in `roofPlanes()` of the plane over a plan point; null where the roof does not reach. On a hip line the front
 * or back plane, on a gable's ridge the back one.
 */
export function roofPlaneAt(roof: RoofShapeFields, x: number, y: number): number | null {
    const local = roofLocal(roof, x, y);
    return roofLocalPlaneAt(roof, local.x, local.y);
}

/** `roofPlaneAt()` of a point in the roof's frame. */
export function roofLocalPlaneAt(roof: RoofShapeFields, s: number, t: number): number | null {
    const half = roofHalfExtent(roof);
    if (Math.abs(s) > half.x + Epsilon || Math.abs(t) > half.y + Epsilon) {
        return null;
    }
    switch (roof.shape) {
        case 'Gable':
            return t < 0 ? 0 : 1;
        case 'Hip':
            if (roof.depth / 2 - Math.abs(t) <= roof.width / 2 - Math.abs(s)) {
                return t < 0 ? 0 : 2;
            }
            return s > 0 ? 1 : 3;
        case 'Shed':
        case 'Flat':
            return 0;
    }
}

/**
 * The planes in plan, overhangs included: each outline counter-clockwise, starting at its eaves (a gable or shed: front
 * then back; a hip: front, right, back, left).
 */
export function roofPlanes(roof: RoofShapeFields): RoofPlane[] {
    const {cos, sin} = axes(roof);
    return roofLocalPlanes(roof).map((local) => ({
        polygon: local.polygon.map((p) => roofToPlan(roof, p.x, p.y)),
        gradient: {x: local.gs * cos - local.gt * sin, y: local.gs * sin + local.gt * cos},
        height: local.base,
    }));
}

/** The planes in the roof's own frame. */
export function roofLocalPlanes(roof: RoofShapeFields): LocalRoofPlane[] {
    const {x: a, y: b} = roofHalfExtent(roof);
    const k = roofSlope(roof);
    const e = roof.eaveHeight;
    const halfD = roof.depth / 2;
    switch (roof.shape) {
        case 'Gable':
            return [
                plane([[-a, -b], [a, -b], [a, 0], [-a, 0]], 0, k, e + halfD * k),
                plane([[a, b], [-a, b], [-a, 0], [a, 0]], 0, -k, e + halfD * k),
            ];
        case 'Hip':
            return hipPlanes(roof, a, b, k, e);
        case 'Shed':
            return [plane([[-a, -b], [a, -b], [a, b], [-a, b]], 0, k, e + halfD * k)];
        case 'Flat':
            return [plane([[-a, -b], [a, -b], [a, b], [-a, b]], 0, 0, e)];
    }
}

/**
 * A hip roof's four planes; the ridge runs along the longer side and shrinks to the apex of a pyramid over a square,
 * leaving the front and back (or the sides) triangles. The overhang is the same all round, so the hip lines of the
 * outline with it are those of the eaves line.
 */
function hipPlanes(roof: RoofShapeFields, a: number, b: number, k: number, e: number): LocalRoofPlane[] {
    const ks = Math.max(a - b, 0);
    const kt = Math.max(b - a, 0);
    const halfW = roof.width / 2;
    const halfD = roof.depth / 2;
    return [
        plane([[-a, -b], [a, -b], [ks, -kt], [-ks, -kt]], 0, k, e + halfD * k),
        plane([[a, -b], [a, b], [ks, kt], [ks, -kt]], -k, 0, e + halfW * k),
        plane([[a, b], [-a, b], [-ks, kt], [ks, kt]], 0, -k, e + halfD * k),
        plane([[-a, b], [-a, -b], [-ks, -kt], [-ks, kt]], k, 0, e + halfW * k),
    ];
}

/** A plane, its outline without repeated points (a ridge shrunk to an apex). */
function plane(points: [number, number][], gs: number, gt: number, base: number): LocalRoofPlane {
    const polygon: Vec2[] = [];
    for (const [x, y] of points) {
        const last = polygon[polygon.length - 1];
        if (last === undefined || Math.abs(last.x - x) > Epsilon || Math.abs(last.y - y) > Epsilon) {
            polygon.push({x, y});
        }
    }
    return {polygon, gs, gt, base};
}
