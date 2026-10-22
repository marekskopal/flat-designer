import * as THREE from 'three';

import {MaterialLibrary} from './materials';
import {MM, TileGrid, tileUv, turnedUv} from './plan-geometry';

const position = new THREE.Vector3();
const normal = new THREE.Vector3();
const normalMatrix = new THREE.Matrix3();

/**
 * Puts every tiled mesh under `object` on one tile grid: UVs are recomputed from each vertex's plan position
 * (see `tileUv`), so the floor, the wall bands and tiled items (bath panel, pre-wall) of a room share their
 * joints. `object` must already be placed in the floor's frame (scene x, y up, −z = plan y), not yet added
 * to it. Meshes with other materials keep their UVs. Level faces are turned by the grid's angle.
 */
export function alignTiles(object: THREE.Object3D, grid: TileGrid, materials: MaterialLibrary): void {
    object.updateWorldMatrix(true, true);
    object.traverse((mesh) => {
        if (!(mesh instanceof THREE.Mesh) || Array.isArray(mesh.material) || !materials.isTile(mesh.material as THREE.Material)) {
            return;
        }
        const geometry = mesh.geometry as THREE.BufferGeometry;
        const positions = geometry.getAttribute('position');
        const normals = geometry.getAttribute('normal') as THREE.BufferAttribute | undefined;
        const uv = geometry.getAttribute('uv') as THREE.BufferAttribute | undefined;
        if (normals === undefined || uv === undefined) {
            return;
        }
        normalMatrix.getNormalMatrix(mesh.matrixWorld);
        for (let i = 0; i < positions.count; i++) {
            position.fromBufferAttribute(positions, i).applyMatrix4(mesh.matrixWorld);
            normal.fromBufferAttribute(normals, i).applyMatrix3(normalMatrix).normalize();
            const [u, v] = tileUv(
                {x: position.x / MM, y: -position.z / MM},
                position.y / MM,
                {x: normal.x, y: -normal.z, up: normal.y},
                grid,
            );
            uv.setXY(i, u, v);
        }
        uv.needsUpdate = true;
    });
}

/**
 * Turns the UVs of a level surface whose UVs are plan x, y in metres (a room's floor) by the grid's angle about its
 * origin, so planks and other patterns not laid by `alignTiles` follow the room's floor angle too.
 */
export function turnLevelUvs(geometry: THREE.BufferGeometry, grid: TileGrid): void {
    const uv = geometry.getAttribute('uv') as THREE.BufferAttribute | undefined;
    if (grid.angle === 0 || uv === undefined) {
        return;
    }
    for (let i = 0; i < uv.count; i++) {
        const [u, v] = turnedUv(uv.getX(i) / MM - grid.origin.x, uv.getY(i) / MM - grid.origin.y, grid.angle);
        uv.setXY(i, grid.origin.x * MM + u, grid.origin.y * MM + v);
    }
    uv.needsUpdate = true;
}
