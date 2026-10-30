import {Wall} from '@app/models/scene';

import {centreLineSegments, snapPoint, SnapResult, snapToGrid, Vec2} from './plan-geometry';
import {RoomDetectError} from './room-detect';

/** What a click in the editor's top view does: select and drag, draw a run of walls or a railing, or outline a room. */
export type EditorTool = 'select' | 'drawWalls' | 'detectRoom' | 'drawRailing';

/** Directions of drawn walls snap to multiples of this, degrees. */
export const DrawAngleStep = 15;

/** What the active drawing tool shows next to the view: the segment being drawn, or the room under the pointer. */
export type ToolStatus =
    | {
          tool: 'drawWalls';
          /** Points of the run placed so far. */
          points: number;
          /** Length (mm) and direction (degrees counter-clockwise from east) of the segment to the pointer; null before the first point. */
          length: number | null;
          angle: number | null;
          snap: SnapResult['kind'] | null;
          /** Arc mode: every segment gets a curve, set by one more click (or typed) once its end is placed. */
          arc: boolean;
          /** The curve (sagitta, mm) of the segment waiting for it; null when none waits. */
          sagitta: number | null;
      }
    | {
          tool: 'drawRailing';
          points: number;
          length: number | null;
          angle: number | null;
          snap: SnapResult['kind'] | null;
      }
    | {
          tool: 'detectRoom';
          /** Area (m²) of the room a click would add, or why there is none (null before the pointer is over the floor). */
          area: number | null;
          error: RoomDetectError | null;
      };

export interface DrawSnapOptions {
    /** Grid step, mm. */
    grid: number;
    /** Snap distance, mm; 0 turns snapping off (Shift). */
    radius: number;
    walls: readonly Wall[];
    /** Points of the run placed so far. */
    points: readonly Vec2[];
    /** Walls of the floor below while it is traced (`ghost.ts`): their ends and centre lines are snapped to as well. */
    ghostWalls?: readonly Wall[];
}

/**
 * Where the next point of a run goes: onto a wall end or a point of the run, onto a wall's centre line
 * (a T-joint), in line with another point, at a multiple of 15° from the previous point (with the length
 * on the grid), else onto the grid. The walls of the floor below count as walls of this one.
 */
export function snapDrawPoint(cursor: Vec2, options: DrawSnapOptions): SnapResult {
    const {grid, radius, points} = options;
    const walls = [...options.walls, ...(options.ghostWalls ?? [])];
    const ends = walls.flatMap((w) => [
        {x: w.startX, y: w.startY},
        {x: w.endX, y: w.endY},
    ]);
    const previous = points.at(-1);
    const snapped = snapPoint(cursor, {
        grid,
        radius,
        points: [...ends, ...points],
        lines: walls.flatMap((w) => centreLineSegments(w)),
        anchors: [...points, ...ends],
    });
    if (snapped.kind !== 'grid' || previous === undefined || radius === 0) {
        return snapped;
    }

    const length = Math.hypot(cursor.x - previous.x, cursor.y - previous.y);
    const angle = Math.round(directionOf(previous, cursor) / DrawAngleStep) * DrawAngleStep;
    const turned = pointAt(previous, snapToGrid(length, grid), angle);
    return Math.hypot(turned.x - cursor.x, turned.y - cursor.y) <= radius ? {point: turned, kind: 'angle'} : snapped;
}

/** The point `length` mm from `from` in the direction `angle` (degrees counter-clockwise from east), in whole mm. */
export function pointAt(from: Vec2, length: number, angle: number): Vec2 {
    const radians = (angle * Math.PI) / 180;
    return {x: Math.round(from.x + length * Math.cos(radians)) + 0, y: Math.round(from.y + length * Math.sin(radians)) + 0};
}

/** Direction from one point to another, degrees counter-clockwise from east in [0, 360). */
export function directionOf(from: Vec2, to: Vec2): number {
    const angle = (Math.atan2(to.y - from.y, to.x - from.x) * 180) / Math.PI;
    return angle < 0 ? angle + 360 : angle;
}

/**
 * Typed segment input: `3200` (a length in mm along the current direction) or `3200<90` (length and
 * direction in degrees counter-clockwise from east), either followed by `~400` for a curved wall (its sagitta, mm, + to
 * the left). Null when it is not one of these.
 */
export function parseDrawInput(text: string): {length: number; angle: number | null; sagitta: number | null} | null {
    const match = /^\s*(\d+(?:\.\d+)?)\s*(?:<\s*(-?\d+(?:\.\d+)?)\s*)?(?:~\s*(-?\d+)\s*)?$/.exec(text);
    if (match === null) {
        return null;
    }
    const length = Math.round(Number(match[1]));
    return length > 0
        ? {length, angle: match[2] === undefined ? null : Number(match[2]), sagitta: match[3] === undefined ? null : Number(match[3])}
        : null;
}

/**
 * The sagitta (mm, + to the left of a → b) that puts the middle of the arc from a to b at `point`'s distance off the
 * chord: at most a semicircle; straight within `radius`, else on the grid.
 */
export function sagittaToward(a: Vec2, b: Vec2, point: Vec2, grid: number, radius: number): number {
    const chord = Math.hypot(b.x - a.x, b.y - a.y);
    if (chord === 0) {
        return 0;
    }
    const off = ((point.x - (a.x + b.x) / 2) * -(b.y - a.y) + (point.y - (a.y + b.y) / 2) * (b.x - a.x)) / chord;
    const limit = Math.floor(chord / 2);
    if (Math.abs(off) <= radius) {
        return 0;
    }
    return Math.max(-limit, Math.min(limit, snapToGrid(off, grid)));
}

/** Whether placing `point` closes the run: it is back at the first point and at least three points are placed. */
export function closesRun(points: readonly Vec2[], point: Vec2): boolean {
    return points.length >= 3 && points[0].x === point.x && points[0].y === point.y;
}
