import {PlanPoint, Room, Scene, Stair} from '@app/models/scene';
import polygonClipping, {Pair, Polygon, Ring} from 'polygon-clipping';
import * as THREE from 'three';

import {MaterialLibrary} from './materials';
import {mergeStatic} from './merge';
import {MM, polygonBounds, Vec2} from './plan-geometry';

/** mm, the highest riser an automatic count gives (`StairRise::MaxAutoRiser`). */
export const MaxAutoRiser = 180;
/** mm, the handrail above the nosings, and above a landing's floor. */
export const RailHeight = 900;
/** mm, a solid flight's waist: the sloping slab under the treads, measured square to it. */
export const Waist = 150;
/** mm, a tread board on stringers, floating, or on a spiral's pole. */
export const TreadThickness = 40;
/** mm, a tread's nosing past the riser under it (treads on stringers and floating ones). */
export const Nosing = 20;
/** mm, a solid landing's slab. */
export const LandingSlab = 250;
/** mm, a landing's platform on stringers or floating. */
export const LandingBoard = 60;
/** mm, a closed stringer: its depth, and how far its top edge stands above the nosings. */
export const StringerDepth = 250;
export const StringerAbove = 60;
export const StringerThickness = 50;
/** mm, a spiral's central pole. */
export const PoleRadius = 60;
/** The walking line of a spiral, as a share of its radius from the centre: where its going is measured. */
export const SpiralWalkLine = 2 / 3;
/** mm, the handrail's and the posts' thickness, and the farthest apart posts stand. */
export const RailRadius = 20;
export const PostSize = 30;
export const PostSpacing = 1200;

/** The risers a stair has (`StairRise::risers()`): as given, or as few as keep each at most `MaxAutoRiser` (two at least). */
export function stairRisers(rise: number, risers: number | null): number {
    return risers ?? Math.max(2, Math.ceil(rise / MaxAutoRiser));
}

/** The risers up to an L or U stair's landing: as given (within the count), else half of them. */
export function stairFirstFlight(risers: number, firstFlight: number | null): number {
    return Math.min(risers - 1, Math.max(1, firstFlight ?? Math.floor(risers / 2)));
}

/** A flight's plan frame: `d` is the walking-up direction, `r` the right of it, both unit vectors. */
export interface StairFrame {
    origin: Vec2;
    d: Vec2;
    r: Vec2;
}

/** The frame of a direction in degrees counter-clockwise, 0 walking north (+y). */
export function stairFrame(origin: Vec2, direction: number): StairFrame {
    const a = (direction * Math.PI) / 180;
    return {origin, d: {x: -Math.sin(a), y: Math.cos(a)}, r: {x: Math.cos(a), y: Math.sin(a)}};
}

/** The plan point `u` mm up the frame and `v` mm to its right. */
export function framePoint(frame: StairFrame, u: number, v: number): Vec2 {
    return {x: frame.origin.x + u * frame.d.x + v * frame.r.x, y: frame.origin.y + u * frame.d.y + v * frame.r.y};
}

/** One straight run of steps: from the middle of its first riser up `direction`, its last riser onto a landing or the floor above. */
export interface StairFlight {
    origin: Vec2;
    /** Degrees counter-clockwise, 0 north. */
    direction: number;
    width: number;
    /** mm, the floor or landing it starts from. */
    base: number;
    /** The number of its first riser (1 for the first flight). */
    first: number;
    /** Risers in the flight, the last one onto what it climbs to. */
    risers: number;
}

/** A landing (or a tread) in plan with its top. */
export interface StairStep {
    outline: Vec2[];
    /** mm above the stair's floor. */
    top: number;
}

/** A tread: the one standing on riser `riser` (its front edge is that riser's nosing). */
export interface StairTread extends StairStep {
    riser: number;
}

/** A point of a line over the stair with its height, mm above the floor. */
export interface StairLinePoint {
    point: Vec2;
    height: number;
}

/** A handrail's path on one side (walking up), `RailHeight` above the steps under it. */
export interface StairRail {
    side: 'Left' | 'Right';
    path: StairLinePoint[];
}

/** A spiral's turning: the pole's centre, the outer radius and the treads' angles (degrees counter-clockwise from +x). */
export interface StairSpiral {
    centre: Vec2;
    radius: number;
    /** The first riser's angle. */
    start: number;
    /** Per tread; negative turning right. */
    step: number;
}

/**
 * The stair laid out: its flights and landings, every tread with its top, the footprint (the void it cuts through the
 * floor above), the walking line and the handrails. Lengths are plan mm, heights mm above the stair's floor.
 */
export interface StairGeometry {
    shape: Stair['shape'];
    rise: number;
    risers: number;
    /** mm, the height of one riser. */
    riser: number;
    going: number;
    width: number;
    flights: StairFlight[];
    landings: StairStep[];
    treads: StairTread[];
    footprint: Vec2[];
    walkLine: StairLinePoint[];
    rails: StairRail[];
    spiral: StairSpiral | null;
}

/** The run of a flight in plan, mm: from its first riser to its last. */
export function flightRun(flight: StairFlight, going: number): number {
    return (flight.risers - 1) * going;
}

/**
 * Lays out a stair climbing `rise` mm. (x, y) is the middle of the first riser and `rotation` the walking-up direction.
 * A straight stair is one flight; an L stair turns on a square quarter landing, a U stair comes back beside itself
 * over a half landing (`gap` between the flights); a spiral winds round a pole, `width` being its diameter.
 */
export function stairGeometry(stair: Stair, rise: number): StairGeometry {
    const risers = stairRisers(rise, stair.risers);
    const riser = rise / risers;
    const going = stair.going;
    const width = stair.width;
    const origin = {x: stair.x, y: stair.y};
    const base: Omit<StairGeometry, 'flights' | 'landings' | 'treads' | 'footprint' | 'walkLine' | 'rails' | 'spiral'> = {
        shape: stair.shape,
        rise,
        risers,
        riser,
        going,
        width,
    };

    if (stair.shape === 'Spiral') {
        return {...base, ...spiralLayout(stair, origin, risers, riser)};
    }
    const first: StairFlight = {origin, direction: stair.rotation, width, base: 0, first: 1, risers};
    const frame = stairFrame(origin, stair.rotation);
    if (stair.shape === 'Straight') {
        const run = flightRun(first, going);
        return {
            ...base,
            flights: [first],
            landings: [],
            treads: flightTreads(first, going, riser),
            footprint: rect(frame, 0, run, -width / 2, width / 2),
            walkLine: flightWalk(first, going, riser),
            rails: [railOf('Left', flightRail(first, going, riser, -width / 2)), railOf('Right', flightRail(first, going, riser, width / 2))],
            spiral: null,
        };
    }

    // The first flight ends on the landing; the second leaves it to the side (L) or back beside the first (U).
    const f1 = stairFirstFlight(risers, stair.firstFlight);
    const flight1: StairFlight = {...first, risers: f1};
    const u1 = flightRun(flight1, going);
    const top = f1 * riser;
    const s = stair.turn === 'Left' ? -1 : 1;
    const w = width;
    const gap = stair.shape === 'UShaped' ? stair.gap : 0;
    const back = stair.shape === 'UShaped';
    // The landing: a square beside which the second flight leaves (L), or wide enough for both flights and the gap (U).
    const far = s * (back ? w / 2 + gap + w : w / 2);
    const near = -s * (w / 2);
    const landing: StairStep = {outline: rect(frame, u1, u1 + w, Math.min(near, far), Math.max(near, far)), top};
    const flight2: StairFlight = {
        origin: back ? framePoint(frame, u1, s * (w + gap)) : framePoint(frame, u1 + w / 2, s * (w / 2)),
        direction: back ? stair.rotation + 180 : stair.rotation - s * 90,
        width: w,
        base: top,
        first: f1 + 1,
        risers: risers - f1,
    };
    const run2 = flightRun(flight2, going);
    const treads = [...flightTreads(flight1, going, riser), ...flightTreads(flight2, going, riser)];

    // Corners in the first flight's frame: v to the turning side is `s`, so the same list serves both turns.
    const footprint = back
        ? [
              [0, -s * (w / 2)],
              [u1 + w, -s * (w / 2)],
              [u1 + w, s * (w / 2 + gap + w)],
              [u1 - run2, s * (w / 2 + gap + w)],
              [u1 - run2, s * (w / 2 + gap)],
              [u1, s * (w / 2 + gap)],
              [u1, s * (w / 2)],
              [0, s * (w / 2)],
          ]
        : [
              [0, -s * (w / 2)],
              [0, s * (w / 2)],
              [u1, s * (w / 2)],
              [u1, s * (w / 2 + run2)],
              [u1 + w, s * (w / 2 + run2)],
              [u1 + w, -s * (w / 2)],
          ];
    const outline = dedupe(footprint.map(([u, v]) => framePoint(frame, u, v)));

    // The handrails: the outer side runs round the landing, the inner one meets the next flight at the corner (L) or
    // crosses the gap (U). `inner` is the turning side.
    const inner = stair.turn === 'Left' ? 'Left' : 'Right';
    const outer = inner === 'Left' ? 'Right' : 'Left';
    const sideOf = (side: 'Left' | 'Right'): number => (side === 'Left' ? -w / 2 : w / 2);
    const landingRail = (points: [number, number][]): StairLinePoint[] =>
        points.map(([u, v]) => ({point: framePoint(frame, u, v), height: top + RailHeight}));
    const innerPath = [
        ...flightRail(flight1, going, riser, sideOf(inner)),
        ...(back ? landingRail([[u1, s * (w / 2 + gap)]]) : []),
        ...flightRail(flight2, going, riser, sideOf(inner)),
    ];
    const outerPath = [
        ...flightRail(flight1, going, riser, sideOf(outer)),
        ...landingRail(
            back
                ? [
                      [u1 + w, -s * (w / 2)],
                      [u1 + w, s * (w / 2 + gap + w)],
                      [u1, s * (w / 2 + gap + w)],
                  ]
                : [
                      [u1 + w, -s * (w / 2)],
                      [u1 + w, s * (w / 2)],
                  ],
        ),
        ...flightRail(flight2, going, riser, sideOf(outer)),
    ];
    return {
        ...base,
        flights: [flight1, flight2],
        landings: [landing],
        treads,
        footprint: outline,
        walkLine: [...flightWalk(flight1, going, riser), ...flightWalk(flight2, going, riser)],
        rails: [railOf(inner, dedupeLine(innerPath)), railOf(outer, dedupeLine(outerPath))],
        spiral: null,
    };
}

type StairLayout = Pick<StairGeometry, 'flights' | 'landings' | 'treads' | 'footprint' | 'walkLine' | 'rails' | 'spiral'>;

function spiralLayout(stair: Stair, origin: Vec2, risers: number, riser: number): StairLayout {
    const radius = stair.width / 2;
    const s = stair.turn === 'Left' ? 1 : -1;
    const frame = stairFrame(origin, stair.rotation);
    // The first riser runs from the pole outwards; its middle is (x, y), the pole to the inner side of the walk.
    const centre = framePoint(frame, 0, -s * (radius / 2));
    const start = (Math.atan2(origin.y - centre.y, origin.x - centre.x) * 180) / Math.PI;
    const step = (s * (stair.going / (radius * SpiralWalkLine)) * 180) / Math.PI;
    const at = (r: number, angle: number): Vec2 => ({
        x: centre.x + r * Math.cos((angle * Math.PI) / 180),
        y: centre.y + r * Math.sin((angle * Math.PI) / 180),
    });
    const treads: StairTread[] = [];
    for (let k = 1; k < risers; k++) {
        const a = start + (k - 1) * step;
        const b = a + step;
        const outer = arcPoints(centre, radius, a, b, 4);
        const inner = arcPoints(centre, PoleRadius, b, a, 2);
        treads.push({outline: [...outer, ...inner], top: k * riser, riser: k});
    }
    const walkLine: StairLinePoint[] = [];
    const railPath: StairLinePoint[] = [];
    for (let j = 0; j <= risers - 1; j++) {
        const angle = start + j * step;
        walkLine.push({point: at(radius * SpiralWalkLine, angle), height: j * riser});
        railPath.push({point: at(radius, angle), height: (j + 1) * riser + RailHeight});
    }
    walkLine[walkLine.length - 1].height = risers * riser;
    return {
        flights: [],
        landings: [],
        treads,
        footprint: arcPoints(centre, radius, 0, 360 - 360 / 32, 31),
        walkLine,
        rails: [railOf(stair.turn === 'Left' ? 'Right' : 'Left', railPath)],
        spiral: {centre, radius, start, step},
    };
}

/** `count` + 1 points along the arc from angle `a` to `b` (degrees). */
function arcPoints(centre: Vec2, radius: number, a: number, b: number, count: number): Vec2[] {
    const points: Vec2[] = [];
    for (let i = 0; i <= count; i++) {
        const angle = ((a + ((b - a) * i) / count) * Math.PI) / 180;
        points.push({x: centre.x + radius * Math.cos(angle), y: centre.y + radius * Math.sin(angle)});
    }
    return points;
}

function rect(frame: StairFrame, u0: number, u1: number, v0: number, v1: number): Vec2[] {
    return [framePoint(frame, u0, v0), framePoint(frame, u1, v0), framePoint(frame, u1, v1), framePoint(frame, u0, v1)];
}

/** The treads of a flight: one on each riser but the last, `going` deep, `riser` higher than the one before. */
export function flightTreads(flight: StairFlight, going: number, riser: number): StairTread[] {
    const frame = stairFrame(flight.origin, flight.direction);
    const treads: StairTread[] = [];
    for (let k = 0; k < flight.risers - 1; k++) {
        treads.push({
            outline: rect(frame, k * going, (k + 1) * going, -flight.width / 2, flight.width / 2),
            top: flight.base + (k + 1) * riser,
            riser: flight.first + k,
        });
    }
    return treads;
}

/** The middle of a flight from its first riser (at its base) to its last (at what it climbs to). */
function flightWalk(flight: StairFlight, going: number, riser: number): StairLinePoint[] {
    const frame = stairFrame(flight.origin, flight.direction);
    return [
        {point: framePoint(frame, 0, 0), height: flight.base},
        {point: framePoint(frame, flightRun(flight, going), 0), height: flight.base + flight.risers * riser},
    ];
}

/** A handrail along a flight's side (`v` from its middle): `RailHeight` over the nosing line, from the first riser to the last. */
function flightRail(flight: StairFlight, going: number, riser: number, v: number): StairLinePoint[] {
    const frame = stairFrame(flight.origin, flight.direction);
    return [
        {point: framePoint(frame, 0, v), height: flight.base + riser + RailHeight},
        {point: framePoint(frame, flightRun(flight, going), v), height: flight.base + flight.risers * riser + RailHeight},
    ];
}

function railOf(side: 'Left' | 'Right', path: StairLinePoint[]): StairRail {
    return {side, path};
}

function dedupe(points: Vec2[]): Vec2[] {
    return points.filter((p, i) => i === 0 || Math.hypot(p.x - points[i - 1].x, p.y - points[i - 1].y) > 0.5);
}

function dedupeLine(points: StairLinePoint[]): StairLinePoint[] {
    return points.filter((p, i) => i === 0 || Math.hypot(p.point.x - points[i - 1].point.x, p.point.y - points[i - 1].point.y) > 0.5);
}

/** The height of the stair under a plan point, mm above its floor: the tread or landing there, else null. */
export function stairHeightAt(geometry: StairGeometry, point: Vec2): number | null {
    for (const step of [...geometry.treads, ...geometry.landings]) {
        if (pointInOutline(point, step.outline)) {
            return step.top;
        }
    }
    return null;
}

function pointInOutline(point: Vec2, outline: readonly Vec2[]): boolean {
    let inside = false;
    for (let i = 0, j = outline.length - 1; i < outline.length; j = i++) {
        const a = outline[i];
        const b = outline[j];
        if (a.y > point.y !== b.y > point.y && point.x < ((b.x - a.x) * (point.y - a.y)) / (b.y - a.y) + a.x) {
            inside = !inside;
        }
    }
    return inside;
}

/**
 * The voids the floor's stairs cut (`own`: through the flat ceilings of its rooms) and the floor below's stairs cut
 * through it (`below`: its slab and room floors).
 */
export interface SceneVoids {
    own: PlanPoint[][];
    below: PlanPoint[][];
}

export const NoVoids: SceneVoids = {own: [], below: []};

/** The stairs' footprints as `PlanPoint` polygons. */
export function stairFootprints(stairs: readonly Stair[], rise: number): PlanPoint[][] {
    return stairs.map((stair) => stairGeometry(stair, rise).footprint.map((p): PlanPoint => [p.x, p.y]));
}

export function sceneVoids(scene: Pick<Scene, 'stairs' | 'stairRise' | 'stairsBelow'>): SceneVoids {
    const below = scene.stairsBelow ?? null;
    return {
        own: stairFootprints(scene.stairs ?? [], scene.stairRise ?? 0),
        below: below === null ? [] : stairFootprints(below.stairs, below.rise),
    };
}

/** An outline with holes, as the floors and ceilings are drawn once the voids are cut. */
export interface CutOutline {
    outer: Vec2[];
    holes: Vec2[][];
}

/**
 * The outline less the voids: zero, one or more pieces, each with the holes left inside it. A void reaching over the
 * outline's edge notches it rather than leaving a hole touching the edge.
 */
export function cutVoids(outline: readonly PlanPoint[], voids: readonly PlanPoint[][]): CutOutline[] {
    if (voids.length === 0 || outline.length < 3) {
        return [{outer: outline.map(([x, y]) => ({x, y})), holes: []}];
    }
    const ring = (polygon: readonly PlanPoint[]): Ring => polygon.map(([x, y]): Pair => [x, y]);
    const subject: Polygon = [ring(outline)];
    const result = polygonClipping.difference(subject, ...voids.filter((v) => v.length >= 3).map((v): Polygon => [ring(v)]));
    return result.map((polygon) => ({outer: openRing(polygon[0]), holes: polygon.slice(1).map(openRing)})).filter((piece) => piece.outer.length >= 3);
}

/** A ring without its closing point (polygon-clipping repeats the first point last). */
function openRing(ring: Ring): Vec2[] {
    const points = ring.map(([x, y]) => ({x, y}));
    const first = points[0];
    const last = points[points.length - 1];
    return points.length > 1 && first.x === last.x && first.y === last.y ? points.slice(0, -1) : points;
}

/** The rooms a void may cut: those whose bounds overlap a footprint's. */
export function roomsCutBy(rooms: readonly Room[], footprints: readonly PlanPoint[][]): Set<number> {
    const boxes = footprints.filter((f) => f.length >= 3).map(polygonBounds);
    const cut = new Set<number>();
    for (const room of rooms) {
        const bounds = polygonBounds(room.polygon);
        if (boxes.some((b) => b.minX <= bounds.maxX && b.maxX >= bounds.minX && b.minY <= bounds.maxY && b.maxY >= bounds.minY)) {
            cut.add(room.id);
        }
    }
    return cut;
}

/** A `THREE.Shape` of a cut outline in scene metres on the plan (x, y), to be laid flat by rotating −90° about x. */
export function cutShape(piece: CutOutline): THREE.Shape {
    const shape = new THREE.Shape(piece.outer.map((p) => new THREE.Vector2(p.x * MM, p.y * MM)));
    for (const hole of piece.holes) {
        shape.holes.push(new THREE.Path(hole.map((p) => new THREE.Vector2(p.x * MM, p.y * MM))));
    }
    return shape;
}

const ConcreteColor = '#c9c5bd';
const TimberColor = '#b08760';
const SteelColor = '#4a4845';

/**
 * The stair in 3D, in its own group at the stair's (x, y) so a drag moves it: the flights (a solid run with a sloping
 * soffit, stringers with treads and risers, or floating treads), the landings, the handrails with their posts, and a
 * spiral's pole and wedge treads. Tagged `stairId` for picking; the solid parts have a section-fill twin for the wall
 * cut. The group is merged (`mergeStatic()`).
 */
export function buildStair(stair: Stair, rise: number, materials: MaterialLibrary, sectionMaterial: THREE.Material): THREE.Group {
    const geometry = stairGeometry({...stair, x: 0, y: 0}, rise);
    const group = new THREE.Group();
    group.name = `stair:${stair.name}`;
    group.userData = {stairId: stair.id};
    group.position.set(stair.x * MM, 0, -stair.y * MM);

    const solid = stair.construction === 'Solid' && stair.shape !== 'Spiral';
    const structure =
        stair.structureMaterialId === null
            ? materials.plain(solid ? ConcreteColor : SteelColor, solid ? 0.9 : 0.6, 0)
            : materials.get(stair.structureMaterialId);
    const tread =
        stair.treadMaterialId === null
            ? solid
                ? structure
                : materials.plain(TimberColor, 0.6, 0)
            : materials.get(stair.treadMaterialId, 'horizontal');

    for (const flight of geometry.flights) {
        group.add(buildFlight(flight, geometry, stair.construction, structure, tread, sectionMaterial));
    }
    for (const landing of geometry.landings) {
        const thickness = solid ? LandingSlab : LandingBoard;
        const slab = extrudeStep(landing.outline, landing.top - thickness, landing.top, solid ? structure : tread);
        group.add(slab);
        if (solid) {
            group.add(sectionTwin(slab, sectionMaterial));
        }
    }
    if (geometry.spiral !== null) {
        group.add(buildSpiral(geometry, geometry.spiral, structure, tread));
    }
    if (stair.railing !== 'None') {
        for (const r of geometry.rails) {
            if (stair.railing === 'Both' || stair.railing === r.side || geometry.spiral !== null) {
                group.add(buildRail(r, structure));
            }
        }
    }
    mergeStatic(group);
    return group;
}

/** One flight in its frame: x up the flight, y up, z to the right; at the flight's base height. */
function buildFlight(
    flight: StairFlight,
    geometry: StairGeometry,
    construction: Stair['construction'],
    structure: THREE.Material,
    tread: THREE.Material,
    sectionMaterial: THREE.Material,
): THREE.Group {
    const group = new THREE.Group();
    group.name = 'flight';
    group.position.set(flight.origin.x * MM, flight.base * MM, -flight.origin.y * MM);
    group.rotation.y = ((flight.direction + 90) * Math.PI) / 180;
    const {riser: h, going: g} = geometry;
    const w = flight.width;
    const n = flight.risers;
    const run = flightRun(flight, g);

    if (construction === 'Solid') {
        // The sawtooth of risers and treads over a soffit `Waist` under the nosing line, meeting the floor at the start.
        const tv = Waist * Math.sqrt(1 + (h / g) * (h / g));
        const u0 = Math.max(0, ((tv - h) * g) / h);
        const soffit = (u: number): number => ((u - u0) * h) / g;
        const profile: [number, number][] = [[0, 0]];
        for (let k = 0; k < n - 1; k++) {
            profile.push([k * g, (k + 1) * h], [(k + 1) * g, (k + 1) * h]);
        }
        profile.push([run, n * h], [run, soffit(run)]);
        if (u0 > 0) {
            profile.push([u0, 0]);
        }
        const mesh = extrudeProfile(profile, w, structure);
        group.add(mesh, sectionTwin(mesh, sectionMaterial));
        return group;
    }

    for (let k = 0; k < n - 1; k++) {
        // Boards: the tread with its nosing over the riser, the riser board (on stringers) behind the nosing line.
        group.add(box(tread, k * g - Nosing, (k + 1) * h - TreadThickness, -w / 2, g + Nosing, TreadThickness, w));
        if (construction === 'Stringers') {
            group.add(box(structure, k * g, k * h, -w / 2 + StringerThickness, Nosing, h - TreadThickness, w - 2 * StringerThickness));
        }
    }
    if (construction === 'Stringers') {
        // Closed stringers: a band `StringerDepth` deep whose top edge runs `StringerAbove` over the nosings, down to the floor.
        const top = (u: number): number => h + StringerAbove + (u * h) / g;
        const bottom = (u: number): number => top(u) - StringerDepth;
        const meets = ((StringerDepth - StringerAbove - h) * g) / h;
        const under: [number, number][] =
            bottom(0) >= 0
                ? [
                      [0, bottom(0)],
                      [run, bottom(run)],
                  ]
                : meets >= run
                  ? [
                        [0, 0],
                        [run, 0],
                    ]
                  : [
                        [0, 0],
                        [meets, 0],
                        [run, bottom(run)],
                    ];
        const profile: [number, number][] = [...under, [run, top(run)], [0, top(0)]];
        for (const z of [-w / 2, w / 2 - StringerThickness]) {
            const stringer = extrudeProfile(profile, StringerThickness, structure);
            stringer.position.z = (z + StringerThickness / 2) * MM;
            group.add(stringer);
        }
    }
    return group;
}

/** A spiral's pole and wedge treads round it. */
function buildSpiral(geometry: StairGeometry, spiral: StairSpiral, structure: THREE.Material, tread: THREE.Material): THREE.Group {
    const group = new THREE.Group();
    group.name = 'spiral';
    const height = (geometry.rise + RailHeight) * MM;
    const pole = new THREE.Mesh(new THREE.CylinderGeometry(PoleRadius * MM, PoleRadius * MM, height, 16), structure);
    pole.position.set(spiral.centre.x * MM, height / 2, -spiral.centre.y * MM);
    pole.castShadow = true;
    pole.receiveShadow = true;
    group.add(pole);
    for (const step of geometry.treads) {
        group.add(extrudeStep(step.outline, step.top - TreadThickness, step.top, tread));
    }
    return group;
}

/** A handrail along its path with posts at its corners and at most `PostSpacing` apart, standing on the steps under it. */
function buildRail(handrail: StairRail, material: THREE.Material): THREE.Group {
    const group = new THREE.Group();
    group.name = `rail:${handrail.side}`;
    const at = (p: StairLinePoint): THREE.Vector3 => new THREE.Vector3(p.point.x * MM, p.height * MM, -p.point.y * MM);
    const posts: THREE.Vector3[] = [];
    handrail.path.forEach((p, i) => {
        if (i > 0) {
            const previous = handrail.path[i - 1];
            const a = at(previous);
            const b = at(p);
            const length = a.distanceTo(b);
            if (length < 0.001) {
                return;
            }
            const tube = new THREE.Mesh(new THREE.CylinderGeometry(RailRadius * MM, RailRadius * MM, length, 8), material);
            tube.position.copy(a).lerp(b, 0.5);
            tube.quaternion.setFromUnitVectors(new THREE.Vector3(0, 1, 0), b.clone().sub(a).normalize());
            tube.castShadow = true;
            group.add(tube);
            // Posts along the stretch, evenly, none closer than its ends.
            const plan = Math.hypot(p.point.x - previous.point.x, p.point.y - previous.point.y);
            const count = Math.ceil(plan / PostSpacing);
            for (let j = 1; j < count; j++) {
                posts.push(a.clone().lerp(b, j / count));
            }
        }
        posts.push(at(p));
    });
    for (const top of posts) {
        const post = new THREE.Mesh(new THREE.BoxGeometry(PostSize * MM, RailHeight * MM, PostSize * MM), material);
        post.position.set(top.x, top.y - (RailHeight * MM) / 2, top.z);
        post.castShadow = true;
        group.add(post);
    }
    return group;
}

/** A step or landing: the plan outline (stair-local mm) extruded from `bottom` to `top`. */
function extrudeStep(outline: readonly Vec2[], bottom: number, top: number, material: THREE.Material): THREE.Mesh {
    const shape = new THREE.Shape(outline.map((p) => new THREE.Vector2(p.x * MM, p.y * MM)));
    const geometry = new THREE.ExtrudeGeometry(shape, {depth: (top - bottom) * MM, bevelEnabled: false});
    geometry.rotateX(-Math.PI / 2);
    geometry.translate(0, bottom * MM, 0);
    const mesh = new THREE.Mesh(geometry, material);
    mesh.castShadow = true;
    mesh.receiveShadow = true;
    return mesh;
}

/** A profile in a flight's (u, height) plane, mm, extruded `width` to the right from the flight's left side. */
function extrudeProfile(profile: readonly [number, number][], width: number, material: THREE.Material): THREE.Mesh {
    const shape = new THREE.Shape(profile.map(([u, z]) => new THREE.Vector2(u * MM, z * MM)));
    const geometry = new THREE.ExtrudeGeometry(shape, {depth: width * MM, bevelEnabled: false});
    geometry.translate(0, 0, (-width / 2) * MM);
    const mesh = new THREE.Mesh(geometry, material);
    mesh.castShadow = true;
    mesh.receiveShadow = true;
    return mesh;
}

/** A box by its min corner and size, mm, in the flight's frame. */
function box(material: THREE.Material, x: number, y: number, z: number, width: number, height: number, depth: number): THREE.Mesh {
    const mesh = new THREE.Mesh(new THREE.BoxGeometry(width * MM, height * MM, depth * MM), material);
    mesh.position.set((x + width / 2) * MM, (y + height / 2) * MM, (z + depth / 2) * MM);
    mesh.castShadow = true;
    mesh.receiveShadow = true;
    return mesh;
}

/**
 * The floor below's stairs coming up through this floor (`stairsBelow`), drawn under its level so they show in their
 * voids: not pickable and untagged, as they are not this floor's to edit. Empty without a floor below.
 */
export function buildStairsBelow(below: Scene['stairsBelow'], materials: MaterialLibrary, sectionMaterial: THREE.Material): THREE.Group {
    const group = new THREE.Group();
    group.name = 'stairs-below';
    if (below == null) {
        return group;
    }
    group.position.y = -below.rise * MM;
    for (const stair of below.stairs) {
        const object = buildStair(stair, below.rise, materials, sectionMaterial);
        object.userData = {};
        object.traverse((o) => {
            o.raycast = (): void => undefined;
        });
        group.add(object);
    }
    return group;
}

/** The same geometry drawn inside-out in the section fill, so the wall cut shows the stair solid. */
function sectionTwin(mesh: THREE.Mesh, sectionMaterial: THREE.Material): THREE.Mesh {
    const twin = new THREE.Mesh(mesh.geometry, sectionMaterial);
    twin.position.copy(mesh.position);
    twin.rotation.copy(mesh.rotation);
    return twin;
}
