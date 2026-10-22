import {MaterialSlot} from '@app/models/furniture';
import {Item, Room, SceneDefinition} from '@app/models/scene';
import * as THREE from 'three';
import {RoundedBoxGeometry} from 'three/examples/jsm/geometries/RoundedBoxGeometry.js';

import {ceilingHeightAt} from '../ceilings';
import {buildAppliance, buildHandle} from '../kitchen';
import {bulbMaterial, createFixtureLight, FixtureKind, markBulb, markFixtureLight} from '../lights';
import {GrainDirection, MaterialLibrary} from '../materials';
import {boxAt, prismAt} from '../meshes';
import {coverUvs, PictureLibrary} from '../pictures';
import {MM} from '../plan-geometry';
import {evaluateDefinition, ResolvedPart} from './definition';
import {taperedLeg} from './legs';

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
/** A cable on the ceiling is sampled this often (mm), so that it follows a vault and sags smoothly. */
const CableStep = 50;
/** Height of a ceiling rose, mm. */
const RoseHeight = 22;
/** A picture's face stands this far (mm) in front of its box, clear of z-fighting. */
const PictureFaceOffset = 0.5;

/**
 * Body of a `Custom` item from its furniture definition, in the item-local frame of `buildItem()` (bottom centre origin, x
 * along the width, +z out of the front); the parts' frame (left-bottom-back corner origin) is shifted by half the width
 * and depth. When the definition is missing or does not evaluate at the item's size, a translucent red box of the item
 * size stands in, with the reason in `userData.definitionError`. `picture` parts show their image from `pictures` (plain
 * while it loads, or without a library); `cable` parts run on the ceiling of the item's `room`. Null for other kinds.
 */
export function buildCustomItemBody(
    item: Item,
    materials: MaterialLibrary,
    definitions: DefinitionLibrary,
    pictures: PictureLibrary | null = null,
    room?: Room,
): THREE.Group | null {
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
            const mesh = tilted(buildPart(part, item, material, materials, pictures, room), part);
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
    pictures: PictureLibrary | null,
    room: Room | undefined,
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
        case 'cushion':
            return buildCushion(part, material('vertical'));
        case 'taperedLeg':
            return buildTaperedLeg(part, item, material('vertical'));
        case 'glass':
            return box(part, glassMaterial());
        case 'led':
            return buildLed(part, material('vertical'));
        case 'picture':
            return buildPicture(part, material(grain), pictures);
        case 'light':
            return buildLight(part);
        case 'cable':
            return buildCable(part, item, material('vertical'), room);
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

/**
 * A light source at the centre of its box (see `lights.ts`), with what glows while it is on: a bare bulb filling the
 * box, a downlight's lens on its bottom face, or nothing.
 */
function buildLight(part: ResolvedPart): THREE.Group {
    const number = (key: string, fallback: number): number => (typeof part.props[key] === 'number' ? part.props[key] : fallback);
    const kelvin = number('kelvin', 2700);
    const kind: FixtureKind = part.props['kind'] === 'spot' ? 'spot' : 'point';
    const group = new THREE.Group();
    const centre = new THREE.Vector3((part.x + part.w / 2) * MM, (part.y + part.h / 2) * MM, (part.z + part.d / 2) * MM);
    const shadow = part.props['shadow'] === true;
    const light = createFixtureLight({kind, lumens: number('lumens', 800), kelvin, angle: number('angle', 60), shadow});
    light.position.copy(centre);
    group.add(light);
    const bulb = part.props['bulb'];
    if (bulb === 'sphere') {
        const mesh = new THREE.Mesh(new THREE.SphereGeometry(0.5, 24, 16), bulbMaterial(kelvin));
        mesh.scale.set(part.w * MM, part.h * MM, part.d * MM);
        mesh.position.copy(centre);
        markBulb(mesh);
        group.add(mesh);
        // The bulb glows by itself and would block its own light: it casts no shadow.
        mesh.userData['castsNoShadow'] = true;
    } else if (bulb === 'disc') {
        const mesh = new THREE.Mesh(new THREE.CircleGeometry(Math.min(part.w, part.d) * 0.5 * MM, 32), bulbMaterial(kelvin));
        mesh.rotation.x = Math.PI / 2;
        mesh.position.set(centre.x, part.y * MM - 0.0002, centre.z);
        markBulb(mesh);
        mesh.userData['castsNoShadow'] = true;
        group.add(mesh);
    }
    return group;
}

/**
 * A pendant's cable: up from the bottom centre of its box to the hook at its top centre, then along `path` to the
 * ceiling rose, hanging between the points with a sag of `sag` mm. With `ceiling` (default) and a room, the hook, the
 * points and the rose are fixed to the room's real ceiling, also a vault, and the cable drapes over what hangs lower
 * between them (a beam).
 */
function buildCable(part: ResolvedPart, item: Item, material: THREE.Material, room: Room | undefined): THREE.Group {
    const group = new THREE.Group();
    const radius = Math.max(Math.min(part.w, part.d) / 2, 1);
    const sag = typeof part.props['sag'] === 'number' ? Math.max(part.props['sag'], 0) : 0;
    const rose = typeof part.props['rose'] === 'number' ? Math.max(part.props['rose'], 0) : 0;
    const onCeiling = part.props['ceiling'] !== false && room !== undefined;
    const hook = {x: part.x + part.w / 2, z: part.z + part.d / 2};
    const path = Array.isArray(part.props['path']) ? (part.props['path'] as {x?: unknown; z?: unknown}[]) : [];
    const given = path.filter((p) => typeof p.x === 'number' && typeof p.z === 'number');
    const points = [hook, ...given.map((p) => ({x: p.x as number, z: p.z as number}))];
    // The ceiling's height over a point of the parts' frame, in the parts' frame (mm above the item's bottom).
    const top = part.y + part.h;
    const ceiling = (p: {x: number; z: number}): number => {
        if (!onCeiling) {
            return top;
        }
        const plan = partsPointToPlan(item, p.x, p.z);
        const height = ceilingHeightAt(room, plan.x, plan.y);
        return height === null ? top : height - item.z;
    };

    const vertices = [new THREE.Vector3(hook.x, part.y, hook.z), new THREE.Vector3(hook.x, ceiling(hook) - radius, hook.z)];
    for (let i = 1; i < points.length; i++) {
        const a = points[i - 1];
        const b = points[i];
        // Hanging freely between its fixings (a sagging chord), but never above the ceiling: over a beam it drapes.
        const ya = ceiling(a) - radius;
        const yb = ceiling(b) - radius;
        const steps = Math.max(Math.ceil(Math.hypot(b.x - a.x, b.z - a.z) / CableStep), 1);
        for (let s = 1; s <= steps; s++) {
            const t = s / steps;
            const p = {x: a.x + (b.x - a.x) * t, z: a.z + (b.z - a.z) * t};
            const hanging = ya + (yb - ya) * t - sag * 4 * t * (1 - t);
            vertices.push(new THREE.Vector3(p.x, Math.min(hanging, ceiling(p) - radius), p.z));
        }
    }
    const curve = new THREE.CurvePath<THREE.Vector3>();
    for (let i = 1; i < vertices.length; i++) {
        curve.add(new THREE.LineCurve3(vertices[i - 1].clone().multiplyScalar(MM), vertices[i].clone().multiplyScalar(MM)));
    }
    group.add(new THREE.Mesh(new THREE.TubeGeometry(curve, Math.max(vertices.length * 2, 8), radius * MM, 8, false), material));

    if (rose > 0) {
        const end = points[points.length - 1];
        const height = ceiling(end);
        const mesh = new THREE.Mesh(new THREE.CylinderGeometry((rose / 2) * MM, (rose / 2) * MM, RoseHeight * MM, CylinderSegments), material);
        mesh.position.set(end.x * MM, (height - RoseHeight / 2) * MM, end.z * MM);
        group.add(mesh);
    }
    return group;
}

/**
 * A point of the parts' frame (mm from the item's left-back corner, x along the width, z towards the front) on the plan:
 * the item frame turned counter-clockwise by its rotation, with the front facing south at 0.
 */
export function partsPointToPlan(item: Item, x: number, z: number): {x: number; y: number} {
    const lx = x - item.width / 2;
    const lz = z - item.depth / 2;
    const theta = THREE.MathUtils.degToRad(item.rotation);
    // Scene x = plan x, scene z = −plan y; the item turns about scene y.
    const sx = lx * Math.cos(theta) + lz * Math.sin(theta);
    const sz = -lx * Math.sin(theta) + lz * Math.cos(theta);
    return {x: item.x + sx, y: item.y - sz};
}

/**
 * A picture: its box in the part's material with the image of `props.image` (a file id) on the front face, cropped to
 * fill it. The face is tagged `userData.pictureId`, so the item is rebuilt once the image has loaded.
 */
function buildPicture(part: ResolvedPart, material: THREE.Material, pictures: PictureLibrary | null): THREE.Group {
    const group = new THREE.Group();
    group.add(box(part, material));
    const fileId = typeof part.props['image'] === 'number' ? part.props['image'] : null;
    const texture = fileId === null || pictures === null ? null : pictures.texture(fileId);
    const image = texture?.image as {width?: number; height?: number} | undefined;
    const geometry = new THREE.PlaneGeometry(part.w * MM, part.h * MM);
    const [u0, v0, u1, v1] = coverUvs((image?.width ?? 1) / (image?.height ?? 1), part.w / part.h);
    geometry.setAttribute('uv', new THREE.Float32BufferAttribute([u0, v1, u1, v1, u0, v0, u1, v0], 2));
    // Without an image (none chosen, loading or unreadable) the face is the part's material, like the box.
    const face = new THREE.Mesh(geometry, texture === null ? material : new THREE.MeshStandardMaterial({map: texture, roughness: 0.85}));
    face.position.set((part.x + part.w / 2) * MM, (part.y + part.h / 2) * MM, (part.z + part.d + PictureFaceOffset) * MM);
    face.userData['pictureId'] = fileId;
    group.add(face);
    return group;
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
    return centredInBox(mesh, part);
}

/** A mesh built around its own origin, placed at the centre of the part's box, casting and taking shadows. */
function centredInBox(mesh: THREE.Mesh, part: ResolvedPart): THREE.Mesh {
    mesh.position.set((part.x + part.w / 2) * MM, (part.y + part.h / 2) * MM, (part.z + part.d / 2) * MM);
    mesh.castShadow = true;
    mesh.receiveShadow = true;
    return mesh;
}

/** Default rounding of a cushion's edges, mm. */
const CushionRadius = 40;

/** Upholstery: the part's box with its edges rounded by `radius` (0 square; at most half its smallest side, so the box stays its size). */
function buildCushion(part: ResolvedPart, material: THREE.Material): THREE.Mesh {
    const wanted = typeof part.props['radius'] === 'number' ? part.props['radius'] : CushionRadius;
    const radius = Math.max(0, Math.min(wanted, part.w / 2, part.h / 2, part.d / 2) - 0.5);
    const geometry = new RoundedBoxGeometry(part.w * MM, part.h * MM, part.d * MM, 4, radius * MM);
    return centredInBox(new THREE.Mesh(geometry, material), part);
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
    markFixtureLight(light, intensity);
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
