import * as THREE from 'three';

import {worldUvBox} from '../meshes';
import {MM} from '../plan-geometry';

/**
 * Leg from the floor to `height` (mm), `width` × `depth` at the top and `bottom` square at the floor, centred on
 * (x, z) at the top. The taper is on the inner faces: the outer faces, towards (`sx`, `sz`), stay plumb.
 */
export function taperedLeg(
    material: THREE.Material,
    x: number,
    z: number,
    height: number,
    width: number,
    depth: number,
    bottom: number,
    sx: number,
    sz: number,
): THREE.Mesh {
    const geometry = worldUvBox(width * MM, height * MM, depth * MM);
    const position = geometry.getAttribute('position') as THREE.BufferAttribute;
    for (let i = 0; i < position.count; i++) {
        if (position.getY(i) < 0) {
            // Pull the inner faces towards the outer ones.
            const px = position.getX(i);
            const pz = position.getZ(i);
            position.setX(i, Math.sign(px) === sx ? px : px + (sx * (width - bottom) * MM));
            position.setZ(i, Math.sign(pz) === sz ? pz : pz + (sz * (depth - bottom) * MM));
        }
    }
    position.needsUpdate = true;
    geometry.computeVertexNormals();
    const mesh = new THREE.Mesh(geometry, material);
    mesh.position.set(x * MM, (height / 2) * MM, z * MM);
    return mesh;
}
