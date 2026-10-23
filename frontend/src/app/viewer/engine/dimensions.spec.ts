import {Opening, PlanPoint, Wall} from '@app/models/scene';
import {describe, expect, it} from 'vitest';

import {ChainOffset, DimensionOffset, roomDimensions} from './dimensions';
import {detectRoom} from './room-detect';

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
    id: 9,
    name: 'Door',
    type: 'Door',
    offset: 1150,
    width: 900,
    height: 2000,
    sillHeight: 0,
    leafWidth: null,
    hinge: 'Start',
    side: 'Left',
    entrance: false,
};

/** Centre lines of 300 mm walls around the inner 4000 × 3000 room; the south wall has a door 1000–1900 from the inner corner. */
const walls = [
    wall(1, [-150, -150], [4150, -150], [door]),
    wall(2, [4150, -150], [4150, 3150]),
    wall(3, [4150, 3150], [-150, 3150]),
    wall(4, [-150, 3150], [-150, -150]),
];
const room = {polygon: [[0, 0], [4000, 0], [4000, 3000], [0, 3000]] as PlanPoint[]};

describe('roomDimensions', () => {
    it('gives every edge its length inside the room, and a chain of piers and the door along the south wall', () => {
        const lines = roomDimensions([room], walls);

        const overall = lines.filter((l) => l.a.y === DimensionOffset && l.b.y === DimensionOffset);
        expect(overall).toEqual([{a: {x: 0, y: 300}, b: {x: 4000, y: 300}, length: 4000, normal: {x: -0, y: 1}}]);
        expect(lines.filter((l) => l.length === 3000)).toHaveLength(2);
        expect(lines.filter((l) => l.length === 4000)).toHaveLength(2);

        const chain = lines.filter((l) => l.a.y === ChainOffset);
        expect(chain.map((l) => [l.a.x, l.b.x, l.length])).toEqual([
            [0, 1000, 1000],
            [1000, 1900, 900],
            [1900, 4000, 2100],
        ]);
        expect(lines).toHaveLength(7);
    });

    it('measures inside a clockwise outline too', () => {
        const clockwise = {polygon: [...room.polygon].reverse()};
        const lines = roomDimensions([clockwise], []);

        expect(lines).toHaveLength(4);
        // Offset into the room, not out of it.
        expect(lines.every((l) => l.a.x >= 0 && l.a.x <= 4000 && l.a.y >= 0 && l.a.y <= 3000)).toBe(true);
    });

    it('gives no chain for a niche, which stays in the plan', () => {
        const niche: Opening = {...door, id: 10, type: 'Niche', side: 'Left', depth: 100, sillHeight: 900, height: 600};
        const lines = roomDimensions([room], [wall(1, [-150, -150], [4150, -150], [niche]), ...walls.slice(1)]);
        expect(lines.filter((l) => l.a.y === ChainOffset)).toEqual([]);
        expect(lines).toHaveLength(4);
    });

    it('leaves out short edges', () => {
        const notched = {polygon: [[0, 0], [4000, 0], [4000, 3000], [100, 3000], [100, 2900], [0, 2900]] as PlanPoint[]};

        expect(roomDimensions([notched], []).map((l) => l.length)).toEqual([4000, 3000, 3900, 2900]);
    });
});

describe('roomDimensions along a curved wall', () => {
    // The south wall bows 400 mm out (south) between the corners of the 4000 × 3200 room; a door 1600 mm along it.
    const bay = [
        {...wall(1, [-100, -100], [4100, -100], [{...door, offset: 1600}]), thickness: 200, sagitta: -400},
        {...wall(2, [4100, -100], [4100, 3100]), thickness: 200},
        {...wall(3, [4100, 3100], [-100, 3100]), thickness: 200},
        {...wall(4, [-100, 3100], [-100, -100]), thickness: 200},
    ];
    const detected = detectRoom(bay, {x: 2000, y: 1500});
    const polygon = 'polygon' in detected ? detected.polygon : [];

    it('measures the curved face as one line along the arc, with the chain of its door', () => {
        const lines = roomDimensions([{polygon}], bay);
        const curved = lines.filter((l) => l.path !== undefined);

        // The overall line, then pier, door, pier.
        // The room side of the wall runs 5612 mm round (the centre line 5712): 4089 mm between the corners, the door 884.
        expect(curved.map((l) => l.length)).toEqual([4089, 1504, 884, 1701]);
        expect(curved[0].path!.length).toBeGreaterThan(10);
        // 300 mm into the room from the face, which lies 400 mm south of the corners at its middle.
        const middle = curved[0].path!.reduce((low, p) => (p.y < low.y ? p : low));
        expect(Math.round(middle.y)).toBeGreaterThanOrEqual(-100 - 3);
        expect(lines.filter((l) => l.path === undefined).map((l) => l.length)).toEqual([3032, 4000, 3032]);
    });

    it('keeps the corner pieces of a thin curved wall between thick walls in its line', () => {
        // The mitres at the corners reach past the thin wall's own span: its end pieces are still part of its face.
        const thin = [{...bay[0], thickness: 100, startY: -50, endY: -50, openings: []}, ...bay.slice(1)];
        const outline = detectRoom(thin, {x: 2000, y: 1500});
        const lines = roomDimensions([{polygon: 'polygon' in outline ? outline.polygon : []}], thin);
        expect(lines.filter((l) => l.path !== undefined)).toHaveLength(1);
        expect(lines.filter((l) => l.path === undefined).map((l) => l.length)).toEqual([3033, 4000, 3033]);
    });
});
