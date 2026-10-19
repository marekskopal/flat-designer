import {Room, Wall} from '@app/models/scene';
import * as THREE from 'three';
import {CSS2DObject} from 'three/examples/jsm/renderers/CSS2DRenderer.js';

import {MM, polygonSignedArea, Vec2, wallGapsAlongEdge} from './plan-geometry';

/** Distance of a room edge's overall dimension from the wall face, mm (inside the room). */
export const DimensionOffset = 300;
/** Distance of the chain (piers and openings) from the wall face, mm. */
export const ChainOffset = 150;
/** Edges shorter than this get no dimension, mm. */
const MinEdge = 200;
/** Half the length of the ticks at the ends of a dimension, mm. */
const Tick = 60;
const DimensionColor = '#4a4640';

/** One dimension line on the plan: from `a` to `b` (already offset into the room), its length in mm and the unit normal towards the room. */
export interface DimensionLine {
    a: Vec2;
    b: Vec2;
    length: number;
    normal: Vec2;
}

/**
 * Inner dimensions of the rooms: for every edge of a room outline (on the inner wall faces), its length
 * offset into the room and, where doors or windows break the wall, the chain of piers and openings closer
 * to the wall.
 */
export function roomDimensions(rooms: readonly Pick<Room, 'polygon'>[], walls: readonly Wall[]): DimensionLine[] {
    const lines: DimensionLine[] = [];
    for (const room of rooms) {
        const ccw = polygonSignedArea(room.polygon) >= 0 ? room.polygon : [...room.polygon].reverse();
        ccw.forEach(([x, y], i) => {
            const [nx, ny] = ccw[(i + 1) % ccw.length];
            const a = {x, y};
            const b = {x: nx, y: ny};
            const length = Math.hypot(b.x - a.x, b.y - a.y);
            if (length < MinEdge) {
                return;
            }
            const dir = {x: (b.x - a.x) / length, y: (b.y - a.y) / length};
            // Left of a counter-clockwise edge is inside.
            const normal = {x: -dir.y, y: dir.x};
            const at = (along: number, offset: number): Vec2 => ({
                x: a.x + dir.x * along + normal.x * offset,
                y: a.y + dir.y * along + normal.y * offset,
            });
            lines.push({a: at(0, DimensionOffset), b: at(length, DimensionOffset), length: Math.round(length), normal});

            const gaps = wallGapsAlongEdge(a, b, walls);
            if (gaps.length === 0) {
                return;
            }
            const stops = [...new Set([0, length, ...gaps.flatMap((g) => [g.from, g.to])].map((t) => Math.round(t)))].sort((p, q) => p - q);
            stops.slice(1).forEach((to, j) => {
                const from = stops[j];
                if (to - from >= 1) {
                    lines.push({a: at(from, ChainOffset), b: at(to, ChainOffset), length: to - from, normal});
                }
            });
        });
    }
    return lines;
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
        push(line.a);
        push(line.b);
        for (const end of [line.a, line.b]) {
            push({x: end.x - line.normal.x * Tick, y: end.y - line.normal.y * Tick});
            push({x: end.x + line.normal.x * Tick, y: end.y + line.normal.y * Tick});
        }

        const element = document.createElement('div');
        element.className = 'dim-label';
        element.textContent = String(line.length);
        const label = new CSS2DObject(element);
        label.position.set(((line.a.x + line.b.x) / 2) * MM, y, (-(line.a.y + line.b.y) / 2) * MM);
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
