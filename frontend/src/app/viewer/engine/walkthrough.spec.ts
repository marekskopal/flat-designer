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
