import {Opening, PlanPoint, Wall} from '@app/models/scene';

/** Plan millimetres → scene metres. Plan y (north) maps to scene −z, so a plan seen from above is not mirrored. */
export const MM = 0.001;

export interface Vec2 {
    x: number;
    y: number;
}

/** Axis-aligned piece of a wall in wall-local coordinates: `from`/`to` along the centre line, `bottom`/`top` heights (mm). */
export interface WallPiece {
    from: number;
    to: number;
    bottom: number;
    top: number;
}

export interface WallFrame {
    start: Vec2;
    /** Unit vector start → end. */
    dir: Vec2;
    /** Unit normal to the left of `dir` (counter-clockwise). */
    normal: Vec2;
    length: number;
    /** Angle of `dir` from the plan x axis, radians counter-clockwise; equals the scene rotation about +y. */
    angle: number;
}

export function wallFrame(wall: Wall): WallFrame {
    const dx = wall.endX - wall.startX;
    const dy = wall.endY - wall.startY;
    const length = Math.hypot(dx, dy);
    const dir = {x: dx / length, y: dy / length};

    return {
        start: {x: wall.startX, y: wall.startY},
        dir,
        normal: {x: -dir.y, y: dir.x},
        length,
        angle: Math.atan2(dy, dx),
    };
}

/** Point at distance `along` the centre line and `across` to its left. */
export function pointOnWall(frame: WallFrame, along: number, across = 0): Vec2 {
    return {
        x: frame.start.x + frame.dir.x * along + frame.normal.x * across,
        y: frame.start.y + frame.dir.y * along + frame.normal.y * across,
    };
}

/**
 * Splits a wall into solid boxes around its openings: full-height piers between openings, plus a
 * parapet below and a lintel above every opening. Openings are clamped to the wall and may be unsorted.
 */
export function wallPieces(length: number, height: number, openings: readonly Opening[]): WallPiece[] {
    const pieces: WallPiece[] = [];
    const sorted = [...openings].sort((a, b) => a.offset - b.offset);

    let cursor = 0;
    for (const opening of sorted) {
        const from = Math.max(opening.offset, cursor);
        const to = Math.min(opening.offset + opening.width, length);
        if (to <= from) {
            continue;
        }

        if (from > cursor) {
            pieces.push({from: cursor, to: from, bottom: 0, top: height});
        }
        if (opening.sillHeight > 0) {
            pieces.push({from, to, bottom: 0, top: Math.min(opening.sillHeight, height)});
        }
        const head = opening.sillHeight + opening.height;
        if (head < height) {
            pieces.push({from, to, bottom: head, top: height});
        }
        cursor = to;
    }

    if (cursor < length) {
        pieces.push({from: cursor, to: length, bottom: 0, top: height});
    }

    return pieces;
}

/** Signed area by the shoelace formula, mm²; positive for counter-clockwise polygons. */
export function polygonSignedArea(polygon: readonly PlanPoint[]): number {
    let sum = 0;
    for (let i = 0; i < polygon.length; i++) {
        const [x1, y1] = polygon[i];
        const [x2, y2] = polygon[(i + 1) % polygon.length];
        sum += x1 * y2 - x2 * y1;
    }
    return sum / 2;
}

/** Area centroid of a simple polygon (falls back to the vertex mean for degenerate input). */
export function polygonCentroid(polygon: readonly PlanPoint[]): Vec2 {
    const area = polygonSignedArea(polygon);
    if (Math.abs(area) < 1e-6) {
        const n = polygon.length || 1;
        return {
            x: polygon.reduce((s, [x]) => s + x, 0) / n,
            y: polygon.reduce((s, [, y]) => s + y, 0) / n,
        };
    }

    let cx = 0;
    let cy = 0;
    for (let i = 0; i < polygon.length; i++) {
        const [x1, y1] = polygon[i];
        const [x2, y2] = polygon[(i + 1) % polygon.length];
        const cross = x1 * y2 - x2 * y1;
        cx += (x1 + x2) * cross;
        cy += (y1 + y2) * cross;
    }
    return {x: cx / (6 * area), y: cy / (6 * area)};
}

export interface Bounds {
    minX: number;
    minY: number;
    maxX: number;
    maxY: number;
}

export function polygonBounds(points: readonly PlanPoint[]): Bounds {
    return points.reduce(
        (b, [x, y]) => ({minX: Math.min(b.minX, x), minY: Math.min(b.minY, y), maxX: Math.max(b.maxX, x), maxY: Math.max(b.maxY, y)}),
        {minX: Infinity, minY: Infinity, maxX: -Infinity, maxY: -Infinity},
    );
}

export interface FrontSlot {
    type: string;
    bottom: number;
    height: number;
}

/**
 * Stacks fronts from the bottom up within `available` mm. Fronts with a fixed height keep it; the
 * remainder is shared evenly by the others. When the fixed heights do not fit they are scaled down.
 */
export function stackFronts(fronts: readonly {type: string; height?: number}[], available: number): FrontSlot[] {
    const fixed = fronts.reduce((sum, f) => sum + (f.height ?? 0), 0);
    const flexible = fronts.filter((f) => f.height === undefined).length;
    const scale = fixed > available ? available / fixed : 1;
    const flexHeight = flexible > 0 ? Math.max(available - fixed * scale, 0) / flexible : 0;

    let bottom = 0;
    return fronts.map((front) => {
        const height = front.height === undefined ? flexHeight : front.height * scale;
        const slot = {type: front.type, bottom, height};
        bottom += height;
        return slot;
    });
}
