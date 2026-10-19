import {PlanPoint} from '@app/models/scene';

import {isSimplePolygon, pointInPolygon, pointOnWall, SquareEnds, Vec2, wallFrame, wallJoints, WallLine} from './plan-geometry';

/** `onWall`: the point is inside a wall; `open`: no walls close around it; `invalid`: the outline is not a usable room polygon. */
export type RoomDetectError = 'onWall' | 'open' | 'invalid';

export type RoomDetection = {polygon: PlanPoint[]} | {error: RoomDetectError};

/** Points closer than this (mm) are one vertex of the face graph. */
const Tolerance = 1;

/** A vertex closer than this (mm) to the line through its neighbours is dropped from the outline. */
const Collinear = 0.5;

/** The faces of a floor's walls, computed once per scene; `pickRoom()` answers for any point. */
export interface RoomFaces {
    footprints: PlanPoint[][];
    /** Bounded faces (counter-clockwise), smallest first. */
    faces: Vec2[][];
}

/** Limits of a stored room outline, as the backend's `PolygonValidator` enforces them. */
const MaxPoints = 200;
const CoordinateLimit = 100000;

/**
 * The outline of the area enclosed by walls around `point`: the inner faces of the walls (mitred corners
 * included), as a counter-clockwise polygon in whole mm starting at its lowest (then leftmost) vertex.
 *
 * The wall footprints are cut into a planar graph at every crossing, dangling edges are pruned, and the
 * smallest bounded face containing the point is taken. Openings do not matter: a wall is continuous.
 * Twin of the backend's `RoomDetector`; the shared fixture `backend/tests/fixtures/room-detection.json`
 * keeps them identical.
 */
export function detectRoom(walls: readonly WallLine[], point: Vec2): RoomDetection {
    return pickRoom(prepareRoomFaces(walls), point);
}

/** The costly part of `detectRoom()`, independent of the point: for repeated picks on the same walls. */
export function prepareRoomFaces(walls: readonly WallLine[]): RoomFaces {
    const footprints = wallFootprints(walls);
    const faces = traceFaces(buildGraph(footprints.flatMap(edgesOf)))
        .filter((face) => signedArea(face) > 0)
        .sort((a, b) => signedArea(a) - signedArea(b));
    return {footprints: footprints.map((f) => f.map(tuple)), faces};
}

export function pickRoom(prepared: RoomFaces, point: Vec2): RoomDetection {
    if (prepared.footprints.some((footprint) => pointInPolygon(point, footprint))) {
        return {error: 'onWall'};
    }
    const best = prepared.faces.find((face) => pointInPolygon(point, face.map(tuple)));
    if (best === undefined) {
        return {error: 'open'};
    }

    const polygon = cleanOutline(best);
    const withinLimits = polygon.length <= MaxPoints && polygon.every(([x, y]) => Math.abs(x) <= CoordinateLimit && Math.abs(y) <= CoordinateLimit);
    return withinLimits && isSimplePolygon(polygon) ? {polygon} : {error: 'invalid'};
}

/** Footprint of every wall with a length: right face start → end, then left face end → start. */
function wallFootprints(walls: readonly WallLine[]): Vec2[][] {
    const joints = wallJoints(walls);
    return walls
        .filter((wall) => wall.startX !== wall.endX || wall.startY !== wall.endY)
        .map((wall) => {
            const frame = wallFrame(wall);
            const half = wall.thickness / 2;
            const ends = joints.get(wall.id) ?? SquareEnds;
            return [
                pointOnWall(frame, -ends.startRight, -half),
                pointOnWall(frame, frame.length + ends.endRight, -half),
                pointOnWall(frame, frame.length + ends.endLeft, half),
                pointOnWall(frame, -ends.startLeft, half),
            ];
        });
}

function edgesOf(polygon: readonly Vec2[]): [Vec2, Vec2][] {
    return polygon.map((p, i) => [p, polygon[(i + 1) % polygon.length]]);
}

interface Graph {
    vertices: Vec2[];
    /** Neighbour vertex indices per vertex. */
    neighbours: number[][];
}

/**
 * Splits every segment where another one crosses it or ends on it, merges points within `Tolerance` (the
 * first one seen stays) and drops duplicate edges, then prunes vertices with a single edge until none is left.
 */
function buildGraph(segments: readonly [Vec2, Vec2][]): Graph {
    const vertices: Vec2[] = [];
    const edges = new Set<string>();
    const vertexAt = (point: Vec2): number => {
        const index = vertices.findIndex((v) => Math.hypot(v.x - point.x, v.y - point.y) <= Tolerance);
        if (index >= 0) {
            return index;
        }
        vertices.push(point);
        return vertices.length - 1;
    };

    segments.forEach(([a, b], i) => {
        const length = Math.hypot(b.x - a.x, b.y - a.y);
        if (length === 0) {
            return;
        }
        const cuts = [0, 1];
        segments.forEach(([c, d], j) => {
            if (i !== j) {
                cuts.push(...cutsBy(a, b, length, c, d));
            }
        });
        cuts.sort((p, q) => p - q);
        let previous = vertexAt(a);
        for (const t of cuts.slice(1)) {
            const next = vertexAt({x: a.x + (b.x - a.x) * t, y: a.y + (b.y - a.y) * t});
            if (next !== previous) {
                edges.add(previous < next ? `${previous}:${next}` : `${next}:${previous}`);
            }
            previous = next;
        }
    });

    const neighbours: number[][] = vertices.map(() => []);
    for (const key of edges) {
        const [u, v] = key.split(':').map(Number);
        neighbours[u].push(v);
        neighbours[v].push(u);
    }
    let pruned = true;
    while (pruned) {
        pruned = false;
        neighbours.forEach((list, u) => {
            if (list.length === 1) {
                const v = list[0];
                neighbours[u] = [];
                neighbours[v] = neighbours[v].filter((w) => w !== u);
                pruned = true;
            }
        });
    }

    return {vertices, neighbours};
}

/** Where along ab (0..1, ends excluded) the segment cd crosses it or one of its end points lies on it. */
function cutsBy(a: Vec2, b: Vec2, length: number, c: Vec2, d: Vec2): number[] {
    const cuts: number[] = [];
    const r = {x: b.x - a.x, y: b.y - a.y};
    const s = {x: d.x - c.x, y: d.y - c.y};
    const denominator = cross(r, s);
    if (Math.abs(denominator) > 1e-9) {
        const ac = {x: c.x - a.x, y: c.y - a.y};
        const t = cross(ac, s) / denominator;
        const u = cross(ac, r) / denominator;
        const sLength = Math.hypot(s.x, s.y);
        if (t * length > Tolerance && (1 - t) * length > Tolerance && u * sLength >= -Tolerance && (1 - u) * sLength >= -Tolerance) {
            cuts.push(t);
        }
    }
    for (const end of [c, d]) {
        const t = ((end.x - a.x) * r.x + (end.y - a.y) * r.y) / (length * length);
        const distance = Math.abs(cross(r, {x: end.x - a.x, y: end.y - a.y})) / length;
        if (distance <= Tolerance && t * length > Tolerance && (1 - t) * length > Tolerance) {
            cuts.push(t);
        }
    }
    return cuts;
}

/**
 * Faces of the planar graph: from each directed edge u → v, turn onto the edge at v that comes next clockwise
 * after v → u. Bounded faces come out counter-clockwise (positive area), the outer ones clockwise.
 */
function traceFaces(graph: Graph): Vec2[][] {
    const {vertices, neighbours} = graph;
    const sorted = neighbours.map((list, u) =>
        [...list].sort((p, q) => angle(vertices[u], vertices[p]) - angle(vertices[u], vertices[q])),
    );
    const visited = new Set<string>();
    const faces: Vec2[][] = [];

    sorted.forEach((list, start) => {
        for (const first of list) {
            if (visited.has(`${start}:${first}`)) {
                continue;
            }
            const face: Vec2[] = [];
            let u = start;
            let v = first;
            for (let steps = 0; steps <= vertices.length * 2 && !visited.has(`${u}:${v}`); steps++) {
                visited.add(`${u}:${v}`);
                face.push(vertices[u]);
                const around = sorted[v];
                const next = around[(around.indexOf(u) - 1 + around.length) % around.length];
                u = v;
                v = next;
            }
            faces.push(face);
        }
    });
    return faces;
}

/** Drops (nearly) collinear vertices, rounds to whole mm and starts at the lowest, then leftmost vertex. */
function cleanOutline(face: readonly Vec2[]): PlanPoint[] {
    const points = [...face];
    let removed = true;
    while (removed && points.length > 3) {
        removed = false;
        for (let i = 0; i < points.length; i++) {
            const previous = points[(i - 1 + points.length) % points.length];
            const next = points[(i + 1) % points.length];
            if (distanceToLine(points[i], previous, next) < Collinear) {
                points.splice(i, 1);
                removed = true;
                break;
            }
        }
    }

    const rounded: PlanPoint[] = [];
    for (const point of points) {
        const vertex: PlanPoint = [roundHalfUp(point.x), roundHalfUp(point.y)];
        const last = rounded[rounded.length - 1];
        if (last === undefined || last[0] !== vertex[0] || last[1] !== vertex[1]) {
            rounded.push(vertex);
        }
    }
    if (rounded.length > 1 && rounded[0][0] === rounded[rounded.length - 1][0] && rounded[0][1] === rounded[rounded.length - 1][1]) {
        rounded.pop();
    }

    let first = 0;
    rounded.forEach(([x, y], i) => {
        const [fx, fy] = rounded[first];
        if (y < fy || (y === fy && x < fx)) {
            first = i;
        }
    });
    return [...rounded.slice(first), ...rounded.slice(0, first)];
}

function distanceToLine(point: Vec2, a: Vec2, b: Vec2): number {
    const length = Math.hypot(b.x - a.x, b.y - a.y);
    if (length === 0) {
        return Math.hypot(point.x - a.x, point.y - a.y);
    }
    return Math.abs(cross({x: b.x - a.x, y: b.y - a.y}, {x: point.x - a.x, y: point.y - a.y})) / length;
}

function signedArea(polygon: readonly Vec2[]): number {
    let sum = 0;
    polygon.forEach((p, i) => {
        const q = polygon[(i + 1) % polygon.length];
        sum += p.x * q.y - q.x * p.y;
    });
    return sum / 2;
}

function angle(from: Vec2, to: Vec2): number {
    return Math.atan2(to.y - from.y, to.x - from.x);
}

function cross(a: Vec2, b: Vec2): number {
    return a.x * b.y - a.y * b.x;
}

/** Rounds .5 up on both signs, like the backend twin (`Math.round` and PHP's `round` differ for negatives); `+ 0` turns −0 into 0. */
function roundHalfUp(value: number): number {
    return Math.floor(value + 0.5) + 0;
}

function tuple(point: Vec2): PlanPoint {
    return [point.x, point.y];
}
