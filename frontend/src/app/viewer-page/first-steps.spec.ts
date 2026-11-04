import {Item, Opening, Room, Scene, Wall} from '@app/models/scene';
import {describe, expect, it} from 'vitest';

import {firstSteps} from './first-steps';

const empty: Scene = {
    floor: {id: 1, name: 'Ground floor', level: 0, elevation: 0, slabThickness: 300},
    revision: 0,
    materials: [],
    rooms: [],
    walls: [],
    items: [],
};
const wall = (openings: Partial<Opening>[]): Wall => ({id: 1, openings: openings as Opening[]}) as Wall;

const done = (scene: Scene, walked = false): string[] => firstSteps(scene, walked).filter((step) => step.done).map((step) => step.key);

describe('first steps', () => {
    it('starts with nothing done, in order', () => {
        expect(firstSteps(empty, false).map((step) => step.key)).toEqual(['walls', 'rooms', 'door', 'furniture', 'walk']);
        expect(done(empty)).toEqual([]);
    });

    it('ticks off what the floor has', () => {
        const scene: Scene = {...empty, walls: [wall([{type: 'Window'}])], rooms: [{id: 2} as Room], items: [{id: 3} as Item]};
        expect(done(scene)).toEqual(['walls', 'rooms', 'furniture']);
        expect(done(scene, true)).toEqual(['walls', 'rooms', 'furniture', 'walk']);
    });

    it('counts any door, a passage included, but not a window or a niche', () => {
        expect(done({...empty, walls: [wall([{type: 'Niche'}, {type: 'Window'}])]})).toEqual(['walls']);
        expect(done({...empty, walls: [wall([{type: 'Passage'}])]})).toEqual(['walls', 'door']);
        expect(done({...empty, walls: [wall([{type: 'SlidingDoor'}])]})).toEqual(['walls', 'door']);
    });
});
