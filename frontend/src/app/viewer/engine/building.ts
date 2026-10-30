import {FloorVoid, isOutdoorRoom, Opening, PlanPoint, Plot, Railing, Roof, Room, Scene, Wall, WallFinish} from '@app/models/scene';
import * as THREE from 'three';
import {mergeGeometries} from 'three/examples/jsm/utils/BufferGeometryUtils.js';

import {
    ceilingSteps,
    clipToBand,
    EdgeFace,
    roomBeams,
    skirtingPieces,
    subtractSpans,
    vaultBreaks,
    vaultFrame,
    vaultHeight,
    VaultProfile,
    vaultProfile,
    wallFacesAlongEdge,
} from './ceilings';
import {buildFacades, facadeBands,FacadeLining} from './facades';
import {MaterialLibrary} from './materials';
import {mergeStatic} from './merge';
import {boxAt, curvedPrismAt, prismAt, worldUvBox} from './meshes';
import {exteriorSign, freeBeside, leafPanels, LeafStile, louvreSlats, PanelRect, shutterLeaves} from './opening-looks';
import {
    alongWall,
    cornerWindows,
    counterClockwise,
    DoorFrameWidth,
    frameBand,
    garageSections,
    isDoor,
    isLiftSlide,
    Leaf,
    LeafOpenAngle,
    MM,
    NoCorners,
    openingFrame,
    openingLeaves,
    pointOnWall,
    RollerDrum,
    rollerSlats,
    SlidingDoorOverlap,
    SquareEnds,
    tileGrid,
    Vec2,
    WallCorners,
    WallEnds,
    wallFaces,
    WallFrame,
    wallFrame,
    WallGap,
    wallGapsAlongEdge,
    wallJoints,
    wallOutline,
    WallPiece,
    wallPieces,
    WindowFrameBar,
} from './plan-geometry';
import {buildPlot, drapedGeometry, drapedOn, groundBeside} from './plots';
import {buildRailing} from './railings';
import {buildRoof, buildRoofOutline} from './roofs';
import {buildStair, buildStairsBelow, cutShape, cutVoids, NoVoids, SceneVoids,sceneVoids} from './stairs';
import {Terrain} from './terrain';
import {alignTiles, turnLevelUvs} from './tiling';
import {
    ceilingParts,
    FloorRoofs,
    HeightAt,
    lowestTopAlong,
    outlineWithout,
    ProfileRun,
    profileRuns,
    runsAreLevel,
    wallBottomAt,
    wallHeightAt,
    wallTopAt,
} from './under-roof';
import {buildVoidLinings, buildVoidOutline} from './voids';

/** Bars of a French window sash, mm. */
const SashBar = 55;

export interface BuildingParts {
    root: THREE.Group;
    /** Ceilings, toggled separately so the dollhouse view can look in from above. */
    ceilings: THREE.Group;
    /** The faces between rooms' ceilings of different heights where they meet without a wall (in `ceilings`). */
    ceilingSteps: THREE.Group;
    /** The slab under the floor, its pieces merged per material (`buildSlab()`). */
    slab: THREE.Group;
    /** Per room: its floor mesh (for picking and highlighting), ceiling and wall finishes, keyed by room id. */
    rooms: Map<number, RoomParts>;
    /** Plots (their ground and boundary) keyed by plot id. */
    plots: Map<number, THREE.Group>;
    /** The floor's stairs keyed by stair id. */
    stairs: Map<number, THREE.Group>;
    /** The floor below's stairs coming up through this floor (`buildStairsBelow()`); hidden while the stack draws that floor. */
    stairsBelow: THREE.Group;
    /** Wall groups (with their doors and windows) keyed by wall id. */
    walls: Map<number, THREE.Group>;
    /** Roofs (in `roofBodies`) and their top-view outlines (in `roofOutlines`), keyed by roof id. */
    roofs: Map<number, RoofParts>;
    /** The roofs, toggled separately (the Display's Roof) to look into the floor under them. */
    roofBodies: THREE.Group;
    /** The roofs' outlines, shown in the top view only. */
    roofOutlines: THREE.Group;
    /** Fill of wall cores revealed by the section plane, shared by all walls. */
    sectionMaterial: THREE.Material;
    /** The façade bands on the wall faces outside every room (`buildFacades()`). */
    facades: THREE.Group;
    /** Railings keyed by railing id (FD-90). */
    railings: Map<number, THREE.Group>;
    /** The voids' top-view outlines keyed by void id, in `voidOutlines`, shown in the top view only. */
    voids: Map<number, THREE.Group>;
    voidOutlines: THREE.Group;
    /** The linings of the voids through and over the floor (`buildVoidLinings()`). */
    voidLinings: THREE.Group;
}

export interface RoofParts {
    roof: THREE.Group;
    outline: THREE.Group;
    /** Its windows' groups in `roof`, keyed by roof window id. */
    windows: Map<number, THREE.Object3D>;
}

export interface RoomParts {
    floor: THREE.Mesh;
    /** The ceiling surface, with the beams of a vaulted one. */
    ceiling: THREE.Group | null;
    /** Wall finishes and skirting. */
    finishes: THREE.Group | null;
}

/**
 * Builds the static architecture: slab, floors, ceilings, walls with their doors and windows, stairs, railings. The parts
 * are kept apart so a changed room or wall can be rebuilt on its own (`buildRoom`, `buildWall`). The voids
 * (`sceneVoids()`) are cut through the slab and room floors (the floor below's stairs, the floor's own voids) and the
 * flat ceilings (its stairs, the floor above's voids), and lined (`buildVoidLinings()`).
 */
export function buildBuilding(scene: Scene, materials: MaterialLibrary): BuildingParts {
    const root = new THREE.Group();
    root.name = 'building';
    const ceilings = new THREE.Group();
    ceilings.name = 'ceilings';
    const voids = sceneVoids(scene);
    const roofs = scene.roofs ?? [];
    const sectionMaterial = materials.sectionFill();
    const parts: BuildingParts = {
        root,
        ceilings,
        ceilingSteps: buildCeilingSteps(scene.rooms, scene.walls, materials, roofs),
        slab: buildSlab(scene.walls, scene.rooms, scene.floor.slabThickness, materials, voids.below, scene.exteriorMaterialId ?? null),
        rooms: new Map(),
        plots: new Map(),
        stairs: new Map(),
        stairsBelow: buildStairsBelow(scene.stairsBelow, materials, sectionMaterial),
        walls: new Map(),
        roofs: new Map(),
        roofBodies: new THREE.Group(),
        roofOutlines: new THREE.Group(),
        sectionMaterial,
        facades: buildFacades(scene, materials),
        railings: new Map(),
        voids: new Map(),
        voidOutlines: new THREE.Group(),
        voidLinings: buildVoidLinings(scene, materials),
    };
    parts.roofOutlines.name = 'roof-outlines';
    parts.roofOutlines.visible = false;
    parts.roofBodies.name = 'roofs';
    parts.voidOutlines.name = 'void-outlines';
    parts.voidOutlines.visible = false;
    root.add(parts.slab, parts.stairsBelow, parts.roofBodies, parts.roofOutlines, parts.facades, parts.voidOutlines, parts.voidLinings);
    for (const railing of scene.railings ?? []) {
        addRailing(parts, railing, materials);
    }
    for (const floorVoid of scene.voids ?? []) {
        addVoid(parts, floorVoid);
    }

    for (const room of scene.rooms) {
        addRoom(parts, buildRoom(room, scene.walls, materials, scene.plots ?? [], voids, roofs), room.id);
    }
    for (const plot of scene.plots ?? []) {
        const group = buildPlot(plot, materials, scene.rooms);
        parts.plots.set(plot.id, group);
        root.add(group);
    }
    for (const stair of scene.stairs ?? []) {
        const group = buildStair(stair, scene.stairRise ?? 0, materials, sectionMaterial);
        parts.stairs.set(stair.id, group);
        root.add(group);
    }

    const joints = wallJoints(scene.walls);
    const corners = cornerWindows(scene.walls);
    for (const wall of scene.walls) {
        const group = buildWall(
            wall,
            materials,
            parts.sectionMaterial,
            joints.get(wall.id),
            corners.get(wall.id),
            roofs,
            scene.plots ?? [],
            scene.exteriorMaterialId ?? null,
        );
        parts.walls.set(wall.id, group);
        root.add(group);
    }

    for (const roof of scene.roofs ?? []) {
        addRoof(parts, roof, materials, scene.roofs);
    }

    ceilings.add(parts.ceilingSteps);
    root.add(ceilings);
    return parts;
}

/** Builds a railing into the building. */
export function addRailing(parts: BuildingParts, railing: Railing, materials: MaterialLibrary): THREE.Group {
    const group = buildRailing(railing, materials);
    parts.railings.set(railing.id, group);
    parts.root.add(group);
    return group;
}

/** Adds a void's top-view outline to the building. */
export function addVoid(parts: BuildingParts, floorVoid: FloorVoid): THREE.Group {
    const group = buildVoidOutline(floorVoid);
    parts.voids.set(floorVoid.id, group);
    parts.voidOutlines.add(group);
    return group;
}

/**
 * Builds a roof and its outline into the building: the roof with the other roofs, the outline with the others. `floorRoofs`
 * are all of the floor's, where it meets them (`buildRoof()`).
 */
export function addRoof(parts: BuildingParts, roof: Roof, materials: MaterialLibrary, floorRoofs: readonly Roof[] = []): RoofParts {
    const built: RoofParts = {
        roof: buildRoof(roof, materials, parts.sectionMaterial, floorRoofs),
        outline: buildRoofOutline(roof),
        windows: new Map(),
    };
    built.roof.traverse((o) => {
        const id = o.userData['roofWindowId'];
        if (typeof id === 'number') {
            built.windows.set(id, o);
        }
    });
    parts.roofs.set(roof.id, built);
    parts.roofBodies.add(built.roof);
    parts.roofOutlines.add(built.outline);
    return built;
}

/**
 * Floor, ceiling and wall finishes of one room (the finishes leave the walls' door openings free), all at the room's
 * floor elevation; the floor's edge steps down (or up) to the ground of the plot the room lies on. A garden, path or
 * driveway on a sloping plot follows its ground instead (`drapedOn()`). The stairs' voids
 * are cut out of the floor (the floor below's stairs) and a flat ceiling (the floor's own). Under the floor's `roofs`
 * the ceiling and the finishes follow their underside.
 */
export function buildRoom(
    room: Room,
    walls: readonly Wall[],
    materials: MaterialLibrary,
    plots: readonly Plot[] = [],
    voids: SceneVoids = NoVoids,
    roofs: FloorRoofs = [],
): RoomParts {
    const parts: RoomParts = {
        floor: buildFloor(room, materials, groundBeside(room, plots), voids.below, drapedOn(room, plots)),
        ceiling: room.ceilingHeight === null ? null : buildCeiling(room, materials, voids.own, roofs),
        finishes: buildRoomFinishes(room, walls, materials, roofs),
    };
    parts.ceiling?.position.setY(parts.ceiling.position.y + (room.floorElevation ?? 0) * MM);
    return parts;
}

/** A room's wall finishes and skirting; null when it has neither and no wall face has bands of its own. */
export function buildRoomFinishes(room: Room, walls: readonly Wall[], materials: MaterialLibrary, roofs: FloorRoofs = []): THREE.Group | null {
    const needed = room.wallFinishes.length > 0 || room.skirtingHeight != null || walls.some(hasFaceFinishes);
    return needed ? buildWallFinishes(room, walls, materials, roofs) : null;
}

/** Puts a room's parts into the building (ceilings into their own group). */
export function addRoom(parts: BuildingParts, room: RoomParts, roomId: number): void {
    parts.rooms.set(roomId, room);
    parts.root.add(room.floor);
    if (room.ceiling !== null) {
        parts.ceilings.add(room.ceiling);
    }
    if (room.finishes !== null) {
        parts.root.add(room.finishes);
    }
}

/** A wall standing on a base or on the ground rather than on the floor (FD-87). */
export function isSiteWall(wall: Pick<Wall, 'baseStart' | 'baseEnd' | 'onGround'>): boolean {
    return wall.onGround === true || (wall.baseStart ?? 0) !== 0 || (wall.baseEnd ?? 0) !== 0;
}

/** Rooms on the ground itself, which have no slab under them. */
const UnslabbedRooms: readonly Room['type'][] = ['Garden', 'Path', 'Driveway'];

/**
 * The slab under the floor, `thickness` mm deep: under its rooms (not gardens, paths and driveways, which lie on the
 * ground; a room's piece hangs under its own floor elevation) and its walls' footprints, so an upper floor's slab
 * roofs only what it covers. The outlines are extruded and merged into one mesh; where they overlap, their faces lie
 * inside the solid or coincide. The `voids` (the stairs coming up from the floor below) are cut out of every piece.
 * A room's piece is in its `soffitMaterialId`, else the project's façade material (`exterior`): its underside and edge
 * show where the floor overhangs the one below (an oriel, a balcony, FD-88); the rest is the plain slab.
 */
export function buildSlab(
    walls: readonly Wall[],
    rooms: readonly Room[],
    thickness: number,
    materials: MaterialLibrary,
    voids: readonly PlanPoint[][] = [],
    exterior: number | null = null,
): THREE.Group {
    const joints = wallJoints(walls);
    const plain = materials.plain('#d6d1c8', 0.95);
    const soffit = (room: Room): THREE.Material => {
        const id = room.soffitMaterialId ?? exterior;
        return id === null ? plain : materials.get(id);
    };
    const outlines: {outline: Vec2[]; top: number; material: THREE.Material}[] = [
        ...rooms
            .filter((r) => !UnslabbedRooms.includes(r.type))
            .map((r) => ({outline: r.polygon.map(([x, y]): Vec2 => ({x, y})), top: r.floorElevation ?? 0, material: soffit(r)})),
        // A site wall (on a base or the ground, FD-87) has a footing of its own, not the floor's slab.
        ...walls.filter((wall) => !isSiteWall(wall)).map((wall) => {
            const {left, right} = wallFaces(wall, joints.get(wall.id));
            return {outline: [...left, ...[...right].reverse()], top: 0, material: plain};
        }),
    ].filter(({outline}) => outline.length >= 3);
    const cut = outlines.flatMap(({outline, top, material}) =>
        cutVoids(
            outline.map((p): PlanPoint => [p.x, p.y]),
            voids,
        ).map((piece) => ({piece, top, material})),
    );
    // A mesh per piece, merged per material (`mergeStatic()`); UVs in metres, as a cladding's planks are sized.
    const slab = new THREE.Group();
    slab.name = 'slab';
    for (const {piece, top, material} of cut) {
        const geometry = new THREE.ExtrudeGeometry(cutShape(piece), {depth: thickness * MM, bevelEnabled: false});
        geometry.rotateX(-Math.PI / 2);
        geometry.translate(0, (top - thickness) * MM, 0);
        const mesh = new THREE.Mesh(geometry, material);
        mesh.castShadow = false;
        mesh.receiveShadow = true;
        slab.add(mesh);
    }
    mergeStatic(slab);
    return slab;
}

/** The room's outline less the voids, as one geometry in plan (x, y). */
function roomSurface(room: Room, voids: readonly PlanPoint[][]): THREE.BufferGeometry {
    const pieces = cutVoids(room.polygon, voids).map((piece) => new THREE.ShapeGeometry(cutShape(piece)));
    if (pieces.length === 1) {
        return pieces[0];
    }
    const geometry = pieces.length === 0 ? new THREE.BufferGeometry() : mergeGeometries(pieces);
    pieces.forEach((piece) => piece.dispose());
    return geometry;
}

/** The ground beside a room at a plan point, mm: the plot's (flat or sloping), else the floor's level. */
export type GroundLevel = (x: number, y: number) => number;

/**
 * The room's floor at its elevation; `ground` (mm) is the level beside it, the plot's ground or the floor's level,
 * which the floor's edge steps down or up to where the two differ (an indoor room's only down: its walls stand where
 * the ground is higher). On a `draped` terrain the floor follows it, `floorElevation` above it, with no edge. The
 * `voids` (stairs coming up from below) are cut out.
 */
export function buildFloor(
    room: Room,
    materials: MaterialLibrary,
    ground: GroundLevel = () => 0,
    voids: readonly PlanPoint[][] = [],
    draped: Terrain | null = null,
): THREE.Mesh {
    const elevation = room.floorElevation ?? 0;
    let geometry: THREE.BufferGeometry;
    if (draped !== null) {
        geometry = drapedGeometry(room.polygon, voids, draped, elevation + FloorLift);
    } else {
        // Shape in plan (x, y); rotating −90° about x lays it flat with plan y → scene −z. UVs stay in metres.
        geometry = roomSurface(room, voids);
        geometry.rotateX(-Math.PI / 2);
    }
    const grid = tileGrid(room);
    const material = materials.get(room.floorMaterialId);
    // Laid tiles get their UVs from `alignTiles` below; the other patterns (planks, wood) are turned here.
    if (!materials.isTile(material)) {
        turnLevelUvs(geometry, grid);
    }
    const floor = new THREE.Mesh(geometry, material);
    floor.position.y = draped === null ? (FloorLift + elevation) * MM : 0;
    floor.receiveShadow = true;
    floor.name = `floor:${room.code}`;
    floor.userData = {roomId: room.id};
    alignTiles(floor, grid, materials);
    if (draped === null) {
        // From the floor mesh, which sits `FloorLift` over the floor's elevation, down to the ground beside it.
        const drop = (x: number, y: number): number => {
            const d = ground(x, y) - elevation;
            return isOutdoorRoom(room) ? d : Math.min(0, d);
        };
        const edge = buildFloorEdge(room.polygon, drop, materials);
        if (edge !== null) {
            floor.add(edge);
        }
    }
    return floor;
}

/** The floor's edge is drawn in pieces at most this long (mm), so it follows a sloping ground. */
const FloorEdgeStep = 500;

/** The floor mesh's height over the room's floor elevation, mm, so it draws over the slab and the plots' ground. */
const FloorLift = 2;

/** The upright face at the step of a room whose floor is off the ground beside it. */
const FloorEdgeColor = '#b9b2a6';
/** How far the edge stands inside the room's outline, mm: off the wall faces along it, so the two do not z-fight. */
const FloorEdgeInset = 2;

/**
 * The upright face round a room whose floor is off the ground beside it: from the room's floor mesh (the parent's
 * height, `FloorLift` over the floor, so y 0 here) down, or up, to the ground `drop(x, y)` mm off the floor — a raised
 * deck's side, a sunken terrace's step — in pieces at most `FloorEdgeStep` long. Both sides show. Null where the
 * floor is level with the ground all round.
 */
function buildFloorEdge(polygon: readonly PlanPoint[], drop: (x: number, y: number) => number, materials: MaterialLibrary): THREE.Mesh | null {
    const positions: number[] = [];
    const indices: number[] = [];
    const outline = counterClockwise(polygon);
    let level = true;
    outline.forEach(([ax, ay], i) => {
        const [bx, by] = outline[(i + 1) % outline.length];
        const length = Math.hypot(bx - ax, by - ay);
        if (length < 1) {
            return;
        }
        // Counter-clockwise: the room is on the left, so the left normal points inwards.
        const nx = (-(by - ay) / length) * FloorEdgeInset;
        const ny = ((bx - ax) / length) * FloorEdgeInset;
        const pieces = Math.ceil(length / FloorEdgeStep);
        for (let p = 0; p < pieces; p++) {
            const [s0, s1] = [p / pieces, (p + 1) / pieces];
            const [px0, py0] = [ax + (bx - ax) * s0, ay + (by - ay) * s0];
            const [px1, py1] = [ax + (bx - ax) * s1, ay + (by - ay) * s1];
            const [g0, g1] = [drop(px0, py0), drop(px1, py1)];
            level &&= g0 === 0 && g1 === 0;
            const [d0, d1] = [g0 - FloorLift, g1 - FloorLift];
            const [x0, y0, x1, y1] = [(px0 + nx) * MM, -(py0 + ny) * MM, (px1 + nx) * MM, -(py1 + ny) * MM];
            const base = positions.length / 3;
            positions.push(x0, 0, y0, x1, 0, y1, x1, d1 * MM, y1, x0, d0 * MM, y0);
            indices.push(base, base + 1, base + 2, base, base + 2, base + 3);
        }
    });
    if (level) {
        return null;
    }
    const geometry = new THREE.BufferGeometry();
    geometry.setAttribute('position', new THREE.Float32BufferAttribute(positions, 3));
    geometry.setIndex(indices);
    geometry.computeVertexNormals();
    const edge = new THREE.Mesh(geometry, materials.plain(FloorEdgeColor, 0.9, 0, THREE.DoubleSide));
    edge.name = 'floor-edge';
    edge.castShadow = true;
    edge.receiveShadow = true;
    return edge;
}

/** Strips across each vault of a vaulted ceiling, enough for a smooth arch. */
const VaultSegments = 8;

/**
 * The ceiling: a flat surface at the ceiling height, or vaults arching between beams (in their own material, white
 * paint by default). Faces down, seen from inside the room. A flat ceiling has the `voids` (the room's stairs going
 * up) cut out and slopes under the `roofs` where they come lower (`ceilingParts()`); a vaulted one stays whole.
 */
function buildCeiling(room: Room, materials: MaterialLibrary, voids: readonly PlanPoint[][] = [], roofs: FloorRoofs = []): THREE.Group {
    const group = new THREE.Group();
    group.name = `ceiling:${room.code}`;
    const material = room.ceilingMaterialId == null ? materials.plain('#f7f6f3', 0.95) : materials.get(room.ceilingMaterialId);
    const profile = vaultProfile(room);
    const underRoofs = profile === null && roofs.length > 0 ? ceilingUnderRoofs(room, material, voids, roofs) : null;
    group.add(underRoofs ?? ceilingSurface(room, profile, material, voids));
    if (profile === null) {
        return group;
    }

    const beamMaterial = room.beamMaterialId == null ? materials.plain('#f4f2ee', 0.8) : materials.get(room.beamMaterialId);
    const springing = profile.crown - profile.rise;
    // Down to `beamDrop` under the springing, up into the vaults so no gap shows beside them.
    const bottom = (springing - (room.beamDrop ?? 30)) * MM;
    const height = (room.beamDrop ?? 30) * MM + (profile.rise / 2) * MM;
    const width = profile.beamWidth * MM;
    for (const beam of roomBeams(room, profile)) {
        const length = Math.hypot(beam.end.x - beam.start.x, beam.end.y - beam.start.y) * MM;
        const holder = new THREE.Group();
        holder.position.set(beam.start.x * MM, 0, -beam.start.y * MM);
        holder.rotation.y = Math.atan2(beam.end.y - beam.start.y, beam.end.x - beam.start.x);
        holder.add(boxAt(beamMaterial, 0, bottom, -width / 2, length, height, width));
        group.add(holder);
    }
    return group;
}

/** A part of a downward-facing surface: its outline in plan with holes, and the height (mm) over each of its points. */
interface SurfacePart {
    contour: Vec2[];
    holes: Vec2[][];
    heightAt: (p: Vec2) => number;
}

/** UVs (metres) of a room's level surfaces: along and across the beams of its vault, or plan x and y without one. */
function ceilingUv(room: Room): (p: Vec2) => [number, number] {
    const frame = vaultFrame(room.vaultAngle ?? 0);
    return (p) => [(p.x * frame.u.x + p.y * frame.u.y) * MM, (p.x * frame.v.x + p.y * frame.v.y) * MM];
}

/**
 * A surface seen from below (a ceiling) over `parts` in plan: counter-clockwise in plan is clockwise seen from below
 * once plan y becomes −z, so the faces look down. It shades the room, so the sun reaches in only through the windows
 * (also while the ceilings are hidden).
 */
function downwardSurface(material: THREE.Material, parts: readonly SurfacePart[], uv: (p: Vec2) => [number, number]): THREE.Mesh {
    const positions: number[] = [];
    const uvs: number[] = [];
    const indices: number[] = [];
    for (const part of parts.filter((s) => s.contour.length >= 3)) {
        const base = positions.length / 3;
        for (const p of [...part.contour, ...part.holes.flat()]) {
            positions.push(p.x * MM, part.heightAt(p) * MM, -p.y * MM);
            uvs.push(...uv(p));
        }
        const toVector = (p: Vec2): THREE.Vector2 => new THREE.Vector2(p.x, p.y);
        for (const [i, j, k] of THREE.ShapeUtils.triangulateShape(part.contour.map(toVector), part.holes.map((hole) => hole.map(toVector)))) {
            indices.push(base + i, base + k, base + j);
        }
    }
    const geometry = new THREE.BufferGeometry();
    geometry.setAttribute('position', new THREE.Float32BufferAttribute(positions, 3));
    geometry.setAttribute('uv', new THREE.Float32BufferAttribute(uvs, 2));
    geometry.setIndex(indices);
    geometry.computeVertexNormals();
    const surface = new THREE.Mesh(geometry, material);
    surface.castShadow = true;
    surface.receiveShadow = true;
    return surface;
}

/**
 * The ceiling's surface over the room's outline: one flat piece, or strips across the beams lifted to the vault
 * profile. UVs are metres along and across the beams.
 */
function ceilingSurface(room: Room, profile: VaultProfile | null, material: THREE.Material, voids: readonly PlanPoint[][] = []): THREE.Mesh {
    const frame = vaultFrame(room.vaultAngle ?? 0);
    const across = (p: {x: number; y: number}): number => p.x * frame.v.x + p.y * frame.v.y;
    const outline = counterClockwise(room.polygon).map(([x, y]) => ({x, y}));
    let strips: {contour: Vec2[]; holes: Vec2[][]}[] = [{contour: outline, holes: []}];
    if (profile !== null) {
        const vs = outline.map(across);
        const breaks = vaultBreaks(Math.min(...vs), Math.max(...vs), profile, VaultSegments);
        strips = breaks.slice(1).map((hi, i) => ({contour: clipToBand(outline, frame.v, breaks[i], hi), holes: []}));
    } else if (voids.length > 0) {
        strips = cutVoids(room.polygon, voids).map((piece) => ({
            contour: counterClockwise(piece.outer.map((p): PlanPoint => [p.x, p.y])).map(([x, y]) => ({x, y})),
            holes: piece.holes,
        }));
    }

    const heightAt = (p: Vec2): number => (profile === null ? (room.ceilingHeight ?? 0) : vaultHeight(across(p), profile));
    return downwardSurface(material, strips.map((strip) => ({...strip, heightAt})), ceilingUv(room));
}

/**
 * A flat ceiling under roofs: its parts (`ceilingParts()`) laid flat or on the roof planes, with the room's floor
 * elevation taken off (the group stands on it). Null when no roof comes lower than the ceiling anywhere in the room.
 */
function ceilingUnderRoofs(room: Room, material: THREE.Material, voids: readonly PlanPoint[][], roofs: FloorRoofs): THREE.Mesh | null {
    const parts = ceilingParts(room, outlineWithout(room.polygon, voids), roofs);
    if (parts === null) {
        return null;
    }
    const elevation = room.floorElevation ?? 0;
    const ring = (points: readonly Vec2[]): Vec2[] => counterClockwise(points.map((p): PlanPoint => [p.x, p.y])).map(([x, y]) => ({x, y}));
    return downwardSurface(
        material,
        // Counter-clockwise in plan, holes clockwise, as the triangulation wants them.
        parts.map((part) => ({
            contour: ring(part.outer),
            holes: part.holes.map((hole) => ring(hole).reverse()),
            heightAt: (p) => part.heightAt(p) - elevation,
        })),
        ceilingUv(room),
    );
}

/** Upright faces closing the step between ceilings of different heights in an open plan (`ceilingSteps`). */
export function buildCeilingSteps(rooms: readonly Room[], walls: readonly Wall[], materials: MaterialLibrary, roofs: FloorRoofs = []): THREE.Group {
    const group = new THREE.Group();
    group.name = 'ceiling-steps';
    const material = materials.plain('#f7f6f3', 0.95, 0, THREE.DoubleSide);
    for (const step of ceilingSteps(rooms, walls, roofs)) {
        const geometry = new THREE.BufferGeometry();
        const atB = step.atB ?? step;
        const corners = [
            [step.a.x, step.bottom, step.a.y],
            [step.b.x, atB.bottom, step.b.y],
            [step.b.x, atB.top, step.b.y],
            [step.a.x, step.top, step.a.y],
        ].flatMap(([x, h, y]) => [x * MM, h * MM, -y * MM]);
        geometry.setAttribute('position', new THREE.Float32BufferAttribute(corners, 3));
        geometry.setIndex([0, 1, 2, 0, 2, 3]);
        geometry.computeVertexNormals();
        const face = new THREE.Mesh(geometry, material);
        face.castShadow = true;
        face.receiveShadow = true;
        group.add(face);
    }
    return group;
}

/**
 * Wall group in wall-local coordinates: x along the centre line from the start (along the chord of a curved wall),
 * y up, z across with −z on the left of the wall direction. Solid pieces leave the openings empty; doors and windows
 * are added into them, on a curved wall built straight on the tangent at their middle (`openingFrame()`). `ends`
 * mitres the end pieces where the wall meets another one in a corner; `corners` names the corner windows at its ends
 * (`cornerWindows()`). Under the floor's `roofs` the wall's top follows their underside (`wallTopAt()`: cut by it, or
 * risen to it with `upToRoof`), and an opening whose sill is above the lowest top over it is left out, the wall solid.
 */
export function buildWall(
    wall: Wall,
    materials: MaterialLibrary,
    sectionMaterial: THREE.Material,
    ends: WallEnds = SquareEnds,
    corners: WallCorners = NoCorners,
    roofs: FloorRoofs = [],
    plots: readonly Plot[] = [],
    exterior: number | null = null,
): THREE.Group {
    const frame = wallFrame(wall);
    const group = new THREE.Group();
    group.name = `wall:${wall.name}`;
    group.userData = {wallId: wall.id};
    group.position.set(frame.start.x * MM, 0, -frame.start.y * MM);
    group.rotation.y = frame.angle;

    const material = materials.get(wall.materialId);
    const top = wallTopAt(wall, roofs, frame);
    const bottom = wallBottomAt(wall, frame, plots);
    // The wall's own sloping top and base are linear along it: the solids bend where they cross a level.
    const slopes = [wallHeightAt(wall, frame), wall.onGround === true ? null : bottom].filter((s): s is HeightAt => s !== null);
    // An opening the roofs leave no wall for (its sill above the lowest top over it) is left out, and the wall is solid there.
    const underTop = (opening: Opening): boolean =>
        top === null ||
        opening.sillHeight <
            lowestTopAlong(pointOnWall(frame, opening.offset), pointOnWall(frame, opening.offset + opening.width), top, roofs, [], slopes);
    const openings = wall.openings.filter(underTop);

    for (const piece of wallPieces(frame.length, wall.height, openings)) {
        const profiled =
            top === null && bottom === null ? null : profiledWallPiece(material, piece, frame, wall, ends, top, roofs, bottom, slopes);
        const meshes = profiled ?? [
            frame.arc === undefined
                ? wallPieceMesh(material, piece, frame.length, wall.thickness, ends)
                : curvedWallPieceMesh(material, piece, frame, wall.thickness, ends),
        ];
        for (const mesh of meshes) {
            group.add(mesh);
            // Same box drawn inside-out in a flat dark colour: invisible normally, it fills the cut once the
            // section plane slices through the wall.
            const cap = new THREE.Mesh(mesh.geometry, sectionMaterial);
            cap.position.copy(mesh.position);
            group.add(cap);
        }
    }

    for (const opening of openings.filter((o) => o.type !== 'Niche')) {
        const part = isDoor(opening) ? buildDoor(opening, wall, materials) : buildWindow(opening, wall, materials, corners);
        const lining = opening.revealLining === true ? revealLining(opening, wall, materials, exterior) : null;
        if (lining !== null) {
            part.add(lining);
        }
        if (frame.arc !== undefined) {
            placeOnFrame(part, frame, openingFrame(frame, opening));
        }
        group.add(part);
    }

    mergeStatic(group);
    return group;
}

/** How thick a lined reveal's boards are, mm. */
const RevealBoard = 20;

/**
 * The boards lining an opening's reveal (FD-88, `revealLining`): its jambs, its head and a window's sill across the
 * whole wall, in the band of the outer face (opposite `side`) at the opening's middle height, else that face's façade
 * (`facadeBands()`). Null when the face has none. In the wall's coordinates, as the opening's parts are.
 */
function revealLining(opening: Opening, wall: Wall, materials: MaterialLibrary, exterior: number | null): THREE.Group | null {
    const outer = (opening.side ?? 'Left') === 'Left' ? 'Right' : 'Left';
    const bands = facadeBands(wall, outer, exterior);
    const middle = opening.sillHeight + opening.height / 2;
    const band = bands.find((b) => b.bottom <= middle && middle <= b.top) ?? bands.at(-1);
    if (band === undefined) {
        return null;
    }
    const material = materials.get(band.materialId);
    const [x0, x1] = [opening.offset * MM, (opening.offset + opening.width) * MM];
    const [y0, y1] = [opening.sillHeight * MM, (opening.sillHeight + opening.height) * MM];
    const board = RevealBoard * MM;
    // Across the wall from the inner face (on `side`; the left face is −z) to the façade band on the outer one.
    const z = (-wall.thickness / 2 - (outer === 'Left' ? FacadeLining : 0)) * MM;
    const depth = (wall.thickness + FacadeLining) * MM;
    const group = new THREE.Group();
    group.name = 'reveal-lining';
    group.add(
        boxAt(material, x0, y0, z, board, y1 - y0, depth),
        boxAt(material, x1 - board, y0, z, board, y1 - y0, depth),
        boxAt(material, x0, y1 - board, z, x1 - x0, board, depth),
    );
    if (opening.sillHeight > 0) {
        group.add(boxAt(material, x0, y0, z, x1 - x0, board, depth));
    }
    return group;
}

/** Puts an object built in the coordinates of `target` into the group of a wall on `frame`. */
function placeOnFrame(object: THREE.Object3D, frame: WallFrame, target: WallFrame): void {
    const dx = target.start.x - frame.start.x;
    const dy = target.start.y - frame.start.y;
    object.position.set((dx * frame.dir.x + dy * frame.dir.y) * MM, 0, -(dx * frame.normal.x + dy * frame.normal.y) * MM);
    object.rotation.y = target.angle - frame.angle;
}

/** On the ground, a wall's faces are stood up through points at most this far apart (mm), following it. */
const GroundStep = 250;

/**
 * A wall outline (`wallOutline()`: one face's points, then the other's back) with points added along both faces in the
 * same places, so no segment is longer than `step` and the faces keep as many points as each other.
 */
function densified(outline: readonly Vec2[], step: number): Vec2[] {
    const half = outline.length / 2;
    const a = outline.slice(0, half);
    const b = outline.slice(half).reverse();
    const lerp = (p: Vec2, q: Vec2, t: number): Vec2 => ({x: p.x + (q.x - p.x) * t, y: p.y + (q.y - p.y) * t});
    const [da, db]: Vec2[][] = [[a[0]], [b[0]]];
    for (let i = 1; i < half; i++) {
        const longer = Math.max(Math.hypot(a[i].x - a[i - 1].x, a[i].y - a[i - 1].y), Math.hypot(b[i].x - b[i - 1].x, b[i].y - b[i - 1].y));
        const n = Math.max(1, Math.ceil(longer / step));
        for (let k = 1; k <= n; k++) {
            da.push(lerp(a[i - 1], a[i], k / n));
            db.push(lerp(b[i - 1], b[i], k / n));
        }
    }
    return [...da, ...db.reverse()];
}

/**
 * A piece of a wall under roofs or with a sloping top (`top`), or on a base or the ground (`bottom`), FD-87: its top
 * follows `top` (a piece reaching the wall's top) or is capped by it (a lower one: under a window, behind a niche); a
 * piece from the floor stands on `bottom`. Its outline (`wallOutline()`, densified on the ground) stood up in runs
 * (`profileRuns()`) up to the top over each point, never under the piece's bottom. Null when it is the piece as it is,
 * which is then drawn as usual; empty when nothing is left of it. Tagged with the niche behind which it is, like
 * `wallPieceMesh()`.
 */
function profiledWallPiece(
    material: THREE.Material,
    piece: WallPiece,
    frame: WallFrame,
    wall: Wall,
    ends: WallEnds,
    top: HeightAt | null,
    roofs: FloorRoofs,
    bottom: HeightAt | null,
    slopes: readonly HeightAt[] = [],
): THREE.Mesh[] | null {
    const wallTop = top ?? ((): number => wall.height);
    const reachesTop = piece.top >= wall.height;
    let pieceBottom: HeightAt = (): number => piece.bottom;
    if (bottom !== null) {
        pieceBottom = piece.bottom === 0 ? bottom : (p): number => Math.max(piece.bottom, bottom(p));
    }
    const pieceTop: HeightAt = (p) => Math.max(pieceBottom(p), reachesTop ? wallTop(p) : Math.min(piece.top, wallTop(p)));
    let outline = wallOutline(frame, wall.thickness, piece.from, piece.to, ends, piece.recess);
    if (wall.onGround === true) {
        outline = densified(outline, GroundStep);
    }
    const half = outline.length / 2;
    const levels = [piece.top, piece.bottom, wall.height];
    const runs = profileRuns(outline.slice(0, half), outline.slice(half).reverse(), pieceTop, roofs, levels, pieceBottom, slopes);
    if (bottom === null && runsAreLevel(runs, piece.top)) {
        return null;
    }
    const local = wallLocal(frame);
    const solid = (run: ProfileRun): boolean =>
        run.rightTop.some((h, i) => h - run.rightBottom[i] > 0.5) || run.leftTop.some((h, i) => h - run.leftBottom[i] > 0.5);
    return runs.filter(solid).map((run: ProfileRun) => {
            const tops = {right: run.rightTop.map((h) => h * MM), left: run.leftTop.map((h) => h * MM)};
            const bottoms = {right: run.rightBottom.map((h) => h * MM), left: run.leftBottom.map((h) => h * MM)};
            const mesh = curvedPrismAt(material, run.right.map(local), run.left.map(local), bottoms, tops);
            if (piece.recess !== undefined) {
                mesh.userData = {openingId: piece.recess.openingId};
            }
            return mesh;
        });
}

/**
 * A piece of a curved wall: its outline (`wallOutline()`, mitred at the wall's ends, thinner behind a niche) stood up
 * with smooth faces. Tagged with the niche behind which it is, like `wallPieceMesh()`.
 */
/** Plan points into a wall's group (metres): x along the chord from the start, z across it with the left at −z. */
function wallLocal(frame: WallFrame): (p: Vec2) => {x: number; z: number} {
    return (p) => {
        const dx = p.x - frame.start.x;
        const dy = p.y - frame.start.y;
        return {x: (dx * frame.dir.x + dy * frame.dir.y) * MM, z: -(dx * frame.normal.x + dy * frame.normal.y) * MM};
    };
}

function curvedWallPieceMesh(material: THREE.Material, piece: WallPiece, frame: WallFrame, thickness: number, ends: WallEnds): THREE.Mesh {
    const outline = wallOutline(frame, thickness, piece.from, piece.to, ends, piece.recess);
    const local = outline.map(wallLocal(frame));
    const half = local.length / 2;
    const mesh = curvedPrismAt(material, local.slice(0, half), local.slice(half).reverse(), piece.bottom * MM, piece.top * MM);
    if (piece.recess !== undefined) {
        mesh.userData = {openingId: piece.recess.openingId};
    }
    return mesh;
}

/**
 * A box, or for a piece at a mitred wall end a prism whose faces run on to the corner. The piece behind a niche is
 * thinner by the niche's depth on the face it is cut into (the left face is −z); its mitre follows the corner's at
 * that depth. It is tagged with the niche, so clicking into it selects the niche.
 */
function wallPieceMesh(material: THREE.Material, piece: WallPiece, length: number, thickness: number, ends: WallEnds): THREE.Mesh {
    const t = thickness * MM;
    const recess = piece.recess;
    // Faces of the piece across the wall, as fractions of the thickness from the left face.
    const left = recess?.side === 'Left' ? recess.depth / thickness : 0;
    const right = recess?.side === 'Right' ? 1 - recess.depth / thickness : 1;
    const mitre = (atLeft: number, atRight: number, f: number): number => atLeft + (atRight - atLeft) * f;
    const atStart = piece.from <= 0;
    const atEnd = piece.to >= length;
    const leftFrom = atStart ? -mitre(ends.startLeft, ends.startRight, left) : piece.from;
    const rightFrom = atStart ? -mitre(ends.startLeft, ends.startRight, right) : piece.from;
    const leftTo = atEnd ? length + mitre(ends.endLeft, ends.endRight, left) : piece.to;
    const rightTo = atEnd ? length + mitre(ends.endLeft, ends.endRight, right) : piece.to;
    const square = leftFrom === piece.from && rightFrom === piece.from && leftTo === piece.to && rightTo === piece.to;

    const zLeft = -t / 2 + t * left;
    const zRight = -t / 2 + t * right;
    const mesh = square || leftTo <= leftFrom || rightTo <= rightFrom
        ? boxAt(material, piece.from * MM, piece.bottom * MM, zLeft, (piece.to - piece.from) * MM, (piece.top - piece.bottom) * MM, zRight - zLeft)
        : prismAt(
            material,
            [
                {x: leftFrom * MM, z: zLeft},
                {x: leftTo * MM, z: zLeft},
                {x: rightTo * MM, z: zRight},
                {x: rightFrom * MM, z: zRight},
            ],
            piece.bottom * MM,
            piece.top * MM,
        );
    if (recess !== undefined) {
        mesh.userData = {openingId: recess.openingId};
    }
    return mesh;
}

const LiningThickness = 8;
/** Thickness of skirting boards, mm. */
const SkirtingThickness = 15;

/**
 * Tiling (or another finish) as thin boxes along the inside of every room edge, band by band, and skirting
 * boards along the walls (`skirtingPieces`: not across open edges or doorways). A wall face with bands of its own
 * (`leftFinishes` / `rightFinishes`) gets those instead of the room's. Door openings on the edge stay free; UVs
 * continue across pieces from the room corner and the floor, and tiles are then put on the room's tile grid
 * (`alignTiles`), the one its floor uses. Under the floor's `roofs` a band stops at their underside (`finishLining()`).
 */
export function buildWallFinishes(room: Room, walls: readonly Wall[], materials: MaterialLibrary, roofs: FloorRoofs = []): THREE.Group {
    const group = new THREE.Group();
    // Clicking a tiled wall selects the room the tiling belongs to.
    group.userData = {roomId: room.id};
    group.name = `finishes:${room.code}`;
    // The bands count from the room's floor: the group stands at its elevation, and the openings' heights, given from
    // the floor's level, come down to it.
    const elevation = room.floorElevation ?? 0;
    group.position.y = elevation * MM;
    // Counter-clockwise, so the room interior is on the left of every edge.
    const polygon = counterClockwise(room.polygon);
    const skirtingMaterial = room.skirtingMaterialId == null ? materials.plain('#f4f2ee', 0.6) : materials.get(room.skirtingMaterialId);

    polygon.forEach(([ax, ay], i) => {
        const [bx, by] = polygon[(i + 1) % polygon.length];
        const a = {x: ax, y: ay};
        const b = {x: bx, y: by};
        const length = Math.hypot(bx - ax, by - ay);
        const gaps = wallGapsAlongEdge(a, b, walls).map((gap) => ({...gap, bottom: gap.bottom - elevation, top: gap.top - elevation}));
        const segments = finishSegments(room, a, b, length, walls);
        // Along a curved wall the edge is one of the pieces of its face: the bands' UVs run on along the arc.
        const faces = wallFacesAlongEdge(a, b, walls);
        const curved = faces.find((face) => (face.wall.sagitta ?? 0) !== 0);
        // Under a wall with a sloping top (FD-87) the bands stop at it.
        const sloping = faces.map((face) => wallHeightAt(face.wall, wallFrame(face.wall))).filter((h): h is HeightAt => h !== null);
        const wallCap: HeightAt | null = sloping.length === 0 ? null : (p): number => Math.min(...sloping.map((h) => h(p)));

        const edge = new THREE.Group();
        edge.position.set(ax * MM, 0, -ay * MM);
        edge.rotation.y = Math.atan2(by - ay, bx - ax);
        // Mirror local z so +z points into the room and the box's +z face (u along +x) is the visible one.
        edge.scale.z = -1;

        const skirting = room.skirtingHeight ?? null;
        const skirtingRuns = skirting === null ? [] : skirtingPieces(a, b, walls);
        for (const segment of segments) {
            // In front of a finish band reaching the floor (tiling), otherwise on the wall face.
            const inset = segment.bands.some((band) => band.bottom <= 0) ? LiningThickness * MM : 0;
            for (const [runFrom, runTo] of skirtingRuns) {
                const from = Math.max(runFrom, segment.from);
                const to = Math.min(runTo, segment.to);
                if (to > from) {
                    edge.add(boxAt(skirtingMaterial, from * MM, 0, inset, (to - from) * MM, (skirting ?? 0) * MM, SkirtingThickness * MM));
                }
            }
            for (const band of segment.bands) {
                const bandGaps = gaps.flatMap((gap) => clipGap(gap, band.bottom, band.top, segment));
                const material = materials.get(band.materialId);
                for (const piece of wallPieces(segment.to - segment.from, band.top - band.bottom, bandGaps)) {
                    const from = segment.from + piece.from;
                    const bottom = band.bottom + piece.bottom;
                    const lining =
                        roofs.length === 0 && wallCap === null
                            ? null
                            : finishLining(material, a, b, from, segment.from + piece.to, bottom, band.bottom + piece.top, elevation, roofs, {
                                  curved,
                                  wallCap,
                              });
                    if (lining !== null) {
                        lining.forEach((mesh) => edge.add(mesh));
                        continue;
                    }
                    const width = (piece.to - piece.from) * MM;
                    const height = (piece.top - piece.bottom) * MM;
                    const geometry = worldUvBox(width, height, LiningThickness * MM, {u: from * MM, v: bottom * MM});
                    if (curved !== undefined) {
                        arcFaceUvs(geometry, {x: from * MM + width / 2, y: bottom * MM + height / 2}, a, b, curved);
                    }
                    const mesh = new THREE.Mesh(geometry, material);
                    mesh.position.set(from * MM + width / 2, bottom * MM + height / 2, (LiningThickness * MM) / 2);
                    mesh.receiveShadow = true;
                    edge.add(mesh);
                }
            }
        }
        group.add(edge);
    });

    alignTiles(group, tileGrid(room), materials);
    mergeStatic(group);
    return group;
}

/**
 * A piece of a finish band on the room edge a → b (`from`–`to` along it, `bottom`–`top` above the room's floor at
 * `elevation`) stopping at the `roofs`' underside: in the edge's group (x along it, z into the lining), in runs as
 * `profileRuns()` gives them, UVs running on from the edge's start (along the arc of a `curved` wall's face, as
 * `arcFaceUvs()` lays them), and under `wallCap` (the wall's own sloping top, against the floor's level). Null when
 * the roofs and the cap leave it whole.
 */
function finishLining(
    material: THREE.Material,
    a: Vec2,
    b: Vec2,
    from: number,
    to: number,
    bottom: number,
    top: number,
    elevation: number,
    roofs: FloorRoofs,
    {curved, wallCap}: {curved: EdgeFace | undefined; wallCap: HeightAt | null},
): THREE.Mesh[] | null {
    const length = Math.hypot(b.x - a.x, b.y - a.y);
    const dir = {x: (b.x - a.x) / length, y: (b.y - a.y) / length};
    // The room is on the left of the edge.
    const inward = {x: -dir.y * LiningThickness, y: dir.x * LiningThickness};
    const at = (along: number): Vec2 => ({x: a.x + dir.x * along, y: a.y + dir.y * along});
    const face = [at(from), at(to)];
    const room = face.map((p) => ({x: p.x + inward.x, y: p.y + inward.y}));
    const roofCap = wallTopAt({height: top + elevation, upToRoof: false}, roofs);
    if (roofCap === null && wallCap === null) {
        return null;
    }
    const cap: HeightAt = (p) => Math.min(roofCap?.(p) ?? top + elevation, wallCap?.(p) ?? Infinity);
    const bandTop: HeightAt = (p) => Math.max(bottom, cap(p) - elevation);
    const runs = profileRuns(face, room, bandTop, roofs, [top + elevation, bottom + elevation], () => 0, wallCap === null ? [] : [wallCap]);
    if (runsAreLevel(runs, top)) {
        return null;
    }
    return runs
        .filter((run) => !runsAreLevel([run], bottom))
        .map((run) => {
            const x = (p: Vec2): number => ((p.x - a.x) * dir.x + (p.y - a.y) * dir.y) * MM;
            const mesh = curvedPrismAt(
                material,
                run.right.map((p) => ({x: x(p), z: 0})),
                run.left.map((p) => ({x: x(p), z: LiningThickness * MM})),
                bottom * MM,
                {right: run.rightTop.map((h) => h * MM), left: run.leftTop.map((h) => h * MM)},
                x(run.right[0]),
            );
            if (curved !== undefined) {
                arcFaceUvs(mesh.geometry, {x: 0, y: 0}, a, b, curved);
            }
            mesh.castShadow = false;
            mesh.receiveShadow = true;
            return mesh;
        });
}

/**
 * UVs of a lining box on the room edge a → b along a curved wall's face, for a box at `offset` (metres, along the edge
 * and up): u the distance along the face's arc from the wall's start, v the height, so the pattern runs on across the
 * pieces of the face. They stay as they are (`alignTiles()` skips them).
 */
function arcFaceUvs(geometry: THREE.BufferGeometry, offset: {x: number; y: number}, a: Vec2, b: Vec2, face: EdgeFace): void {
    const frame = wallFrame(face.wall);
    if (frame.arc === undefined) {
        return;
    }
    const length = Math.hypot(b.x - a.x, b.y - a.y);
    const across = face.side === 'Left' ? face.wall.thickness / 2 : -face.wall.thickness / 2;
    const scale = (frame.arc.radius - Math.sign(frame.arc.sweep) * across) / frame.arc.radius;
    const positions = geometry.getAttribute('position');
    const uv = geometry.getAttribute('uv') as THREE.BufferAttribute;
    for (let i = 0; i < positions.count; i++) {
        const t = (positions.getX(i) + offset.x) / MM / length;
        const point = {x: a.x + (b.x - a.x) * t, y: a.y + (b.y - a.y) * t};
        uv.setXY(i, alongWall(frame, point) * scale * MM, positions.getY(i) + offset.y);
    }
    uv.needsUpdate = true;
    geometry.userData['ownUvs'] = true;
}

/** A stretch of a room edge (mm from its start) and the finish bands lining it. */
export interface FinishSegment {
    from: number;
    to: number;
    bands: readonly WallFinish[];
}

/** The edge a → b in stretches: the wall faces with bands of their own, and the room's bands elsewhere. */
export function finishSegments(room: Room, a: Vec2, b: Vec2, length: number, walls: readonly Wall[]): FinishSegment[] {
    const own = wallFacesAlongEdge(a, b, walls).flatMap((face): FinishSegment[] => {
        const bands = face.side === 'Left' ? face.wall.leftFinishes : face.wall.rightFinishes;
        // Inside a room a face's bands start on its floor (a façade's plinth may reach below it, FD-88).
        const onFloor = bands?.filter((band) => band.top > 0).map((band) => ({...band, bottom: Math.max(band.bottom, 0)}));
        return onFloor == null ? [] : [{from: face.from, to: face.to, bands: onFloor}];
    });
    const rest = subtractSpans([[0, length]], own.map((segment): [number, number] => [segment.from, segment.to]));
    return [...own, ...rest.map(([from, to]): FinishSegment => ({from, to, bands: room.wallFinishes}))].sort((x, y) => x.from - y.from);
}

/** Whether a wall face has finish bands of its own, so the rooms along it draw their finishes. */
export function hasFaceFinishes(wall: Wall): boolean {
    return wall.leftFinishes != null || wall.rightFinishes != null;
}

/** Opening relative to a finish band on a segment, as `wallPieces` expects; nothing when the gap misses them. */
export function clipGap(gap: WallGap, bandBottom: number, bandTop: number, segment: FinishSegment): Opening[] {
    const from = Math.max(gap.from, segment.from);
    const to = Math.min(gap.to, segment.to);
    if (gap.top <= bandBottom || gap.bottom >= bandTop || to <= from) {
        return [];
    }
    const sill = Math.max(gap.bottom - bandBottom, 0);
    const head = Math.min(gap.top, bandTop) - bandBottom;
    return [{
        id: 0,
        name: '',
        type: 'Door',
        offset: from - segment.from,
        width: to - from,
        height: head - sill,
        sillHeight: sill,
        leafWidth: null,
        hinge: null,
        side: null,
        entrance: false,
    }];
}

/** Casing boards of an `Architrave` door frame: width and thickness, mm. */
const ArchitraveWidth = 70;
const ArchitraveThickness = 15;

/** The material of an opening's frame and sashes (FD-89): its own, else white for doors and anthracite for windows. */
function frameMaterialOf(opening: Opening, materials: MaterialLibrary): THREE.Material {
    if (opening.frameMaterialId != null) {
        return materials.get(opening.frameMaterialId);
    }
    return materials.byCode(opening.type === 'Window' || isLiftSlide(opening) ? 'window-frame-anthracite' : 'door-white');
}

/** The material of a door's leaves (FD-89): its own, else the frame's. */
function leafMaterialOf(opening: Opening, materials: MaterialLibrary): THREE.Material {
    return opening.leafMaterialId == null ? frameMaterialOf(opening, materials) : materials.get(opening.leafMaterialId);
}

function buildDoor(opening: Opening, wall: Wall, materials: MaterialLibrary): THREE.Group {
    if (isLiftSlide(opening)) {
        return buildLiftSlide(opening, wall, materials);
    }
    const group = new THREE.Group();
    group.name = `door:${opening.name}`;
    group.userData = {openingId: opening.id};
    const frameMaterial = frameMaterialOf(opening, materials);
    const leafMaterial = leafMaterialOf(opening, materials);
    for (const part of doorFrame(opening, wall.thickness, frameMaterial)) {
        group.add(part);
    }
    if (opening.type === 'GarageDoor' && (opening.garageDoorStyle ?? 'Sectional') !== 'SideHinged') {
        return buildLiftingDoor(group, opening, wall, materials);
    }

    const handleMaterial = materials.plain('#2b2b2b', 0.35, 0.7);
    for (const leaf of openingLeaves(opening, wall.thickness)) {
        // A sliding leaf covers the opening's head too; the others stop short of the frame head (and the floor).
        const leafHeight =
            opening.type === 'SlidingDoor'
                ? (opening.height + SlidingDoorOverlap) * MM
                : (opening.height - LeafGap) * MM - doorHeadDepth(opening);
        const pivot = leafPivot(leaf, 1);
        for (const part of doorLeaf(opening, leaf, leafHeight, leafMaterial, materials.byCode('glass'))) {
            pivot.add(part);
        }
        for (const handle of doorHandles(opening, leaf, handleMaterial)) {
            pivot.add(handle);
        }
        group.add(pivot);

        if (opening.type === 'SlidingDoor') {
            // The track the leaf hangs from, as long as its way.
            const from = Math.min(leaf.along, leaf.along + leaf.travel) * MM;
            const length = (leaf.width + Math.abs(leaf.travel)) * MM;
            group.add(boxAt(handleMaterial, from, leafHeight + 0.01, -leaf.across * MM - 0.015, length, 0.04, 0.03));
        }
    }

    return group;
}

/** Raised panels: a bead round each rectangle (mm, in the slab's frame) and a field inside it, a few mm proud of the given faces. */
const PanelBead = 0.02;
const PanelBeadDepth = 0.006;
const PanelFieldInset = 0.045;
const PanelFieldDepth = 0.003;

/**
 * The relief of panels on a slab `thickness` m thick, centred on z = 0: on its +z face (`faces` 1), −z face (−1) or
 * both.
 */
function panelRelief(rects: readonly PanelRect[], thickness: number, faces: readonly (1 | -1)[], material: THREE.Material): THREE.Mesh[] {
    const parts: THREE.Mesh[] = [];
    for (const rect of rects) {
        const x = rect.x * MM;
        const y = rect.y * MM;
        const w = rect.width * MM;
        const h = rect.height * MM;
        for (const face of faces) {
            const z = face > 0 ? thickness / 2 : -thickness / 2 - PanelBeadDepth;
            parts.push(
                boxAt(material, x, y, z, w, PanelBead, PanelBeadDepth),
                boxAt(material, x, y + h - PanelBead, z, w, PanelBead, PanelBeadDepth),
                boxAt(material, x, y + PanelBead, z, PanelBead, h - 2 * PanelBead, PanelBeadDepth),
                boxAt(material, x + w - PanelBead, y + PanelBead, z, PanelBead, h - 2 * PanelBead, PanelBeadDepth),
            );
            if (w > 2 * PanelFieldInset && h > 2 * PanelFieldInset) {
                const fieldZ = face > 0 ? thickness / 2 : -thickness / 2 - PanelFieldDepth;
                const inset = PanelFieldInset;
                parts.push(boxAt(material, x + inset, y + inset, fieldZ, w - 2 * inset, h - 2 * inset, PanelFieldDepth));
            }
        }
    }
    return parts;
}

/** A boarded leaf: the joint between its boards, and how far the boards stand proud of the core on each face (m). */
const BoardJoint = 0.006;
const BoardRelief = 0.004;

/**
 * The boards of a `Boarded` leaf `width` × `height` m (FD-89), `count` side by side running `direction`, with a narrow
 * joint between them, proud of a core `thickness` thick on both faces.
 */
function boardedLeaf(
    width: number,
    height: number,
    thickness: number,
    count: number,
    direction: Opening['boardDirection'],
    material: THREE.Material,
): THREE.Mesh[] {
    const parts = [boxAt(material, 0, 0, -thickness / 2, width, height, thickness)];
    const boards = Math.max(1, Math.round(count));
    const across = direction === 'Horizontal' ? height : width;
    const pitch = across / boards;
    for (let i = 0; i < boards; i++) {
        const from = i * pitch + (i === 0 ? 0 : BoardJoint / 2);
        const size = pitch - (i === 0 || i === boards - 1 ? BoardJoint / 2 : BoardJoint) + (boards === 1 ? BoardJoint / 2 : 0);
        for (const face of [-1, 1]) {
            const z = face > 0 ? thickness / 2 : -thickness / 2 - BoardRelief;
            parts.push(
                direction === 'Horizontal'
                    ? boxAt(material, 0, from, z, width, size, BoardRelief)
                    : boxAt(material, from, 0, z, size, height, BoardRelief),
            );
        }
    }
    return parts;
}

/**
 * A door leaf by its `leafStyle`, in its pivot's frame (x from the hinge, centred on z = 0): a flush slab, a slab
 * with raised panels on both faces, stiles and rails round glass panes, or boards.
 */
function doorLeaf(opening: Opening, leaf: Leaf, height: number, material: THREE.Material, glass: THREE.Material): THREE.Mesh[] {
    const width = leaf.width * MM;
    const t = leaf.thickness * MM;
    const style = opening.leafStyle ?? 'Flush';
    if (style === 'Boarded') {
        return boardedLeaf(width, height, t - 2 * BoardRelief, opening.leafPanels ?? 8, opening.boardDirection, material);
    }
    const rects = style === 'Flush' ? [] : leafPanels(leaf.width, height / MM, opening.leafPanels ?? 2);
    const slab = (): THREE.Mesh => boxAt(material, 0, 0, -t / 2, width, height, t);
    if (rects.length === 0) {
        return [slab()];
    }
    if (style === 'Panelled') {
        return [slab(), ...panelRelief(rects, t, [-1, 1], material)];
    }
    // Glazed: the frame round the panes, and the panes.
    const stile = LeafStile * MM;
    const parts = [boxAt(material, 0, 0, -t / 2, stile, height, t), boxAt(material, width - stile, 0, -t / 2, stile, height, t)];
    let below = 0;
    for (const rect of rects) {
        parts.push(boxAt(material, stile, below, -t / 2, width - 2 * stile, rect.y * MM - below, t));
        parts.push(boxAt(glass, rect.x * MM, rect.y * MM, -0.004, rect.width * MM, rect.height * MM, 0.008, false));
        below = (rect.y + rect.height) * MM;
    }
    parts.push(boxAt(material, stile, below, -t / 2, width - 2 * stile, height - below, t));
    return parts;
}

/** Clearance of a swinging leaf under the frame head, mm. */
const LeafGap = 10;

/** How far the frame head of a door comes down into the opening (m), by its `frameStyle`; nothing for a bare hole. */
function doorHeadDepth(opening: Opening): number {
    const style = opening.frameStyle ?? 'Lining';
    return style === 'None' ? 0 : DoorFrameWidth * MM * (style === 'Flush' ? 0.4 : 0.6);
}

/** Jambs and head of a door frame by its style (none for `None`), plus casings on both faces for `Architrave`. */
function doorFrame(opening: Opening, wallThickness: number, material: THREE.Material): THREE.Mesh[] {
    const style = opening.frameStyle ?? 'Lining';
    if (style === 'None') {
        return [];
    }
    const from = opening.offset * MM;
    const width = opening.width * MM;
    const height = opening.height * MM;
    // A lining is slightly proud of both wall faces; a flush frame is thinner and level with them. A frame set in from
    // the outer face (`inset`) is a frame of its own depth there, without casings.
    const lining = doorHeadDepth(opening);
    const band = frameBand(opening, wallThickness);
    const depth = band.depth * MM + (style === 'Flush' || opening.inset != null ? 0 : 0.02);
    // Plan across is local −z.
    const back = -band.centre * MM - depth / 2;
    const parts = [
        boxAt(material, from, 0, back, lining, height, depth),
        boxAt(material, from + width - lining, 0, back, lining, height, depth),
        boxAt(material, from, height - lining, back, width, lining, depth),
    ];
    if (style === 'Architrave' && opening.inset == null) {
        const reach = ArchitraveWidth * MM - lining;
        const thickness = ArchitraveThickness * MM;
        for (const z of [depth / 2, -depth / 2 - thickness]) {
            parts.push(
                boxAt(material, from - reach, 0, z, ArchitraveWidth * MM, height + reach, thickness),
                boxAt(material, from + width - lining, 0, z, ArchitraveWidth * MM, height + reach, thickness),
                boxAt(material, from - reach, height - lining, z, width + 2 * reach, ArchitraveWidth * MM, thickness),
            );
        }
    }
    return parts;
}

/**
 * Handles of a leaf, in its pivot's frame (x from the hinge along the leaf), on both faces: near the free edge of
 * a swinging leaf, near the edge a sliding leaf leaves in the opening. Without a style a swinging leaf gets a
 * lever and a sliding one a pull; the face of a sliding door towards the wall only takes a flush pull.
 */
function doorHandles(opening: Opening, leaf: Leaf, material: THREE.Material): THREE.Object3D[] {
    const style = opening.handle ?? (leaf.motion === 'slide' ? 'Pull' : 'Lever');
    if (style === 'None') {
        return [];
    }
    const width = leaf.width * MM;
    const t = leaf.thickness * MM;
    const edge = leaf.motion === 'slide' && leaf.travel > 0 ? 0 : width;
    const inward = edge === 0 ? 1 : -1;
    const box = (w: number, h: number, d: number, x: number, y: number, z: number): THREE.Mesh =>
        boxAt(material, x - w / 2, y - h / 2, z - d / 2, w, h, d);

    return [-1, 1].map((face) => {
        // Plan across is local −z, so a sliding leaf's face towards the wall is +z for a leaf on the left.
        const flush = leaf.motion === 'slide' && (opening.type === 'PocketDoor' || face === leaf.swing);
        if (flush) {
            return box(0.03, 0.16, 0.004, edge + inward * 0.05, 1.0, face * (t / 2 + 0.002));
        }
        switch (style) {
            case 'Knob': {
                const knob = new THREE.Mesh(new THREE.CylinderGeometry(0.027, 0.027, 0.05, 20), material);
                knob.rotation.x = Math.PI / 2;
                knob.position.set(edge + inward * 0.07, 1.0, face * (t / 2 + 0.025));
                knob.castShadow = true;
                return knob;
            }
            case 'Pull':
                return box(0.02, 0.3, 0.02, edge + inward * 0.06, 1.0, face * (t / 2 + 0.035));
            default:
                return box(0.12, 0.018, 0.018, edge + inward * 0.1, 1.039, face * (t / 2 + 0.03));
        }
    });
}

/** Sill boards: thickness, overhang past the opening on each side, and the outer sill's reach past the wall face (mm). */
const SillBoardThickness = 20;
const SillBoardOverhang = 30;
const OuterSillReach = 40;
/** The aluminium cladding of a timber-aluminium frame's outer face, m. */
const OutsideCladding = 0.015;

/**
 * A frame bar's box across `depth` m centred on z = 0 (in a frame's own group): in `material`, with an outer face of
 * `outside` (a timber-aluminium frame, FD-89) `OutsideCladding` thick towards `exterior` (local z sign) when given.
 */
function frameBar(
    material: THREE.Material,
    outside: THREE.Material | null,
    exterior: 1 | -1,
    x: number,
    y: number,
    width: number,
    height: number,
    depth: number,
): THREE.Mesh[] {
    if (outside === null) {
        return [boxAt(material, x, y, -depth / 2, width, height, depth)];
    }
    const inner = depth - OutsideCladding;
    const [back, front] = [-depth / 2, depth / 2 - OutsideCladding];
    return exterior > 0
        ? [boxAt(material, x, y, back, width, height, inner), boxAt(outside, x, y, front, width, height, OutsideCladding)]
        : [boxAt(outside, x, y, back, width, height, OutsideCladding), boxAt(material, x, y, back + OutsideCladding, width, height, inner)];
}

/**
 * How far past the wall's end a corner window's frame reaches (m): half its depth, and with the frame set in by
 * `inset` as far as its centre lies towards the exterior, where the other window's frame is (both set in alike, the
 * exterior outside the corner).
 */
function cornerReach(opening: Opening, wall: Wall): number {
    const band = frameBand(opening, wall.thickness);
    const outward = opening.inset == null ? 0 : wall.thickness / 2 - opening.inset - band.depth / 2;
    return (band.depth / 2 + outward) * MM;
}

/**
 * A window: frame, glazing divided by `mullions` and `transoms` (or a walkable window's sashes) and sill boards, in the
 * frame's material with an outside material on its outer face (FD-89), centred in the wall or set in by `inset`.
 * At a corner (`corners`), the frame runs on to the wall's end without a jamb and the glass meets the other
 * window's at the corner.
 */
function buildWindow(opening: Opening, wall: Wall, materials: MaterialLibrary, corners: WallCorners): THREE.Group {
    const group = new THREE.Group();
    group.name = `window:${opening.name}`;
    group.userData = {openingId: opening.id};
    const frameMaterial = frameMaterialOf(opening, materials);
    const outside = opening.outsideFrameMaterialId == null ? null : materials.get(opening.outsideFrameMaterialId);
    const glassMaterial = materials.byCode('glass');
    // The frame, glass and bars in a group of their own across the frame's band (plan across is local −z).
    const glazing = new THREE.Group();
    glazing.position.z = -frameBand(opening, wall.thickness).centre * MM;
    group.add(glazing);
    const from = opening.offset * MM;
    const to = (opening.offset + opening.width) * MM;
    const bottom = opening.sillHeight * MM;
    const height = opening.height * MM;
    const bar = WindowFrameBar * MM;
    const depth = frameBand(opening, wall.thickness).depth * MM;
    const exterior = exteriorSign(opening);
    const frameBox = (x: number, y: number, w: number, h: number): void => {
        glazing.add(...frameBar(frameMaterial, outside, exterior, x, y, w, h, depth));
    };
    const cornerStart = corners.start === opening.id;
    const cornerEnd = corners.end === opening.id;
    // The head and bottom of a corner window reach past the corner to meet the other's.
    const reach = cornerReach(opening, wall);
    const frameFrom = cornerStart ? from - reach : from;
    const frameTo = cornerEnd ? to + reach : to;

    if (!cornerStart) {
        frameBox(from, bottom, bar, height);
    }
    if (!cornerEnd) {
        frameBox(to - bar, bottom, bar, height);
    }
    frameBox(frameFrom, bottom + height - bar, frameTo - frameFrom, bar);
    frameBox(frameFrom, bottom, frameTo - frameFrom, 0.04);
    for (const board of sillBoards(opening, wall, cornerStart, cornerEnd, materials)) {
        group.add(board);
    }

    const transoms = opening.transoms ?? 0;
    const leaves = openingLeaves(opening, wall.thickness);
    if (leaves.length > 0) {
        // Walkable: sashes hinged on the jambs, closed by default (their place across the wall includes the inset).
        const panel = opening.panelHeight == null ? null : opening.panelHeight * MM - 0.04;
        for (const leaf of leaves) {
            group.add(buildSash(leaf, bottom + 0.04, height - bar - 0.04, transoms, panel, frameMaterial, glassMaterial));
        }
        for (const part of shutters(opening, wall, materials)) {
            group.add(part);
        }
        return group;
    }

    // Fixed glazing: one pane behind a grid of bars, evenly spaced between the jambs (or the corner), above a
    // solid lower panel when there is one.
    const glassFrom = cornerStart ? from - reach + depth / 2 : from + bar;
    const glassTo = cornerEnd ? to + reach - depth / 2 : to - bar;
    let glassBottom = bottom + 0.04;
    const glassTop = bottom + height - bar;
    if (opening.panelHeight != null && opening.panelHeight * MM > 0.04 + bar) {
        const panelTop = bottom + opening.panelHeight * MM;
        glazing.add(boxAt(frameMaterial, glassFrom, glassBottom, -depth * 0.3, glassTo - glassFrom, panelTop - glassBottom, depth * 0.6));
        frameBox(glassFrom, panelTop, glassTo - glassFrom, bar);
        glassBottom = panelTop + bar;
    }
    glazing.add(boxAt(glassMaterial, glassFrom, glassBottom, -0.004, glassTo - glassFrom, glassTop - glassBottom, 0.008, false));
    const columns = (opening.mullions ?? 1) + 1;
    for (let i = 1; i < columns; i++) {
        const x = glassFrom + ((glassTo - glassFrom) * i) / columns;
        frameBox(x - bar / 2, glassBottom, bar, glassTop - glassBottom);
    }
    for (let i = 1; i <= transoms; i++) {
        const y = glassBottom + ((glassTop - glassBottom) * i) / (transoms + 1);
        frameBox(glassFrom, y - bar / 2, glassTo - glassFrom, bar);
    }
    for (const part of shutters(opening, wall, materials)) {
        group.add(part);
    }

    return group;
}

/**
 * A lift-and-slide door (FD-89, a `SlidingDoor` with `fixedPanes`): a frame round the opening across its band like a
 * window's, a threshold, and its panes as sashes on two tracks, the sliding one tagged as a leaf with a handle.
 */
function buildLiftSlide(opening: Opening, wall: Wall, materials: MaterialLibrary): THREE.Group {
    const group = new THREE.Group();
    group.name = `door:${opening.name}`;
    group.userData = {openingId: opening.id};
    const frameMaterial = frameMaterialOf(opening, materials);
    const outside = opening.outsideFrameMaterialId == null ? null : materials.get(opening.outsideFrameMaterialId);
    const band = frameBand(opening, wall.thickness);
    const glazing = new THREE.Group();
    glazing.position.z = -band.centre * MM;
    group.add(glazing);
    const from = opening.offset * MM;
    const width = opening.width * MM;
    const height = opening.height * MM;
    const bar = WindowFrameBar * MM;
    // As deep as both tracks with their panes (`LiftSlideFrameDepth`).
    const depth = band.depth * MM;
    const frameBox = (x: number, y: number, w: number, h: number): void => {
        glazing.add(...frameBar(frameMaterial, outside, exteriorSign(opening), x, y, w, h, depth));
    };
    frameBox(from, 0, bar, height);
    frameBox(from + width - bar, 0, bar, height);
    frameBox(from, height - bar, width, bar);
    frameBox(from, 0, width, 0.02);
    const glass = materials.byCode('glass');
    for (const leaf of openingLeaves(opening, wall.thickness)) {
        group.add(buildSash(leaf, 0.02, height - bar - 0.02, 0, null, frameMaterial, glass, leaf.travel !== 0));
    }
    return group;
}

/** The radius a sectional garage door's track turns through under the ceiling (m). */
const GarageTrackRadius = 0.12;

/**
 * A garage door that lifts (FD-89): its leaf behind the opening on the garage's face, tagged as a leaf so
 * `setLeafPose()` lifts it. A sectional door's leaf is horizontal sections (tagged `section`) running on tracks up the
 * jambs and under the ceiling, an up-and-over door's one panel (tagged `tilt`) that tilts up, a roller door's slats
 * (tagged `roll`) rolling up into a drum above the opening. The faces follow `leafStyle` and `leafMaterialId`.
 */
function buildLiftingDoor(group: THREE.Group, opening: Opening, wall: Wall, materials: MaterialLibrary): THREE.Group {
    const material = leafMaterialOf(opening, materials);
    const steel = materials.plain('#5b5c5e', 0.5, 0.6);
    for (const leaf of openingLeaves(opening, wall.thickness)) {
        const pivot = leafPivot(leaf, 0);
        const width = leaf.width * MM;
        const height = (leaf.height ?? opening.height) * MM;
        const t = leaf.thickness * MM;
        const face = (w: number, h: number, boards: number): THREE.Mesh[] =>
            opening.leafStyle === 'Boarded'
                ? boardedLeaf(w, h, t - 2 * BoardRelief, boards, opening.boardDirection, material)
                : [boxAt(material, 0, 0, -t / 2, w, h, t)];
        const totalBoards = opening.leafStyle === 'Boarded' ? (opening.leafPanels ?? 8) : 1;
        if (leaf.lift === 'Sectional') {
            const count = garageSections(height / MM);
            const section = height / count;
            for (let i = 0; i < count; i++) {
                const part = new THREE.Group();
                part.userData = {section: i, sectionHeight: section};
                // Each section a few mm short of the next: the joint.
                const boards = opening.boardDirection === 'Horizontal' ? Math.max(1, Math.round(totalBoards / count)) : totalBoards;
                part.add(...face(width, section - 0.004, boards));
                pivot.add(part);
            }
            // The tracks: up the jambs, then turning under the ceiling as far into the garage as the door is high.
            const inward = -leaf.swing;
            for (const x of [-0.03, width]) {
                pivot.add(boxAt(steel, x, 0, -0.02, 0.03, height + GarageTrackRadius, 0.04));
                pivot.add(boxAt(steel, x, height + GarageTrackRadius - 0.02, inward > 0 ? 0 : -height, 0.03, 0.04, height));
            }
        } else if (leaf.lift === 'UpAndOver') {
            const part = new THREE.Group();
            part.userData = {tilt: true, tiltHeight: height};
            const panel = new THREE.Group();
            panel.position.y = -height / 3;
            panel.add(...face(width, height, totalBoards));
            part.add(panel);
            pivot.add(part);
        } else {
            const part = new THREE.Group();
            part.userData = {roll: true, rollHeight: height};
            const slats = rollerSlats(height / MM);
            const pitch = height / slats;
            for (let i = 0; i < slats; i++) {
                part.add(boxAt(material, 0, -height + i * pitch, -t / 4, width, pitch - 0.006, t / 2));
            }
            pivot.add(part);
            // The drum's box above the opening, centred on the leaf (the plan draws the same box, `liftShapes()`).
            const drum = RollerDrum * MM;
            group.add(boxAt(steel, leaf.along * MM, opening.height * MM, -leaf.across * MM - drum / 2, width, drum, drum));
        }
        setLeafPose(pivot, 0);
        group.add(pivot);
    }
    return group;
}

/** Shutters: leaf thickness and its gap off the wall face, frame members and louvre slats of a louvred leaf (m). */
const ShutterThickness = 0.03;
const ShutterGap = 0.003;
const ShutterStile = 0.05;
const ShutterRail = 0.07;
const ShutterSlatPitch = 45;
const ShutterSlatTilt = 0.6;

/**
 * Exterior shutters of a window (`shutters`): two leaves on the outer face, across the window when
 * `shuttersClosed`, else folded back flat against the wall beside it. Louvred leaves have tilted slats in a frame,
 * solid ones raised panels on their outer face.
 */
function shutters(opening: Opening, wall: Wall, materials: MaterialLibrary): THREE.Object3D[] {
    if (opening.shutters == null) {
        return [];
    }
    const material = opening.shutterMaterialId == null ? materials.plain('#6b4f37', 0.7, 0) : materials.get(opening.shutterMaterialId);
    const sign = exteriorSign(opening);
    const face = (wall.thickness / 2) * MM;
    // The leaves' centre plane, just off the outer face.
    const centre = sign * (face + ShutterGap + ShutterThickness / 2);
    const bottom = opening.sillHeight * MM;
    const height = opening.height * MM;
    const t = ShutterThickness;
    return shutterLeaves(opening, opening.shuttersClosed === true, freeBeside(opening, wall)).map((leaf) => {
        const group = new THREE.Group();
        group.position.set(leaf.from * MM, bottom, centre);
        const width = (leaf.to - leaf.from) * MM;
        if (opening.shutters === 'Solid') {
            group.add(boxAt(material, 0, 0, -t / 2, width, height, t));
            for (const part of panelRelief(leafPanels(leaf.to - leaf.from, height / MM, 2), t, [sign], material)) {
                group.add(part);
            }
            return group;
        }
        group.add(boxAt(material, 0, 0, -t / 2, ShutterStile, height, t));
        group.add(boxAt(material, width - ShutterStile, 0, -t / 2, ShutterStile, height, t));
        group.add(boxAt(material, ShutterStile, 0, -t / 2, width - 2 * ShutterStile, ShutterRail, t));
        group.add(boxAt(material, ShutterStile, height - ShutterRail, -t / 2, width - 2 * ShutterStile, ShutterRail, t));
        for (const y of louvreSlats(height / MM, ShutterRail / MM, ShutterSlatPitch)) {
            // A thin blade across the leaf's thickness, its outer edge lower to shed rain outwards.
            const slat = new THREE.Mesh(worldUvBox(width - 2 * ShutterStile, 0.008, t * 1.2), material);
            slat.position.set(width / 2, y * MM, 0);
            slat.rotation.x = sign * ShutterSlatTilt;
            slat.castShadow = true;
            group.add(slat);
        }
        return group;
    });
}

/**
 * Sill boards under a window with a sill: the inner board on `side` (the interior) reaching `sillBoard` past the
 * wall face, and on an exterior wall an outer sill on the other face. None without `sillBoard`. At a corner end
 * the boards run on to meet the other window's: the outer one round the corner, the inner one to the glass.
 */
function sillBoards(opening: Opening, wall: Wall, cornerStart: boolean, cornerEnd: boolean, materials: MaterialLibrary): THREE.Mesh[] {
    if (opening.sillBoard === null || opening.sillBoard === undefined || opening.sillHeight === 0) {
        return [];
    }
    const from = opening.offset * MM;
    const to = (opening.offset + opening.width) * MM;
    const top = opening.sillHeight * MM;
    const face = (wall.thickness / 2) * MM;
    // The frame's centre in local z (plan across is local −z): the boards reach from its faces to the wall's.
    const band = frameBand(opening, wall.thickness);
    const centre = -band.centre * MM;
    const overhang = SillBoardOverhang * MM;
    const thickness = SillBoardThickness * MM;
    // Plan across is local −z: the interior on the left of the wall is −z.
    const inside = opening.side === 'Right' ? 1 : -1;
    const board = (sign: number, reach: number, outer: boolean, material: THREE.Material, drop: number): THREE.Mesh => {
        const edge = face + reach;
        const corner = outer ? edge : 0;
        const start = cornerStart ? from - corner : from - overhang;
        const end = cornerEnd ? to + corner : to + overhang;
        const frameFace = centre + (sign * band.depth * MM) / 2;
        const [z0, z1] = sign > 0 ? [frameFace, edge] : [-edge, frameFace];
        return boxAt(material, start, top - thickness - drop, z0, end - start, thickness, z1 - z0);
    };
    const boards = [board(inside, opening.sillBoard * MM, false, materials.plain('#eeebe5', 0.6, 0), 0)];
    if (wall.type === 'Exterior') {
        boards.push(board(-inside, OuterSillReach * MM, true, materials.plain('#6f6f6c', 0.45, 0.4), 0.02));
    }
    return boards;
}

/**
 * A glazed sash of a walkable French window or a lift-and-slide door: frame bars around a pane, divided by `transoms`,
 * pivoting or sliding like a door leaf; `handle` on its free edge.
 */
function buildSash(
    leaf: Leaf,
    bottom: number,
    height: number,
    transoms: number,
    panel: number | null,
    frameMaterial: THREE.Material,
    glassMaterial: THREE.Material,
    handle = true,
): THREE.Group {
    const sash = leafPivot(leaf, 0);
    sash.position.y = bottom;
    const width = leaf.width * MM;
    const thickness = leaf.thickness * MM;
    const bar = SashBar * MM;
    const z = -thickness / 2;
    sash.add(boxAt(frameMaterial, 0, 0, z, bar, height, thickness));
    sash.add(boxAt(frameMaterial, width - bar, 0, z, bar, height, thickness));
    sash.add(boxAt(frameMaterial, bar, 0, z, width - 2 * bar, bar, thickness));
    sash.add(boxAt(frameMaterial, bar, height - bar, z, width - 2 * bar, bar, thickness));
    // A solid lower panel up to `panel` (from the sash bottom), with a rail above it and raised fields on both faces.
    let glassBottom = bar;
    if (panel !== null && panel > 2 * bar && panel < height - 2 * bar) {
        sash.add(boxAt(frameMaterial, bar, bar, -thickness * 0.3, width - 2 * bar, panel - bar, thickness * 0.6));
        const field = {x: bar / MM, y: bar / MM, width: (width - 2 * bar) / MM, height: (panel - bar) / MM};
        for (const part of panelRelief([field], thickness * 0.6, [-1, 1], frameMaterial)) {
            sash.add(part);
        }
        sash.add(boxAt(frameMaterial, bar, panel, z, width - 2 * bar, bar, thickness));
        glassBottom = panel + bar;
    }
    sash.add(boxAt(glassMaterial, bar, glassBottom, -0.004, width - 2 * bar, height - bar - glassBottom, 0.008, false));
    for (let i = 1; i <= transoms; i++) {
        const y = glassBottom + ((height - bar - glassBottom) * i) / (transoms + 1);
        sash.add(boxAt(frameMaterial, bar, y - bar / 2, z, width - 2 * bar, bar, thickness));
    }
    if (handle) {
        // Handle on the inner face of the free edge.
        const grip = boxAt(frameMaterial, width - bar / 2 - 0.01, 1.05 - bottom, 0, 0.02, 0.14, 0.02);
        grip.position.z = -leaf.swing * (thickness / 2 + 0.015);
        sash.add(grip);
    }
    return sash;
}

/**
 * The group a leaf or sash moves in: at its hinge (a sliding leaf's start edge) in the wall's local frame (x
 * along, −z towards the wall's left), tagged `userData.leaf` so `setLeafPose` can swing or slide it. Children
 * are built along +x from the hinge. `open` is 0 closed … 1 open.
 */
function leafPivot(leaf: Leaf, open: number): THREE.Group {
    const pivot = new THREE.Group();
    pivot.position.set(leaf.along * MM, 0.008, -leaf.across * MM);
    pivot.userData = {leaf};
    setLeafPose(pivot, open);
    return pivot;
}

/**
 * Lifts a garage door's leaf (FD-89) to `open` (0 … 1): a sectional door's sections run up the track and turn under the
 * ceiling into the garage, until the bottom one has rounded the bend; an up-and-over door's panel tilts about an axis a
 * third of the way up, which rises to the head, its top going into the garage; a roller door's slats roll up.
 */
function liftLeaf(pivot: THREE.Object3D, leaf: Leaf, open: number): void {
    // Plan across is local −z: the garage, on `swing`, is −swing in local z.
    const inward = -leaf.swing;
    const height = (leaf.height ?? 0) * MM;
    for (const part of pivot.children) {
        const data = part.userData as {section?: number; sectionHeight?: number; tilt?: boolean; roll?: boolean};
        if (data.section !== undefined) {
            const size = data.sectionHeight ?? 0;
            const at = (s: number): {y: number; z: number} => trackPoint(s, height, inward);
            // Fully open, the bottom section has rounded the bend too: all of them lie under the ceiling.
            const s = data.section * size + open * (height + (Math.PI / 2) * GarageTrackRadius);
            const [a, b] = [at(s), at(s + size)];
            part.position.set(0, a.y, a.z);
            part.rotation.x = Math.atan2(b.z - a.z, b.y - a.y);
        } else if (data.tilt === true) {
            const angle = (open * Math.PI) / 2;
            part.position.y = height / 3 + ((2 * height) / 3) * open;
            part.rotation.x = Math.atan2(inward * Math.sin(angle), Math.cos(angle));
        } else if (data.roll === true) {
            part.position.y = height;
            part.scale.y = Math.max(0.03, 1 - open);
        }
    }
}

/**
 * A point `s` m along a sectional garage door's track (pivot-local y, z): up the jamb to the door's `height`, round a
 * quarter circle of `GarageTrackRadius` and on under the ceiling into the garage (`inward`, local z sign).
 */
function trackPoint(s: number, height: number, inward: number): {y: number; z: number} {
    const r = GarageTrackRadius;
    if (s <= height) {
        return {y: s, z: 0};
    }
    const turn = (Math.PI / 2) * r;
    if (s <= height + turn) {
        const angle = (s - height) / r;
        return {y: height + r * Math.sin(angle), z: inward * r * (1 - Math.cos(angle))};
    }
    return {y: height + r, z: inward * (r + s - height - turn)};
}

/** The leaf of an object tagged by `buildWall`. */
export function leafOf(pivot: THREE.Object3D): Leaf {
    return (pivot.userData as {leaf: Leaf}).leaf;
}

/**
 * Moves a leaf built by `buildWall` (an object with `userData.leaf`) to `open` (0 closed … 1 open): a swinging
 * leaf turns to that share of `LeafOpenAngle`, a sliding one that share of its `travel` along the wall.
 */
export function setLeafPose(pivot: THREE.Object3D, open: number): void {
    const leaf = leafOf(pivot);
    if (leaf.motion === 'slide') {
        pivot.position.x = (leaf.along + open * leaf.travel) * MM;
        return;
    }
    if (leaf.motion === 'lift') {
        liftLeaf(pivot, leaf, open);
        return;
    }
    const angle = open * LeafOpenAngle;
    // Plan across is local −z: the leaf points along (closed · cos, −swing · sin) in local x / z.
    pivot.rotation.y = Math.atan2(leaf.swing * Math.sin(angle), leaf.closed * Math.cos(angle));
}
