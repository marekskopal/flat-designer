import {PlanPoint, Plot, RoofWindow, Room, Wall} from '@app/models/scene';
import polygonClipping, {MultiPolygon, Pair, Polygon, Ring} from 'polygon-clipping';

import {alongWall, Bounds, polygonBounds, polygonSignedArea, Vec2, WallFrame} from './plan-geometry';
import {roofHalfExtent, roofLocal, roofPlanes, RoofShapeFields, roofsUndersideAt, roofToPlan, roofWindowLayouts} from './roof-geometry';
import {groundAt} from './terrain';

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

/** How far along a wall a plan point lies, as a fraction of its length (0 at its start, 1 at its end). */
const fractionAlong = (frame: WallFrame, p: Vec2): number => (frame.length > 0 ? Math.max(0, Math.min(1, alongWall(frame, p) / frame.length)) : 0);

/**
 * A wall's own top at a plan point, sloping straight from `height` at its start to `heightEnd` at its end along the
 * wall on `frame` (FD-87); null when it is level. Linear along any line, so `roofBreaks()` takes it as a slope.
 */
export function wallHeightAt(wall: Pick<Wall, 'height' | 'heightEnd'>, frame: WallFrame): HeightAt | null {
    const end = wall.heightEnd ?? null;
    if (end === null || end === wall.height) {
        return null;
    }
    return (p) => wall.height + (end - wall.height) * fractionAlong(frame, p);
}

/**
 * The top of a wall at a plan point: its height (with `heightEnd` sloping straight to it along the wall on `frame`,
 * FD-87), cut by the roofs' underside where that is lower, or with `upToRoof` the underside wherever a roof is over it.
 * Null without roofs and slope, as the wall is then its height everywhere. The twin is `WallTop`.
 */
export function wallTopAt(wall: Pick<Wall, 'height' | 'upToRoof' | 'heightEnd'>, roofs: FloorRoofs, frame?: WallFrame): HeightAt | null {
    const own = frame === undefined ? null : wallHeightAt(wall, frame);
    if (roofs.length === 0 && own === null) {
        return null;
    }
    const height: HeightAt = own ?? ((): number => wall.height);
    return (p) => {
        const h = height(p);
        if (roofs.length === 0) {
            return h;
        }
        const underside = roofsUndersideAt(roofs, p.x, p.y);
        if (underside === null) {
            return h;
        }
        return wall.upToRoof === true ? underside : Math.min(h, underside);
    };
}

/**
 * The bottom of a wall at a plan point (FD-87): on the floor's level, on its base sloping from `baseStart` to `baseEnd`
 * along it, or with `onGround` on the ground of the `plots`. Null on the floor's level. The twin is `WallBottom` (which
 * reads the ground under the centre line; here each face stands on the ground beside it).
 */
export function wallBottomAt(
    wall: Pick<Wall, 'baseStart' | 'baseEnd' | 'onGround'>,
    frame: WallFrame,
    plots: readonly Plot[],
): HeightAt | null {
    if (wall.onGround === true) {
        return (p) => groundAt(plots, p.x, p.y);
    }
    const [start, end] = [wall.baseStart ?? 0, wall.baseEnd ?? 0];
    if (start === 0 && end === 0) {
        return null;
    }
    return (p) => start + (end - start) * fractionAlong(frame, p);
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

/**
 * The lowest `top` along p → q: a top under the roofs (and a wall's own sloping top, among the `slopes`) is piecewise
 * linear, so it is lowest at a break or an end.
 */
export function lowestTopAlong(
    p: Vec2,
    q: Vec2,
    top: HeightAt,
    roofs: FloorRoofs,
    levels: readonly number[] = [],
    slopes: readonly HeightAt[] = [],
): number {
    return Math.min(...roofBreaks(p, q, roofs, levels, slopes).map((t) => top(lerp(p, q, t))));
}

const valueOf = (f: Linear, p: Vec2): number => f.k + f.g.x * p.x + f.g.y * p.y;

/**
 * Where along the line p → q (0–1) a height under the roofs may bend or step: where the line crosses a roof's edge,
 * where two planes meet (a ridge, a hip, two roofs) and where a plane, or one of the `slopes` (heights linear along the
 * line: a wall's own sloping top or base, FD-87), reaches one of `levels` (a wall's height, a ceiling's) or another.
 * Ascending, with 0 and 1; more than needed is harmless, as a height is linear between them. The twin is `RoofBreaks`.
 */
export function roofBreaks(p: Vec2, q: Vec2, roofs: FloorRoofs, levels: readonly number[], slopes: readonly HeightAt[] = []): number[] {
    const ts = [0, 1];
    const push = (t: number): void => {
        if (Number.isFinite(t) && t > 0 && t < 1) {
            ts.push(t);
        }
    };
    const lines: [number, number][] = levels.map((level) => [level, 0]);
    for (const slope of slopes) {
        const at = slope(p);
        lines.push([at, slope(q) - at]);
    }
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

/** A stretch of a solid under the roofs: its two faces' points in plan, the top over each point and the bottom under it. */
export interface ProfileRun {
    right: Vec2[];
    left: Vec2[];
    rightTop: number[];
    leftTop: number[];
    rightBottom: number[];
    leftBottom: number[];
}

/**
 * An upright solid between two faces (`right`, `left`: polylines in plan with as many points, as `curvedPrismAt()` takes
 * them) under a top following `top`, cut where the top bends (`roofBreaks()` on either face) and split where it steps (at
 * a roof's edge: each run then ends in a face of its own). It stands on `bottom` at each station (0 without). The
 * `slopes` (the wall's own sloping top and base) bend it where they cross the `levels` or one another.
 */
export function profileRuns(
    right: readonly Vec2[],
    left: readonly Vec2[],
    top: HeightAt,
    roofs: FloorRoofs,
    levels: readonly number[],
    bottom: HeightAt = () => 0,
    slopes: readonly HeightAt[] = [],
): ProfileRun[] {
    const stations: {r: Vec2; l: Vec2}[] = [];
    for (let i = 1; i < right.length; i++) {
        const us = [...roofBreaks(right[i - 1], right[i], roofs, levels, slopes), ...roofBreaks(left[i - 1], left[i], roofs, levels, slopes)]
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
    const empty = (): ProfileRun => ({right: [], left: [], rightTop: [], leftTop: [], rightBottom: [], leftBottom: []});
    let run = empty();
    const add = (station: {r: Vec2; l: Vec2}, r: number, l: number): void => {
        run.right.push(station.r);
        run.left.push(station.l);
        run.rightTop.push(r);
        run.leftTop.push(l);
        run.rightBottom.push(bottom(station.r));
        run.leftBottom.push(bottom(station.l));
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
            run = empty();
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
export function belowLine(g: Vec2, c: number): MultiPolygon {
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
        area = withoutHigher(area, plane.f, planes.filter((o) => o.roof !== plane.roof));
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

/**
 * `area` without where one of `others` (planes of other roofs) is higher than `f`: exactly where the planes cross, so
 * the parts meet; parallel planes within `Same` are one, the `earlier` ones' (another roof before this one) the top.
 */
function withoutHigher(
    area: MultiPolygon,
    f: Linear,
    others: readonly PlaneFunction[],
    earlier: (other: PlaneFunction) => boolean = () => false,
): MultiPolygon {
    for (const other of others) {
        if (area.length === 0) {
            break;
        }
        // other − f > 0: (g_f − g_o) · p < k_o − k_f. The plane is convex, so is the part of it.
        const g = {x: f.g.x - other.f.g.x, y: f.g.y - other.f.g.y};
        const tie = Math.abs(g.x) + Math.abs(g.y) < 1e-9 ? (earlier(other) ? Same : -Same) : 0;
        const higher = clipBelow(other.polygon, g, other.f.k - f.k + tie);
        area = higher.length < 3 ? area : polygonClipping.difference(area, [[ringOf(higher)]]);
    }
    return area;
}

/** The part of a convex polygon where g · p < c, rounded to a micrometre for `polygon-clipping`. */
function clipBelow(polygon: readonly Vec2[], g: Vec2, c: number): Vec2[] {
    return clipRing(polygon, g, c).map(micro);
}

const micro = (p: Vec2): Vec2 => ({x: Math.round(p.x * 1000) / 1000, y: Math.round(p.y * 1000) / 1000});

/**
 * Roof junctions: the parts (in plan) of each of a roof's planes where it is the top, its underside the highest of
 * `others` (the floor's roofs, in their order; a roof alike an earlier one is under it), so roofs meeting form valleys
 * and a wing's ridge runs into the main roof. In the order of `roofPlanes()`; a plane another roof covers wholly has
 * none. Twin of `RoofCover` (PHP), which measures them, kept alike by the junction cases of `roof-geometry.json`.
 */
export function roofPlaneParts(roof: RoofShapeFields, others: FloorRoofs): MultiPolygon[] {
    const own = planeFunctions(roof);
    const index = others.indexOf(roof);
    const near = others.flatMap((other) => (other === roof ? [] : planeFunctions(other)));
    const before = new Set(others.slice(0, Math.max(index, 0)).flatMap((other) => planeFunctions(other)));
    return own.map((plane) =>
        withoutHigher(
            [[ringOf(plane.polygon.map(micro))]],
            plane.f,
            near.filter((o) => overlap(o.bounds, plane.bounds)),
            (o) => before.has(o),
        ),
    );
}

/** Whether two roofs' outlines (with the overhangs) overlap, so a change of one may move the other's junctions. */
export function roofsMeet(a: RoofShapeFields, b: RoofShapeFields): boolean {
    return planeFunctions(a).some((p) => planeFunctions(b).some((q) => overlap(p.bounds, q.bounds)));
}

/**
 * The stretches (0–1 along p → q) of a line on `roof` (a ridge, a hip) where it is the top, no other of `others` higher:
 * between `roofBreaks()` the answer is the same, so its middle decides.
 */
export function topStretches(p: Vec2, q: Vec2, roof: RoofShapeFields, others: FloorRoofs): [number, number][] {
    const rest = others.filter((other) => other !== roof);
    if (rest.length === 0) {
        return [[0, 1]];
    }
    const ts = roofBreaks(p, q, [roof, ...rest], []);
    const stretches: [number, number][] = [];
    for (let i = 1; i < ts.length; i++) {
        const middle = lerp(p, q, (ts[i - 1] + ts[i]) / 2);
        const own = roofsUndersideAt([roof], middle.x, middle.y);
        const higher = roofsUndersideAt(rest, middle.x, middle.y);
        if (own !== null && (higher === null || higher <= own + Same)) {
            const last = stretches[stretches.length - 1];
            if (last !== undefined && last[1] === ts[i - 1]) {
                last[1] = ts[i];
            } else {
                stretches.push([ts[i - 1], ts[i]]);
            }
        }
    }
    return stretches;
}

/** mm: the clear height from which a room's floor area counts as usable under a roof (`RoomUnderRoof::Headroom`). */
export const HeadroomHeight = 1200;

/**
 * A room under the roofs (FD-38): its clear height's range (mm, from its floor to the ceiling, which is its height or
 * the roof's underside where that is lower, not less than 0), its volume (mm³, under that ceiling) and the area (mm²)
 * where the clear height is at least `HeadroomHeight`, and the ceiling's area (mm², its sloped parts along the slope).
 * Null for an open-air or vaulted room, or one no roof reaches. Measured on the roofs' underside itself, without
 * `CeilingUnderRoof`. Twin of `RoomUnderRoof` (PHP), kept identical by the room cases of `roof-geometry.json`.
 */
export interface RoomUnderRoof {
    minHeight: number;
    maxHeight: number;
    volume: number;
    headroomArea: number;
    ceilingArea: number;
}

type MeasuredRoom = Pick<Room, 'polygon' | 'ceilingHeight' | 'floorElevation' | 'vaultSpacing'>;

export function roomUnderRoofs(room: MeasuredRoom, roofs: FloorRoofs): RoomUnderRoof | null {
    const height = room.ceilingHeight;
    if (height === null || room.vaultSpacing != null || !roofsReach(polygonBounds(room.polygon), roofs)) {
        return null;
    }
    const floor = room.floorElevation ?? 0;
    const outline: MultiPolygon = [[ringOf(room.polygon.map(([x, y]) => ({x, y})))]];
    let covered = 0;
    let volume = 0;
    let headroom = 0;
    let ceiling = 0;
    let lowest = Infinity;
    let highest = -Infinity;
    for (const {f, parts} of coverParts(roofs)) {
        // The clear height over the part is f − floor, between 0 and the room's height.
        for (const ring of polygonClipping.intersection(outline, parts).flat(1)) {
            const points = ring.slice(0, -1).map(([x, y]): Vec2 => ({x, y}));
            const [area] = moments(points);
            covered += area;
            const below = clipRing(clipRing(points, f.g, height + floor - f.k), {x: -f.g.x, y: -f.g.y}, f.k - floor);
            const [la, lx, ly] = moments(below);
            const [ha] = moments(clipRing(points, {x: -f.g.x, y: -f.g.y}, f.k - floor - height));
            volume += (f.k - floor) * la + f.g.x * lx + f.g.y * ly + height * ha;
            // The ceiling: flat over `ha`, along the slope over `la`; none where the roof comes down to the floor.
            ceiling += ha + la * Math.hypot(1, f.g.x, f.g.y);
            if (height >= HeadroomHeight) {
                headroom += moments(clipRing(points, {x: -f.g.x, y: -f.g.y}, f.k - floor - HeadroomHeight))[0];
            }
            for (const p of points) {
                const clear = Math.min(Math.max(valueOf(f, p) - floor, 0), height);
                lowest = Math.min(lowest, clear);
                highest = Math.max(highest, clear);
            }
        }
    }
    if (covered < NoArea) {
        return null;
    }
    const open = Math.abs(polygonSignedArea(room.polygon)) - covered;
    if (open > NoArea) {
        volume += height * open;
        ceiling += open;
        headroom += height >= HeadroomHeight ? open : 0;
        lowest = Math.min(lowest, height);
        highest = height;
    }
    return {minHeight: lowest, maxHeight: highest, volume, headroomArea: headroom, ceilingArea: ceiling};
}

/** mm²: an area this small is none. */
const NoArea = 1;

/** The parts of a floor's roofs where each plane is the top (`roofPlaneParts()`), with its underside; once per roofs array. */
const coverCache = new WeakMap<FloorRoofs, {f: Linear; parts: MultiPolygon}[]>();

function coverParts(roofs: FloorRoofs): {f: Linear; parts: MultiPolygon}[] {
    let parts = coverCache.get(roofs);
    if (parts === undefined) {
        parts = roofs.flatMap((roof) => {
            const planes = planeFunctions(roof);
            return roofPlaneParts(roof, roofs).map((part, k) => ({f: planes[k].f, parts: part}));
        });
        coverCache.set(roofs, parts);
    }
    return parts;
}

/** The part of a ring (any shape; its signed measures stay right) where g · p < c (Sutherland–Hodgman). */
export function clipRing(ring: readonly Vec2[], g: Vec2, c: number): Vec2[] {
    const out: Vec2[] = [];
    const side = (p: Vec2): number => c - g.x * p.x - g.y * p.y;
    for (let i = 0; i < ring.length; i++) {
        const a = ring[i];
        const b = ring[(i + 1) % ring.length];
        const sa = side(a);
        const sb = side(b);
        if (sa > 0) {
            out.push(a);
        }
        if ((sa > 0) !== (sb > 0)) {
            out.push(lerp(a, b, sa / (sa - sb)));
        }
    }
    return out;
}

/** Signed area (mm², + counter-clockwise) and first moments (∫x, ∫y) of a ring. */
function moments(ring: readonly Vec2[]): [number, number, number] {
    let area = 0;
    let mx = 0;
    let my = 0;
    ring.forEach((p, i) => {
        const q = ring[(i + 1) % ring.length];
        const cross = p.x * q.y - q.x * p.y;
        area += cross;
        mx += (p.x + q.x) * cross;
        my += (p.y + q.y) * cross;
    });
    return [area / 2, mx / 6, my / 6];
}

/** A room's outline as a polygon-clipping shape, its `voids` (stair openings) cut out. */
export function outlineWithout(polygon: readonly PlanPoint[], voids: readonly PlanPoint[][]): MultiPolygon {
    const outline: MultiPolygon = [[ringOf(polygon.map(([x, y]) => ({x, y})))]];
    return voids.length === 0 ? outline : polygonClipping.difference(outline, ...voids.map((v): Polygon => [ringOf(v.map(([x, y]) => ({x, y})))]));
}
