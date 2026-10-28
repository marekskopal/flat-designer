import {PlanPoint, RoofWindow, Room, Wall} from '@app/models/scene';
import polygonClipping, {MultiPolygon, Pair, Polygon, Ring} from 'polygon-clipping';

import {Bounds, polygonBounds, Vec2} from './plan-geometry';
import {roofHalfExtent, roofLocal, roofPlanes, RoofShapeFields, roofsUndersideAt, roofToPlan, roofWindowLayouts} from './roof-geometry';

/**
 * What stands under the roofs of a floor (FD-38): the walls' tops, cut by the roofs' underside or rising to it, and the
 * rooms' ceilings, sloping with it where it comes lower than their ceiling height. Heights are mm above the floor's level.
 * TS only: the backend checks openings against the roofs' underside (`RoofGeometry`), it draws nothing.
 *
 * Every such height is piecewise linear along a line in plan: the roofs' planes are, and so are the highest of them and
 * a cap. `roofBreaks()` finds where along a line the pieces meet, so a mesh with vertices there follows it exactly.
 */

/** mm: heights closer than this count as the same. */
const Same = 0.5;

const lerp = (a: Vec2, b: Vec2, t: number): Vec2 => ({x: a.x + (b.x - a.x) * t, y: a.y + (b.y - a.y) * t});

/** mm by which a sloped ceiling lies under the roof's underside, so the two do not flicker where both are shown. */
export const CeilingUnderRoof = 10;

/** A height at a plan point. */
export type HeightAt = (p: Vec2) => number;

/** The roofs whose underside a floor's walls and ceilings meet (with their windows, cut out of sloped ceilings). */
export type FloorRoofs = readonly (RoofShapeFields & {windows?: readonly RoofWindow[]})[];

/**
 * The top of a wall at a plan point: its height, cut by the roofs' underside where that is lower, or with `upToRoof`
 * the underside wherever a roof is over it. Null without roofs, as the wall is then its height everywhere.
 */
export function wallTopAt(wall: Pick<Wall, 'height' | 'upToRoof'>, roofs: FloorRoofs): HeightAt | null {
    if (roofs.length === 0) {
        return null;
    }
    return (p) => {
        const underside = roofsUndersideAt(roofs, p.x, p.y);
        if (underside === null) {
            return wall.height;
        }
        return wall.upToRoof === true ? underside : Math.min(wall.height, underside);
    };
}

/** A linear function of plan points, `k + g · p`. */
interface Linear {
    k: number;
    g: Vec2;
}

interface PlaneFunction {
    polygon: Vec2[];
    bounds: Bounds;
    f: Linear;
}

/** The planes once per roof object: a scene keeps its roofs until they change, and a build asks many times. */
const planeCache = new WeakMap<RoofShapeFields, PlaneFunction[]>();

/** Each plane of the roofs as a linear function of plan points (its underside), with its outline. */
function planeFunctions(roof: RoofShapeFields): PlaneFunction[] {
    let planes = planeCache.get(roof);
    if (planes === undefined) {
        planes = roofPlanes(roof).map((plane) => ({
            polygon: plane.polygon,
            bounds: polygonBounds(plane.polygon.map((p): PlanPoint => [p.x, p.y])),
            f: {k: plane.height - plane.gradient.x * roof.x - plane.gradient.y * roof.y, g: plane.gradient},
        }));
        planeCache.set(roof, planes);
    }
    return planes;
}

const overlap = (a: Bounds, b: Bounds, margin = 1): boolean =>
    a.minX <= b.maxX + margin && b.minX <= a.maxX + margin && a.minY <= b.maxY + margin && b.minY <= a.maxY + margin;

/** Whether any of the roofs (their outlines with the overhangs) reaches the plan rectangle `bounds`. */
export function roofsReach(bounds: Bounds, roofs: FloorRoofs): boolean {
    return roofs.some((roof) => planeFunctions(roof).some((plane) => overlap(plane.bounds, bounds)));
}

/** The lowest `top` along p → q: a top under the roofs is piecewise linear, so it is lowest at a break or an end. */
export function lowestTopAlong(p: Vec2, q: Vec2, top: HeightAt, roofs: FloorRoofs, levels: readonly number[] = []): number {
    return Math.min(...roofBreaks(p, q, roofs, levels).map((t) => top(lerp(p, q, t))));
}

const valueOf = (f: Linear, p: Vec2): number => f.k + f.g.x * p.x + f.g.y * p.y;

/**
 * Where along the line p → q (0–1) a height under the roofs may bend or step: where the line crosses a roof's edge,
 * where two planes meet (a ridge, a hip, two roofs) and where a plane reaches one of `levels` (a wall's height, a
 * ceiling's). Ascending, with 0 and 1; more than needed is harmless, as a height is linear between them.
 */
export function roofBreaks(p: Vec2, q: Vec2, roofs: FloorRoofs, levels: readonly number[]): number[] {
    const ts = [0, 1];
    const push = (t: number): void => {
        if (Number.isFinite(t) && t > 0 && t < 1) {
            ts.push(t);
        }
    };
    const lines: [number, number][] = levels.map((level) => [level, 0]);
    for (const roof of roofs) {
        const a = roofLocal(roof, p.x, p.y);
        const b = roofLocal(roof, q.x, q.y);
        const half = roofHalfExtent(roof);
        for (const [from, to, edge] of [
            [a.x, b.x, half.x],
            [a.y, b.y, half.y],
        ]) {
            if (to !== from) {
                push((edge - from) / (to - from));
                push((-edge - from) / (to - from));
            }
        }
        for (const {f} of planeFunctions(roof)) {
            const at = valueOf(f, p);
            lines.push([at, valueOf(f, q) - at]);
        }
    }
    for (let i = 0; i < lines.length; i++) {
        for (let j = i + 1; j < lines.length; j++) {
            const slope = lines[i][1] - lines[j][1];
            if (Math.abs(slope) > 1e-9) {
                push((lines[j][0] - lines[i][0]) / slope);
            }
        }
    }
    const length = Math.hypot(q.x - p.x, q.y - p.y);
    const sorted = ts.sort((x, y) => x - y);
    // Breaks closer than a hundredth of a mm are one.
    return sorted.filter((t, i) => i === 0 || (t - sorted[i - 1]) * length > 0.01 || t === 1);
}

/** A stretch of a solid under the roofs: its two faces' points in plan and the top over each point. */
export interface ProfileRun {
    right: Vec2[];
    left: Vec2[];
    rightTop: number[];
    leftTop: number[];
}

/**
 * An upright solid between two faces (`right`, `left`: polylines in plan with as many points, as `curvedPrismAt()` takes
 * them) under a top following `top`, cut where the top bends (`roofBreaks()` on either face) and split where it steps (at
 * a roof's edge: each run then ends in a face of its own).
 */
export function profileRuns(
    right: readonly Vec2[],
    left: readonly Vec2[],
    top: HeightAt,
    roofs: FloorRoofs,
    levels: readonly number[],
): ProfileRun[] {
    const stations: {r: Vec2; l: Vec2}[] = [];
    for (let i = 1; i < right.length; i++) {
        const us = [...roofBreaks(right[i - 1], right[i], roofs, levels), ...roofBreaks(left[i - 1], left[i], roofs, levels)]
            .sort((x, y) => x - y)
            .filter((u, k, all) => k === 0 || u - all[k - 1] > 1e-7);
        for (const u of us) {
            if (u === 0 && i > 1) {
                continue;
            }
            stations.push({r: lerp(right[i - 1], right[i], u), l: lerp(left[i - 1], left[i], u)});
        }
    }

    const runs: ProfileRun[] = [];
    let run: ProfileRun = {right: [], left: [], rightTop: [], leftTop: []};
    const add = (station: {r: Vec2; l: Vec2}, r: number, l: number): void => {
        run.right.push(station.r);
        run.left.push(station.l);
        run.rightTop.push(r);
        run.leftTop.push(l);
    };
    // The top just before and just after a station, from the neighbouring stations.
    const nudge = 1e-6;
    stations.forEach((station, k) => {
        const previous = stations[k - 1];
        const next = stations[k + 1];
        const before = previous === undefined ? null : [top(lerp(station.r, previous.r, nudge)), top(lerp(station.l, previous.l, nudge))];
        const after = next === undefined ? null : [top(lerp(station.r, next.r, nudge)), top(lerp(station.l, next.l, nudge))];
        if (before !== null && after !== null && (Math.abs(before[0] - after[0]) > Same || Math.abs(before[1] - after[1]) > Same)) {
            add(station, before[0], before[1]);
            runs.push(run);
            run = {right: [], left: [], rightTop: [], leftTop: []};
            add(station, after[0], after[1]);
            return;
        }
        const at = before ?? after ?? [top(station.r), top(station.l)];
        add(station, at[0], at[1]);
    });
    runs.push(run);
    return runs.filter((r) => r.right.length >= 2);
}

/** Whether every top of the runs is `height` (the solid is then a plain one). */
export function runsAreLevel(runs: readonly ProfileRun[], height: number): boolean {
    return runs.every((run) => [...run.rightTop, ...run.leftTop].every((h) => Math.abs(h - height) <= Same));
}

/**
 * The ceiling of a room at a plan point, mm above the floor's level: its ceiling height (from its own floor), or under a
 * roof coming lower the roof's underside less `CeilingUnderRoof`. Null for an open-air room. A vaulted ceiling is not cut
 * by the roofs.
 */
export function roomCeilingAt(room: Room, roofs: FloorRoofs, p: Vec2): number | null {
    if (room.ceilingHeight === null) {
        return null;
    }
    const flat = room.ceilingHeight + (room.floorElevation ?? 0);
    if (room.vaultSpacing != null) {
        return flat;
    }
    const underside = roofsUndersideAt(roofs, p.x, p.y);
    return underside === null ? flat : Math.min(flat, underside - CeilingUnderRoof);
}

/** A part of a room's ceiling: its outline in plan with holes, and its height (mm above the floor's level) at a point. */
export interface CeilingPart {
    outer: Vec2[];
    holes: Vec2[][];
    heightAt: HeightAt;
}

/** mm: how far the half-planes of `belowLine()` reach. */
const Far = 1e7;

/** The half-plane `g · p < c`, as a large polygon (all of the plan or nothing when g is 0). */
function belowLine(g: Vec2, c: number): MultiPolygon {
    const length = Math.hypot(g.x, g.y);
    if (length < 1e-12) {
        return c > 0 ? [[[[-Far, -Far], [Far, -Far], [Far, Far], [-Far, Far], [-Far, -Far]]]] : [];
    }
    const n = {x: g.x / length, y: g.y / length};
    const d = c / length;
    const o = {x: n.x * d, y: n.y * d};
    const t = {x: -n.y, y: n.x};
    const ring: Ring = [
        [o.x + t.x * Far, o.y + t.y * Far],
        [o.x - t.x * Far, o.y - t.y * Far],
        [o.x - t.x * Far - n.x * Far, o.y - t.y * Far - n.y * Far],
        [o.x + t.x * Far - n.x * Far, o.y + t.y * Far - n.y * Far],
    ];
    return [[[...ring, ring[0]]]];
}

const ringOf = (points: readonly Vec2[]): Ring => {
    const ring = points.map((p): Pair => [p.x, p.y]);
    return [...ring, ring[0]];
};

/**
 * A room's ceiling under the roofs, in parts: where a roof plane (the highest one where roofs overlap) comes lower than
 * the ceiling height, the part lies on it (`CeilingUnderRoof` under it); the rest is flat at the ceiling height. The
 * outline is `outline` (the room's, its stair voids cut out). Heights count from the floor's level. Null when no roof
 * comes lower anywhere: the ceiling is then the plain flat one.
 */
export function ceilingParts(room: Room, outline: MultiPolygon, roofs: FloorRoofs): CeilingPart[] | null {
    const flatHeight = (room.ceilingHeight ?? 0) + (room.floorElevation ?? 0);
    const bounds = polygonBounds(outline.flat(2) as PlanPoint[]);
    const planes = roofs
        .map((roof, r) => {
            const footprints = windowFootprints(roof);
            const onPlane = (k: number): Vec2[][] => footprints.filter((f) => f.plane === k).map((f) => f.polygon);
            return planeFunctions(roof).map((plane, k) => ({...plane, roof: r, windows: onPlane(k)}));
        })
        .flat()
        .filter((plane) => overlap(plane.bounds, bounds));
    const sloped: {area: MultiPolygon; f: Linear}[] = [];
    for (const plane of planes) {
        // Under the plane, lower than the ceiling height: k + g · p − offset < flat.
        let area = polygonClipping.intersection(
            outline,
            [[ringOf(plane.polygon)]],
            belowLine(plane.f.g, flatHeight + CeilingUnderRoof - plane.f.k),
        );
        // Where another roof's plane is higher, the space reaches that one.
        for (const other of planes.filter((o) => o.roof !== plane.roof)) {
            if (area.length === 0) {
                break;
            }
            const g = {x: plane.f.g.x - other.f.g.x, y: plane.f.g.y - other.f.g.y};
            const higher = polygonClipping.intersection([[ringOf(other.polygon)]], belowLine(g, other.f.k - plane.f.k));
            area = higher.length === 0 ? area : polygonClipping.difference(area, higher);
        }
        // The plane's windows open the sloped ceiling under them.
        if (area.length > 0 && plane.windows.length > 0) {
            area = polygonClipping.difference(area, ...plane.windows.map((w): Polygon => [ringOf(w)]));
        }
        if (area.length > 0) {
            sloped.push({area, f: plane.f});
        }
    }
    if (sloped.length === 0) {
        return null;
    }
    const flat = polygonClipping.difference(outline, ...sloped.map((s) => s.area));

    const parts = (area: MultiPolygon, heightAt: HeightAt): CeilingPart[] =>
        area.map((polygon: Polygon) => {
            const [outer, ...holes] = polygon.map((ring) => ring.slice(0, -1).map(([x, y]): Vec2 => ({x, y})));
            return {outer, holes, heightAt};
        });
    return [
        ...parts(flat, () => flatHeight),
        ...sloped.flatMap(({area, f}) => parts(area, (p) => valueOf(f, p) - CeilingUnderRoof)),
    ];
}

/**
 * The footprints in plan of a roof's windows (those it left behind are none), each with its plane: the hole at the
 * soffit, which the ceiling lies just under.
 */
function windowFootprints(roof: FloorRoofs[number]): {plane: number; polygon: Vec2[]}[] {
    return roofWindowLayouts(roof).map(({layout}) => ({plane: layout.plane, polygon: layout.soffit.map((c) => roofToPlan(roof, c.x, c.y))}));
}

/** A room's outline as a polygon-clipping shape, its `voids` (stair openings) cut out. */
export function outlineWithout(polygon: readonly PlanPoint[], voids: readonly PlanPoint[][]): MultiPolygon {
    const outline: MultiPolygon = [[ringOf(polygon.map(([x, y]) => ({x, y})))]];
    return voids.length === 0 ? outline : polygonClipping.difference(outline, ...voids.map((v): Polygon => [ringOf(v.map(([x, y]) => ({x, y})))]));
}
