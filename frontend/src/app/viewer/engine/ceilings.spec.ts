import {Opening, Room, Wall} from '@app/models/scene';
import {describe, expect, it} from 'vitest';

import {beamSpans, ceilingSteps, clipToBand, roomBeams, skirtingPieces, vaultBreaks, vaultFrame, vaultHeight, wallSpansAlongEdge} from './ceilings';

const room = (id: number, polygon: Room['polygon'], ceilingHeight: number | null, extra: Partial<Room> = {}): Room => ({
    id,
    code: `R${id}`,
    name: `Room ${id}`,
    type: 'Living',
    polygon,
    area: 0,
    ceilingHeight,
    floorMaterialId: null,
    wallFinishes: [],
    tileOriginX: null,
    tileOriginY: null,
    ...extra,
});

const wall = (id: number, start: [number, number], end: [number, number], openings: Opening[] = []): Wall => ({
    id,
    name: `Wall ${id}`,
    type: 'Interior',
    startX: start[0],
    startY: start[1],
    endX: end[0],
    endY: end[1],
    thickness: 100,
    height: 2600,
    materialId: null,
    openings,
});

const door = (offset: number, width: number): Opening => ({
    id: 1,
    name: 'Door',
    type: 'Door',
    offset,
    width,
    height: 2000,
    sillHeight: 0,
    leafWidth: null,
    hinge: 'Start',
    side: 'Left',
    entrance: false,
});

describe('vaultHeight', () => {
    const profile = {spacing: 550, rise: 130, beamWidth: 80, crown: 2920};

    it('springs from the beams and peaks halfway between them', () => {
        expect(vaultHeight(0, profile)).toBe(2790);
        expect(vaultHeight(40, profile)).toBe(2790);
        expect(vaultHeight(275, profile)).toBeCloseTo(2920, 6);
        expect(vaultHeight(550 + 275, profile)).toBeCloseTo(2920, 6);
        expect(vaultHeight(-275, profile)).toBeCloseTo(2920, 6);
    });

    it('breaks the ceiling at the beam edges and across each vault', () => {
        const breaks = vaultBreaks(0, 1100, profile, 2);
        expect(breaks).toEqual([0, 40, 275, 510, 590, 825, 1060, 1100]);
    });
});

describe('clipToBand', () => {
    it('cuts a square to the strip between two lines', () => {
        const square = [
            {x: 0, y: 0},
            {x: 1000, y: 0},
            {x: 1000, y: 1000},
            {x: 0, y: 1000},
        ];
        const strip = clipToBand(square, {x: 0, y: 1}, 200, 300);
        const ys = strip.map((p) => p.y);
        expect(Math.min(...ys)).toBe(200);
        expect(Math.max(...ys)).toBe(300);
        expect(strip).toHaveLength(4);
    });

    it('is empty outside the polygon', () => {
        expect(clipToBand([{x: 0, y: 0}, {x: 10, y: 0}, {x: 0, y: 10}], {x: 0, y: 1}, 50, 60)).toEqual([]);
    });
});

describe('beams', () => {
    it('finds where a beam line runs inside an L-shaped room', () => {
        const lShape: Room['polygon'] = [
            [0, 0],
            [4000, 0],
            [4000, 2000],
            [2000, 2000],
            [2000, 4000],
            [0, 4000],
        ];
        // Beams running along x (angle 0): the line y = 1000 crosses the whole room, y = 3000 only the upper arm.
        expect(beamSpans(lShape, vaultFrame(0), 1000)).toEqual([[0, 4000]]);
        expect(beamSpans(lShape, vaultFrame(0), 3000)).toEqual([[0, 2000]]);
    });

    it('lays a beam on every multiple of the spacing inside the room', () => {
        const vaulted = room(1, [[0, 0], [3000, 0], [3000, 1200], [0, 1200]], 2920, {vaultSpacing: 550, vaultAngle: 0});
        const beams = roomBeams(vaulted, {spacing: 550, rise: 130, beamWidth: 80, crown: 2920});
        // v across the beams is plan y for beams along x: y = 0, 550 and 1100.
        expect(beams.map((b) => b.start.y)).toEqual([550, 1100]);
        expect(beams[0].start.x).toBeCloseTo(0, 6);
        expect(beams[0].end.x).toBeCloseTo(3000, 6);
    });
});

describe('skirting', () => {
    it('runs along the wall on an edge and leaves its doorway free', () => {
        // A wall whose face (y = 50) is the room edge from (0, 50) to (4000, 50), with a door 1000..1900.
        const walls = [wall(1, [0, 0], [4000, 0], [door(1000, 900)])];
        expect(wallSpansAlongEdge({x: 0, y: 50}, {x: 4000, y: 50}, walls)).toEqual([[0, 4000]]);
        expect(skirtingPieces({x: 0, y: 50}, {x: 4000, y: 50}, walls)).toEqual([
            [0, 1000],
            [1900, 4000],
        ]);
    });

    it('skips edges without a wall', () => {
        expect(skirtingPieces({x: 0, y: 500}, {x: 4000, y: 500}, [wall(1, [0, 0], [4000, 0])])).toEqual([]);
    });
});

describe('ceilingSteps', () => {
    // A corridor (2510) beside a living room (2920) along x = 3000, with no wall between them.
    const living = room(1, [[0, 0], [3000, 0], [3000, 3000], [0, 3000]], 2920);
    const corridor = room(2, [[3000, 0], [5000, 0], [5000, 3000], [3000, 3000]], 2510);

    it('closes the step from the lower ceiling up to the higher one along the open edge', () => {
        expect(ceilingSteps([living, corridor], [])).toEqual([{a: {x: 3000, y: 3000}, b: {x: 3000, y: 0}, bottom: 2510, top: 2920}]);
    });

    it('draws nothing where a wall stands between them or the ceilings are level', () => {
        expect(ceilingSteps([living, corridor], [wall(1, [3050, -100], [3050, 3100])])).toEqual([]);
        expect(ceilingSteps([living, {...corridor, ceilingHeight: 2920}], [])).toEqual([]);
    });
});
