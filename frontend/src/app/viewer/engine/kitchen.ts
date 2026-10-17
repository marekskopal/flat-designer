import {CabinetParams, FrontSpec, Item} from '@app/models/scene';
import * as THREE from 'three';

import {GrainDirection, MaterialLibrary} from './materials';
import {boxAt} from './meshes';
import {MM, stackFronts} from './plan-geometry';

const FrontThickness = 19;
const FrontGap = 3;
const PlinthRecess = 50;
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
        case 'BaseCabinet':
        case 'WallCabinet':
        case 'TallCabinet':
            return buildCabinet(item, materials);
        case 'Worktop':
        case 'Backsplash':
            return buildSlab(item, materials, 'horizontal');
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

function cabinetParams(item: Item): Required<CabinetParams> {
    const p = item.params as CabinetParams;
    const fronts = Array.isArray(p.fronts) && p.fronts.length > 0 ? p.fronts : [{type: 'door'} satisfies FrontSpec];
    return {
        plinth: typeof p.plinth === 'number' ? p.plinth : 0,
        handle: p.handle ?? 'none',
        fronts,
        blindRight: typeof p.blindRight === 'number' ? p.blindRight : 0,
    };
}

function buildCabinet(item: Item, materials: MaterialLibrary): THREE.Group {
    const params = cabinetParams(item);
    const group = new THREE.Group();
    const body = materials.get(item.materialId);
    const mm = (v: number): number => v * MM;

    const halfW = item.width / 2;
    const backZ = -item.depth / 2;
    const frontZ = item.depth / 2;

    // Carcass behind the fronts, plinth recessed under them.
    const carcassHeight = item.height - params.plinth;
    group.add(boxAt(body, mm(-halfW), mm(params.plinth), mm(backZ), mm(item.width), mm(carcassHeight), mm(item.depth - FrontThickness)));
    if (params.plinth > 0) {
        const plinth = boxAt(
            plinthMaterial(body, materials),
            mm(-halfW + 2),
            0,
            mm(backZ),
            mm(item.width - 4),
            mm(params.plinth),
            mm(item.depth - FrontThickness - PlinthRecess),
        );
        group.add(plinth);
    }

    const frontLeft = -halfW;
    const frontRight = halfW - params.blindRight;
    const slots = stackFronts(params.fronts, item.height - params.plinth);

    slots.forEach((slot, index) => {
        const spec = params.fronts[index];
        const bottom = params.plinth + slot.bottom;
        const leaves = spec.type === 'door' ? Math.max(1, spec.count ?? 1) : 1;
        const leafWidth = (frontRight - frontLeft) / leaves;

        for (let leaf = 0; leaf < leaves; leaf++) {
            const left = frontLeft + leaf * leafWidth + FrontGap / 2;
            const width = leafWidth - FrontGap;
            const front = {left, width, bottom: bottom + FrontGap / 2, height: slot.height - FrontGap, z: frontZ - FrontThickness};

            if (spec.type === 'oven' || spec.type === 'microwave') {
                group.add(buildAppliance(spec.type, front, materials));
                continue;
            }

            group.add(boxAt(body, mm(front.left), mm(front.bottom), mm(front.z), mm(front.width), mm(front.height), mm(FrontThickness)));

            if (params.handle !== 'none') {
                // Double doors get their handles at the meeting edges.
                const edge = leaves === 2 ? (leaf === 0 ? 'right' : 'left') : 'right';
                group.add(buildHandle(params.handle, front, item.z, edge, materials));
            }
        }
    });

    return group;
}

/** Plinth in a darker shade of the carcass, as it sits in the shadow under the fronts. */
function plinthMaterial(body: THREE.Material, materials: MaterialLibrary): THREE.Material {
    const color = body instanceof THREE.MeshStandardMaterial ? body.color.clone().multiplyScalar(0.7) : new THREE.Color('#333333');
    return materials.plain(`#${color.getHexString()}`, 0.8);
}

interface FrontBox {
    left: number;
    width: number;
    bottom: number;
    height: number;
    /** Back face of the front (mm, cabinet-local). */
    z: number;
}

function buildHandle(
    orientation: 'horizontal' | 'vertical',
    front: FrontBox,
    itemElevation: number,
    edge: 'left' | 'right',
    materials: MaterialLibrary,
): THREE.Mesh {
    const material = materials.plain('#161616', 0.35, 0.6);
    const z = front.z + FrontThickness + HandleStandoff - HandleSection;

    if (orientation === 'horizontal') {
        const length = THREE.MathUtils.clamp(front.width * 0.45, 120, 480);
        const nearTop = itemElevation + front.bottom + front.height <= HandleReachHeight + 400;
        const y = nearTop ? front.bottom + front.height - 45 : front.bottom + 45;
        const left = front.left + (front.width - length) / 2;
        return boxAt(material, left * MM, (y - HandleSection / 2) * MM, z * MM, length * MM, HandleSection * MM, HandleSection * MM);
    }

    const length = THREE.MathUtils.clamp(front.height * 0.35, 150, 500);
    const x = edge === 'right' ? front.left + front.width - 45 : front.left + 45;
    const centre = THREE.MathUtils.clamp(
        HandleReachHeight - itemElevation,
        front.bottom + 80 + length / 2,
        front.bottom + front.height - 80 - length / 2,
    );
    return boxAt(material, (x - HandleSection / 2) * MM, (centre - length / 2) * MM, z * MM, HandleSection * MM, length * MM, HandleSection * MM);
}

/** Built-in oven or microwave: black glass front with a darker window and a steel bar handle. */
function buildAppliance(type: 'oven' | 'microwave', front: FrontBox, materials: MaterialLibrary): THREE.Group {
    const group = new THREE.Group();
    const glass = materials.byCode('appliance-black-glass');
    const windowMaterial = materials.plain('#050506', 0.05, 0.5);
    const steel = materials.plain('#9a9a9a', 0.25, 1);
    const mm = (v: number): number => v * MM;
    const faceZ = front.z + FrontThickness;

    group.add(boxAt(glass, mm(front.left), mm(front.bottom), mm(front.z), mm(front.width), mm(front.height), mm(FrontThickness)));

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
    group.add(light);
    return group;
}
