import {Opening, Wall} from '@app/models/scene';
import * as THREE from 'three';
import {describe, expect, it} from 'vitest';

import {buildWall} from './building';
import {MaterialLibrary} from './materials';
import {WallEnds} from './plan-geometry';

const niche: Opening = {
    id: 7,
    name: 'Niche',
    type: 'Niche',
    offset: 0,
    width: 800,
    height: 2600,
    sillHeight: 0,
    leafWidth: null,
    hinge: null,
    side: 'Left',
    entrance: false,
    depth: 100,
};

const wall: Wall = {
    id: 1,
    name: 'Wall',
    type: 'Interior',
    startX: 0,
    startY: 0,
    endX: 3000,
    endY: 0,
    thickness: 300,
    height: 2600,
    materialId: null,
    openings: [niche],
};

describe('buildWall', () => {
    const materials = new MaterialLibrary([], 1);
    const mm = (v: number): number => Math.round(v * 1000) + 0;

    it('mitres the piece behind a niche at a corner, following the mitre at its depth, and tags it with the niche', () => {
        // The left face runs 150 on to the corner, the right face is cut 150 short.
        const ends: WallEnds = {startLeft: 150, startRight: -150, endLeft: 0, endRight: 0};
        const group = buildWall(wall, materials, materials.sectionFill(), ends);
        const back = group.children.find((o) => o.userData['openingId'] === 7) as THREE.Mesh;
        const box = new THREE.Box3().setFromObject(back);

        // 100 of the 300 mm wall is cut away on the left: the piece spans z −50…150 and reaches −50 at its recessed face.
        expect([mm(box.min.z), mm(box.max.z)]).toEqual([-50, 150]);
        expect(mm(box.min.x)).toBe(-50);
        expect(mm(box.max.x)).toBe(800);
        expect([mm(box.min.y), mm(box.max.y)]).toEqual([0, 2600]);
    });

    it('keeps a square end square', () => {
        const group = buildWall(wall, materials, materials.sectionFill());
        const back = group.children.find((o) => o.userData['openingId'] === 7) as THREE.Mesh;
        const box = new THREE.Box3().setFromObject(back);
        expect([mm(box.min.x), mm(box.max.x), mm(box.min.z), mm(box.max.z)]).toEqual([0, 800, -50, 150]);
    });
});
