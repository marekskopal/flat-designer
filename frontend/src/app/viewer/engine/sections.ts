import {levelText} from '@app/core/level-text';
import {isOutdoorRoom, Material, Opening, PlanPoint, Plot, Railing, Room, Scene, Stair, Wall, WallFinish} from '@app/models/scene';
import polygonClipping, {MultiPolygon, Polygon, Ring} from 'polygon-clipping';

import {isSiteWall} from './building';
import {chimneyOutline, chimneyTop, isChimney} from './chimneys';
import {facadeBands} from './facades';
import {
    alongWall,
    DoorFrameWidth,
    garageSections,
    isLiftSlide,
    pointInPolygon,
    pointOnWall,
    Vec2,
    wallFaces,
    wallFrame,
    wallJoints,
    WindowFrameBar,
} from './plan-geometry';
import {isFenceStyle} from './railings';
import {gutterOffset, roofDownpipes, roofGutters} from './rainwater';
import {roofPlanes, roofRidgeHeight, roofToPlan, roofVerticalThickness, roofWindowLayouts} from './roof-geometry';
import {
    frameSvg,
    Ink,
    line,
    Margin,
    Muted,
    n,
    Padding,
    PaperLong,
    PaperShort,
    path,
    Sheet,
    SheetInfo,
    sheetSvg,
    Style,
    text,
    textWidth,
    TitleBand,
    titleBlockSvg,
} from './sheet-svg';
import {stairGeometry} from './stairs';
import {groundAt, plotAt} from './terrain';
import {clipRing, FloorRoofs, HeightAt, roofBreaks, roofPlaneParts, wallBottomAt, wallHeightAt, wallTopAt} from './under-roof';

/**
 * The building drawings: façade elevations and sections through all the floors given, framework-free, from the
 * geometry the viewer builds with. A drawing looks along a view plane: plan points project to `u` along its `right`
 * axis (left to right on paper), heights are levels against ±0.000 (a floor's elevation plus its own heights), and
 * `depth` runs along `look` into the drawing. The building is collected as groups (a wall face with its openings and
 * bands, a roof plane with its covering, its edge and windows, a slab edge, a chimney's side) drawn far to near, each
 * filled, so nearer ones cover what lies behind (painter's order, by the group's nearest point). A section keeps what
 * lies beyond its cut (`depth` 0 to its own depth) and draws the cut parts filled over it: walls (split by the openings
 * the cut passes through), slabs, roofs, stair treads and the ground (cut out where the house goes into it).
 */

/** A point of a drawing: `u` mm along the view plane, `v` mm above ±0.000. */
export interface ViewPoint {
    u: number;
    v: number;
}

/** What a drawing looks at: plan `origin`, unit `right` (paper right) and `look` (into the drawing), the depths drawn. */
export interface ViewPlane {
    origin: Vec2;
    right: Vec2;
    look: Vec2;
    /** mm along `look` from the origin: a section's cut (0) and its depth; null leaves that side open. */
    near: number | null;
    far: number | null;
}

/** A line to cut the building along (`SectionLine`): it looks to the left of start → end, `depth` mm deep (null: all). */
export interface SectionCut {
    name: string;
    start: Vec2;
    end: Vec2;
    depth: number | null;
}

interface ViewShape {
    points: ViewPoint[];
    closed: boolean;
    style: Style;
}

/** Shapes drawn together, as one surface and what lies on it; `depth` is its nearest point's, for the painter's order. */
interface ViewGroup {
    depth: number;
    shapes: ViewShape[];
    /** Out on the site (a garden wall, steps): drawn where it falls within the building's extent, not widening it. */
    site?: boolean;
}

/** What a wall face needs of its floor. */
interface FloorContext {
    roofs: FloorRoofs;
    plots: readonly Plot[];
    /** mm, the floor's elevation. */
    e: number;
    colours: ReadonlyMap<number, string>;
    /** The outlines of the floor's indoor rooms: a face into one is not a façade. */
    indoor: readonly PlanPoint[][];
}

/** A level mark at the drawing's side. */
export interface LevelMark {
    level: number;
    label: string;
}

/** A drawing's content in view mm, ready to be laid on paper. */
export interface BuildingView {
    groups: ViewGroup[];
    /** A section's cut parts, drawn over the groups. */
    cut: ViewShape[];
    marks: LevelMark[];
    /** The building's extent, `GroundMargin` wider on either side; the drawing is clipped to it. */
    bounds: {minU: number; maxU: number; minV: number; maxV: number};
}

/** Paper scales (1:n) the drawings try, largest first. */
export const BuildingScales = [50, 100, 200, 500];

/** A3 in mm, the façade sheet. */
const A3Long = 420;
const A3Short = 297;

/** mm of view a drawing's ground reaches past the building on either side. */
const GroundMargin = 1000;
/** mm the cut ground reaches under the lowest point drawn. */
const EarthDepth = 800;
/** mm between ground samples along a line. */
const GroundStep = 100;
/** Paper mm at the drawing's right kept for the level marks. */
const MarkGutter = 20;

const Line = '#2f2c28';
const Thin = 0.13;
const Medium = 0.25;
const Glass = '#dde7eb';
const Leaf = '#f1ece4';
const Opened = '#d9d3c9';
const SlabFill = '#d6d1c8';
const CutFill = '#5b5650';
const Earth = '#e9e1d1';
const PlainWall = '#ffffff';
const DefaultCovering = '#b5654a';
const Metal = '#8d939a';

const ThinLine: Style = {stroke: Line, width: Thin};
const ThinGlass: Style = {fill: Glass, stroke: Line, width: Thin};

/** The rooms lying on the ground itself, which have no slab under them (`UnslabbedRooms` of the slab). */
const OnGround: readonly Room['type'][] = ['Garden', 'Path', 'Driveway'];

const dot = (a: Vec2, b: Vec2): number => a.x * b.x + a.y * b.y;

/** The least and greatest of a value over many points (a loop: spreading them into `Math.min()` overflows the stack). */
function extent<T>(items: readonly T[], value: (item: T) => number): [number, number] {
    let [low, high] = [Infinity, -Infinity];
    for (const item of items) {
        const v = value(item);
        low = Math.min(low, v);
        high = Math.max(high, v);
    }
    return [low, high];
}
const lerp = (a: Vec2, b: Vec2, t: number): Vec2 => ({x: a.x + (b.x - a.x) * t, y: a.y + (b.y - a.y) * t});
const toVec = ([x, y]: readonly number[]): Vec2 => ({x, y});

/** A façade direction: the side the viewer stands on, as a plan axis. */
export type FacadeSide = 'North' | 'East' | 'South' | 'West';

/**
 * The view of the building from one side of the plan's axes (plan north is +y), no depth clipped: the viewer stands on
 * that side, looking across the building.
 */
export function facadePlane(side: FacadeSide): ViewPlane {
    const look = {North: {x: 0, y: -1}, East: {x: -1, y: 0}, South: {x: 0, y: 1}, West: {x: 1, y: 0}}[side];
    return {origin: {x: 0, y: 0}, right: {x: look.y, y: -look.x}, look, near: null, far: null};
}

/** The view of a section line: along it, looking to its left, from the line (depth 0) to its depth. */
export function sectionPlane(cut: SectionCut): ViewPlane | null {
    const length = Math.hypot(cut.end.x - cut.start.x, cut.end.y - cut.start.y);
    if (length < 1) {
        return null;
    }
    const dir = {x: (cut.end.x - cut.start.x) / length, y: (cut.end.y - cut.start.y) / length};
    const look = {x: -dir.y, y: dir.x};
    return {origin: cut.start, right: {x: look.y, y: -look.x}, look, near: 0, far: cut.depth};
}

/**
 * The compass name of a façade: the true direction the viewer stands on, from plan `side` turned by the project's
 * `northAngle` (degrees clockwise from plan north to true north), to the nearest of the four.
 */
export function facadeName(side: FacadeSide, northAngle: number): FacadeSide {
    const bearing = {North: 0, East: 90, South: 180, West: 270}[side];
    const sides: FacadeSide[] = ['North', 'East', 'South', 'West'];
    return sides[Math.round((((bearing - northAngle) % 360) + 360) / 90) % 4];
}

/** The scenes as floors of one building, lowest first (each scene's heights count from its floor's elevation). */
const byLevel = (scenes: readonly Scene[]): Scene[] => [...scenes].sort((a, b) => a.floor.level - b.floor.level);

/** The building seen on the plane: the groups, a section's cut parts, the level marks and the extent. */
export function buildingView(scenes: readonly Scene[], plane: ViewPlane, cut: SectionCut | null = null): BuildingView {
    const floors = byLevel(scenes);
    const view = new ViewCollector(plane);
    for (const scene of floors) {
        view.floor(scene);
    }
    const house = view.bounds();
    const bounds = {...house, minU: house.minU - GroundMargin, maxU: house.maxU + GroundMargin};
    const groups = [...view.groups, ...facadeGround(floors, plane, bounds, cut === null)];
    const cutShapes = cut === null ? [] : sectionCutShapes(floors, cut, plane, bounds);
    // Below the house, as far as the ground drawn within the extent goes down.
    const within = [...groups.flatMap((g) => g.shapes), ...cutShapes]
        .flatMap((s) => s.points)
        .filter((q) => q.u >= bounds.minU && q.u <= bounds.maxU);
    return {
        groups: groups.sort((a, b) => b.depth - a.depth),
        cut: cutShapes,
        marks: levelMarks(floors),
        bounds: {...bounds, minV: Math.min(house.minV, extent(within, (q) => q.v)[0]), maxV: Math.max(house.maxV, extent(within, (q) => q.v)[1])},
    };
}

/** Collects the groups of a building seen on a plane, clipped to its depths. */
class ViewCollector {
    public readonly groups: ViewGroup[] = [];
    /** Set while collecting what stands out on the site. */
    private site = false;

    public constructor(private readonly plane: ViewPlane) {}

    public floor(scene: Scene): void {
        const e = scene.floor.elevation;
        const roofs: FloorRoofs = scene.roofs ?? [];
        const colours = materialColours(scene.materials);
        const joints = wallJoints(scene.walls);
        const indoor = scene.rooms.filter((r) => !isOutdoorRoom(r)).map((r) => r.polygon);
        for (const wall of scene.walls) {
            this.site = isSiteWall(wall);
            const faces = wallFaces(wall, joints.get(wall.id));
            for (const side of ['Left', 'Right'] as const) {
                const bands = facadeBands(wall, side, scene.exteriorMaterialId ?? null);
                const face = {side, points: side === 'Left' ? faces.left : faces.right, bands};
                this.wallFace(wall, face, {roofs, plots: scene.plots ?? [], e, colours, indoor});
            }
        }
        this.site = false;
        this.slabEdges(scene);
        roofs.forEach((roof, index) => this.roof(scene, index, colours));
        for (const item of scene.items.filter(isChimney)) {
            const outline = chimneyOutline(item).map(toVec);
            this.prism(outline, e + item.z, e + chimneyTop(item, 0, scene), {fill: '#e4dfd7', stroke: Line, width: Thin});
        }
        for (const stair of scene.stairs ?? []) {
            const geometry = stairGeometry(stair, scene.stairRise ?? 0);
            const base = e + (stair.rise == null ? 0 : (stair.baseZ ?? 0));
            this.site = stair.rise != null;
            // A curved solid flight's treads are blocks down to its base; the others show a riser.
            const blocks = isBlockStair(stair);
            for (const tread of geometry.treads) {
                const points = this.clipPolygon(tread.outline);
                if (points.length >= 3) {
                    const us = points.map((p) => this.u(p));
                    const top = base + tread.top;
                    const bottom = blocks ? base : top - geometry.riser;
                    const step = rectangle(Math.min(...us), Math.max(...us), bottom, top, {fill: PlainWall, stroke: Line, width: Thin});
                    this.push(points, [step]);
                }
            }
        }
        this.site = false;
        const plots = scene.plots ?? [];
        for (const railing of scene.railings ?? []) {
            this.railing(railing, plots, e);
        }
        this.site = false;
    }

    /**
     * A railing's stretches: bands `height` high from its base, a fence on the ground standing on it at each end
     * (Rake), or in level bays over the highest ground under each (Step, as `buildFenceRun()`).
     */
    private railing(railing: Railing, plots: readonly Plot[], e: number): void {
        const follow = isFenceStyle(railing.style) ? (railing.follow ?? 'Level') : 'Level';
        // A fence stands out on the site: drawn within the building's extent, not widening it (as garden walls).
        this.site = isFenceStyle(railing.style);
        const ground = (q: Vec2): number => e + (follow === 'Level' ? railing.baseZ : groundAt(plots, q.x, q.y));
        const style = railing.style === 'Glass' || railing.style === 'Panel' ? ThinGlass : ThinLine;
        railing.points.slice(1).forEach((point, i) => {
            const [start, end] = [toVec(railing.points[i]), toVec(point)];
            const length = Math.hypot(end.x - start.x, end.y - start.y);
            const bays = follow === 'Step' ? Math.max(1, Math.ceil(length / railing.postSpacing)) : 1;
            for (let k = 0; k < bays; k++) {
                const [a, b] = this.clipSegment(lerp(start, end, k / bays), lerp(start, end, (k + 1) / bays));
                if (a === null || b === null) {
                    continue;
                }
                const groundAt3 = [k, k + 0.5, k + 1].map((t) => ground(lerp(start, end, t / bays)));
                const level = Math.max(...groundAt3);
                const [ba, bb] = follow === 'Step' ? [level, level] : [ground(a), ground(b)];
                const outline = [p(this.u(a), ba), p(this.u(b), bb), p(this.u(b), bb + railing.height), p(this.u(a), ba + railing.height)];
                this.push([a, b], [{points: outline, closed: true, style}]);
            }
        });
    }

    /** The building's extent on the plane: its walls, slabs and roofs (not the ground nor what stands on the site). */
    public bounds(): BuildingView['bounds'] {
        const points = this.groups.filter((g) => g.site !== true).flatMap((g) => g.shapes.flatMap((s) => s.points));
        if (points.length === 0) {
            return {minU: 0, maxU: 1000, minV: 0, maxV: 1000};
        }
        const [minU, maxU] = extent(points, (q) => q.u);
        const [minV, maxV] = extent(points, (q) => q.v);
        return {minU, maxU, minV, maxV};
    }

    private u(point: Vec2): number {
        return dot({x: point.x - this.plane.origin.x, y: point.y - this.plane.origin.y}, this.plane.right);
    }

    private depth(point: Vec2): number {
        return dot({x: point.x - this.plane.origin.x, y: point.y - this.plane.origin.y}, this.plane.look);
    }

    private push(plan: readonly Vec2[], shapes: ViewShape[]): void {
        if (shapes.length > 0) {
            this.groups.push({depth: Math.min(...plan.map((q) => this.depth(q))), shapes, site: this.site});
        }
    }

    /** The stretch (0–1) of a plan segment within the plane's depths; null where none is. */
    private clipRange(a: Vec2, b: Vec2): [number, number] | null {
        let [from, to] = [0, 1];
        const da = this.depth(a);
        const db = this.depth(b);
        const limit = (bound: number, keepAbove: boolean): void => {
            const ta = keepAbove ? da - bound : bound - da;
            const tb = keepAbove ? db - bound : bound - db;
            if (ta < 0 && tb < 0) {
                [from, to] = [1, 0];
            } else if (ta < 0) {
                from = Math.max(from, ta / (ta - tb));
            } else if (tb < 0) {
                to = Math.min(to, ta / (ta - tb));
            }
        };
        if (this.plane.near !== null) {
            limit(this.plane.near, true);
        }
        if (this.plane.far !== null) {
            limit(this.plane.far, false);
        }
        return to - from > 1e-9 ? [from, to] : null;
    }

    private clipSegment(a: Vec2, b: Vec2): [Vec2 | null, Vec2 | null] {
        const range = this.clipRange(a, b);
        return range === null ? [null, null] : [lerp(a, b, range[0]), lerp(a, b, range[1])];
    }

    /** A plan polygon clipped to the plane's depths. */
    private clipPolygon(ring: readonly Vec2[]): Vec2[] {
        let points = [...ring];
        const {origin, look} = this.plane;
        const base = dot(origin, look);
        if (this.plane.near !== null) {
            points = clipRing(points, {x: -look.x, y: -look.y}, -(base + this.plane.near));
        }
        if (this.plane.far !== null && points.length > 0) {
            points = clipRing(points, look, base + this.plane.far);
        }
        return points;
    }

    /**
     * A wall's face (`points` from its start to its end, mitred) where it faces the viewer: piece by piece between the
     * heights' bends (`roofBreaks()`, with its own sloping top and base), from its bottom to its top, its openings on it,
     * and the façade `bands` over a piece outside every indoor room (an inner face shows the plain wall).
     */
    private wallFace(
        wall: Wall,
        {side, points, bands}: {side: 'Left' | 'Right'; points: readonly Vec2[]; bands: readonly WallFinish[]},
        {roofs, plots, e, colours, indoor}: FloorContext,
    ): void {
        const frame = wallFrame(wall);
        const topAt = wallTopAt(wall, roofs, frame);
        const bottomAt = wallBottomAt(wall, frame, plots);
        const slopes = [wallHeightAt(wall, frame), wall.onGround === true ? null : bottomAt].filter((f): f is HeightAt => f !== null);
        const top = (q: Vec2): number => e + (topAt?.(q) ?? wall.height);
        const bottom = (q: Vec2): number => e + (bottomAt?.(q) ?? 0);
        const own = wall.materialId === null ? PlainWall : lighten(colours.get(wall.materialId) ?? PlainWall);
        for (let i = 1; i < points.length; i++) {
            const [a, b] = [points[i - 1], points[i]];
            const dir = {x: b.x - a.x, y: b.y - a.y};
            const outward = side === 'Left' ? {x: -dir.y, y: dir.x} : {x: dir.y, y: -dir.x};
            const range = dot(outward, this.plane.look) < 0 ? this.clipRange(a, b) : null;
            if (range === null) {
                continue;
            }
            const breaks = roofBreaks(a, b, roofs, [wall.height], slopes).filter((t) => t > range[0] && t < range[1]);
            const ground = wall.onGround === true ? stations(a, b, 250).filter((t) => t > range[0] && t < range[1]) : [];
            const ts = [...new Set([range[0], ...breaks, ...ground, range[1]])].sort((x, y) => x - y);
            const along = ts.map((t) => lerp(a, b, t));
            const outline = [...along.map((q) => p(this.u(q), bottom(q))), ...[...along].reverse().map((q) => p(this.u(q), top(q)))];
            const shapes: ViewShape[] = [{points: outline, closed: true, style: {fill: own, stroke: Line, width: Medium}}];
            const length = Math.hypot(dir.x, dir.y);
            const middle = lerp(a, b, 0.5);
            const probe = {x: middle.x + (outward.x / length) * 20, y: middle.y + (outward.y / length) * 20};
            const facade = length > 0 && !indoor.some((polygon) => pointInPolygon(probe, polygon));
            for (const band of facade ? bands : []) {
                const piece = clipView(outline, e + band.bottom, e + band.top);
                if (piece.length >= 3) {
                    const fill = lighten(colours.get(band.materialId) ?? PlainWall);
                    shapes.push({points: piece, closed: true, style: {fill, stroke: Muted, width: Thin}});
                }
            }
            if (facade && bands.length > 0) {
                shapes.push({points: outline, closed: true, style: {stroke: Line, width: Medium}});
            }
            const across = side === 'Left' ? wall.thickness / 2 : -wall.thickness / 2;
            const [from, to] = [alongWall(frame, along[0]), alongWall(frame, along[along.length - 1])].sort((x, y) => x - y);
            for (const opening of wall.openings) {
                if (opening.type === 'Niche' && opening.side !== side) {
                    continue;
                }
                const start = Math.max(opening.offset, from);
                const end = Math.min(opening.offset + opening.width, to);
                if (end - start < 1) {
                    continue;
                }
                const at = (s: number): number => this.u(pointOnWall(frame, s, across));
                shapes.push(...openingShapes(opening, at, start, end, e + opening.sillHeight, e + opening.sillHeight + opening.height));
            }
            this.push([a, b].map((q, k) => (k === 0 ? lerp(a, b, range[0]) : lerp(a, b, range[1]))), shapes);
        }
    }

    /** The edges of the floor's slab facing the viewer: under its rooms (not those on the ground) and walls. */
    private slabEdges(scene: Scene): void {
        const outline = slabOutline(scene);
        const e = scene.floor.elevation;
        for (const polygon of outline) {
            for (const ring of polygon) {
                this.prismRing(ring.slice(0, -1).map(toVec), e - scene.floor.slabThickness, e, {fill: SlabFill, stroke: Line, width: Thin}, true);
            }
        }
    }

    /** A prism's sides facing the viewer: an outline (either way round) from `bottom` to `top`. */
    private prism(outline: readonly Vec2[], bottom: number, top: number, style: Style): void {
        const counter = signedArea(outline) > 0;
        this.prismRing(counter ? outline : [...outline].reverse(), bottom, top, style, true);
    }

    /** The sides of a ring (counter-clockwise round a solid, clockwise round a hole) that face the viewer. */
    private prismRing(ring: readonly Vec2[], bottom: number, top: number, style: Style, outwardRight: boolean): void {
        ring.forEach((a, i) => {
            const b = ring[(i + 1) % ring.length];
            const outward = outwardRight ? {x: b.y - a.y, y: -(b.x - a.x)} : {x: -(b.y - a.y), y: b.x - a.x};
            if (dot(outward, this.plane.look) >= 0) {
                return;
            }
            const [from, to] = this.clipSegment(a, b);
            if (from !== null && to !== null) {
                this.push([from, to], [rectangle(this.u(from), this.u(to), bottom, top, style)]);
            }
        });
    }

    /**
     * A roof: each plane's parts where it is the top (`roofPlaneParts()`), the soffit under the covering so its edge
     * shows, tiles hatched across the slope, its windows; a flat roof's edges and parapet; gutters and downpipes.
     */
    private roof(scene: Scene, index: number, colours: ReadonlyMap<number, string>): void {
        const roofs = scene.roofs ?? [];
        const roof = roofs[index];
        const e = scene.floor.elevation;
        const thickness = roofVerticalThickness(roof);
        const coveringColour = roof.coveringMaterialId === null ? undefined : colours.get(roof.coveringMaterialId);
        const covering = lighten(coveringColour ?? DefaultCovering, 0.45);
        const soffit = roof.soffitMaterialId === null ? SlabFill : lighten(colours.get(roof.soffitMaterialId) ?? SlabFill);
        const planes = roofPlanes(roof);
        const parts = planeParts(roof, roofs);
        planes.forEach((plane, i) => {
            const under = (q: Vec2): number => e + plane.height + plane.gradient.x * (q.x - roof.x) + plane.gradient.y * (q.y - roof.y);
            for (const polygon of parts[i] ?? []) {
                const ring = this.clipPolygon(polygon[0].slice(0, -1).map(toVec));
                if (ring.length < 3) {
                    continue;
                }
                if (roof.shape === 'Flat') {
                    this.prism(ring, under(ring[0]), under(ring[0]) + thickness, {fill: soffit, stroke: Line, width: Medium});
                    if (roof.parapet != null && roof.parapet > 0) {
                        const top = under(ring[0]) + thickness;
                        this.prism(ring, top, top + roof.parapet, {fill: covering, stroke: Line, width: Thin});
                    }
                    continue;
                }
                const underside: ViewShape = {
                    points: ring.map((q) => p(this.u(q), under(q))),
                    closed: true,
                    style: {fill: soffit, stroke: Line, width: Medium},
                };
                const top: ViewShape[] = [
                    {points: ring.map((q) => p(this.u(q), under(q) + thickness)), closed: true, style: {fill: covering, stroke: Line, width: Medium}},
                    ...tileLines(ring, plane.gradient).map(([a, b]): ViewShape => ({
                        points: [p(this.u(a), under(a) + thickness), p(this.u(b), under(b) + thickness)],
                        closed: false,
                        style: {stroke: Muted, width: 0.08},
                    })),
                ];
                // The covering shows where the plane rises away from the viewer, the soffit where it rises towards them
                // (seen from inside, in a section); the other lies under it, its edge showing as the fascia.
                const shapes = dot(plane.gradient, this.plane.look) >= 0 ? [underside, ...top] : [...top, underside];
                for (const {layout} of roofWindowLayouts(roof)) {
                    if (layout.plane !== i) {
                        continue;
                    }
                    const corners = layout.corners.map((c) => roofToPlan(roof, c.x, c.y));
                    if (corners.every((c) => pointInPolygon(c, ring.map((q): PlanPoint => [q.x, q.y])))) {
                        shapes.push({points: corners.map((c) => p(this.u(c), under(c) + thickness + 40)), closed: true, style: ThinGlass});
                    }
                }
                this.push(ring, shapes);
            }
        });
        if ((roof.gutters ?? 'None') === 'None') {
            return;
        }
        const runs = roofGutters(roofs, index);
        const size = gutterOffset(roof.gutters === 'Box' ? 'Box' : 'HalfRound');
        for (const run of runs) {
            const [a, b] = this.clipSegment(run.from, run.to);
            if (a !== null && b !== null) {
                const gutter = rectangle(this.u(a), this.u(b), e + run.height - 1.6 * size, e + run.height, {fill: Metal, stroke: Line, width: Thin});
                this.push([a, b], [gutter]);
            }
        }
        for (const pipe of roofDownpipes(roof, roof.downpipes, runs)) {
            const depth = this.depth(pipe.at);
            if ((this.plane.near !== null && depth < this.plane.near) || (this.plane.far !== null && depth > this.plane.far)) {
                continue;
            }
            const foot = plotAt(scene.plots ?? [], pipe.at.x, pipe.at.y) === undefined ? -e : groundAt(scene.plots ?? [], pipe.at.x, pipe.at.y);
            const u = this.u(pipe.at);
            this.push([pipe.at], [rectangle(u - 45, u + 45, e + foot, e + pipe.top, {fill: Metal, stroke: Line, width: Thin})]);
        }
    }
}

/** The slab outlines once per scene object: the façades and every section of an export ask for them. */
const slabCache = new WeakMap<Scene, MultiPolygon>();

/** The floor's slab in plan: its rooms' outlines (not those on the ground) and its walls' footprints joined. */
function slabOutline(scene: Scene): MultiPolygon {
    let outline = slabCache.get(scene);
    if (outline === undefined) {
        outline = joinedSlab(scene);
        slabCache.set(scene, outline);
    }
    return outline;
}

/** The roof junctions once per floor's roofs (`roofPlaneParts()`), for the views and the cuts of an export. */
const partsCache = new WeakMap<FloorRoofs, Map<FloorRoofs[number], MultiPolygon[]>>();

function planeParts(roof: FloorRoofs[number], roofs: FloorRoofs): MultiPolygon[] {
    let byRoof = partsCache.get(roofs);
    if (byRoof === undefined) {
        byRoof = new Map();
        partsCache.set(roofs, byRoof);
    }
    let parts = byRoof.get(roof);
    if (parts === undefined) {
        parts = roofPlaneParts(roof, roofs);
        byRoof.set(roof, parts);
    }
    return parts;
}

function joinedSlab(scene: Scene): MultiPolygon {
    const joints = wallJoints(scene.walls);
    const pieces: Polygon[] = [
        ...scene.rooms.filter((r) => !OnGround.includes(r.type) && r.polygon.length >= 3).map((r): Polygon => [closedRing(r.polygon.map(toVec))]),
        ...scene.walls
            .filter((w) => !isSiteWall(w))
            .map((w): Polygon => {
                const {left, right} = wallFaces(w, joints.get(w.id));
                return [closedRing([...left, ...[...right].reverse()])];
            }),
    ];
    if (pieces.length === 0) {
        return [];
    }
    try {
        return polygonClipping.union(pieces[0], ...pieces.slice(1));
    } catch {
        // polygon-clipping trips over some near-coincident edges: join piece by piece, leaving out one it cannot take.
        return pieces.slice(1).reduce<MultiPolygon>((joined, piece) => {
            try {
                return polygonClipping.union(joined, piece);
            } catch {
                return joined;
            }
        }, [pieces[0]]);
    }
}

/** A closed polygon-clipping ring, counter-clockwise. */
function closedRing(points: readonly Vec2[]): Ring {
    const ordered = signedArea(points) < 0 ? [...points].reverse() : points;
    // To a tenth of a mm, as polygon-clipping is fragile with nearly coincident points.
    const closed = ordered.map((q): [number, number] => [Math.round(q.x * 10) / 10, Math.round(q.y * 10) / 10]);
    return [...closed, closed[0]];
}

function signedArea(points: readonly Vec2[]): number {
    let area = 0;
    points.forEach((a, i) => {
        const b = points[(i + 1) % points.length];
        area += a.x * b.y - b.x * a.y;
    });
    return area / 2;
}

/** Parameters (0–1) every `step` mm along a → b, without the ends. */
function stations(a: Vec2, b: Vec2, step: number): number[] {
    const count = Math.floor(Math.hypot(b.x - a.x, b.y - a.y) / step);
    return Array.from({length: count}, (_, i) => (i + 1) / (count + 1));
}

const p = (u: number, v: number): ViewPoint => ({u, v});

function rectangle(u0: number, u1: number, v0: number, v1: number, style: Style): ViewShape {
    return {points: [p(u0, v0), p(u1, v0), p(u1, v1), p(u0, v1)], closed: true, style};
}

/** The part of a view polygon between two levels (Sutherland–Hodgman twice). */
function clipView(points: readonly ViewPoint[], low: number, high: number): ViewPoint[] {
    const clip = (input: readonly ViewPoint[], keep: (q: ViewPoint) => number): ViewPoint[] => {
        const out: ViewPoint[] = [];
        input.forEach((a, i) => {
            const b = input[(i + 1) % input.length];
            const [sa, sb] = [keep(a), keep(b)];
            if (sa >= 0) {
                out.push(a);
            }
            if ((sa >= 0) !== (sb >= 0)) {
                const t = sa / (sa - sb);
                out.push(p(a.u + (b.u - a.u) * t, a.v + (b.v - a.v) * t));
            }
        });
        return out;
    };
    return clip(clip(points, (q) => q.v - low), (q) => high - q.v);
}

/**
 * An opening on a wall face, `at(s)` placing a point `s` mm along the wall on the paper's u: a window's frame, panes and
 * bars, a door's frame and leaf (a double door's split, a lift-and-slide's panes), a garage door's sections, a passage's
 * dark gap, a niche's recess.
 */
function openingShapes(opening: Opening, at: (s: number) => number, start: number, end: number, sill: number, head: number): ViewShape[] {
    const [u0, u1] = [at(start), at(end)].sort((x, y) => x - y);
    const shapes: ViewShape[] = [];
    const frame: Style = {stroke: Line, width: Thin};
    const vertical = (s: number, from: number, to: number): ViewShape => ({points: [p(at(s), from), p(at(s), to)], closed: false, style: frame});
    const within = (s: number): boolean => s > start && s < end;
    switch (opening.type) {
        case 'Window': {
            shapes.push(rectangle(u0, u1, sill, head, {fill: Glass, stroke: Line, width: Medium}));
            const bar = WindowFrameBar;
            if (u1 - u0 > 2 * bar && head - sill > 2 * bar) {
                shapes.push(rectangle(u0 + bar, u1 - bar, sill + bar, head - bar, frame));
            }
            const panes = (opening.mullions ?? 1) + 1;
            for (let i = 1; i < panes; i++) {
                const s = opening.offset + (opening.width * i) / panes;
                if (within(s)) {
                    shapes.push(vertical(s, sill, head));
                }
            }
            const rows = (opening.transoms ?? 0) + 1;
            for (let i = 1; i < rows; i++) {
                const v = sill + ((head - sill) * i) / rows;
                shapes.push({points: [p(u0, v), p(u1, v)], closed: false, style: frame});
            }
            if (opening.panelHeight != null && opening.panelHeight > 0) {
                shapes.push(rectangle(u0, u1, sill, sill + opening.panelHeight, {fill: Leaf, stroke: Line, width: Thin}));
            }
            break;
        }
        case 'Passage':
            shapes.push(rectangle(u0, u1, sill, head, {fill: Opened, stroke: Line, width: Medium}));
            break;
        case 'Niche':
            shapes.push(rectangle(u0, u1, sill, head, {fill: '#ece8e1', stroke: Line, width: Thin}));
            break;
        case 'GarageDoor': {
            shapes.push(rectangle(u0, u1, sill, head, {fill: Leaf, stroke: Line, width: Medium}));
            const sections = garageSections(head - sill);
            for (let i = 1; i < sections; i++) {
                const v = sill + ((head - sill) * i) / sections;
                shapes.push({points: [p(u0, v), p(u1, v)], closed: false, style: frame});
            }
            break;
        }
        default: {
            const glazed = opening.leafStyle === 'Glazed';
            shapes.push(rectangle(u0, u1, sill, head, {fill: Leaf, stroke: Line, width: Medium}));
            const inset = DoorFrameWidth;
            if (u1 - u0 > 2 * inset) {
                shapes.push(rectangle(u0 + inset, u1 - inset, sill, head - inset, {fill: glazed ? Glass : Leaf, stroke: Line, width: Thin}));
            }
            const fromHinge = opening.hinge === 'End' ? -1 : 1;
            const hinge = opening.hinge === 'End' ? opening.offset + opening.width : opening.offset;
            if (opening.type === 'DoubleDoor') {
                const split = hinge + fromHinge * (opening.leafWidth ?? opening.width / 2);
                if (within(split)) {
                    shapes.push(vertical(split, sill, head - inset));
                }
            }
            if (opening.type === 'SlidingDoor' && (opening.fixedPanes ?? 0) > 0) {
                const panes = (opening.fixedPanes ?? 0) + 1;
                for (let i = 1; i < panes; i++) {
                    const s = opening.offset + (opening.width * i) / panes;
                    if (within(s)) {
                        shapes.push(vertical(s, sill, head - inset));
                    }
                }
            }
        }
    }
    return shapes;
}

/** Lines across a pitched plane every 350 mm up its slope (tile courses), clipped to its outline, in plan. */
function tileLines(ringPoints: readonly Vec2[], gradient: Vec2): [Vec2, Vec2][] {
    const k = Math.hypot(gradient.x, gradient.y);
    if (k < 1e-6) {
        return [];
    }
    const up = {x: gradient.x / k, y: gradient.y / k};
    const across = {x: -up.y, y: up.x};
    // 350 mm along the slope is this far in plan.
    const step = 350 / Math.sqrt(1 + k * k);
    const ups = ringPoints.map((q) => dot(q, up));
    const lines: [Vec2, Vec2][] = [];
    for (let level = Math.ceil(Math.min(...ups) / step) * step; level < Math.max(...ups); level += step) {
        const base = {x: up.x * level, y: up.y * level};
        for (const [from, to] of lineInRing(base, across, ringPoints)) {
            lines.push([
                {x: base.x + across.x * from, y: base.y + across.y * from},
                {x: base.x + across.x * to, y: base.y + across.y * to},
            ]);
        }
    }
    return lines;
}

/** The stretches (mm along `dir` from `origin`) of a line inside a ring, by its crossings (even–odd). */
export function lineInRing(origin: Vec2, dir: Vec2, ringPoints: readonly Vec2[]): [number, number][] {
    const crossings: number[] = [];
    ringPoints.forEach((a, i) => {
        const b = ringPoints[(i + 1) % ringPoints.length];
        const edge = {x: b.x - a.x, y: b.y - a.y};
        const denominator = dir.x * edge.y - dir.y * edge.x;
        if (Math.abs(denominator) < 1e-12) {
            return;
        }
        const w = {x: a.x - origin.x, y: a.y - origin.y};
        const t = (w.x * edge.y - w.y * edge.x) / denominator;
        const s = (w.x * dir.y - w.y * dir.x) / denominator;
        // Half-open on the edge, so a line through a vertex counts it once.
        if (s >= 0 && s < 1) {
            crossings.push(t);
        }
    });
    crossings.sort((x, y) => x - y);
    const stretches: [number, number][] = [];
    for (let i = 0; i + 1 < crossings.length; i += 2) {
        if (crossings[i + 1] - crossings[i] > 1e-6) {
            stretches.push([crossings[i], crossings[i + 1]]);
        }
    }
    return stretches;
}

/** The materials' colours by id. */
function materialColours(materials: readonly Material[]): Map<number, string> {
    return new Map(materials.map((m) => [m.id, m.color]));
}

/** A colour mixed with white (`amount` of white), as a drawing's tint. */
export function lighten(colour: string, amount = 0.6): string {
    const match = /^#?([0-9a-f]{6})$/i.exec(colour);
    if (match === null) {
        return colour;
    }
    const value = parseInt(match[1], 16);
    const mix = (c: number): string =>
        Math.round(c + (255 - c) * amount)
            .toString(16)
            .padStart(2, '0');
    return `#${mix((value >> 16) & 255)}${mix((value >> 8) & 255)}${mix(value & 255)}`;
}

/** A curved solid flight, whose treads are solid blocks down to its base (`buildStair()`). */
const isBlockStair = (stair: Stair): boolean => stair.shape === 'Curved' && stair.construction === 'Solid';

/** Plots of all floors with their floor's elevation (their ground counts from it). */
function floorPlots(floors: readonly Scene[]): {plots: Plot[]; e: number}[] {
    return floors.filter((s) => (s.plots ?? []).length > 0).map((s) => ({plots: s.plots ?? [], e: s.floor.elevation}));
}

/** The ground level at a plan point, against ±0.000: the first plot holding it, else null. */
function groundLevel(plots: readonly {plots: Plot[]; e: number}[], point: Vec2): number | null {
    for (const floor of plots) {
        if (plotAt(floor.plots, point.x, point.y) !== undefined) {
            return floor.e + groundAt(floor.plots, point.x, point.y);
        }
    }
    return null;
}

/**
 * A façade's ground: the terrain along the line in front of the building (its nearest depth), from the building's
 * left to its right and `GroundMargin` past, filled down below the lowest point; drawn in front of all.
 */
function facadeGround(floors: readonly Scene[], plane: ViewPlane, bounds: BuildingView['bounds'], facade: boolean): ViewGroup[] {
    const plots = floorPlots(floors);
    if (!facade || plots.length === 0) {
        return [];
    }
    const depths = floors.flatMap((s) =>
        s.walls
            .filter((w) => !isSiteWall(w))
            .flatMap((w) => [
                {x: w.startX, y: w.startY},
                {x: w.endX, y: w.endY},
            ])
            .map((q) => dot({x: q.x - plane.origin.x, y: q.y - plane.origin.y}, plane.look)),
    );
    if (depths.length === 0) {
        return [];
    }
    const front = Math.min(...depths) - 300;
    const runs = groundProfile(plots, plane, front, bounds.minU, bounds.maxU);
    const base = Math.min(bounds.minV, ...runs.flat().map((q) => q.v)) - EarthDepth;
    return [{depth: front - 1, shapes: runs.flatMap((profile) => earth(profile, base))}];
}

/** The ground along a line square to `look` at `depth`, from u0 to u1: runs where a plot is. */
function groundProfile(plots: readonly {plots: Plot[]; e: number}[], plane: ViewPlane, depth: number, u0: number, u1: number): ViewPoint[][] {
    const runs: ViewPoint[][] = [[]];
    for (let u = u0; u <= u1 + 1e-6; u += GroundStep) {
        const at = {
            x: plane.origin.x + plane.right.x * u + plane.look.x * depth,
            y: plane.origin.y + plane.right.y * u + plane.look.y * depth,
        };
        const level = groundLevel(plots, at);
        if (level !== null) {
            runs[runs.length - 1].push(p(u, level));
        } else if (runs[runs.length - 1].length > 0) {
            runs.push([]);
        }
    }
    return runs.filter((run) => run.length > 1);
}

/** The ground under a profile: filled down to `base`, its line heavy. */
function earth(profile: readonly ViewPoint[], base: number): ViewShape[] {
    return [
        {points: [...profile, p(profile[profile.length - 1].u, base), p(profile[0].u, base)], closed: true, style: {fill: Earth}},
        {points: [...profile], closed: false, style: {stroke: Line, width: 0.5}},
    ];
}

/**
 * A section's cut parts: the ground along the line (cut out down to the lowest slab's underside where the building
 * stands), the walls (less the openings it passes through), the slabs, the roofs and the stair treads.
 */
function sectionCutShapes(floors: readonly Scene[], cut: SectionCut, plane: ViewPlane, bounds: BuildingView['bounds']): ViewShape[] {
    const length = Math.hypot(cut.end.x - cut.start.x, cut.end.y - cut.start.y);
    const dir = {x: (cut.end.x - cut.start.x) / length, y: (cut.end.y - cut.start.y) / length};
    // u runs along `right`, which is the line's direction (looking to its left).
    const along = (t: number): Vec2 => ({x: cut.start.x + dir.x * t, y: cut.start.y + dir.y * t});
    const filled: Style = {fill: CutFill, stroke: Ink, width: Medium};
    const shapes: ViewShape[] = [...cutGround(floors, along, length, bounds)];
    for (const scene of floors) {
        const e = scene.floor.elevation;
        const roofs: FloorRoofs = scene.roofs ?? [];
        // Slabs.
        for (const polygon of slabOutline(scene)) {
            for (const [from, to] of polygonStretches(polygon, cut.start, dir, length)) {
                shapes.push(rectangle(from, to, e - scene.floor.slabThickness, e, filled));
            }
        }
        // Walls, less the openings the line passes through.
        const joints = wallJoints(scene.walls);
        for (const wall of scene.walls) {
            const frame = wallFrame(wall);
            const {left, right} = wallFaces(wall, joints.get(wall.id));
            const topAt = wallTopAt(wall, roofs, frame);
            const bottomAt = wallBottomAt(wall, frame, scene.plots ?? []);
            for (const [from, to] of lineInRing(cut.start, dir, [...left, ...[...right].reverse()]).map(clamp(length)).filter(nonEmpty)) {
                const [a, b] = [along(from), along(to)];
                const top = (q: Vec2): number => e + (topAt?.(q) ?? wall.height);
                const bottom = (q: Vec2): number => e + (bottomAt?.(q) ?? 0);
                const middle = alongWall(frame, lerp(a, b, 0.5));
                const opening = wall.openings.find((o) => o.type !== 'Niche' && middle >= o.offset && middle <= o.offset + o.width);
                const solid = (low: (q: Vec2) => number, high: (q: Vec2) => number): void => {
                    if (high(a) - low(a) > 1 || high(b) - low(b) > 1) {
                        shapes.push({points: [p(from, low(a)), p(to, low(b)), p(to, high(b)), p(from, high(a))], closed: true, style: filled});
                    }
                };
                if (opening === undefined) {
                    solid(bottom, top);
                } else {
                    solid(bottom, () => e + opening.sillHeight);
                    solid(() => e + opening.sillHeight + opening.height, top);
                    if (opening.type === 'Window' || isLiftSlide(opening) || opening.leafStyle === 'Glazed') {
                        shapes.push(rectangle(from, to, e + opening.sillHeight, e + opening.sillHeight + opening.height, ThinGlass));
                    }
                }
            }
        }
        // Roofs: each plane's parts where it is the top, from the soffit to the covering.
        roofs.forEach((roof) => {
            const thickness = roofVerticalThickness(roof);
            const parts = planeParts(roof, roofs);
            roofPlanes(roof).forEach((rp, i) => {
                const under = (q: Vec2): number => e + rp.height + rp.gradient.x * (q.x - roof.x) + rp.gradient.y * (q.y - roof.y);
                for (const polygon of parts[i] ?? []) {
                    for (const [from, to] of polygonStretches(polygon, cut.start, dir, length)) {
                        const [a, b] = [along(from), along(to)];
                        const points = [p(from, under(a)), p(to, under(b)), p(to, under(b) + thickness), p(from, under(a) + thickness)];
                        shapes.push({points, closed: true, style: filled});
                    }
                }
            });
        });
        // Stair treads (a solid flight down a riser and its waist, a curved solid one's blocks down to its base).
        for (const stair of scene.stairs ?? []) {
            const geometry = stairGeometry(stair, scene.stairRise ?? 0);
            const base = e + (stair.rise == null ? 0 : (stair.baseZ ?? 0));
            const depth = stair.construction === 'Solid' ? geometry.riser + 150 : 40;
            for (const tread of [...geometry.treads, ...geometry.landings]) {
                for (const [from, to] of lineInRing(cut.start, dir, tread.outline).map(clamp(length)).filter(nonEmpty)) {
                    const top = base + tread.top;
                    shapes.push(rectangle(from, to, isBlockStair(stair) ? base : top - depth, top, filled));
                }
            }
        }
    }
    return shapes;
}

const clamp =
    (length: number) =>
    ([from, to]: [number, number]): [number, number] => [Math.max(from, 0), Math.min(to, length)];
const nonEmpty = ([from, to]: [number, number]): boolean => to - from > 0.5;

/** The stretches of the line inside a polygon (its outer ring less its holes), clamped to the line. */
function polygonStretches(polygon: Polygon, origin: Vec2, dir: Vec2, length: number): [number, number][] {
    const rings = polygon.map((r) => r.slice(0, -1).map(toVec));
    const crossings = rings.flatMap((r) => lineInRing(origin, dir, r).flat());
    crossings.sort((x, y) => x - y);
    const stretches: [number, number][] = [];
    for (let i = 0; i + 1 < crossings.length; i += 2) {
        stretches.push([crossings[i], crossings[i + 1]]);
    }
    return stretches.map(clamp(length)).filter(nonEmpty);
}

/**
 * The ground along a section line, cut and filled down below the lowest point: where the building stands (inside a
 * slabbed room or a wall of any floor), only up to the underside of its lowest slab there, as the house is dug in.
 */
function cutGround(floors: readonly Scene[], along: (t: number) => Vec2, length: number, bounds: BuildingView['bounds']): ViewShape[] {
    const plots = floorPlots(floors);
    if (plots.length === 0) {
        return [];
    }
    const outlines = floors.map((scene) => ({bottom: scene.floor.elevation - scene.floor.slabThickness, outline: slabOutline(scene)}));
    const runs: ViewPoint[][] = [];
    let run: ViewPoint[] = [];
    const steps = Math.ceil(length / GroundStep);
    for (let i = 0; i <= steps; i++) {
        const t = (length * i) / steps;
        const at = along(t);
        let level = groundLevel(plots, at);
        if (level === null) {
            if (run.length > 1) {
                runs.push(run);
            }
            run = [];
            continue;
        }
        for (const {bottom, outline} of outlines) {
            if (outline.some((polygon) => insidePolygon(at, polygon))) {
                level = Math.min(level, bottom);
            }
        }
        run.push(p(t, level));
    }
    if (run.length > 1) {
        runs.push(run);
    }
    const base = Math.min(bounds.minV, ...runs.flat().map((q) => q.v)) - EarthDepth;
    return runs.flatMap((profile) => earth(profile, base));
}

function insidePolygon(point: Vec2, polygon: Polygon): boolean {
    const [outer, ...holes] = polygon.map((r) => r.slice(0, -1) as PlanPoint[]);
    return pointInPolygon(point, outer) && !holes.some((hole) => pointInPolygon(point, hole));
}

/** The level marks: every floor's level, and each roof's eaves and ridge (the top of its covering). */
function levelMarks(floors: readonly Scene[]): LevelMark[] {
    const marks: LevelMark[] = [];
    for (const scene of floors) {
        const e = scene.floor.elevation;
        marks.push({level: e, label: scene.floor.name});
        for (const roof of scene.roofs ?? []) {
            if (roof.shape === 'Flat') {
                marks.push({level: e + roof.eaveHeight + roofVerticalThickness(roof) + (roof.parapet ?? 0), label: 'Roof'});
                continue;
            }
            marks.push({level: e + roof.eaveHeight, label: 'Eaves'});
            marks.push({level: e + roofRidgeHeight(roof) + roofVerticalThickness(roof), label: 'Ridge'});
        }
    }
    // One mark per level (the first label), highest first.
    const seen = new Map<number, LevelMark>();
    for (const mark of marks) {
        const key = Math.round(mark.level);
        if (!seen.has(key)) {
            seen.set(key, {level: key, label: mark.label});
        }
    }
    return [...seen.values()].sort((a, b) => b.level - a.level);
}

/** Paper size of a drawing at a scale (1:scale), with the gutter for the level marks. */
function drawingSize(view: BuildingView, scale: number): {width: number; height: number} {
    return {width: (view.bounds.maxU - view.bounds.minU) / scale + MarkGutter, height: (view.bounds.maxV - view.bounds.minV) / scale};
}

/** The largest of `BuildingScales` at which every view fits a cell, else the smallest. */
function fittingScale(views: readonly BuildingView[], width: number, height: number): number {
    return (
        BuildingScales.find((scale) => views.every((view) => {
            const size = drawingSize(view, scale);
            return size.width <= width && size.height <= height;
        })) ?? BuildingScales[BuildingScales.length - 1]
    );
}

let clipIds = 0;

/** The paper's area for drawings: inside the frame and its padding, above the title band. */
function drawingArea(width: number, height: number): {x: number; y: number; width: number; height: number} {
    const inset = Margin + Padding;
    return {x: inset, y: inset, width: width - 2 * inset, height: height - 2 * Margin - TitleBand - 2 * Padding};
}

/**
 * A drawing on paper: its bottom-left corner at `at` (paper mm), the groups far to near, the cut over them and the
 * level marks at its right, each a tick on a short line out from the drawing with its level and label.
 */
function drawViewSvg(view: BuildingView, at: Vec2, scale: number): string[] {
    const toPaper = (q: ViewPoint): Vec2 => ({x: at.x + (q.u - view.bounds.minU) / scale, y: at.y - (q.v - view.bounds.minV) / scale});
    const draw = (shape: ViewShape): string => path(shape.points.map(toPaper), shape.closed, shape.style);
    const right = at.x + (view.bounds.maxU - view.bounds.minU) / scale;
    const top = at.y - (view.bounds.maxV - view.bounds.minV) / scale;
    // Clipped to the extent: the site beyond it and the ground's depth.
    const id = `view-${(clipIds += 1)}`;
    const body = [
        `<clipPath id="${id}"><rect x="${n(at.x)}" y="${n(top)}" width="${n(right - at.x)}" height="${n(at.y - top)}"/></clipPath>`,
        `<g clip-path="url(#${id})">`,
        ...view.groups.flatMap((g) => g.shapes.map(draw)),
        ...view.cut.map(draw),
        '</g>',
    ];
    let lastY = -Infinity;
    for (const mark of view.marks) {
        if (mark.level < view.bounds.minV || mark.level > view.bounds.maxV) {
            continue;
        }
        const y = at.y - (mark.level - view.bounds.minV) / scale;
        if (y - lastY < 4.4) {
            continue;
        }
        lastY = y;
        const x = right + 2;
        body.push(
            line({x: right + 0.5, y}, {x: x + 6, y}, {stroke: Muted, width: Thin}),
            path(
                [
                    {x: x + 1.4, y},
                    {x: x + 0.2, y: y - 1.6},
                    {x: x + 2.6, y: y - 1.6},
                ],
                true,
                {fill: Ink},
            ),
            text({x: x + 3.4, y: y - 0.5}, levelText(mark.level), 2),
            text({x: x + 3.4, y: y + 2.2}, mark.label, 1.6, {color: Muted}),
        );
    }
    return body;
}

/** How façade views may share the A3 sheets: columns × rows on a sheet. */
const FacadeLayouts: readonly {columns: number; rows: number}[] = [
    {columns: 2, rows: 2},
    {columns: 2, rows: 1},
];

/**
 * The façades on A3 sheets: the building seen from plan south, north, east and west (named by the true compass when
 * the project's north is turned), all at one scale: four to a sheet, or two to a sheet where that allows a larger scale.
 */
export function facadeSheets(scenes: readonly Scene[], info: SheetInfo, northAngle = 0): Sheet[] {
    const width = A3Long;
    const height = A3Short;
    const sides: FacadeSide[] = ['South', 'North', 'East', 'West'];
    const views = sides.map((side) => buildingView(scenes, facadePlane(side)));
    const inner = drawingArea(width, height);
    const caption = 6;
    const cellOf = (layout: {columns: number; rows: number}): {width: number; height: number} => ({
        width: inner.width / layout.columns,
        height: inner.height / layout.rows,
    });
    const options = FacadeLayouts.map((layout) => {
        const cell = cellOf(layout);
        return {layout, cell, scale: fittingScale(views, cell.width - 4, cell.height - caption - 4)};
    });
    // The largest scale; of equal ones the fewer sheets.
    const {layout, cell, scale} = options.reduce((best, option) => (option.scale < best.scale ? option : best));
    const perSheet = layout.columns * layout.rows;
    const sheets: Sheet[] = [];
    for (let first = 0; first < views.length; first += perSheet) {
        const body: string[] = [frameSvg(width, height)];
        views.slice(first, first + perSheet).forEach((view, k) => {
            const size = drawingSize(view, scale);
            const left = inner.x + (k % layout.columns) * cell.width + (cell.width - size.width) / 2;
            const bottom = inner.y + Math.floor(k / layout.columns) * cell.height + (cell.height - caption + size.height) / 2;
            body.push(...drawViewSvg(view, {x: left, y: bottom}, scale));
            const name = `${facadeName(sides[first + k], northAngle)} elevation`;
            body.push(text({x: left + (size.width - MarkGutter) / 2, y: bottom + 5}, name, 3, {bold: true, anchor: 'middle'}));
        });
        body.push(titleBlockSvg(width, height, info, `Façade elevations 1:${scale}`));
        sheets.push({width, height, svg: sheetSvg(width, height, body)});
    }
    return sheets;
}

/** A section's sheet: A4 across, the drawing at the largest scale that fits, the section's name under it. */
export function sectionSheet(scenes: readonly Scene[], cut: SectionCut, info: SheetInfo): Sheet | null {
    const plane = sectionPlane(cut);
    if (plane === null) {
        return null;
    }
    const view = buildingView(scenes, plane, cut);
    const width = PaperLong;
    const height = PaperShort;
    const inner = drawingArea(width, height);
    const caption = 7;
    const scale = fittingScale([view], inner.width, inner.height - caption);
    const size = drawingSize(view, scale);
    const left = inner.x + (inner.width - size.width) / 2;
    const bottom = inner.y + (inner.height - caption + size.height) / 2;
    const title = `Section ${cut.name}`;
    const body = [
        frameSvg(width, height),
        ...drawViewSvg(view, {x: left, y: bottom}, scale),
        text({x: left + (size.width - MarkGutter) / 2, y: bottom + 5.5}, title, 3.2, {bold: true, anchor: 'middle'}),
        titleBlockSvg(width, height, info, `${truncateName(title)} 1:${scale}`),
    ];
    return {width, height, svg: sheetSvg(width, height, body)};
}

/** A section's name kept short in the title block. */
function truncateName(value: string): string {
    return textWidth(value, 2.2) > 60 ? `${value.slice(0, 40)}…` : value;
}

/** The section sheets of the cuts, in their order (a cut along no length is left out). */
export function sectionSheets(scenes: readonly Scene[], cuts: readonly SectionCut[], info: SheetInfo): Sheet[] {
    return cuts.flatMap((cut) => {
        const sheet = sectionSheet(scenes, cut, info);
        return sheet === null ? [] : [sheet];
    });
}
