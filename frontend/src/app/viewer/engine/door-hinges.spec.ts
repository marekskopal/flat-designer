import {Material, Opening, Wall} from '@app/models/scene';
import * as THREE from 'three';
import {describe, expect, it} from 'vitest';

import {buildWall, setLeafPose} from './building';
import {leafObjects} from './doors';
import {MaterialLibrary} from './materials';
import {hingeHeights} from './opening-looks';
import {doorRebate, MM, openingLeaves} from './plan-geometry';

function opening(fields: Partial<Opening>): Opening {
    return {
        id: 1,
        name: '',
        type: 'Door',
        offset: 1000,
        width: 900,
        height: 2000,
        sillHeight: 0,
        leafWidth: null,
        hinge: 'Start',
        side: 'Left',
        entrance: false,
        ...fields,
    };
}

function wallWith(openings: Opening[]): Wall {
    return {
        id: 9,
        name: 'Wall',
        type: 'Interior',
        startX: 0,
        startY: 0,
        endX: 6000,
        endY: 0,
        thickness: 200,
        height: 2600,
        materialId: null,
        openings,
    } as unknown as Wall;
}

function material(id: number, code: string): Material {
    return {id, code, name: code, pattern: 'Plain', color: '#a07040', roughness: 0.6, metalness: 0, texture: null, tileWidth: null, tileHeight: null};
}

const materials = new MaterialLibrary([material(1, 'oak')], 1);

function isHinge(mesh: THREE.Mesh): boolean {
    return mesh.name.includes('hinge') || mesh.userData['partName'] === 'hinge';
}

function meshes(root: THREE.Object3D, wanted: (mesh: THREE.Mesh) => boolean): THREE.Mesh[] {
    const found: THREE.Mesh[] = [];
    root.traverse((o) => o instanceof THREE.Mesh && wanted(o) && found.push(o));
    return found;
}

/** The world box of the meshes, mm. */
function boxOf(list: readonly THREE.Mesh[]): THREE.Box3 {
    const box = new THREE.Box3();
    for (const mesh of list) {
        mesh.updateWorldMatrix(true, false);
        box.union(new THREE.Box3().setFromObject(mesh));
    }
    return new THREE.Box3(box.min.clone().divideScalar(MM), box.max.clone().divideScalar(MM));
}

describe('rebated room doors (FD-105)', () => {
    const door = opening({});

    it('hangs a leaf on two hinges, three when it is tall', () => {
        expect(hingeHeights(1960)).toEqual([250, 1710]);
        expect(hingeHeights(2400)).toEqual([250, 1200, 2150]);
    });

    it('reads the rebate of a lining or architrave frame only', () => {
        expect(doorRebate(door, 200)).toEqual({stop: 50, head: 50, rebate: 38, overlap: 13, front: 110, back: -110});
        expect(doorRebate({...door, frameStyle: 'Architrave', side: 'Right'}, 200)).toMatchObject({front: -125, back: 110});
        expect(doorRebate({...door, frameStyle: 'Flush'}, 200)).toBeNull();
        expect(doorRebate({...door, type: 'SlidingDoor'}, 200)).toBeNull();
    });

    it('puts the pivot on the hinge axis, the leaf where it was closed', () => {
        const group = buildWall(wallWith([door]), materials, new THREE.MeshBasicMaterial());
        const [pivot] = leafObjects(group);
        setLeafPose(pivot, 0);
        group.updateMatrixWorld(true);
        // The axis 2 mm off the lip's edge, 7 mm in front of the leaf's face (which is flush with the lining's).
        expect([pivot.position.x / MM, pivot.position.z / MM].map((v) => Math.round(v * 1000) / 1000)).toEqual([1038, -117]);
        const leaf = boxOf(meshes(pivot, (m) => !isHinge(m)));
        expect(Math.round(leaf.min.x)).toBe(1040);
        expect(Math.round(leaf.max.x)).toBeLessThanOrEqual(1860);
    });

    it('swings the leaf clear of the frame, the hinges turning on their axis', () => {
        const group = buildWall(wallWith([door]), materials, new THREE.MeshBasicMaterial());
        const [pivot] = leafObjects(group);
        const frameHinges = meshes(group, (m) => isHinge(m) && !pivot.getObjectById(m.id));
        expect(frameHinges.length).toBeGreaterThan(0);
        expect(meshes(pivot, isHinge).length).toBeGreaterThan(0);
        const frameBox = boxOf(frameHinges);
        for (const open of [0, 0.2, 0.5, 0.8, 1]) {
            setLeafPose(pivot, open);
            group.updateMatrixWorld(true);
            // Nothing of the leaf reaches back past the jamb's rebate (1038).
            expect(boxOf(meshes(pivot, (m) => !isHinge(m))).min.x).toBeGreaterThan(1038 - 1e-6);
            expect(boxOf(frameHinges)).toEqual(frameBox);
        }
    });

    it('hangs both leaves of a double door, and no sliding, pocket or lift-and-slide door', () => {
        const double = buildWall(wallWith([opening({type: 'DoubleDoor', width: 1400})]), materials, new THREE.MeshBasicMaterial());
        expect(leafObjects(double).map((p) => meshes(p, isHinge).length > 0)).toEqual([true, true]);
        for (const type of ['SlidingDoor', 'PocketDoor'] as const) {
            const wall = buildWall(wallWith([opening({type})]), materials, new THREE.MeshBasicMaterial());
            expect(meshes(wall, isHinge)).toEqual([]);
        }
        const liftSlide = buildWall(wallWith([opening({type: 'SlidingDoor', width: 3000, fixedPanes: 2})]), materials, new THREE.MeshBasicMaterial());
        expect(meshes(liftSlide, isHinge)).toEqual([]);
    });

    it('keeps the leaves of a rebated door inside the opening less its rebates', () => {
        for (const hinge of ['Start', 'End'] as const) {
            for (const leaf of openingLeaves(opening({hinge}), 200)) {
                const [a, b] = [leaf.along, leaf.along + leaf.closed * leaf.width].sort((x, y) => x - y);
                expect(a).toBeGreaterThanOrEqual(1000 + 38 + 2);
                expect(b).toBeLessThanOrEqual(1900 - 38 - 2);
            }
        }
    });
});
