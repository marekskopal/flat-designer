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
 * edge depending on the height (`itemElevation` + front), vertical ones at `edge` around reach height.
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
): THREE.Mesh {
    const material = handleMaterial ?? defaultHandleMaterial(materials);
    const z = faceZ + HandleStandoff - HandleSection;

    if (orientation === 'horizontal') {
        const length =
            handleLength === undefined ? THREE.MathUtils.clamp(front.width * 0.45, 120, 480) : Math.min(handleLength, Math.max(40, front.width - 20));
        const y = handleNearTop(front, itemElevation) ? front.bottom + front.height - 45 : front.bottom + 45;
        const left = front.left + (front.width - length) / 2;
        return boxAt(material, left * MM, (y - HandleSection / 2) * MM, z * MM, length * MM, HandleSection * MM, HandleSection * MM);
    }

    const length =
        handleLength === undefined ? THREE.MathUtils.clamp(front.height * 0.35, 150, 500) : Math.min(handleLength, Math.max(40, front.height - 160));
    const x = edge === 'right' ? front.left + front.width - 45 : front.left + 45;
    const centre = THREE.MathUtils.clamp(
        HandleReachHeight - itemElevation,
        front.bottom + 80 + length / 2,
        front.bottom + front.height - 80 - length / 2,
    );
    return boxAt(material, (x - HandleSection / 2) * MM, (centre - length / 2) * MM, z * MM, HandleSection * MM, length * MM, HandleSection * MM);
}

/** The handle kinds a front may have (`PartTypeEnum::Handles`); `push` and `none` draw nothing. */
export type FrontHandle = 'horizontal' | 'vertical' | 'knob' | 'edge' | 'gola' | 'push' | 'none';

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
 * `buildHandle()`, a knob (`KnobDiameter`) at a door's handle edge or a drawer's middle, near the top or bottom as the
 * bars; an edge pull (`EdgePull`) on the top edge (the bottom one out of reach); a gola channel, a dark band
 * `GolaHeight` high along the top (or bottom) of the face. On a tall door reaching from below reach height to well
 * above it the knob and the edge pull stand at reach height on its handle edge and the gola channel runs up that edge.
 */
export function buildFrontHandle(
    kind: FrontHandle,
    front: Omit<FrontBox, 'z'>,
    faceZ: number,
    itemElevation: number,
    edge: 'left' | 'right',
    drawer: boolean,
    materials: MaterialLibrary,
    handleMaterial?: THREE.Material,
    handleLength?: number,
): THREE.Object3D | null {
    const material = handleMaterial ?? defaultHandleMaterial(materials);
    const nearTop = handleNearTop(front, itemElevation);
    const top = front.bottom + front.height;
    // A tall door reaching from below reach height to well above it: its handle stands at reach height on its handle edge.
    const tall = !drawer && !nearTop && itemElevation + front.bottom < HandleReachHeight;
    const atReach = (length: number): number =>
        THREE.MathUtils.clamp(HandleReachHeight - itemElevation, front.bottom + length / 2 + 20, top - length / 2 - 20);
    switch (kind) {
        case 'horizontal':
        case 'vertical':
            return buildHandle(kind, front, faceZ, itemElevation, edge, materials, material, handleLength);
        case 'knob': {
            const x = drawer ? front.left + front.width / 2 : edge === 'right' ? front.left + front.width - 45 : front.left + 45;
            const y = drawer && front.height < 300 ? front.bottom + front.height / 2 : nearTop ? top - 60 : tall ? atReach(0) : front.bottom + 60;
            const geometry = new THREE.CylinderGeometry((KnobDiameter / 2) * MM, (KnobDiameter / 2 - 4) * MM, KnobDepth * MM, 24);
            const knob = new THREE.Mesh(geometry, material);
            knob.rotation.x = Math.PI / 2;
            knob.position.set(x * MM, y * MM, (faceZ + KnobDepth / 2) * MM);
            knob.castShadow = true;
            return knob;
        }
        case 'edge': {
            if (tall) {
                // A profile over the handle edge, at reach height.
                const length = handleLength ?? THREE.MathUtils.clamp(front.height * 0.3, 150, 600);
                const x = edge === 'right' ? front.left + front.width - EdgePull.face : front.left - EdgePull.lip;
                const [across, depth] = [EdgePull.face + EdgePull.lip, EdgePull.depth + EdgePull.proud];
                const y = atReach(length) - length / 2;
                return boxAt(material, x * MM, y * MM, (faceZ - EdgePull.depth) * MM, across * MM, length * MM, depth * MM);
            }
            const length = Math.min(handleLength ?? THREE.MathUtils.clamp(front.width * 0.5, 150, 600), front.width - 20);
            const left = front.left + (front.width - length) / 2;
            // A profile over the edge: on the face, a lip over the edge and a little beyond it.
            const y = nearTop ? top - EdgePull.face : front.bottom - EdgePull.lip;
            const [height, depth] = [EdgePull.face + EdgePull.lip, EdgePull.depth + EdgePull.proud];
            return boxAt(material, left * MM, y * MM, (faceZ - EdgePull.depth) * MM, length * MM, height * MM, depth * MM);
        }
        case 'gola': {
            const shadow = materials.plain('#0b0b0c', 0.6, 0.2);
            if (tall) {
                // A vertical channel along the handle edge, the full height of the door.
                const x = edge === 'right' ? front.left + front.width - GolaHeight : front.left;
                return boxAt(shadow, x * MM, front.bottom * MM, faceZ * MM, GolaHeight * MM, front.height * MM, 0.6 * MM, false);
            }
            const y = nearTop ? top - GolaHeight : front.bottom;
            return boxAt(shadow, front.left * MM, y * MM, faceZ * MM, front.width * MM, GolaHeight * MM, 0.6 * MM, false);
        }
        default:
            return null;
    }
}

/** A knob's diameter and how far it stands out (mm). */
const KnobDiameter = 32;
const KnobDepth = 26;

/** An edge pull's profile (mm): down the face, over the edge, into the front's thickness and out in front of it. */
const EdgePull = {face: 22, lip: 3, depth: 8, proud: 6};

/** The height (mm) of a gola channel's shadow band. */
const GolaHeight = 28;

/**
 * Built-in oven or microwave: black glass front with a darker window and a steel bar handle (mm, item-local; the
 * front `thickness` deep from its back face `front.z`).
 */
export function buildAppliance(
    type: 'oven' | 'microwave',
    front: FrontBox,
    materials: MaterialLibrary,
    thickness: number = FrontThickness,
): THREE.Group {
    const group = new THREE.Group();
    const glass = materials.byCode('appliance-black-glass');
    const windowMaterial = materials.plain('#050506', 0.05, 0.5);
    const steel = materials.plain('#9a9a9a', 0.25, 1);
    const mm = (v: number): number => v * MM;
    const faceZ = front.z + thickness;

    group.add(boxAt(glass, mm(front.left), mm(front.bottom), mm(front.z), mm(front.width), mm(front.height), mm(thickness)));

    if (type === 'oven') {
        const controls = 90;
        const margin = 60;
        group.add(
            boxAt(
                windowMaterial,
                mm(front.left + margin),
                mm(front.bottom + margin),
                mm(faceZ),
                mm(front.width - 2 * margin),
                mm(front.height - controls - 2 * margin),
                mm(2),
            ),
        );
        const handleY = front.bottom + front.height - controls - 30;
        group.add(boxAt(steel, mm(front.left + 60), mm(handleY), mm(faceZ + 20), mm(front.width - 120), mm(14), mm(14)));
        // Control knobs in the top strip.
        for (const x of [0.18, 0.3, 0.7, 0.82]) {
            const knob = new THREE.Mesh(new THREE.CylinderGeometry(mm(16), mm(16), mm(14), 24), steel);
            knob.rotation.x = Math.PI / 2;
            knob.position.set(mm(front.left + front.width * x), mm(front.bottom + front.height - controls / 2), mm(faceZ + 7));
            group.add(knob);
        }
    } else {
        const margin = 40;
        group.add(
            boxAt(
                windowMaterial,
                mm(front.left + margin),
                mm(front.bottom + margin),
                mm(faceZ),
                mm(front.width * 0.68),
                mm(front.height - 2 * margin),
                mm(2),
            ),
        );
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
