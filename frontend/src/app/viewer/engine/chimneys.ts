import {Item, PlanPoint, Scene} from '@app/models/scene';
import * as THREE from 'three';

import {MaterialLibrary} from './materials';
import {boxAt} from './meshes';
import {itemFootprint, MM} from './plan-geometry';
import {roofTopAt} from './roof-geometry';
import {FloorRoofs} from './under-roof';

/**
 * Chimneys (FD-91): a structural item standing on its floor and rising through the slabs and roofs of the floors above.
 * Its top is `params.aboveRoof` over the highest roof covering it passes (the roofs of its floor and the higher ones,
 * the scene's `roofs` and `chimneyFloors`), measured at its outline's corners and centre; without a roof over it, its
 * own height. Each floor draws the stretch passing through it: its own chimneys from their foot, those of the floors at
 * or below its level (`chimneyFloors`, a split level's too) from its level, each up to the next floor's level
 * (`voidsAbove.rise`) or the top, where the cap sits. So the floors of the stack meet, and a floor shown alone keeps its
 * storey's stretch.
 */

/** mm over the highest roof a chimney passes, without `params.aboveRoof`. */
export const DefaultAboveRoof = 600;

/** A cap's plate overhangs the chimney this far all round, mm, on legs `CapRise` high. */
const CapOverhang = 60;
const CapRise = 200;
const CapPlate = 25;
const CapLeg = 30;

/** White render of a chimney without a material. */
const RenderColor = '#eeebe4';
const CapColor = '#4d5154';

const RoundSegments = 32;

/** The stretch of a chimney a floor draws, mm against that floor's level; `cap` when its top is in it. */
export interface ChimneyStretch {
    bottom: number;
    top: number;
    cap: boolean;
}

/** A chimney a floor draws: its own or one of another floor at or below its level, with the stretch passing through it. */
export interface SceneChimney {
    item: Item;
    /** mm from this floor's level to the chimney's own (negative below, 0 for its own and a floor at the same level). */
    offset: number;
    /** The floor's own chimney (an item of its scene). */
    own: boolean;
    stretch: ChimneyStretch;
}

export const isChimney = (item: Pick<Item, 'kind'>): boolean => item.kind === 'Chimney';

/** The chimney's outline in plan (`itemFootprint()`): its rectangle, or a polygon round its ellipse. */
export function chimneyOutline(item: Pick<Item, 'kind' | 'x' | 'y' | 'width' | 'depth' | 'rotation' | 'params'>): PlanPoint[] {
    return itemFootprint(item).map((p): PlanPoint => [p.x, p.y]);
}

/** The roofs a floor's scene knows, with their floor's level against its own. */
function sceneRoofs(scene: Pick<Scene, 'roofs' | 'chimneyFloors'>): {offset: number; roofs: FloorRoofs}[] {
    return [{offset: 0, roofs: scene.roofs ?? []}, ...(scene.chimneyFloors ?? []).map((f) => ({offset: f.offset, roofs: f.roofs}))];
}

/**
 * The top of a chimney whose floor lies `offset` mm from this one's level, mm against this floor's level: over the
 * highest roof covering its outline on its floor or higher, else its own height.
 */
export function chimneyTop(item: Item, offset: number, scene: Pick<Scene, 'roofs' | 'chimneyFloors'>): number {
    const points: PlanPoint[] = [...chimneyOutline(item), [item.x, item.y]];
    let highest: number | null = null;
    for (const floor of sceneRoofs(scene)) {
        if (floor.offset < offset) {
            continue;
        }
        for (const roof of floor.roofs) {
            for (const [x, y] of points) {
                const top = roofTopAt(roof, x, y);
                if (top !== null && (highest === null || top + floor.offset > highest)) {
                    highest = top + floor.offset;
                }
            }
        }
    }
    const above = typeof item.params['aboveRoof'] === 'number' ? item.params['aboveRoof'] : DefaultAboveRoof;
    return highest === null ? offset + item.z + item.height : highest + above;
}

/**
 * The stretch of a chimney this floor draws, `own` from its foot, another floor's from this floor's level; null when none
 * of it passes through the floor.
 */
export function chimneyStretch(
    item: Item,
    offset: number,
    scene: Pick<Scene, 'roofs' | 'chimneyFloors' | 'voidsAbove'>,
    own = offset === 0,
): ChimneyStretch | null {
    const top = chimneyTop(item, offset, scene);
    const bottom = own ? item.z : Math.max(offset + item.z, 0);
    const next = scene.voidsAbove?.rise ?? Infinity;
    const drawn = Math.min(top, next);
    return drawn > bottom ? {bottom, top: drawn, cap: top <= next} : null;
}

/** The chimneys a floor draws: its own and those of the floors at or below its level (a split level's) rising through it. */
export function sceneChimneys(scene: Pick<Scene, 'items' | 'roofs' | 'chimneyFloors' | 'voidsAbove'>): SceneChimney[] {
    const chimneys: SceneChimney[] = [];
    const add = (item: Item, offset: number, own: boolean): void => {
        const stretch = chimneyStretch(item, offset, scene, own);
        if (stretch !== null) {
            chimneys.push({item, offset, own, stretch});
        }
    };
    scene.items.filter(isChimney).forEach((item) => add(item, 0, true));
    for (const floor of scene.chimneyFloors ?? []) {
        if (floor.offset <= 0) {
            floor.chimneys.forEach((item) => add(item, floor.offset, false));
        }
    }
    return chimneys;
}

/** The floor's own chimneys' stretches by item id, for the item builder (null: not drawn). */
export function chimneyStretches(scene: Pick<Scene, 'items' | 'roofs' | 'chimneyFloors' | 'voidsAbove'>): Map<number, ChimneyStretch | null> {
    return new Map(scene.items.filter(isChimney).map((item) => [item.id, chimneyStretch(item, 0, scene)]));
}

/**
 * A chimney's body in the item-local frame (bottom centre origin at the stretch's bottom): a box or an upright cylinder
 * `top − bottom` high in the item material (white render without one), with a section-fill twin, and where its top is
 * on this floor a metal cap: a plate over it on four legs.
 */
export function buildChimneyBody(item: Item, stretch: ChimneyStretch, materials: MaterialLibrary): THREE.Group {
    const group = new THREE.Group();
    const material = item.materialId === null ? materials.plain(RenderColor, 0.9) : materials.get(item.materialId);
    const height = stretch.top - stretch.bottom;
    const round = item.params['shape'] === 'round';
    const body = round
        ? new THREE.Mesh(new THREE.CylinderGeometry(0.5, 0.5, height * MM, RoundSegments), material)
        : boxAt(material, (-item.width / 2) * MM, 0, (-item.depth / 2) * MM, item.width * MM, height * MM, item.depth * MM);
    if (round) {
        body.scale.set(item.width * MM, 1, item.depth * MM);
        body.position.y = (height / 2) * MM;
    }
    body.castShadow = true;
    body.receiveShadow = true;
    const fill = new THREE.Mesh(body.geometry, materials.sectionFill());
    fill.position.copy(body.position);
    fill.scale.copy(body.scale);
    fill.userData = {castsNoShadow: true};
    group.add(body, fill);

    if (stretch.cap && item.params['cap'] !== false) {
        const metal = materials.plain(CapColor, 0.45, 0.6);
        const w = item.width + 2 * CapOverhang;
        const d = item.depth + 2 * CapOverhang;
        group.add(boxAt(metal, (-w / 2) * MM, (height + CapRise) * MM, (-d / 2) * MM, w * MM, CapPlate * MM, d * MM));
        // The legs stand on the body: at a rectangle's corners, on a round one's ellipse along the diagonals.
        const reach = round ? Math.SQRT1_2 : 1;
        for (const [sx, sz] of [
            [-1, -1],
            [1, -1],
            [1, 1],
            [-1, 1],
        ]) {
            const x = sx * (item.width / 2 - CapLeg) * reach - CapLeg / 2;
            const z = sz * (item.depth / 2 - CapLeg) * reach - CapLeg / 2;
            group.add(boxAt(metal, x * MM, height * MM, z * MM, CapLeg * MM, CapRise * MM, CapLeg * MM));
        }
    }
    return group;
}

/**
 * The chimneys of the floors below rising through this floor, drawn in place (not pickable, untagged): each from this
 * floor's level up to the next one's, or its top.
 */
export function buildChimneysBelow(scene: Pick<Scene, 'items' | 'roofs' | 'chimneyFloors' | 'voidsAbove'>, materials: MaterialLibrary): THREE.Group {
    const group = new THREE.Group();
    group.name = 'chimneys-below';
    for (const {item, own, stretch} of sceneChimneys(scene)) {
        if (own) {
            continue;
        }
        const body = buildChimneyBody(item, stretch, materials);
        body.position.set(item.x * MM, stretch.bottom * MM, -item.y * MM);
        body.rotation.y = THREE.MathUtils.degToRad(item.rotation);
        group.add(body);
    }
    return group;
}
