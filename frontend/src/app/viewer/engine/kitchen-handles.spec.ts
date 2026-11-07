import * as THREE from 'three';
import {describe, expect, it} from 'vitest';

import {buildFrontHandle, handlePositionOf} from './kitchen';
import {MaterialLibrary} from './materials';

const materials = new MaterialLibrary([], 1);

/** A tall door on a 100 mm plinth: from 100 to 2000 mm, below reach height to well above it. */
const tallDoor = {left: 0, width: 600, bottom: 0, height: 1900};

/** The handle's box in mm (item-local). */
function box(handle: THREE.Object3D | null): THREE.Box3 {
    expect(handle).not.toBeNull();
    const bounds = new THREE.Box3().setFromObject(handle as THREE.Object3D);
    return new THREE.Box3(bounds.min.clone().multiplyScalar(1000), bounds.max.clone().multiplyScalar(1000));
}

describe('front handle positions', () => {
    it('reads unknown positions as auto', () => {
        expect(handlePositionOf('top')).toBe('top');
        expect(handlePositionOf('middle')).toBe('auto');
        expect(handlePositionOf(undefined)).toBe('auto');
    });

    it('stands an edge pull up the handle edge of a tall door by default, and lays it on the edge asked for', () => {
        const upright = box(buildFrontHandle('edge', tallDoor, 19, 100, 'right', false, materials));
        expect(upright.max.y - upright.min.y).toBeGreaterThan(100);

        // The pull at the split of two doors: on the top edge, at the door's handle side.
        const top = box(buildFrontHandle('edge', tallDoor, 19, 100, 'right', false, materials, {position: 'top', length: 300}));
        expect(Math.round(top.max.y)).toBe(1900 + 3);
        expect(Math.round(top.max.x)).toBe(600 - 40);
        expect(Math.round(top.max.x - top.min.x)).toBe(300);

        const bottom = box(buildFrontHandle('edge', tallDoor, 19, 100, 'left', false, materials, {position: 'bottom', length: 300}));
        expect(Math.round(bottom.min.y)).toBe(-3);
        expect(Math.round(bottom.min.x)).toBe(40);
    });

    it('runs a gola channel along the edge asked for, in the handle material when there is one', () => {
        const side = box(buildFrontHandle('gola', tallDoor, 19, 100, 'right', false, materials));
        expect(Math.round(side.max.y - side.min.y)).toBe(1900);

        const own = new THREE.MeshStandardMaterial();
        const channel = buildFrontHandle('gola', tallDoor, 19, 100, 'right', false, materials, {material: own, position: 'top'});
        expect((channel as THREE.Mesh).material).toBe(own);
        const top = box(channel);
        expect(Math.round(top.max.x - top.min.x)).toBe(600);
        expect(Math.round(top.max.y)).toBe(1900);
    });

    it('puts a vertical bar at the split of two doors, and a drawer knob on its handle edge when asked', () => {
        const bar = box(buildFrontHandle('vertical', tallDoor, 19, 100, 'right', false, materials, {position: 'bottom', length: 300}));
        expect(Math.round(bar.min.y)).toBe(80);
        const drawer = {left: 0, width: 600, bottom: 0, height: 400};
        const knob = box(buildFrontHandle('knob', drawer, 19, 900, 'left', true, materials, {position: 'side'}));
        expect(Math.round(knob.getCenter(new THREE.Vector3()).x)).toBe(45);
    });

    it('puts a knob at the bottom of a base unit door when asked, though its top is within reach', () => {
        const door = {left: 0, width: 400, bottom: 0, height: 700};
        const auto = box(buildFrontHandle('knob', door, 19, 100, 'right', false, materials));
        const bottom = box(buildFrontHandle('knob', door, 19, 100, 'right', false, materials, {position: 'bottom'}));
        expect(auto.getCenter(new THREE.Vector3()).y).toBeGreaterThan(600);
        expect(Math.round(bottom.getCenter(new THREE.Vector3()).y)).toBe(60);
    });
});
