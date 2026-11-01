import {Plot, Room} from '@app/models/scene';
import * as THREE from 'three';
import {describe, expect, it} from 'vitest';

import {stairPaths} from './stairs';
import {floorLevels, Walkthrough} from './walkthrough';

const square = (x: number, y: number, side: number): Room['polygon'] => [[x, y], [x + side, y], [x + side, y + side], [x, y + side]];
const room = (polygon: Room['polygon'], floorElevation?: number): Room => ({
    id: 1,
    code: 'R',
    name: 'Room',
    type: 'Terrace',
    polygon,
    area: 0,
    ceilingHeight: null,
    floorMaterialId: null,
    wallFinishes: [],
    tileOriginX: null,
    tileOriginY: null,
    floorElevation,
});
const plot: Plot = {id: 1, name: 'Plot', polygon: square(-5000, -5000, 20000), area: 400, groundMaterialId: null, groundLevel: -300};

describe('floorLevels', () => {
    const levels = floorLevels({rooms: [room(square(0, 0, 3000)), room(square(3000, 0, 2000), -150)], plots: [plot]});

    it('stands on the floor of the room underfoot', () => {
        expect(levels({x: 1000, y: 1000})).toBe(0);
        expect(levels({x: 4000, y: 1000})).toBe(-150);
    });

    it('takes the smallest of overlapping rooms, whichever comes first', () => {
        const garden = room(square(-1000, -1000, 8000));
        const terrace = room(square(0, 0, 2000), -150);
        expect(floorLevels({rooms: [garden, terrace]})({x: 1000, y: 1000})).toBe(-150);
        expect(floorLevels({rooms: [terrace, garden]})({x: 1000, y: 1000})).toBe(-150);
        expect(floorLevels({rooms: [garden, terrace]})({x: 5000, y: 5000})).toBe(0);
    });

    it('stands on the plot outside the rooms, and on the floor level beyond it', () => {
        expect(levels({x: 8000, y: 8000})).toBe(-300);
        expect(levels({x: 30000, y: 0})).toBe(0);
        expect(floorLevels({rooms: []})({x: 0, y: 0})).toBe(0);
    });
});

describe('floorLevels on a sloping plot', () => {
    // 3 m down from west to east over 20 m (8.5°), and a 6 m cliff of 45° beyond x 20000.
    const slope: Plot = {
        ...plot,
        polygon: square(0, 0, 30000),
        terrain: [[0, 0, 3000], [0, 30000, 3000], [20000, 0, 0], [20000, 30000, 0], [26000, 0, -6000], [26000, 30000, -6000]],
    };
    const garden = {...room(square(4000, 4000, 2000), 50), type: 'Garden' as const};
    const levels = floorLevels({rooms: [garden, room(square(10000, 10000, 2000), 1000)], plots: [slope]});

    it('follows the ground, a garden on it and a terrace at its own level', () => {
        expect(levels({x: 2000, y: 1000})).toBeCloseTo(2700);
        expect(levels({x: 5000, y: 5000})).toBeCloseTo(2300);
        expect(levels({x: 11000, y: 11000})).toBe(1000);
    });

    it('stops at ground steeper than 35°, not on gentle slopes or off it', () => {
        expect(levels.tooSteep?.({x: 2000, y: 1000}, {x: 2100, y: 1000})).toBe(false);
        expect(levels.tooSteep?.({x: 21000, y: 1000}, {x: 21100, y: 1000})).toBe(true);
        expect(levels.tooSteep?.({x: 11000, y: 11000}, {x: 11100, y: 11000})).toBe(false);
        expect(floorLevels({rooms: [], plots: [plot]}).tooSteep).toBeUndefined();
        // Off a draped garden onto the plot's ground on the cliff: still the ground.
        const bank = floorLevels({rooms: [{...room(square(19000, 0, 2000), 0), type: 'Garden'}], plots: [slope]});
        expect(bank.tooSteep?.({x: 20950, y: 1000}, {x: 21050, y: 1000})).toBe(true);
        // Stepping off a terrace at its own level onto the ground is a step, not a slope.
        expect(levels.tooSteep?.({x: 11950, y: 11000}, {x: 12050, y: 11000})).toBe(false);
    });
});

describe('Walkthrough between floors', () => {
    const start = (): {walk: Walkthrough; camera: THREE.PerspectiveCamera; steps: number[]} => {
        const camera = new THREE.PerspectiveCamera();
        const walk = new Walkthrough(camera, document.createElement('div'), [], {x: 1000, y: 2000}, 0.5);
        const steps: number[] = [];
        walk.onFloor = (step): void => void steps.push(step);
        return {walk, camera, steps};
    };
    const press = (code: string, repeat = false): void => void document.dispatchEvent(new KeyboardEvent('keydown', {code, repeat}));

    it('asks for the floor above or below once per press of PageUp or PageDown', () => {
        const {walk, steps} = start();
        press('PageUp');
        press('PageUp', true);
        press('PageDown');
        walk.dispose();
        expect(steps).toEqual([1, -1]);
    });

    it('moves the walker to a point, keeping the view unless given a direction', () => {
        const {walk, camera} = start();
        const yaw = camera.rotation.y;
        walk.moveTo({x: 4000, y: 3000});
        expect(walk.where()).toEqual({x: 4000, y: 3000});
        expect(camera.position.x).toBeCloseTo(4);
        expect(camera.position.z).toBeCloseTo(-3);
        expect(camera.rotation.y).toBeCloseTo(yaw);
        walk.moveTo({x: 0, y: 0}, Math.PI);
        expect(camera.getWorldDirection(new THREE.Vector3()).z).toBeCloseTo(1);
        walk.dispose();
    });
});

describe('floorLevels on stairs', () => {
    const stair = {
        id: 1, name: 'Stairs', shape: 'Straight', x: 1000, y: 500, rotation: 0, width: 900, going: 270, risers: 4, firstFlight: null,
        turn: 'Left', gap: 100, construction: 'Solid', railing: 'Right', treadMaterialId: null, structureMaterialId: null,
    } as const;
    const hall = room(square(0, 0, 3000));

    it("climbs the floor's own stairs over the room's floor", () => {
        const levels = floorLevels({rooms: [hall]}, stairPaths({stairs: [stair], stairRise: 720, stairsBelow: null}));
        expect(levels({x: 1000, y: 905})).toBeCloseTo(360);
        expect(levels({x: 2500, y: 905})).toBe(0);
    });

    it('goes down into the void of the stairs from below, under the floor by their rise', () => {
        const levels = floorLevels({rooms: [hall]}, stairPaths({stairs: [], stairRise: 2600, stairsBelow: {floorId: 2, rise: 720, stairs: [stair]}}));
        expect(levels({x: 1000, y: 1310})).toBeCloseTo(0);
        expect(levels({x: 1000, y: 500})).toBeCloseTo(-720);
    });
});
