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

/** The fields of a wall that define its footprint. */
export type WallLine = Pick<Wall, 'id' | 'startX' | 'startY' | 'endX' | 'endY' | 'thickness'>;

export function wallFrame(wall: Pick<Wall, 'startX' | 'startY' | 'endX' | 'endY'>): WallFrame {
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
/** Plan point (mm) where a room's tile joints cross; the plan origin when the room sets none. */
export function tileOrigin(room: Pick<Room, 'tileOriginX' | 'tileOriginY'> | undefined): Vec2 {
    return {x: room?.tileOriginX ?? 0, y: room?.tileOriginY ?? 0};
}

/**
 * Texture coordinates (metres) of a point on a tiled surface, on the grid whose joints cross at `origin`.
 * Point and origin are plan mm, `height` mm above the floor; the normal is in plan axes plus `up`. Floors
 * and other level faces take u, v from plan x, y. Upright faces take u along the face (to the right as seen
 * from the front) measured from the origin's projection, so joints continue the floor's around every
 * corner, and v from the floor, so rows start with a whole tile at the floor.
 */
export function tileUv(point: Vec2, height: number, normal: {x: number; y: number; up: number}, origin: Vec2): [number, number] {
    const dx = point.x - origin.x;
    const dy = point.y - origin.y;
    if (Math.abs(normal.up) > 0.5) {
        return [dx * MM, dy * MM];
    }
    const length = Math.hypot(normal.x, normal.y);
    return [((dx * -normal.y + dy * normal.x) / length) * MM, height * MM];
}

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

/**
 * Floor-level footprints of the walls; doors and walkable French windows leave gaps (their leaves are
 * `leafColliders`), other windows count as solid wall.
 */
export function wallColliders(walls: readonly Wall[]): WallCollider[] {
    const colliders: WallCollider[] = [];
    for (const wall of walls) {
        const frame = wallFrame(wall);
        if (!(frame.length > 0)) {
            continue;
        }
        const doors = wall.openings.filter(hasLeaves);
        for (const piece of wallPieces(frame.length, wall.height, doors)) {
            if (piece.bottom < WalkableStep) {
                colliders.push({frame, from: piece.from, to: piece.to, halfThickness: wall.thickness / 2});
            }
        }
    }
    return colliders;
}

/** Door frame on each side of a door leaf, mm; the leaf fills the rest of the opening unless `leafWidth` says otherwise. */
export const DoorFrameWidth = 50;
export const DoorLeafThickness = 40;
/** Frame bar around a French window; its sashes hinge on the inside of it (mm). */
export const WindowFrameBar = 70;
export const SashThickness = 60;
/** How far a door leaf or sash opens, radians. */
export const LeafOpenAngle = (80 * Math.PI) / 180;

/**
 * A swinging door leaf or French window sash, on its wall's centre-line frame (mm). At angle `a` (0 closed,
 * `LeafOpenAngle` open) it runs from the hinge along `closed · cos a` and across `swing · sin a`.
 */
export interface Leaf {
    openingId: number;
    /** Index of the sash (0, 1) of a French window; 0 for a door. */
    index: number;
    /** The hinge axis: mm along the wall from its start, mm across it (+ towards `normal`). */
    along: number;
    across: number;
    /** From the hinge to the free edge when closed: +1 towards the wall end, −1 towards its start. */
    closed: 1 | -1;
    /** Side it swings into: +1 the left of the wall direction (`normal`), −1 the right. */
    swing: 1 | -1;
    width: number;
    thickness: number;
}

/** Doors and walkable French windows: openings that open and can be walked through. */
export function hasLeaves(opening: Opening): boolean {
    return opening.type === 'Door' || opening.walkable === true;
}

/**
 * The leaves of an opening: a door's one leaf on its `hinge` edge, or a walkable French window's two sashes
 * hinged on its jambs; both swing into `side` (default left). Closed, a leaf lies flush with that wall face
 * (a sash inside the window frame). Other windows have none.
 */
export function openingLeaves(opening: Opening, wallThickness: number): Leaf[] {
    const swing: Leaf['swing'] = opening.side === 'Right' ? -1 : 1;
    if (opening.type === 'Door') {
        const width = opening.leafWidth ?? opening.width - 2 * DoorFrameWidth;
        const clearance = (opening.width - width) / 2;
        const atEnd = opening.hinge === 'End';
        return [{
            openingId: opening.id,
            index: 0,
            along: atEnd ? opening.offset + opening.width - clearance : opening.offset + clearance,
            across: swing * (wallThickness / 2 - DoorLeafThickness / 2),
            closed: atEnd ? -1 : 1,
            swing,
            width,
            thickness: DoorLeafThickness,
        }];
    }
    if (opening.walkable !== true) {
        return [];
    }
    const width = (opening.width - 2 * WindowFrameBar) / 2;
    const across = swing * (WindowFrameBar / 2 - SashThickness / 2);
    const sash = {openingId: opening.id, across, swing, width, thickness: SashThickness};
    return [
        {...sash, index: 0, along: opening.offset + WindowFrameBar, closed: 1},
        {...sash, index: 1, along: opening.offset + opening.width - WindowFrameBar, closed: -1},
    ];
}

/** Footprint of a leaf at `angle` (radians) as a thin wall from its hinge along the leaf. */
export function leafCollider(frame: WallFrame, leaf: Leaf, angle: number): WallCollider {
    const along = leaf.closed * Math.cos(angle);
    const across = leaf.swing * Math.sin(angle);
    const dir = {x: frame.dir.x * along + frame.normal.x * across, y: frame.dir.y * along + frame.normal.y * across};
    return {
        frame: {
            start: pointOnWall(frame, leaf.along, leaf.across),
            dir,
            normal: {x: -dir.y, y: dir.x},
            length: leaf.width,
            angle: Math.atan2(dir.y, dir.x),
        },
        from: 0,
        to: leaf.width,
        halfThickness: leaf.thickness / 2,
    };
}

/** Footprints of all door leaves and sashes; `openness` (0 closed … 1 open) of an opening sets their angle. */
export function leafColliders(walls: readonly Wall[], openness: (openingId: number) => number): WallCollider[] {
    return walls.flatMap((wall) => {
        const frame = wallFrame(wall);
        if (!(frame.length > 0)) {
            return [];
        }
        return wall.openings.flatMap((opening) =>
            openingLeaves(opening, wall.thickness).map((leaf) => leafCollider(frame, leaf, openness(opening.id) * LeafOpenAngle)),
        );
    });
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
    /** The entrance door it starts at, if any. */
    openingId?: number;
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

    return {
        position: inside.position,
        direction: {x: frame.normal.x * inside.sign, y: frame.normal.y * inside.sign},
        openingId: door.opening.id,
    };
}

/** Where a dragged point ended up and what it snapped to (for feedback). */
export interface SnapResult {
    point: Vec2;
    /**
     * `point`: onto one of the candidate points; `line`: onto one of the candidate lines; `axis`: x and/or y
     * aligned with an anchor; `angle`: a drawn segment turned to a multiple of 15° (`snapDrawPoint`); `grid`: grid only.
     */
    kind: 'point' | 'line' | 'axis' | 'angle' | 'grid';
}

export interface SnapOptions {
    /** Grid step, mm (≤ 1: whole mm). */
    grid: number;
    /** Snap distance, mm; 0 turns point and axis snapping off. */
    radius: number;
    /** Points to snap onto (other wall ends, room corners). */
    points?: readonly Vec2[];
    /** Points to align with horizontally or vertically (the other end of the wall, neighbouring corners). */
    anchors?: readonly Vec2[];
    /** Segments to snap onto, after the points (wall centre lines, for T-joints). */
    lines?: readonly (readonly [Vec2, Vec2])[];
}

/**
 * Snaps a dragged plan point: onto the nearest candidate point within `radius`; otherwise onto the nearest
 * point of a candidate line within `radius` (rounded to whole mm); otherwise each coordinate separately onto
 * the nearest anchor's x / y within `radius` (keeping edges orthogonal), else onto the grid.
 */
export function snapPoint(point: Vec2, options: SnapOptions): SnapResult {
    const {grid, radius} = options;
    if (radius > 0) {
        let best: Vec2 | null = null;
        let bestDistance = radius;
        for (const candidate of options.points ?? []) {
            const distance = Math.hypot(candidate.x - point.x, candidate.y - point.y);
            if (distance <= bestDistance) {
                best = candidate;
                bestDistance = distance;
            }
        }
        if (best !== null) {
            return {point: {x: best.x, y: best.y}, kind: 'point'};
        }
        const onLine = nearestOnLines(point, options.lines ?? [], radius);
        if (onLine !== null) {
            const aligned = alignOnLine(point, onLine.line, options.anchors ?? [], radius) ?? onLine.point;
            return {point: {x: Math.round(aligned.x), y: Math.round(aligned.y)}, kind: 'line'};
        }
    }

    const align = (value: number, coordinate: 'x' | 'y'): number | null => {
        let best: number | null = null;
        let bestDistance = radius;
        for (const anchor of options.anchors ?? []) {
            const distance = Math.abs(anchor[coordinate] - value);
            if (radius > 0 && distance <= bestDistance) {
                best = anchor[coordinate];
                bestDistance = distance;
            }
        }
        return best;
    };
    const x = align(point.x, 'x');
    const y = align(point.y, 'y');
    return {
        point: {x: x ?? snapToGrid(point.x, grid), y: y ?? snapToGrid(point.y, grid)},
        kind: x !== null || y !== null ? 'axis' : 'grid',
    };
}

/** The nearest point on any of the segments (and that segment), if one is within `radius`. */
function nearestOnLines(
    point: Vec2,
    lines: readonly (readonly [Vec2, Vec2])[],
    radius: number,
): {point: Vec2; line: readonly [Vec2, Vec2]} | null {
    let best: {point: Vec2; line: readonly [Vec2, Vec2]} | null = null;
    let bestDistance = radius;
    for (const line of lines) {
        const [a, b] = line;
        const dx = b.x - a.x;
        const dy = b.y - a.y;
        const lengthSquared = dx * dx + dy * dy;
        if (lengthSquared === 0) {
            continue;
        }
        const t = Math.min(Math.max(((point.x - a.x) * dx + (point.y - a.y) * dy) / lengthSquared, 0), 1);
        const candidate = {x: a.x + dx * t, y: a.y + dy * t};
        const distance = Math.hypot(candidate.x - point.x, candidate.y - point.y);
        if (distance <= bestDistance) {
            best = {point: candidate, line};
            bestDistance = distance;
        }
    }
    return best;
}

/**
 * The point of the segment in line with an anchor (same x or same y) nearest to `point`, if within
 * `radius`: a partition drawn onto a wall then stays square to the previous point.
 */
function alignOnLine(point: Vec2, [a, b]: readonly [Vec2, Vec2], anchors: readonly Vec2[], radius: number): Vec2 | null {
    let best: Vec2 | null = null;
    let bestDistance = radius;
    const consider = (t: number): void => {
        if (!Number.isFinite(t) || t < 0 || t > 1) {
            return;
        }
        const candidate = {x: a.x + (b.x - a.x) * t, y: a.y + (b.y - a.y) * t};
        const distance = Math.hypot(candidate.x - point.x, candidate.y - point.y);
        if (distance <= bestDistance) {
            best = candidate;
            bestDistance = distance;
        }
    };
    for (const anchor of anchors) {
        consider((anchor.x - a.x) / (b.x - a.x));
        consider((anchor.y - a.y) / (b.y - a.y));
    }
    return best;
}

/** Distance along the wall's centre line of the point's projection onto it, mm from the start. */
export function alongWall(frame: WallFrame, point: Vec2): number {
    return (point.x - frame.start.x) * frame.dir.x + (point.y - frame.start.y) * frame.dir.y;
}

/** Centre-line length in whole mm, rounded down like the backend does (openings that fit it fit the wall). */
export function wallLength(wall: Pick<Wall, 'startX' | 'startY' | 'endX' | 'endY'>): number {
    return Math.floor(Math.hypot(wall.endX - wall.startX, wall.endY - wall.startY));
}

/**
 * The wall with new end points and its openings' offsets as the backend derives them: a moved start shifts
 * the offsets so the openings keep their place on the plan. `fits` tells whether they all still fit
 * (otherwise the edit would be refused).
 */
export function moveWallEnds(wall: Wall, start: Vec2, end: Vec2): {wall: Wall; fits: boolean} {
    const moved = {...wall, startX: start.x, startY: start.y, endX: end.x, endY: end.y};
    const length = wallLength(moved);
    const oldLength = Math.hypot(wall.endX - wall.startX, wall.endY - wall.startY);
    const newLength = Math.hypot(end.x - start.x, end.y - start.y);
    const startMoved = start.x !== wall.startX || start.y !== wall.startY;

    const openings = wall.openings.map((opening) => {
        if (!startMoved || newLength === 0) {
            return opening;
        }
        const t = oldLength > 0 ? opening.offset / oldLength : 0;
        const x = wall.startX + (wall.endX - wall.startX) * t - start.x;
        const y = wall.startY + (wall.endY - wall.startY) * t - start.y;
        return {...opening, offset: Math.round((x * (end.x - start.x) + y * (end.y - start.y)) / newLength)};
    });
    const fits = length >= 100 && openings.every((o) => o.offset >= 0 && o.offset + o.width <= length);

    return {wall: {...moved, openings}, fits};
}

/** Where a door or window dragged along its wall goes: `offset` kept on the wall, `fits` false when it overlaps another opening. */
export function slideOpening(wall: Wall, openingId: number, offset: number): {offset: number; fits: boolean} {
    const opening = wall.openings.find((o) => o.id === openingId);
    if (opening === undefined) {
        return {offset, fits: false};
    }
    const clamped = Math.min(Math.max(Math.round(offset), 0), Math.max(wallLength(wall) - opening.width, 0));
    const fits =
        clamped + opening.width <= wallLength(wall) &&
        wall.openings.every((o) => o.id === openingId || clamped >= o.offset + o.width || o.offset >= clamped + opening.width);
    return {offset: clamped, fits};
}

/** The polygon with a new vertex after `edgeIndex` (on the edge from vertex `edgeIndex` to the next one). */
export function insertVertex(polygon: readonly PlanPoint[], edgeIndex: number, point: Vec2): PlanPoint[] {
    const result = [...polygon];
    result.splice(edgeIndex + 1, 0, [Math.round(point.x), Math.round(point.y)]);
    return result;
}

/** The polygon without vertex `index`; null when it would have fewer than three. */
export function removeVertex(polygon: readonly PlanPoint[], index: number): PlanPoint[] | null {
    return polygon.length <= 3 ? null : polygon.filter((_, i) => i !== index);
}

/** Midpoint of every edge (edge i runs from vertex i to i + 1): where new vertices are pulled out. */
export function edgeMidpoints(polygon: readonly PlanPoint[]): Vec2[] {
    return polygon.map(([ax, ay], i) => {
        const [bx, by] = polygon[(i + 1) % polygon.length];
        return {x: (ax + bx) / 2, y: (ay + by) / 2};
    });
}

/** Whether the backend would accept the outline: ≥ 3 points, no repeated neighbours, an area, no crossing edges. */
export function isSimplePolygon(polygon: readonly PlanPoint[]): boolean {
    const n = polygon.length;
    if (n < 3 || polygon.some(([x, y], i) => x === polygon[(i + 1) % n][0] && y === polygon[(i + 1) % n][1])) {
        return false;
    }
    if (polygonSignedArea(polygon) === 0) {
        return false;
    }
    for (let i = 0; i < n; i++) {
        for (let j = i + 2; j < n; j++) {
            if (i === 0 && j === n - 1) {
                continue;
            }
            if (segmentsIntersect(polygon[i], polygon[(i + 1) % n], polygon[j], polygon[(j + 1) % n])) {
                return false;
            }
        }
    }
    return true;
}

function segmentsIntersect(a: PlanPoint, b: PlanPoint, c: PlanPoint, d: PlanPoint): boolean {
    const orientation = (p: PlanPoint, q: PlanPoint, r: PlanPoint): number =>
        Math.sign((q[0] - p[0]) * (r[1] - p[1]) - (q[1] - p[1]) * (r[0] - p[0]));
    const onSegment = (p: PlanPoint, q: PlanPoint, r: PlanPoint): boolean =>
        r[0] >= Math.min(p[0], q[0]) && r[0] <= Math.max(p[0], q[0]) && r[1] >= Math.min(p[1], q[1]) && r[1] <= Math.max(p[1], q[1]);
    const d1 = orientation(c, d, a);
    const d2 = orientation(c, d, b);
    const d3 = orientation(a, b, c);
    const d4 = orientation(a, b, d);
    if (d1 * d2 < 0 && d3 * d4 < 0) {
        return true;
    }
    return (
        (d1 === 0 && onSegment(c, d, a)) ||
        (d2 === 0 && onSegment(c, d, b)) ||
        (d3 === 0 && onSegment(a, b, c)) ||
        (d4 === 0 && onSegment(a, b, d))
    );
}

/**
 * How far each face of a wall reaches past its end points (mm; negative = cut short), so that two walls
 * whose ends meet join in a mitred corner instead of overlapping with a notch. Left / right are as seen
 * along the wall's direction (left = `WallFrame.normal`).
 */
export interface WallEnds {
    startLeft: number;
    startRight: number;
    endLeft: number;
    endRight: number;
}

export const SquareEnds: WallEnds = {startLeft: 0, startRight: 0, endLeft: 0, endRight: 0};

/** End points closer than this (mm) count as one corner. */
const JointTolerance = 1;

/**
 * Mitres for walls whose end points meet in pairs: at such a corner each face runs on until it meets the
 * corresponding face of the other wall. Ends shared by three or more walls, straight continuations and
 * very sharp angles stay square. Walls without a joint are left out of the map.
 */
export function wallJoints(walls: readonly WallLine[]): Map<number, WallEnds> {
    interface End {
        wall: WallLine;
        atStart: boolean;
        point: Vec2;
    }
    const ends: End[] = walls.flatMap((wall) => [
        {wall, atStart: true, point: {x: wall.startX, y: wall.startY}},
        {wall, atStart: false, point: {x: wall.endX, y: wall.endY}},
    ]);
    const result = new Map<number, WallEnds>();

    for (const end of ends) {
        const others = ends.filter(
            (o) => o.wall.id !== end.wall.id && Math.hypot(o.point.x - end.point.x, o.point.y - end.point.y) <= JointTolerance,
        );
        if (others.length !== 1 || wallFrame(end.wall).length === 0 || wallFrame(others[0].wall).length === 0) {
            continue;
        }
        const other = others[0];
        // Unit vectors from the corner into each wall.
        const a = intoWall(end);
        const b = intoWall(other);
        const cross = a.x * b.y - a.y * b.x;
        const ha = end.wall.thickness / 2;
        const hb = other.wall.thickness / 2;
        const limit = 4 * Math.max(ha, hb);
        if (Math.abs(cross) < 1e-3) {
            continue;
        }
        // Distance from the corner along `a` where the face on the left of `a` meets the other wall's face
        // (the right face is its mirror image).
        const k = (hb + ha * (a.x * b.x + a.y * b.y)) / cross;
        if (Math.abs(k) > limit) {
            continue;
        }
        const faces = result.get(end.wall.id) ?? {...SquareEnds};
        if (end.atStart) {
            faces.startLeft = -k;
            faces.startRight = k;
        } else {
            // Seen from the end, the left of `a` is the wall's right.
            faces.endRight = -k;
            faces.endLeft = k;
        }
        result.set(end.wall.id, faces);
    }
    return result;

    function intoWall(end: End): Vec2 {
        const frame = wallFrame(end.wall);
        return end.atStart ? frame.dir : {x: -frame.dir.x, y: -frame.dir.y};
    }
}
