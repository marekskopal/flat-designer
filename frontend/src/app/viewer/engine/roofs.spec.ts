import {Roof, RoofWindow} from '@app/models/scene';
import * as THREE from 'three';
import {describe, expect, it} from 'vitest';

import {MaterialLibrary} from './materials';
import {MM} from './plan-geometry';
import {roofWindowLayout} from './roof-geometry';
import {buildRoof, buildRoofOutline, resizeRoofByCorner, roofCorners} from './roofs';
import {selectionOf} from './selection';

const gable: Roof = {
    id: 4,
    name: 'Main roof',
    shape: 'Gable',
    x: 5000,
    y: 4000,
    rotation: 0,
    width: 10000,
    depth: 8000,
    eaveHeight: 2600,
    pitch: 45,
    overhang: 400,
    vergeOverhang: 200,
    thickness: 250,
    parapet: null,
    coveringMaterialId: null,
    soffitMaterialId: null,
    windows: [],
};

const materials = new MaterialLibrary([], 1);
const section = materials.sectionFill();

/** The world box of what a roof draws, mm, without its section-fill twins. */
function bounds(object: THREE.Object3D): THREE.Box3 {
    object.updateMatrixWorld(true);
    const box = new THREE.Box3();
    object.traverse((o) => {
        if (o instanceof THREE.Mesh && o.material !== section) {
            box.expandByObject(o);
        }
    });
    return new THREE.Box3(box.min.divideScalar(MM), box.max.divideScalar(MM));
}

describe('buildRoof', () => {
    it('draws a gable from the overhang up to the ridge, tagged for picking', () => {
        const roof = buildRoof(gable, materials, section);
        const box = bounds(roof);
        // The underside comes down 400 mm over the eaves overhang at 45°; the top is the ridge, the 250 mm slab
        // (353.6 upright) and half the 70 mm cap.
        expect(box.min.y).toBeCloseTo(2200, 0);
        expect(box.max.y).toBeCloseTo(6600 + 353.6 + 35, 0);
        // Plan x runs 200 mm past the gables; plan y (scene −z) 400 mm past the eaves.
        expect(box.min.x).toBeCloseTo(-200, 0);
        expect(box.max.x).toBeCloseTo(10200, 0);
        expect(-box.max.z).toBeCloseTo(-400, 0);
        expect(-box.min.z).toBeCloseTo(8400, 0);

        const meshes: THREE.Mesh[] = [];
        roof.traverse((o) => o instanceof THREE.Mesh && meshes.push(o));
        expect(selectionOf(meshes[0])).toEqual({kind: 'roof', id: 4});
        expect(meshes.some((m) => m.material === section)).toBe(true);
    });

    it('turns with its rotation about its centre', () => {
        const turned = bounds(buildRoof({...gable, x: 0, y: 0, rotation: 90}, materials, section));
        // The ridge now runs north: plan y spans the width with the verges, plan x the depth with the eaves.
        expect(-turned.min.z).toBeCloseTo(5200, 0);
        expect(turned.max.x).toBeCloseTo(4400, 0);
    });

    it('stands a flat roof level with its parapet on top', () => {
        const flat = bounds(buildRoof({...gable, shape: 'Flat', parapet: 900, thickness: 300, overhang: 0}, materials, section));
        expect(flat.min.y).toBeCloseTo(2600, 0);
        expect(flat.max.y).toBeCloseTo(2600 + 300 + 900, 0);
    });
});

describe('buildRoofOutline', () => {
    it('is picked along its edge in the top view, not over the floor inside it', () => {
        const outline = buildRoofOutline(gable);
        const scene = new THREE.Scene();
        scene.add(outline);
        scene.updateMatrixWorld(true);
        const ray = (x: number, y: number): THREE.Intersection[] =>
            new THREE.Raycaster(new THREE.Vector3(x * MM, 10, -y * MM), new THREE.Vector3(0, -1, 0)).intersectObject(scene, true);

        // On the dashed edge 400 mm past the eaves, and in the middle of the roof.
        const edge = ray(5000, -400);
        expect(edge.length).toBeGreaterThan(0);
        expect(selectionOf(edge[0].object)).toEqual({kind: 'roof', id: 4});
        expect(ray(5000, 4000)).toEqual([]);
        expect(outline.userData['planOnly']).toBe(true);
    });
});

describe('resizeRoofByCorner', () => {
    it('keeps the opposite corner where it was', () => {
        // The back right corner (5000, 4000) of a 10 × 8 m roof round the origin, pulled out by 1 m each way.
        expect(resizeRoofByCorner({...gable, x: 0, y: 0}, 2, {x: 6000, y: 5000}, 1000)).toEqual({x: 500, y: 500, width: 11000, depth: 9000});
    });

    it('works in the roof frame and keeps each side at least the minimum', () => {
        const turned = {...gable, x: 0, y: 0, rotation: 90};
        const [frontLeft] = roofCorners(turned);
        // At 90° the front left corner lies at plan (4000, −5000).
        expect(frontLeft.x).toBeCloseTo(4000, 6);
        expect(frontLeft.y).toBeCloseTo(-5000, 6);
        const same = resizeRoofByCorner(turned, 0, frontLeft, 1000);
        expect(same).toEqual({x: 0, y: 0, width: 10000, depth: 8000});
        // Pulled past the opposite corner: both sides shrink to the minimum, the fixed corner stays.
        const squashed = resizeRoofByCorner(turned, 0, {x: -9000, y: 9000}, 1000);
        expect([squashed.width, squashed.depth]).toEqual([1000, 1000]);
        expect(roofCorners({...turned, ...squashed})[2].x).toBeCloseTo(roofCorners(turned)[2].x, 0);
        expect(roofCorners({...turned, ...squashed})[2].y).toBeCloseTo(roofCorners(turned)[2].y, 0);
    });
});

const skylight: RoofWindow = {
    id: 7,
    name: 'Skylight',
    type: 'CentrePivot',
    along: 1000,
    across: -2000,
    width: 780,
    length: 1180,
    frameMaterialId: null,
};

describe('roofWindowLayout', () => {
    it('lies on the plane under its centre, counter-clockwise, its length foreshortened in plan', () => {
        const layout = roofWindowLayout(gable, skylight)!;
        expect(layout.plane).toBe(0);
        expect(layout.up).toEqual({x: 0, y: 1});
        // 1180 mm up a 45° slope is 834 in plan.
        const ys = layout.corners.map((c) => c.y);
        expect(Math.max(...ys) - Math.min(...ys)).toBeCloseTo(1180 * Math.SQRT1_2, 6);
        const area = layout.corners.reduce((sum, c, i) => {
            const d = layout.corners[(i + 1) % 4];
            return sum + c.x * d.y - d.x * c.y;
        }, 0);
        expect(area).toBeGreaterThan(0);
        // The hole is square to the slope: at the soffit it lies thickness · sin(pitch) further up it.
        expect(layout.soffit[0].x).toBe(layout.corners[0].x);
        expect(layout.soffit[0].y - layout.corners[0].y).toBeCloseTo(250 * Math.SQRT1_2, 6);
    });

    it('is none on a flat roof, or for a window the roof has left behind', () => {
        expect(roofWindowLayout({...gable, shape: 'Flat'}, skylight)).toBeNull();
        expect(roofWindowLayout(gable, {...skylight, along: 4900})).toBeNull();
        expect(roofWindowLayout(gable, {...skylight, across: -200})).toBeNull();
    });
});

describe('roof windows', () => {
    const roof = {...gable, windows: [skylight]};
    const down = (object: THREE.Object3D, x: number, y: number): THREE.Intersection[] => {
        const scene = new THREE.Scene();
        scene.add(object);
        scene.updateMatrixWorld(true);
        return new THREE.Raycaster(new THREE.Vector3(x * MM, 20, -y * MM), new THREE.Vector3(0, -1, 0))
            .intersectObject(scene, true)
            .filter((hit) => hit.object instanceof THREE.Mesh && hit.object.material !== section);
    };

    it('stands in a hole of the slab, tagged to be picked', () => {
        // At the window's centre (plan 6000, 2000) the first thing hit is the window, and no covering is under it.
        const built = buildRoof(roof, materials, section);
        const hits = down(built, 6000, 2000);
        expect(selectionOf(hits[0].object)).toEqual({kind: 'roofWindow', id: 7});
        expect(hits.every((hit) => selectionOf(hit.object)?.kind === 'roofWindow')).toBe(true);
        // Beside it the covering is whole.
        expect(selectionOf(down(buildRoof(roof, materials, section), 7000, 2000)[0].object)).toEqual({kind: 'roof', id: 4});
    });

    it('leaves out a window the roof left behind', () => {
        const built = buildRoof({...roof, windows: [{...skylight, along: 4900}]}, materials, section);
        let tagged = false;
        built.traverse((o) => (tagged ||= o.userData['roofWindowId'] !== undefined));
        expect(tagged).toBe(false);
    });

    it('is picked in the top view inside its outline', () => {
        const hits = down(buildRoofOutline(roof), 6000, 2000);
        expect(hits.length).toBeGreaterThan(0);
        expect(selectionOf(hits[0].object)).toEqual({kind: 'roofWindow', id: 7});
    });
});
