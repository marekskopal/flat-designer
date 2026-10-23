import {Floor, Plot, Scene, Wall} from '@app/models/scene';
import * as THREE from 'three';
import {describe, expect, it} from 'vitest';

import {MaterialLibrary} from './materials';
import {buildContextFloor, floorOffset, floorPoints, floorsBelow, stackGround} from './stack';

const wall = (id: number, startX: number, startY: number, endX: number, endY: number): Wall => ({
    id,
    name: `Wall ${id}`,
    type: 'Exterior',
    startX,
    startY,
    endX,
    endY,
    thickness: 300,
    height: 2600,
    materialId: null,
    leftFinishes: null,
    rightFinishes: null,
    openings: [],
});

const floor = (id: number, level: number, elevation: number, fields: Partial<Scene> = {}): Scene =>
    ({
        floor: {id, name: `Floor ${id}`, level, elevation, slabThickness: level <= 0 ? 300 : 250} satisfies Floor,
        materials: [],
        rooms: [],
        walls: [wall(id * 10, 0, 0, 4000, 0), wall(id * 10 + 1, 4000, 0, 4000, 3000)],
        items: [],
        ...fields,
    }) as Scene;

const basement = floor(1, -1, -2900);
const ground = floor(2, 0, 0);
const first = floor(3, 1, 2850);
const attic = floor(4, 2, 5700);

describe('floorsBelow', () => {
    it('keeps the floors under the shown one, lowest first', () => {
        expect(floorsBelow([attic, ground, first, basement], first).map((s) => s.floor.id)).toEqual([1, 2]);
        expect(floorsBelow([attic, ground, first, basement], basement)).toEqual([]);
        // The shown floor itself is left out, also when the stack still holds it.
        expect(floorsBelow([first], first)).toEqual([]);
    });
});

describe('floorOffset', () => {
    it('measures a floor against the shown one', () => {
        expect(floorOffset(ground, first)).toBe(-2850);
        expect(floorOffset(basement, first)).toBe(-5750);
    });
});

describe('floorPoints', () => {
    it("lists the walls' ends and the rooms' corners", () => {
        expect(floorPoints({walls: [wall(1, 0, 0, 4000, 0)], rooms: []})).toEqual([
            [0, 0],
            [4000, 0],
        ]);
    });
});

describe('stackGround', () => {
    it('lies under the lowest ground of the shown floor and the floors under it', () => {
        expect(stackGround(first, [])).toBe(0);
        expect(stackGround(first, [ground])).toBe(-2850);
        const sunken = floor(2, 0, 0, {plots: [{id: 1, polygon: [], groundMaterialId: null, groundLevel: -400} as unknown as Plot]});
        expect(stackGround(first, [sunken])).toBe(-3250);
    });
});

describe('buildContextFloor', () => {
    const materials = new MaterialLibrary([], 1);

    it('draws the floor tagged as context, with its walls and slab', () => {
        const group = buildContextFloor(ground, materials, {models: new Map(), modelLibrary: null, pictures: null});
        expect(group.userData).toEqual({contextFloor: 2});
        const names: string[] = [];
        group.traverse((o) => names.push(o.name));
        expect(names).toContain('slab');
        expect(names).toContain('ceilings');
        const box = new THREE.Box3().setFromObject(group);
        // The walls stand 2.6 m high on the floor, the slab hangs 300 mm under it.
        expect(box.max.y).toBeCloseTo(2.6);
        expect(box.min.y).toBeCloseTo(-0.3);
    });
});
