import {readFileSync} from 'node:fs';
import {resolve} from 'node:path';

import {FloorVoid, Material, PlanPoint, Railing, Room, Scene, Stair} from '@app/models/scene';
import * as THREE from 'three';
import {describe, expect, it} from 'vitest';

import {MaterialLibrary} from './materials';
import {MM} from './plan-geometry';
import {BalusterSize, buildRailing, offsetLine, railingColliders, RailingPost, WallRailOffset} from './railings';
import {buildStair, sceneVoids, StairPath} from './stairs';
import {buildVoidLinings, buildVoidOutline, insetOutline, LiningThickness, netRoomArea, voidColliders} from './voids';

function material(id: number, code: string): Material {
    return {id, code, name: code, pattern: 'Plain', color: '#a07040', roughness: 0.6, metalness: 0, texture: null, tileWidth: null, tileHeight: null};
}

const materials = new MaterialLibrary([material(1, 'oak'), material(2, 'glass')], 1);

const square = (x: number, y: number, side: number): PlanPoint[] => [
    [x, y],
    [x + side, y],
    [x + side, y + side],
    [x, y + side],
];

const floorVoid = (fields: Partial<FloorVoid>): FloorVoid => ({
    id: 1,
    name: 'Void',
    polygon: square(1000, 1000, 2000),
    area: 4,
    liningMaterialId: 1,
    liningDepth: null,
    ...fields,
});

const room = (fields: Partial<Room>): Room => ({
    id: 1,
    code: 'R1',
    name: 'Hall',
    type: 'Hallway',
    polygon: square(0, 0, 4000),
    area: 16,
    perimeter: 16,
    ceilingHeight: 2600,
    floorMaterialId: null,
    wallFinishes: [],
    tileOriginX: null,
    tileOriginY: null,
    ...fields,
});

const railing = (fields: Partial<Railing>): Railing => ({
    id: 7,
    name: 'Railing',
    points: [
        [0, 0],
        [2000, 0],
    ],
    height: 1000,
    baseZ: 0,
    style: 'Balusters',
    spacing: null,
    postSpacing: 1200,
    handrail: 'Round',
    materialId: null,
    infillMaterialId: null,
    ...fields,
});

const stair: Stair = {
    id: 3,
    name: 'Stairs',
    shape: 'Straight',
    x: 2000,
    y: 0,
    rotation: 0,
    width: 900,
    going: 250,
    risers: 16,
    firstFlight: null,
    turn: 'Left',
    gap: 100,
    construction: 'Stringers',
    railing: 'None',
    treadMaterialId: null,
    structureMaterialId: null,
};

const scene = (fields: Partial<Scene>): Scene =>
    ({
        floor: {id: 1, name: 'Upper', level: 1, elevation: 3000, slabThickness: 250},
        materials: [],
        rooms: [room({})],
        walls: [],
        items: [],
        ...fields,
    }) as Scene;

/** The bounds of a group's meshes in plan mm (y up). */
function bounds(group: THREE.Object3D): THREE.Box3 {
    group.updateMatrixWorld(true);
    const box = new THREE.Box3().setFromObject(group);
    return new THREE.Box3(box.min.clone().divideScalar(MM), box.max.clone().divideScalar(MM));
}

function meshes(group: THREE.Object3D): THREE.Mesh[] {
    const found: THREE.Mesh[] = [];
    group.traverse((o) => o instanceof THREE.Mesh && found.push(o));
    return found;
}

/** Shared with the backend twin (`VoidAreaTest`): the area the voids take, mm². */
const fixture = JSON.parse(readFileSync(resolve(process.cwd(), '../backend/tests/fixtures/void-area.json'), 'utf8')) as {
    cases: {name: string; room: PlanPoint[]; voids: PlanPoint[][]; area: number}[];
};

describe('netRoomArea (shared fixture)', () => {
    it.each(fixture.cases)('$name', (testCase) => {
        const gross = 100;
        const net = netRoomArea({area: gross, polygon: testCase.room}, testCase.voids.map((polygon) => ({polygon})));
        expect(gross - net).toBeCloseTo(testCase.area / 1e6, 2);
    });
});

describe('floor voids (FD-90)', () => {
    it('cut the floor and slab of their floor, and the ceilings of the floor below', () => {
        const hole = floorVoid({});
        expect(sceneVoids(scene({voids: [hole]})).below).toEqual([hole.polygon]);
        const above = {floorId: 2, rise: 3000, slabThickness: 250, voids: [hole]};
        expect(sceneVoids(scene({voidsAbove: above})).own).toEqual([hole.polygon]);
    });

    it('line their floor through its slab, down to the lining depth', () => {
        const box = bounds(buildVoidLinings(scene({voids: [floorVoid({})]}), materials));
        expect([Math.round(box.min.y), Math.round(box.max.y)]).toEqual([-250, 0]);
        // The boards stand inside the outline.
        expect([Math.round(box.min.x), Math.round(box.max.x)]).toEqual([1000, 3000]);
        const shallow = bounds(buildVoidLinings(scene({voids: [floorVoid({liningDepth: 100})]}), materials));
        expect(Math.round(shallow.min.y)).toBe(-100);
        expect(buildVoidLinings(scene({voids: [floorVoid({liningMaterialId: null})]}), materials).children).toHaveLength(0);
    });

    it('line the well over the floor below from its ceiling up to the slab above', () => {
        const above = {floorId: 2, rise: 3000, slabThickness: 250, voids: [floorVoid({})]};
        const box = bounds(buildVoidLinings(scene({voidsAbove: above}), materials));
        expect([Math.round(box.min.y), Math.round(box.max.y)]).toEqual([2600, 2750]);
        // A lining 400 deep stops 150 under the slab; one within the slab leaves nothing here.
        const deep = {...above, voids: [floorVoid({liningDepth: 400})]};
        expect(Math.round(bounds(buildVoidLinings(scene({voidsAbove: deep}), materials)).min.y)).toBe(2600);
        const deeper = {...above, voids: [floorVoid({liningDepth: 300})]};
        expect(Math.round(bounds(buildVoidLinings(scene({voidsAbove: deeper}), materials)).min.y)).toBe(2700);
        const within = {...above, voids: [floorVoid({liningDepth: 200})]};
        expect(buildVoidLinings(scene({voidsAbove: within}), materials).children).toHaveLength(0);
    });

    it("line a stair's void when it asks for it, upstairs and down", () => {
        const lined = {...stair, voidLiningMaterialId: 1};
        const up = bounds(buildVoidLinings(scene({stairsBelow: {floorId: 0, rise: 3000, stairs: [lined]}}), materials));
        expect([Math.round(up.min.y), Math.round(up.max.y)]).toEqual([-250, 0]);
        const above = {floorId: 2, rise: 3000, slabThickness: 250, voids: []};
        const down = bounds(buildVoidLinings(scene({stairs: [{...lined, x: 2000, y: 500}], stairRise: 3000, voidsAbove: above}), materials));
        expect([Math.round(down.min.y), Math.round(down.max.y)]).toEqual([2600, 2750]);
    });

    it('inset an outline square to its edges, and take the voids off the rooms they cut', () => {
        const inset = insetOutline(
            square(0, 0, 1000).map(([x, y]) => ({x, y})),
            LiningThickness,
        );
        expect(inset.map((p) => [Math.round(p.x), Math.round(p.y)])).toEqual([
            [20, 20],
            [980, 20],
            [980, 980],
            [20, 980],
        ]);
        // A quarter of the void lies outside the room.
        expect(netRoomArea(room({}), [floorVoid({polygon: square(3000, 3000, 2000)})])).toBe(15);
    });

    it('are picked by their outline in the top view, and stop the walker except where a stair arrives', () => {
        const outline = buildVoidOutline(floorVoid({id: 9}));
        expect(outline.userData).toEqual({voidId: 9, planOnly: true});
        expect(voidColliders([floorVoid({})], [])).toHaveLength(4);
        const path = (footprint: PlanPoint[]): StairPath => ({below: true, footprint: footprint.map(([x, y]) => ({x, y}))}) as unknown as StairPath;
        expect(voidColliders([floorVoid({})], [path(square(1000, 1000, 2000))])).toHaveLength(0);
        // A 900 mm stair arriving at the west end of the south edge: that stretch is the stair's, the rest stays closed.
        const stretches = voidColliders([floorVoid({})], [path([[1000, 0], [1900, 0], [1900, 1600], [1000, 1600]])]);
        const south = stretches.filter((c) => c.frame.start.y === 1000 && c.frame.dir.x === 1).map((c) => [Math.round(c.from), Math.round(c.to)]);
        expect(south).toEqual([[900, 2000]]);
        // The west edge (running south from (1000, 3000)) is crossed by the footprint's north side at 1600: closed down to it.
        const west = stretches.filter((c) => c.frame.start.x === 1000 && c.frame.dir.y < 0).map((c) => [Math.round(c.from), Math.round(c.to)]);
        expect(west).toEqual([[0, 1400]]);
        expect(stretches.length).toBe(4);
    });
});

describe('railings (FD-90)', () => {
    it('stand posts at their ends and at most the post spacing apart, balusters between', () => {
        const group = buildRailing(railing({}), materials);
        expect(group.userData).toEqual({railingId: 7});
        const box = bounds(group);
        // The top of the handrail (a ten-sided tube) at the railing's height.
        expect(Math.round(box.min.y)).toBe(0);
        expect(box.max.y).toBeCloseTo(1000, -1);
        expect(Math.round(box.max.x - box.min.x)).toBe(2000 + RailingPost);
        expect(BalusterSize).toBe(25);
    });

    it('stands one post where a closed run meets its start', () => {
        // Glass without a handrail: the steel is the corner posts only, merged into one mesh; a closed run has as many
        // as an open one through the same four corners.
        const glass = materials.byCode('glass');
        const posts = (points: PlanPoint[]): number =>
            meshes(buildRailing(railing({points, style: 'Glass', handrail: 'None'}), materials))
                .filter((m) => m.material !== glass)
                .reduce((sum, m) => sum + m.geometry.getAttribute('position').count, 0);
        const corners: PlanPoint[] = [[0, 0], [1000, 0], [1000, 1000], [0, 1000]];
        expect(posts(corners)).toBeGreaterThan(0);
        expect(posts([...corners, [0, 0]])).toBe(posts(corners));
    });

    it('stand on their base and take glass between the posts', () => {
        const glass = buildRailing(railing({style: 'Glass', handrail: 'None', baseZ: 450}), materials);
        expect(glass.position.y).toBeCloseTo(0.45);
        expect(meshes(glass).some((m) => m.material === materials.byCode('glass'))).toBe(true);
    });

    it('block the walker along every stretch', () => {
        const run = railing({points: [[0, 0], [2000, 0], [2000, 2000]]});
        expect(railingColliders([run]).map((c) => Math.round(c.frame.length))).toEqual([2000, 2000]);
    });

    it('offset a line to its right, mitred', () => {
        const line = offsetLine(
            [
                {point: {x: 0, y: 0}, top: 900},
                {point: {x: 1000, y: 0}, top: 900},
                {point: {x: 1000, y: 1000}, top: 900},
            ],
            WallRailOffset,
        );
        expect(line.map((p) => [Math.round(p.point.x), Math.round(p.point.y)])).toEqual([
            [0, -60],
            [1060, -60],
            [1060, 1000],
        ]);
    });

    it('fill a stair rail with its style and add a wall handrail', () => {
        const count = (s: Stair): number => meshes(buildStair(s, 3000, materials, new THREE.MeshBasicMaterial())).length;
        const plain = count({...stair, railing: 'Left'});
        // Merged per material, a styled rail adds the infill's material meshes, a wall rail its own.
        const glazed = count({...stair, railing: 'Left', railingStyle: 'Glass'});
        expect(glazed).toBeGreaterThan(plain);
        const walled = buildStair({...stair, railing: 'None', wallHandrail: 'Right'}, 3000, materials, new THREE.MeshBasicMaterial());
        const box = bounds(walled);
        // The flight's right side at +450 of its middle, the rail 60 in from it.
        expect(Math.round(box.max.x)).toBeLessThanOrEqual(2000 + 450);
        expect(Math.round(box.max.x)).toBeGreaterThan(2000 + 450 - WallRailOffset - 30);
    });
});
