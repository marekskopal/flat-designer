import {Wall} from '@app/models/scene';
import {describe, expect, it} from 'vitest';

import {closesRun, directionOf, parseDrawInput, pointAt, snapDrawPoint} from './drawing';

const wall: Wall = {
    id: 1,
    name: 'South',
    type: 'Exterior',
    startX: 0,
    startY: 0,
    endX: 4000,
    endY: 0,
    thickness: 300,
    height: 2600,
    materialId: null,
    leftFinishes: null,
    rightFinishes: null,
    openings: [],
};

describe('snapDrawPoint', () => {
    const options = {grid: 10, radius: 100, walls: [wall], points: []};

    it('snaps onto wall ends, then onto a wall centre line for a T-joint', () => {
        expect(snapDrawPoint({x: 3960, y: 40}, options)).toEqual({point: {x: 4000, y: 0}, kind: 'point'});
        expect(snapDrawPoint({x: 2013, y: 60}, options)).toEqual({point: {x: 2013, y: 0}, kind: 'line'});
    });

    it('turns a segment to a multiple of 15° from the previous point, with its length on the grid', () => {
        const from = {x: 10000, y: 10000};
        const snapped = snapDrawPoint({x: 10000 + 2121, y: 10000 + 2160}, {...options, walls: [], points: [from]});
        expect(snapped.kind).toBe('angle');
        expect(directionOf(from, snapped.point)).toBeCloseTo(45, 1);
    });

    it('places points freely with a radius of 0', () => {
        expect(snapDrawPoint({x: 3960.4, y: 40.6}, {...options, grid: 1, radius: 0})).toEqual({point: {x: 3960, y: 41}, kind: 'grid'});
    });
});

describe('pointAt / directionOf', () => {
    it('measures degrees counter-clockwise from east', () => {
        expect(pointAt({x: 0, y: 0}, 3000, 90)).toEqual({x: 0, y: 3000});
        expect(pointAt({x: 100, y: 100}, 1000, 180)).toEqual({x: -900, y: 100});
        expect(directionOf({x: 0, y: 0}, {x: 0, y: -10})).toBe(270);
    });
});

describe('parseDrawInput', () => {
    it('reads a length, optionally with an angle', () => {
        expect(parseDrawInput('3200')).toEqual({length: 3200, angle: null});
        expect(parseDrawInput(' 3200 < 90 ')).toEqual({length: 3200, angle: 90});
        expect(parseDrawInput('1200.4<-45')).toEqual({length: 1200, angle: -45});
    });

    it('rejects anything else', () => {
        expect(parseDrawInput('')).toBeNull();
        expect(parseDrawInput('0')).toBeNull();
        expect(parseDrawInput('3200<')).toBeNull();
        expect(parseDrawInput('abc')).toBeNull();
    });
});

describe('closesRun', () => {
    it('closes on the first point once there are three', () => {
        const points = [
            {x: 0, y: 0},
            {x: 4000, y: 0},
        ];
        expect(closesRun(points, {x: 0, y: 0})).toBe(false);
        expect(closesRun([...points, {x: 4000, y: 3000}], {x: 0, y: 0})).toBe(true);
    });
});
