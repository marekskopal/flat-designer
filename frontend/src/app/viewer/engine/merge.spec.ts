import * as THREE from 'three';
import {describe, expect, it, vi} from 'vitest';

import {mergeStatic} from './merge';

const wood = new THREE.MeshStandardMaterial();
const steel = new THREE.MeshStandardMaterial();

function box(material: THREE.Material, x: number, y = 0): THREE.Mesh {
    const mesh = new THREE.Mesh(new THREE.BoxGeometry(0.1, 0.1, 0.1), material);
    mesh.position.set(x, y, 0);
    return mesh;
}

function meshes(root: THREE.Object3D): THREE.Mesh[] {
    const found: THREE.Mesh[] = [];
    root.traverse((o) => {
        if (o instanceof THREE.Mesh) {
            found.push(o);
        }
    });
    return found;
}

function bounds(object: THREE.Object3D): number[] {
    object.updateMatrixWorld(true);
    const box3 = new THREE.Box3().setFromObject(object);
    return [...box3.min.toArray(), ...box3.max.toArray()].map((v) => Math.round(v * 1000));
}

describe('mergeStatic', () => {
    it('merges the plain meshes per material where they were', () => {
        const root = new THREE.Group();
        root.position.set(2, 0, 1);
        root.rotation.y = 0.5;
        const nested = new THREE.Group();
        nested.position.y = 0.4;
        nested.add(box(wood, 0.3), box(steel, 0.6));
        root.add(box(wood, 0), box(wood, 0.2), box(steel, -0.2), nested);
        const before = bounds(root);

        expect(mergeStatic(root)).toBe(3);

        const after = meshes(root);
        expect(after).toHaveLength(2);
        expect(new Set(after.map((m) => m.material))).toEqual(new Set([wood, steel]));
        expect(bounds(root)).toEqual(before);
        // The emptied group is gone.
        expect(root.children).toHaveLength(2);
    });

    it('names a merged mesh after its parts', () => {
        const root = new THREE.Group();
        const side = box(wood, 0);
        side.userData['partName'] = 'side';
        const top = box(wood, 0.2);
        top.userData['partName'] = 'top';
        root.add(side, top);
        mergeStatic(root);
        expect(meshes(root)[0].name).toBe('side, top');
    });

    it('leaves tagged meshes and merges tagged groups on their own', () => {
        const root = new THREE.Group();
        const bulb = box(wood, 0.5);
        bulb.userData['bulb'] = true;
        const leaf = new THREE.Group();
        leaf.userData['leaf'] = {openingId: 1};
        leaf.add(box(wood, 0), box(wood, 0.1));
        root.add(box(wood, -0.2), box(wood, -0.4), bulb, leaf);

        expect(mergeStatic(root)).toBe(2);

        expect(bulb.parent).toBe(root);
        expect(leaf.parent).toBe(root);
        expect(meshes(leaf)).toHaveLength(1);
        expect(meshes(root)).toHaveLength(3);
    });

    it('leaves hidden objects, mirrored meshes and multi-material ones', () => {
        const root = new THREE.Group();
        const hidden = box(wood, 0.2);
        hidden.visible = false;
        const mirrored = box(wood, 0.4);
        mirrored.scale.x = -1;
        const multi = new THREE.Mesh(new THREE.BoxGeometry(), [wood, steel]);
        root.add(box(wood, 0), hidden, mirrored, multi);

        expect(mergeStatic(root)).toBe(0);
        expect(meshes(root)).toHaveLength(4);
    });

    it('keeps a geometry another mesh still draws', () => {
        const root = new THREE.Group();
        const body = box(wood, 0);
        const twin = new THREE.Mesh(body.geometry, steel);
        twin.userData['castsNoShadow'] = true;
        const lone = box(wood, 0.3);
        const dispose = vi.spyOn(body.geometry, 'dispose');
        const loneDispose = vi.spyOn(lone.geometry, 'dispose');
        root.add(body, twin, lone);

        mergeStatic(root);

        expect(dispose).not.toHaveBeenCalled();
        expect(loneDispose).toHaveBeenCalled();
    });
});
