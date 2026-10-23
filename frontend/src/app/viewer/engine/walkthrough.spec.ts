import {Plot, Room} from '@app/models/scene';
import {describe, expect, it} from 'vitest';

import {floorLevels} from './walkthrough';

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
