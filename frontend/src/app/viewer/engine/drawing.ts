import {Wall} from '@app/models/scene';

import {snapPoint, SnapResult, snapToGrid, Vec2} from './plan-geometry';
import {RoomDetectError} from './room-detect';

/** What a click in the editor's top view does: select and drag, draw a run of walls, or outline a room. */
export type EditorTool = 'select' | 'drawWalls' | 'detectRoom';

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
}

/**
 * Where the next point of a run goes: onto a wall end or a point of the run, onto a wall's centre line
 * (a T-joint), in line with another point, at a multiple of 15° from the previous point (with the length
 * on the grid), else onto the grid.
 */
export function snapDrawPoint(cursor: Vec2, options: DrawSnapOptions): SnapResult {
    const {grid, radius, walls, points} = options;
    const ends = walls.flatMap((w) => [
        {x: w.startX, y: w.startY},
        {x: w.endX, y: w.endY},
    ]);
    const previous = points.at(-1);
    const snapped = snapPoint(cursor, {
        grid,
        radius,
        points: [...ends, ...points],
        lines: walls.map((w) => [{x: w.startX, y: w.startY}, {x: w.endX, y: w.endY}] as const),
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
 * direction in degrees counter-clockwise from east). Null when it is not one of these.
 */
export function parseDrawInput(text: string): {length: number; angle: number | null} | null {
    const match = /^\s*(\d+(?:\.\d+)?)\s*(?:<\s*(-?\d+(?:\.\d+)?)\s*)?$/.exec(text);
    if (match === null) {
        return null;
    }
    const length = Math.round(Number(match[1]));
    return length > 0 ? {length, angle: match[2] === undefined ? null : Number(match[2])} : null;
}

/** Whether placing `point` closes the run: it is back at the first point and at least three points are placed. */
export function closesRun(points: readonly Vec2[], point: Vec2): boolean {
    return points.length >= 3 && points[0].x === point.x && points[0].y === point.y;
}
