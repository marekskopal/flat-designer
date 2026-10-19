import {Opening, PlanPoint, Room, Wall} from '@app/models/scene';

import {
    collideCircle,
    edgeMidpoints,
    entranceWalkStart,
    insertVertex,
    isSimplePolygon,
    leafColliders,
    moveWallEnds,
    openingLeaves,
    pointInPolygon,
    polygonCentroid,
    polygonSignedArea,
    removeVertex,
    slideOpening,
    snapPoint,
    snapToGrid,
    stackFronts,
    tileOrigin,
    tileUv,
    wallColliders,
    wallFrame,
    wallGapsAlongEdge,
    wallJoints,
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

describe('door leaves', () => {
    // The same wall with a door hinged at its start, swinging to the left (+y), and a walkable French window.
    const door = {...opening(1000, 900, 2020), id: 1, hinge: 'Start', side: 'Left'} as Opening;
    const frenchWindow = {...opening(2500, 1200, 2200), id: 2, type: 'Window', walkable: true} as Opening;
    const wall = {startX: 0, startY: 0, endX: 4000, endY: 0, thickness: 200, height: 2600, openings: [door, frenchWindow]} as Wall;
    const radius = 250;
    const collide = (point: {x: number; y: number}, openness: number): {x: number; y: number} =>
        collideCircle(point, radius, [...wallColliders([wall]), ...leafColliders([wall], () => openness)]);

    it('hangs a door leaf on its hinge, flush with the face it swings to', () => {
        expect(openingLeaves(door, 200)).toEqual([{openingId: 1, index: 0, along: 1050, across: 80, closed: 1, swing: 1, width: 800, thickness: 40}]);
        const mirrored = openingLeaves({...door, hinge: 'End', side: 'Right'}, 200)[0];
        expect([mirrored.along, mirrored.across, mirrored.closed, mirrored.swing]).toEqual([1850, -80, -1, -1]);
    });

    it('gives a walkable French window two sashes on its jambs and other windows none', () => {
        expect(openingLeaves(frenchWindow, 200).map((l) => [l.along, l.closed, l.width])).toEqual([[2570, 1, 530], [3630, -1, 530]]);
        expect(openingLeaves({...frenchWindow, walkable: false}, 200)).toEqual([]);
    });

    it('leaves a gap for walkable French windows', () => {
        expect(wallColliders([wall]).map((c) => [c.from, c.to])).toEqual([[0, 1000], [1900, 2500], [3700, 4000]]);
    });

    it('blocks the doorway with a closed leaf', () => {
        const p = collide({x: 1450, y: 0}, 0);
        expect(p.x).toBeCloseTo(1450);
        expect(p.y).toBeCloseTo(60 - radius);
    });

    it('leaves the doorway free when open', () => {
        expect(collide({x: 1450, y: 0}, 1)).toEqual({x: 1450, y: 0});
    });

    it('blocks along the diagonal of a half-open leaf', () => {
        const leaf = leafColliders([wall], () => 0.5)[0];
        expect(Math.atan2(leaf.frame.dir.y, leaf.frame.dir.x)).toBeCloseTo((40 * Math.PI) / 180);
        const p = collide({x: 1450, y: 500}, 0.5);
        const across = (p.x - leaf.frame.start.x) * leaf.frame.normal.x + (p.y - leaf.frame.start.y) * leaf.frame.normal.y;
        expect(across).toBeCloseTo(20 + radius);
    });

    it('opens a French window only with its sashes', () => {
        expect(collide({x: 3100, y: 0}, 0).y).toBeLessThan(-150);
        expect(collide({x: 3100, y: 0}, 1)).toEqual({x: 3100, y: 0});
    });
});

describe('entranceWalkStart', () => {
    const room = (type: Room['type'], polygon: PlanPoint[]): Room =>
        ({
            id: 0,
            code: '',
            name: '',
            type,
            polygon,
            area: 0,
            perimeter: 0,
            ceilingHeight: null,
            floorMaterialId: null,
            wallFinishes: [],
            tileOriginX: null,
            tileOriginY: null,
        }) as Room;
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

    it('prefers the flagged entrance to other exterior doors, and names it', () => {
        const flagged = {...opening(2500, 1000, 2100), id: 7, entrance: true};
        const start = entranceWalkStart([wall([opening(500, 900, 2100), flagged])], [flat]);
        expect(start?.position.y).toBeCloseTo(3000);
        expect(start?.openingId).toBe(7);
    });

    it('needs a door: French windows and interior doors alone give no entrance', () => {
        expect(entranceWalkStart([wall([{...opening(1000, 1800, 2200), type: 'Window'}])], [flat])).toBeNull();
        expect(entranceWalkStart([wall([opening(1000, 900, 2000)], 'Interior')], [flat])).toBeNull();
    });
});

function straightWall(id: number, start: [number, number], end: [number, number], openings: Opening[] = [], thickness = 200): Wall {
    return {
        id,
        name: `W${id}`,
        type: 'Interior',
        startX: start[0],
        startY: start[1],
        endX: end[0],
        endY: end[1],
        thickness,
        height: 2600,
        materialId: null,
        openings,
    };
}

describe('snapPoint', () => {
    const points = [{x: 1000, y: 1000}];

    it('snaps onto a nearby point first', () => {
        expect(snapPoint({x: 1040, y: 970}, {grid: 10, radius: 100, points, anchors: [{x: 1042, y: 0}]})).toEqual({
            point: {x: 1000, y: 1000},
            kind: 'point',
        });
    });

    it('aligns each coordinate with an anchor, the other one on the grid', () => {
        expect(snapPoint({x: 3043, y: 2017}, {grid: 10, radius: 100, points, anchors: [{x: 0, y: 2000}]})).toEqual({
            point: {x: 3040, y: 2000},
            kind: 'axis',
        });
    });

    it('snaps onto a line after the points, rounded to whole mm', () => {
        const lines = [[{x: 2000, y: -150}, {x: 2000, y: 3150}]] as const;
        expect(snapPoint({x: 2040, y: 1517.4}, {grid: 10, radius: 100, points, lines})).toEqual({
            point: {x: 2000, y: 1517},
            kind: 'line',
        });
        expect(snapPoint({x: 1040, y: 970}, {grid: 10, radius: 100, points, lines})).toEqual({point: {x: 1000, y: 1000}, kind: 'point'});
    });

    it('stays in line with an anchor along the line it snapped onto', () => {
        const lines = [[{x: -150, y: 4150}, {x: 6150, y: 4150}]] as const;
        expect(snapPoint({x: 4805, y: 4100}, {grid: 10, radius: 100, lines, anchors: [{x: 4800, y: 2500}]})).toEqual({
            point: {x: 4800, y: 4150},
            kind: 'line',
        });
    });

    it('falls back to the grid, or to whole mm without snapping', () => {
        expect(snapPoint({x: 3043, y: 2517}, {grid: 10, radius: 100, points})).toEqual({point: {x: 3040, y: 2520}, kind: 'grid'});
        expect(snapPoint({x: 1040.4, y: 970.6}, {grid: 1, radius: 0, points})).toEqual({point: {x: 1040, y: 971}, kind: 'grid'});
    });
});

describe('moveWallEnds', () => {
    const door = {...opening(1000, 900, 2000), id: 7};
    const wall = straightWall(1, [0, 0], [4000, 0], [door]);

    it('keeps the openings in place on the plan when the start moves', () => {
        const moved = moveWallEnds(wall, {x: 500, y: 0}, {x: 4000, y: 0});
        expect(moved.fits).toBe(true);
        expect(moved.wall.openings[0].offset).toBe(500);
        expect(moveWallEnds(wall, {x: -1000, y: 0}, {x: 4000, y: 0}).wall.openings[0].offset).toBe(2000);
    });

    it('leaves the offsets when only the end moves', () => {
        expect(moveWallEnds(wall, {x: 0, y: 0}, {x: 3000, y: 500}).wall.openings[0].offset).toBe(1000);
    });

    it('reports when an opening would fall off the wall', () => {
        expect(moveWallEnds(wall, {x: 1500, y: 0}, {x: 4000, y: 0}).fits).toBe(false);
        expect(moveWallEnds(wall, {x: 0, y: 0}, {x: 1800, y: 0}).fits).toBe(false);
        expect(moveWallEnds(wall, {x: 0, y: 0}, {x: 1900, y: 0}).fits).toBe(true);
    });
});

describe('slideOpening', () => {
    const wall = straightWall(1, [0, 0], [5000, 0], [{...opening(500, 900, 2000), id: 1}, {...opening(3000, 1200, 1200, 900), id: 2}]);

    it('keeps the opening on the wall', () => {
        expect(slideOpening(wall, 1, -300)).toEqual({offset: 0, fits: true});
        expect(slideOpening(wall, 2, 4500)).toEqual({offset: 3800, fits: true});
    });

    it('flags an overlap with another opening', () => {
        expect(slideOpening(wall, 1, 2200)).toEqual({offset: 2200, fits: false});
        expect(slideOpening(wall, 1, 2100)).toEqual({offset: 2100, fits: true});
    });
});

describe('room vertices', () => {
    const square: PlanPoint[] = [[0, 0], [1000, 0], [1000, 1000], [0, 1000]];

    it('inserts after the edge start and removes down to a triangle', () => {
        expect(insertVertex(square, 1, {x: 1200.4, y: 500})).toEqual([[0, 0], [1000, 0], [1200, 500], [1000, 1000], [0, 1000]]);
        expect(removeVertex(square, 2)).toEqual([[0, 0], [1000, 0], [0, 1000]]);
        expect(removeVertex([[0, 0], [1000, 0], [0, 1000]], 0)).toBeNull();
    });

    it('finds edge midpoints', () => {
        expect(edgeMidpoints(square)[3]).toEqual({x: 0, y: 500});
    });

    it('tells simple polygons from crossing or degenerate ones', () => {
        expect(isSimplePolygon(square)).toBe(true);
        expect(isSimplePolygon([[0, 0], [1000, 1000], [1000, 0], [0, 1000]])).toBe(false);
        expect(isSimplePolygon([[0, 0], [1000, 0], [2000, 0]])).toBe(false);
        expect(isSimplePolygon([[0, 0], [0, 0], [1000, 0], [0, 1000]])).toBe(false);
    });
});

describe('wallJoints', () => {
    it('mitres an L corner: inner faces cut short, outer faces extended', () => {
        const joints = wallJoints([straightWall(1, [0, 0], [3000, 0]), straightWall(2, [0, 0], [0, 3000])]);
        // Wall 1 runs east, its left (north) face is the inner one.
        expect(joints.get(1)).toEqual({startLeft: -100, startRight: 100, endLeft: 0, endRight: 0});
        // Wall 2 runs north, its left (west) face is the outer one.
        expect(joints.get(2)).toEqual({startLeft: 100, startRight: -100, endLeft: 0, endRight: 0});
    });

    it('handles corners at wall ends and different thicknesses', () => {
        const joints = wallJoints([straightWall(1, [0, 0], [3000, 0], [], 300), straightWall(2, [3000, 3000], [3000, 0], [], 100)]);
        // Wall 1's end: its north (left) face stops at wall 2's west face, its south face reaches its east face.
        expect(joints.get(1)).toEqual({startLeft: 0, startRight: 0, endLeft: -50, endRight: 50});
        // Wall 2 runs south and ends at the corner: its left is east (outer).
        expect(joints.get(2)).toEqual({startLeft: 0, startRight: 0, endLeft: 150, endRight: -150});
    });

    it('leaves straight continuations, T joints and free ends square', () => {
        expect(wallJoints([straightWall(1, [0, 0], [3000, 0]), straightWall(2, [3000, 0], [6000, 0])]).size).toBe(0);
        const tee = [straightWall(1, [0, 0], [3000, 0]), straightWall(2, [3000, 0], [6000, 0]), straightWall(3, [3000, 0], [3000, 2000])];
        expect(wallJoints(tee).size).toBe(0);
        expect(wallJoints([straightWall(1, [0, 0], [3000, 0])]).size).toBe(0);
    });
});

describe('tile grid', () => {
    const origin = {x: 4010, y: 9655};

    it('defaults to the plan origin', () => {
        expect(tileOrigin({tileOriginX: null, tileOriginY: null})).toEqual({x: 0, y: 0});
        expect(tileOrigin({tileOriginX: 4010, tileOriginY: 9655})).toEqual(origin);
    });

    it('lays floors on plan x, y from the origin', () => {
        expect(tileUv({x: 4610, y: 9055}, 0, {x: 0, y: 0, up: 1}, origin)).toEqual([0.6, -0.6]);
    });

    it('continues the floor joints up the walls and starts rows at the floor', () => {
        // Facing south (wall along x): u follows plan x, joints at the floor's x joints.
        const [u, v] = tileUv({x: 4610, y: 8045}, 300, {x: 0, y: -1, up: 0}, origin);
        expect(u).toBeCloseTo(0.6);
        expect(v).toBeCloseTo(0.3);
        // Facing east (wall along y): u runs with −y, a joint where the floor has one.
        expect(tileUv({x: 3871, y: 9055}, 0, {x: 1, y: 0, up: 0}, origin)[0]).toBeCloseTo(-0.6);
    });
});
