import {Opening, PlanPoint, Room, Wall} from '@app/models/scene';

import {
    collideCircle,
    entranceWalkStart,
    pointInPolygon,
    polygonCentroid,
    polygonSignedArea,
    snapToGrid,
    stackFronts,
    wallColliders,
    wallFrame,
    wallGapsAlongEdge,
    wallPieces,
} from './plan-geometry';

function opening(offset: number, width: number, height: number, sillHeight = 0): Opening {
    return {id: 0, name: '', type: 'Door', offset, width, height, sillHeight, leafWidth: null, hinge: null, side: null, entrance: false};
}

describe('wallPieces', () => {
    it('returns one full-height piece for a wall without openings', () => {
        expect(wallPieces(3000, 2600, [])).toEqual([{from: 0, to: 3000, bottom: 0, top: 2600}]);
    });

    it('leaves a lintel above a door', () => {
        expect(wallPieces(3871, 2600, [opening(2755, 900, 2020)])).toEqual([
            {from: 0, to: 2755, bottom: 0, top: 2600},
            {from: 2755, to: 3655, bottom: 2020, top: 2600},
            {from: 3655, to: 3871, bottom: 0, top: 2600},
        ]);
    });

    it('adds a parapet under a window and sorts openings', () => {
        expect(wallPieces(5000, 2600, [opening(3000, 1000, 1500, 900), opening(500, 1000, 2600)])).toEqual([
            {from: 0, to: 500, bottom: 0, top: 2600},
            {from: 1500, to: 3000, bottom: 0, top: 2600},
            {from: 3000, to: 4000, bottom: 0, top: 900},
            {from: 3000, to: 4000, bottom: 2400, top: 2600},
            {from: 4000, to: 5000, bottom: 0, top: 2600},
        ]);
    });

    it('clamps an opening that runs past the wall end', () => {
        expect(wallPieces(1000, 2600, [opening(800, 400, 2600)])).toEqual([{from: 0, to: 800, bottom: 0, top: 2600}]);
    });
});

describe('wallFrame', () => {
    it('has a left-hand normal', () => {
        const wall = {startX: 0, startY: 0, endX: 0, endY: 2000} as Wall;
        const frame = wallFrame(wall);
        expect(frame.length).toBe(2000);
        expect(frame.normal.x).toBeCloseTo(-1);
        expect(frame.normal.y).toBeCloseTo(0);
        expect(frame.angle).toBeCloseTo(Math.PI / 2);
    });
});

describe('polygon helpers', () => {
    // Living room of flat 50: a 6151 × 5935 rectangle plus the 2280 × 730 kitchen niche.
    const living: PlanPoint[] = [[0, 0], [6151, 0], [6151, 6665], [3871, 6665], [3871, 5935], [0, 5935]];

    it('computes the area', () => {
        expect(polygonSignedArea(living) / 1e6).toBeCloseTo(38.17, 2);
    });

    it('computes the centroid of a rectangle', () => {
        const c = polygonCentroid([[0, 0], [2000, 0], [2000, 1000], [0, 1000]]);
        expect(c.x).toBeCloseTo(1000);
        expect(c.y).toBeCloseTo(500);
    });
});

describe('stackFronts', () => {
    it('shares the remainder among fronts without height', () => {
        expect(stackFronts([{type: 'drawer', height: 200}, {type: 'door'}, {type: 'door'}], 800)).toEqual([
            {type: 'drawer', bottom: 0, height: 200},
            {type: 'door', bottom: 200, height: 300},
            {type: 'door', bottom: 500, height: 300},
        ]);
    });

    it('scales fixed fronts that do not fit', () => {
        const slots = stackFronts([{type: 'door', height: 600}, {type: 'door', height: 600}], 600);
        expect(slots.map((s) => s.height)).toEqual([300, 300]);
    });
});

describe('wallGapsAlongEdge', () => {
    // Hallway / bathroom wall of flat 50: centre x = 3814, 114 mm thick, bathroom door at y 8955–9755.
    const wall = {
        id: 6, name: '', type: 'Interior', startX: 3814, startY: 5935, endX: 3814, endY: 9906, thickness: 114, height: 2600,
        materialId: null, openings: [opening(3020, 800, 2020)],
    } as Wall;

    it('finds the door on the bathroom edge that runs along the wall face', () => {
        // Bathroom west edge, walked north → south as in a counter-clockwise polygon.
        expect(wallGapsAlongEdge({x: 3871, y: 9906}, {x: 3871, y: 8045}, [wall])).toEqual([{from: 151, to: 951, bottom: 0, top: 2020}]);
    });

    it('ignores walls that are not on the edge', () => {
        expect(wallGapsAlongEdge({x: 6151, y: 8045}, {x: 6151, y: 9906}, [wall])).toEqual([]);
    });
});

describe('snapToGrid', () => {
    it('rounds to the step, or to whole mm', () => {
        expect(snapToGrid(1234, 10)).toBe(1230);
        expect(snapToGrid(1235, 10)).toBe(1240);
        expect(snapToGrid(-14, 10)).toBe(-10);
        expect(snapToGrid(12.6, 1)).toBe(13);
    });
});

describe('pointInPolygon', () => {
    const lShape: PlanPoint[] = [[0, 0], [4000, 0], [4000, 2000], [2000, 2000], [2000, 4000], [0, 4000]];

    it('tells inside from outside, also in a concave notch', () => {
        expect(pointInPolygon({x: 1000, y: 1000}, lShape)).toBe(true);
        expect(pointInPolygon({x: 1000, y: 3500}, lShape)).toBe(true);
        expect(pointInPolygon({x: 3000, y: 3000}, lShape)).toBe(false);
        expect(pointInPolygon({x: -10, y: 1000}, lShape)).toBe(false);
    });
});

describe('wall collisions', () => {
    // A wall along x from 0 to 4000, 200 mm thick, with a 900 mm door at 1000 and a window at 2500.
    const wall = {
        startX: 0,
        startY: 0,
        endX: 4000,
        endY: 0,
        thickness: 200,
        height: 2600,
        openings: [opening(1000, 900, 2020), {...opening(2500, 1000, 1200, 0), type: 'Window'}],
    } as Wall;
    const colliders = wallColliders([wall]);
    const radius = 250;

    it('leaves a gap for doors only', () => {
        expect(colliders.map((c) => [c.from, c.to])).toEqual([[0, 1000], [1900, 4000]]);
    });

    it('keeps a walker a radius away from the wall face', () => {
        const p = collideCircle({x: 500, y: 200}, radius, colliders);
        expect(p.x).toBeCloseTo(500);
        expect(p.y).toBeCloseTo(350);
    });

    it('slides along the wall instead of stopping', () => {
        // Walking diagonally into the wall keeps the movement along it.
        const p = collideCircle({x: 700, y: 300}, radius, colliders);
        expect(p.x).toBeCloseTo(700);
        expect(p.y).toBeCloseTo(350);
    });

    it('lets a walker through the door', () => {
        expect(collideCircle({x: 1450, y: 0}, radius, colliders)).toEqual({x: 1450, y: 0});
    });

    it('blocks the window, also when the centre ends up inside the wall', () => {
        const p = collideCircle({x: 3000, y: 30}, radius, colliders);
        expect(p.x).toBeCloseTo(3000);
        expect(p.y).toBeCloseTo(350);
    });

    it('rounds the door jambs', () => {
        const p = collideCircle({x: 1100, y: 150}, radius, colliders);
        expect(Math.hypot(p.x - 1000, p.y - 100)).toBeCloseTo(radius);
    });
});

describe('entranceWalkStart', () => {
    const room = (type: Room['type'], polygon: PlanPoint[]): Room =>
        ({id: 0, code: '', name: '', type, polygon, area: 0, ceilingHeight: null, floorMaterialId: null, wallFinishes: []}) as Room;
    // Flat west of x = 0, garden east of it; the wall runs north, so its left (+normal) is the west side.
    const flat = room('Hallway', [[-4000, 0], [-150, 0], [-150, 4000], [-4000, 4000]]);
    const garden = room('Garden', [[150, 0], [4000, 0], [4000, 4000], [150, 4000]]);
    const wall = (openings: Opening[], type: Wall['type'] = 'Exterior'): Wall =>
        ({id: 1, name: '', type, startX: 0, startY: 0, endX: 0, endY: 4000, thickness: 300, height: 2600, materialId: null, openings}) as Wall;

    it('starts inside the flat, facing away from the door', () => {
        const start = entranceWalkStart([wall([opening(1000, 1000, 2100)])], [garden, flat]);
        expect(start?.position.x).toBeCloseTo(-850);
        expect(start?.position.y).toBeCloseTo(1500);
        expect(start?.direction.x).toBeCloseTo(-1);
        expect(start?.direction.y).toBeCloseTo(0);
    });

    it('prefers the flagged entrance to other exterior doors', () => {
        const flagged = {...opening(2500, 1000, 2100), entrance: true};
        const start = entranceWalkStart([wall([opening(500, 900, 2100), flagged])], [flat]);
        expect(start?.position.y).toBeCloseTo(3000);
    });

    it('needs a door: French windows and interior doors alone give no entrance', () => {
        expect(entranceWalkStart([wall([{...opening(1000, 1800, 2200), type: 'Window'}])], [flat])).toBeNull();
        expect(entranceWalkStart([wall([opening(1000, 900, 2000)], 'Interior')], [flat])).toBeNull();
    });
});
