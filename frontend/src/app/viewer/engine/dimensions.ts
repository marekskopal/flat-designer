import {Room, Wall} from '@app/models/scene';
import * as THREE from 'three';
import {CSS2DObject} from 'three/examples/jsm/renderers/CSS2DRenderer.js';

import {EdgeRun, edgeRuns, joinedAlong, wallSpansAlongEdge} from './ceilings';
import {alongWall, MM, pointOnWall, Vec2, wallFrame, WallGap, wallGapsAlongEdge, wallSamples} from './plan-geometry';

/** Distance of a room edge's overall dimension from the wall face, mm (inside the room). */
export const DimensionOffset = 300;
/** Distance of the chain (piers and openings) from the wall face, mm. */
export const ChainOffset = 150;
/** Edges shorter than this get no dimension, mm. */
const MinEdge = 200;
/** Half the length of the ticks at the ends of a dimension, mm. */
const Tick = 60;
const DimensionColor = '#4a4640';

/**
 * One dimension line on the plan: from `a` to `b` (already offset into the room), its length in mm and the unit normal
 * towards the room. Along a curved wall it follows the arc: `path` runs from a to b, and `normal` is the one at its middle.
 */
export interface DimensionLine {
    a: Vec2;
    b: Vec2;
    length: number;
    normal: Vec2;
    path?: Vec2[];
}

/** A dimension line's points from a to b. */
export function dimensionPath(line: DimensionLine): Vec2[] {
    return line.path ?? [line.a, line.b];
}

/** The middle of a dimension line along its path, and its direction there (unit vector towards b). */
export function dimensionMiddle(line: DimensionLine): {at: Vec2; dir: Vec2} {
    const points = dimensionPath(line);
    const steps = points.slice(1).map((p, i) => Math.hypot(p.x - points[i].x, p.y - points[i].y));
    let rest = steps.reduce((sum, step) => sum + step, 0) / 2;
    for (let i = 0; i < steps.length; i++) {
        if (rest <= steps[i] || i === steps.length - 1) {
            const [p, q] = [points[i], points[i + 1]];
            const t = steps[i] > 0 ? Math.min(rest / steps[i], 1) : 0;
            const dir = steps[i] > 0 ? {x: (q.x - p.x) / steps[i], y: (q.y - p.y) / steps[i]} : {x: 1, y: 0};
            return {at: {x: p.x + (q.x - p.x) * t, y: p.y + (q.y - p.y) * t}, dir};
        }
        rest -= steps[i];
    }
    return {at: line.a, dir: {x: 1, y: 0}};
}

/**
 * Inner dimensions of the rooms: for every edge of a room outline (on the inner wall faces), its length
 * offset into the room and, where doors or windows break the wall, the chain of piers and openings closer
 * to the wall; the edges along a curved wall face together, as one line along the arc (`edgeRuns()`). A drawing at a
 * scale passes its own offsets (mm into the room); `walledOnly` leaves out the edges without a wall along them (where
 * open-plan rooms meet).
 */
export function roomDimensions(
    rooms: readonly Pick<Room, 'polygon'>[],
    walls: readonly Wall[],
    {overall = DimensionOffset, chain = ChainOffset, walledOnly = false}: {overall?: number; chain?: number; walledOnly?: boolean} = {},
): DimensionLine[] {
    const lines: DimensionLine[] = [];
    for (const room of rooms) {
        for (const run of edgeRuns(room.polygon, walls)) {
            if (run.curve !== null && run.edges.length > 1) {
                lines.push(...curvedDimensions(run, run.curve, walls, overall, chain));
                continue;
            }
            const {a, b, length} = run.edges[0];
            if (length < MinEdge || (walledOnly && wallSpansAlongEdge(a, b, walls).length === 0)) {
                continue;
            }
            const dir = {x: (b.x - a.x) / length, y: (b.y - a.y) / length};
            // Left of a counter-clockwise edge is inside.
            const normal = {x: -dir.y, y: dir.x};
            const at = (along: number, offset: number): Vec2 => ({
                x: a.x + dir.x * along + normal.x * offset,
                y: a.y + dir.y * along + normal.y * offset,
            });
            lines.push({a: at(0, overall), b: at(length, overall), length: Math.round(length), normal});

            const gaps = wallGapsAlongEdge(a, b, walls).filter((gap) => !gap.niche);
            for (const [from, to] of chainStretches(gaps, length)) {
                lines.push({a: at(from, chain), b: at(to, chain), length: to - from, normal});
            }
        }
    }
    return lines;
}

/** The stretches of a chain of piers and openings along `length` (whole mm), empty without openings. */
function chainStretches(gaps: readonly WallGap[], length: number): [number, number][] {
    if (gaps.length === 0) {
        return [];
    }
    const stops = [...new Set([0, length, ...gaps.flatMap((g) => [g.from, g.to])].map((t) => Math.round(t)))].sort((p, q) => p - q);
    return stops.slice(1).flatMap((to, j): [number, number][] => (to - stops[j] >= 1 ? [[stops[j], to]] : []));
}

/**
 * The dimensions of the room edges along a curved wall face: their whole length as one line following the arc, and the
 * chain of its openings.
 */
function curvedDimensions(
    {edges, offsets, length}: EdgeRun,
    curve: NonNullable<EdgeRun['curve']>,
    walls: readonly Wall[],
    overall: number,
    chain: number,
): DimensionLine[] {
    const frame = wallFrame(curve.wall);
    const across = curve.side === 'Left' ? curve.wall.thickness / 2 : -curve.wall.thickness / 2;
    const from = alongWall(frame, edges[0].a);
    const to = alongWall(frame, edges[edges.length - 1].b);
    // A stretch s0..s1 of the run (mm along it) as a line `offset` into the room, along the arc.
    const line = (s0: number, s1: number, offset: number, value: number): DimensionLine => {
        const t0 = from + ((to - from) * s0) / length;
        const t1 = from + ((to - from) * s1) / length;
        const samples = wallSamples(frame, Math.min(t0, t1), Math.max(t0, t1));
        const path = (t0 <= t1 ? samples : samples.reverse()).map((t) => pointOnWall(frame, t, across + Math.sign(across) * offset));
        const middle = pointOnWall(frame, (t0 + t1) / 2, across);
        const inner = pointOnWall(frame, (t0 + t1) / 2, across + Math.sign(across));
        return {a: path[0], b: path[path.length - 1], length: value, normal: {x: inner.x - middle.x, y: inner.y - middle.y}, path};
    };
    const gaps = edges
        .flatMap((edge, i) => wallGapsAlongEdge(edge.a, edge.b, walls).map((gap) => ({...gap, from: gap.from + offsets[i], to: gap.to + offsets[i]})))
        .filter((gap) => !gap.niche);
    // An opening's gap split where the pieces of the face meet, joined again.
    const joined = joinedAlong(gaps, (p, q) => p.bottom === q.bottom && p.top === q.top);
    return [line(0, length, overall, Math.round(length)), ...chainStretches(joined, length).map(([s0, s1]) => line(s0, s1, chain, s1 - s0))];
}

/**
 * Lines with ticks at the floor (`y` metres up) and a CSS2D label with the length at each midpoint; turn the
 * labels along their lines with `alignDimensionLabels()`.
 */
export function buildDimensions(lines: readonly DimensionLine[], y = 0.03): THREE.Group {
    const group = new THREE.Group();
    group.name = 'dimensions';
    const points: number[] = [];
    const push = (p: Vec2): void => {
        points.push(p.x * MM, y, -p.y * MM);
    };
    for (const line of lines) {
        const path = dimensionPath(line);
        path.slice(1).forEach((p, i) => {
            push(path[i]);
            push(p);
        });
        // Ticks across the line at its ends.
        for (const [end, next] of [
            [path[0], path[1]],
            [path[path.length - 1], path[path.length - 2]],
        ]) {
            const step = Math.hypot(next.x - end.x, next.y - end.y) || 1;
            const across = {x: -(next.y - end.y) / step, y: (next.x - end.x) / step};
            push({x: end.x - across.x * Tick, y: end.y - across.y * Tick});
            push({x: end.x + across.x * Tick, y: end.y + across.y * Tick});
        }

        const element = document.createElement('div');
        element.className = 'dim-label';
        element.textContent = String(line.length);
        const label = new CSS2DObject(element);
        const middle = dimensionMiddle(line).at;
        label.position.set(middle.x * MM, y, -middle.y * MM);
        label.userData = {dimension: line};
        group.add(label);
    }
    const geometry = new THREE.BufferGeometry();
    geometry.setAttribute('position', new THREE.Float32BufferAttribute(points, 3));
    const segments = new THREE.LineSegments(geometry, new THREE.LineBasicMaterial({color: DimensionColor}));
    segments.raycast = (): void => undefined;
    group.add(segments);
    return group;
}

/** Screen width of a label's digit, px (with its padding: `LabelPadding`), to tell whether it fits beside its line. */
const DigitPixels = 6;
const LabelPadding = 12;

/**
 * Turns the labels of `buildDimensions()` along their lines as the camera shows them (in a view of `width` ×
 * `height` px), never upside down, and hides those whose line is shorter on screen than the label.
 */
export function alignDimensionLabels(group: THREE.Object3D, camera: THREE.Camera, width: number, height: number): void {
    const a = new THREE.Vector3();
    const b = new THREE.Vector3();
    for (const child of group.children) {
        const line = (child.userData as {dimension?: DimensionLine}).dimension;
        if (!(child instanceof CSS2DObject) || line === undefined) {
            continue;
        }
        a.set(line.a.x * MM, 0, -line.a.y * MM).project(camera);
        b.set(line.b.x * MM, 0, -line.b.y * MM).project(camera);
        const dx = ((b.x - a.x) * width) / 2;
        const dy = ((b.y - a.y) * height) / 2;
        child.visible = Math.hypot(dx, dy) >= String(line.length).length * DigitPixels + LabelPadding;
        let angle = Math.atan2(dy, dx);
        if (angle > Math.PI / 2) {
            angle -= Math.PI;
        } else if (angle <= -Math.PI / 2) {
            angle += Math.PI;
        }
        child.rotation2D = angle;
    }
}
