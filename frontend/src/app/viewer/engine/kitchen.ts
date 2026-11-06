import {Item} from '@app/models/scene';
import * as THREE from 'three';

import {markFixtureLight} from './lights';
import {GrainDirection, MaterialLibrary} from './materials';
import {boxAt} from './meshes';
import {MM} from './plan-geometry';

const FrontThickness = 19;
const HandleSection = 12;
const HandleStandoff = 25;

/** Height above the floor (mm) that front handles gravitate to: below it they sit at the top edge, above it at the bottom. */
const HandleReachHeight = 1100;

/**
 * Kitchen items in the item-local frame: origin at the bottom centre of the item's box, x along the
 * width, y up and +z out of the front. Returns null for kinds handled elsewhere.
 */
export function buildKitchenItemBody(item: Item, materials: MaterialLibrary): THREE.Group | null {
    switch (item.kind) {
        case 'Sink':
            return buildSink(item, materials);
        case 'Hob':
            return buildHob(item, materials);
        case 'LedStrip':
            return buildLedStrip(item, materials);
        default:
            return null;
    }
}

export function buildSlab(item: Item, materials: MaterialLibrary, grain: GrainDirection): THREE.Group {
    const group = new THREE.Group();
    const w = item.width * MM;
    const d = item.depth * MM;
    group.add(boxAt(materials.get(item.materialId, grain), -w / 2, 0, -d / 2, w, item.height * MM, d));
    return group;
}

export interface FrontBox {
    left: number;
    width: number;
    bottom: number;
    height: number;
    /** Back face of the front (mm, cabinet-local). */
    z: number;
}

/**
 * Bar handle on a front (mm, item-local; `faceZ` is the front's outer face): horizontal ones near the top or bottom
 * edge depending on the height (`itemElevation` + front), vertical ones at `edge` around reach height; `position` `top`
 * / `bottom` puts either at that end whatever the height (`side` is `auto` for a bar).
 */
export function buildHandle(
    orientation: 'horizontal' | 'vertical',
    front: Omit<FrontBox, 'z'>,
    faceZ: number,
    itemElevation: number,
    edge: 'left' | 'right',
    materials: MaterialLibrary,
    handleMaterial?: THREE.Material,
    handleLength?: number,
    position: HandlePosition = 'auto',
): THREE.Mesh {
    const material = handleMaterial ?? defaultHandleMaterial(materials);
    const z = faceZ + HandleStandoff - HandleSection;

    if (orientation === 'horizontal') {
        const length =
            handleLength === undefined ? THREE.MathUtils.clamp(front.width * 0.45, 120, 480) : Math.min(handleLength, Math.max(40, front.width - 20));
        const nearTop = position === 'top' || (position !== 'bottom' && handleNearTop(front, itemElevation));
        const y = nearTop ? front.bottom + front.height - 45 : front.bottom + 45;
        const left = front.left + (front.width - length) / 2;
        return boxAt(material, left * MM, (y - HandleSection / 2) * MM, z * MM, length * MM, HandleSection * MM, HandleSection * MM);
    }

    const length =
        handleLength === undefined ? THREE.MathUtils.clamp(front.height * 0.35, 150, 500) : Math.min(handleLength, Math.max(40, front.height - 160));
    const x = edge === 'right' ? front.left + front.width - 45 : front.left + 45;
    const [low, high] = [front.bottom + 80 + length / 2, front.bottom + front.height - 80 - length / 2];
    // At reach height, or at the edge asked for (the pull at the split of two doors).
    const centre = position === 'top' ? high : position === 'bottom' ? low : THREE.MathUtils.clamp(HandleReachHeight - itemElevation, low, high);
    return boxAt(material, (x - HandleSection / 2) * MM, (centre - length / 2) * MM, z * MM, HandleSection * MM, length * MM, HandleSection * MM);
}

/** The handle kinds a front may have (`PartTypeEnum::Handles`); `push` and `none` draw nothing. */
export type FrontHandle = 'horizontal' | 'vertical' | 'knob' | 'edge' | 'gola' | 'push' | 'none';

/**
 * Where a front's handle goes (`PartTypeEnum::HandlePositions`): `auto` by the front's height (near the top within reach,
 * else the bottom; on a tall door from below reach height to well above it, at reach height on its handle edge), `top`
 * or `bottom` on that edge whatever the height (the pull at a split between two doors), `side` at reach height on the
 * handle edge.
 */
export type HandlePosition = 'auto' | 'top' | 'bottom' | 'side';

/** A front's handle position option, `auto` when missing or unknown. */
export function handlePositionOf(value: unknown): HandlePosition {
    return value === 'top' || value === 'bottom' || value === 'side' ? value : 'auto';
}

/** What `buildFrontHandle()` takes besides the kind and the front: the handle's material, its length, its position. */
export interface FrontHandleOptions {
    material?: THREE.Material;
    length?: number;
    position?: HandlePosition;
}

/** The black of a handle without a material of its own. */
export function defaultHandleMaterial(materials: MaterialLibrary): THREE.Material {
    return materials.plain('#161616', 0.35, 0.6);
}

/** Whether a front's top edge is within reach (`HandleReachHeight` + 400): its handle goes near the top, else the bottom. */
function handleNearTop(front: Omit<FrontBox, 'z'>, itemElevation: number): boolean {
    return itemElevation + front.bottom + front.height <= HandleReachHeight + 400;
}

/**
 * The handle of a front (mm, item-local; `faceZ` its outer face), or null for push-to-open and none: bars as
 * `buildHandle()`, a knob (`KnobDiameter`) at a door's handle edge or a drawer's middle, an edge pull (`EdgePull`) on an
 * edge, a gola channel (`GolaHeight`) along an edge, in the handle material or, without one, a dark shadow band. The
 * position (`HandlePosition`) says which edge: by default near the top within reach, else the bottom, and on a tall door
 * reaching from below reach height to well above it at reach height on its handle edge (the knob and the edge pull) or
 * up that edge (the gola channel). An edge pull placed on the top or bottom on purpose sits at a door's handle side.
 */
export function buildFrontHandle(
    kind: FrontHandle,
    front: Omit<FrontBox, 'z'>,
    faceZ: number,
    itemElevation: number,
    edge: 'left' | 'right',
    drawer: boolean,
    materials: MaterialLibrary,
    options: FrontHandleOptions = {},
): THREE.Object3D | null {
    const material = options.material ?? defaultHandleMaterial(materials);
    const handleLength = options.length;
    const position = options.position ?? 'auto';
    const top = front.bottom + front.height;
    const place = handlePlace(front, itemElevation, drawer, position);
    const atReach = (length: number): number =>
        THREE.MathUtils.clamp(HandleReachHeight - itemElevation, front.bottom + length / 2 + 20, top - length / 2 - 20);
    switch (kind) {
        case 'horizontal':
        case 'vertical':
            return buildHandle(kind, front, faceZ, itemElevation, edge, materials, material, handleLength, position);
        case 'knob': {
            const middle = drawer && place !== 'side';
            const x = middle ? front.left + front.width / 2 : edge === 'right' ? front.left + front.width - 45 : front.left + 45;
            const centred = drawer && front.height < 300 && position === 'auto';
            const y = centred ? front.bottom + front.height / 2 : place === 'side' ? atReach(0) : place === 'top' ? top - 60 : front.bottom + 60;
            const geometry = new THREE.CylinderGeometry((KnobDiameter / 2) * MM, (KnobDiameter / 2 - 4) * MM, KnobDepth * MM, 24);
            const knob = new THREE.Mesh(geometry, material);
            knob.rotation.x = Math.PI / 2;
            knob.position.set(x * MM, y * MM, (faceZ + KnobDepth / 2) * MM);
            knob.castShadow = true;
            return knob;
        }
        case 'edge': {
            if (place === 'side') {
                // A profile over the handle edge, at reach height.
                const length = handleLength ?? THREE.MathUtils.clamp(front.height * 0.3, 150, 600);
                const x = edge === 'right' ? front.left + front.width - EdgePull.face : front.left - EdgePull.lip;
                const [across, depth] = [EdgePull.face + EdgePull.lip, EdgePull.depth + EdgePull.proud];
                const y = atReach(length) - length / 2;
                return boxAt(material, x * MM, y * MM, (faceZ - EdgePull.depth) * MM, across * MM, length * MM, depth * MM);
            }
            const length = Math.min(handleLength ?? THREE.MathUtils.clamp(front.width * 0.5, 150, 600), front.width - 20);
            // Centred, or at a door's handle side when the edge was chosen.
            const toSide = position !== 'auto' && !drawer;
            const left = !toSide
                ? front.left + (front.width - length) / 2
                : edge === 'right'
                  ? front.left + front.width - length - EdgePullInset
                  : front.left + EdgePullInset;
            // A profile over the edge: on the face, a lip over the edge and a little beyond it.
            const y = place === 'top' ? top - EdgePull.face : front.bottom - EdgePull.lip;
            const [height, depth] = [EdgePull.face + EdgePull.lip, EdgePull.depth + EdgePull.proud];
            return boxAt(material, left * MM, y * MM, (faceZ - EdgePull.depth) * MM, length * MM, height * MM, depth * MM);
        }
        case 'gola': {
            const channel = options.material ?? materials.plain('#0b0b0c', 0.6, 0.2);
            if (place === 'side') {
                // A vertical channel along the handle edge, the full height of the door.
                const x = edge === 'right' ? front.left + front.width - GolaHeight : front.left;
                return boxAt(channel, x * MM, front.bottom * MM, faceZ * MM, GolaHeight * MM, front.height * MM, 0.6 * MM, false);
            }
            const y = place === 'top' ? top - GolaHeight : front.bottom;
            return boxAt(channel, front.left * MM, y * MM, faceZ * MM, front.width * MM, GolaHeight * MM, 0.6 * MM, false);
        }
        default:
            return null;
    }
}

/**
 * Which edge a knob, edge pull or gola channel goes to: the position given, else (`auto`) at reach height on the handle
 * edge for a tall door reaching from below reach height to well above it, near the top within reach, else the bottom.
 */
function handlePlace(front: Omit<FrontBox, 'z'>, itemElevation: number, drawer: boolean, position: HandlePosition): 'top' | 'bottom' | 'side' {
    if (position !== 'auto') {
        return position;
    }
    const nearTop = handleNearTop(front, itemElevation);
    if (!drawer && !nearTop && itemElevation + front.bottom < HandleReachHeight) {
        return 'side';
    }
    return nearTop ? 'top' : 'bottom';
}

/** How far (mm) an edge pull placed on purpose stays in from a door's handle edge. */
const EdgePullInset = 40;

/** A knob's diameter and how far it stands out (mm). */
const KnobDiameter = 32;
const KnobDepth = 26;

/** An edge pull's profile (mm): down the face, over the edge, into the front's thickness and out in front of it. */
const EdgePull = {face: 22, lip: 3, depth: 8, proud: 6};

/** The height (mm) of a gola channel's shadow band. */
const GolaHeight = 28;

/** A built-in appliance front's kind (`PartTypeEnum::Appliances`). */
export type ApplianceKind = 'oven' | 'microwave' | 'steam' | 'coffee';

/** An appliance front's finish (`PartTypeEnum::ApplianceFinishes`). */
export type ApplianceFinish = 'black' | 'white' | 'steel' | 'retro';

/** An appliance part's `kind` option, an oven when missing or unknown. */
export function applianceKindOf(value: unknown): ApplianceKind {
    return value === 'microwave' || value === 'steam' || value === 'coffee' ? value : 'oven';
}

/** An appliance part's `finish` option, black glass when missing or unknown. */
export function applianceFinishOf(value: unknown): ApplianceFinish {
    return value === 'white' || value === 'steel' || value === 'retro' ? value : 'black';
}

/** The face, window and trim (handle, knobs, spouts) materials of an appliance finish. */
function applianceLook(finish: ApplianceFinish, materials: MaterialLibrary): {face: THREE.Material; window: THREE.Material; trim: THREE.Material} {
    switch (finish) {
        case 'white':
            return {
                face: materials.plain('#f1f1ee', 0.12),
                window: materials.plain('#16171a', 0.05, 0.5),
                trim: materials.plain('#c9c9c9', 0.25, 1),
            };
        case 'steel':
            return {
                face: materials.plain('#b7b8b8', 0.35, 1),
                window: materials.plain('#09090a', 0.05, 0.5),
                trim: materials.plain('#d8d8d8', 0.2, 1),
            };
        case 'retro':
            return {
                face: materials.plain('#e8dec6', 0.4),
                window: materials.plain('#141414', 0.05, 0.5),
                trim: materials.plain('#e2e2e2', 0.12, 1),
            };
        default:
            return {
                face: materials.byCode('appliance-black-glass'),
                window: materials.plain('#050506', 0.05, 0.5),
                trim: materials.plain('#9a9a9a', 0.25, 1),
            };
    }
}

/**
 * Built-in appliance front (mm, item-local; the front `thickness` deep from its back face `front.z`) in its finish: an
 * oven (window, bar handle under a strip of control knobs), a steam oven (the same with a display between two knobs), a
 * microwave (a window beside the controls) or a coffee machine (a display strip over a recess with the spouts and a drip
 * tray).
 */
export function buildAppliance(
    type: ApplianceKind,
    front: FrontBox,
    materials: MaterialLibrary,
    thickness: number = FrontThickness,
    finish: ApplianceFinish = 'black',
): THREE.Group {
    const group = new THREE.Group();
    const look = applianceLook(finish, materials);
    const mm = (v: number): number => v * MM;
    const faceZ = front.z + thickness;
    const flat = (material: THREE.Material, x: number, y: number, w: number, h: number, d = 2, z = faceZ): void => {
        group.add(boxAt(material, mm(x), mm(y), mm(z), mm(w), mm(h), mm(d)));
    };
    const knob = (x: number, y: number, radius: number): void => {
        const mesh = new THREE.Mesh(new THREE.CylinderGeometry(mm(radius), mm(radius), mm(14), 24), look.trim);
        mesh.rotation.x = Math.PI / 2;
        mesh.position.set(mm(x), mm(y), mm(faceZ + 7));
        group.add(mesh);
    };

    group.add(boxAt(look.face, mm(front.left), mm(front.bottom), mm(front.z), mm(front.width), mm(front.height), mm(thickness)));
    const top = front.bottom + front.height;

    if (type === 'oven' || type === 'steam') {
        const controls = 90;
        const margin = 60;
        flat(look.window, front.left + margin, front.bottom + margin, front.width - 2 * margin, front.height - controls - 2 * margin);
        const handleY = top - controls - 30;
        flat(look.trim, front.left + 60, handleY, front.width - 120, 14, 14, faceZ + 20);
        // Control knobs in the top strip (larger on a retro front); a steam oven has a display between two of them.
        const radius = finish === 'retro' ? 20 : 16;
        const knobs = type === 'steam' ? [0.2, 0.8] : [0.18, 0.3, 0.7, 0.82];
        for (const x of knobs) {
            knob(front.left + front.width * x, top - controls / 2, radius);
        }
        if (type === 'steam') {
            flat(look.window, front.left + front.width / 2 - 70, top - controls / 2 - 15, 140, 30);
        }
    } else if (type === 'coffee') {
        // A display strip over a recess with the spouts and a drip tray.
        const margin = 40;
        const recessW = Math.min(front.width * 0.45, 260);
        const recessH = Math.min(front.height * 0.55, 260);
        const recessLeft = front.left + (front.width - recessW) / 2;
        flat(look.window, front.left + front.width / 2 - 80, top - 70, 160, 34);
        flat(look.window, recessLeft, front.bottom + margin, recessW, recessH, 2, faceZ - 1);
        flat(look.trim, recessLeft + recessW / 2 - 40, front.bottom + margin + recessH - 40, 80, 30, 40, faceZ - 20);
        flat(look.trim, recessLeft, front.bottom + margin, recessW, 12, 60, faceZ - 30);
        knob(front.left + front.width * 0.18, top - 55, 14);
    } else {
        const margin = 40;
        flat(look.window, front.left + margin, front.bottom + margin, front.width * 0.68, front.height - 2 * margin);
    }

    return group;
}

/** Inset granite sink: flat rim with one large and one small bowl, plus a high arched tap. */
function buildSink(item: Item, materials: MaterialLibrary): THREE.Group {
    const group = new THREE.Group();
    const granite = materials.get(item.materialId);
    const bowl = materials.plain('#0b0b0c', 0.75);
    const tapMaterial = materials.plain('#2a2a2c', 0.3, 0.8);
    const mm = (v: number): number => v * MM;

    const w = item.width;
    const d = item.depth;
    const rim = 50;
    group.add(boxAt(granite, mm(-w / 2), 0, mm(-d / 2), mm(w), mm(4), mm(d)));

    const bowls = item.params['bowls'] === 2 ? [0.6, 0.4] : [1];
    const inner = w - rim * (bowls.length + 1);
    let x = -w / 2 + rim;
    for (const share of bowls) {
        const bw = inner * share;
        group.add(boxAt(bowl, mm(x), mm(4), mm(-d / 2 + rim + 30), mm(bw), mm(1), mm(d - 2 * rim - 30)));
        x += bw + rim;
    }

    // Tap behind the large bowl: column, arch towards the bowl, spout down.
    const tapX = -w / 2 + rim + inner * bowls[0] / 2;
    const tapZ = -d / 2 + 25;
    const height = item.height;
    const reach = 180;
    const column = new THREE.Mesh(new THREE.CylinderGeometry(mm(15), mm(22), mm(height - reach / 2), 24), tapMaterial);
    column.position.set(mm(tapX), mm((height - reach / 2) / 2), mm(tapZ));
    group.add(column);

    const arch = new THREE.Mesh(new THREE.TorusGeometry(mm(reach / 2), mm(14), 16, 32, Math.PI), tapMaterial);
    arch.rotation.y = Math.PI / 2;
    arch.position.set(mm(tapX), mm(height - reach / 2), mm(tapZ + reach / 2));
    group.add(arch);

    const spout = new THREE.Mesh(new THREE.CylinderGeometry(mm(16), mm(16), mm(90), 24), tapMaterial);
    spout.position.set(mm(tapX), mm(height - reach / 2 - 45), mm(tapZ + reach));
    group.add(spout);

    group.traverse((o) => (o.castShadow = true));
    return group;
}

let hobTexture: THREE.CanvasTexture | null = null;

/** Induction hob: black glass with four cooking-zone rings. */
function buildHob(item: Item, materials: MaterialLibrary): THREE.Group {
    const group = new THREE.Group();
    const base = materials.get(item.materialId) as THREE.MeshStandardMaterial;
    const top = base.clone();
    // The map carries the glass colour itself, so the base tint must not darken the rings.
    top.color = new THREE.Color('#ffffff');
    top.map = hobRingsTexture(`#${base.color.getHexString()}`);
    const w = item.width * MM;
    const d = item.depth * MM;
    const plate = new THREE.Mesh(new THREE.BoxGeometry(w, item.height * MM, d), [base, base, top, base, base, base]);
    plate.position.y = (item.height * MM) / 2;
    plate.receiveShadow = true;
    group.add(plate);
    return group;
}

function hobRingsTexture(glassColor: string): THREE.CanvasTexture {
    if (hobTexture !== null) {
        return hobTexture;
    }
    const canvas = document.createElement('canvas');
    canvas.width = 512;
    canvas.height = 448;
    const ctx = canvas.getContext('2d');
    if (ctx !== null) {
        ctx.fillStyle = glassColor;
        ctx.fillRect(0, 0, canvas.width, canvas.height);
        ctx.strokeStyle = '#5a5a5a';
        ctx.lineWidth = 3;
        const zones: [number, number, number][] = [[0.27, 0.3, 0.2], [0.27, 0.72, 0.15], [0.73, 0.3, 0.15], [0.73, 0.68, 0.2]];
        for (const [cx, cy, r] of zones) {
            ctx.beginPath();
            ctx.arc(cx * canvas.width, cy * canvas.height, r * canvas.width, 0, Math.PI * 2);
            ctx.stroke();
        }
        ctx.fillStyle = '#6a6a6a';
        ctx.font = '22px sans-serif';
        ctx.fillText('−  ●  +', canvas.width / 2 - 40, canvas.height - 18);
    }
    hobTexture = new THREE.CanvasTexture(canvas);
    hobTexture.colorSpace = THREE.SRGBColorSpace;
    return hobTexture;
}

/** Under-cabinet LED: a glowing strip plus a downward rect light that washes the backsplash and worktop. */
function buildLedStrip(item: Item, materials: MaterialLibrary): THREE.Group {
    const group = new THREE.Group();
    const w = item.width * MM;
    const d = item.depth * MM;
    const strip = boxAt(materials.get(item.materialId), -w / 2, 0, -d / 2, w, item.height * MM, d, false);
    group.add(strip);

    const color = typeof item.params['color'] === 'string' ? item.params['color'] : '#ffd2a0';
    const intensity = typeof item.params['intensity'] === 'number' ? item.params['intensity'] : 5;
    const light = new THREE.RectAreaLight(color, intensity, w, 0.06);
    light.rotation.x = -Math.PI / 2;
    markFixtureLight(light, intensity);
    group.add(light);
    return group;
}
