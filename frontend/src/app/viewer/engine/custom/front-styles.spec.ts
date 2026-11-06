import * as THREE from 'three';
import {describe, expect, it} from 'vitest';

import {buildFrontBody, frontFrame, frontStyleOf} from './front-styles';

const front = {x: 0, y: 0, z: 0, w: 596, h: 700, d: 19};
const material = new THREE.MeshStandardMaterial();
const frameMaterial = new THREE.MeshStandardMaterial();

describe('front styles', () => {
    it('takes the frame width of the style, held to leave the middle, as the cut list cuts it', () => {
        expect(frontFrame('shaker', 0, 596, 700)).toBe(70);
        expect(frontFrame('glass', undefined, 596, 700)).toBe(40);
        expect(frontFrame('shaker', 60, 596, 700)).toBe(60);
        expect(frontFrame('shaker', null, 100, 700)).toBe(40);
        expect(frontFrame('flat', 50, 596, 700)).toBe(50);
    });

    it('reads unknown styles as flat', () => {
        expect(frontStyleOf({style: 'shaker'})).toBe('shaker');
        expect(frontStyleOf({style: 'ornate'})).toBe('flat');
        expect(frontStyleOf({})).toBe('flat');
    });

    it('builds a flat slab, a shaker frame round a recessed panel and a frame round glass', () => {
        expect(buildFrontBody(front, 'flat', 0, material, frameMaterial)).toHaveLength(1);

        const shaker = buildFrontBody(front, 'shaker', 0, material, frameMaterial);
        expect(shaker.map((mesh) => mesh.material)).toEqual([frameMaterial, frameMaterial, frameMaterial, frameMaterial, material]);
        const panel = new THREE.Box3().setFromObject(shaker[4]);
        expect(Math.round(panel.max.z * 1000)).toBe(13);

        const glass = buildFrontBody(front, 'glass', 0, material, frameMaterial);
        expect(glass).toHaveLength(5);
        expect((glass[4].material as THREE.Material).transparent).toBe(true);
    });

    it('stands flutes on a fluted front, about 30 mm apart', () => {
        expect(buildFrontBody(front, 'fluted', 0, material, frameMaterial)).toHaveLength(1 + 20);
    });
});
