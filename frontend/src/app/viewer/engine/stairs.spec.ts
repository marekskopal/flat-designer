import {Room, Stair} from '@app/models/scene';
import * as THREE from 'three';
import {describe, expect, it} from 'vitest';

import {MaterialLibrary} from './materials';
import {collideCircle, polygonSignedArea, Vec2} from './plan-geometry';
import {
    buildStair,
    buildStairsBelow,
    cutVoids,
    PostSize,
    RailHeight,
    RailRadius,
    roomsCutBy,
    sceneVoids,
    stairColliders,
    stairFirstFlight,
    stairFloorStep,
    stairGeometry,
    stairHeightAt,
    StairPath,
    stairPaths,
    stairRisers,
    stairSpot,
} from './stairs';

const stair: Stair = {
    id: 1,
    name: 'Stairs',
    shape: 'Straight',
    x: 1000,
    y: 2000,
    rotation: 0,
    width: 900,
    going: 270,
    risers: null,
    firstFlight: null,
    turn: 'Left',
    gap: 100,
    construction: 'Solid',
    railing: 'Right',
    treadMaterialId: null,
    structureMaterialId: null,
};

const round = (p: Vec2): [number, number] => [Math.round(p.x), Math.round(p.y)];
const area = (outline: readonly Vec2[]): number => Math.abs(polygonSignedArea(outline.map((p) => [p.x, p.y])));

describe('stairRisers', () => {
    it('counts as few risers as keep each at most 180 mm, two at least', () => {
        expect(stairRisers(2850, null)).toBe(16);
        expect(stairRisers(3600, null)).toBe(20);
        expect(stairRisers(200, null)).toBe(2);
        expect(stairRisers(2850, 14)).toBe(14);
    });

    it('halves the risers at the landing unless told, within the count', () => {
        expect(stairFirstFlight(16, null)).toBe(8);
        expect(stairFirstFlight(15, null)).toBe(7);
        expect(stairFirstFlight(16, 5)).toBe(5);
        expect(stairFirstFlight(16, 40)).toBe(15);
        expect(stairFirstFlight(16, 0)).toBe(1);
    });
});

describe('stairGeometry', () => {
    it('lays a straight stair up its direction: 15 treads of the going, the rise split evenly', () => {
        const g = stairGeometry(stair, 2850);
        expect(g.risers).toBe(16);
        expect(g.riser).toBeCloseTo(178.125);
        expect(g.treads).toHaveLength(15);
        // The first tread stands on the first riser, from the start point north 270 mm, 900 wide.
        expect(g.treads[0].outline.map(round)).toEqual([
            [550, 2000],
            [550, 2270],
            [1450, 2270],
            [1450, 2000],
        ]);
        expect(g.treads[0].top).toBeCloseTo(178.125);
        expect(g.treads[14].top).toBeCloseTo(15 * 178.125);
        // The footprint runs to the last riser, 15 goings up.
        expect(g.footprint.map(round)).toEqual([
            [550, 2000],
            [550, 6050],
            [1450, 6050],
            [1450, 2000],
        ]);
        expect(g.walkLine.map((p) => [...round(p.point), Math.round(p.height)])).toEqual([
            [1000, 2000, 0],
            [1000, 6050, 2850],
        ]);
    });

    it('turns with the rotation: 90° walks west', () => {
        // Walking west, the right of the walk is north.
        const g = stairGeometry({...stair, rotation: 90, risers: 4}, 720);
        expect(g.footprint.map(round)).toEqual([
            [1000, 1550],
            [190, 1550],
            [190, 2450],
            [1000, 2450],
        ]);
    });

    it('puts an L stair\'s second flight off a square landing to the turning side', () => {
        const g = stairGeometry({...stair, shape: 'LShaped', risers: 10, firstFlight: 4}, 1800);
        expect(g.flights).toHaveLength(2);
        expect(g.landings).toHaveLength(1);
        // Three treads, then the landing at the fourth riser's height, 900 square.
        expect(g.landings[0].top).toBe(720);
        expect(g.landings[0].outline.map(round)).toEqual([
            [550, 2810],
            [550, 3710],
            [1450, 3710],
            [1450, 2810],
        ]);
        // The second flight walks west from the landing's left edge with the remaining six risers (five treads).
        expect(g.flights[1].direction).toBe(90);
        expect(round(g.flights[1].origin)).toEqual([550, 3260]);
        expect(g.flights[1].risers).toBe(6);
        expect(g.treads).toHaveLength(3 + 5);
        expect(g.treads[3].top).toBe(900);
        // The footprint: the first flight, the landing and the second flight reaching 5 × 270 west of it.
        expect(area(g.footprint)).toBe(810 * 900 + 900 * 900 + 1350 * 900);
        // The outer handrail runs round the landing's far corners; the inner one meets the second flight at the corner.
        const outer = g.rails.find((r) => r.side === 'Right');
        const inner = g.rails.find((r) => r.side === 'Left');
        expect(outer?.path.map((p) => round(p.point))).toEqual([
            [1450, 2000],
            [1450, 2810],
            [1450, 3710],
            [550, 3710],
            [-800, 3710],
        ]);
        expect(inner?.path.map((p) => round(p.point))).toEqual([
            [550, 2000],
            [550, 2810],
            [-800, 2810],
        ]);
        expect(outer?.path[2].height).toBe(720 + RailHeight);
        expect(outer?.path[4].height).toBe(1800 + RailHeight);
    });

    it('brings a U stair back beside itself over a half landing with the gap between the flights', () => {
        const g = stairGeometry({...stair, shape: 'UShaped', risers: 10, firstFlight: 5, turn: 'Right', gap: 200}, 1800);
        expect(g.flights[1].direction).toBe(180);
        // The second flight's middle lies a width and the gap to the right of the first's.
        expect(round(g.flights[1].origin)).toEqual([2100, 3080]);
        expect(g.landings[0].outline.map(round)).toEqual([
            [550, 3080],
            [550, 3980],
            [2550, 3980],
            [2550, 3080],
        ]);
        expect(area(g.footprint)).toBe(1080 * 900 + 2000 * 900 + 1080 * 900);
        // The inner rail crosses the gap on the landing's entry edge.
        const inner = g.rails.find((r) => r.side === 'Right');
        expect(inner?.path.map((p) => round(p.point))).toEqual([
            [1450, 2000],
            [1450, 3080],
            [1650, 3080],
            [1650, 2000],
        ]);
    });

    it('winds a spiral round a pole to the inner side of the first step', () => {
        const g = stairGeometry({...stair, shape: 'Spiral', width: 1600, risers: 12, turn: 'Left'}, 2400);
        expect(g.spiral).not.toBeNull();
        // Walking north, turning left: the pole stands 400 mm west of the first riser's middle.
        expect(round(g.spiral?.centre ?? {x: 0, y: 0})).toEqual([600, 2000]);
        expect(g.spiral?.radius).toBe(800);
        expect(g.spiral?.step).toBeGreaterThan(0);
        expect(g.treads).toHaveLength(11);
        expect(g.treads[0].top).toBe(200);
        // The footprint is the full circle, the handrail on the outside (the right, turning left).
        expect(area(g.footprint)).toBeGreaterThan(Math.PI * 800 * 800 * 0.98);
        expect(g.rails.map((r) => r.side)).toEqual(['Right']);
        expect(g.rails[0].path[0].height).toBe(200 + RailHeight);
        expect(g.rails[0].path[11].height).toBe(2400 + RailHeight);
    });

    it('tells the height of the step under a point', () => {
        const g = stairGeometry({...stair, risers: 4}, 720);
        expect(stairHeightAt(g, {x: 1000, y: 2100})).toBe(180);
        expect(stairHeightAt(g, {x: 1000, y: 2700})).toBe(540);
        expect(stairHeightAt(g, {x: 1000, y: 1900})).toBeNull();
        expect(stairHeightAt(g, {x: 2000, y: 2100})).toBeNull();
    });
});

describe('curved stairs', () => {
    // Garden steps: 5 risers over 750 mm, 1200 wide, curving left round a centre 2000 mm from their inner edge.
    const curved: Stair = {...stair, shape: 'Curved', width: 1200, going: 350, risers: 5, rise: 750, innerRadius: 2000, construction: 'Solid'};

    it('lays ring-sector treads round a centre on the side it turns to, wider at the outer edge', () => {
        const g = stairGeometry(curved, 0);
        expect(g.treads).toHaveLength(4);
        expect(g.flights).toEqual([]);
        // The walking line runs 2600 mm from the centre, which lies to the left (west) of the first riser at (1000, 2000).
        const centre = {x: 1000 - 2600, y: 2000};
        for (const point of g.walkLine) {
            expect(Math.hypot(point.point.x - centre.x, point.point.y - centre.y)).toBeCloseTo(2600);
        }
        // Each tread turns 350 / 2600 rad along it: the risers are 350 mm apart there, more at the outer edge.
        const [a, b] = [g.walkLine[0].point, g.walkLine[1].point];
        const chord = 2 * 2600 * Math.sin(350 / 2600 / 2);
        expect(Math.hypot(b.x - a.x, b.y - a.y)).toBeCloseTo(chord);
        const tread = g.treads[0].outline;
        const radii = tread.map((p) => Math.hypot(p.x - centre.x, p.y - centre.y));
        expect(Math.min(...radii)).toBeCloseTo(2000);
        expect(Math.max(...radii)).toBeCloseTo(3200);
        // Climbing its own rise, the top at 750.
        expect(g.walkLine[g.walkLine.length - 1].height).toBeCloseTo(750);
        expect(g.rails.map((r) => r.side)).toEqual(['Left', 'Right']);
    });

    it('turning right curves the other way', () => {
        const g = stairGeometry({...curved, turn: 'Right'}, 0);
        expect(g.walkLine[g.walkLine.length - 1].point.x).toBeGreaterThan(1000);
        expect(stairGeometry(curved, 0).walkLine[4].point.x).toBeLessThan(1000);
    });

    it('is never walked under when solid, as its treads are blocks down to its base', () => {
        // A storey: 16 risers of 180 over 2880 mm, round a centre 2000 mm from the inner edge (about 95°).
        const storey = (construction: Stair['construction']): StairPath[] =>
            stairPaths({stairs: [{...curved, risers: 16, rise: null, going: 270, width: 900, construction}], stairRise: 2880, stairsBelow: null});
        const overhead = (paths: StairPath[]): boolean => stairColliders(paths).some((c) => (c.levels?.[0] ?? -Infinity) > 0);
        expect(overhead(storey('Open'))).toBe(true);
        expect(overhead(storey('Solid'))).toBe(false);
    });

    it('builds solid blocks for its treads', () => {
        // Without a railing: the blocks stand on the base and reach the fourth tread's top (4 × 150 mm).
        const group = buildStair({...curved, railing: 'None'}, 0, new MaterialLibrary([], 1), new THREE.MeshBasicMaterial());
        group.updateMatrixWorld(true);
        const box = new THREE.Box3().setFromObject(group);
        expect(box.max.y).toBeCloseTo(0.6);
        expect(box.min.y).toBeCloseTo(0);
    });
});

describe('cutVoids', () => {
    const square: [number, number][] = [
        [0, 0],
        [4000, 0],
        [4000, 4000],
        [0, 4000],
    ];

    it('leaves a hole for a void inside the outline', () => {
        const pieces = cutVoids(square, [
            [
                [1000, 1000],
                [2000, 1000],
                [2000, 3000],
                [1000, 3000],
            ],
        ]);
        expect(pieces).toHaveLength(1);
        expect(area(pieces[0].outer)).toBe(16_000_000);
        expect(pieces[0].holes).toHaveLength(1);
        expect(area(pieces[0].holes[0])).toBe(2_000_000);
    });

    it('notches the outline where the void reaches over its edge, and leaves it whole where they miss', () => {
        const pieces = cutVoids(square, [
            [
                [3000, 1000],
                [5000, 1000],
                [5000, 2000],
                [3000, 2000],
            ],
        ]);
        expect(pieces).toHaveLength(1);
        expect(pieces[0].holes).toHaveLength(0);
        expect(pieces[0].outer).toHaveLength(8);
        expect(area(pieces[0].outer)).toBe(16_000_000 - 1_000_000);

        const whole = cutVoids(square, [
            [
                [6000, 0],
                [7000, 0],
                [7000, 1000],
            ],
        ]);
        expect(whole).toHaveLength(1);
        expect(whole[0].outer).toHaveLength(4);
    });

    it('finds the rooms a footprint may reach by their bounds', () => {
        const room = (id: number, x: number): Room => ({
            id,
            name: `R${id}`,
            code: `R${id}`,
            type: 'Living',
            polygon: [
                [x, 0],
                [x + 3000, 0],
                [x + 3000, 3000],
                [x, 3000],
            ],
            floorMaterialId: null,
            ceilingHeight: 2600,
            ceilingMaterialId: null,
            wallFinishes: [],
            tileOriginX: null,
            tileOriginY: null,
        });
        const cut = roomsCutBy(
            [room(1, 0), room(2, 5000)],
            [
                [
                    [2500, 1000],
                    [3500, 1000],
                    [3500, 2000],
                    [2500, 2000],
                ],
            ],
        );
        expect([...cut]).toEqual([1]);
    });
});

describe('buildStair', () => {
    const materials = new MaterialLibrary([], 1);
    const bounds = (object: THREE.Object3D): THREE.Box3 => new THREE.Box3().setFromObject(object);
    const mm = (v: number): number => Math.round(v * 1000);

    it('stands at the stair\'s point, tagged for picking, and climbs the rise with its handrail above', () => {
        const group = buildStair({...stair, risers: 4}, 720, materials, materials.sectionFill());
        expect(group.userData['stairId']).toBe(1);
        expect([mm(group.position.x), mm(group.position.z)]).toEqual([1000, -2000]);
        const box = bounds(group);
        // Three treads of 270 north of the start, 900 wide; the handrail on the right edge stands 900 over the top
        // riser's nosing, its posts half over the first and last riser.
        expect([mm(box.min.x), mm(box.max.x)]).toEqual([550, 1450 + RailRadius]);
        expect([mm(box.max.z), mm(box.min.z)]).toEqual([-2000 + PostSize / 2, -2810 - PostSize / 2]);
        expect(mm(box.min.y)).toBe(0);
        // The top post reaches the rail height; the sloping tube's rim a little over it.
        expect(mm(box.max.y)).toBeGreaterThanOrEqual(720 + RailHeight);
        expect(mm(box.max.y)).toBeLessThanOrEqual(720 + RailHeight + RailRadius);
    });

    it('draws the floor below\'s stairs under the floor, unpickable', () => {
        const group = buildStairsBelow({floorId: 3, rise: 720, stairs: [{...stair, risers: 4}]}, materials, materials.sectionFill());
        expect(mm(group.position.y)).toBe(-720);
        expect(group.children[0].userData['stairId']).toBeUndefined();
        const hits: THREE.Intersection[] = [];
        group.updateMatrixWorld(true);
        new THREE.Raycaster(new THREE.Vector3(1, 5, -2.2), new THREE.Vector3(0, -1, 0)).intersectObject(group, true, hits);
        expect(hits).toHaveLength(0);
        expect(buildStairsBelow(null, materials, materials.sectionFill()).children).toHaveLength(0);
    });
});

describe('walking the stairs', () => {
    // 4 risers of 180 over 720 mm, 3 treads of 270: the walking line runs from (1000, 2000) north to (1000, 2810).
    const short: Stair = {...stair, risers: 4};
    const own = stairPaths({stairs: [short], stairRise: 720, stairsBelow: null});
    const below = stairPaths({stairs: [], stairRise: 2600, stairsBelow: {floorId: 3, rise: 720, stairs: [short]}});

    it('ramps along the walking line, and is off the stair outside its footprint', () => {
        expect(stairSpot(own, {x: 1000, y: 2000})?.height).toBeCloseTo(0);
        expect(stairSpot(own, {x: 1200, y: 2405})?.height).toBeCloseTo(360);
        expect(stairSpot(own, {x: 1000, y: 2800})?.height).toBeCloseTo(720 * (800 / 810));
        expect(stairSpot(own, {x: 1000, y: 1900})).toBeNull();
        // The stairs from below lie under the floor by their rise.
        expect(below[0].offset).toBe(-720);
    });

    it("keeps to the flight nearest the walker's height where the line passes beside itself", () => {
        const u = stairPaths({stairs: [{...stair, shape: 'UShaped', risers: 10, firstFlight: 5, gap: 0}], stairRise: 1800, stairsBelow: null});
        // On the landing, between the end of the first flight and the start of the second, the height is the landing's.
        expect(stairSpot(u, {x: 1000, y: 3400}, 900)?.height).toBeCloseTo(900);
        expect(stairSpot(u, {x: 1000, y: 2100}, 100)?.height).toBeLessThan(200);
    });

    it('arriving from below under an upper stair standing over the void, stays at the floor rather than on the upper flight', () => {
        // The same straight stair on both floors, standing over the void: at the top of the lower one the walker is at
        // the floor's level, not a storey up on the upper one's last step.
        const both = stairPaths({stairs: [short], stairRise: 720, stairsBelow: {floorId: 3, rise: 720, stairs: [short]}});
        const spot = stairSpot(both, {x: 1000, y: 2805}, 0);
        expect(spot?.path.below).toBe(true);
        expect(spot === null ? null : spot.path.offset + spot.height).toBeCloseTo(0, -1);
        // Having climbed the upper one to the same spot, the walker is on it.
        expect(stairSpot(both, {x: 1000, y: 2805}, 700)?.path.below).toBe(false);
    });

    it('opens a spiral only round its first riser (or last, from below), not a third of the circle', () => {
        const spiral = stairPaths({stairs: [{...stair, shape: 'Spiral', width: 1400, risers: 12}], stairRise: 2400, stairsBelow: null});
        const open = 32 - stairColliders(spiral).length;
        expect(open).toBeGreaterThanOrEqual(2);
        expect(open).toBeLessThanOrEqual(6);
    });

    it('blocks the sides, opens where the floor\'s stairs start and where the stairs from below arrive', () => {
        // The straight stair's footprint has four edges: the start is open downstairs, the end upstairs.
        expect(stairColliders(own)).toHaveLength(3);
        expect(stairColliders(below)).toHaveLength(3);
        const openStart = (colliders: ReturnType<typeof stairColliders>): boolean =>
            !colliders.some((c) => Math.abs(c.frame.start.y - 2000) < 1 && Math.abs(c.frame.start.y + c.frame.dir.y * c.frame.length - 2000) < 1);
        expect(openStart(stairColliders(own))).toBe(true);
        expect(openStart(stairColliders(below))).toBe(false);
    });

    it('lets the walker under a storey-high flight where it clears the headroom, and stops it where the flight comes down', () => {
        // 16 risers of 180 over 2880 mm, 15 treads of 270: the walking line runs north from (1000, 2000) to (1000, 6050).
        const tall = stairPaths({stairs: [{...stair, risers: 16}], stairRise: 2880, stairsBelow: null});
        const colliders = stairColliders(tall);
        // Under the high part, at the floor: not on the stair, and not stopped beside it.
        expect(stairSpot(tall, {x: 1000, y: 5500}, 0)).toBeNull();
        expect(collideCircle({x: 1000 + 450 + 150, y: 5500}, 200, colliders, 0).x).toBeCloseTo(1000 + 450 + 150);
        // Beside the low part the side stops it, at any level up to the flight's.
        expect(collideCircle({x: 1000 + 450 + 150, y: 2500}, 200, colliders, 0).x).toBeGreaterThan(1000 + 450 + 150);
        // Walking under it from the high end, it stops where the flight comes down to the headroom (2000 mm: 11.1 risers up).
        const under = collideCircle({x: 1000, y: 2000 + 270 * 10.6}, 200, colliders, 0);
        expect(under.y).toBeGreaterThan(2000 + 270 * 10.6);
        // On the flight there, the walker passes.
        expect(collideCircle({x: 1000, y: 2000 + 270 * 10.6}, 200, colliders, 1900).y).toBeCloseTo(2000 + 270 * 10.6);
    });

    it('stops at the stairwell of a stair from below only the walker on the floor, not one on a flight over it', () => {
        const colliders = stairColliders(below);
        const side = {x: 1000 + 450 + 50, y: 2400};
        expect(collideCircle(side, 200, colliders, 0).x).not.toBeCloseTo(side.x);
        expect(collideCircle(side, 200, colliders, 300).x).toBeCloseTo(side.x);
    });

    it('leaves the stairwell from below open where the floor\'s own stair flies over it', () => {
        // The floor's stair starts 300 mm before the closed end of the stairwell (y 2000) and climbs north over it.
        const stacked = stairPaths({stairs: [{...short, y: 1700}], stairRise: 720, stairsBelow: {floorId: 3, rise: 720, stairs: [short]}});
        const onStair = {x: 1000, y: 1850};
        expect(collideCircle(onStair, 200, stairColliders(below), 50).y).not.toBeCloseTo(onStair.y);
        expect(collideCircle(onStair, 200, stairColliders(stacked), 50).y).toBeCloseTo(onStair.y);
    });

    it('lets the walker on a stair from below pass under the floor\'s own flight above it', () => {
        // A storey-high flight north from (1000, 2000) over the stairwell of one coming up beside it, 1000 mm below its crossing.
        const tall = stairPaths({stairs: [{...stair, risers: 16}], stairRise: 2880, stairsBelow: null});
        const under = {x: 1000, y: 2000 + 270 * 10.6};
        expect(collideCircle(under, 200, stairColliders(tall), 0).y).not.toBeCloseTo(under.y);
        expect(collideCircle(under, 200, stairColliders(tall), -1000).y).toBeCloseTo(under.y);
    });

    it('goes on upstairs near the top of the floor\'s stairs, downstairs near the bottom of those from below', () => {
        expect(stairFloorStep(stairSpot(own, {x: 1000, y: 2700}))).toBe(1);
        expect(stairFloorStep(stairSpot(own, {x: 1000, y: 2300}))).toBe(0);
        expect(stairFloorStep(stairSpot(below, {x: 1000, y: 2100}))).toBe(-1);
        expect(stairFloorStep(stairSpot(below, {x: 1000, y: 2700}))).toBe(0);
        expect(stairFloorStep(null)).toBe(0);
    });
});

describe('garden steps', () => {
    const steps: Stair = {...stair, rise: 900, baseZ: -900};

    it('climb a rise of their own from their base, and cut no void', () => {
        const geometry = stairGeometry(steps, 2850);

        expect([geometry.rise, geometry.risers]).toEqual([900, 5]);
        expect(sceneVoids({stairs: [steps, stair], stairRise: 2850, stairsBelow: null}).own).toHaveLength(1);
        const group = buildStair(steps, 2850, new MaterialLibrary([], 1), new THREE.MeshBasicMaterial());
        expect(Math.round(group.position.y * 1000)).toBe(-900);
    });

    it('are walked from their base, open at both ends, and lead to no other floor', () => {
        const [path] = stairPaths({stairs: [steps], stairRise: 2850, stairsBelow: null});
        const top = path.walk[path.walk.length - 1].point;
        const spot = stairSpot([path], {x: top.x, y: top.y - 50});

        expect([path.free, path.offset]).toEqual([true, -900]);
        expect(spot).not.toBeNull();
        expect(stairFloorStep(spot)).toBe(0);
        expect(spot!.path.offset + spot!.height).toBeGreaterThan(-100);
    });
});
