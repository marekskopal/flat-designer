import {Opening, Room, Scene, Wall} from '@app/models/scene';
import * as THREE from 'three';

import {MaterialLibrary} from './materials';
import {boxAt, worldUvBox} from './meshes';
import {MM, polygonBounds, polygonSignedArea, wallFrame, WallGap, wallGapsAlongEdge, wallPieces} from './plan-geometry';

const SlabThickness = 300;
const FrameWidth = 50;
const LeafThickness = 40;
const DoorOpenAngle = THREE.MathUtils.degToRad(80);

/** Colour of wall cores revealed by the section plane (classic poché). */
const SectionColor = '#3d3a36';

export interface BuildingParts {
    root: THREE.Group;
    /** Ceilings, toggled separately so the dollhouse view can look in from above. */
    ceilings: THREE.Group;
    /** Room floor meshes keyed by room id, for picking and highlighting. */
    floors: Map<number, THREE.Mesh>;
}

/** Builds the static architecture: slab, floors, ceilings, walls with their doors and windows. */
export function buildBuilding(scene: Scene, materials: MaterialLibrary): BuildingParts {
    const root = new THREE.Group();
    root.name = 'building';
    const ceilings = new THREE.Group();
    ceilings.name = 'ceilings';
    const floors = new Map<number, THREE.Mesh>();

    root.add(buildSlab(scene, materials));

    for (const room of scene.rooms) {
        const floor = buildFloor(room, materials);
        floors.set(room.id, floor);
        root.add(floor);
        if (room.ceilingHeight !== null) {
            ceilings.add(buildCeiling(room, materials));
        }
    }

    for (const room of scene.rooms) {
        if (room.wallFinishes.length > 0) {
            root.add(buildWallFinishes(room, scene.walls, materials));
        }
    }

    const sectionMaterial = new THREE.MeshBasicMaterial({color: SectionColor, side: THREE.BackSide});
    for (const wall of scene.walls) {
        root.add(buildWall(wall, materials, sectionMaterial));
    }

    root.add(ceilings);
    return {root, ceilings, floors};
}

function buildSlab(scene: Scene, materials: MaterialLibrary): THREE.Mesh {
    const points = scene.walls.flatMap((w) => [
        [w.startX, w.startY] as [number, number],
        [w.endX, w.endY] as [number, number],
    ]);
    const maxThickness = Math.max(...scene.walls.map((w) => w.thickness), 0) / 2;
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

function buildFloor(room: Room, materials: MaterialLibrary): THREE.Mesh {
    // Shape in plan (x, y); rotating −90° about x lays it flat with plan y → scene −z. UVs stay in metres.
    const geometry = new THREE.ShapeGeometry(roomShape(room));
    geometry.rotateX(-Math.PI / 2);
    const floor = new THREE.Mesh(geometry, materials.get(room.floorMaterialId));
    floor.position.y = 0.002;
    floor.receiveShadow = true;
    floor.name = `floor:${room.code}`;
    floor.userData = {roomId: room.id};
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
 * added into them.
 */
function buildWall(wall: Wall, materials: MaterialLibrary, sectionMaterial: THREE.Material): THREE.Group {
    const frame = wallFrame(wall);
    const group = new THREE.Group();
    group.name = `wall:${wall.name}`;
    group.userData = {wallId: wall.id};
    group.position.set(frame.start.x * MM, 0, -frame.start.y * MM);
    group.rotation.y = frame.angle;

    const material = materials.get(wall.materialId);
    const t = wall.thickness * MM;

    for (const piece of wallPieces(frame.length, wall.height, wall.openings)) {
        const mesh = boxAt(material, piece.from * MM, piece.bottom * MM, -t / 2, (piece.to - piece.from) * MM, (piece.top - piece.bottom) * MM, t);
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

const LiningThickness = 8;

/**
 * Tiling (or another finish) as thin boxes along the inside of every room edge, band by band. Door
 * openings on the edge stay free; UVs continue across pieces so the tile grid runs from the room
 * corner and from the floor.
 */
function buildWallFinishes(room: Room, walls: readonly Wall[], materials: MaterialLibrary): THREE.Group {
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
    const lining = FrameWidth * MM * 0.6;
    const liningDepth = t + 0.02;

    // Lining: two jambs and a head, slightly proud of both wall faces.
    group.add(boxAt(frameMaterial, from, 0, -liningDepth / 2, lining, height, liningDepth));
    group.add(boxAt(frameMaterial, from + width - lining, 0, -liningDepth / 2, lining, height, liningDepth));
    group.add(boxAt(frameMaterial, from, height - lining, -liningDepth / 2, width, lining, liningDepth));

    const leafWidth = (opening.leafWidth ?? opening.width - 2 * FrameWidth) * MM;
    const leafHeight = Math.min(1970, opening.height - 20) * MM;
    const clearance = (width - leafWidth) / 2;
    const hingeAtEnd = opening.hinge === 'End';
    const towardsLeft = opening.side !== 'Right';

    // Leaf rotates about the hinge into the swing side: closed along ±x, open towards ∓z.
    const closed = hingeAtEnd ? -1 : 1;
    const across = towardsLeft ? -1 : 1;
    const dirX = closed * Math.cos(DoorOpenAngle);
    const dirZ = across * Math.sin(DoorOpenAngle);
    const hingeX = hingeAtEnd ? from + width - clearance : from + clearance;
    const hingeZ = (across * t) / 2;

    const leaf = new THREE.Group();
    leaf.position.set(hingeX, 0.008, hingeZ);
    leaf.rotation.y = Math.atan2(-dirZ, dirX);
    const panel = boxAt(frameMaterial, 0, 0, -LeafThickness * MM / 2, leafWidth, leafHeight, LeafThickness * MM);
    leaf.add(panel);

    // Lever handles on both faces near the free edge.
    const handleMaterial = materials.plain('#2b2b2b', 0.35, 0.7);
    for (const face of [-1, 1]) {
        const handle = boxAt(handleMaterial, leafWidth - 0.16, 1.03, 0, 0.12, 0.018, 0.018);
        handle.position.z = face * (LeafThickness * MM / 2 + 0.03);
        leaf.add(handle);
    }
    group.add(leaf);

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
    const bar = 0.07;
    const depth = 0.08;
    const z = -depth / 2;

    group.add(boxAt(frameMaterial, from, bottom, z, bar, height, depth));
    group.add(boxAt(frameMaterial, from + width - bar, bottom, z, bar, height, depth));
    group.add(boxAt(frameMaterial, from, bottom + height - bar, z, width, bar, depth));
    group.add(boxAt(frameMaterial, from, bottom, z, width, 0.04, depth));
    // French window with two sashes.
    group.add(boxAt(frameMaterial, from + width / 2 - bar / 2, bottom, z, bar, height, depth));

    const paneWidth = width / 2 - 1.5 * bar;
    for (const paneFrom of [from + bar, from + width / 2 + bar / 2]) {
        const pane = boxAt(glassMaterial, paneFrom, bottom + 0.04, -0.004, paneWidth, height - bar - 0.04, 0.008, false);
        group.add(pane);
    }

    return group;
}
