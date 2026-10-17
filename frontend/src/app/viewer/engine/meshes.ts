import * as THREE from 'three';

/**
 * Box whose UVs are in metres instead of 0…1 per face, so tiled textures keep their real-world scale on
 * any size. Texture v runs along the height on the sides and along the depth on top and bottom.
 * `frontOffset` shifts the UVs of the ±z faces, so pieces of one surface continue the same tile grid.
 */
export function worldUvBox(width: number, height: number, depth: number, frontOffset?: {u: number; v: number}): THREE.BoxGeometry {
    const geometry = new THREE.BoxGeometry(width, height, depth);
    const uv = geometry.getAttribute('uv') as THREE.BufferAttribute;
    // Face order of BoxGeometry: +x, −x, +y, −y, +z, −z; four vertices each.
    const faceSize: [number, number][] = [
        [depth, height],
        [depth, height],
        [width, depth],
        [width, depth],
        [width, height],
        [width, height],
    ];
    for (let face = 0; face < 6; face++) {
        const [su, sv] = faceSize[face];
        const offset = face >= 4 && frontOffset !== undefined ? frontOffset : {u: 0, v: 0};
        for (let v = 0; v < 4; v++) {
            const i = face * 4 + v;
            uv.setXY(i, uv.getX(i) * su + offset.u, uv.getY(i) * sv + offset.v);
        }
    }
    uv.needsUpdate = true;
    return geometry;
}

/** Mesh for an axis-aligned box given by its min corner and size (metres, in the parent's frame). */
export function boxAt(
    material: THREE.Material,
    x: number,
    y: number,
    z: number,
    width: number,
    height: number,
    depth: number,
    shadows = true,
): THREE.Mesh {
    const mesh = new THREE.Mesh(worldUvBox(width, height, depth), material);
    mesh.position.set(x + width / 2, y + height / 2, z + depth / 2);
    mesh.castShadow = shadows;
    mesh.receiveShadow = shadows;
    return mesh;
}
