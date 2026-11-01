import {Room, Scene, Stair, Wall} from '@app/models/scene';
import {describe, expect, it} from 'vitest';

import {buildingLevels, floorAt, floorColliders, walkFloors} from './building-walk';
import {collideCircle, wallColliders} from './plan-geometry';

/** 16 risers of 185 mm over 2960 mm, 15 treads of 270: the walking line runs north from (1000, 2000) to (1000, 6050). */
const stair: Stair = {
    id: 1,
    name: 'Stairs',
    shape: 'Straight',
    x: 1000,
    y: 2000,
    rotation: 0,
    width: 900,
    going: 270,
    risers: 16,
    firstFlight: null,
    turn: 'Left',
    gap: 100,
    construction: 'Solid',
    railing: 'Right',
    treadMaterialId: null,
    structureMaterialId: null,
};

const room = (id: number, name: string): Room => ({
    id,
    code: `${id}`,
    name,
    type: 'Living',
    polygon: [
        [0, 0],
        [8000, 0],
        [8000, 9000],
        [0, 9000],
    ],
    area: 72,
    perimeter: 34,
    ceilingHeight: 2600,
    floorMaterialId: null,
    wallFinishes: [],
    tileOriginX: null,
    tileOriginY: null,
});

/** A partition across the house at y 7000, on its floor only. */
const partition = (id: number): Wall => ({
    id,
    name: 'Partition',
    type: 'Interior',
    startX: 0,
    startY: 7000,
    endX: 8000,
    endY: 7000,
    thickness: 100,
    height: 2600,
    materialId: null,
    leftFinishes: null,
    rightFinishes: null,
    openings: [],
});

const ground: Scene = {
    floor: {id: 1, name: 'Ground floor', level: 0, elevation: 0, slabThickness: 300},
    materials: [],
    rooms: [room(1, 'Living')],
    walls: [partition(11)],
    items: [],
    stairs: [stair],
    stairRise: 2960,
};

const first: Scene = {
    floor: {id: 2, name: 'First floor', level: 1, elevation: 2960, slabThickness: 300},
    materials: [],
    rooms: [room(2, 'Hall')],
    walls: [partition(12)],
    items: [],
    stairsBelow: {floorId: 1, rise: 2960, stairs: [stair]},
};

/** Walks the points one after another, each looked up at the height of the one before, as the walkthrough does. */
const walk = (levels: ReturnType<typeof buildingLevels>, ys: number[], start: number): number[] => {
    let level = start;
    return ys.map((y) => (level = levels({x: 1000, y}, level)));
};

describe('walking all floors at once', () => {
    it('gives each floor a band of heights from 600 mm under its level', () => {
        const floors = walkFloors(ground, [first, ground]);
        expect(floors.map((f) => [f.scene.floor.id, f.offset])).toEqual([
            [1, 0],
            [2, 2960],
        ]);
        expect(floors.map((f) => f.band)).toEqual([
            [-Infinity, 2360],
            [2360, Infinity],
        ]);
        expect(floorAt(floors, 1000).scene.floor.id).toBe(1);
        expect(floorAt(floors, 2500).scene.floor.id).toBe(2);
        // Seen from the first floor, the ground floor lies below.
        expect(walkFloors(first, [ground]).map((f) => f.offset)).toEqual([-2960, 0]);
    });

    it('starts a floor\'s band under its lowest room, so a sunken one stays on its floor', () => {
        const sunken = {...first, rooms: [{...room(3, 'Sunken lounge'), floorElevation: -700}]};
        const floors = walkFloors(ground, [sunken]);
        expect(floors[1].band[0]).toBe(2960 - 700 - 600);
        expect(floorAt(floors, 2960 - 700).scene.floor.id).toBe(2);
        expect(buildingLevels(floors)({x: 4000, y: 4000}, 2960 - 700)).toBe(2960 - 700);
    });

    it('climbs the stair onto the floor above and back down without a switch', () => {
        const levels = buildingLevels(walkFloors(ground, [first]));
        const ys = Array.from({length: 50}, (_, i) => 1500 + i * 100);
        const up = walk(levels, ys, 0);
        expect(up[0]).toBe(0);
        // Rising all the way, never dropping back, and on the first floor past the top.
        expect(up.every((level, i) => i === 0 || level >= up[i - 1] - 1e-6)).toBe(true);
        expect(up[up.length - 1]).toBeCloseTo(2960);
        const down = walk(levels, [...ys].reverse(), 2960);
        expect(down.every((level, i) => i === 0 || level <= down[i - 1] + 1e-6)).toBe(true);
        expect(down[down.length - 1]).toBe(0);
    });

    it('stops the walker at a floor\'s walls only on that floor', () => {
        const floors = walkFloors(ground, [first]);
        const colliders = floors.flatMap((f) => floorColliders(f, wallColliders(f.scene.walls)));
        const beside = {x: 4000, y: 7000 - 50 - 150};
        // On the ground floor the partition there stops a walker of 200 mm radius; on the first, the first floor's does.
        expect(collideCircle(beside, 200, colliders, 0).y).toBeLessThan(beside.y);
        expect(collideCircle(beside, 200, colliders, 2960).y).toBeLessThan(beside.y);
        // Each partition alone stops only its own floor's walker.
        const groundOnly = floorColliders(floors[0], wallColliders(ground.walls));
        expect(collideCircle(beside, 200, groundOnly, 2960).y).toBeCloseTo(beside.y);
        expect(collideCircle(beside, 200, groundOnly, 0).y).toBeLessThan(beside.y);
    });
});
