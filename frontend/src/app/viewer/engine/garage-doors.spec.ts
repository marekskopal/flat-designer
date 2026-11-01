import {Material, Opening, Wall} from '@app/models/scene';
import * as THREE from 'three';
import {describe, expect, it} from 'vitest';

import {buildWall, setLeafPose} from './building';
import {leafObjects,LeafStates} from './doors';
import {MaterialLibrary} from './materials';
import {
    frameBand,
    GarageOverlap,
    isLiftSlide,
    leafColliders,
    LiftClear,
    LiftSlideFrameDepth,
    LiftSlideTrack,
    MM,
    occupiedSpan,
    openingLeaves,
    WindowFrameBar,
} from './plan-geometry';

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

function wallWith(openings: Opening[], thickness = 300): Wall {
    return {
        id: 9,
        name: 'Wall',
        type: 'Exterior',
        startX: 0,
        startY: 0,
        endX: 9000,
        endY: 0,
        thickness,
        height: 2600,
        materialId: null,
        openings,
    } as unknown as Wall;
}

function material(id: number, code: string): Material {
    return {id, code, name: code, pattern: 'Plain', color: '#a07040', roughness: 0.6, metalness: 0, texture: null, tileWidth: null, tileHeight: null};
}

const materials = new MaterialLibrary([material(1, 'oak'), material(2, 'alu')], 1);

/** The plain meshes under `root` drawn in `material`. */
function meshesIn(root: THREE.Object3D, wanted: THREE.Material): THREE.Mesh[] {
    const found: THREE.Mesh[] = [];
    root.traverse((o) => o instanceof THREE.Mesh && o.material === wanted && found.push(o));
    return found;
}

describe('frame band (FD-89)', () => {
    it('centres a window, spans the wall with a door lining, and sets a frame in from the outer face', () => {
        expect(frameBand(opening({type: 'Window'}), 300)).toEqual({centre: 0, depth: 80});
        expect(frameBand(opening({}), 300)).toEqual({centre: 0, depth: 300});
        // A lift-and-slide door's frame is as deep as its two tracks, set in or not.
        expect(frameBand(opening({type: 'SlidingDoor', fixedPanes: 2}), 300)).toEqual({centre: 0, depth: LiftSlideFrameDepth});
        expect(frameBand(opening({type: 'SlidingDoor', fixedPanes: 2, inset: 20}), 300)).toEqual({
            centre: -(150 - 20 - LiftSlideFrameDepth / 2),
            depth: LiftSlideFrameDepth,
        });
        // The interior on the left: the outer face is the right one, −150.
        expect(frameBand(opening({type: 'Window', inset: 0}), 300)).toEqual({centre: -110, depth: 80});
        expect(frameBand(opening({type: 'Window', inset: 50, side: 'Right'}), 300)).toEqual({centre: 60, depth: 80});
    });

    it('closes a door set in flush with its frame on the side it swings to', () => {
        const [leaf] = openingLeaves(opening({inset: 100}), 300);
        // The frame from −50 to +30: the leaf's 40 mm on its left face.
        expect(leaf.across).toBe(10);
    });
});

describe('lift-and-slide doors (FD-89)', () => {
    const door = opening({type: 'SlidingDoor', width: 3640, fixedPanes: 2, hinge: 'Start'});

    it('divides the opening into panes, the one away from the hinge sliding a pane towards it', () => {
        expect(isLiftSlide(door)).toBe(true);
        const panes = openingLeaves(door, 300);
        const pane = (3640 - 2 * WindowFrameBar) / 3;
        expect(panes.map((p) => [p.along, p.width, p.travel])).toEqual([
            [1000 + WindowFrameBar, pane, 0],
            [1000 + WindowFrameBar + pane, pane, 0],
            [1000 + WindowFrameBar + 2 * pane, pane, -pane],
        ]);
        // The sliding pane on the track towards `side`, the fixed ones on the other.
        expect(panes.map((p) => p.across)).toEqual([-LiftSlideTrack, -LiftSlideTrack, LiftSlideTrack]);
    });

    it('takes no wall beside the opening, unlike a sliding leaf on the face', () => {
        expect(occupiedSpan(door)).toEqual([1000, 4640]);
        expect(occupiedSpan({...door, fixedPanes: 0})).toEqual([1000 - 3640 - 100, 4640]);
    });

    it('keeps the fixed panes in place as it opens', () => {
        const group = buildWall(wallWith([door]), materials, new THREE.MeshBasicMaterial());
        const panes = leafObjects(group);
        expect(panes).toHaveLength(3);
        panes.forEach((p) => setLeafPose(p, 1));
        expect(panes.map((p) => Math.round(p.position.x / MM))).toEqual(
            openingLeaves(door, 300).map((l) => Math.round(l.along + l.travel)),
        );
    });
});

describe('garage doors (FD-89)', () => {
    const sectional = opening({type: 'GarageDoor', width: 2500, height: 2125, garageDoorStyle: 'Sectional', side: 'Left'});

    it('hangs one lifting leaf behind the opening on the garage side, overlapping it', () => {
        const [leaf] = openingLeaves(sectional, 300);
        expect([leaf.motion, leaf.lift, leaf.along, leaf.width, leaf.height]).toEqual([
            'lift',
            'Sectional',
            1000 - GarageOverlap,
            2500 + 2 * GarageOverlap,
            2125 + GarageOverlap,
        ]);
        expect(leaf.across).toBeGreaterThan(150);
        const hinged = openingLeaves({...sectional, garageDoorStyle: 'SideHinged'}, 300);
        expect(hinged.map((l) => [l.motion, l.width])).toEqual([['swing', 1250], ['swing', 1250]]);
    });

    it('shows closed outside a walk and blocks its opening until it is mostly up', () => {
        const wall = wallWith([sectional]);
        const states = new LeafStates();
        states.reset([wall]);
        expect(states.openness(1)).toBe(0);
        expect(leafColliders([wall], () => 0)).toHaveLength(1);
        expect(leafColliders([wall], () => LiftClear - 0.01)).toHaveLength(1);
        expect(leafColliders([wall], () => LiftClear)).toHaveLength(0);
    });

    it('runs a sectional door up the jambs and under the ceiling into the garage', () => {
        const group = buildWall(wallWith([{...sectional, leafMaterialId: 1}]), materials, new THREE.MeshBasicMaterial());
        const [pivot] = leafObjects(group);
        const sections = pivot.children.filter((c) => (c.userData as {section?: number}).section !== undefined);
        expect(sections.length).toBeGreaterThanOrEqual(4);
        expect(meshesIn(pivot, materials.get(1)).length).toBeGreaterThan(0);
        expect(sections[0].position.y).toBeCloseTo(0);
        expect(sections[0].rotation.x).toBeCloseTo(0);

        setLeafPose(pivot, 1);
        const height = (2125 + GarageOverlap) * MM;
        for (const section of sections) {
            // Above the head, lying flat, inside (the left of the wall, −z).
            expect(section.position.y).toBeGreaterThan(height);
            expect(Math.abs(section.rotation.x)).toBeCloseTo(Math.PI / 2);
            expect(section.position.z).toBeLessThan(0);
        }
    });

    it('tilts an up-and-over door up into the garage and rolls a roller door up', () => {
        const tilt = leafObjects(buildWall(wallWith([{...sectional, garageDoorStyle: 'UpAndOver'}]), materials, new THREE.MeshBasicMaterial()))[0];
        setLeafPose(tilt, 1);
        const panel = tilt.children[0];
        expect(panel.position.y).toBeCloseTo((2125 + GarageOverlap) * MM);
        // Its top towards −z, inside.
        expect(panel.rotation.x).toBeCloseTo(-Math.PI / 2);

        const roll = leafObjects(buildWall(wallWith([{...sectional, garageDoorStyle: 'Roller'}]), materials, new THREE.MeshBasicMaterial()))[0];
        expect(roll.children[0].scale.y).toBeCloseTo(1);
        setLeafPose(roll, 1);
        expect(roll.children[0].scale.y).toBeLessThan(0.05);
    });
});

describe('opening materials and boarded leaves (FD-89)', () => {
    it('dresses the frame, the leaf and a window frame outside in their own materials', () => {
        const door = opening({frameMaterialId: 1, leafMaterialId: 2, leafStyle: 'Boarded', leafPanels: 6});
        const group = buildWall(wallWith([door]), materials, new THREE.MeshBasicMaterial());
        const [leaf] = leafObjects(group);
        expect(meshesIn(leaf, materials.get(2)).length).toBeGreaterThan(0);
        expect(meshesIn(leaf, materials.get(1))).toHaveLength(0);
        expect(meshesIn(group, materials.get(1)).length).toBeGreaterThan(0);

        const window = opening({type: 'Window', sillHeight: 900, height: 1200, frameMaterialId: 1, outsideFrameMaterialId: 2});
        const windowGroup = buildWall(wallWith([window]), materials, new THREE.MeshBasicMaterial());
        expect(meshesIn(windowGroup, materials.get(1)).length).toBeGreaterThan(0);
        expect(meshesIn(windowGroup, materials.get(2)).length).toBeGreaterThan(0);
    });

    it('sets a window frame in from the outer face', () => {
        const window = opening({type: 'Window', sillHeight: 900, height: 1200, inset: 0, frameMaterialId: 1});
        const group = buildWall(wallWith([window]), materials, new THREE.MeshBasicMaterial());
        group.updateMatrixWorld(true);
        const box = new THREE.Box3();
        meshesIn(group, materials.get(1)).forEach((m) => box.expandByObject(m));
        // Plan across is local −z: the outer (right) face is at +150 mm, the frame 80 mm deep inside it.
        expect(box.max.z / MM).toBeCloseTo(150, 0);
        expect(box.min.z / MM).toBeCloseTo(70, 0);
    });
});
