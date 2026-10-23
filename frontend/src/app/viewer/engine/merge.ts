import * as THREE from 'three';
import {mergeGeometries} from 'three/examples/jsm/utils/BufferGeometryUtils.js';

/** `userData` keys a merged mesh may lose: only names. Any other key marks an object the engine reads or toggles. */
const DroppableKeys: ReadonlySet<string> = new Set(['partName']);

/**
 * Merges the plain meshes of an element (an item, a wall, a room's finishes) that share a material and shadow settings into
 * one mesh each, so the element draws in a few calls rather than one per part (FD-58). Objects the engine reads by their
 * `userData` are left as they are: a mesh so tagged stays (a bulb, a picture face, a ceiling part, a model's shared mesh), and
 * a group so tagged is merged on its own (an opening in its wall, a door leaf, which swings as a whole). Mirrored meshes,
 * hidden objects and meshes with several materials stay too. The merged meshes are named after their parts. Returns how many
 * meshes were merged away.
 */
export function mergeStatic(root: THREE.Object3D): number {
    root.updateMatrixWorld(true);
    const toRoot = root.matrixWorld.clone().invert();
    const buckets = new Map<string, {mesh: THREE.Mesh; matrix: THREE.Matrix4}[]>();
    const nested: THREE.Object3D[] = [];

    const visit = (parent: THREE.Object3D): void => {
        for (const child of parent.children) {
            if (isTagged(child)) {
                nested.push(child);
                continue;
            }
            if (!child.visible) {
                // Hidden with all it holds; merged into the root, it would show.
                continue;
            }
            const matrix = toRoot.clone().multiply(child.matrixWorld);
            if (isPlainMesh(child) && matrix.determinant() > 0) {
                const key = bucketKey(child);
                let bucket = buckets.get(key);
                if (bucket === undefined) {
                    bucket = [];
                    buckets.set(key, bucket);
                }
                bucket.push({mesh: child, matrix});
            }
            visit(child);
        }
    };
    visit(root);

    let merged = 0;
    const replaced = new Set<THREE.BufferGeometry>();
    for (const bucket of buckets.values()) {
        if (bucket.length < 2) {
            continue;
        }
        const parts = bucket.map(({mesh, matrix}) => mesh.geometry.clone().applyMatrix4(matrix));
        const geometry = mergeGeometries(parts);
        parts.forEach((part) => part.dispose());
        if (geometry === null) {
            continue;
        }
        const first = bucket[0].mesh;
        const mesh = new THREE.Mesh(geometry, first.material);
        mesh.name = [...new Set(bucket.map(({mesh: m}) => partName(m)))].join(', ');
        mesh.castShadow = first.castShadow;
        mesh.receiveShadow = first.receiveShadow;
        mesh.renderOrder = first.renderOrder;
        for (const {mesh: part} of bucket) {
            part.removeFromParent();
            replaced.add(part.geometry);
        }
        root.add(mesh);
        merged += bucket.length - 1;
    }
    if (replaced.size > 0) {
        // A geometry may still be drawn by a mesh that stayed (a section-fill twin shares its body's).
        root.traverse((o) => {
            if (o instanceof THREE.Mesh) {
                replaced.delete(o.geometry as THREE.BufferGeometry);
            }
        });
        replaced.forEach((geometry) => geometry.dispose());
        pruneEmptyGroups(root);
    }
    return merged + nested.reduce((sum, child) => sum + mergeStatic(child), 0);
}

function isTagged(object: THREE.Object3D): boolean {
    return Object.keys(object.userData).some((key) => !DroppableKeys.has(key));
}

function isPlainMesh(object: THREE.Object3D): object is THREE.Mesh {
    // Not a skinned or instanced mesh, nor a line.
    return (
        object instanceof THREE.Mesh &&
        object.type === 'Mesh' &&
        !Array.isArray(object.material) &&
        Object.keys(object.geometry.morphAttributes).length === 0
    );
}

/** Meshes merge when they draw alike: the same material, shadows and order, and geometries of the same attributes. */
function bucketKey(mesh: THREE.Mesh): string {
    const geometry = mesh.geometry;
    const attributes = Object.keys(geometry.attributes)
        .sort()
        .map((name) => {
            const attribute = geometry.getAttribute(name);
            return `${name}:${attribute.itemSize}:${attribute.normalized}:${attribute.array.constructor.name}`;
        });
    const material = mesh.material as THREE.Material;
    return [material.uuid, mesh.castShadow, mesh.receiveShadow, mesh.renderOrder, geometry.index === null, ...attributes].join('|');
}

function partName(mesh: THREE.Mesh): string {
    const name: unknown = mesh.userData['partName'];
    return typeof name === 'string' ? name : mesh.name;
}

/** Drops the untagged groups left without children by the merge. */
function pruneEmptyGroups(object: THREE.Object3D): void {
    for (const child of [...object.children]) {
        pruneEmptyGroups(child);
        if (child.type === 'Group' && child.children.length === 0 && !isTagged(child)) {
            child.removeFromParent();
        }
    }
}
