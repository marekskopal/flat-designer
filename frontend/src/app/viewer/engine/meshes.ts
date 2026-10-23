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

/**
 * Upright prism over a convex footprint (x, z in the parent's frame, metres) from `bottom` to `top`: a wall
 * piece with a mitred end. UVs are in metres like `worldUvBox` (sides along x or z, top and bottom in x/z).
 */
export function prismAt(material: THREE.Material, footprint: readonly {x: number; z: number}[], bottom: number, top: number): THREE.Mesh {
    const positions: number[] = [];
    const uvs: number[] = [];
    const center = footprint.reduce((c, p) => ({x: c.x + p.x / footprint.length, z: c.z + p.z / footprint.length}), {x: 0, z: 0});
    const centroid = new THREE.Vector3(center.x, (bottom + top) / 2, center.z);

    const triangle = (a: THREE.Vector3, b: THREE.Vector3, c: THREE.Vector3, uv: (p: THREE.Vector3) => [number, number]): void => {
        // Wind every face counter-clockwise seen from outside.
        const normal = new THREE.Vector3().subVectors(b, a).cross(new THREE.Vector3().subVectors(c, a));
        const outward = new THREE.Vector3().addVectors(a, b).add(c).divideScalar(3).sub(centroid);
        const vertices = normal.dot(outward) >= 0 ? [a, b, c] : [a, c, b];
        for (const v of vertices) {
            positions.push(v.x, v.y, v.z);
            uvs.push(...uv(v));
        }
    };

    footprint.forEach((p, i) => {
        const q = footprint[(i + 1) % footprint.length];
        const alongX = Math.abs(q.x - p.x) >= Math.abs(q.z - p.z);
        const uv = (v: THREE.Vector3): [number, number] => [alongX ? v.x : v.z, v.y];
        const p0 = new THREE.Vector3(p.x, bottom, p.z);
        const q0 = new THREE.Vector3(q.x, bottom, q.z);
        const q1 = new THREE.Vector3(q.x, top, q.z);
        const p1 = new THREE.Vector3(p.x, top, p.z);
        triangle(p0, q0, q1, uv);
        triangle(p0, q1, p1, uv);
    });
    const flat = (v: THREE.Vector3): [number, number] => [v.x, v.z];
    for (const y of [bottom, top]) {
        const first = new THREE.Vector3(footprint[0].x, y, footprint[0].z);
        for (let i = 1; i < footprint.length - 1; i++) {
            triangle(first, new THREE.Vector3(footprint[i].x, y, footprint[i].z), new THREE.Vector3(footprint[i + 1].x, y, footprint[i + 1].z), flat);
        }
    }

    const geometry = new THREE.BufferGeometry();
    geometry.setAttribute('position', new THREE.Float32BufferAttribute(positions, 3));
    geometry.setAttribute('uv', new THREE.Float32BufferAttribute(uvs, 2));
    geometry.computeVertexNormals();
    const mesh = new THREE.Mesh(geometry, material);
    mesh.castShadow = true;
    mesh.receiveShadow = true;
    return mesh;
}

/**
 * Upright solid between two faces given as polylines of the same number of points (x, z in the parent's frame,
 * metres), `right` and `left`, from their start to their end, from `bottom` to `top`: a piece of a curved wall. The
 * faces are smooth (normals averaged at the joints), the ends and the top and bottom flat. UVs are in metres: along
 * each face and up, top and bottom in x/z.
 */
export function curvedPrismAt(
    material: THREE.Material,
    right: readonly {x: number; z: number}[],
    left: readonly {x: number; z: number}[],
    bottom: number,
    top: number,
): THREE.Mesh {
    const positions: number[] = [];
    const normals: number[] = [];
    const uvs: number[] = [];
    type Vertex = {x: number; y: number; z: number; normal: THREE.Vector3; uv: [number, number]};
    const triangle = (a: Vertex, b: Vertex, c: Vertex, facing: THREE.Vector3): void => {
        // Wound counter-clockwise seen from the side the face looks to.
        const normal = new THREE.Vector3(b.x - a.x, b.y - a.y, b.z - a.z).cross(new THREE.Vector3(c.x - a.x, c.y - a.y, c.z - a.z));
        for (const v of normal.dot(facing) >= 0 ? [a, b, c] : [a, c, b]) {
            positions.push(v.x, v.y, v.z);
            normals.push(v.normal.x, v.normal.y, v.normal.z);
            uvs.push(...v.uv);
        }
    };
    const quad = (p: Vertex, q: Vertex, r: Vertex, s: Vertex, facing: THREE.Vector3): void => {
        triangle(p, q, r, facing);
        triangle(p, r, s, facing);
    };

    // A face's normals at its points, away from the other face.
    const faceNormals = (face: readonly {x: number; z: number}[], other: readonly {x: number; z: number}[]): THREE.Vector3[] =>
        face.map((p, i) => {
            const before = face[Math.max(i - 1, 0)];
            const after = face[Math.min(i + 1, face.length - 1)];
            const normal = new THREE.Vector3(after.z - before.z, 0, -(after.x - before.x)).normalize();
            return normal.dot(new THREE.Vector3(p.x - other[i].x, 0, p.z - other[i].z)) < 0 ? normal.negate() : normal;
        });
    for (const [face, other] of [
        [right, left],
        [left, right],
    ] as const) {
        const faceNormal = faceNormals(face, other);
        let u = 0;
        for (let i = 1; i < face.length; i++) {
            const p = face[i - 1];
            const q = face[i];
            const step = Math.hypot(q.x - p.x, q.z - p.z);
            const at = (point: {x: number; z: number}, y: number, normal: THREE.Vector3, along: number): Vertex => ({
                ...point,
                y,
                normal,
                uv: [along, y],
            });
            const [n0, n1] = [faceNormal[i - 1], faceNormal[i]];
            quad(at(p, bottom, n0, u), at(q, bottom, n1, u + step), at(q, top, n1, u + step), at(p, top, n0, u), n0.clone().add(n1));
            u += step;
        }
    }

    const flat = (point: {x: number; z: number}, y: number, normal: THREE.Vector3): Vertex => ({...point, y, normal, uv: [point.x, point.z]});
    const last = right.length - 1;
    for (const [i, towards] of [
        [0, 1],
        [last, last - 1],
    ] as const) {
        // An end, facing away from the rest of the piece.
        const r = right[i];
        const l = left[i];
        const normal = new THREE.Vector3(l.z - r.z, 0, -(l.x - r.x)).normalize();
        const inside = {x: (right[towards].x + left[towards].x) / 2 - (r.x + l.x) / 2, z: (right[towards].z + left[towards].z) / 2 - (r.z + l.z) / 2};
        if (normal.x * inside.x + normal.z * inside.z > 0) {
            normal.negate();
        }
        const side = (point: {x: number; z: number}, y: number): Vertex => ({...point, y, normal, uv: [Math.hypot(point.x - r.x, point.z - r.z), y]});
        quad(side(r, bottom), side(l, bottom), side(l, top), side(r, top), normal);
    }
    for (const [y, normal] of [
        [top, new THREE.Vector3(0, 1, 0)],
        [bottom, new THREE.Vector3(0, -1, 0)],
    ] as const) {
        for (let i = 1; i < right.length; i++) {
            quad(flat(right[i - 1], y, normal), flat(right[i], y, normal), flat(left[i], y, normal), flat(left[i - 1], y, normal), normal);
        }
    }

    const geometry = new THREE.BufferGeometry();
    geometry.setAttribute('position', new THREE.Float32BufferAttribute(positions, 3));
    geometry.setAttribute('normal', new THREE.Float32BufferAttribute(normals, 3));
    geometry.setAttribute('uv', new THREE.Float32BufferAttribute(uvs, 2));
    const mesh = new THREE.Mesh(geometry, material);
    mesh.castShadow = true;
    mesh.receiveShadow = true;
    return mesh;
}
