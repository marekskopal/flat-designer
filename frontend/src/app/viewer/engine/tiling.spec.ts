import {Material} from '@app/models/scene';
import * as THREE from 'three';
import {describe, expect, it} from 'vitest';

import {MaterialLibrary} from './materials';
import {MM} from './plan-geometry';
import {alignTiles} from './tiling';

const tiles: Material = {
    id: 5,
    code: 'tiles',
    name: 'Wall tiles 300 × 300',
    pattern: 'Tiles',
    color: '#d9d4cc',
    roughness: 0.4,
    metalness: 0,
    texture: null,
    tileWidth: 300,
    tileHeight: 300,
};

/** A splashback 600 high facing south (−y), its bottom `bottom` mm over the floor. */
function splashback(materials: MaterialLibrary, bottom: number): THREE.Mesh {
    const mesh = new THREE.Mesh(new THREE.PlaneGeometry(1200 * MM, 600 * MM), materials.get(tiles.id));
    mesh.position.set(0, (bottom + 300) * MM, 0);
    mesh.updateWorldMatrix(true, true);
    return mesh;
}

/** The lowest v (metres) of the mesh's UVs. */
function lowestV(mesh: THREE.Mesh): number {
    const uv = mesh.geometry.getAttribute('uv');
    let lowest = Infinity;
    for (let i = 0; i < uv.count; i++) {
        lowest = Math.min(lowest, uv.getY(i));
    }
    return lowest;
}

describe('alignTiles', () => {
    it('counts upright rows from the floor, or from the base given (an item\'s own bottom)', () => {
        const materials = new MaterialLibrary([tiles], 1);
        const grid = {origin: {x: 0, y: 0}, angle: 0};

        const fromFloor = splashback(materials, 910);
        alignTiles(fromFloor, grid, materials);
        expect(lowestV(fromFloor)).toBeCloseTo(0.91);

        const fromItem = splashback(materials, 910);
        alignTiles(fromItem, grid, materials, 910);
        expect(lowestV(fromItem)).toBeCloseTo(0);
    });
});
