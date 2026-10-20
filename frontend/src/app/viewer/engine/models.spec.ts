import {Item, SceneModel} from '@app/models/scene';
import * as THREE from 'three';
import {describe, expect, it} from 'vitest';

import {buildModelItemBody, ModelLibrary} from './models';

const model: SceneModel = {
    id: 7,
    name: 'Butterfly chair',
    width: 760,
    depth: 800,
    height: 860,
    license: 'CC0',
    author: '',
    sourceUrl: '',
    updatedAt: '2026-10-20T10:00:00+00:00',
};

const item = (fields: Partial<Item> = {}): Item => ({
    id: 1,
    roomId: null,
    groupId: null,
    kind: 'Model',
    definitionId: null,
    modelId: 7,
    name: 'Chair',
    x: 0,
    y: 0,
    z: 0,
    width: 760,
    depth: 800,
    height: 860,
    rotation: 0,
    materialId: null,
    params: {},
    ...fields,
});

/** A library whose model is either loaded (a 1 × 1 × 1 m cube standing on y = 0), loading or failed. */
const library = (state: 'loaded' | 'loading' | 'failed'): ModelLibrary =>
    ({
        instance: (): THREE.Object3D | null => {
            if (state !== 'loaded') {
                return null;
            }
            const cube = new THREE.Mesh(new THREE.BoxGeometry(1, 1, 1));
            cube.position.y = 0.5;
            return new THREE.Group().add(cube);
        },
        hasFailed: (): boolean => state === 'failed',
    }) as unknown as ModelLibrary;

const models = new Map([[7, model]]);

describe('buildModelItemBody', () => {
    it('is null for other kinds', () => {
        expect(buildModelItemBody(item({kind: 'Box'}), models, library('loaded'))).toBeNull();
    });

    it('scales the model evenly to fit the item box', () => {
        // Twice as wide, as deep as the model and as high: the smallest ratio (1) wins, the model keeps its proportions.
        const body = buildModelItemBody(item({width: 1520}), models, library('loaded'))!;
        expect(body.children[0].scale.x).toBeCloseTo(1, 6);
        const half = buildModelItemBody(item({width: 380, depth: 800, height: 860}), models, library('loaded'))!;
        expect(half.children[0].scale.x).toBeCloseTo(0.5, 6);
        expect(half.userData['modelPending']).toBeUndefined();
    });

    it('stands in a light box while the model loads, a red one when it is missing', () => {
        const pending = buildModelItemBody(item(), models, library('loading'))!;
        expect(pending.userData['modelPending']).toBe(true);
        const box = new THREE.Box3().setFromObject(pending);
        expect(box.getSize(new THREE.Vector3()).toArray().map((v) => Math.round(v * 1000))).toEqual([760, 860, 800]);
        expect(box.min.y).toBeCloseTo(0, 6);

        for (const missing of [buildModelItemBody(item(), models, library('failed'))!, buildModelItemBody(item({modelId: 99}), models, null)!]) {
            expect(missing.userData['modelPending']).toBe(false);
            expect(((missing.children[0] as THREE.Mesh).material as THREE.MeshStandardMaterial).color.getHexString()).toBe('c0504d');
        }
    });
});
