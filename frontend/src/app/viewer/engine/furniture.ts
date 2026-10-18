import {ChairParams, Item, WardrobeParams} from '@app/models/scene';
import * as THREE from 'three';

import {GrainDirection, MaterialLibrary} from './materials';
import {boxAt, prismAt, worldUvBox} from './meshes';
import {MM} from './plan-geometry';

/**
 * Furniture in the item-local frame: origin at the bottom centre of the item's box, x along the width,
 * y up and +z out of the front. Returns null for kinds handled elsewhere.
 */
export function buildFurnitureItemBody(item: Item, materials: MaterialLibrary): THREE.Group | null {
    switch (item.kind) {
        case 'Wardrobe':
            return buildWardrobe(item, materials);
        case 'Chair':
            return buildChair(item, materials);
        case 'Table':
            return buildTable(item, materials);
        default:
            return null;
    }
}

/** Carcass sides and top. */
const PanelThickness = 19;
/** Depth taken by the two door tracks behind the front face. */
const DoorZone = 60;
const LeafThickness = 20;
const TrackGap = 5;
/** Neighbouring leaves overlap by this much, so no gap shows between them. */
const LeafOverlap = 25;
const ProfileWidth = 22;
const InfillThickness = 8;
const TopTrackHeight = 40;
const BottomRailHeight = 15;
const DefaultPlinth = 60;
const DoorWidth = 900;
const MinDoors = 2;
const MaxDoors = 6;

export interface SlidingLeaf {
    /** Left edge (mm, item-local x). */
    x: number;
    width: number;
    track: 'front' | 'back';
}

/** Number of sliding leaves: `params.doors`, or one per ~900 mm of the width. */
export function wardrobeDoors(item: Pick<Item, 'width' | 'params'>): number {
    const doors = (item.params as WardrobeParams).doors;
    if (typeof doors === 'number' && Number.isInteger(doors)) {
        return Math.min(MaxDoors, Math.max(MinDoors, doors));
    }
    return Math.min(MaxDoors, Math.max(MinDoors, Math.round(item.width / DoorWidth)));
}

/**
 * Equal leaves across the opening between the carcass sides (centred on x = 0), alternating between the
 * front and the back track, each overlapping its neighbour by `LeafOverlap`.
 */
export function slidingLeaves(openingWidth: number, doors: number): SlidingLeaf[] {
    const width = (openingWidth + (doors - 1) * LeafOverlap) / doors;
    return Array.from({length: doors}, (_, i) => ({
        x: -openingWidth / 2 + i * (width - LeafOverlap),
        width,
        track: i % 2 === 0 ? 'front' : 'back',
    }));
}

/**
 * Built-in wardrobe: carcass sides and top in the item material, a closed interior behind the door tracks,
 * a plinth, the top track and bottom rail, and framed sliding leaves (infill in the item material or mirror).
 */
function buildWardrobe(item: Item, materials: MaterialLibrary): THREE.Group {
    const params = item.params as WardrobeParams;
    const group = new THREE.Group();
    const body = materials.get(item.materialId);
    const profile =
        typeof params.profileMaterialId === 'number' ? materials.get(params.profileMaterialId) : materials.byCode('aluminium-brushed');
    const mirror = materials.byCode('mirror');
    const mm = (v: number): number => v * MM;
    const add = (material: THREE.Material, x: number, y: number, z: number, w: number, h: number, d: number): void => {
        group.add(boxAt(material, mm(x), mm(y), mm(z), mm(w), mm(h), mm(d)));
    };

    const w = item.width;
    const h = item.height;
    const d = item.depth;
    const halfW = w / 2;
    const backZ = -d / 2;
    const frontZ = d / 2;
    const plinth = typeof params.plinth === 'number' ? params.plinth : DefaultPlinth;
    const opening = w - 2 * PanelThickness;
    const top = h - PanelThickness;
    const tracksZ = frontZ - DoorZone;

    add(body, -halfW, 0, backZ, PanelThickness, h, d);
    add(body, halfW - PanelThickness, 0, backZ, PanelThickness, h, d);
    add(body, -opening / 2, top, backZ, opening, PanelThickness, d);
    add(body, -opening / 2, plinth, backZ, opening, top - plinth, tracksZ - backZ);
    if (plinth > 0) {
        add(body, -opening / 2, 0, backZ, opening, plinth, tracksZ - backZ + PanelThickness);
    }
    add(profile, -opening / 2, top - TopTrackHeight, tracksZ, opening, TopTrackHeight, DoorZone);
    add(profile, -opening / 2, plinth, tracksZ, opening, BottomRailHeight, DoorZone);

    const mirrorDoors = Array.isArray(params.mirrorDoors) ? params.mirrorDoors : [];
    const leafBottom = plinth + BottomRailHeight;
    const leafHeight = top - TopTrackHeight - leafBottom;
    slidingLeaves(opening, wardrobeDoors(item)).forEach((leaf, index) => {
        const z = leaf.track === 'front' ? frontZ - LeafThickness : frontZ - 2 * LeafThickness - TrackGap;
        const infill = mirrorDoors.includes(index + 1) ? mirror : body;
        const innerWidth = leaf.width - 2 * ProfileWidth;
        add(profile, leaf.x, leafBottom, z, ProfileWidth, leafHeight, LeafThickness);
        add(profile, leaf.x + leaf.width - ProfileWidth, leafBottom, z, ProfileWidth, leafHeight, LeafThickness);
        add(profile, leaf.x + ProfileWidth, leafBottom, z, innerWidth, ProfileWidth, LeafThickness);
        add(profile, leaf.x + ProfileWidth, leafBottom + leafHeight - ProfileWidth, z, innerWidth, ProfileWidth, LeafThickness);
        const infillZ = z + (LeafThickness - InfillThickness) / 2;
        add(infill, leaf.x + ProfileWidth, leafBottom + ProfileWidth, infillZ, innerWidth, leafHeight - 2 * ProfileWidth, InfillThickness);
    });

    return group;
}

const ChairSeatThickness = 25;
const DefaultSeatHeight = 460;
/** The seat runs this far past the front legs. */
const SeatOverhang = 10;
/** Legs taper on their inner faces from `LegTop` under the seat to `LegBottom` at the floor. */
const ChairLegTop = 36;
const ChairLegBottom = 26;
/** Back posts: across × front to back. */
const PostWidth = 36;
const PostDepth = 30;
/** The back posts lean backwards by this angle. */
const PostRake = THREE.MathUtils.degToRad(5);
const ChairRailThickness = 20;
const TopRailHeight = 70;
const LowerRailHeight = 55;
/** Centre of the lower back rail, as a fraction of the way from the seat to the top. */
const LowerRailAt = 0.6;
const ChairApronHeight = 60;
const ApronThickness = 20;

const TableTopThickness = 28;
/** Boards of the top, glued along the table's width. */
const TableBoardWidth = 180;
const TableLegTop = 55;
const TableLegBottom = 38;
/** Outer leg faces stand this far in from the edge of the top. */
const TableLegInset = 40;
const TableApronHeight = 90;
const TableApronThickness = 22;
/** Apron faces are set back from the outer leg faces. */
const ApronSetback = 10;

/** Back width (mm) and seat height (mm) of a chair: params or the defaults (back as wide as the front, seat at 460). */
export function chairShape(item: Pick<Item, 'width' | 'height' | 'params'>): {backWidth: number; seatHeight: number} {
    const params = item.params as ChairParams;
    const backWidth = typeof params.backWidth === 'number' && params.backWidth > 0 ? Math.min(params.backWidth, item.width) : item.width;
    const seatHeight =
        typeof params.seatHeight === 'number' && params.seatHeight > ChairSeatThickness && params.seatHeight < item.height
            ? params.seatHeight
            : DefaultSeatHeight < item.height
              ? DefaultSeatHeight
              : item.height / 2;
    return {backWidth, seatHeight};
}

/**
 * Solid wood chair: a seat of two boards narrowing towards the back, legs tapered on the inside, aprons under
 * the seat, and back legs that continue as posts leaning back, joined by a top and a lower rail. The item width
 * is the front of the seat, `params.backWidth` the back.
 */
function buildChair(item: Item, materials: MaterialLibrary): THREE.Group {
    const group = new THREE.Group();
    const wood = (grain: GrainDirection): THREE.Material => materials.get(item.materialId, grain);
    const {backWidth, seatHeight} = chairShape(item);
    const h = item.height;
    const frontZ = item.depth / 2;
    const backZ = -item.depth / 2;
    const underSeat = seatHeight - ChairSeatThickness;
    // Distance along the leaning posts at which a piece whose front face is `front` mm ahead of their back face
    // reaches the height `top`.
    const along = (top: number, front: number): number => (top - underSeat - front * Math.sin(PostRake)) / Math.cos(PostRake);
    const rise = along(h, PostDepth);
    // Back face of the posts where they meet the seat; their tops reach the back of the item box.
    const postZ = backZ + rise * Math.sin(PostRake);
    // Half the seat width at a given z: the sides run straight from the front corners to the back ones.
    const halfAt = (z: number): number => backWidth / 2 + ((item.width - backWidth) / 2) * ((z - backZ) / (frontZ - backZ));

    for (const side of [-1, 1]) {
        const edge = halfAt(frontZ);
        const back = halfAt(postZ);
        const board = [
            {x: side * 0.5, z: frontZ},
            {x: side * edge, z: frontZ},
            {x: side * back, z: postZ},
            {x: side * 0.5, z: postZ},
        ].map((p) => ({x: p.x * MM, z: p.z * MM}));
        group.add(prismAt(wood('vertical'), board, underSeat * MM, seatHeight * MM));
    }

    const frontLegZ = frontZ - SeatOverhang - ChairLegTop / 2;
    const frontLegX = halfAt(frontLegZ) - SeatOverhang - ChairLegTop / 2;
    const backLegZ = postZ + PostDepth / 2;
    const backLegX = halfAt(postZ) - PostWidth / 2;
    for (const side of [-1, 1]) {
        group.add(taperedLeg(wood('vertical'), side * frontLegX, frontLegZ, underSeat, ChairLegTop, ChairLegTop, ChairLegBottom, side, 1));
        group.add(taperedLeg(wood('vertical'), side * backLegX, backLegZ, underSeat, PostWidth, PostDepth, ChairLegBottom, side, -1));
    }

    // Aprons between the legs, under the seat.
    const apronY = underSeat - ChairApronHeight;
    const frontInner = frontLegX - ChairLegTop / 2;
    const apronFrontZ = frontLegZ + ChairLegTop / 2 - ApronSetback - ApronThickness;
    const add = (grain: GrainDirection, x: number, y: number, z: number, w: number, height: number, d: number): void => {
        group.add(boxAt(wood(grain), x * MM, y * MM, z * MM, w * MM, height * MM, d * MM));
    };
    add('horizontal', -frontInner, apronY, apronFrontZ, 2 * frontInner, ChairApronHeight, ApronThickness);
    const sideFrom = backLegZ + PostDepth / 2;
    const sideTo = frontLegZ - ChairLegTop / 2;
    for (const side of [-1, 1]) {
        const x = Math.min(frontLegX, backLegX) + ChairLegTop / 2 - ApronSetback - ApronThickness;
        add('horizontal', side > 0 ? x : -x - ApronThickness, apronY, sideFrom, ApronThickness, ChairApronHeight, sideTo - sideFrom);
    }

    // Posts and rails above the seat, built upright from the posts' back face and leant back around it.
    const back = new THREE.Group();
    back.position.set(0, underSeat * MM, postZ * MM);
    back.rotation.x = -PostRake;
    const postX = halfAt(postZ) - PostWidth;
    for (const x of [-halfAt(postZ), postX]) {
        back.add(boxAt(wood('vertical'), x * MM, 0, 0, PostWidth * MM, rise * MM, PostDepth * MM));
    }
    const railZ = (PostDepth - ChairRailThickness) / 2;
    const railY = (top: number, height: number): number => along(top, railZ + ChairRailThickness) - height;
    const lowerTop = seatHeight + LowerRailAt * (h - seatHeight) + LowerRailHeight / 2;
    for (const [top, height] of [
        [h, TopRailHeight],
        [lowerTop, LowerRailHeight],
    ]) {
        back.add(boxAt(wood('horizontal'), -postX * MM, railY(top, height) * MM, railZ * MM, 2 * postX * MM, height * MM, ChairRailThickness * MM));
    }
    group.add(back);

    return group;
}

/**
 * Solid wood dining table in the style of the chairs: a top of boards running along the width, legs tapered on
 * the inside and an apron between them.
 */
function buildTable(item: Item, materials: MaterialLibrary): THREE.Group {
    const group = new THREE.Group();
    const wood = (grain: GrainDirection): THREE.Material => materials.get(item.materialId, grain);
    const add = (grain: GrainDirection, x: number, y: number, z: number, w: number, height: number, d: number): void => {
        group.add(boxAt(wood(grain), x * MM, y * MM, z * MM, w * MM, height * MM, d * MM));
    };
    const w = item.width;
    const d = item.depth;
    const underTop = item.height - TableTopThickness;

    // Boards with a hairline joint between them.
    const boards = Math.max(1, Math.round(d / TableBoardWidth));
    const boardDepth = d / boards;
    for (let i = 0; i < boards; i++) {
        const joint = i > 0 ? 0.5 : 0;
        add('horizontal', -w / 2, underTop, -d / 2 + i * boardDepth + joint, w, TableTopThickness, boardDepth - joint - (i < boards - 1 ? 0.5 : 0));
    }

    const legX = w / 2 - TableLegInset - TableLegTop / 2;
    const legZ = d / 2 - TableLegInset - TableLegTop / 2;
    for (const sx of [-1, 1]) {
        for (const sz of [-1, 1]) {
            group.add(taperedLeg(wood('vertical'), sx * legX, sz * legZ, underTop, TableLegTop, TableLegTop, TableLegBottom, sx, sz));
        }
    }

    const apronY = underTop - TableApronHeight;
    const outer = (half: number): number => half + TableLegTop / 2 - ApronSetback;
    const innerX = legX - TableLegTop / 2;
    const innerZ = legZ - TableLegTop / 2;
    for (const sz of [-1, 1]) {
        const z = sz > 0 ? outer(legZ) - TableApronThickness : -outer(legZ);
        add('horizontal', -innerX, apronY, z, 2 * innerX, TableApronHeight, TableApronThickness);
    }
    for (const sx of [-1, 1]) {
        const x = sx > 0 ? outer(legX) - TableApronThickness : -outer(legX);
        add('horizontal', x, apronY, -innerZ, TableApronThickness, TableApronHeight, 2 * innerZ);
    }

    return group;
}

/**
 * Leg from the floor to `height` (mm), `width` × `depth` at the top and `bottom` square at the floor, centred on
 * (x, z) at the top. The taper is on the inner faces: the outer faces, towards (`sx`, `sz`), stay plumb.
 */
function taperedLeg(
    material: THREE.Material,
    x: number,
    z: number,
    height: number,
    width: number,
    depth: number,
    bottom: number,
    sx: number,
    sz: number,
): THREE.Mesh {
    const geometry = worldUvBox(width * MM, height * MM, depth * MM);
    const position = geometry.getAttribute('position') as THREE.BufferAttribute;
    for (let i = 0; i < position.count; i++) {
        if (position.getY(i) < 0) {
            // Pull the inner faces towards the outer ones.
            const px = position.getX(i);
            const pz = position.getZ(i);
            position.setX(i, Math.sign(px) === sx ? px : px + (sx * (width - bottom) * MM));
            position.setZ(i, Math.sign(pz) === sz ? pz : pz + (sz * (depth - bottom) * MM));
        }
    }
    position.needsUpdate = true;
    geometry.computeVertexNormals();
    const mesh = new THREE.Mesh(geometry, material);
    mesh.position.set(x * MM, (height / 2) * MM, z * MM);
    return mesh;
}
