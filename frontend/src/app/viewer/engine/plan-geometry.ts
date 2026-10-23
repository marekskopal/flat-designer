import {Item, Opening, PlanPoint, Room, Wall} from '@app/models/scene';

/** Plan millimetres → scene metres. Plan y (north) maps to scene −z, so a plan seen from above is not mirrored. */
export const MM = 0.001;

export interface Vec2 {
    x: number;
    y: number;
}

/**
 * Axis-aligned piece of a wall in wall-local coordinates: `from`/`to` along the centre line, `bottom`/`top` heights (mm).
 * Behind a niche (`openingId`), `recess` takes its depth off the face it is cut into.
 */
export interface WallPiece {
    from: number;
    to: number;
    bottom: number;
    top: number;
    recess?: {openingId: number; side: 'Left' | 'Right'; depth: number};
}

/**
 * The circle of a curved wall's centre line: it turns through `sweep` (radians, counter-clockwise positive) from
 * `startAngle` (of the start point about `centre`). `segments` straight pieces stand in for it in footprints and meshes.
 */
export interface WallArc {
    centre: Vec2;
    radius: number;
    startAngle: number;
    sweep: number;
    segments: number;
}

export interface WallFrame {
    start: Vec2;
    /** Unit vector start → end (of the chord, for a curved wall). */
    dir: Vec2;
    /** Unit normal to the left of `dir` (counter-clockwise). */
    normal: Vec2;
    /** Length of the centre line, along the arc for a curved wall. */
    length: number;
    /** Angle of `dir` from the plan x axis, radians counter-clockwise; equals the scene rotation about +y. */
    angle: number;
    /** Set for a curved wall: `pointOnWall()`, `tangentAt()` and `alongWall()` follow it. */
    arc?: WallArc;
}

/** The fields of a wall that define its centre line; `sagitta` is missing in older snapshots (straight). */
export type WallPath = Pick<Wall, 'startX' | 'startY' | 'endX' | 'endY'> & {sagitta?: number};

/** The fields of a wall that define its footprint. */
export type WallLine = Pick<Wall, 'id' | 'startX' | 'startY' | 'endX' | 'endY' | 'thickness' | 'sagitta'>;

/** How far (mm) a straight piece standing in for an arc may be off it, about (the PHP `WallLine::ArcSag`). */
const ArcSag = 3;
const MaxArcSegments = 64;

/**
 * How many straight pieces stand in for an arc of this sagitta: each is off the arc by about `ArcSag` at most, a
 * curved wall has at least two. Twin of `WallLine::segments()`.
 */
export function arcSegments(sagitta: number): number {
    return Math.min(MaxArcSegments, Math.max(2, Math.ceil(Math.sqrt(Math.abs(sagitta) / ArcSag))));
}

/**
 * The centre line of a wall: straight, or for a `sagitta` (mm from the chord's midpoint to the arc, + to the left of
 * start → end) an arc of at most a semicircle. Twin of `WallLine` in the backend.
 */
export function wallFrame(wall: WallPath): WallFrame {
    const dx = wall.endX - wall.startX;
    const dy = wall.endY - wall.startY;
    const chord = Math.hypot(dx, dy);
    const dir = {x: dx / chord, y: dy / chord};
    const normal = {x: -dir.y, y: dir.x};
    const frame: WallFrame = {start: {x: wall.startX, y: wall.startY}, dir, normal, length: chord, angle: Math.atan2(dy, dx)};
    const sagitta = wall.sagitta ?? 0;
    if (sagitta === 0 || !(chord > 0)) {
        return frame;
    }

    const radius = ((chord * chord) / 4 + sagitta * sagitta) / (2 * Math.abs(sagitta));
    // The centre lies on the chord's bisector, on the side away from the bulge.
    const offset = sagitta - Math.sign(sagitta) * radius;
    const centre = {x: (wall.startX + wall.endX) / 2 + normal.x * offset, y: (wall.startY + wall.endY) / 2 + normal.y * offset};
    const half = Math.asin(Math.min(chord / (2 * radius), 1));
    return {
        ...frame,
        length: 2 * half * radius,
        arc: {
            centre,
            radius,
            startAngle: Math.atan2(wall.startY - centre.y, wall.startX - centre.x),
            // Bulging to the left, the wall turns clockwise.
            sweep: -Math.sign(sagitta) * 2 * half,
            segments: arcSegments(sagitta),
        },
    };
}

/**
 * Point at distance `along` the centre line and `across` to its left. On a curved wall `across` is radial, and an
 * `along` beyond the ends runs on along the circle.
 */
export function pointOnWall(frame: WallFrame, along: number, across = 0): Vec2 {
    const arc = frame.arc;
    if (arc === undefined) {
        return {
            x: frame.start.x + frame.dir.x * along + frame.normal.x * across,
            y: frame.start.y + frame.dir.y * along + frame.normal.y * across,
        };
    }
    const angle = arc.startAngle + (arc.sweep * along) / frame.length;
    // The left of a clockwise arc is outwards.
    const distance = arc.radius - Math.sign(arc.sweep) * across;
    return {x: arc.centre.x + Math.cos(angle) * distance, y: arc.centre.y + Math.sin(angle) * distance};
}

/** Unit vector along the centre line (towards the end) at `along`. */
export function tangentAt(frame: WallFrame, along: number): Vec2 {
    const arc = frame.arc;
    if (arc === undefined) {
        return frame.dir;
    }
    const angle = arc.startAngle + (arc.sweep * along) / frame.length;
    const sign = Math.sign(arc.sweep);
    return {x: -Math.sin(angle) * sign, y: Math.cos(angle) * sign};
}

/**
 * The straight frame touching the centre line at `along`: the same `along` / `across` coordinates near that point. A
 * door or window of a curved wall is built straight on it, at its middle. A straight wall's own frame.
 */
export function tangentFrame(frame: WallFrame, along: number): WallFrame {
    if (frame.arc === undefined) {
        return frame;
    }
    const dir = tangentAt(frame, along);
    const at = pointOnWall(frame, along);
    return {
        start: {x: at.x - dir.x * along, y: at.y - dir.y * along},
        dir,
        normal: {x: -dir.y, y: dir.x},
        length: frame.length,
        angle: Math.atan2(dir.y, dir.x),
    };
}

/** The frame an opening is built on: its wall's, or on a curved wall the tangent at the opening's middle. */
export function openingFrame(frame: WallFrame, opening: Pick<Opening, 'offset' | 'width'>): WallFrame {
    return tangentFrame(frame, opening.offset + opening.width / 2);
}

/**
 * Where along the centre line `along` the samples of a face or footprint lie between `from` and `to`: the ends and,
 * on a curved wall, the joints of its `segments` pieces in between (the same for every face, so pieces line up).
 */
export function wallSamples(frame: WallFrame, from: number, to: number): number[] {
    const samples = [from];
    const segments = frame.arc?.segments ?? 1;
    for (let i = 1; i < segments; i++) {
        const along = (frame.length * i) / segments;
        if (along > from && along < to) {
            samples.push(along);
        }
    }
    samples.push(to);
    return samples;
}

/**
 * Footprint of a stretch of wall `from`..`to` along the centre line: its right face start → end, then its left face
 * back. `ends` mitres it where it reaches the wall's ends; a recess takes `depth` off one face (`WallPiece.recess`).
 * Four corners for a straight wall.
 */
export function wallOutline(
    frame: WallFrame,
    thickness: number,
    from: number,
    to: number,
    ends: WallEnds = SquareEnds,
    recess?: WallPiece['recess'],
): Vec2[] {
    const h = thickness / 2;
    const left = h - (recess?.side === 'Left' ? recess.depth : 0);
    const right = -h + (recess?.side === 'Right' ? recess.depth : 0);
    // A mitre's reach at the given distance across, between the two faces' (as a cut across the wall).
    const mitre = (atLeft: number, atRight: number, across: number): number => atRight + ((atLeft - atRight) * (across + h)) / thickness;
    const face = (across: number): Vec2[] => {
        const samples = wallSamples(frame, from, to);
        if (from <= 0) {
            samples[0] = -mitre(ends.startLeft, ends.startRight, across);
        }
        if (to >= frame.length) {
            samples[samples.length - 1] = frame.length + mitre(ends.endLeft, ends.endRight, across);
        }
        return samples.map((along) => pointOnWall(frame, along, across));
    };
    return [...face(right), ...face(left).reverse()];
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
        if (opening.type === 'Niche' && opening.depth != null) {
            const recess = {openingId: opening.id, side: opening.side ?? 'Left', depth: opening.depth};
            pieces.push({from, to, bottom: opening.sillHeight, top: Math.min(head, height), recess});
        }
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
    /** A niche: a recess into this face that stays in the plan (no pier, no dimension chain). */
    niche: boolean;
}

/**
 * How a wall lies along the room edge a → b: the side of it whose face is on the edge and, for a curved wall, where the
 * edge's ends lie along its centre line (mm, unsorted). Null when neither face is.
 */
export interface EdgeWall {
    frame: WallFrame;
    side: 'Left' | 'Right';
    along?: [number, number];
}

/**
 * The face of `wall` on the edge a → b, if any: a straight wall runs parallel to the edge with its centre line half a
 * thickness (± tolerance) away; a curved one has the edge's ends and middle half a thickness (± tolerance) off its arc,
 * on one side, beside it (the edge is a piece of the outline along its face, its ends reaching half a thickness on).
 * Twin of `EdgeWall::of()`.
 */
export function edgeWall(a: Vec2, b: Vec2, wall: WallPath & Pick<Wall, 'thickness'>, tolerance: number): EdgeWall | null {
    const frame = wallFrame(wall);
    const half = wall.thickness / 2;
    if (frame.arc === undefined) {
        const length = Math.hypot(b.x - a.x, b.y - a.y);
        const parallel = Math.abs(((b.x - a.x) * frame.dir.y - (b.y - a.y) * frame.dir.x) / length) < 1e-3;
        // Distance of a from the centre line, positive on its left.
        const normal = acrossWall(frame, a);
        if (!parallel || Math.abs(Math.abs(normal) - half) > tolerance) {
            return null;
        }
        return {frame, side: normal > 0 ? 'Left' : 'Right'};
    }

    const across = [a, {x: (a.x + b.x) / 2, y: (a.y + b.y) / 2}, b].map((point) => acrossWall(frame, point));
    if (across.some((d) => Math.abs(Math.abs(d) - half) > tolerance || d > 0 !== across[0] > 0)) {
        return null;
    }
    const along: [number, number] = [alongWall(frame, a), alongWall(frame, b)];
    if (Math.max(...along) <= -half || Math.min(...along) >= frame.length + half) {
        return null;
    }
    return {frame, side: across[0] > 0 ? 'Left' : 'Right', along};
}

/**
 * Where a stretch of a wall's centre line (mm along it) lies on the edge from `a` along `dir`, `length` long, mm from a
 * (unsorted): projected, on a curved wall clipped to the edge and placed between its ends. Null when it misses the edge.
 */
export function edgeStretch(match: EdgeWall, a: Vec2, dir: Vec2, length: number, from: number, to: number): [number, number] | null {
    if (match.along === undefined) {
        const project = (point: Vec2): number => (point.x - a.x) * dir.x + (point.y - a.y) * dir.y;
        return [project(pointOnWall(match.frame, from)), project(pointOnWall(match.frame, to))];
    }
    const [ta, tb] = match.along;
    const lo = Math.max(from, Math.min(ta, tb));
    const hi = Math.min(to, Math.max(ta, tb));
    if (hi <= lo) {
        return null;
    }
    return [((lo - ta) / (tb - ta)) * length, ((hi - ta) / (tb - ta)) * length];
}

/**
 * Openings of walls whose face lies on the edge a → b (`edgeWall()`). Returns their extents projected onto the edge; a
 * niche counts on the face it is cut into only.
 */
export function wallGapsAlongEdge(a: Vec2, b: Vec2, walls: readonly Wall[], tolerance = 30): WallGap[] {
    const length = Math.hypot(b.x - a.x, b.y - a.y);
    if (length === 0) {
        return [];
    }
    const dir = {x: (b.x - a.x) / length, y: (b.y - a.y) / length};
    const gaps: WallGap[] = [];

    for (const wall of walls) {
        const match = edgeWall(a, b, wall, tolerance);
        if (match === null) {
            continue;
        }
        for (const opening of wall.openings) {
            if (opening.type === 'Niche' && (opening.side ?? 'Left') !== match.side) {
                continue;
            }
            const stretch = edgeStretch(match, a, dir, length, opening.offset, opening.offset + opening.width);
            if (stretch === null) {
                continue;
            }
            const from = Math.max(Math.min(...stretch), 0);
            const to = Math.min(Math.max(...stretch), length);
            if (to > from) {
                gaps.push({from, to, bottom: opening.sillHeight, top: opening.sillHeight + opening.height, niche: opening.type === 'Niche'});
            }
        }
    }

    return gaps;
}

/** Signed area by the shoelace formula, mm²; positive for counter-clockwise polygons. */
/**
 * The corners of an item's footprint in plan mm, counter-clockwise from its front left (as seen from the front); a
 * round column's is an ellipse of 32 points.
 */
export function itemFootprint(item: Pick<Item, 'kind' | 'x' | 'y' | 'width' | 'depth' | 'rotation' | 'params'>): Vec2[] {
    const r = (item.rotation * Math.PI) / 180;
    const cos = Math.cos(r);
    const sin = Math.sin(r);
    const w = item.width / 2;
    const d = item.depth / 2;
    const local: [number, number][] =
        item.kind === 'Column' && item.params['shape'] === 'round'
            ? Array.from({length: 32}, (_, i) => [w * Math.cos((i / 16) * Math.PI), d * Math.sin((i / 16) * Math.PI)])
            : [
                [-w, -d],
                [w, -d],
                [w, d],
                [-w, d],
            ];
    return local.map(([x, y]) => ({x: item.x + x * cos - y * sin, y: item.y + x * sin + y * cos}));
}

/** Plan point (mm) where a room's tile joints cross; the plan origin when the room sets none. */
export function tileOrigin(room: Pick<Room, 'tileOriginX' | 'tileOriginY'> | undefined): Vec2 {
    return {x: room?.tileOriginX ?? 0, y: room?.tileOriginY ?? 0};
}

/** A room's tile grid: where its joints cross (plan mm) and how far its floor pattern is turned (degrees counter-clockwise). */
export interface TileGrid {
    origin: Vec2;
    angle: number;
}

export function tileGrid(room: Pick<Room, 'tileOriginX' | 'tileOriginY' | 'floorAngle'> | undefined): TileGrid {
    return {origin: tileOrigin(room), angle: room?.floorAngle ?? 0};
}

/** A polygon counter-clockwise, so its interior is on the left of every edge. */
export function counterClockwise(polygon: readonly PlanPoint[]): PlanPoint[] {
    return polygonSignedArea(polygon) >= 0 ? [...polygon] : [...polygon].reverse();
}

/**
 * Texture coordinates (metres) of a point on a tiled surface, on the grid whose joints cross at `grid.origin`.
 * Point and origin are plan mm, `height` mm above the floor; the normal is in plan axes plus `up`. Floors
 * and other level faces take u, v from plan x, y, turned by `grid.angle`. Upright faces take u along the face
 * (to the right as seen from the front) measured from the origin's projection, so joints continue those of an
 * unturned floor around every corner, and v from the floor, so rows start with a whole tile at the floor.
 */
export function tileUv(point: Vec2, height: number, normal: {x: number; y: number; up: number}, grid: TileGrid): [number, number] {
    const dx = point.x - grid.origin.x;
    const dy = point.y - grid.origin.y;
    if (Math.abs(normal.up) > 0.5) {
        return turnedUv(dx, dy, grid.angle);
    }
    const length = Math.hypot(normal.x, normal.y);
    return [((dx * -normal.y + dy * normal.x) / length) * MM, height * MM];
}

/** Plan offsets (mm) as texture coordinates (metres) of a pattern turned `angle` degrees counter-clockwise. */
export function turnedUv(dx: number, dy: number, angle: number): [number, number] {
    if (angle === 0) {
        return [dx * MM, dy * MM];
    }
    const a = (angle * Math.PI) / 180;
    const cos = Math.cos(a);
    const sin = Math.sin(a);
    return [(dx * cos + dy * sin) * MM, (dy * cos - dx * sin) * MM];
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
 * Floor-level footprints of the walls; doorways and walkable French windows leave gaps (their leaves are
 * `leafColliders`), other windows count as solid wall.
 */
export function wallColliders(walls: readonly Wall[]): WallCollider[] {
    const colliders: WallCollider[] = [];
    for (const wall of walls) {
        const frame = wallFrame(wall);
        if (!(frame.length > 0)) {
            continue;
        }
        const doors = wall.openings.filter(isPassable);
        for (const piece of wallPieces(frame.length, wall.height, doors)) {
            if (piece.bottom >= WalkableStep) {
                continue;
            }
            if (frame.arc === undefined) {
                colliders.push({frame, from: piece.from, to: piece.to, halfThickness: wall.thickness / 2});
                continue;
            }
            // A curved wall blocks as its straight pieces, each on the chord of its stretch.
            const samples = wallSamples(frame, piece.from, piece.to);
            for (let i = 1; i < samples.length; i++) {
                const start = pointOnWall(frame, samples[i - 1]);
                const end = pointOnWall(frame, samples[i]);
                const chord = wallFrame({startX: start.x, startY: start.y, endX: end.x, endY: end.y});
                colliders.push({frame: chord, from: 0, to: chord.length, halfThickness: wall.thickness / 2});
            }
        }
    }
    return colliders;
}

/**
 * Floor-level footprints of the columns among `items`, as wall pieces along their width (a round column by its
 * bounding box). Other items do not block walking; beams are above head height.
 */
export function columnColliders(items: readonly Item[]): WallCollider[] {
    return items
        .filter((item) => item.kind === 'Column' && item.z < WalkableStep)
        .map((item) => {
            const angle = (item.rotation * Math.PI) / 180;
            const dir = {x: Math.cos(angle), y: Math.sin(angle)};
            const frame: WallFrame = {
                start: {x: item.x - (dir.x * item.width) / 2, y: item.y - (dir.y * item.width) / 2},
                dir,
                normal: {x: -dir.y, y: dir.x},
                length: item.width,
                angle,
            };
            return {frame, from: 0, to: item.width, halfThickness: item.depth / 2};
        });
}

/** Door frame on each side of a door leaf, mm; the leaf fills the rest of the opening unless `leafWidth` says otherwise. */
export const DoorFrameWidth = 50;
export const DoorLeafThickness = 40;
/** How far a sliding door's leaf overlaps the opening on each edge (the PHP `OpeningPlacement::SlidingDoorOverlap`). */
export const SlidingDoorOverlap = 50;
/** Gap between a sliding door's leaf and the wall face it hangs on, mm. */
export const SlidingDoorGap = 15;
/** Frame bar around a French window; its sashes hinge on the inside of it (mm). */
export const WindowFrameBar = 70;
export const SashThickness = 60;
/** How far a door leaf or sash opens, radians. */
export const LeafOpenAngle = (80 * Math.PI) / 180;

/**
 * A door leaf or French window sash, on its wall's centre-line frame (mm). A swinging one, at angle `a` (0
 * closed, `LeafOpenAngle` open), runs from the hinge along `closed · cos a` and across `swing · sin a`. A sliding
 * one runs from `along` towards the wall end and, open, has moved `travel` along the wall.
 */
export interface Leaf {
    openingId: number;
    /** Index of the leaf (0, 1) of a double door or French window; 0 otherwise. */
    index: number;
    motion: 'swing' | 'slide';
    /** The hinge axis (a sliding leaf's start edge, closed): mm along the wall from its start, mm across it (+ towards `normal`). */
    along: number;
    across: number;
    /** From the hinge to the free edge when closed: +1 towards the wall end, −1 towards its start; +1 when sliding. */
    closed: 1 | -1;
    /** Side it swings into, or a sliding door hangs on: +1 the left of the wall direction (`normal`), −1 the right. */
    swing: 1 | -1;
    /** How far a sliding leaf moves when open, mm along the wall (signed); 0 for a swinging one. */
    travel: number;
    width: number;
    thickness: number;
}

/** Doorways of every kind, passages included. */
export function isDoor(opening: Pick<Opening, 'type'>): boolean {
    return opening.type !== 'Window' && opening.type !== 'Niche';
}

/** Doorways and walkable French windows: openings a walker can pass through. */
export function isPassable(opening: Opening): boolean {
    return isDoor(opening) || opening.walkable === true;
}

/** Doors with a leaf and walkable French windows: openings that open. */
export function hasLeaves(opening: Opening): boolean {
    return isPassable(opening) && opening.type !== 'Passage';
}

/**
 * The leaves of an opening. A door's one leaf hangs on its `hinge` edge, a double door's two and a walkable
 * French window's two sashes on its jambs (one on `hinge` without a mullion); they swing into `side` (default left) and, closed, lie flush with
 * that wall face (a sash inside the window frame). A sliding door's leaf hangs in front of the `side` face, a
 * pocket door's in the wall; both slide open towards `hinge` until they clear the opening. Passages and other
 * windows have none.
 */
export function openingLeaves(opening: Opening, wallThickness: number): Leaf[] {
    const swing: Leaf['swing'] = opening.side === 'Right' ? -1 : 1;
    const towardsEnd = opening.hinge === 'End';
    const base = {openingId: opening.id, index: 0, swing, travel: 0};
    switch (opening.type) {
        case 'Door': {
            const width = opening.leafWidth ?? opening.width - 2 * DoorFrameWidth;
            const clearance = (opening.width - width) / 2;
            return [{
                ...base,
                motion: 'swing',
                along: towardsEnd ? opening.offset + opening.width - clearance : opening.offset + clearance,
                across: swing * (wallThickness / 2 - DoorLeafThickness / 2),
                closed: towardsEnd ? -1 : 1,
                width,
                thickness: DoorLeafThickness,
            }];
        }
        case 'DoubleDoor': {
            const space = opening.width - 2 * DoorFrameWidth;
            const active = Math.min(opening.leafWidth ?? space / 2, space);
            const widths = towardsEnd ? [space - active, active] : [active, space - active];
            const across = swing * (wallThickness / 2 - DoorLeafThickness / 2);
            const leaf = {...base, motion: 'swing' as const, across, thickness: DoorLeafThickness};
            return [
                {...leaf, along: opening.offset + DoorFrameWidth, closed: 1 as const, width: widths[0]},
                {...leaf, index: 1, along: opening.offset + opening.width - DoorFrameWidth, closed: -1 as const, width: widths[1]},
            ].filter((l) => l.width > 0);
        }
        case 'SlidingDoor':
            return [{
                ...base,
                motion: 'slide',
                along: opening.offset - SlidingDoorOverlap,
                across: swing * (wallThickness / 2 + SlidingDoorGap + DoorLeafThickness / 2),
                closed: 1,
                travel: (towardsEnd ? 1 : -1) * (opening.width + SlidingDoorOverlap),
                width: opening.width + 2 * SlidingDoorOverlap,
                thickness: DoorLeafThickness,
            }];
        case 'PocketDoor':
            return [{
                ...base,
                motion: 'slide',
                along: opening.offset,
                across: 0,
                closed: 1,
                travel: (towardsEnd ? 1 : -1) * opening.width,
                width: opening.width,
                thickness: DoorLeafThickness,
            }];
        case 'Passage':
        case 'Niche':
            return [];
        case 'Window': {
            if (opening.walkable !== true) {
                return [];
            }
            const across = swing * (WindowFrameBar / 2 - SashThickness / 2);
            const start = opening.offset + WindowFrameBar;
            const end = opening.offset + opening.width - WindowFrameBar;
            if (opening.mullions === 0) {
                // One sash, hung on `hinge`.
                const sash = {...base, motion: 'swing' as const, across, width: end - start, thickness: SashThickness};
                return [towardsEnd ? {...sash, along: end, closed: -1} : {...sash, along: start, closed: 1}];
            }
            const sash = {...base, motion: 'swing' as const, across, width: (end - start) / 2, thickness: SashThickness};
            return [
                {...sash, along: start, closed: 1},
                {...sash, index: 1, along: end, closed: -1},
            ];
        }
    }
}

/**
 * Footprint of a leaf as a thin wall from its hinge (a sliding leaf's start edge) along the leaf; `open` is
 * 0 closed … 1 open: the angle of a swinging leaf over `LeafOpenAngle`, the way of a sliding one along `travel`.
 */
export function leafCollider(frame: WallFrame, leaf: Leaf, open: number): WallCollider {
    const angle = leaf.motion === 'swing' ? open * LeafOpenAngle : 0;
    const along = leaf.closed * Math.cos(angle);
    const across = leaf.swing * Math.sin(angle);
    const dir = {x: frame.dir.x * along + frame.normal.x * across, y: frame.dir.y * along + frame.normal.y * across};
    const hinge = leaf.motion === 'slide' ? leaf.along + open * leaf.travel : leaf.along;
    return {
        frame: {
            start: pointOnWall(frame, hinge, leaf.across),
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

/** Footprints of all door leaves and sashes; `openness` (0 closed … 1 open) of an opening sets their angle or way. */
export function leafColliders(walls: readonly Wall[], openness: (openingId: number) => number): WallCollider[] {
    return walls.flatMap((wall) => {
        const frame = wallFrame(wall);
        if (!(frame.length > 0)) {
            return [];
        }
        return wall.openings.flatMap((opening) =>
            openingLeaves(opening, wall.thickness).map((leaf) => leafCollider(openingFrame(frame, opening), leaf, openness(opening.id))),
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
    const doors = walls.flatMap((wall) => wall.openings.filter(isDoor).map((opening) => ({wall, opening})));
    const door = doors.find((d) => d.opening.entrance) ?? doors.find((d) => d.wall.type === 'Exterior');
    if (door === undefined) {
        return null;
    }

    const frame = openingFrame(wallFrame(door.wall), door.opening);
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

/**
 * Distance along the wall's centre line of the point's projection onto it, mm from the start; on a curved wall by its
 * angle about the centre (within half a turn of the arc's middle).
 */
export function alongWall(frame: WallFrame, point: Vec2): number {
    const arc = frame.arc;
    if (arc === undefined) {
        return (point.x - frame.start.x) * frame.dir.x + (point.y - frame.start.y) * frame.dir.y;
    }
    const middle = arc.startAngle + arc.sweep / 2;
    let delta = Math.atan2(point.y - arc.centre.y, point.x - arc.centre.x) - middle;
    delta -= 2 * Math.PI * Math.round(delta / (2 * Math.PI));
    return frame.length / 2 + (delta / arc.sweep) * frame.length;
}

/** Distance of the point from the wall's centre line, mm, positive on its left (radial on a curved wall). */
export function acrossWall(frame: WallFrame, point: Vec2): number {
    const arc = frame.arc;
    if (arc === undefined) {
        return (point.x - frame.start.x) * frame.normal.x + (point.y - frame.start.y) * frame.normal.y;
    }
    return Math.sign(arc.sweep) * (arc.radius - Math.hypot(point.x - arc.centre.x, point.y - arc.centre.y));
}

/** Centre-line length in whole mm (along the arc), rounded down like the backend does (openings that fit it fit the wall). */
export function wallLength(wall: WallPath): number {
    return Math.floor(wallFrame(wall).length);
}

/** Smallest radius (mm) of a curved wall's inner face (the PHP `WallCurve::MinInnerRadius`). */
export const MinInnerRadius = 100;

/** Whether a wall's curve is one the backend accepts: at most a semicircle, its inner face at least `MinInnerRadius` round. */
export function curveFits(wall: WallPath & Pick<Wall, 'thickness'>): boolean {
    const sagitta = wall.sagitta ?? 0;
    const frame = wallFrame(wall);
    return sagitta === 0 || (2 * Math.abs(sagitta) <= Math.hypot(wall.endX - wall.startX, wall.endY - wall.startY) &&
        frame.arc !== undefined && frame.arc.radius - wall.thickness / 2 >= MinInnerRadius);
}

/**
 * The stretch of wall (mm along it) an opening takes: a sliding or pocket door with the wall its open leaf slides into
 * (towards `hinge`). Twin of `OpeningPlacement::occupied()`.
 */
export function occupiedSpan(opening: Pick<Opening, 'type' | 'hinge' | 'offset' | 'width'>): [number, number] {
    const {offset, width} = opening;
    if (opening.type !== 'SlidingDoor' && opening.type !== 'PocketDoor') {
        return [offset, offset + width];
    }
    const travel = width + (opening.type === 'SlidingDoor' ? 2 * SlidingDoorOverlap : 0);
    return opening.hinge === 'End' ? [offset, offset + width + travel] : [offset - travel, offset + width];
}

/**
 * Whether an opening is shallow enough for its curved wall: built straight on the tangent (with the wall its open leaf
 * takes, `occupiedSpan()`), it may stand off the arc by a quarter of the thickness at most. Niches follow the curve.
 * Twin of `OpeningPlacement::fitsCurve()`.
 */
export function openingFitsCurve(frame: WallFrame, thickness: number, opening: Pick<Opening, 'type' | 'hinge' | 'offset' | 'width'>): boolean {
    const arc = frame.arc;
    if (arc === undefined || opening.type === 'Niche') {
        return true;
    }
    const [from, to] = occupiedSpan(opening);
    return arc.radius * (1 - Math.cos((to - from) / (2 * arc.radius))) <= thickness / 4;
}

/** The sagitta clamped to what the chord a → b allows: a semicircle at most. */
export function clampSagitta(a: Vec2, b: Vec2, sagitta: number): number {
    const limit = Math.floor(Math.hypot(b.x - a.x, b.y - a.y) / 2);
    return Math.max(-limit, Math.min(limit, Math.round(sagitta)));
}

/**
 * The wall with new end points and its openings' offsets as the backend derives them: a moved start shifts
 * the offsets so the openings keep their place on the plan (projected onto the moved centre line). The curve's sagitta
 * stays. `fits` tells whether the curve and the openings all still fit (otherwise the edit would be refused).
 */
export function moveWallEnds(wall: Wall, start: Vec2, end: Vec2): {wall: Wall; fits: boolean} {
    // Turned round (the backend's `reverses()`: the new chord runs against the old one), the curve stays on the same
    // side of the plan.
    const reversed = (wall.endX - wall.startX) * (end.x - start.x) + (wall.endY - wall.startY) * (end.y - start.y) < 0;
    const sagitta = reversed ? -(wall.sagitta ?? 0) : (wall.sagitta ?? 0);
    const moved = {...wall, startX: start.x, startY: start.y, endX: end.x, endY: end.y, sagitta};
    const length = wallLength(moved);
    const startMoved = start.x !== wall.startX || start.y !== wall.startY;
    const oldFrame = wallFrame(wall);
    const newFrame = wallFrame(moved);

    const openings = wall.openings.map((opening) => {
        if (!startMoved || !(newFrame.length > 0)) {
            return opening;
        }
        const at = oldFrame.length > 0 ? pointOnWall(oldFrame, opening.offset) : {x: wall.startX, y: wall.startY};
        return {...opening, offset: Math.round(alongWall(newFrame, at))};
    });
    const fits =
        length >= 100 &&
        curveFits(moved) &&
        openings.every((o) => o.offset >= 0 && o.offset + o.width <= length && openingFitsCurve(newFrame, wall.thickness, o));

    return {wall: {...moved, openings}, fits};
}

/**
 * The wall curved to a new sagitta (mm off its chord's midpoint, + to the left; 0 straight), its openings keeping their
 * offsets. `fits` tells whether the curve and the openings still fit (otherwise the edit would be refused).
 */
export function bendWall(wall: Wall, sagitta: number): {wall: Wall; fits: boolean} {
    const bent = {...wall, sagitta};
    const frame = wallFrame(bent);
    const length = wallLength(bent);
    const fits =
        curveFits(bent) &&
        wall.openings.every((o) => o.offset + o.width <= length && openingFitsCurve(frame, wall.thickness, o));
    return {wall: bent, fits};
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
 * The two faces of a wall, each from end to end: `left` of the direction start → end and `right`, running on
 * to the mitred corners given by `ends` (from `wallJoints()`). Two points on a straight wall, the samples of its
 * pieces (`wallSamples()`) on a curved one.
 */
export function wallFaces(wall: WallLine, ends: WallEnds = SquareEnds): {left: Vec2[]; right: Vec2[]} {
    const frame = wallFrame(wall);
    const h = wall.thickness / 2;
    const face = (across: number, start: number, end: number): Vec2[] => {
        const samples = wallSamples(frame, 0, frame.length);
        samples[0] = -start;
        samples[samples.length - 1] = frame.length + end;
        return samples.map((along) => pointOnWall(frame, along, across));
    };
    return {left: face(h, ends.startLeft, ends.endLeft), right: face(-h, ends.startRight, ends.endRight)};
}

/** The straight pieces of a polyline, as segments. */
export function polylineSegments(points: readonly Vec2[]): [Vec2, Vec2][] {
    return points.slice(1).map((point, i): [Vec2, Vec2] => [points[i], point]);
}

/** The wall's centre line as segments: one for a straight wall, the pieces standing in for an arc. */
export function centreLineSegments(wall: WallPath): [Vec2, Vec2][] {
    const frame = wallFrame(wall);
    return polylineSegments(wallSamples(frame, 0, frame.length).map((along) => pointOnWall(frame, along)));
}

/**
 * Mitres for walls whose end points meet in pairs: at such a corner each face runs on until it meets the
 * corresponding face of the other wall (a curved wall's face along its circle). Ends shared by three or more walls,
 * straight (tangent) continuations and very sharp angles stay square. Walls without a joint are left out of the map.
 * Twin of `WallJoints` in the backend.
 */
export function wallJoints(walls: readonly WallLine[]): Map<number, WallEnds> {
    const result = new Map<number, WallEnds>();

    for (const {end, other} of cornerEnds(walls)) {
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
        // How far from the corner into the wall the face on the left of `a` meets the other wall's face, and the one
        // on its right (for straight walls its mirror image).
        const reach = faceReach(end, other, a, b, cross);
        if (reach === null || Math.abs(reach.left) > limit || Math.abs(reach.right) > limit) {
            continue;
        }
        const faces = result.get(end.wall.id) ?? {...SquareEnds};
        if (end.atStart) {
            faces.startLeft = -reach.left;
            faces.startRight = -reach.right;
        } else {
            // Seen from the end, the left of `a` is the wall's right.
            faces.endRight = -reach.left;
            faces.endLeft = -reach.right;
        }
        result.set(end.wall.id, faces);
    }
    return result;
}

/** A wall face in the plan: a line through `point` along `dir`, or a curved wall's circle. */
type FaceCurve = {point: Vec2; dir: Vec2} | {centre: Vec2; radius: number};

/**
 * Where the faces left and right of `a` meet the other wall's faces at a corner: mm from the corner into the wall,
 * along its centre line. Null when a curved face misses the other.
 */
function faceReach(end: WallEnd<WallLine>, other: WallEnd<WallLine>, a: Vec2, b: Vec2, cross: number): {left: number; right: number} | null {
    const ha = end.wall.thickness / 2;
    const hb = other.wall.thickness / 2;
    const frame = wallFrame(end.wall);
    const otherFrame = wallFrame(other.wall);
    if (frame.arc === undefined && otherFrame.arc === undefined) {
        const k = (hb + ha * (a.x * b.x + a.y * b.y)) / cross;
        return {left: k, right: -k};
    }
    // The left of `a` is the wall's left at its start, its right at its end; the face it meets is on the right of `b`.
    const sign = end.atStart ? 1 : -1;
    const otherSign = other.atStart ? 1 : -1;
    const reach = (across: number, otherAcross: number): number | null => {
        const meet = nearestIntersection(faceCurve(frame, across), faceCurve(otherFrame, otherAcross), end.point);
        if (meet === null) {
            return null;
        }
        const along = alongWall(frame, meet);
        return end.atStart ? along : frame.length - along;
    };
    const left = reach(sign * ha, -otherSign * hb);
    const right = reach(-sign * ha, otherSign * hb);
    return left === null || right === null ? null : {left, right};
}

function faceCurve(frame: WallFrame, across: number): FaceCurve {
    const arc = frame.arc;
    return arc === undefined
        ? {point: pointOnWall(frame, 0, across), dir: frame.dir}
        : {centre: arc.centre, radius: arc.radius - Math.sign(arc.sweep) * across};
}

/** The intersection of two face curves nearest to `near`; null when they do not meet. */
function nearestIntersection(p: FaceCurve, q: FaceCurve, near: Vec2): Vec2 | null {
    let points: Vec2[];
    if ('dir' in p && 'dir' in q) {
        points = lineLine(p.point, p.dir, q.point, q.dir);
    } else if ('dir' in p && 'centre' in q) {
        points = lineCircle(p.point, p.dir, q.centre, q.radius);
    } else if ('centre' in p && 'dir' in q) {
        points = lineCircle(q.point, q.dir, p.centre, p.radius);
    } else if ('centre' in p && 'centre' in q) {
        points = circleCircle(p.centre, p.radius, q.centre, q.radius);
    } else {
        points = [];
    }
    let best: Vec2 | null = null;
    for (const point of points) {
        if (best === null || Math.hypot(point.x - near.x, point.y - near.y) < Math.hypot(best.x - near.x, best.y - near.y)) {
            best = point;
        }
    }
    return best;
}

function lineLine(p: Vec2, d: Vec2, q: Vec2, e: Vec2): Vec2[] {
    const cross = d.x * e.y - d.y * e.x;
    if (Math.abs(cross) < 1e-9) {
        return [];
    }
    const t = ((q.x - p.x) * e.y - (q.y - p.y) * e.x) / cross;
    return [{x: p.x + d.x * t, y: p.y + d.y * t}];
}

function lineCircle(p: Vec2, d: Vec2, centre: Vec2, radius: number): Vec2[] {
    const fx = p.x - centre.x;
    const fy = p.y - centre.y;
    const b = fx * d.x + fy * d.y;
    const discriminant = b * b - (fx * fx + fy * fy - radius * radius);
    if (discriminant < 0) {
        return [];
    }
    const root = Math.sqrt(discriminant);
    return [-b - root, -b + root].map((t) => ({x: p.x + d.x * t, y: p.y + d.y * t}));
}

function circleCircle(c1: Vec2, r1: number, c2: Vec2, r2: number): Vec2[] {
    const dx = c2.x - c1.x;
    const dy = c2.y - c1.y;
    const distance = Math.hypot(dx, dy);
    if (distance === 0 || distance > r1 + r2 || distance < Math.abs(r1 - r2)) {
        return [];
    }
    const along = (r1 * r1 - r2 * r2 + distance * distance) / (2 * distance);
    const height = Math.sqrt(Math.max(r1 * r1 - along * along, 0));
    const base = {x: c1.x + (dx * along) / distance, y: c1.y + (dy * along) / distance};
    return [1, -1].map((sign) => ({x: base.x - (sign * dy * height) / distance, y: base.y + (sign * dx * height) / distance}));
}

interface WallEnd<W extends WallLine> {
    wall: W;
    atStart: boolean;
    point: Vec2;
}

/** Wall ends meeting exactly one other wall end, each with that other end (so every pair comes up twice). */
function cornerEnds<W extends WallLine>(walls: readonly W[]): {end: WallEnd<W>; other: WallEnd<W>}[] {
    const ends: WallEnd<W>[] = walls.flatMap((wall) => [
        {wall, atStart: true, point: {x: wall.startX, y: wall.startY}},
        {wall, atStart: false, point: {x: wall.endX, y: wall.endY}},
    ]);
    return ends.flatMap((end) => {
        const others = ends.filter(
            (o) => o.wall.id !== end.wall.id && Math.hypot(o.point.x - end.point.x, o.point.y - end.point.y) <= JointTolerance,
        );
        if (others.length !== 1 || wallFrame(end.wall).length === 0 || wallFrame(others[0].wall).length === 0) {
            return [];
        }
        return [{end, other: others[0]}];
    });
}

/** Unit vector from a wall end into the wall (along the tangent of a curved wall). */
function intoWall(end: WallEnd<WallLine>): Vec2 {
    const frame = wallFrame(end.wall);
    if (end.atStart) {
        return tangentAt(frame, 0);
    }
    const tangent = tangentAt(frame, frame.length);
    return {x: -tangent.x, y: -tangent.y};
}

/** The corner windows at a wall's ends: the id of the window that turns the corner there, or null. */
export interface WallCorners {
    start: number | null;
    end: number | null;
}

export const NoCorners: WallCorners = {start: null, end: null};

/** How close (mm) a window's edge must come to its wall's end to reach it. */
const CornerWindowTolerance = 2;

/**
 * Corner windows: where two walls meet at an angle and each has a window reaching that end, their heights
 * overlapping, the windows meet without a post. The walls already leave the corner open there (no piece of
 * either wall reaches into it at the windows' height). Walls without one are left out of the map.
 */
export function cornerWindows(walls: readonly Wall[]): Map<number, WallCorners> {
    const result = new Map<number, WallCorners>();
    const windowAt = (end: WallEnd<Wall>): Opening | undefined => {
        const length = wallFrame(end.wall).length;
        return end.wall.openings.find(
            (o) =>
                o.type === 'Window' &&
                (end.atStart ? o.offset <= CornerWindowTolerance : o.offset + o.width >= length - CornerWindowTolerance),
        );
    };

    for (const {end, other} of cornerEnds(walls)) {
        // Not on curved walls: their windows stand on the tangent, away from the corner.
        if (wallFrame(end.wall).arc !== undefined || wallFrame(other.wall).arc !== undefined) {
            continue;
        }
        const a = intoWall(end);
        const b = intoWall(other);
        const mine = windowAt(end);
        const theirs = windowAt(other);
        if (Math.abs(a.x * b.y - a.y * b.x) < 1e-3 || mine === undefined || theirs === undefined) {
            continue;
        }
        const bottom = Math.max(mine.sillHeight, theirs.sillHeight);
        const top = Math.min(mine.sillHeight + mine.height, theirs.sillHeight + theirs.height);
        if (top > bottom) {
            const corners = result.get(end.wall.id) ?? {...NoCorners};
            corners[end.atStart ? 'start' : 'end'] = mine.id;
            result.set(end.wall.id, corners);
        }
    }
    return result;
}
