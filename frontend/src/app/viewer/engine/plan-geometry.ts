import {Opening, PlanPoint, Room, Wall} from '@app/models/scene';

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

/** Interval along a room edge (mm from its start) blocked by an opening, with its vertical extent. */
export interface WallGap {
    from: number;
    to: number;
    bottom: number;
    top: number;
}

/**
 * Openings of walls whose face lies on the edge a → b: the wall runs parallel to the edge and its
 * centre line is half a thickness (± tolerance) away. Returns their extents projected onto the edge.
 */
export function wallGapsAlongEdge(a: Vec2, b: Vec2, walls: readonly Wall[], tolerance = 30): WallGap[] {
    const length = Math.hypot(b.x - a.x, b.y - a.y);
    if (length === 0) {
        return [];
    }
    const dir = {x: (b.x - a.x) / length, y: (b.y - a.y) / length};
    const gaps: WallGap[] = [];

    for (const wall of walls) {
        const frame = wallFrame(wall);
        const parallel = Math.abs(dir.x * frame.dir.y - dir.y * frame.dir.x) < 1e-3;
        const distance = Math.abs((a.x - frame.start.x) * frame.normal.x + (a.y - frame.start.y) * frame.normal.y);
        if (!parallel || Math.abs(distance - wall.thickness / 2) > tolerance) {
            continue;
        }

        for (const opening of wall.openings) {
            const p0 = pointOnWall(frame, opening.offset);
            const p1 = pointOnWall(frame, opening.offset + opening.width);
            const t0 = (p0.x - a.x) * dir.x + (p0.y - a.y) * dir.y;
            const t1 = (p1.x - a.x) * dir.x + (p1.y - a.y) * dir.y;
            const from = Math.max(Math.min(t0, t1), 0);
            const to = Math.min(Math.max(t0, t1), length);
            if (to > from) {
                gaps.push({from, to, bottom: opening.sillHeight, top: opening.sillHeight + opening.height});
            }
        }
    }

    return gaps;
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

/** Rounds a length in mm to the nearest multiple of `step` (the editor's snap grid). */
export function snapToGrid(value: number, step: number): number {
    return step <= 1 ? Math.round(value) : Math.round(value / step) * step;
}

/** Even-odd test whether a point lies inside a polygon. */
export function pointInPolygon(point: Vec2, polygon: readonly PlanPoint[]): boolean {
    let inside = false;
    for (let i = 0, j = polygon.length - 1; i < polygon.length; j = i++) {
        const [xi, yi] = polygon[i];
        const [xj, yj] = polygon[j];
        if (yi > point.y !== yj > point.y && point.x < ((xj - xi) * (point.y - yi)) / (yj - yi) + xi) {
            inside = !inside;
        }
    }
    return inside;
}

/** Wall pieces starting lower than this (mm) block walking; door lintels do not. */
const WalkableStep = 300;

/** Solid footprint of a wall piece at floor level: the rectangle `from`..`to` along the centre line, ± half the thickness across. */
export interface WallCollider {
    frame: WallFrame;
    from: number;
    to: number;
    halfThickness: number;
}

/** Floor-level footprints of the walls; doors leave gaps, windows count as solid wall. */
export function wallColliders(walls: readonly Wall[]): WallCollider[] {
    const colliders: WallCollider[] = [];
    for (const wall of walls) {
        const frame = wallFrame(wall);
        if (!(frame.length > 0)) {
            continue;
        }
        const doors = wall.openings.filter((o) => o.type === 'Door');
        for (const piece of wallPieces(frame.length, wall.height, doors)) {
            if (piece.bottom < WalkableStep) {
                colliders.push({frame, from: piece.from, to: piece.to, halfThickness: wall.thickness / 2});
            }
        }
    }
    return colliders;
}

/**
 * Pushes a circle (centre `point`, plan mm) out of every collider it overlaps. Applied after each step of a
 * move, the push along the wall normal makes a walker slide along walls instead of stopping at them.
 */
export function collideCircle(point: Vec2, radius: number, colliders: readonly WallCollider[]): Vec2 {
    let {x, y} = point;
    for (let pass = 0; pass < 4; pass++) {
        let pushed = false;
        for (const {frame, from, to, halfThickness} of colliders) {
            const dx = x - frame.start.x;
            const dy = y - frame.start.y;
            let along = dx * frame.dir.x + dy * frame.dir.y;
            let across = dx * frame.normal.x + dy * frame.normal.y;
            const offAlong = along - Math.min(Math.max(along, from), to);
            const offAcross = across - Math.min(Math.max(across, -halfThickness), halfThickness);
            const distance = Math.hypot(offAlong, offAcross);
            if (distance >= radius) {
                continue;
            }
            if (distance > 1e-6) {
                const scale = (radius - distance) / distance;
                along += offAlong * scale;
                across += offAcross * scale;
            } else {
                // Centre inside the wall: leave through the nearest face.
                const exits = [from - radius - along, to + radius - along, -halfThickness - radius - across, halfThickness + radius - across];
                const nearest = exits.reduce((best, e, i) => (Math.abs(e) < Math.abs(exits[best]) ? i : best), 0);
                if (nearest < 2) {
                    along += exits[nearest];
                } else {
                    across += exits[nearest];
                }
            }
            const p = pointOnWall(frame, along, across);
            x = p.x;
            y = p.y;
            pushed = true;
        }
        if (!pushed) {
            break;
        }
    }
    return {x, y};
}

/** Where a walk starts and the way it faces (unit vector), plan mm. */
export interface WalkStart {
    position: Vec2;
    direction: Vec2;
}

/** How far inside the entrance a walk starts, measured from the wall face (mm). */
const EntranceStep = 700;

/**
 * A step inside the main door, facing into the floor: the door flagged `entrance`, otherwise the first
 * door in an exterior wall. Inside is the side lying in an indoor room (not a terrace or garden).
 * Null when there is no such door.
 */
export function entranceWalkStart(walls: readonly Wall[], rooms: readonly Room[]): WalkStart | null {
    const doors = walls.flatMap((wall) => wall.openings.filter((o) => o.type === 'Door').map((opening) => ({wall, opening})));
    const door = doors.find((d) => d.opening.entrance) ?? doors.find((d) => d.wall.type === 'Exterior');
    if (door === undefined) {
        return null;
    }

    const frame = wallFrame(door.wall);
    const along = door.opening.offset + door.opening.width / 2;
    const across = door.wall.thickness / 2 + EntranceStep;
    const indoor = rooms.filter((r) => r.type !== 'Terrace' && r.type !== 'Garden');
    const sides = [1, -1].map((sign) => ({sign, position: pointOnWall(frame, along, sign * across)}));
    const inside =
        sides.find((s) => indoor.some((r) => pointInPolygon(s.position, r.polygon))) ??
        sides.find((s) => rooms.some((r) => pointInPolygon(s.position, r.polygon))) ??
        sides[0];

    return {position: inside.position, direction: {x: frame.normal.x * inside.sign, y: frame.normal.y * inside.sign}};
}
