import {Opening, Wall} from '@app/models/scene';
import * as THREE from 'three';

import {setLeafPose} from './building';
import {LeafStates} from './doors';
import {MM, openingLeaves} from './plan-geometry';

function opening(id: number, type: Opening['type'], walkable = false): Opening {
    const placement = {offset: 100 + id * 1500, width: 1200, height: 2200, sillHeight: 0};
    return {id, name: '', type, ...placement, leafWidth: null, hinge: null, side: null, entrance: false, walkable};
}

function wallWith(openings: Opening[]): Wall {
    return {startX: 0, startY: 0, endX: 6000, endY: 0, thickness: 200, height: 2600, openings} as Wall;
}

describe('LeafStates', () => {
    const walls = [wallWith([opening(1, 'Door'), opening(2, 'Window', true), opening(3, 'Window')])];
    let states: LeafStates;

    beforeEach(() => {
        states = new LeafStates();
        states.reset(walls);
    });

    it('shows doors open and French windows closed; plain windows do not open', () => {
        expect(states.openness(1)).toBe(1);
        expect(states.openness(2)).toBe(0);
        expect(states.has(2)).toBe(true);
        expect(states.has(3)).toBe(false);
    });

    it('toggles with a swing over time', () => {
        expect(states.toggle(1)).toBe(false);
        expect(states.isOpen(1)).toBe(false);
        expect(states.update(0.1)).toBe(true);
        expect(states.openness(1)).toBeGreaterThan(0);
        expect(states.openness(1)).toBeLessThan(1);
        for (let i = 0; i < 10; i++) {
            states.update(0.1);
        }
        expect(states.openness(1)).toBe(0);
        expect(states.update(0.1)).toBe(false);
    });

    it('reverses mid-swing', () => {
        states.toggle(1);
        states.update(0.1);
        const halfway = states.openness(1);
        expect(states.toggle(1)).toBe(true);
        states.update(0.05);
        expect(states.openness(1)).toBeGreaterThan(halfway);
    });

    it('eases the swing: slow at the ends, the same for the meshes and colliders', () => {
        states.setOpen(2, true);
        states.update(0.045);
        expect(states.swing(2)).toBeGreaterThan(0);
        expect(states.swing(2)).toBeLessThan(states.openness(2));
    });

    it('closes everything for a walk and goes back to the display state after it', () => {
        states.closeAll();
        states.setOpen(2, true, false);
        expect([states.openness(1), states.openness(2)]).toEqual([0, 1]);

        states.reset(walls, true);
        expect([states.isOpen(1), states.isOpen(2)]).toEqual([true, false]);
        expect([states.openness(1), states.openness(2)]).toEqual([0, 1]);
        states.reset(walls);
        expect([states.openness(1), states.openness(2)]).toEqual([1, 0]);
    });

    it('keeps the states through a live update, adding and dropping openings', () => {
        states.closeAll();
        states.sync([wallWith([opening(1, 'Door'), opening(3, 'Door')])]);
        expect(states.openness(1)).toBe(0);
        expect(states.openness(3)).toBe(1);
        expect(states.has(2)).toBe(false);
    });

    it('swings the tagged leaf objects', () => {
        const leaf = openingLeaves({...opening(1, 'Door'), hinge: 'End', side: 'Right'}, 200)[0];
        const pivot = new THREE.Object3D();
        pivot.userData = {leaf};
        states.closeAll();
        states.apply([pivot]);
        // Closed, the leaf points from the hinge at the opening's end back towards its start: local −x.
        expect(Math.abs(pivot.rotation.y)).toBeCloseTo(Math.PI);

        states.setOpen(1, true, false);
        states.apply([pivot]);
        const expected = new THREE.Object3D();
        expected.userData = {leaf};
        setLeafPose(expected, 1);
        expect(pivot.rotation.y).toBeCloseTo(expected.rotation.y);
        // Swinging to the right of the wall: plan −y, scene +z.
        expect(new THREE.Vector3(1, 0, 0).applyEuler(pivot.rotation).z).toBeGreaterThan(0.9);
    });

    it('opens every door kind with a leaf and slides the sliding ones', () => {
        states.sync([wallWith([opening(1, 'SlidingDoor'), opening(2, 'Passage')])]);
        expect(states.openness(1)).toBe(1);
        expect(states.has(2)).toBe(false);

        const leaf = {...openingLeaves({...opening(1, 'SlidingDoor'), hinge: 'End'}, 200)[0]};
        const pivot = new THREE.Object3D();
        pivot.userData = {leaf};
        states.closeAll();
        states.apply([pivot]);
        expect(pivot.position.x).toBeCloseTo(leaf.along * MM);
        states.setOpen(1, true, false);
        states.apply([pivot]);
        expect(pivot.position.x).toBeCloseTo((leaf.along + leaf.travel) * MM);
        expect(pivot.rotation.y).toBe(0);
    });
});
