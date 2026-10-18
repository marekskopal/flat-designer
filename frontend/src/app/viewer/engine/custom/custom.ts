import {MaterialSlot} from '@app/models/furniture';
import {Item, SceneDefinition} from '@app/models/scene';
import * as THREE from 'three';

import {taperedLeg} from '../furniture';
import {buildAppliance, buildHandle} from '../kitchen';
import {GrainDirection, MaterialLibrary} from '../materials';
import {boxAt, prismAt} from '../meshes';
import {MM} from '../plan-geometry';
import {evaluateDefinition, ResolvedPart} from './definition';

/** The scene's furniture definitions by id. */
export type DefinitionLibrary = ReadonlyMap<number, SceneDefinition>;

export function definitionLibrary(definitions: readonly SceneDefinition[] | undefined): DefinitionLibrary {
    return new Map((definitions ?? []).map((d) => [d.id, d]));
}

/** Definitions that are new, gone or edited (`updatedAt`) between two scenes: their items need rebuilding. */
export function changedDefinitionIds(previous: DefinitionLibrary, next: DefinitionLibrary): Set<number> {
    const changed = new Set<number>();
    for (const [id, definition] of next) {
        if (previous.get(id)?.updatedAt !== definition.updatedAt) {
            changed.add(id);
        }
    }
    for (const id of previous.keys()) {
        if (!next.has(id)) {
            changed.add(id);
        }
    }
    return changed;
}

const CylinderSegments = 24;
/** A sliding door's frame, as on the built-in wardrobe. */
const SlidingProfileWidth = 22;
const SlidingInfillThickness = 8;

/**
 * Body of a `Custom` item from its furniture definition, in the item-local frame of `buildItem()` (bottom centre origin, x
 * along the width, +z out of the front); the parts' frame (left-bottom-back corner origin) is shifted by half the width
 * and depth. When the definition is missing or does not evaluate at the item's size, a translucent red box of the item
 * size stands in, with the reason in `userData.definitionError`. Null for other kinds.
 */
export function buildCustomItemBody(item: Item, materials: MaterialLibrary, definitions: DefinitionLibrary): THREE.Group | null {
    if (item.kind !== 'Custom') {
        return null;
    }
    const definition = item.definitionId === null ? undefined : definitions.get(item.definitionId);
    if (definition === undefined) {
        return placeholder(item, 'The furniture definition is not in the scene.');
    }

    try {
        const parts = evaluateDefinition(definition.definition, item, item.params['values'] ?? {});
        // The evaluator reads `{}` as an empty list too (PHP decodes both alike).
        const slots = Array.isArray(definition.definition.materialSlots) ? definition.definition.materialSlots : [];
        const group = new THREE.Group();
        for (const part of parts) {
            const material = (grain: GrainDirection, slot = part.material): THREE.Material =>
                partMaterial({...part, material: slot}, item, slots, materials, grain);
            const mesh = tilted(buildPart(part, item, material, materials), part);
            mesh.name = `part:${part.name}`;
            mesh.userData['partName'] = part.name;
            mesh.position.x -= (item.width / 2) * MM;
            mesh.position.z -= (item.depth / 2) * MM;
            group.add(mesh);
        }
        return group;
    } catch (e) {
        return placeholder(item, e instanceof Error ? e.message : String(e));
    }
}

/**
 * The material of a part: the item's choice for its slot (`params.materials`), for the first slot the item material,
 * otherwise the slot's default code; parts without a slot, or of a slot without a default, take the item material.
 */
export function partMaterial(
    part: ResolvedPart,
    item: Item,
    slots: readonly MaterialSlot[],
    materials: MaterialLibrary,
    grain: GrainDirection,
): THREE.Material {
    const chosen = (item.params['materials'] as Record<string, unknown> | undefined)?.[part.material ?? ''];
    if (typeof chosen === 'number') {
        return materials.get(chosen, grain);
    }
    const index = slots.findIndex((s) => s.key === part.material);
    const code = index >= 0 ? slots[index].default : undefined;
    if (code !== undefined && (index > 0 || item.materialId === null)) {
        return materials.byCode(code, grain);
    }
    return materials.get(item.materialId, grain);
}

/** One part as a mesh (or a group, for a front with its handle) in the parts' frame, metres. */
function buildPart(
    part: ResolvedPart,
    item: Item,
    material: (grain: GrainDirection, slot?: string | null) => THREE.Material,
    materials: MaterialLibrary,
): THREE.Object3D {
    const grain: GrainDirection = part.props['grain'] === 'horizontal' ? 'horizontal' : 'vertical';
    switch (part.type) {
        case 'appliance': {
            const front = {left: part.x, bottom: part.y, width: part.w, height: part.h, z: part.z};
            return buildAppliance(part.props['kind'] === 'microwave' ? 'microwave' : 'oven', front, materials, part.d);
        }
        case 'slidingDoor': {
            const slot = part.props['profile'];
            const profile = typeof slot === 'string' ? material('vertical', slot) : materials.byCode('aluminium-brushed');
            const infill = part.props['mirror'] === true ? materials.byCode('mirror') : material('vertical');
            return buildSlidingDoor(part, profile, infill);
        }
        case 'board':
            return buildBoard(part, shaded(material(grain), part.props['shade'], materials));
        case 'front':
            return buildFront(part, item, material(grain), materials);
        case 'cylinder':
            return buildCylinder(part, material('vertical'));
        case 'taperedLeg':
            return buildTaperedLeg(part, item, material('vertical'));
        case 'glass':
            return box(part, glassMaterial());
        case 'led':
            return buildLed(part, material('vertical'));
        default:
            return box(part, material(grain));
    }
}

/** A board, narrowing towards the back to `backWidth` (on both sides, or only the `taper` side) when it has one. */
function buildBoard(part: ResolvedPart, material: THREE.Material): THREE.Mesh {
    const back = typeof part.props['backWidth'] === 'number' ? Math.min(part.props['backWidth'], part.w) : part.w;
    if (back === part.w) {
        return box(part, material);
    }
    const narrowing = part.w - back;
    const taper = part.props['taper'];
    const left = part.x + (taper === 'right' ? 0 : taper === 'left' ? narrowing : narrowing / 2);
    const footprint = [
        {x: part.x, z: part.z + part.d},
        {x: part.x + part.w, z: part.z + part.d},
        {x: left + back, z: part.z},
        {x: left, z: part.z},
    ].map((p) => ({x: p.x * MM, z: p.z * MM}));
    const mesh = prismAt(material, footprint, part.y * MM, (part.y + part.h) * MM);
    mesh.castShadow = true;
    mesh.receiveShadow = true;
    return mesh;
}

/** The material darkened by `shade` (0–1), as a plinth in the shadow under the fronts; unchanged without one. */
function shaded(material: THREE.Material, shade: unknown, materials: MaterialLibrary): THREE.Material {
    return typeof shade === 'number' && shade > 0 ? materials.darkened(material, 1 - Math.min(shade, 1)) : material;
}

/** A sliding door leaf: stiles and rails `profileWidth` wide in the profile material around a thin infill. */
function buildSlidingDoor(part: ResolvedPart, profile: THREE.Material, infill: THREE.Material): THREE.Group {
    const group = new THREE.Group();
    const wanted = typeof part.props['profileWidth'] === 'number' ? part.props['profileWidth'] : SlidingProfileWidth;
    // A frame cannot be wider than half the leaf.
    const pw = Math.min(wanted, Math.floor(part.w / 2), Math.floor(part.h / 2));
    const inner = Math.max(part.w - 2 * pw, 1);
    const add = (material: THREE.Material, x: number, y: number, z: number, w: number, h: number, d: number): void => {
        group.add(boxAt(material, x * MM, y * MM, z * MM, w * MM, h * MM, d * MM));
    };
    add(profile, part.x, part.y, part.z, pw, part.h, part.d);
    add(profile, part.x + part.w - pw, part.y, part.z, pw, part.h, part.d);
    add(profile, part.x + pw, part.y, part.z, inner, pw, part.d);
    add(profile, part.x + pw, part.y + part.h - pw, part.z, inner, pw, part.d);
    const thickness = Math.min(SlidingInfillThickness, part.d);
    add(infill, part.x + pw, part.y + pw, part.z + (part.d - thickness) / 2, inner, Math.max(part.h - 2 * pw, 1), thickness);
    return group;
}

/**
 * The part leant back by `tilt` degrees (top towards the back) around a line along x at `tiltY` / `tiltZ` (mm, the
 * item's parts frame; default the part's bottom-back edge).
 */
function tilted(object: THREE.Object3D, part: ResolvedPart): THREE.Object3D {
    const tilt = part.props['tilt'];
    if (typeof tilt !== 'number' || tilt === 0) {
        return object;
    }
    const y = typeof part.props['tiltY'] === 'number' ? part.props['tiltY'] : part.y;
    const z = typeof part.props['tiltZ'] === 'number' ? part.props['tiltZ'] : part.z;
    const pivot = new THREE.Group();
    pivot.position.set(0, y * MM, z * MM);
    pivot.rotation.x = -THREE.MathUtils.degToRad(tilt);
    object.position.y -= y * MM;
    object.position.z -= z * MM;
    pivot.add(object);
    return pivot;
}

/** Clear glass; one per mesh, disposed with it (not one of the library's materials). */
function glassMaterial(): THREE.Material {
    return new THREE.MeshPhysicalMaterial({color: '#dfeff2', roughness: 0.05, transmission: 0.9, transparent: true, opacity: 0.35});
}

function box(part: ResolvedPart, material: THREE.Material): THREE.Mesh {
    return boxAt(material, part.x * MM, part.y * MM, part.z * MM, part.w * MM, part.h * MM, part.d * MM);
}

/** Door or drawer leaf (the part's box) with a bar handle: vertical on doors, horizontal on drawers by default. */
function buildFront(part: ResolvedPart, item: Item, material: THREE.Material, materials: MaterialLibrary): THREE.Group {
    const group = new THREE.Group();
    group.add(box(part, material));
    const drawer = part.props['kind'] === 'drawer';
    const handle = part.props['handle'] ?? (drawer ? 'horizontal' : 'vertical');
    if (handle === 'horizontal' || handle === 'vertical') {
        const edge = part.props['handleSide'] === 'left' ? 'left' : 'right';
        const front = {left: part.x, width: part.w, bottom: part.y, height: part.h};
        group.add(buildHandle(handle, front, part.z + part.d, item.z, edge, materials));
    }
    return group;
}

/** Round rod or leg along `axis` (default y), as thick as the box's smaller side across it. */
function buildCylinder(part: ResolvedPart, material: THREE.Material): THREE.Mesh {
    const axis = part.props['axis'] === 'x' || part.props['axis'] === 'z' ? part.props['axis'] : 'y';
    const across = {x: [part.h, part.d], y: [part.w, part.d], z: [part.w, part.h]}[axis];
    const length = {x: part.w, y: part.h, z: part.d}[axis];
    const radius = Math.min(across[0], across[1]) / 2;
    const mesh = new THREE.Mesh(new THREE.CylinderGeometry(radius * MM, radius * MM, length * MM, CylinderSegments), material);
    if (axis === 'x') {
        mesh.rotation.z = Math.PI / 2;
    } else if (axis === 'z') {
        mesh.rotation.x = Math.PI / 2;
    }
    mesh.position.set((part.x + part.w / 2) * MM, (part.y + part.h / 2) * MM, (part.z + part.d / 2) * MM);
    mesh.castShadow = true;
    mesh.receiveShadow = true;
    return mesh;
}

/**
 * Leg narrowing to `bottomSize` (default 60 % of its smaller side) at its foot; the faces towards the nearest corner of
 * the item stay plumb, as on the dining table.
 */
function buildTaperedLeg(part: ResolvedPart, item: Item, material: THREE.Material): THREE.Mesh {
    const bottomSize = typeof part.props['bottomSize'] === 'number' ? part.props['bottomSize'] : Math.round(Math.min(part.w, part.d) * 0.6);
    const cx = part.x + part.w / 2;
    const cz = part.z + part.d / 2;
    const sx = cx < item.width / 2 ? -1 : 1;
    const sz = cz < item.depth / 2 ? -1 : 1;
    const mesh = taperedLeg(material, cx, cz, part.h, part.w, part.d, Math.min(bottomSize, part.w, part.d), sx, sz);
    mesh.position.y += part.y * MM;
    return mesh;
}

/** Glowing strip in the slot material with a light of `color` shining down. */
function buildLed(part: ResolvedPart, material: THREE.Material): THREE.Group {
    const group = new THREE.Group();
    group.add(boxAt(material, part.x * MM, part.y * MM, part.z * MM, part.w * MM, part.h * MM, part.d * MM, false));
    const color = typeof part.props['color'] === 'string' ? part.props['color'] : '#ffd2a0';
    const intensity = typeof part.props['intensity'] === 'number' ? part.props['intensity'] : 5;
    const light = new THREE.RectAreaLight(color, intensity, part.w * MM, Math.max(part.d * MM, 0.02));
    light.rotation.x = -Math.PI / 2;
    light.position.set((part.x + part.w / 2) * MM, part.y * MM, (part.z + part.d / 2) * MM);
    group.add(light);
    return group;
}

function placeholder(item: Item, reason: string): THREE.Group {
    const group = new THREE.Group();
    const material = new THREE.MeshStandardMaterial({color: '#d04040', transparent: true, opacity: 0.45, roughness: 0.8});
    const w = item.width * MM;
    const d = item.depth * MM;
    group.add(boxAt(material, -w / 2, 0, -d / 2, w, item.height * MM, d, false));
    group.userData['definitionError'] = reason;
    return group;
}
