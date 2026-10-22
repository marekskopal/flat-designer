import {readFileSync} from 'node:fs';
import {resolve} from 'node:path';

import {FurnitureDefinitionBody} from '@app/models/furniture';
import {Item, Material} from '@app/models/scene';
import * as THREE from 'three';
import {describe, expect, it} from 'vitest';

import {buildItem} from '../items';
import {MaterialLibrary} from '../materials';
import {definitionLibrary} from './custom';

/**
 * The global definitions of flat 50's furniture (backend/migrations/furniture) against the built-in kinds they replaced:
 * real items of the flat must take the same box, with handles, appliance fronts and mirrors in the same places (within
 * 2 mm), as the built-in kinds drew them. `parity-reference.json` holds what those drew (recorded before they were
 * removed) and the parameter values the conversion migration mapped their params to. Vitest runs in frontend/.
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

interface Sample {
    name: string;
    file: string;
    size: Pick<Item, 'width' | 'depth' | 'height'>;
    z: number;
    materialId: number;
    values: Record<string, unknown>;
    reference: Shape;
}

interface Shape {
    box: number[];
    tracked: number[][][];
}

const samples = JSON.parse(readFileSync(resolve(process.cwd(), 'src/app/viewer/engine/custom/parity-reference.json'), 'utf8')) as Sample[];

/** The box of an object, and of its meshes in each tracked material (sorted), in mm. */
function shape(object: THREE.Object3D): Shape {
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

describe('flat 50 definitions match the built-in kinds they replaced', () => {
    it.each(samples.map((sample): [string, Sample] => [sample.name, sample]))('%s', (_name, sample) => {
        const custom: Item = {
            id: 1,
            roomId: null,
            groupId: null,
            kind: 'Custom',
            definitionId: 1,
            name: sample.name,
            x: 0,
            y: 0,
            z: sample.z,
            rotation: 0,
            materialId: sample.materialId,
            params: {values: sample.values},
            ...sample.size,
        };
        const definitions = definitionLibrary([{id: 1, name: sample.file, definition: definition(sample.file), updatedAt: ''}]);

        const drawn = buildItem(custom, library, undefined, definitions);
        expect(drawn.userData['definitionError']).toBeUndefined();
        const result = shape(drawn);

        expectClose(result.box, sample.reference.box, 'box');
        ['handles', 'appliances', 'mirrors'].forEach((label, i) => expectClose(result.tracked[i], sample.reference.tracked[i], label));
    });
});
