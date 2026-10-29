import {Material, Opening, Room, Wall} from '@app/models/scene';
import * as THREE from 'three';
import {describe, expect, it} from 'vitest';

import {buildSlab, buildWall} from './building';
import {buildFacades, facadeBands} from './facades';
import {MaterialLibrary} from './materials';
import {MM} from './plan-geometry';
import {selectionOf} from './selection';

const material = (id: number, code: string): Material => ({
    id,
    code,
    name: code,
    pattern: 'Plain',
    color: '#808080',
    roughness: 0.5,
    metalness: 0,
    texture: null,
    tileWidth: null,
    tileHeight: null,
});
const materials = new MaterialLibrary([material(1, 'render'), material(2, 'larch'), material(3, 'plinth')], 1);
const wall = (id: number, start: [number, number], end: [number, number], fields: Partial<Wall> = {}): Wall => ({
    id,
    name: 'Wall',
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
    openings: [],
    ...fields,
});
// A box drawn counter-clockwise: the left faces look in, the right faces out.
const box = (south: Partial<Wall> = {}): Wall[] => [
    wall(1, [-150, -150], [4150, -150], south),
    wall(2, [4150, -150], [4150, 3150]),
    wall(3, [4150, 3150], [-150, 3150]),
    wall(4, [-150, 3150], [-150, -150]),
];
const room: Room = {
    id: 1,
    code: 'L',
    name: 'Living',
    type: 'Living',
    polygon: [
        [0, 0],
        [4000, 0],
        [4000, 3000],
        [0, 3000],
    ],
    area: 12,
    ceilingHeight: 2600,
    floorMaterialId: null,
    wallFinishes: [],
    tileOriginX: null,
    tileOriginY: null,
};
const window = (fields: Partial<Opening> = {}): Opening => ({
    id: 9,
    name: 'W',
    type: 'Window',
    offset: 1650,
    width: 1000,
    height: 1200,
    sillHeight: 900,
    leafWidth: null,
    hinge: null,
    side: 'Left',
    entrance: false,
    ...fields,
});
const meshesOf = (group: THREE.Object3D, code: string): THREE.Mesh[] => {
    const found: THREE.Mesh[] = [];
    group.traverse((o) => o instanceof THREE.Mesh && o.material === materials.byCode(code) && found.push(o));
    return found;
};
const mm = (v: number): number => Math.round(v / MM);

describe('buildFacades', () => {
    it('lines the outer face with its own bands, from a plinth below the floor up to the top', () => {
        const bands = [
            {materialId: 3, bottom: -300, top: 400},
            {materialId: 1, bottom: 400, top: 5000},
        ];
        const group = buildFacades({walls: box({rightFinishes: bands}), rooms: [room]}, materials);
        const box3 = new THREE.Box3().setFromObject(meshesOf(group, 'plinth')[0]);
        const render = new THREE.Box3().setFromObject(meshesOf(group, 'render')[0]);

        // On the south wall's outer face (plan y −300), 20 mm off it, along its mitred length.
        expect([mm(box3.min.y), mm(box3.max.y)]).toEqual([-300, 400]);
        expect([mm(render.min.y), mm(render.max.y)]).toEqual([400, 2600]);
        expect([mm(box3.min.x), mm(box3.max.x)]).toEqual([-300, 4300]);
        expect([mm(-box3.max.z), mm(-box3.min.z)]).toEqual([-320, -300]);
        // The inner faces border the room: nothing there. A click on the band picks the wall.
        expect(meshesOf(group, 'larch')).toHaveLength(0);
        expect(selectionOf(meshesOf(group, 'plinth')[0])).toEqual({kind: 'wall', id: 1});
    });

    it('stops a band under a sloping top', () => {
        const bands = [{materialId: 1, bottom: 0, top: 1500}];
        const walls = [wall(5, [0, -150], [6000, -150], {height: 2000, heightEnd: 800, rightFinishes: bands})];
        const group = buildFacades({walls, rooms: []}, materials);
        group.updateMatrixWorld(true);
        const topAt = (x: number): number => {
            const ray = new THREE.Raycaster(new THREE.Vector3(x * MM, 5, 0.31), new THREE.Vector3(0, -1, 0));
            return Math.round(ray.intersectObjects(meshesOf(group, 'render'))[0].point.y / MM);
        };

        // 2000 → 800 along 6 m: 1500 at x 2500 (the band's own top before that), 900 at x 5500.
        expect(topAt(1000)).toBe(1500);
        expect(topAt(5500)).toBe(900);
    });

    it('leaves the openings of the face free', () => {
        const bands = [{materialId: 2, bottom: 0, top: 2600}];
        const group = buildFacades({walls: box({rightFinishes: bands, openings: [window()]}), rooms: [room]}, materials);
        const hit = (x: number, y: number): number =>
            new THREE.Raycaster(new THREE.Vector3(x * MM, y * MM, 1), new THREE.Vector3(0, 0, -1)).intersectObjects(meshesOf(group, 'larch')).length;

        group.updateMatrixWorld(true);
        // The window spans x 1500–2500 (offset 1650 from the wall's start at −150), sill 900 to head 2100.
        expect(hit(2000, 1500)).toBe(0);
        expect(hit(2000, 500)).toBeGreaterThan(0);
        expect(hit(1000, 1500)).toBeGreaterThan(0);
    });

    it('gives the exterior walls nobody set the project\'s façade, and nothing else', () => {
        expect(facadeBands(box()[0], 'Right', 1)).toEqual([{materialId: 1, bottom: 0, top: 100000}]);
        expect(facadeBands(box()[0], 'Right', null)).toEqual([]);
        expect(facadeBands({...box()[0], materialId: 2}, 'Right', 1)).toEqual([]);
        expect(facadeBands({...box()[0], rightFinishes: []}, 'Right', 1)).toEqual([]);
        expect(facadeBands({...box()[0], type: 'Interior'}, 'Right', 1)).toEqual([]);
        const group = buildFacades({walls: box(), rooms: [room], exteriorMaterialId: 1}, materials);
        expect(meshesOf(group, 'render').length).toBeGreaterThan(0);
    });
});

describe('slab soffits', () => {
    it('puts a room\'s piece of the slab in its soffit material, else the façade\'s', () => {
        const used = (slab: THREE.Object3D): THREE.Material[] => {
            const found: THREE.Material[] = [];
            slab.traverse((o) => o instanceof THREE.Mesh && found.push(o.material as THREE.Material));
            return found;
        };
        expect(used(buildSlab([], [{...room, soffitMaterialId: 2}], 300, materials, [], 1))).toEqual([materials.byCode('larch')]);
        expect(used(buildSlab([], [room], 300, materials, [], 1))).toEqual([materials.byCode('render')]);
        // The walls' pieces stay the plain slab, merged apart from the room's.
        expect(used(buildSlab(box(), [{...room, soffitMaterialId: 2}], 300, materials, [], null))).toHaveLength(2);
    });
});

describe('reveal lining', () => {
    it('lines the jambs, head and sill of an opening with the outer face\'s band', () => {
        const larch = [{materialId: 2, bottom: 0, top: 2600}];
        const lined = buildWall({...box()[0], rightFinishes: larch, openings: [window({revealLining: true})]}, materials, materials.sectionFill());
        const bare = buildWall({...box()[0], rightFinishes: larch, openings: [window()]}, materials, materials.sectionFill());

        // The boards merge into the window's group: they show as the larch in the wall.
        const larchBox = new THREE.Box3();
        meshesOf(lined, 'larch').forEach((m) => larchBox.expandByObject(m));
        expect(mm(larchBox.max.y - larchBox.min.y)).toBe(1200);
        expect(meshesOf(bare, 'larch')).toHaveLength(0);
    });
});
