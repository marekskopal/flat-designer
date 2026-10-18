import {readFileSync} from 'node:fs';
import {resolve} from 'node:path';

import {FurnitureDefinitionBody} from '@app/models/furniture';
import {CabinetParams, Item, ItemKind, Material, WardrobeParams} from '@app/models/scene';
import * as THREE from 'three';
import {describe, expect, it} from 'vitest';

import {wardrobeDoors} from '../furniture';
import {buildItem} from '../items';
import {MaterialLibrary} from '../materials';
import {definitionLibrary} from './custom';

/**
 * The global definitions of flat 50's furniture (backend/migrations/furniture) against the built-in kinds they replace:
 * real items of the flat built both ways must take the same box, with handles, appliance fronts and mirrors in the same
 * places (within 2 mm). The parameter mapping is the one the conversion migration uses. Vitest runs in frontend/.
 */
const SeedDirectory = resolve(process.cwd(), '../backend/migrations/furniture');
const definition = (file: string): FurnitureDefinitionBody =>
    (JSON.parse(readFileSync(resolve(SeedDirectory, file), 'utf8')) as {definition: FurnitureDefinitionBody}).definition;

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

const library = new MaterialLibrary(
    [
        material(1, 'kitchen-green-matt', '#556b4a'),
        material(2, 'aluminium-brushed', '#c0c0c0'),
        material(3, 'mirror', '#d8dde0'),
        material(4, 'appliance-black-glass', '#101012'),
        material(5, 'solid-ash-honey', '#c59048'),
    ],
    1,
);
const handleMaterial = library.plain('#161616', 0.35, 0.6);
const tracked = [handleMaterial, library.byCode('appliance-black-glass'), library.byCode('mirror')];

type Size = Pick<Item, 'width' | 'depth' | 'height'>;

const item = (kind: ItemKind, size: Size, params: Record<string, unknown>, z = 0, definitionId: number | null = null): Item => ({
    id: 1,
    roomId: null,
    groupId: null,
    kind,
    definitionId,
    name: kind,
    x: 0,
    y: 0,
    z,
    rotation: 0,
    materialId: kind === 'Chair' || kind === 'Table' ? 5 : 1,
    params,
    ...size,
});

/** The parameter values of a definition for a built-in item's params (as the conversion migration maps them). */
function values(kind: ItemKind, built: Item): Record<string, unknown> {
    const p = built.params;
    switch (kind) {
        case 'BaseCabinet':
        case 'WallCabinet':
        case 'TallCabinet': {
            const cabinet = p as CabinetParams;
            return {
                fronts: Array.isArray(cabinet.fronts) && cabinet.fronts.length > 0 ? cabinet.fronts : [{type: 'door'}],
                // A wall cabinet hangs: its definition has no plinth.
                ...(kind === 'WallCabinet' ? {} : {plinth: cabinet.plinth ?? 0}),
                handle: cabinet.handle ?? 'none',
                handleSide: cabinet.handleSide === 'left' ? 'left' : 'right',
                blindLeft: cabinet.blindLeft ?? 0,
                blindRight: cabinet.blindRight ?? 0,
            };
        }
        case 'Wardrobe': {
            const wardrobe = p as WardrobeParams;
            const mirrors = wardrobe.mirrorDoors ?? [];
            const doors = Array.from({length: wardrobeDoors(built)}, (_, i) => (mirrors.includes(i + 1) ? {mirror: true} : {}));
            return {doors, plinth: wardrobe.plinth ?? 60};
        }
        case 'Chair':
            return {seatHeight: p['seatHeight'] ?? 460, backWidth: p['backWidth'] ?? built.width};
        default:
            return {};
    }
}

/** The box of an object, and of its meshes in each tracked material (sorted), in mm. */
function shape(object: THREE.Object3D): {box: number[]; tracked: number[][][]} {
    object.updateMatrixWorld(true);
    const mm = (box: THREE.Box3): number[] => [...box.min.toArray(), ...box.max.toArray()].map((v) => Math.round(v * 1000));
    const meshes: THREE.Mesh[] = [];
    object.traverse((o) => {
        if (o instanceof THREE.Mesh) {
            meshes.push(o);
        }
    });
    return {
        box: mm(new THREE.Box3().setFromObject(object)),
        tracked: tracked.map((m) =>
            meshes
                .filter((mesh) => mesh.material === m)
                .map((mesh) => mm(new THREE.Box3().setFromObject(mesh)))
                .sort((a, b) => a[1] - b[1] || a[0] - b[0] || a[2] - b[2]),
        ),
    };
}

function expectClose(actual: number[][] | number[], expected: number[][] | number[], label: string): void {
    expect(actual.length, label).toBe(expected.length);
    actual.forEach((value, i) => {
        if (Array.isArray(value)) {
            expectClose(value, (expected as number[][])[i], `${label}[${i}]`);
        } else {
            expect(Math.abs(value - (expected as number[])[i]), `${label}[${i}]: ${value} vs ${(expected as number[])[i]}`).toBeLessThanOrEqual(2);
        }
    });
}

const fronts = (...list: Record<string, unknown>[]): Record<string, unknown> => ({fronts: list});

const samples: [string, ItemKind, string, Size, Record<string, unknown>, number?][] = [
    [
        'base cabinet',
        'BaseCabinet',
        'kitchen-base-cabinet.json',
        {width: 500, depth: 580, height: 860},
        {plinth: 100, handle: 'horizontal', ...fronts({type: 'door'})},
    ],
    [
        'corner base with a blind left',
        'BaseCabinet',
        'kitchen-base-cabinet.json',
        {width: 1200, depth: 580, height: 860},
        {plinth: 100, handle: 'horizontal', blindLeft: 580, ...fronts({type: 'door'})},
    ],
    [
        'hob drawers',
        'BaseCabinet',
        'kitchen-base-cabinet.json',
        {width: 800, depth: 580, height: 860},
        {plinth: 100, handle: 'horizontal', ...fronts({type: 'drawer', height: 180}, {type: 'drawer', height: 290}, {type: 'drawer', height: 290})},
    ],
    [
        'hall stand bench with a pair of doors',
        'BaseCabinet',
        'kitchen-base-cabinet.json',
        {width: 1200, depth: 400, height: 450},
        {plinth: 60, handle: 'none', ...fronts({type: 'door', count: 2})},
    ],
    [
        'pair of doors with handles at the meeting edges',
        'BaseCabinet',
        'kitchen-base-cabinet.json',
        {width: 900, depth: 580, height: 860},
        {plinth: 100, handle: 'vertical', ...fronts({type: 'drawer', height: 180}, {type: 'door', count: 2})},
    ],
    [
        'wall cabinet',
        'WallCabinet',
        'kitchen-wall-cabinet.json',
        {width: 480, depth: 350, height: 700},
        {handle: 'none', ...fronts({type: 'door'})},
        1450,
    ],
    [
        'tall fridge with a vertical handle on the left',
        'TallCabinet',
        'kitchen-tall-cabinet.json',
        {width: 600, depth: 600, height: 2600},
        {
            plinth: 100,
            handle: 'vertical',
            handleSide: 'left',
            ...fronts({type: 'door', height: 700}, {type: 'door', height: 1350}, {type: 'door', height: 450, handle: 'none'}),
        },
    ],
    [
        'tall oven and microwave column',
        'TallCabinet',
        'kitchen-tall-cabinet.json',
        {width: 600, depth: 600, height: 2600},
        {
            plinth: 100,
            handle: 'horizontal',
            ...fronts(
                {type: 'drawer', height: 300},
                {type: 'drawer', height: 150},
                {type: 'oven', height: 600},
                {type: 'microwave', height: 400},
                {type: 'door', height: 600},
                {type: 'door', height: 450, handle: 'none'},
            ),
        },
    ],
    ['worktop', 'Worktop', 'worktop.json', {width: 1200, depth: 600, height: 38}, {}, 860],
    ['backsplash', 'Backsplash', 'backsplash.json', {width: 1200, depth: 16, height: 550}, {}, 900],
    [
        'sliding wardrobe with a mirror door',
        'Wardrobe',
        'sliding-wardrobe.json',
        {width: 2468, depth: 600, height: 2400},
        {doors: 4, mirrorDoors: [3], plinth: 60},
    ],
    ['dining chair', 'Chair', 'dining-chair.json', {width: 400, depth: 395, height: 885}, {seatHeight: 460, backWidth: 375}],
    ['dining table', 'Table', 'dining-table.json', {width: 1320, depth: 700, height: 780}, {}],
    ['hall stand back panel', 'Box', 'panel.json', {width: 1200, depth: 18, height: 1400}, {}],
];

/** The handles, appliance fronts and mirrors the built-in kind draws for each sample, so a check cannot pass on nothing. */
const Tracked: Record<string, [number, number, number]> = {
    'base cabinet': [1, 0, 0],
    'corner base with a blind left': [1, 0, 0],
    'hob drawers': [3, 0, 0],
    'hall stand bench with a pair of doors': [0, 0, 0],
    'pair of doors with handles at the meeting edges': [3, 0, 0],
    'wall cabinet': [0, 0, 0],
    'tall fridge with a vertical handle on the left': [2, 0, 0],
    'tall oven and microwave column': [3, 2, 0],
    worktop: [0, 0, 0],
    backsplash: [0, 0, 0],
    'sliding wardrobe with a mirror door': [0, 0, 1],
    'dining chair': [0, 0, 0],
    'dining table': [0, 0, 0],
    'hall stand back panel': [0, 0, 0],
};

describe('flat 50 definitions match the built-in kinds', () => {
    it.each(samples)('%s', (name, kind, file, size, params, z = 0) => {
        const built = item(kind, size, params, z);
        const custom = item('Custom', size, {values: values(kind, built)}, z, 1);
        const definitions = definitionLibrary([{id: 1, name: file, definition: definition(file), updatedAt: ''}]);

        const reference = shape(buildItem(built, library));
        expect(reference.tracked.map((t) => t.length), 'tracked meshes of the built-in kind').toEqual(Tracked[name]);
        const drawn = buildItem(custom, library, undefined, definitions);
        expect(drawn.userData['definitionError']).toBeUndefined();
        const result = shape(drawn);

        expectClose(result.box, reference.box, 'box');
        ['handles', 'appliances', 'mirrors'].forEach((label, i) => expectClose(result.tracked[i], reference.tracked[i], label));
    });
});
