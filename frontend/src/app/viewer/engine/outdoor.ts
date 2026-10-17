import {Item} from '@app/models/scene';
import * as THREE from 'three';

import {MaterialLibrary} from './materials';
import {boxAt} from './meshes';
import {MM} from './plan-geometry';

/**
 * Outdoor items in the item-local frame (bottom centre origin, x along the width, +z out of the
 * front). Returns null for kinds handled elsewhere.
 */
export function buildOutdoorItemBody(item: Item, materials: MaterialLibrary): THREE.Group | null {
    return item.kind === 'Fence' ? buildFence(item, materials) : null;
}

const PostSize = 60;
const BoardThickness = 50;
/** Welded mesh: vertical wires every 50 mm, horizontal every 200 mm. */
const MeshPitchU = 0.05;
const MeshPitchV = 0.2;

/**
 * Panel fence along x: square posts every `panelLength` mm, a concrete base board (`baseBoard` mm
 * high) between them and welded-mesh panels above it.
 */
function buildFence(item: Item, materials: MaterialLibrary): THREE.Group {
    const group = new THREE.Group();
    const metal = materials.get(item.materialId);
    const board = materials.plain('#9c9890', 0.9);
    const panelLength = typeof item.params['panelLength'] === 'number' ? item.params['panelLength'] : 2500;
    const baseBoard = typeof item.params['baseBoard'] === 'number' ? item.params['baseBoard'] : 0;

    const length = item.width;
    const height = item.height;
    const count = Math.max(1, Math.round(length / panelLength));
    const span = length / count;
    const mesh = weldedMeshMaterial(metal);

    for (let i = 0; i <= count; i++) {
        const x = -length / 2 + i * span;
        group.add(boxAt(metal, (x - PostSize / 2) * MM, 0, (-PostSize / 2) * MM, PostSize * MM, height * MM, PostSize * MM));
    }

    for (let i = 0; i < count; i++) {
        const from = -length / 2 + i * span + PostSize / 2;
        const width = span - PostSize;
        if (baseBoard > 0) {
            group.add(boxAt(board, from * MM, 0, (-BoardThickness / 2) * MM, width * MM, baseBoard * MM, BoardThickness * MM));
        }

        const panelHeight = height - baseBoard - 30;
        const geometry = new THREE.PlaneGeometry(width * MM, panelHeight * MM);
        // UVs in metres so the wire pitch stays real whatever the panel size.
        const uv = geometry.getAttribute('uv') as THREE.BufferAttribute;
        for (let k = 0; k < uv.count; k++) {
            uv.setXY(k, uv.getX(k) * width * MM, uv.getY(k) * panelHeight * MM);
        }
        const panel = new THREE.Mesh(geometry, mesh);
        panel.position.set((from + width / 2) * MM, (baseBoard + 15 + panelHeight / 2) * MM, 0);
        panel.castShadow = true;
        group.add(panel);
    }

    return group;
}

/** Wire grid as an alpha-tested texture in the fence colour, visible from both sides. */
function weldedMeshMaterial(base: THREE.Material): THREE.MeshStandardMaterial {
    const canvas = document.createElement('canvas');
    canvas.width = 64;
    canvas.height = 256;
    const ctx = canvas.getContext('2d');
    if (ctx !== null) {
        ctx.clearRect(0, 0, canvas.width, canvas.height);
        ctx.fillStyle = '#ffffff';
        ctx.fillRect(0, 0, 6, canvas.height);
        ctx.fillRect(0, 0, canvas.width, 8);
    }
    const texture = new THREE.CanvasTexture(canvas);
    texture.wrapS = THREE.RepeatWrapping;
    texture.wrapT = THREE.RepeatWrapping;
    texture.repeat.set(1 / MeshPitchU, 1 / MeshPitchV);
    texture.colorSpace = THREE.SRGBColorSpace;

    const color = base instanceof THREE.MeshStandardMaterial ? base.color : new THREE.Color('#383b3e');
    return new THREE.MeshStandardMaterial({color, map: texture, alphaTest: 0.5, side: THREE.DoubleSide, roughness: 0.5, metalness: 0.3});
}
