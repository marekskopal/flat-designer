import {Opening, PlanPoint, Plot, Roof, Room, Scene, Wall, WallFinish} from '@app/models/scene';
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
import {MaterialLibrary} from './materials';
import {mergeStatic} from './merge';
import {boxAt, curvedPrismAt, prismAt, worldUvBox} from './meshes';
import {exteriorSign, freeBeside, leafPanels, LeafStile, louvreSlats, PanelRect, shutterLeaves} from './opening-looks';
import {
    alongWall,
    cornerWindows,
    counterClockwise,
    DoorFrameWidth,
    isDoor,
    Leaf,
    LeafOpenAngle,
    MM,
    NoCorners,
    openingFrame,
    openingLeaves,
    pointOnWall,
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
import {buildPlot, groundUnder} from './plots';
import {buildRoof, buildRoofOutline} from './roofs';
import {buildStair, buildStairsBelow, cutShape, cutVoids, NoVoids, SceneVoids,sceneVoids} from './stairs';
import {alignTiles, turnLevelUvs} from './tiling';
import {ceilingParts, FloorRoofs, HeightAt, lowestTopAlong, outlineWithout, ProfileRun, profileRuns, runsAreLevel, wallTopAt} from './under-roof';

/** Bars of a French window sash, mm. */
const SashBar = 55;

export interface BuildingParts {
    root: THREE.Group;
    /** Ceilings, toggled separately so the dollhouse view can look in from above. */
    ceilings: THREE.Group;
    /** The faces between rooms' ceilings of different heights where they meet without a wall (in `ceilings`). */
    ceilingSteps: THREE.Group;
    slab: THREE.Mesh;
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
 * Builds the static architecture: slab, floors, ceilings, walls with their doors and windows, stairs. The parts are
 * kept apart so a changed room or wall can be rebuilt on its own (`buildRoom`, `buildWall`). The stairs' voids
 * (`sceneVoids()`) are cut through the slab and room floors (the floor below's stairs) and the flat ceilings (its own).
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
        slab: buildSlab(scene.walls, scene.rooms, scene.floor.slabThickness, materials, voids.below),
        rooms: new Map(),
        plots: new Map(),
        stairs: new Map(),
        stairsBelow: buildStairsBelow(scene.stairsBelow, materials, sectionMaterial),
        walls: new Map(),
        roofs: new Map(),
        roofBodies: new THREE.Group(),
        roofOutlines: new THREE.Group(),
        sectionMaterial,
    };
    parts.roofOutlines.name = 'roof-outlines';
    parts.roofOutlines.visible = false;
    parts.roofBodies.name = 'roofs';
    root.add(parts.slab, parts.stairsBelow, parts.roofBodies, parts.roofOutlines);

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
        const group = buildWall(wall, materials, parts.sectionMaterial, joints.get(wall.id), corners.get(wall.id), roofs);
        parts.walls.set(wall.id, group);
        root.add(group);
    }

    for (const roof of scene.roofs ?? []) {
        addRoof(parts, roof, materials);
    }

    ceilings.add(parts.ceilingSteps);
    root.add(ceilings);
    return parts;
}

/** Builds a roof and its outline into the building: the roof with the other roofs, the outline with the others. */
export function addRoof(parts: BuildingParts, roof: Roof, materials: MaterialLibrary): RoofParts {
    const built: RoofParts = {roof: buildRoof(roof, materials, parts.sectionMaterial), outline: buildRoofOutline(roof), windows: new Map()};
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
 * floor elevation; the floor's edge steps down (or up) to the ground of the plot the room lies on. The stairs' voids
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
        floor: buildFloor(room, materials, groundUnder(room, plots), voids.below),
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

/** Rooms on the ground itself, which have no slab under them. */
const UnslabbedRooms: readonly Room['type'][] = ['Garden', 'Path', 'Driveway'];

/**
 * The slab under the floor, `thickness` mm deep: under its rooms (not gardens, paths and driveways, which lie on the
 * ground; a room's piece hangs under its own floor elevation) and its walls' footprints, so an upper floor's slab
 * roofs only what it covers. The outlines are extruded and merged into one mesh; where they overlap, their faces lie
 * inside the solid or coincide. The `voids` (the stairs coming up from the floor below) are cut out of every piece.
 */
export function buildSlab(
    walls: readonly Wall[],
    rooms: readonly Room[],
    thickness: number,
    materials: MaterialLibrary,
    voids: readonly PlanPoint[][] = [],
): THREE.Mesh {
    const joints = wallJoints(walls);
    const outlines: {outline: Vec2[]; top: number}[] = [
        ...rooms
            .filter((r) => !UnslabbedRooms.includes(r.type))
            .map((r) => ({outline: r.polygon.map(([x, y]): Vec2 => ({x, y})), top: r.floorElevation ?? 0})),
        ...walls.map((wall) => {
            const {left, right} = wallFaces(wall, joints.get(wall.id));
            return {outline: [...left, ...[...right].reverse()], top: 0};
        }),
    ].filter(({outline}) => outline.length >= 3);
    const cut = outlines.flatMap(({outline, top}) =>
        cutVoids(
            outline.map((p): PlanPoint => [p.x, p.y]),
            voids,
        ).map((piece) => ({piece, top})),
    );
    const pieces = cut.map(({piece, top}) => {
        const geometry = new THREE.ExtrudeGeometry(cutShape(piece), {depth: thickness * MM, bevelEnabled: false});
        geometry.rotateX(-Math.PI / 2);
        geometry.translate(0, (top - thickness) * MM, 0);
        // Merged pieces need the same attributes; the slab has no texture.
        geometry.deleteAttribute('uv');
        return geometry;
    });
    const geometry = pieces.length === 0 ? new THREE.BufferGeometry() : mergeGeometries(pieces);
    pieces.forEach((piece) => piece !== geometry && piece.dispose());

    const slab = new THREE.Mesh(geometry, materials.plain('#d6d1c8', 0.95));
    slab.castShadow = false;
    slab.receiveShadow = true;
    slab.name = 'slab';
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

/**
 * The room's floor at its elevation; `ground` (mm) is the level beside it, the plot's ground or the floor's level,
 * which the floor's edge steps down or up to when the two differ. The `voids` (stairs coming up from below) are cut out.
 */
export function buildFloor(room: Room, materials: MaterialLibrary, ground = 0, voids: readonly PlanPoint[][] = []): THREE.Mesh {
    // Shape in plan (x, y); rotating −90° about x lays it flat with plan y → scene −z. UVs stay in metres.
    const geometry = roomSurface(room, voids);
    geometry.rotateX(-Math.PI / 2);
    const grid = tileGrid(room);
    const material = materials.get(room.floorMaterialId);
    // Laid tiles get their UVs from `alignTiles` below; the other patterns (planks, wood) are turned here.
    if (!materials.isTile(material)) {
        turnLevelUvs(geometry, grid);
    }
    const floor = new THREE.Mesh(geometry, material);
    const elevation = room.floorElevation ?? 0;
    floor.position.y = (FloorLift + elevation) * MM;
    floor.receiveShadow = true;
    floor.name = `floor:${room.code}`;
    floor.userData = {roomId: room.id};
    alignTiles(floor, grid, materials);
    if (elevation !== ground) {
        // From the floor mesh, which sits `FloorLift` over the floor's elevation, down to the ground beside it.
        floor.add(buildFloorEdge(room.polygon, ground - elevation - FloorLift, materials));
    }
    return floor;
}

/** The floor mesh's height over the room's floor elevation, mm, so it draws over the slab and the plots' ground. */
const FloorLift = 2;

/** The upright face at the step of a room whose floor is off the ground beside it. */
const FloorEdgeColor = '#b9b2a6';
/** How far the edge stands inside the room's outline, mm: off the wall faces along it, so the two do not z-fight. */
const FloorEdgeInset = 2;

/**
 * The upright face round a room whose floor is off the ground beside it: from the room's floor (the parent's height,
 * so y 0 here) down, or up, `drop` mm to that ground — a raised deck's side, a sunken terrace's step. Both sides show.
 */
function buildFloorEdge(polygon: readonly PlanPoint[], drop: number, materials: MaterialLibrary): THREE.Mesh {
    const positions: number[] = [];
    const indices: number[] = [];
    const outline = counterClockwise(polygon);
    outline.forEach(([ax, ay], i) => {
        const [bx, by] = outline[(i + 1) % outline.length];
        const length = Math.hypot(bx - ax, by - ay);
        if (length < 1) {
            return;
        }
        // Counter-clockwise: the room is on the left, so the left normal points inwards.
        const nx = (-(by - ay) / length) * FloorEdgeInset;
        const ny = ((bx - ax) / length) * FloorEdgeInset;
        const [x0, y0, x1, y1] = [(ax + nx) * MM, -(ay + ny) * MM, (bx + nx) * MM, -(by + ny) * MM];
        const base = positions.length / 3;
        positions.push(x0, 0, y0, x1, 0, y1, x1, drop * MM, y1, x0, drop * MM, y0);
        indices.push(base, base + 1, base + 2, base, base + 2, base + 3);
    });
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
): THREE.Group {
    const frame = wallFrame(wall);
    const group = new THREE.Group();
    group.name = `wall:${wall.name}`;
    group.userData = {wallId: wall.id};
    group.position.set(frame.start.x * MM, 0, -frame.start.y * MM);
    group.rotation.y = frame.angle;

    const material = materials.get(wall.materialId);
    const top = wallTopAt(wall, roofs);
    // An opening the roofs leave no wall for (its sill above the lowest top over it) is left out, and the wall is solid there.
    const underTop = (opening: Opening): boolean =>
        top === null ||
        opening.sillHeight < lowestTopAlong(pointOnWall(frame, opening.offset), pointOnWall(frame, opening.offset + opening.width), top, roofs);
    const openings = wall.openings.filter(underTop);

    for (const piece of wallPieces(frame.length, wall.height, openings)) {
        const meshes = (top === null ? null : profiledWallPiece(material, piece, frame, wall, ends, top, roofs)) ?? [
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
        if (frame.arc !== undefined) {
            placeOnFrame(part, frame, openingFrame(frame, opening));
        }
        group.add(part);
    }

    mergeStatic(group);
    return group;
}

/** Puts an object built in the coordinates of `target` into the group of a wall on `frame`. */
function placeOnFrame(object: THREE.Object3D, frame: WallFrame, target: WallFrame): void {
    const dx = target.start.x - frame.start.x;
    const dy = target.start.y - frame.start.y;
    object.position.set((dx * frame.dir.x + dy * frame.dir.y) * MM, 0, -(dx * frame.normal.x + dy * frame.normal.y) * MM);
    object.rotation.y = target.angle - frame.angle;
}

/**
 * A piece of a wall under roofs, its top following `top` (a piece reaching the wall's top) or capped by it (a lower
 * one: under a window, behind a niche): its outline (`wallOutline()`) stood up in runs (`profileRuns()`) up to the top
 * over each point, never under the piece's bottom. Null when the roofs leave the piece as it is, which is then drawn as
 * usual; empty when they take all of it. Tagged with the niche behind which it is, like `wallPieceMesh()`.
 */
function profiledWallPiece(
    material: THREE.Material,
    piece: WallPiece,
    frame: WallFrame,
    wall: Wall,
    ends: WallEnds,
    top: HeightAt,
    roofs: FloorRoofs,
): THREE.Mesh[] | null {
    const reachesTop = piece.top >= wall.height;
    const pieceTop: HeightAt = (p) => Math.max(piece.bottom, reachesTop ? top(p) : Math.min(piece.top, top(p)));
    const outline = wallOutline(frame, wall.thickness, piece.from, piece.to, ends, piece.recess);
    const half = outline.length / 2;
    const runs = profileRuns(outline.slice(0, half), outline.slice(half).reverse(), pieceTop, roofs, [piece.top, piece.bottom, wall.height]);
    if (runsAreLevel(runs, piece.top)) {
        return null;
    }
    const local = wallLocal(frame);
    return runs
        .filter((run) => !runsAreLevel([run], piece.bottom))
        .map((run: ProfileRun) => {
            const tops = {right: run.rightTop.map((h) => h * MM), left: run.leftTop.map((h) => h * MM)};
            const mesh = curvedPrismAt(material, run.right.map(local), run.left.map(local), piece.bottom * MM, tops);
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
        const curved = wallFacesAlongEdge(a, b, walls).find((face) => (face.wall.sagitta ?? 0) !== 0);

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
                    const lining = roofs.length === 0
                        ? null
                        : finishLining(material, a, b, from, segment.from + piece.to, bottom, band.bottom + piece.top, elevation, roofs, curved);
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
 * `arcFaceUvs()` lays them). Null when the roofs leave it whole.
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
    curved: EdgeFace | undefined,
): THREE.Mesh[] | null {
    const length = Math.hypot(b.x - a.x, b.y - a.y);
    const dir = {x: (b.x - a.x) / length, y: (b.y - a.y) / length};
    // The room is on the left of the edge.
    const inward = {x: -dir.y * LiningThickness, y: dir.x * LiningThickness};
    const at = (along: number): Vec2 => ({x: a.x + dir.x * along, y: a.y + dir.y * along});
    const face = [at(from), at(to)];
    const room = face.map((p) => ({x: p.x + inward.x, y: p.y + inward.y}));
    const cap = wallTopAt({height: top + elevation, upToRoof: false}, roofs);
    if (cap === null) {
        return null;
    }
    const bandTop: HeightAt = (p) => Math.max(bottom, cap(p) - elevation);
    const runs = profileRuns(face, room, bandTop, roofs, [top + elevation, bottom + elevation]);
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
        return bands == null ? [] : [{from: face.from, to: face.to, bands}];
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

function buildDoor(opening: Opening, wall: Wall, materials: MaterialLibrary): THREE.Group {
    const group = new THREE.Group();
    group.name = `door:${opening.name}`;
    group.userData = {openingId: opening.id};
    const frameMaterial = materials.byCode('door-white');
    for (const part of doorFrame(opening, wall.thickness, frameMaterial)) {
        group.add(part);
    }

    const handleMaterial = materials.plain('#2b2b2b', 0.35, 0.7);
    for (const leaf of openingLeaves(opening, wall.thickness)) {
        // A sliding leaf covers the opening's head too; the others stop short of the frame head (and the floor).
        const leafHeight =
            opening.type === 'SlidingDoor'
                ? (opening.height + SlidingDoorOverlap) * MM
                : (opening.height - LeafGap) * MM - doorHeadDepth(opening);
        const pivot = leafPivot(leaf, 1);
        for (const part of doorLeaf(opening, leaf, leafHeight, frameMaterial, materials.byCode('glass'))) {
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

/**
 * A door leaf by its `leafStyle`, in its pivot's frame (x from the hinge, centred on z = 0): a flush slab, a slab
 * with raised panels on both faces, or stiles and rails round glass panes.
 */
function doorLeaf(opening: Opening, leaf: Leaf, height: number, material: THREE.Material, glass: THREE.Material): THREE.Mesh[] {
    const width = leaf.width * MM;
    const t = leaf.thickness * MM;
    const style = opening.leafStyle ?? 'Flush';
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
    // A lining is slightly proud of both wall faces; a flush frame is thinner and level with them.
    const lining = doorHeadDepth(opening);
    const depth = wallThickness * MM + (style === 'Flush' ? 0 : 0.02);
    const parts = [
        boxAt(material, from, 0, -depth / 2, lining, height, depth),
        boxAt(material, from + width - lining, 0, -depth / 2, lining, height, depth),
        boxAt(material, from, height - lining, -depth / 2, width, lining, depth),
    ];
    if (style === 'Architrave') {
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

/** Depth of a window frame, centred on the wall's centre line, m. */
const WindowFrameDepth = 0.08;
/** Sill boards: thickness, overhang past the opening on each side, and the outer sill's reach past the wall face (mm). */
const SillBoardThickness = 20;
const SillBoardOverhang = 30;
const OuterSillReach = 40;

/**
 * A window: frame, glazing divided by `mullions` and `transoms` (or a walkable window's sashes) and sill boards.
 * At a corner (`corners`), the frame runs on to the wall's end without a jamb and the glass meets the other
 * window's at the corner.
 */
function buildWindow(opening: Opening, wall: Wall, materials: MaterialLibrary, corners: WallCorners): THREE.Group {
    const group = new THREE.Group();
    group.name = `window:${opening.name}`;
    group.userData = {openingId: opening.id};
    const frameMaterial = materials.byCode('window-frame-anthracite');
    const glassMaterial = materials.byCode('glass');
    const from = opening.offset * MM;
    const to = (opening.offset + opening.width) * MM;
    const bottom = opening.sillHeight * MM;
    const height = opening.height * MM;
    const bar = WindowFrameBar * MM;
    const depth = WindowFrameDepth;
    const z = -depth / 2;
    const cornerStart = corners.start === opening.id;
    const cornerEnd = corners.end === opening.id;
    // The head and bottom of a corner window reach past the corner by half the frame depth to meet the other's.
    const frameFrom = cornerStart ? from - depth / 2 : from;
    const frameTo = cornerEnd ? to + depth / 2 : to;

    if (!cornerStart) {
        group.add(boxAt(frameMaterial, from, bottom, z, bar, height, depth));
    }
    if (!cornerEnd) {
        group.add(boxAt(frameMaterial, to - bar, bottom, z, bar, height, depth));
    }
    group.add(boxAt(frameMaterial, frameFrom, bottom + height - bar, z, frameTo - frameFrom, bar, depth));
    group.add(boxAt(frameMaterial, frameFrom, bottom, z, frameTo - frameFrom, 0.04, depth));
    for (const board of sillBoards(opening, wall, cornerStart, cornerEnd, materials)) {
        group.add(board);
    }

    const transoms = opening.transoms ?? 0;
    const leaves = openingLeaves(opening, wall.thickness);
    if (leaves.length > 0) {
        // Walkable: sashes hinged on the jambs, closed by default.
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
    const glassFrom = cornerStart ? from : from + bar;
    const glassTo = cornerEnd ? to : to - bar;
    let glassBottom = bottom + 0.04;
    const glassTop = bottom + height - bar;
    if (opening.panelHeight != null && opening.panelHeight * MM > 0.04 + bar) {
        const panelTop = bottom + opening.panelHeight * MM;
        group.add(boxAt(frameMaterial, glassFrom, glassBottom, -depth * 0.3, glassTo - glassFrom, panelTop - glassBottom, depth * 0.6));
        group.add(boxAt(frameMaterial, glassFrom, panelTop, z, glassTo - glassFrom, bar, depth));
        glassBottom = panelTop + bar;
    }
    group.add(boxAt(glassMaterial, glassFrom, glassBottom, -0.004, glassTo - glassFrom, glassTop - glassBottom, 0.008, false));
    const columns = (opening.mullions ?? 1) + 1;
    for (let i = 1; i < columns; i++) {
        const x = glassFrom + ((glassTo - glassFrom) * i) / columns;
        group.add(boxAt(frameMaterial, x - bar / 2, glassBottom, z, bar, glassTop - glassBottom, depth));
    }
    for (let i = 1; i <= transoms; i++) {
        const y = glassBottom + ((glassTop - glassBottom) * i) / (transoms + 1);
        group.add(boxAt(frameMaterial, glassFrom, y - bar / 2, z, glassTo - glassFrom, bar, depth));
    }
    for (const part of shutters(opening, wall, materials)) {
        group.add(part);
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
    const frameFace = WindowFrameDepth / 2;
    const overhang = SillBoardOverhang * MM;
    const thickness = SillBoardThickness * MM;
    // Plan across is local −z: the interior on the left of the wall is −z.
    const inside = opening.side === 'Right' ? 1 : -1;
    const board = (sign: number, reach: number, outer: boolean, material: THREE.Material, drop: number): THREE.Mesh => {
        const edge = face + reach;
        const corner = outer ? edge : 0;
        const start = cornerStart ? from - corner : from - overhang;
        const end = cornerEnd ? to + corner : to + overhang;
        const z = sign > 0 ? frameFace : -edge;
        return boxAt(material, start, top - thickness - drop, z, end - start, thickness, edge - frameFace);
    };
    const boards = [board(inside, opening.sillBoard * MM, false, materials.plain('#eeebe5', 0.6, 0), 0)];
    if (wall.type === 'Exterior') {
        boards.push(board(-inside, OuterSillReach * MM, true, materials.plain('#6f6f6c', 0.45, 0.4), 0.02));
    }
    return boards;
}

/**
 * A glazed sash of a walkable French window: frame bars around a pane, divided by `transoms`, pivoting like a door
 * leaf.
 */
function buildSash(
    leaf: Leaf,
    bottom: number,
    height: number,
    transoms: number,
    panel: number | null,
    frameMaterial: THREE.Material,
    glassMaterial: THREE.Material,
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
    // Handle on the inner face of the free edge.
    const handle = boxAt(frameMaterial, width - bar / 2 - 0.01, 1.05 - bottom, 0, 0.02, 0.14, 0.02);
    handle.position.z = -leaf.swing * (thickness / 2 + 0.015);
    sash.add(handle);
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
    const angle = open * LeafOpenAngle;
    // Plan across is local −z: the leaf points along (closed · cos, −swing · sin) in local x / z.
    pivot.rotation.y = Math.atan2(leaf.swing * Math.sin(angle), leaf.closed * Math.cos(angle));
}
