import {readFileSync} from 'node:fs';
import {resolve} from 'node:path';

import {Opening, PlanPoint, Room, Wall} from '@app/models/scene';
import {describe, expect, it} from 'vitest';

import {
    beamSpans,
    ceilingHeightAt,
    ceilingSteps,
    clipToBand,
    facadeSpans,
    roomBeams,
    skirtingPieces,
    vaultBreaks,
    vaultFrame,
    vaultHeight,
    wallFacesAlongEdge,
    wallSpansAlongEdge,
} from './ceilings';
import {wallGapsAlongEdge} from './plan-geometry';

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
    leftFinishes: null,
    rightFinishes: null,
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

interface WallSpansCase {
    name: string;
    edge: [[number, number], [number, number]];
    walls: (Pick<Wall, 'startX' | 'startY' | 'endX' | 'endY' | 'sagitta' | 'thickness'> & {
        openings: (Pick<Opening, 'offset' | 'width' | 'sillHeight' | 'height'> & Partial<Pick<Opening, 'type' | 'side'>>)[];
    })[];
    spans: [number, number][];
    gaps: {from: number; to: number; bottom: number; top: number; niche?: boolean}[];
    skirting: [number, number][];
    faces: {wall: number; side: 'Left' | 'Right'; from: number; to: number}[];
}

/** Shared with the backend twin (`WallFaceSpansTest`), which the quantities use. */
const wallSpansFixture = JSON.parse(readFileSync(resolve(process.cwd(), '../backend/tests/fixtures/wall-spans.json'), 'utf8')) as {
    cases: WallSpansCase[];
    facades: {
        name: string;
        walls: WallSpansCase['walls'];
        rooms: {polygon: PlanPoint[]; outdoor?: boolean}[];
        facades: {wall: number; side: 'Left' | 'Right'; spans: [number, number][]}[];
    }[];
};

describe('wall faces along an edge (shared fixture)', () => {
    const near = (actual: number[][], expected: number[][]): void => {
        expect(actual).toHaveLength(expected.length);
        expected.forEach((values, i) => values.forEach((value, j) => expect(actual[i][j]).toBeCloseTo(value, 2)));
    };

    it.each(wallSpansFixture.cases)('$name', (testCase) => {
        const [a, b] = testCase.edge.map(([x, y]) => ({x, y}));
        const walls = testCase.walls.map((w, i): Wall => {
            const openings = w.openings.map(
                (o): Opening => ({...door(o.offset, o.width), ...o, depth: o.type === 'Niche' ? 100 : null}),
            );
            return {...wall(i + 1, [w.startX, w.startY], [w.endX, w.endY], openings), thickness: w.thickness, sagitta: w.sagitta ?? 0};
        });

        near(wallSpansAlongEdge(a, b, walls), testCase.spans);
        near(skirtingPieces(a, b, walls), testCase.skirting);
        const faces = wallFacesAlongEdge(a, b, walls);
        expect(faces.map((face) => [face.wall.id, face.side])).toEqual(testCase.faces.map((face) => [walls[face.wall].id, face.side]));
        near(
            faces.map((face) => [face.from, face.to]),
            testCase.faces.map((face) => [face.from, face.to]),
        );
        near(
            wallGapsAlongEdge(a, b, walls).map((gap) => [gap.from, gap.to, gap.bottom, gap.top, gap.niche ? 1 : 0]),
            testCase.gaps.map((gap) => [gap.from, gap.to, gap.bottom, gap.top, gap.niche === true ? 1 : 0]),
        );
    });
});

describe('façades (shared fixture)', () => {
    it.each(wallSpansFixture.facades)('$name', (testCase) => {
        const walls = testCase.walls.map(
            (w, i): Wall => ({...wall(i + 1, [w.startX, w.startY], [w.endX, w.endY]), thickness: w.thickness, sagitta: w.sagitta ?? 0}),
        );
        const facades = facadeSpans(
            walls,
            testCase.rooms.map((r) => ({polygon: r.polygon, outdoor: r.outdoor === true})),
        );

        expect(facades.map((f) => [f.wall.id, f.side])).toEqual(testCase.facades.map((f) => [walls[f.wall].id, f.side]));
        facades.forEach((f, i) =>
            testCase.facades[i].spans.forEach(([from, to], k) => {
                expect(f.spans[k][0]).toBeCloseTo(from, 2);
                expect(f.spans[k][1]).toBeCloseTo(to, 2);
            }),
        );
    });
});

describe('ceilingSteps', () => {
    // A corridor (2510) beside a living room (2920) along x = 3000, with no wall between them.
    const living = room(1, [[0, 0], [3000, 0], [3000, 3000], [0, 3000]], 2920);
    const corridor = room(2, [[3000, 0], [5000, 0], [5000, 3000], [3000, 3000]], 2510);

    it('closes the step from the lower ceiling up to the higher one along the open edge', () => {
        expect(ceilingSteps([living, corridor], [])).toEqual([{a: {x: 3000, y: 3000}, b: {x: 3000, y: 0}, bottom: 2510, top: 2920}]);
    });

    it('counts each ceiling from its own floor level', () => {
        // The corridor two steps up: its ceiling at 2510 + 300 is still under the living room's 2920.
        expect(ceilingSteps([living, {...corridor, floorElevation: 300}], [])).toEqual([
            {a: {x: 3000, y: 3000}, b: {x: 3000, y: 0}, bottom: 2810, top: 2920},
        ]);
        // The living room sunk by 500: its 2920 ceiling is now the lower one, at 2420.
        expect(ceilingSteps([{...living, floorElevation: -500}, corridor], [])).toEqual([
            {a: {x: 3000, y: 0}, b: {x: 3000, y: 3000}, bottom: 2420, top: 2510},
        ]);
    });

    it('draws nothing where a wall stands between them or the ceilings are level', () => {
        expect(ceilingSteps([living, corridor], [wall(1, [3050, -100], [3050, 3100])])).toEqual([]);
        expect(ceilingSteps([living, {...corridor, ceilingHeight: 2920}], [])).toEqual([]);
    });
});

describe('ceilingHeightAt', () => {
    it('is the ceiling height of a flat room, and none for an open-air one', () => {
        const flat = room(1, [[0, 0], [3000, 0], [3000, 3000], [0, 3000]], 2600);
        expect(ceilingHeightAt(flat, 1000, 1000)).toBe(2600);
        expect(ceilingHeightAt({...flat, ceilingHeight: null}, 1000, 1000)).toBeNull();
    });

    it('follows the vaults and drops to the beams under them', () => {
        // Beams along x every 550 (at y = 0, 550, …), 80 wide, hanging 30 below the springing at 2790.
        const vault = {vaultSpacing: 550, vaultAngle: 0, vaultRise: 130, beamWidth: 80, beamDrop: 30};
        const vaulted = room(1, [[0, 0], [3000, 0], [3000, 3000], [0, 3000]], 2920, vault);
        expect(ceilingHeightAt(vaulted, 1000, 275)).toBeCloseTo(2920, 6);
        expect(ceilingHeightAt(vaulted, 1000, 550)).toBe(2760);
        expect(ceilingHeightAt(vaulted, 1000, 100)).toBeGreaterThan(2790);
        expect(ceilingHeightAt(vaulted, 1000, 100)).toBeLessThan(2920);
    });
});
