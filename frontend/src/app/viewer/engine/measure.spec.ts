import {Wall} from '@app/models/scene';
import {describe, expect, it} from 'vitest';

import {measureClick, measureStatus, NoMeasurement, snapMeasurePoint} from './measure';
import {wallJoints} from './plan-geometry';

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

/** An L of two 300 mm walls meeting at (−150, −150): south along y = −150, west along x = −150. */
const walls = [wall(1, -150, -150, 4150, -150), wall(2, -150, 3150, -150, -150)];
const options = {radius: 100, walls, joints: wallJoints(walls), from: null};

describe('snapMeasurePoint', () => {
    it("snaps onto the ends and centre lines of the floor below's walls", () => {
        const ghostWalls = [wall(9, 1000, 6000, 5000, 6000)];
        expect(snapMeasurePoint({x: 4970, y: 6040}, {...options, ghostWalls})).toEqual({point: {x: 5000, y: 6000}, kind: 'point'});
        expect(snapMeasurePoint({x: 2500, y: 5950}, {...options, ghostWalls})).toEqual({point: {x: 2500, y: 6000}, kind: 'line'});
    });

    it('snaps onto wall ends and the corners where the faces meet', () => {
        expect(snapMeasurePoint({x: 4120, y: -130}, options)).toEqual({point: {x: 4150, y: -150}, kind: 'point'});
        // Inner corner of the mitred joint.
        expect(snapMeasurePoint({x: 40, y: -30}, options)).toEqual({point: {x: 0, y: 0}, kind: 'point'});
        // Outer corner.
        expect(snapMeasurePoint({x: -260, y: -330}, options)).toEqual({point: {x: -300, y: -300}, kind: 'point'});
    });

    it('snaps onto the faces and the centre line', () => {
        expect(snapMeasurePoint({x: 2000, y: 40}, options)).toEqual({point: {x: 2000, y: 0}, kind: 'line'});
        expect(snapMeasurePoint({x: 2000, y: -340}, options)).toEqual({point: {x: 2000, y: -300}, kind: 'line'});
        expect(snapMeasurePoint({x: 2000, y: -170}, options)).toEqual({point: {x: 2000, y: -150}, kind: 'line'});
    });

    it('lines the second point up with the first, and measures freely with a radius of 0', () => {
        expect(snapMeasurePoint({x: 2040, y: 1500}, {...options, from: {x: 2000, y: 0}})).toEqual({point: {x: 2000, y: 1500}, kind: 'axis'});
        expect(snapMeasurePoint({x: 40.4, y: -29.6}, {...options, radius: 0})).toEqual({point: {x: 40, y: -30}, kind: 'grid'});
    });
});

describe('measureClick / measureStatus', () => {
    it('places two points, then starts over', () => {
        let m = measureClick(NoMeasurement, {x: 0, y: 0});
        expect(measureStatus({...m, cursor: {x: 3000, y: 4000}})).toEqual({points: 1, distance: 5000, dx: 3000, dy: 4000, snap: null});

        m = measureClick(m, {x: -300, y: 400});
        expect(measureStatus(m)).toEqual({points: 2, distance: 500, dx: -300, dy: 400, snap: null});

        m = measureClick(m, {x: 10, y: 10});
        expect(measureStatus(m)).toEqual({points: 1, distance: 0, dx: 0, dy: 0, snap: null});
        expect(measureStatus(NoMeasurement).distance).toBeNull();
    });
});
