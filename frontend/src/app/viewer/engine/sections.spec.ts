import {Opening, PlanPoint, Room, Scene, Stair, Wall} from '@app/models/scene';
import {describe, expect, it} from 'vitest';

import {buildingView, facadeName, facadePlane, facadeSheets, lineInRing, SectionCut, sectionPlane, sectionSheet} from './sections';
import {SheetInfo} from './sheet-svg';

const wall = (id: number, start: PlanPoint, end: PlanPoint, openings: Opening[] = []): Wall => ({
    id,
    name: `Wall ${id}`,
    type: 'Exterior',
    startX: start[0],
    startY: start[1],
    endX: end[0],
    endY: end[1],
    thickness: 300,
    height: 2600,
    materialId: null,
    leftFinishes: null,
    rightFinishes: null,
    openings,
});

const door: Opening = {
    id: 1,
    name: 'Front door',
    type: 'Door',
    offset: 2550,
    width: 900,
    height: 2000,
    sillHeight: 0,
    leafWidth: null,
    hinge: 'Start',
    side: 'Left',
    entrance: true,
};

const room: Room = {
    id: 1,
    code: '1.01',
    name: 'Living room',
    type: 'Living',
    polygon: [
        [150, 150],
        [5850, 150],
        [5850, 3850],
        [150, 3850],
    ],
    area: 21,
    perimeter: 19,
    ceilingHeight: 2600,
    floorMaterialId: null,
    wallFinishes: [],
    tileOriginX: null,
    tileOriginY: null,
};

/** A box house, 6 × 4 m on its walls' centre lines, its south wall (the first) with a door from x 2550 to 3450. */
const house = (elevation = 0, level = 0): Scene => ({
    floor: {id: 10 + level, name: level === 0 ? 'Ground floor' : 'First floor', level, elevation, slabThickness: 250},
    materials: [],
    rooms: [room],
    walls: [
        wall(1, [0, 0], [6000, 0], level === 0 ? [door] : []),
        wall(2, [6000, 0], [6000, 4000]),
        wall(3, [6000, 4000], [0, 4000]),
        wall(4, [0, 4000], [0, 0]),
    ],
    items: [],
});

const info: SheetInfo = {projectName: 'Box', floorName: 'Ground floor', floorElevation: 0, address: null, date: new Date(2026, 10, 1)};

/** The cut through the door, south to north, looking west. */
const cut: SectionCut = {name: 'A–A', start: {x: 3000, y: -1000}, end: {x: 3000, y: 5000}, depth: null};

describe('building drawings', () => {
    it('names a façade by the true compass when north is turned', () => {
        expect(facadeName('North', 0)).toBe('North');
        // True north 90° clockwise from plan north: plan north is true west.
        expect(facadeName('North', 90)).toBe('West');
        expect(facadeName('South', 350)).toBe('South');
    });

    it('looks to the left of a section line, along it on paper', () => {
        const plane = sectionPlane(cut);
        expect(plane?.look).toEqual({x: -1, y: 0});
        expect(plane?.right.x).toBeCloseTo(0);
        expect(plane?.right.y).toBeCloseTo(1);
        expect(sectionPlane({...cut, end: cut.start})).toBeNull();
        // The south façade looks north, paper right is plan east.
        expect(facadePlane('South')).toMatchObject({look: {x: 0, y: 1}, right: {x: 1, y: -0}});
    });

    it('finds where a line runs inside a ring', () => {
        const square = [
            {x: 0, y: 0},
            {x: 10, y: 0},
            {x: 10, y: 10},
            {x: 0, y: 10},
        ];
        expect(lineInRing({x: -5, y: 5}, {x: 1, y: 0}, square)).toEqual([[5, 15]]);
        expect(lineInRing({x: -5, y: 20}, {x: 1, y: 0}, square)).toEqual([]);
    });

    it('draws a façade over the walls\' outer faces, from the floor up to their tops', () => {
        const view = buildingView([house()], facadePlane('South'));
        // The south face, mitred to the outer corners, a metre of margin either side.
        expect(view.bounds.minU).toBeCloseTo(-150 - 1000);
        expect(view.bounds.maxU).toBeCloseTo(6150 + 1000);
        expect(view.bounds.maxV).toBeCloseTo(2600);
        const faces = view.groups.flatMap((g) => g.shapes).filter((s) => s.closed && s.style.width === 0.25);
        expect(faces.some((s) => Math.min(...s.points.map((p) => p.u)) === -150 && Math.max(...s.points.map((p) => p.v)) === 2600)).toBe(true);
        expect(view.marks.map((m) => m.level)).toEqual([0]);
    });

    it('draws a fence out on the site without widening the drawing', () => {
        const fenced: Scene = {
            ...house(),
            railings: [
                {
                    id: 1,
                    name: 'Fence',
                    points: [
                        [-20000, -3000],
                        [26000, -3000],
                    ],
                    height: 1600,
                    baseZ: 0,
                    style: 'Boards',
                    spacing: null,
                    postSpacing: 2500,
                    handrail: 'None',
                    materialId: null,
                    infillMaterialId: null,
                },
            ],
        };
        expect(buildingView([fenced], facadePlane('South')).bounds).toEqual(buildingView([house()], facadePlane('South')).bounds);
    });

    it('stacks the floors at their elevations, the slab between them', () => {
        const view = buildingView([house(2850, 1), house()], facadePlane('South'));
        expect(view.bounds.maxV).toBeCloseTo(2850 + 2600);
        expect(view.bounds.minV).toBeCloseTo(-250);
        expect(view.marks.map((m) => m.label)).toEqual(['First floor', 'Ground floor']);
    });

    it('cuts the walls, less the door it passes through, and the slab', () => {
        const view = buildingView([house()], sectionPlane(cut)!, cut);
        const span = (from: number, to: number): {u0: number; u1: number; v0: number; v1: number}[] =>
            view.cut
                .filter((s) => s.closed)
                .map((s) => ({
                    u0: Math.min(...s.points.map((p) => p.u)),
                    u1: Math.max(...s.points.map((p) => p.u)),
                    v0: Math.min(...s.points.map((p) => p.v)),
                    v1: Math.max(...s.points.map((p) => p.v)),
                }))
                .filter((r) => r.u0 >= from - 1 && r.u1 <= to + 1);
        // u runs along the line from its start (y −1000): the south wall at u 850–1150, the north wall at 4850–5150.
        const south = span(850, 1150);
        expect(south.some((r) => r.v0 === 2000 && r.v1 === 2600)).toBe(true);
        expect(south.some((r) => r.v0 === 0 && r.v1 === 2000)).toBe(false);
        expect(span(4850, 5150).some((r) => r.v0 === 0 && r.v1 === 2600)).toBe(true);
        // The slab under the room and the walls.
        expect(span(850, 5150).some((r) => r.u0 === 850 && r.u1 === 5150 && r.v0 === -250 && r.v1 === 0)).toBe(true);
    });

    it('draws each stair step a riser high, and cuts a solid flight down its waist', () => {
        // 12 risers of 200 mm over 2400 mm, north from (3000, 1000).
        const stair: Stair = {
            id: 1,
            name: 'Stairs',
            shape: 'Straight',
            x: 3000,
            y: 1000,
            rotation: 0,
            width: 900,
            going: 250,
            risers: 12,
            firstFlight: null,
            turn: 'Left',
            gap: 100,
            construction: 'Solid',
            railing: 'Right',
            treadMaterialId: null,
            structureMaterialId: null,
        };
        const scene = {...house(), stairs: [stair], stairRise: 2400};
        const heights = (shapes: {points: {v: number}[]; closed: boolean}[]): number[] =>
            shapes.filter((s) => s.closed).map((s) => Math.max(...s.points.map((q) => q.v)) - Math.min(...s.points.map((q) => q.v)));
        // Seen from the east, beyond a cut through the middle of the house (looking west, off the stair).
        const beyond = buildingView([scene], sectionPlane({...cut, start: {x: 4500, y: -1000}, end: {x: 4500, y: 5000}})!, null);
        expect(heights(beyond.groups.flatMap((g) => g.shapes)).filter((h) => Math.abs(h - 200) < 0.5).length).toBeGreaterThanOrEqual(11);
        // Cut along the walking line: every tread 200 + 150 mm deep.
        const along = {...cut, start: {x: 3000, y: -1000}, end: {x: 3000, y: 5000}};
        const cutView = buildingView([scene], sectionPlane(along)!, along);
        expect(heights(cutView.cut).filter((h) => Math.abs(h - 350) < 0.5).length).toBeGreaterThanOrEqual(11);
    });

    it('lays the façades on an A3 sheet and a section on A4, with their names and levels', () => {
        const sheets = facadeSheets([house()], info);
        expect(sheets).toHaveLength(1);
        expect([sheets[0].width, sheets[0].height]).toEqual([420, 297]);
        for (const name of ['South', 'North', 'East', 'West']) {
            expect(sheets[0].svg).toContain(`${name} elevation`);
        }
        const section = sectionSheet([house()], cut, info);
        expect(section?.svg).toContain('Section A–A');
        expect(section?.svg).toContain('±0.000');
        expect(section?.svg).toContain('1:50');
    });
});
