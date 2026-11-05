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
        box.union(new THREE.Box3().setFromObject(mesh, true));
    }
    return new THREE.Box3(box.min.clone().divideScalar(MM), box.max.clone().divideScalar(MM));
}

describe('rebated room doors', () => {
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

describe('hinges of the other doors and windows', () => {
    const build = (fields: Partial<Opening>): {group: THREE.Group; pivots: THREE.Object3D[]} => {
        const group = buildWall(wallWith([opening(fields)]), materials, new THREE.MeshBasicMaterial());
        return {group, pivots: leafObjects(group)};
    };
    const inPivots = (pivots: readonly THREE.Object3D[], mesh: THREE.Mesh): boolean => pivots.some((p) => p.getObjectById(mesh.id) !== undefined);

    /** Every leaf of the opening stays at or past `jamb` (plan x) along its way, and its frame's hinges stay put. */
    const sweepsClear = (fields: Partial<Opening>, jamb: number): void => {
        const {group, pivots} = build(fields);
        const frameHinges = meshes(group, (m) => isHinge(m) && !inPivots(pivots, m));
        expect(frameHinges.length).toBeGreaterThan(0);
        expect(pivots.every((p) => meshes(p, isHinge).length > 0)).toBe(true);
        group.updateMatrixWorld(true);
        const frameBox = boxOf(frameHinges);
        for (const open of [0, 0.2, 0.5, 0.8, 1]) {
            pivots.forEach((p) => setLeafPose(p, open));
            group.updateMatrixWorld(true);
            // A sash's hinge covers merge with its bars (they share the frame's material); they stay past the jamb too.
            expect(boxOf(meshes(pivots[0], (m) => !isHinge(m) || fields.type === 'Window')).min.x).toBeGreaterThan(jamb - 1e-3);
            expect(boxOf(frameHinges)).toEqual(frameBox);
        }
    };

    it('hangs a door in a bare hole or an unrebated lining on barrel hinges, clear of the reveal', () => {
        sweepsClear({frameStyle: 'None'}, 1000);
        sweepsClear({leafWidth: 880}, 1000);
    });

    it('hangs a door in a flush frame on concealed hinges', () => {
        sweepsClear({frameStyle: 'Flush'}, 1000);
    });

    it('hangs a side-hinged garage door on strap hinges, clear of the wall', () => {
        sweepsClear({type: 'GarageDoor', garageDoorStyle: 'SideHinged', frameStyle: 'None', width: 2400, height: 2125}, 1000);
    });

    it('hangs a French window\'s sashes on corner hinges, clear of the jamb', () => {
        // The jamb's edge is at 1070; only the covers on the hinge corner, standing in front of the frame's face, pass it.
        sweepsClear({type: 'Window', walkable: true, width: 1400, height: 2100}, 1066);
    });

    it('puts a French window sash\'s grip on the face it swings to, whichever way it closes', () => {
        for (const hinge of ['Start', 'End'] as const) {
            for (const side of ['Left', 'Right'] as const) {
                const {group, pivots} = build({type: 'Window', walkable: true, mullions: 0, width: 1000, height: 2100, hinge, side});
                setLeafPose(pivots[0], 0);
                group.updateMatrixWorld(true);
                // The face it swings to is plan +across on the left, which is world −z. The sash's faces are 35 and 25 mm
                // off the wall's centre line; the grip stands 25 mm proud of the one it swings to.
                const sash = boxOf(meshes(pivots[0], () => true));
                const [towards, away] = side === 'Left' ? [-sash.min.z, sash.max.z] : [sash.max.z, -sash.min.z];
                expect(towards).toBeGreaterThan(55);
                expect(away).toBeLessThan(30);
            }
        }
    });

    it('wraps the reveal in a steel frame, the leaf in its rebate', () => {
        const {group, pivots} = build({frameStyle: 'Steel'});
        pivots.forEach((p) => setLeafPose(p, 0));
        group.updateMatrixWorld(true);
        const door = group.getObjectByName('door:') as THREE.Object3D;
        const frame = boxOf(meshes(door, (m) => !isHinge(m) && !inPivots(pivots, m)));
        expect([frame.min.x, frame.max.x, frame.min.z, frame.max.z].map((v) => Math.round(v * 10) / 10)).toEqual([990, 1910, -101.5, 101.5]);
        const leaf = boxOf(meshes(pivots[0], (m) => !isHinge(m)));
        expect(leaf.min.x).toBeGreaterThan(1038);
        // The leaf's face is flush with the frame's face on its side.
        expect(Math.round(leaf.min.z * 10) / 10).toBe(-101.5);
    });

    it('opens a double door\'s passive leaf after the active one', () => {
        const {pivots} = build({type: 'DoubleDoor', width: 1400});
        const closed = pivots.map((p) => {
            setLeafPose(p, 0);
            return p.rotation.y;
        });
        pivots.forEach((p) => setLeafPose(p, 0.2));
        expect(pivots[0].rotation.y).not.toBeCloseTo(closed[0]);
        expect(pivots[1].rotation.y).toBeCloseTo(closed[1]);
    });
});
