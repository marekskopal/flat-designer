import {FloorVoid, PlanPoint, Room, Scene, Stair} from '@app/models/scene';
import polygonClipping, {Pair} from 'polygon-clipping';
import * as THREE from 'three';

import {ceilingHeightAt} from './ceilings';
import {MaterialLibrary} from './materials';
import {mergeStatic} from './merge';
import {counterClockwise, MM, pointInPolygon, polygonCentroid, Vec2, WallCollider, WallFrame, wallFrame} from './plan-geometry';
import {stairGeometry,StairPath} from './stairs';
import {FloorRoofs} from './under-roof';

/**
 * A room's floor area less the voids through it (FD-90), m², as the backend's room schedule takes it (`VoidArea`): the
 * voids are taken not to overlap one another.
 */
export function netRoomArea(room: Pick<Room, 'area' | 'polygon'>, voids: readonly Pick<FloorVoid, 'polygon'>[]): number {
    if (voids.length === 0) {
        return room.area;
    }
    const ring = (polygon: readonly PlanPoint[]): Pair[] => polygon.map(([x, y]): Pair => [x, y]);
    let taken = 0;
    for (const floorVoid of voids) {
        for (const piece of polygonClipping.intersection([ring(room.polygon)], [ring(floorVoid.polygon)])) {
            taken += piece.reduce((sum, r, i) => sum + (i === 0 ? 1 : -1) * Math.abs(ringArea(r)), 0);
        }
    }
    return Math.max(0, Math.round((room.area - taken / 1e6) * 100) / 100);
}

function ringArea(ring: readonly Pair[]): number {
    let twice = 0;
    ring.forEach(([x0, y0], i) => {
        const [x1, y1] = ring[(i + 1) % ring.length];
        twice += x0 * y1 - x1 * y0;
    });
    return twice / 2;
}

/** mm, the lining boards round a void's edges. */
export const LiningThickness = 20;
/** Half the thickness of a void edge's collider, mm. */
const ColliderHalf = 20;

const OutlineColor = '#5d6670';
const SelectedOutlineColor = '#2f7de1';

/** A void to line: its outline (plan mm) and the band's bottom and top, mm against the floor's level. */
interface LiningBand {
    polygon: readonly PlanPoint[];
    bottom: number;
    top: number;
    materialId: number;
}

/**
 * The linings of the voids through and over the floor (FD-90), in one merged group: the floor's own voids and the
 * stairs coming up through it (`stairsBelow` with a `voidLiningMaterialId`) are lined through its slab, down to the
 * lining's depth; the floor above's voids (`voidsAbove`) and its own stairs' voids from this floor's ceilings up to
 * the slab above, below the depth a void's lining stops at. So each floor draws its part of the well, and the stack
 * draws all of it.
 */
export function buildVoidLinings(
    scene: Pick<Scene, 'floor' | 'rooms' | 'voids' | 'voidsAbove' | 'stairs' | 'stairRise' | 'stairsBelow' | 'roofs'>,
    materials: MaterialLibrary,
): THREE.Group {
    const group = new THREE.Group();
    group.name = 'void-linings';
    for (const band of liningBands(scene)) {
        if (band.top - band.bottom >= 1) {
            group.add(liningRing(band, materials.get(band.materialId)));
        }
    }
    mergeStatic(group);
    return group;
}

function liningBands(
    scene: Pick<Scene, 'floor' | 'rooms' | 'voids' | 'voidsAbove' | 'stairs' | 'stairRise' | 'stairsBelow' | 'roofs'>,
): LiningBand[] {
    const slab = scene.floor.slabThickness;
    const bands: LiningBand[] = [];
    for (const floorVoid of scene.voids ?? []) {
        if (floorVoid.liningMaterialId !== null) {
            const depth = Math.min(floorVoid.liningDepth ?? slab, slab);
            bands.push({polygon: floorVoid.polygon, bottom: -depth, top: 0, materialId: floorVoid.liningMaterialId});
        }
    }
    const below = scene.stairsBelow ?? null;
    for (const stair of below?.stairs ?? []) {
        if (stair.voidLiningMaterialId != null) {
            bands.push({polygon: footprint(stair, below?.rise ?? 0), bottom: -slab, top: 0, materialId: stair.voidLiningMaterialId});
        }
    }
    const above = scene.voidsAbove ?? null;
    if (above === null) {
        return bands;
    }
    const soffit = above.rise - above.slabThickness;
    for (const floorVoid of above.voids) {
        if (floorVoid.liningMaterialId !== null) {
            const ceiling = ceilingUnder(scene, floorVoid.polygon);
            const bottom = Math.max(ceiling ?? soffit, above.rise - (floorVoid.liningDepth ?? Infinity));
            bands.push({polygon: floorVoid.polygon, bottom, top: soffit, materialId: floorVoid.liningMaterialId});
        }
    }
    for (const stair of scene.stairs ?? []) {
        if (stair.voidLiningMaterialId != null && stair.rise == null) {
            const polygon = footprint(stair, scene.stairRise ?? 0);
            bands.push({polygon, bottom: ceilingUnder(scene, polygon) ?? soffit, top: soffit, materialId: stair.voidLiningMaterialId});
        }
    }
    return bands;
}

function footprint(stair: Stair, rise: number): PlanPoint[] {
    return stairGeometry(stair, rise).footprint.map((p): PlanPoint => [p.x, p.y]);
}

/**
 * The lowest ceiling of the floor's rooms round a void's outline (its corners and centre), mm against the floor's
 * level; null where no room with a ceiling lies under it.
 */
export function ceilingUnder(scene: Pick<Scene, 'rooms' | 'roofs'>, polygon: readonly PlanPoint[]): number | null {
    const roofs: FloorRoofs = scene.roofs ?? [];
    const centre = polygonCentroid(polygon);
    // Just inside each corner, so a corner on the room's edge counts that room.
    const probes: Vec2[] = [centre, ...polygon.map(([x, y]) => ({x: x + (centre.x - x) * 0.02, y: y + (centre.y - y) * 0.02}))];
    let lowest: number | null = null;
    for (const p of probes) {
        const room = scene.rooms.find((r) => r.ceilingHeight !== null && pointInPolygon(p, r.polygon));
        const height = room === undefined ? null : ceilingHeightAt(room, p.x, p.y, roofs);
        if (room !== undefined && height !== null) {
            const level = height + (room.floorElevation ?? 0);
            lowest = lowest === null ? level : Math.min(lowest, level);
        }
    }
    return lowest;
}

/** A lining band: the outline less the outline moved `LiningThickness` in, extruded from its bottom to its top. */
function liningRing(band: LiningBand, material: THREE.Material): THREE.Mesh {
    const outer = counterClockwise([...band.polygon]).map(([x, y]) => ({x, y}));
    const inner = insetOutline(outer, LiningThickness);
    const shape = new THREE.Shape(outer.map((p) => new THREE.Vector2(p.x * MM, p.y * MM)));
    shape.holes.push(new THREE.Path(inner.map((p) => new THREE.Vector2(p.x * MM, p.y * MM))));
    const geometry = new THREE.ExtrudeGeometry(shape, {depth: (band.top - band.bottom) * MM, bevelEnabled: false});
    geometry.rotateX(-Math.PI / 2);
    geometry.translate(0, band.bottom * MM, 0);
    const mesh = new THREE.Mesh(geometry, material);
    mesh.castShadow = true;
    mesh.receiveShadow = true;
    return mesh;
}

/** A counter-clockwise outline with every edge moved `distance` mm in (to its left), mitred at the corners. */
export function insetOutline(outline: readonly Vec2[], distance: number): Vec2[] {
    const count = outline.length;
    const normal = (i: number): Vec2 => {
        const a = outline[i];
        const b = outline[(i + 1) % count];
        const length = Math.hypot(b.x - a.x, b.y - a.y) || 1;
        return {x: -(b.y - a.y) / length, y: (b.x - a.x) / length};
    };
    return outline.map((p, i) => {
        const before = normal((i + count - 1) % count);
        const after = normal(i);
        const mx = before.x + after.x;
        const my = before.y + after.y;
        const m = Math.hypot(mx, my);
        if (m < 1e-6) {
            return {x: p.x + before.x * distance, y: p.y + before.y * distance};
        }
        const scale = distance / ((mx / m) * before.x + (my / m) * before.y);
        return {x: p.x + (mx / m) * scale, y: p.y + (my / m) * scale};
    });
}

/**
 * A void in the top view: its outline with the diagonals of the plan's void symbol, drawn over the plan without a depth
 * test just above the floor, and an undrawn face over it that picks it. Tagged `voidId`; `planOnly` keeps it out of
 * exports.
 */
export function buildVoidOutline(floorVoid: FloorVoid): THREE.Group {
    const group = new THREE.Group();
    group.name = `void-outline:${floorVoid.name}`;
    group.userData = {voidId: floorVoid.id, planOnly: true};
    const flat = ([x, y]: readonly [number, number]): THREE.Vector3 => new THREE.Vector3(x * MM, 0.01, -y * MM);
    const material = new THREE.LineDashedMaterial({color: OutlineColor, dashSize: 0.2, gapSize: 0.1, depthTest: false, transparent: true});
    const outline = new THREE.LineLoop(new THREE.BufferGeometry().setFromPoints(floorVoid.polygon.map(flat)), material);
    outline.computeLineDistances();
    const points = floorVoid.polygon;
    const cross = new THREE.LineSegments(new THREE.BufferGeometry().setFromPoints(voidDiagonals(points).flat().map(flat)), material);
    cross.computeLineDistances();
    for (const line of [outline, cross]) {
        line.renderOrder = 5;
        line.userData = {voidLine: true};
        line.raycast = (): void => undefined;
        group.add(line);
    }
    const face = new THREE.Shape(points.map(([x, y]) => new THREE.Vector2(x * MM, y * MM)));
    const pick = new THREE.Mesh(new THREE.ShapeGeometry(face).rotateX(-Math.PI / 2), new THREE.MeshBasicMaterial({visible: false}));
    pick.position.y = 0.01;
    group.add(pick);
    return group;
}

/**
 * The cross a void has on a plan: the two diagonals between the outline's corners nearest the corners of its bounding
 * box (the diagonals themselves for a rectangle).
 */
export function voidDiagonals(points: readonly PlanPoint[]): [PlanPoint, PlanPoint][] {
    const xs = points.map(([x]) => x);
    const ys = points.map(([, y]) => y);
    const [minX, maxX, minY, maxY] = [Math.min(...xs), Math.max(...xs), Math.min(...ys), Math.max(...ys)];
    return [
        [nearestCorner(points, [minX, minY]), nearestCorner(points, [maxX, maxY])],
        [nearestCorner(points, [maxX, minY]), nearestCorner(points, [minX, maxY])],
    ];
}

function nearestCorner(points: readonly PlanPoint[], [x, y]: [number, number]): PlanPoint {
    let best = points[0];
    for (const p of points) {
        if (Math.hypot(p[0] - x, p[1] - y) < Math.hypot(best[0] - x, best[1] - y)) {
            best = p;
        }
    }
    return [best[0], best[1]];
}

/** Colours a void's outline as selected or not. */
export function highlightVoidOutline(group: THREE.Object3D, selected: boolean): void {
    group.traverse((object) => {
        if (object instanceof THREE.Line && object.userData['voidLine'] === true) {
            (object.material as THREE.LineBasicMaterial).color.set(selected ? SelectedOutlineColor : OutlineColor);
        }
    });
}

/** mm either side of an arriving stair's footprint that its edge of the void stays open. */
const ArrivalMargin = 50;

/**
 * What the walker bumps into at the floor's voids: their edges (it does not walk into a hole), except where a stair
 * from below arrives: the stretch of an edge over its footprint (`ArrivalMargin` either side) is left to the stair's
 * own colliders, which close or open it; the rest of the edge stays closed.
 */
export function voidColliders(voids: readonly FloorVoid[], stairs: readonly StairPath[]): WallCollider[] {
    const arriving = stairs.filter((s) => s.below).map((s) => s.footprint.map((p): PlanPoint => [p.x, p.y]));
    const colliders: WallCollider[] = [];
    for (const floorVoid of voids) {
        floorVoid.polygon.forEach(([x0, y0], i) => {
            const [x1, y1] = floorVoid.polygon[(i + 1) % floorVoid.polygon.length];
            const frame = wallFrame({startX: x0, startY: y0, endX: x1, endY: y1});
            if (frame.length <= 0) {
                return;
            }
            for (const [from, to] of closedStretches(frame, arriving)) {
                colliders.push({frame, from, to, halfThickness: ColliderHalf});
            }
        });
    }
    return colliders;
}

/**
 * The stretches of an edge (mm along `frame`) over none of the `footprints`: the edge is cut where it crosses a
 * footprint's edges, and each piece is open when its middle lies in or within `ArrivalMargin` of one.
 */
function closedStretches(frame: WallFrame, footprints: readonly PlanPoint[][]): [number, number][] {
    const a = frame.start;
    const cuts = new Set<number>([0, frame.length]);
    for (const outline of footprints) {
        outline.forEach((p, i) => {
            const q = outline[(i + 1) % outline.length];
            const t = segmentCrossing(a, frame.dir, frame.length, {x: p[0], y: p[1]}, {x: q[0], y: q[1]});
            if (t !== null) {
                cuts.add(t);
            }
        });
    }
    const stations = [...cuts].sort((s, t) => s - t);
    const stretches: [number, number][] = [];
    for (let i = 1; i < stations.length; i++) {
        const [from, to] = [stations[i - 1], stations[i]];
        const middle = {x: a.x + frame.dir.x * ((from + to) / 2), y: a.y + frame.dir.y * ((from + to) / 2)};
        if (to - from > 1e-6 && !footprints.some((f) => pointInPolygon(middle, f) || nearOutline(middle, f))) {
            stretches.push([from, to]);
        }
    }
    return stretches;
}

/** Where the segment from `a` along `dir` for `length` crosses the segment p–q: mm from `a`, or null when it does not. */
function segmentCrossing(a: Vec2, dir: Vec2, length: number, p: Vec2, q: Vec2): number | null {
    const ex = q.x - p.x;
    const ey = q.y - p.y;
    const denominator = dir.x * ey - dir.y * ex;
    if (Math.abs(denominator) < 1e-9) {
        return null;
    }
    const t = ((p.x - a.x) * ey - (p.y - a.y) * ex) / denominator;
    const u = ((p.x - a.x) * dir.y - (p.y - a.y) * dir.x) / denominator;
    return t >= 0 && t <= length && u >= 0 && u <= 1 ? t : null;
}

/** Whether a point lies within `ArrivalMargin` of an outline's edges. */
function nearOutline(point: Vec2, outline: readonly PlanPoint[]): boolean {
    return outline.some(([x0, y0], i) => {
        const [x1, y1] = outline[(i + 1) % outline.length];
        const dx = x1 - x0;
        const dy = y1 - y0;
        const length = dx * dx + dy * dy;
        const t = length === 0 ? 0 : Math.max(0, Math.min(1, ((point.x - x0) * dx + (point.y - y0) * dy) / length));
        return Math.hypot(point.x - (x0 + dx * t), point.y - (y0 + dy * t)) <= ArrivalMargin;
    });
}
