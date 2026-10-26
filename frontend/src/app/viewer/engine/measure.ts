import {Wall} from '@app/models/scene';

import {centreLineSegments, polylineSegments, snapPoint, SnapResult, Vec2, WallEnds, wallFaces} from './plan-geometry';

/** A measurement in the top view: the first point, the second (null while the pointer still moves it), and the pointer. */
export interface Measurement {
    a: Vec2 | null;
    b: Vec2 | null;
    cursor: Vec2 | null;
    snap: SnapResult['kind'] | null;
}

/** What the measure tool shows next to the view: the distance between the points (to the pointer while the second is not placed). */
export interface MeasureStatus {
    /** Points placed: 0, 1 or 2. */
    points: number;
    /** Distance and its east / north components, mm; null before the first point. */
    distance: number | null;
    dx: number | null;
    dy: number | null;
    snap: SnapResult['kind'] | null;
}

export const NoMeasurement: Measurement = {a: null, b: null, cursor: null, snap: null};

export interface MeasureSnapOptions {
    /** Snap distance, mm; 0 turns snapping off (Shift). */
    radius: number;
    walls: readonly Wall[];
    /** Mitres of the walls (`wallJoints()`), so face corners lie where the faces meet. */
    joints: ReadonlyMap<number, WallEnds>;
    /** The first point, to line the second one up with. */
    from: Vec2 | null;
    /** Walls of the floor below while it is traced (`ghost.ts`): their ends and centre lines are snapped to as well. */
    ghostWalls?: readonly Wall[];
}

/**
 * Where a measure point goes: onto a wall end or a corner of the wall faces, onto a face or a centre line,
 * in line with the first point, else the pointer itself (whole mm). The floor below adds its wall ends and centre lines.
 */
export function snapMeasurePoint(cursor: Vec2, options: MeasureSnapOptions): SnapResult {
    const {radius, walls, joints, from} = options;
    const lines: (readonly [Vec2, Vec2])[] = [];
    const points: Vec2[] = [];
    for (const wall of walls) {
        const start = {x: wall.startX, y: wall.startY};
        const end = {x: wall.endX, y: wall.endY};
        const {left, right} = wallFaces(wall, joints.get(wall.id));
        points.push(start, end, left[0], left[left.length - 1], right[0], right[right.length - 1]);
        lines.push(...polylineSegments(left), ...polylineSegments(right), ...centreLineSegments(wall));
    }
    for (const wall of options.ghostWalls ?? []) {
        points.push({x: wall.startX, y: wall.startY}, {x: wall.endX, y: wall.endY});
        lines.push(...centreLineSegments(wall));
    }
    return snapPoint(cursor, {grid: 1, radius, points, lines, anchors: from === null ? [] : [from]});
}

/** The measurement after a click at `point`: the first point, the second, or (with both placed) a new first one. */
export function measureClick(measurement: Measurement, point: Vec2): Measurement {
    if (measurement.a === null || measurement.b !== null) {
        return {a: point, b: null, cursor: point, snap: measurement.snap};
    }
    return {...measurement, b: point, cursor: point};
}

/** The readout of a measurement. */
export function measureStatus(measurement: Measurement): MeasureStatus {
    const {a, snap} = measurement;
    const b = measurement.b ?? measurement.cursor;
    const points = a === null ? 0 : measurement.b === null ? 1 : 2;
    if (a === null || b === null) {
        return {points, distance: null, dx: null, dy: null, snap};
    }
    return {points, distance: Math.round(Math.hypot(b.x - a.x, b.y - a.y)), dx: Math.round(b.x - a.x), dy: Math.round(b.y - a.y), snap};
}
