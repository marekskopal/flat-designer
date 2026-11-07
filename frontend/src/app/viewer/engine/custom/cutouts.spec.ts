import {readFileSync} from 'node:fs';
import {resolve} from 'node:path';

import {FurnitureDefinitionBody} from '@app/models/furniture';
import {Item, ItemKind, SceneDefinition} from '@app/models/scene';
import * as THREE from 'three';
import {describe, expect, it} from 'vitest';

import {buildItem, ItemAssets} from '../items';
import {MaterialLibrary} from '../materials';
import {MM} from '../plan-geometry';
import {DefinitionLibrary, definitionLibrary} from './custom';
import {cutoutRoleOf, partsToPlan, planToParts, worktopCutters} from './cutouts';

/** The global definitions seeded by the backend (backend/migrations/furniture); vitest runs in frontend/. */
const seed = (file: string): FurnitureDefinitionBody =>
    (JSON.parse(readFileSync(resolve(process.cwd(), '../backend/migrations/furniture', file), 'utf8')) as {definition: FurnitureDefinitionBody})
        .definition;

const Worktop = 1;
const LWorktop = 2;
const Island = 3;
const Sink = 4;
const Hob = 5;

const definitions: DefinitionLibrary = definitionLibrary(
    [
        [Worktop, 'worktop.json'],
        [LWorktop, 'l-worktop.json'],
        [Island, 'island-worktop.json'],
        [Sink, 'kitchen-sink.json'],
        [Hob, 'hob.json'],
    ].map(([id, file]): SceneDefinition => ({id: id as number, name: file as string, definition: seed(file as string), updatedAt: '2026-11-07'})),
);

let nextId = 1;

const item = (definitionId: number | null, fields: Partial<Item>, kind: ItemKind = 'Custom'): Item => ({
    id: nextId++,
    roomId: null,
    groupId: null,
    kind,
    definitionId,
    name: 'Item',
    x: 0,
    y: 0,
    z: 0,
    width: 600,
    depth: 600,
    height: 38,
    rotation: 0,
    materialId: null,
    params: {},
    ...fields,
});

/** A straight worktop 2400 × 600 on 872 mm base cabinets, its top at 910: x 0…2400, y 0…600 at rotation 0. */
const worktop = (fields: Partial<Item> = {}): Item => item(Worktop, {x: 1200, y: 300, z: 872, width: 2400, depth: 600, height: 38, ...fields});

const sink = (fields: Partial<Item> = {}, values: Record<string, unknown> = {}): Item =>
    item(Sink, {x: 1600, y: 300, z: 910, width: 800, depth: 500, height: 6, params: {values}, ...fields});

const hob = (fields: Partial<Item> = {}): Item => item(Hob, {x: 600, y: 300, z: 910, width: 600, depth: 520, height: 6, ...fields});

function build(target: Item, others: readonly Item[], hidden: ReadonlySet<number> = new Set()): THREE.Object3D {
    const cutters = worktopCutters([target, ...others], definitions, hidden);
    const assets: ItemAssets = {models: new Map(), modelLibrary: null, pictures: null, cutters};
    const object = buildItem(target, new MaterialLibrary([], 1), undefined, definitions, assets);
    object.updateMatrixWorld(true);
    return object;
}

/** The height (mm) where a ray down at the plan point first meets the object; null where it passes through. */
function topAt(object: THREE.Object3D, x: number, y: number): number | null {
    const ray = new THREE.Raycaster(new THREE.Vector3(x * MM, 5, -y * MM), new THREE.Vector3(0, -1, 0));
    const hit = ray.intersectObject(object, true)[0];
    return hit === undefined ? null : Math.round(hit.point.y / MM);
}

describe('worktop cut-outs', () => {
    it('reads the cut-out roles of the seeded worktops, sink and hob', () => {
        expect([Worktop, LWorktop, Island, Sink, Hob].map((id) => cutoutRoleOf(definitions.get(id)?.definition))).toEqual([
            'worktop',
            'worktop',
            'worktop',
            'sink',
            'hob',
        ]);
    });

    it('turns points of the parts frame into the plan and back', () => {
        const turned = item(null, {x: 1000, y: 2000, width: 800, depth: 400, rotation: 90});
        // At 90° the width runs north and the front faces east.
        expect(partsToPlan(turned, 0, 0).map(Math.round)).toEqual([800, 1600]);
        expect(partsToPlan(turned, 800, 400).map(Math.round)).toEqual([1200, 2400]);
        expect(planToParts(turned, 1100, 1700).map(Math.round)).toEqual([100, 300]);
    });

    it('cuts an inset sink out of the worktop, its footprint less the rim', () => {
        const object = build(worktop(), [sink()]);
        // The sink spans x 1200…2000 and y 50…550; the hole 20 mm inside.
        expect(topAt(object, 1600, 300)).toBeNull();
        expect(topAt(object, 1225, 300)).toBeNull();
        expect(topAt(object, 1215, 300)).toBe(910);
        expect(topAt(object, 1600, 75)).toBeNull();
        expect(topAt(object, 1600, 65)).toBe(910);
        expect(topAt(object, 800, 300)).toBe(910);
    });

    it('keeps the cut board textured like an uncut one', () => {
        const object = build(worktop(), [sink()]);
        const meshes: THREE.Mesh[] = [];
        object.traverse((o) => o instanceof THREE.Mesh && meshes.push(o));
        expect(meshes).toHaveLength(1);
        const geometry = meshes[0].geometry;
        expect(geometry.getAttribute('uv').count).toBe(geometry.getAttribute('position').count);
        // On the top face u runs along the width from the board's left end and v from its back to the front, in metres,
        // as on a box.
        geometry.computeBoundingBox();
        const box = geometry.boundingBox as THREE.Box3;
        const position = geometry.getAttribute('position');
        const uv = geometry.getAttribute('uv');
        const normal = geometry.getAttribute('normal');
        let top = 0;
        for (let i = 0; i < position.count; i++) {
            if (normal.getY(i) > 0.9) {
                top++;
                expect(uv.getX(i)).toBeCloseTo(position.getX(i) - box.min.x, 5);
                expect(uv.getY(i)).toBeCloseTo(box.max.z - position.getZ(i), 5);
            }
        }
        expect(top).toBeGreaterThan(0);
        expect(box.max.x - box.min.x).toBeCloseTo(2.4, 5);
    });

    it('cuts a sink turned with a turned worktop', () => {
        // Both at 90°: their widths run north, the sink y 1200…2000.
        const object = build(worktop({x: 300, y: 1200, rotation: 90}), [sink({x: 300, y: 1600, rotation: 90})]);
        expect(topAt(object, 300, 1600)).toBeNull();
        expect(topAt(object, 300, 1225)).toBeNull();
        expect(topAt(object, 300, 1215)).toBe(910);
        expect(topAt(object, 300, 800)).toBe(910);
    });

    it('cuts a sink across a worktop turned the other way, splitting its slab', () => {
        // The worktop at 270° runs north, x 0…600; the sink at 0° is wider than it (x −100…700, y 1350…1850).
        const object = build(worktop({x: 300, y: 1200, rotation: 270}), [sink({x: 300, y: 1600})]);
        expect(topAt(object, 300, 1600)).toBeNull();
        expect(topAt(object, 10, 1600)).toBeNull();
        expect(topAt(object, 300, 1360)).toBe(910);
        expect(topAt(object, 300, 1380)).toBeNull();
        expect(topAt(object, 300, 1900)).toBe(910);
    });

    it('cuts each bowl of an undermount sink, the worktop over their edges', () => {
        // Two bowls 373 wide at x 12 and 415 of the sink (left edge at 1200), the worktop 3 mm over their edges.
        const object = build(worktop(), [sink({}, {style: 'undermount', bowls: 2})]);
        expect(topAt(object, 1400, 300)).toBeNull();
        expect(topAt(object, 1800, 300)).toBeNull();
        expect(topAt(object, 1600, 300)).toBe(910);
        expect(topAt(object, 1213, 300)).toBe(910);
        expect(topAt(object, 1217, 300)).toBeNull();
    });

    it('cuts a hob, its footprint less 10 mm', () => {
        const object = build(worktop(), [hob()]);
        expect(topAt(object, 600, 300)).toBeNull();
        expect(topAt(object, 315, 300)).toBeNull();
        expect(topAt(object, 305, 300)).toBe(910);
    });

    it('cuts the old built-in sink and hob kinds', () => {
        const object = build(worktop(), [
            item(null, {x: 1600, y: 300, z: 910, width: 800, depth: 500, height: 250}, 'Sink'),
            item(null, {x: 600, y: 300, z: 910, width: 600, depth: 520, height: 6}, 'Hob'),
        ]);
        expect(topAt(object, 1600, 300)).toBeNull();
        expect(topAt(object, 600, 300)).toBeNull();
        expect(topAt(object, 1100, 300)).toBe(910);
    });

    it('cuts nothing for a sink elsewhere, at another height, of a hidden variant or a farmhouse sink', () => {
        expect(topAt(build(worktop(), [sink({x: 5000})]), 1600, 300)).toBe(910);
        expect(topAt(build(worktop(), [sink({z: 950})]), 1600, 300)).toBe(910);
        expect(topAt(build(worktop(), [sink({z: 920})]), 1600, 300)).toBeNull();
        const hidden = sink();
        expect(topAt(build(worktop(), [hidden], new Set([hidden.id])), 1600, 300)).toBe(910);
        expect(topAt(build(worktop(), [sink({}, {style: 'farmhouse'})]), 1600, 300)).toBe(910);
    });

    it('leaves items that are not worktops whole', () => {
        // A plain board under the sink: a definition without the worktop role.
        const shelf = item(Hob, {x: 1600, y: 300, z: 904, width: 800, depth: 500, height: 6});
        expect(topAt(build(shelf, [sink()]), 1600, 300)).toBe(910);
    });

    it('cuts the L-shaped worktop and the island worktop', () => {
        // The L 2400 × 1800 at 0°: the run 620 deep along the back (plan y 1180…1800), the return 620 wide on the left.
        const l = item(LWorktop, {x: 1200, y: 900, z: 872, width: 2400, depth: 1800, height: 38});
        const onRun = build(l, [sink({x: 1600, y: 1490, rotation: 180})]);
        expect(topAt(onRun, 1600, 1490)).toBeNull();
        expect(topAt(onRun, 800, 1490)).toBe(910);
        const onReturn = build(l, [hob({x: 310, y: 600, rotation: 270})]);
        expect(topAt(onReturn, 310, 600)).toBeNull();
        expect(topAt(onReturn, 310, 1000)).toBe(910);

        // The island's top lies at its height, its waterfall ends reach the floor.
        const island = item(Island, {x: 1200, y: 500, z: 0, width: 2400, depth: 1000, height: 910});
        const cut = build(island, [sink({x: 1200, y: 500})]);
        expect(topAt(cut, 1200, 500)).toBeNull();
        expect(topAt(cut, 600, 500)).toBe(910);
    });
});
