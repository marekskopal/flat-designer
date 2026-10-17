import {Item, WardrobeParams} from '@app/models/scene';
import * as THREE from 'three';

import {MaterialLibrary} from './materials';
import {boxAt} from './meshes';
import {MM} from './plan-geometry';

/**
 * Furniture in the item-local frame: origin at the bottom centre of the item's box, x along the width,
 * y up and +z out of the front. Returns null for kinds handled elsewhere.
 */
export function buildFurnitureItemBody(item: Item, materials: MaterialLibrary): THREE.Group | null {
    return item.kind === 'Wardrobe' ? buildWardrobe(item, materials) : null;
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
