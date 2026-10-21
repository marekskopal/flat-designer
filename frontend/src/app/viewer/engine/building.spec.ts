import {Material, Opening, Room, Wall} from '@app/models/scene';
import * as THREE from 'three';
import {describe, expect, it} from 'vitest';

import {buildWall, buildWallFinishes} from './building';
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
    leftFinishes: null,
    rightFinishes: null,
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

describe('buildWallFinishes', () => {
    const material = (id: number, code: string): Material => ({
        id,
        code,
        name: code,
        pattern: 'Plain',
        color: '#808080',
        roughness: 0.5,
        metalness: 0,
        texture: null,
        tileWidth: null,
        tileHeight: null,
    });
    const materials = new MaterialLibrary([material(1, 'herringbone'), material(2, 'terrazzo')], 1);
    const room: Room = {
        id: 1,
        code: 'B',
        name: 'Bathroom',
        type: 'Bathroom',
        polygon: [[0, 0], [2000, 0], [2000, 2000], [0, 2000]],
        area: 4,
        ceilingHeight: 2600,
        floorMaterialId: null,
        wallFinishes: [{materialId: 1, bottom: 0, top: 1200}],
        tileOriginX: null,
        tileOriginY: null,
    };
    // The south wall; its left face (start → end runs east) faces the room.
    const south = (leftFinishes: Wall['leftFinishes']): Wall => ({
        ...wall,
        id: 2,
        startX: -50,
        startY: -50,
        endX: 2050,
        endY: -50,
        thickness: 100,
        leftFinishes,
        openings: [],
    });
    const pieces = (group: THREE.Group, code: string): THREE.Mesh[] => {
        const meshes: THREE.Mesh[] = [];
        group.traverse((o) => {
            if (o instanceof THREE.Mesh && o.material === materials.byCode(code)) {
                meshes.push(o);
            }
        });
        return meshes;
    };

    it('lines a wall face with its own bands instead of the room\'s', () => {
        const group = buildWallFinishes(room, [south([{materialId: 2, bottom: 0, top: 2100}])], materials);
        const terrazzo = pieces(group, 'terrazzo');
        expect(terrazzo).toHaveLength(1);
        const box = new THREE.Box3().setFromObject(terrazzo[0]);
        expect([Math.round(box.min.x * 1000), Math.round(box.max.x * 1000), Math.round(box.max.y * 1000)]).toEqual([0, 2000, 2100]);
        // The other three edges keep the room's band.
        expect(pieces(group, 'herringbone')).toHaveLength(3);
    });

    it('leaves a face with an empty list bare, and gives the room\'s bands to a face without its own', () => {
        expect(pieces(buildWallFinishes(room, [south([])], materials), 'herringbone')).toHaveLength(3);
        expect(pieces(buildWallFinishes(room, [south(null)], materials), 'herringbone')).toHaveLength(4);
    });
});
