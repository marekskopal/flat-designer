import {Item} from '@app/models/scene';
import * as THREE from 'three';
import {describe, expect, it} from 'vitest';

import {MaterialLibrary} from './materials';
import {buildOutdoorItemBody, fenceFollows} from './outdoor';

const materials = new MaterialLibrary([], 1);
const fence = (params: Record<string, unknown>): Item => ({
    id: 1,
    roomId: null,
    groupId: null,
    kind: 'Fence',
    definitionId: null,
    name: 'Fence',
    x: 0,
    y: 0,
    z: 0,
    width: 5000,
    depth: 60,
    height: 1500,
    rotation: 0,
    materialId: null,
    params,
});
// The ground falls 1 mm per 10 mm along the fence: +250 at its start, −250 at its end.
const slope = (x: number): number => -x / 10;
const box = (object: THREE.Object3D): THREE.Box3 => new THREE.Box3().setFromObject(object);
const mm = (v: number): number => Math.round(v * 1000);

describe('fences', () => {
    it('stay level without follow, as before', () => {
        const level = buildOutdoorItemBody(fence({}), materials, slope)!;

        expect([mm(box(level).min.y), mm(box(level).max.y)]).toEqual([0, 1500]);
        expect(fenceFollows(fence({}))).toBe(false);
    });

    it('rake with the ground, the posts standing on it', () => {
        const rake = buildOutdoorItemBody(fence({follow: 'Rake', panelLength: 2500}), materials, slope)!;

        expect(fenceFollows(fence({follow: 'Rake'}))).toBe(true);
        // The posts at −2500, 0 and 2500 stand on +250, 0 and −250.
        expect([mm(box(rake).min.y), mm(box(rake).max.y)]).toEqual([-250, 1750]);
    });

    it('step per bay, each level over the highest ground under it', () => {
        const step = buildOutdoorItemBody(fence({follow: 'Step', style: 'Boards', panelLength: 2500}), materials, slope)!;

        // The first bay stands on +250 (its start) and reaches 1750; the last post on −250.
        expect([mm(box(step).min.y), mm(box(step).max.y)]).toEqual([-250, 1750]);
    });

    it('slide open as a gate', () => {
        const closed = buildOutdoorItemBody(fence({gate: true, style: 'Boards'}), materials)!;
        const open = buildOutdoorItemBody(fence({gate: true, style: 'Boards', open: 0.5}), materials)!;
        const leaf = (g: THREE.Object3D): THREE.Object3D => g.getObjectByName('gate-leaf')!;

        expect(mm(leaf(closed).position.x)).toBe(0);
        expect(mm(leaf(open).position.x)).toBe(2500);
    });
});
