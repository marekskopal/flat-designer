import {Opening, Room, Scene, Wall} from '@app/models/scene';
import * as THREE from 'three';

import {MaterialLibrary} from './materials';
import {boxAt, prismAt, worldUvBox} from './meshes';
import {
    DoorFrameWidth,
    Leaf,
    LeafOpenAngle,
    MM,
    openingLeaves,
    polygonBounds,
    polygonSignedArea,
    SquareEnds,
    tileOrigin,
    WallEnds,
    wallFrame,
    WallGap,
    wallGapsAlongEdge,
    wallJoints,
    WallPiece,
    wallPieces,
    WindowFrameBar,
} from './plan-geometry';
import {alignTiles} from './tiling';

const SlabThickness = 300;
/** Bars of a French window sash, mm. */
const SashBar = 55;

/** Colour of wall cores revealed by the section plane (classic poché). */
const SectionColor = '#3d3a36';

export interface BuildingParts {
    root: THREE.Group;
    /** Ceilings, toggled separately so the dollhouse view can look in from above. */
    ceilings: THREE.Group;
    slab: THREE.Mesh;
    /** Per room: its floor mesh (for picking and highlighting), ceiling and wall finishes, keyed by room id. */
    rooms: Map<number, RoomParts>;
    /** Wall groups (with their doors and windows) keyed by wall id. */
    walls: Map<number, THREE.Group>;
    /** Fill of wall cores revealed by the section plane, shared by all walls. */
    sectionMaterial: THREE.Material;
}

export interface RoomParts {
    floor: THREE.Mesh;
    ceiling: THREE.Mesh | null;
    finishes: THREE.Group | null;
}

/**
 * Builds the static architecture: slab, floors, ceilings, walls with their doors and windows. The parts are
 * kept apart so a changed room or wall can be rebuilt on its own (`buildRoom`, `buildWall`).
 */
export function buildBuilding(scene: Scene, materials: MaterialLibrary): BuildingParts {
    const root = new THREE.Group();
    root.name = 'building';
    const ceilings = new THREE.Group();
    ceilings.name = 'ceilings';
    const parts: BuildingParts = {
        root,
        ceilings,
        slab: buildSlab(scene.walls, materials),
        rooms: new Map(),
        walls: new Map(),
        sectionMaterial: new THREE.MeshBasicMaterial({color: SectionColor, side: THREE.BackSide}),
    };
    root.add(parts.slab);

    for (const room of scene.rooms) {
        addRoom(parts, buildRoom(room, scene.walls, materials), room.id);
    }

    const joints = wallJoints(scene.walls);
    for (const wall of scene.walls) {
        const group = buildWall(wall, materials, parts.sectionMaterial, joints.get(wall.id));
        parts.walls.set(wall.id, group);
        root.add(group);
    }

    root.add(ceilings);
    return parts;
}

/** Floor, ceiling and wall finishes of one room (the finishes leave the walls' door openings free). */
export function buildRoom(room: Room, walls: readonly Wall[], materials: MaterialLibrary): RoomParts {
    return {
        floor: buildFloor(room, materials),
        ceiling: room.ceilingHeight === null ? null : buildCeiling(room, materials),
        finishes: room.wallFinishes.length > 0 ? buildWallFinishes(room, walls, materials) : null,
    };
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

export function buildSlab(walls: readonly Wall[], materials: MaterialLibrary): THREE.Mesh {
    const points = walls.flatMap((w) => [
        [w.startX, w.startY] as [number, number],
        [w.endX, w.endY] as [number, number],
    ]);
    const maxThickness = Math.max(...walls.map((w) => w.thickness), 0) / 2;
    const b = polygonBounds(points);
    const minX = b.minX - maxThickness;
    const maxX = b.maxX + maxThickness;
    const minY = b.minY - maxThickness;
    const maxY = b.maxY + maxThickness;

    const slab = boxAt(
        materials.plain('#d6d1c8', 0.95),
        minX * MM,
        -SlabThickness * MM,
        -maxY * MM,
        (maxX - minX) * MM,
        SlabThickness * MM,
        (maxY - minY) * MM,
    );
    slab.castShadow = false;
    slab.name = 'slab';
    return slab;
}

function roomShape(room: Room): THREE.Shape {
    return new THREE.Shape(room.polygon.map(([x, y]) => new THREE.Vector2(x * MM, y * MM)));
}

export function buildFloor(room: Room, materials: MaterialLibrary): THREE.Mesh {
    // Shape in plan (x, y); rotating −90° about x lays it flat with plan y → scene −z. UVs stay in metres.
    const geometry = new THREE.ShapeGeometry(roomShape(room));
    geometry.rotateX(-Math.PI / 2);
    const floor = new THREE.Mesh(geometry, materials.get(room.floorMaterialId));
    floor.position.y = 0.002;
    floor.receiveShadow = true;
    floor.name = `floor:${room.code}`;
    floor.userData = {roomId: room.id};
    alignTiles(floor, tileOrigin(room), materials);
    return floor;
}

function buildCeiling(room: Room, materials: MaterialLibrary): THREE.Mesh {
    const geometry = new THREE.ShapeGeometry(roomShape(room));
    geometry.rotateX(-Math.PI / 2);
    // Faces up after the rotation, so only its back side is visible from inside the room.
    const ceiling = new THREE.Mesh(geometry, materials.plain('#f7f6f3', 0.95, 0, THREE.BackSide));
    ceiling.position.y = (room.ceilingHeight ?? 0) * MM;
    ceiling.receiveShadow = true;
    ceiling.name = `ceiling:${room.code}`;
    return ceiling;
}

/**
 * Wall group in wall-local coordinates: x along the centre line from the start, y up, z across with
 * −z on the left of the wall direction. Solid pieces leave the openings empty; doors and windows are
 * added into them. `ends` mitres the end pieces where the wall meets another one in a corner.
 */
export function buildWall(wall: Wall, materials: MaterialLibrary, sectionMaterial: THREE.Material, ends: WallEnds = SquareEnds): THREE.Group {
    const frame = wallFrame(wall);
    const group = new THREE.Group();
    group.name = `wall:${wall.name}`;
    group.userData = {wallId: wall.id};
    group.position.set(frame.start.x * MM, 0, -frame.start.y * MM);
    group.rotation.y = frame.angle;

    const material = materials.get(wall.materialId);

    for (const piece of wallPieces(frame.length, wall.height, wall.openings)) {
        const mesh = wallPieceMesh(material, piece, frame.length, wall.thickness, ends);
        group.add(mesh);

        // Same box drawn inside-out in a flat dark colour: invisible normally, it fills the cut once the
        // section plane slices through the wall.
        const cap = new THREE.Mesh(mesh.geometry, sectionMaterial);
        cap.position.copy(mesh.position);
        group.add(cap);
    }

    for (const opening of wall.openings) {
        group.add(opening.type === 'Door' ? buildDoor(opening, wall, materials) : buildWindow(opening, wall, materials));
    }

    return group;
}

/** A box, or for a piece at a mitred wall end a prism whose faces run on to the corner. */
function wallPieceMesh(material: THREE.Material, piece: WallPiece, length: number, thickness: number, ends: WallEnds): THREE.Mesh {
    const t = thickness * MM;
    const atStart = piece.from <= 0;
    const atEnd = piece.to >= length;
    const leftFrom = atStart ? -ends.startLeft : piece.from;
    const rightFrom = atStart ? -ends.startRight : piece.from;
    const leftTo = atEnd ? length + ends.endLeft : piece.to;
    const rightTo = atEnd ? length + ends.endRight : piece.to;
    const square = leftFrom === piece.from && rightFrom === piece.from && leftTo === piece.to && rightTo === piece.to;

    if (square || leftTo <= leftFrom || rightTo <= rightFrom) {
        return boxAt(material, piece.from * MM, piece.bottom * MM, -t / 2, (piece.to - piece.from) * MM, (piece.top - piece.bottom) * MM, t);
    }
    const footprint = [
        {x: leftFrom * MM, z: -t / 2},
        {x: leftTo * MM, z: -t / 2},
        {x: rightTo * MM, z: t / 2},
        {x: rightFrom * MM, z: t / 2},
    ];
    return prismAt(material, footprint, piece.bottom * MM, piece.top * MM);
}

const LiningThickness = 8;

/**
 * Tiling (or another finish) as thin boxes along the inside of every room edge, band by band. Door
 * openings on the edge stay free; UVs continue across pieces from the room corner and the floor, and tiles
 * are then put on the room's tile grid (`alignTiles`), the one its floor uses.
 */
export function buildWallFinishes(room: Room, walls: readonly Wall[], materials: MaterialLibrary): THREE.Group {
    const group = new THREE.Group();
    // Clicking a tiled wall selects the room the tiling belongs to.
    group.userData = {roomId: room.id};
    group.name = `finishes:${room.code}`;
    // Counter-clockwise, so the room interior is on the left of every edge.
    const polygon = polygonSignedArea(room.polygon) >= 0 ? room.polygon : [...room.polygon].reverse();

    polygon.forEach(([ax, ay], i) => {
        const [bx, by] = polygon[(i + 1) % polygon.length];
        const length = Math.hypot(bx - ax, by - ay);
        const gaps = wallGapsAlongEdge({x: ax, y: ay}, {x: bx, y: by}, walls);

        const edge = new THREE.Group();
        edge.position.set(ax * MM, 0, -ay * MM);
        edge.rotation.y = Math.atan2(by - ay, bx - ax);
        // Mirror local z so +z points into the room and the box's +z face (u along +x) is the visible one.
        edge.scale.z = -1;

        for (const band of room.wallFinishes) {
            const bandGaps = gaps.flatMap((gap) => clipGap(gap, band.bottom, band.top));
            const material = materials.get(band.materialId);
            for (const piece of wallPieces(length, band.top - band.bottom, bandGaps)) {
                const bottom = band.bottom + piece.bottom;
                const width = (piece.to - piece.from) * MM;
                const height = (piece.top - piece.bottom) * MM;
                const geometry = worldUvBox(width, height, LiningThickness * MM, {u: piece.from * MM, v: bottom * MM});
                const mesh = new THREE.Mesh(geometry, material);
                mesh.position.set(piece.from * MM + width / 2, bottom * MM + height / 2, (LiningThickness * MM) / 2);
                mesh.receiveShadow = true;
                edge.add(mesh);
            }
        }
        group.add(edge);
    });

    alignTiles(group, tileOrigin(room), materials);
    return group;
}

/** Opening relative to a finish band, as `wallPieces` expects; nothing when the gap misses the band. */
function clipGap(gap: WallGap, bandBottom: number, bandTop: number): Opening[] {
    if (gap.top <= bandBottom || gap.bottom >= bandTop) {
        return [];
    }
    const sill = Math.max(gap.bottom - bandBottom, 0);
    const head = Math.min(gap.top, bandTop) - bandBottom;
    return [{
        id: 0,
        name: '',
        type: 'Door',
        offset: gap.from,
        width: gap.to - gap.from,
        height: head - sill,
        sillHeight: sill,
        leafWidth: null,
        hinge: null,
        side: null,
        entrance: false,
    }];
}

function buildDoor(opening: Opening, wall: Wall, materials: MaterialLibrary): THREE.Group {
    const group = new THREE.Group();
    group.name = `door:${opening.name}`;
    group.userData = {openingId: opening.id};
    const frameMaterial = materials.byCode('door-white');
    const t = wall.thickness * MM;
    const from = opening.offset * MM;
    const width = opening.width * MM;
    const height = opening.height * MM;
    const lining = DoorFrameWidth * MM * 0.6;
    const liningDepth = t + 0.02;

    // Lining: two jambs and a head, slightly proud of both wall faces.
    group.add(boxAt(frameMaterial, from, 0, -liningDepth / 2, lining, height, liningDepth));
    group.add(boxAt(frameMaterial, from + width - lining, 0, -liningDepth / 2, lining, height, liningDepth));
    group.add(boxAt(frameMaterial, from, height - lining, -liningDepth / 2, width, lining, liningDepth));

    const leafHeight = Math.min(1970, opening.height - 20) * MM;
    const handleMaterial = materials.plain('#2b2b2b', 0.35, 0.7);
    for (const leaf of openingLeaves(opening, wall.thickness)) {
        const leafGroup = leafPivot(leaf, LeafOpenAngle);
        const leafWidth = leaf.width * MM;
        const leafThickness = leaf.thickness * MM;
        leafGroup.add(boxAt(frameMaterial, 0, 0, -leafThickness / 2, leafWidth, leafHeight, leafThickness));

        // Lever handles on both faces near the free edge.
        for (const face of [-1, 1]) {
            const handle = boxAt(handleMaterial, leafWidth - 0.16, 1.03, 0, 0.12, 0.018, 0.018);
            handle.position.z = face * (leafThickness / 2 + 0.03);
            leafGroup.add(handle);
        }
        group.add(leafGroup);
    }

    return group;
}

function buildWindow(opening: Opening, wall: Wall, materials: MaterialLibrary): THREE.Group {
    const group = new THREE.Group();
    group.name = `window:${opening.name}`;
    group.userData = {openingId: opening.id};
    const frameMaterial = materials.byCode('window-frame-anthracite');
    const glassMaterial = materials.byCode('glass');
    const from = opening.offset * MM;
    const width = opening.width * MM;
    const bottom = opening.sillHeight * MM;
    const height = opening.height * MM;
    const bar = WindowFrameBar * MM;
    const depth = 0.08;
    const z = -depth / 2;

    group.add(boxAt(frameMaterial, from, bottom, z, bar, height, depth));
    group.add(boxAt(frameMaterial, from + width - bar, bottom, z, bar, height, depth));
    group.add(boxAt(frameMaterial, from, bottom + height - bar, z, width, bar, depth));
    group.add(boxAt(frameMaterial, from, bottom, z, width, 0.04, depth));

    const leaves = openingLeaves(opening, wall.thickness);
    if (leaves.length > 0) {
        // Walkable: two sashes hinged on the jambs, closed by default.
        for (const leaf of leaves) {
            group.add(buildSash(leaf, bottom + 0.04, height - bar - 0.04, frameMaterial, glassMaterial));
        }
        return group;
    }

    // French window with two sashes.
    group.add(boxAt(frameMaterial, from + width / 2 - bar / 2, bottom, z, bar, height, depth));

    const paneWidth = width / 2 - 1.5 * bar;
    for (const paneFrom of [from + bar, from + width / 2 + bar / 2]) {
        const pane = boxAt(glassMaterial, paneFrom, bottom + 0.04, -0.004, paneWidth, height - bar - 0.04, 0.008, false);
        group.add(pane);
    }

    return group;
}

/** A glazed sash of a walkable French window: frame bars around a pane, pivoting like a door leaf. */
function buildSash(leaf: Leaf, bottom: number, height: number, frameMaterial: THREE.Material, glassMaterial: THREE.Material): THREE.Group {
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
    sash.add(boxAt(glassMaterial, bar, bar, -0.004, width - 2 * bar, height - 2 * bar, 0.008, false));
    // Handle on the inner face of the free edge.
    const handle = boxAt(frameMaterial, width - bar / 2 - 0.01, 1.05 - bottom, 0, 0.02, 0.14, 0.02);
    handle.position.z = -leaf.swing * (thickness / 2 + 0.015);
    sash.add(handle);
    return sash;
}

/**
 * The group a leaf or sash turns in: at its hinge in the wall's local frame (x along, −z towards the wall's
 * left), tagged `userData.leaf` so `setLeafAngle` can swing it. Children are built along +x from the hinge.
 */
function leafPivot(leaf: Leaf, angle: number): THREE.Group {
    const pivot = new THREE.Group();
    pivot.position.set(leaf.along * MM, 0.008, -leaf.across * MM);
    pivot.userData = {leaf};
    setLeafAngle(pivot, angle);
    return pivot;
}

/** Swings a leaf built by `buildWall` (an object with `userData.leaf`) to `angle` radians (0 closed). */
export function setLeafAngle(pivot: THREE.Object3D, angle: number): void {
    const leaf = (pivot.userData as {leaf: Leaf}).leaf;
    // Plan across is local −z: the leaf points along (closed · cos, −swing · sin) in local x / z.
    pivot.rotation.y = Math.atan2(leaf.swing * Math.sin(angle), leaf.closed * Math.cos(angle));
}
