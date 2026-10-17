import {Item} from '@app/models/scene';
import * as THREE from 'three';

import {buildSlab} from './kitchen';
import {MaterialLibrary} from './materials';
import {boxAt} from './meshes';
import {MM} from './plan-geometry';

/**
 * Bathroom and WC items in the item-local frame: origin at the bottom centre of the item's box, x
 * along the width, y up, +z out of the front (the back touches the wall). Returns null for kinds
 * handled elsewhere. Shapes are simplified but sized like the real products.
 */
export function buildSanitaryItemBody(item: Item, materials: MaterialLibrary): THREE.Group | null {
    switch (item.kind) {
        case 'Box':
            return buildSlab(item, materials, 'vertical');
        case 'Bathtub':
            return buildBathtub(item, materials);
        case 'Washbasin':
            return buildWashbasin(item, materials);
        case 'Toilet':
            return buildToilet(item, materials);
        case 'WashingMachine':
            return buildWashingMachine(item, materials);
        case 'Mirror':
            return buildSlab(item, materials, 'vertical');
        case 'TowelRadiator':
            return buildTowelRadiator(item, materials);
        case 'FlushPlate':
            return buildFlushPlate(item, materials);
        case 'Fixture':
            return buildFixture(item, materials);
        default:
            return null;
    }
}

const mm = (v: number): number => v * MM;

function chrome(materials: MaterialLibrary): THREE.Material {
    return materials.byCode('chrome');
}

function cylinder(material: THREE.Material, radius: number, length: number, axis: 'x' | 'y' | 'z', x: number, y: number, z: number): THREE.Mesh {
    const mesh = new THREE.Mesh(new THREE.CylinderGeometry(mm(radius), mm(radius), mm(length), 24), material);
    if (axis === 'x') {
        mesh.rotation.z = Math.PI / 2;
    } else if (axis === 'z') {
        mesh.rotation.x = Math.PI / 2;
    }
    mesh.position.set(mm(x), mm(y), mm(z));
    return mesh;
}

/** Box from min corner and size in millimetres. */
function box(material: THREE.Material, x: number, y: number, z: number, w: number, h: number, d: number): THREE.Mesh {
    return boxAt(material, mm(x), mm(y), mm(z), mm(w), mm(h), mm(d));
}

/**
 * Built-in acrylic bath with a tiled front panel: rim frame on top, inner liner and floor of the tub
 * below it, drain at the +x end (where the mixer sits).
 */
function buildBathtub(item: Item, materials: MaterialLibrary): THREE.Group {
    const group = new THREE.Group();
    const acrylic = materials.get(item.materialId);
    const panelId = item.params['panelMaterialId'];
    const panel = materials.get(typeof panelId === 'number' ? panelId : null);

    const w = item.width;
    const d = item.depth;
    const h = item.height;
    const rim = 40;
    const side = 70;
    const end = 110;
    const x0 = -w / 2;
    const z0 = -d / 2;
    const bottom = 140;

    // Tiled apron along the front.
    group.add(box(panel, x0, 0, d / 2 - 20, w, h - rim, 20));

    // Rim frame.
    group.add(box(acrylic, x0, h - rim, z0, w, rim, side));
    group.add(box(acrylic, x0, h - rim, d / 2 - side, w, rim, side));
    group.add(box(acrylic, x0, h - rim, z0 + side, end, rim, d - 2 * side));
    group.add(box(acrylic, w / 2 - end, h - rim, z0 + side, end, rim, d - 2 * side));

    // Liner: four inner walls and the floor of the tub.
    const liner = 8;
    const innerW = w - 2 * end;
    const innerD = d - 2 * side;
    const innerH = h - rim - bottom;
    group.add(box(acrylic, x0 + end, bottom, z0 + side, innerW, innerH, liner));
    group.add(box(acrylic, x0 + end, bottom, d / 2 - side - liner, innerW, innerH, liner));
    group.add(box(acrylic, x0 + end, bottom, z0 + side, liner, innerH, innerD));
    group.add(box(acrylic, w / 2 - end - liner, bottom, z0 + side, liner, innerH, innerD));
    group.add(box(acrylic, x0 + end, bottom - 10, z0 + side, innerW, 10, innerD));

    const drain = cylinder(chrome(materials), 30, 4, 'y', w / 2 - end - 120, bottom + 2, 0);
    group.add(drain);
    return group;
}

/** Wall-hung ceramic basin with a recessed bowl, tap and siphon. `bowl: 'small'` puts the tap in the corner. */
function buildWashbasin(item: Item, materials: MaterialLibrary): THREE.Group {
    const group = new THREE.Group();
    const ceramic = materials.get(item.materialId);
    const bowlShade = materials.plain('#dcdcda', 0.2);
    const metal = chrome(materials);
    const w = item.width;
    const d = item.depth;
    const h = item.height;
    const small = item.params['bowl'] === 'small';

    group.add(box(ceramic, -w / 2, 0, -d / 2, w, h, d));

    // Bowl: shaded recess, back ledge left for the tap.
    const ledge = small ? 40 : 90;
    const margin = small ? 35 : 45;
    const bowlW = small ? w * 0.62 : w - 2 * margin;
    const bowlX = small ? -w / 2 + margin : -w / 2 + margin;
    group.add(box(bowlShade, bowlX, h, -d / 2 + ledge, bowlW, 1, d - ledge - margin));
    group.add(cylinder(metal, 18, 2, 'y', bowlX + bowlW / 2, h + 2, (ledge - margin) / 2 + 20));

    // Single-lever tap.
    const tapX = small ? w / 2 - 70 : 0;
    const tapZ = -d / 2 + ledge / 2 + (small ? 20 : 0);
    group.add(cylinder(metal, 22, 110, 'y', tapX, h + 55, tapZ));
    group.add(box(metal, tapX - 14, h + 85, tapZ, 28, 22, 110));
    group.add(box(metal, tapX - 6, h + 112, tapZ - 40, 12, 10, 80));

    // Siphon to the wall.
    group.add(cylinder(metal, 18, 200, 'y', 0, -100, -d / 2 + 80));
    group.add(cylinder(metal, 16, 80, 'z', 0, -190, -d / 2 + 40));
    return group;
}

/** Wall-hung toilet: D-shaped bowl and a thin seat with lid. */
function buildToilet(item: Item, materials: MaterialLibrary): THREE.Group {
    const group = new THREE.Group();
    const ceramic = materials.get(item.materialId);
    const seatMaterial = materials.plain('#f7f7f5', 0.25);
    const w = item.width;
    const d = item.depth;
    const h = item.height;

    const dShape = (inset: number): THREE.Shape => {
        const r = w / 2 - inset;
        const shape = new THREE.Shape();
        shape.moveTo(mm(-r), mm(d / 2 - inset));
        shape.lineTo(mm(r), mm(d / 2 - inset));
        shape.lineTo(mm(r), mm(-d / 2 + w / 2));
        shape.absarc(0, mm(-d / 2 + w / 2), mm(r), 0, -Math.PI, true);
        shape.lineTo(mm(-r), mm(d / 2 - inset));
        return shape;
    };

    // Extruded shape lies in plan (x, −z) and grows upwards after rotating it onto the floor.
    const extrude = (shape: THREE.Shape, height: number, material: THREE.Material, bottom: number): THREE.Mesh => {
        const bevel = {bevelEnabled: true, bevelThickness: mm(8), bevelSize: mm(8), bevelSegments: 3};
        const geometry = new THREE.ExtrudeGeometry(shape, {depth: mm(height), ...bevel});
        geometry.rotateX(-Math.PI / 2);
        const mesh = new THREE.Mesh(geometry, material);
        mesh.position.y = mm(bottom);
        return mesh;
    };

    group.add(extrude(dShape(30), 260, ceramic, h - 340));
    group.add(extrude(dShape(10), 22, seatMaterial, h - 50));
    group.add(extrude(dShape(12), 14, seatMaterial, h - 20));
    return group;
}

/** Front loader: white box, porthole door, control strip and detergent drawer. */
function buildWashingMachine(item: Item, materials: MaterialLibrary): THREE.Group {
    const group = new THREE.Group();
    const body = materials.get(item.materialId);
    const trim = materials.plain('#c9cacb', 0.3, 0.4);
    const glass = materials.plain('#2a2d31', 0.05, 0.3);
    const display = materials.plain('#16181a', 0.2);
    const w = item.width;
    const d = item.depth;
    const h = item.height;
    const front = d / 2;

    group.add(box(body, -w / 2, 0, -d / 2, w, h, d));

    const doorY = h * 0.42;
    const ring = new THREE.Mesh(new THREE.TorusGeometry(mm(165), mm(22), 16, 48), trim);
    ring.position.set(0, mm(doorY), mm(front + 15));
    group.add(ring);
    const pane = new THREE.Mesh(new THREE.CircleGeometry(mm(150), 48), glass);
    pane.position.set(0, mm(doorY), mm(front + 10));
    group.add(pane);

    // Control strip: drawer on the left, display and knob on the right.
    group.add(box(trim, -w / 2 + 20, h - 130, front, 160, 90, 4));
    group.add(box(display, 0, h - 105, front, 130, 40, 3));
    group.add(cylinder(trim, 28, 20, 'z', w / 2 - 90, h - 85, front + 10));
    return group;
}

/** Ladder towel radiator: two vertical tubes with groups of rungs. */
function buildTowelRadiator(item: Item, materials: MaterialLibrary): THREE.Group {
    const group = new THREE.Group();
    const white = materials.get(item.materialId);
    const w = item.width;
    const h = item.height;
    const tube = 15;

    for (const x of [-w / 2 + tube, w / 2 - tube]) {
        group.add(cylinder(white, tube, h, 'y', x, h / 2, 0));
        // Wall brackets.
        for (const y of [150, h - 150]) {
            group.add(cylinder(white, 8, 50, 'z', x, y, -25));
        }
    }

    const pitch = 45;
    for (let y = 40; y < h - 20; y += pitch) {
        // Leave two wider gaps for hanging towels.
        if (Math.abs(y - h * 0.35) < pitch || Math.abs(y - h * 0.7) < pitch) {
            continue;
        }
        group.add(cylinder(white, 10, w - 2 * tube, 'x', 0, y, 0));
    }
    return group;
}

/** Dual flush plate. */
function buildFlushPlate(item: Item, materials: MaterialLibrary): THREE.Group {
    const group = new THREE.Group();
    const plate = materials.get(item.materialId);
    const edge = materials.plain('#d9dad9', 0.3);
    const w = item.width;
    const h = item.height;
    const d = item.depth;

    group.add(box(plate, -w / 2, 0, -d / 2, w, h, d));
    group.add(cylinder(edge, 55, 3, 'z', -25, h / 2, d / 2 + 1));
    group.add(cylinder(edge, 36, 3, 'z', 55, h / 2 - 10, d / 2 + 1));
    return group;
}

/** Chrome wall fittings: `type` is bathMixer, showerHead or bidetSpray. */
function buildFixture(item: Item, materials: MaterialLibrary): THREE.Group {
    const group = new THREE.Group();
    const metal = materials.get(item.materialId);
    const w = item.width;
    const h = item.height;
    const d = item.depth;
    const front = d / 2;

    switch (item.params['type']) {
        case 'bathMixer': {
            // Thermostatic bar with end knobs and a spout pointing down into the bath.
            const y = h / 2;
            group.add(cylinder(metal, 24, w - 60, 'x', 0, y, front - 30));
            group.add(cylinder(metal, 30, 50, 'x', -w / 2 + 25, y, front - 30));
            group.add(cylinder(metal, 30, 50, 'x', w / 2 - 25, y, front - 30));
            for (const x of [-w / 2 + 60, w / 2 - 60]) {
                group.add(cylinder(metal, 12, d - 30, 'z', x, y, -d / 2 + (d - 30) / 2));
            }
            group.add(box(metal, -20, y - 70, front - 45, 40, 50, 30));
            break;
        }
        case 'showerHead': {
            // Square hand shower resting in a wall holder, head up.
            group.add(box(metal, -15, 0, -d / 2, 30, 40, 30));
            group.add(cylinder(metal, 13, h - 110, 'y', 0, (h - 110) / 2, -d / 2 + 45));
            group.add(box(metal, -w / 2, h - 110, -d / 2 + 30, w, 110, 22));
            break;
        }
        case 'bidetSpray':
        default: {
            group.add(box(metal, -15, h - 120, -d / 2, 30, 50, 25));
            group.add(cylinder(metal, 12, 160, 'y', 0, h - 140, -d / 2 + 40));
            // Hose hanging to the wall outlet.
            group.add(cylinder(metal, 5, h - 220, 'y', 12, (h - 220) / 2, -d / 2 + 30));
            break;
        }
    }
    return group;
}
