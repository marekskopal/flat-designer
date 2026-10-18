import * as THREE from 'three';

import {MaterialLibrary} from './materials';
import {MM, tileUv, Vec2} from './plan-geometry';

const position = new THREE.Vector3();
const normal = new THREE.Vector3();
const normalMatrix = new THREE.Matrix3();

/**
 * Puts every tiled mesh under `object` on one tile grid: UVs are recomputed from each vertex's plan position
 * (see `tileUv`), so the floor, the wall bands and tiled items (bath panel, pre-wall) of a room share their
 * joints. `object` must already be placed in the floor's frame (scene x, y up, −z = plan y), not yet added
 * to it. Meshes with other materials keep their UVs.
 */
export function alignTiles(object: THREE.Object3D, origin: Vec2, materials: MaterialLibrary): void {
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
                origin,
            );
            uv.setXY(i, u, v);
        }
        uv.needsUpdate = true;
    });
}
