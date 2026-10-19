import {Opening, Room, Scene, Wall} from '@app/models/scene';
import * as THREE from 'three';

import {MaterialLibrary} from './materials';
import {boxAt, prismAt, worldUvBox} from './meshes';
import {
    cornerWindows,
    DoorFrameWidth,
    isDoor,
    Leaf,
    LeafOpenAngle,
    MM,
    NoCorners,
    openingLeaves,
    polygonBounds,
    polygonSignedArea,
    SlidingDoorOverlap,
    SquareEnds,
    tileOrigin,
    WallCorners,
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
    const corners = cornerWindows(scene.walls);
    for (const wall of scene.walls) {
        const group = buildWall(wall, materials, parts.sectionMaterial, joints.get(wall.id), corners.get(wall.id));
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
 * added into them. `ends` mitres the end pieces where the wall meets another one in a corner; `corners` names
 * the corner windows at its ends (`cornerWindows()`).
 */
export function buildWall(
    wall: Wall,
    materials: MaterialLibrary,
    sectionMaterial: THREE.Material,
    ends: WallEnds = SquareEnds,
    corners: WallCorners = NoCorners,
): THREE.Group {
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
        group.add(isDoor(opening) ? buildDoor(opening, wall, materials) : buildWindow(opening, wall, materials, corners));
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
        // A sliding leaf covers the opening's head too; the others stop short of the lining.
        const leafHeight = (opening.type === 'SlidingDoor' ? opening.height + SlidingDoorOverlap : Math.min(1970, opening.height - 20)) * MM;
        const pivot = leafPivot(leaf, 1);
        pivot.add(boxAt(frameMaterial, 0, 0, (-leaf.thickness * MM) / 2, leaf.width * MM, leafHeight, leaf.thickness * MM));
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
    const lining = DoorFrameWidth * MM * (style === 'Flush' ? 0.4 : 0.6);
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
        for (const leaf of leaves) {
            group.add(buildSash(leaf, bottom + 0.04, height - bar - 0.04, transoms, frameMaterial, glassMaterial));
        }
        return group;
    }

    // Fixed glazing: one pane behind a grid of bars, evenly spaced between the jambs (or the corner).
    const glassFrom = cornerStart ? from : from + bar;
    const glassTo = cornerEnd ? to : to - bar;
    const glassBottom = bottom + 0.04;
    const glassTop = bottom + height - bar;
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

    return group;
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
    sash.add(boxAt(glassMaterial, bar, bar, -0.004, width - 2 * bar, height - 2 * bar, 0.008, false));
    for (let i = 1; i <= transoms; i++) {
        const y = bar + ((height - 2 * bar) * i) / (transoms + 1);
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
