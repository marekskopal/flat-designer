import {Item} from '@app/models/scene';
import * as THREE from 'three';
import {describe, expect, it} from 'vitest';

import {MaterialLibrary} from './materials';
import {buildStructureItemBody} from './structure';

const item = (kind: Item['kind'], params: Record<string, unknown> = {}): Item =>
    ({id: 1, roomId: null, kind, name: kind, x: 0, y: 0, z: 0, width: 400, depth: 300, height: 2600, rotation: 0, materialId: null, params}) as Item;

const meshes = (object: THREE.Object3D): THREE.Mesh[] => {
    const found: THREE.Mesh[] = [];
    object.traverse((o) => {
        if (o instanceof THREE.Mesh) {
            found.push(o);
        }
    });
    return found;
};

describe('buildStructureItemBody', () => {
    const materials = new MaterialLibrary([], 1);

    it('builds nothing for other kinds', () => {
        expect(buildStructureItemBody(item('Box'), materials)).toBeNull();
    });

    it('fills a cut column with the section fill, as walls', () => {
        const column = buildStructureItemBody(item('Column'), materials)!;
        const [body, cap] = meshes(column);
        expect(body.geometry).toBeInstanceOf(THREE.BoxGeometry);
        expect(cap.geometry).toBe(body.geometry);
        expect(cap.material).toBe(materials.sectionFill());
        const box = new THREE.Box3().setFromObject(column);
        expect(box.min.toArray().map((v) => Math.round(v * 1000))).toEqual([-200, 0, -150]);
        expect(box.max.toArray().map((v) => Math.round(v * 1000))).toEqual([200, 2600, 150]);
    });

    it('draws a round column as a cylinder filling width × depth', () => {
        const column = buildStructureItemBody(item('Column', {shape: 'round'}), materials)!;
        const [body, cap] = meshes(column);
        expect(body.geometry).toBeInstanceOf(THREE.CylinderGeometry);
        expect(cap.scale.toArray()).toEqual(body.scale.toArray());
        const box = new THREE.Box3().setFromObject(column);
        expect(Math.round(box.max.x * 1000)).toBe(200);
        expect(Math.round(box.max.z * 1000)).toBe(150);
    });

    it('marks a beam as part of the ceiling, under the item root that variants hide', () => {
        const beam = buildStructureItemBody(item('Beam'), materials)!;
        expect(beam.userData['ceilingPart']).toBeUndefined();
        expect(beam.children[0].userData['ceilingPart']).toBe(true);
    });
});
