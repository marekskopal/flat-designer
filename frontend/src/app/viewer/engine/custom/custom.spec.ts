import {readdirSync, readFileSync} from 'node:fs';
import {resolve} from 'node:path';

import {FurnitureDefinitionBody} from '@app/models/furniture';
import {Item, Material, SceneDefinition} from '@app/models/scene';
import * as THREE from 'three';
import {describe, expect, it} from 'vitest';

import {buildItem} from '../items';
import {MaterialLibrary} from '../materials';
import {buildCustomItemBody, changedDefinitionIds, definitionLibrary, partMaterial} from './custom';
import {evaluateDefinition} from './definition';

/** The global definitions seeded by the backend (backend/migrations/furniture); vitest runs in frontend/. */
const SeedDirectory = resolve(process.cwd(), '../backend/migrations/furniture');
const seeds = readdirSync(SeedDirectory)
    .filter((f) => f.endsWith('.json'))
    .map((f) => ({file: f, ...(JSON.parse(readFileSync(resolve(SeedDirectory, f), 'utf8')) as {name: string; definition: FurnitureDefinitionBody})}));

const material = (id: number, code: string, color: string): Material => ({
    id,
    code,
    name: code,
    pattern: 'Plain',
    color,
    roughness: 0.5,
    metalness: 0,
    texture: null,
    tileWidth: null,
    tileHeight: null,
});

const materials = (): MaterialLibrary =>
    new MaterialLibrary(
        [
            material(1, 'kitchen-oak', '#b08850'),
            material(2, 'furniture-white-matt', '#f4f4f0'),
            material(3, 'solid-ash-honey', '#c59048'),
            material(4, 'aluminium-brushed', '#c0c0c0'),
            material(5, 'kitchen-green-matt', '#556b4a'),
        ],
        1,
    );

const sceneDefinition = (id: number, definition: FurnitureDefinitionBody, updatedAt = '2026-10-18T10:00:00+00:00'): SceneDefinition => ({
    id,
    name: `Definition ${id}`,
    definition,
    updatedAt,
});

type Size = {width: number; depth: number; height: number};

const customItem = (definitionId: number | null, size: Size, params: Record<string, unknown> = {}): Item => ({
    id: 7,
    roomId: null,
    groupId: null,
    kind: 'Custom',
    definitionId,
    name: 'Custom',
    x: 0,
    y: 0,
    z: 0,
    rotation: 0,
    materialId: null,
    params,
    ...size,
});

function meshes(object: THREE.Object3D): THREE.Mesh[] {
    const found: THREE.Mesh[] = [];
    object.traverse((o) => {
        if (o instanceof THREE.Mesh) {
            found.push(o);
        }
    });
    return found;
}

const shelf = seeds.find((s) => s.file === 'open-shelf.json')?.definition as FurnitureDefinitionBody;

describe('seeded definitions', () => {
    it.each(seeds.map((s) => [s.file, s] as const))('%s builds at its default size', (_, seed) => {
        const size = seed.definition.size ?? {width: 1000, depth: 500, height: 1000};
        const library = definitionLibrary([sceneDefinition(1, seed.definition)]);
        const parts = evaluateDefinition(seed.definition, size);

        const body = buildCustomItemBody(customItem(1, size), materials(), library);

        expect(body?.userData['definitionError']).toBeUndefined();
        expect(parts.length).toBeGreaterThan(0);
        // One mesh per part, plus the handles of fronts.
        expect(meshes(body as THREE.Group).length).toBeGreaterThanOrEqual(parts.length);
    });
});

describe('buildCustomItemBody', () => {
    it('places the parts around the bottom centre of the item', () => {
        const library = definitionLibrary([sceneDefinition(1, shelf)]);
        const body = buildCustomItemBody(customItem(1, {width: 800, depth: 300, height: 1800}), materials(), library) as THREE.Group;

        const sides = body.children.filter((c) => c.userData['partName'] === 'Side');
        expect(sides.map((s) => s.position.x)).toEqual([expect.closeTo((-400 + 9) / 1000), expect.closeTo((400 - 9) / 1000)]);
        expect(sides[0].position.y).toBeCloseTo(0.9);
        expect(sides[0].position.z).toBeCloseTo(0);
        expect(body.children.filter((c) => c.userData['partName'] === 'Shelf')).toHaveLength(5);
    });

    it('draws a red placeholder when the definition is missing or does not fit the item', () => {
        const library = definitionLibrary([sceneDefinition(1, shelf)]);

        const missing = buildItem(customItem(2, {width: 800, depth: 300, height: 1800}), materials(), undefined, library);
        expect(missing.userData).toEqual({definitionError: 'The furniture definition is not in the scene.', itemId: 7});

        const tooNarrow = buildCustomItemBody(customItem(1, {width: 30, depth: 300, height: 1800}), materials(), library);
        expect(tooNarrow?.userData['definitionError']).toBe('parts[1].w: "Shelf" gets a width of -6 mm.');
        expect(meshes(tooNarrow as THREE.Group)).toHaveLength(1);
    });

    it('tolerates material slots decoded as an empty object', () => {
        const parts = shelf.parts.map(({material: _material, ...part}) => part);
        const odd = {...shelf, materialSlots: {} as unknown as [], parts};
        const library = definitionLibrary([sceneDefinition(1, odd)]);
        const body = buildCustomItemBody(customItem(1, {width: 800, depth: 300, height: 1800}), materials(), library);
        expect(body?.userData['definitionError']).toBeUndefined();
        expect(meshes(body as THREE.Group).length).toBeGreaterThan(1);
    });

    it('is null for other kinds', () => {
        expect(buildCustomItemBody({...customItem(1, {width: 1, depth: 1, height: 1}), kind: 'Box'}, materials(), new Map())).toBeNull();
    });
});

describe('partMaterial', () => {
    const slots = [
        {key: 'carcass', default: 'kitchen-oak'},
        {key: 'back', default: 'furniture-white-matt'},
    ];
    const part = (slot: string | null): Parameters<typeof partMaterial>[0] => ({
        name: 'P',
        type: 'board',
        index: null,
        x: 0,
        y: 0,
        z: 0,
        w: 1,
        h: 1,
        d: 1,
        material: slot,
        props: {},
    });
    const color = (m: THREE.Material): string => `#${(m as THREE.MeshStandardMaterial).color.getHexString()}`;

    it('takes the item choice, then the item material for the first slot, then the slot default', () => {
        const library = materials();
        const item = {...customItem(1, {width: 1, depth: 1, height: 1}), materialId: 5};
        expect(color(partMaterial(part('back'), {...item, params: {materials: {back: 3}}}, slots, library, 'vertical'))).toBe('#c59048');
        expect(color(partMaterial(part('carcass'), item, slots, library, 'vertical'))).toBe('#556b4a');
        expect(color(partMaterial(part('back'), item, slots, library, 'vertical'))).toBe('#f4f4f0');
        expect(color(partMaterial(part('carcass'), {...item, materialId: null}, slots, library, 'vertical'))).toBe('#b08850');
        expect(color(partMaterial(part(null), item, slots, library, 'vertical'))).toBe('#556b4a');
    });
});

describe('changedDefinitionIds', () => {
    it('lists new, removed and edited definitions', () => {
        const before = definitionLibrary([sceneDefinition(1, shelf), sceneDefinition(2, shelf), sceneDefinition(3, shelf)]);
        const edited = sceneDefinition(2, shelf, '2026-10-18T11:00:00+00:00');
        const after = definitionLibrary([sceneDefinition(1, shelf), edited, sceneDefinition(4, shelf)]);
        expect([...changedDefinitionIds(before, after)].sort()).toEqual([2, 3, 4]);
    });
});
