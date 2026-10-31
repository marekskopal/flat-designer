import {readFileSync} from 'node:fs';
import {resolve} from 'node:path';

import {Downpipe, Item, Roof, Scene} from '@app/models/scene';
import * as THREE from 'three';
import {describe, expect, it} from 'vitest';

import {buildChimneysBelow, chimneyStretch, chimneyTop, DefaultAboveRoof, sceneChimneys} from './chimneys';
import {buildItem, itemsOverFloor, roofUnder} from './items';
import {MaterialLibrary} from './materials';
import {MM} from './plan-geometry';
import {roofDownpipes, roofGutters} from './rainwater';
import {RoofShapeFields,roofTopAt} from './roof-geometry';
import {buildRoof} from './roofs';

/** The shared fixture of `RoofGutters` (PHP), as `RoofGuttersTest` reads it. */
const fixture = JSON.parse(readFileSync(resolve(process.cwd(), '../backend/tests/fixtures/roof-gutters.json'), 'utf8')) as {
    cases: {
        name: string;
        roofs: RoofShapeFields[];
        index: number;
        downpipes: Downpipe[] | null;
        runs: {edge: string; from: [number, number]; to: [number, number]; height: number}[];
        pipes: {edge: string; at: [number, number]; outlet: [number, number]; top: number}[];
    }[];
};

describe('roof gutters and downpipes (shared fixture)', () => {
    for (const c of fixture.cases) {
        it(c.name, () => {
            const runs = roofGutters(c.roofs, c.index);
            expect(runs.map((r) => r.edge)).toEqual(c.runs.map((r) => r.edge));
            runs.forEach((run, i) => {
                expect(run.from.x).toBeCloseTo(c.runs[i].from[0], 2);
                expect(run.from.y).toBeCloseTo(c.runs[i].from[1], 2);
                expect(run.to.x).toBeCloseTo(c.runs[i].to[0], 2);
                expect(run.to.y).toBeCloseTo(c.runs[i].to[1], 2);
                expect(run.height).toBeCloseTo(c.runs[i].height, 2);
            });
            const pipes = roofDownpipes(c.roofs[c.index], c.downpipes, runs);
            expect(pipes.map((p) => p.edge)).toEqual(c.pipes.map((p) => p.edge));
            pipes.forEach((pipe, i) => {
                expect(pipe.at.x).toBeCloseTo(c.pipes[i].at[0], 2);
                expect(pipe.at.y).toBeCloseTo(c.pipes[i].at[1], 2);
                expect(pipe.outlet.x).toBeCloseTo(c.pipes[i].outlet[0], 2);
                expect(pipe.outlet.y).toBeCloseTo(c.pipes[i].outlet[1], 2);
                expect(pipe.top).toBeCloseTo(c.pipes[i].top, 2);
            });
        });
    }
});

const gable: Roof = {
    id: 4,
    name: 'Main roof',
    shape: 'Gable',
    x: 5000,
    y: 4000,
    rotation: 0,
    width: 10000,
    depth: 8000,
    eaveHeight: 2600,
    pitch: 45,
    overhang: 400,
    vergeOverhang: 200,
    thickness: 250,
    parapet: null,
    coveringMaterialId: null,
    soffitMaterialId: null,
    windows: [],
};

const chimney = (fields: Partial<Item>): Item => ({
    id: 7,
    roomId: null,
    groupId: null,
    kind: 'Chimney',
    definitionId: null,
    name: 'Chimney',
    x: 5000,
    y: 5000,
    z: 0,
    width: 450,
    depth: 450,
    height: 3000,
    rotation: 0,
    materialId: null,
    params: {},
    ...fields,
});

const materials = new MaterialLibrary([], 1);
const section = materials.sectionFill();

/** The world box of what an object draws, mm, without section-fill twins. */
function bounds(object: THREE.Object3D): THREE.Box3 {
    object.updateMatrixWorld(true);
    const box = new THREE.Box3();
    object.traverse((o) => {
        if (o instanceof THREE.Mesh && o.material !== section && o.material !== materials.sectionFill()) {
            box.expandByObject(o);
        }
    });
    return new THREE.Box3(box.min.divideScalar(MM), box.max.divideScalar(MM));
}

/** Whether a ray straight down at a plan point hits the roof's covering or soffit. */
function hitsRoof(roof: THREE.Object3D, x: number, y: number): boolean {
    roof.updateMatrixWorld(true);
    const ray = new THREE.Raycaster(new THREE.Vector3(x * MM, 100, -y * MM), new THREE.Vector3(0, -1, 0));
    return ray.intersectObject(roof, true).some((hit) => (hit.object as THREE.Mesh).material !== section);
}

describe('chimneys', () => {
    const scene = (fields: Partial<Scene>): Pick<Scene, 'items' | 'roofs' | 'chimneyFloors' | 'voidsAbove'> => ({items: [], ...fields});

    it('rises to aboveRoof over the highest roof covering its outline, else to its own height', () => {
        // On the back plane, its outline's front corners (y 4775) lie highest, nearest the ridge at y 4000.
        const top = roofTopAt(gable, 5225, 4775) ?? 0;
        expect(top).toBeGreaterThan(roofTopAt(gable, 5225, 5225) ?? 0);
        expect(chimneyTop(chimney({}), 0, scene({roofs: [gable]}))).toBeCloseTo(top + DefaultAboveRoof, 6);
        expect(chimneyTop(chimney({params: {aboveRoof: 1000}}), 0, scene({roofs: [gable]}))).toBeCloseTo(top + 1000, 6);
        expect(chimneyTop(chimney({z: 150}), 0, scene({}))).toBe(3150);
    });

    it('reaches over the roofs of the floors above, and draws its storey up to the next floor', () => {
        // The roof is the attic's, 2900 mm up; the ground floor draws the chimney to the attic's level.
        const upper = scene({
            chimneyFloors: [{floorId: 2, offset: 2900, roofs: [gable], chimneys: []}],
            voidsAbove: {floorId: 2, rise: 2900, slabThickness: 250, voids: []},
        });
        const top = chimneyTop(chimney({}), 0, upper);
        expect(top).toBeCloseTo((roofTopAt(gable, 5225, 4775) ?? 0) + 2900 + DefaultAboveRoof, 6);
        expect(chimneyStretch(chimney({}), 0, upper)).toEqual({bottom: 0, top: 2900, cap: false});

        // The attic draws the ground floor's chimney from its level up to its top, with the cap.
        const attic = scene({roofs: [gable], chimneyFloors: [{floorId: 1, offset: -2900, roofs: [], chimneys: [chimney({})]}]});
        const [below] = sceneChimneys(attic);
        expect(below.offset).toBe(-2900);
        expect(below.stretch.bottom).toBe(0);
        expect(below.stretch.top).toBeCloseTo(top - 2900, 6);
        expect(below.stretch.cap).toBe(true);
        // In place, untagged.
        const drawn = bounds(buildChimneysBelow(attic, materials));
        expect(drawn.min.y).toBeCloseTo(0, 3);
        expect(drawn.max.y).toBeGreaterThan(top - 2900);

        // A chimney of a floor below ending under this floor is not drawn here.
        const low = scene({chimneyFloors: [{floorId: 1, offset: -3500, roofs: [], chimneys: [chimney({})]}]});
        expect(sceneChimneys(low)).toEqual([]);
    });

    it('is built as an item up to its stretch, the cap above its top', () => {
        const stretches = new Map([[7, {bottom: 0, top: 6000, cap: true}]]);
        const assets = {models: new Map(), modelLibrary: null, pictures: null, chimneys: stretches};
        const box = bounds(buildItem(chimney({}), materials, undefined, undefined, assets));
        // The cap's plate: 200 mm legs and 25 mm.
        expect(box.max.y).toBeCloseTo(6225, 3);
        expect(box.max.x - box.min.x).toBeCloseTo(570, 3);
        const plain = bounds(buildItem(chimney({params: {cap: false}}), materials, undefined, undefined, assets));
        expect(plain.max.y).toBeCloseTo(6000, 3);
    });

    it('is cut out of the roof it passes through', () => {
        const outline: [number, number][] = [
            [4775, 4775],
            [5225, 4775],
            [5225, 5225],
            [4775, 5225],
        ];
        expect(hitsRoof(buildRoof(gable, materials, section), 5000, 5000)).toBe(true);
        const cut = buildRoof(gable, materials, section, [], {chimneys: [outline]});
        expect(hitsRoof(cut, 5000, 5000)).toBe(false);
        expect(hitsRoof(cut, 5000, 6000)).toBe(true);

        // One straddling the ridge (y 4000) splits the ridge cap: the ridge is hit either side of it, not at it.
        const ridge: [number, number][] = outline.map(([x, y]) => [x, y - 1000]);
        const split = buildRoof(gable, materials, section, [], {chimneys: [ridge]});
        expect(hitsRoof(buildRoof(gable, materials, section), 5000, 4000)).toBe(true);
        expect(hitsRoof(split, 5000, 4000)).toBe(false);
        expect(hitsRoof(split, 3000, 4000)).toBe(true);
        expect(hitsRoof(split, 7000, 4000)).toBe(true);
    });

    it('is drawn up its own storey in the stack and with others at the same level (FD-91 review)', () => {
        const stretch = {bottom: 0, top: 2900, cap: false};
        const level = scene({chimneyFloors: [{floorId: 3, offset: 0, roofs: [gable], chimneys: [chimney({})]}]});
        const [same] = sceneChimneys(level);
        expect(same.own).toBe(false);
        expect(same.stretch.bottom).toBe(0);
        const below = scene({items: [chimney({})], voidsAbove: {floorId: 2, rise: 2900, slabThickness: 250, voids: []}});
        expect(sceneChimneys(below)[0].stretch).toEqual(stretch);
    });
});

describe('roof details', () => {
    it('hangs gutters at the eaves and runs the downpipes to the ground', () => {
        const bare = bounds(buildRoof(gable, materials, section));
        const guttered = bounds(buildRoof({...gable, gutters: 'HalfRound'}, materials, section, [], {ground: () => -300}));
        expect(guttered.min.y).toBeCloseTo(-300, 3);
        // The gutters hang 10 mm in front of the fascia, 140 mm across.
        expect(-guttered.max.z - -bare.max.z).toBeCloseTo(-150, 0);
        // No downpipes: the gutters' bottoms only.
        const noPipes = bounds(buildRoof({...gable, gutters: 'Box', downpipes: []}, materials, section));
        expect(noPipes.min.y).toBeGreaterThan(2000);
    });

    it('lays rows of snow guards and draws the ridge in its style', () => {
        const steel = materials.plain('#3b3e41', 0.5, 0.6);
        const uses = (roof: THREE.Object3D, material: THREE.Material): boolean => {
            let found = false;
            roof.traverse((o) => (found ||= o instanceof THREE.Mesh && o.material === material));
            return found;
        };
        expect(uses(buildRoof({...gable, snowGuards: 2}, materials, section), steel)).toBe(true);
        expect(uses(buildRoof(gable, materials, section), steel)).toBe(false);
        // Half-round ridge tiles stand 120 mm over the ridge line, the box cap 35 mm.
        const box = bounds(buildRoof(gable, materials, section)).max.y;
        expect(bounds(buildRoof({...gable, ridgeStyle: 'Round'}, materials, section)).max.y - box).toBeCloseTo(120 - 35, 1);
    });
});

describe('items on the roof', () => {
    const panel: Item = {...chimney({}), id: 9, kind: 'Box', name: 'Panel', x: 5000, y: 2000, z: 50, width: 1000, depth: 1700, height: 40};
    const onRoof: Item = {...panel, onRoof: true};
    const assets = {models: new Map(), modelLibrary: null, pictures: null, roofs: [gable]};

    it('lie on the slope under their centre, z out along its normal', () => {
        const object = buildItem(onRoof, materials, undefined, undefined, assets);
        const top = roofTopAt(gable, 5000, 2000) ?? 0;
        // 45°: z goes half along up, half towards the eaves (plan −y, scene +z).
        expect(object.position.y / MM).toBeCloseTo(top + 50 * Math.SQRT1_2, 3);
        expect(object.position.z / MM).toBeCloseTo(-2000 + 50 * Math.SQRT1_2, 3);
        const up = new THREE.Vector3(0, 1, 0).applyQuaternion(object.quaternion);
        expect(up.angleTo(new THREE.Vector3(0, 1, 0))).toBeCloseTo(Math.PI / 4, 6);
    });

    it('count from the roof in the drawings, and stay as they are off it', () => {
        expect(itemsOverFloor({items: [onRoof], roofs: [gable]})[0].z).toBe(50 + Math.round(roofTopAt(gable, 5000, 2000) ?? 0));
        expect(itemsOverFloor({items: [panel], roofs: [gable]})[0].z).toBe(50);
        expect(roofUnder([gable], 20000, 0)).toBeNull();
        const off = buildItem({...onRoof, x: 20000}, materials, undefined, undefined, assets);
        expect(off.position.y / MM).toBeCloseTo(50, 6);
    });
});
