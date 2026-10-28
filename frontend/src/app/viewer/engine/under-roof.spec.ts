import {Room, Wall} from '@app/models/scene';
import * as THREE from 'three';
import {describe, expect, it} from 'vitest';

import {buildRoom, buildWall} from './building';
import {ceilingHeightAt, ceilingSteps} from './ceilings';
import {MaterialLibrary} from './materials';
import {MM} from './plan-geometry';
import {RoofShapeFields} from './roof-geometry';
import {ceilingParts, CeilingUnderRoof, lowestTopAlong, outlineWithout, profileRuns, roofBreaks, roofsReach, wallTopAt} from './under-roof';

/** A gable over 10 × 8 m from the origin, its ridge along x at y = 4000: the underside is 2600 + min(y, 8000 − y). */
const gable: RoofShapeFields = {
    shape: 'Gable',
    x: 5000,
    y: 4000,
    rotation: 0,
    width: 10000,
    depth: 8000,
    eaveHeight: 2600,
    pitch: 45,
    overhang: 0,
    vergeOverhang: 200,
    thickness: 250,
};

const wall = (start: [number, number], end: [number, number], fields: Partial<Wall> = {}): Wall => ({
    id: 1,
    name: 'Wall',
    type: 'Exterior',
    startX: start[0],
    startY: start[1],
    endX: end[0],
    endY: end[1],
    thickness: 200,
    height: 2600,
    materialId: null,
    leftFinishes: null,
    rightFinishes: null,
    openings: [],
    ...fields,
});

const room = (id: number, polygon: Room['polygon'], ceilingHeight: number, extra: Partial<Room> = {}): Room => ({
    id,
    code: `R${id}`,
    name: `Room ${id}`,
    type: 'Living',
    polygon,
    area: 0,
    ceilingHeight,
    floorMaterialId: null,
    wallFinishes: [],
    tileOriginX: null,
    tileOriginY: null,
    ...extra,
});

const materials = new MaterialLibrary([], 1);
const section = materials.sectionFill();

/** The world box (mm) of the meshes of an object, without the section-fill twins. */
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

describe('wallTopAt', () => {
    it('cuts a wall at the underside, or raises a gable to it, and leaves it alone outside the roof', () => {
        const partition = wallTopAt(wall([0, 0], [0, 1], {height: 3000}), [gable])!;
        expect(partition({x: 5000, y: 200})).toBeCloseTo(2800, 6);
        expect(partition({x: 5000, y: 4000})).toBe(3000);
        const raised = wallTopAt(wall([0, 0], [0, 1], {upToRoof: true}), [gable])!;
        expect(raised({x: 5000, y: 4000})).toBeCloseTo(6600, 6);
        expect(raised({x: 20000, y: 4000})).toBe(2600);
        expect(wallTopAt(wall([0, 0], [0, 1]), [])).toBeNull();
    });
});

describe('roofBreaks', () => {
    it('finds the ridge and where the underside passes a level', () => {
        expect(roofBreaks({x: 0, y: 0}, {x: 0, y: 8000}, [gable], [])).toEqual([0, 0.5, 1]);
        // A 3 m partition under it bends where the underside reaches 3000, 400 mm in from either eaves.
        const breaks = roofBreaks({x: 5000, y: 0}, {x: 5000, y: 8000}, [gable], [3000]);
        expect(breaks.map((t) => Math.round(t * 8000))).toEqual([0, 400, 4000, 7600, 8000]);
    });
});

describe('profileRuns', () => {
    it('steps where a wall runs out from under the roof', () => {
        // Along y = 2000, from 2 m west of the verge into the roof: 2600 outside, the underside (4600) under the roof.
        const raised = wall([-2000, 2000], [2000, 2000], {upToRoof: true});
        const right = [{x: -2000, y: 1900}, {x: 2000, y: 1900}];
        const left = [{x: -2000, y: 2100}, {x: 2000, y: 2100}];
        const runs = profileRuns(right, left, wallTopAt(raised, [gable])!, [gable], [2600]);
        expect(runs).toHaveLength(2);
        expect(runs[0].right.at(-1)!.x).toBeCloseTo(-200, 6);
        expect(runs[0].rightTop).toEqual([2600, 2600]);
        expect(runs[1].rightTop[0]).toBeCloseTo(4500, 6);
        expect(runs[1].leftTop.at(-1)).toBeCloseTo(4700, 6);
    });
});

describe('buildWall under a roof', () => {
    it('raises a gable wall to the ridge and cuts a tall partition at the slope', () => {
        const gableWall = bounds(buildWall(wall([0, 0], [0, 8000], {upToRoof: true}), materials, section, undefined, undefined, [gable]));
        expect(gableWall.max.y).toBeCloseTo(6600, 0);
        const plain = bounds(buildWall(wall([0, 0], [0, 8000]), materials, section, undefined, undefined, [gable]));
        expect(plain.max.y).toBeCloseTo(2600, 0);

        // A 3 m partition along the slope: 2600 + 100 at its south face's end, 3000 where the roof is higher.
        const partition = buildWall(wall([5000, 100], [5000, 4000], {height: 3000}), materials, section, undefined, undefined, [gable]);
        const tops: number[] = [];
        partition.traverse((o) => {
            if (o instanceof THREE.Mesh && o.material !== section) {
                const position = o.geometry.getAttribute('position');
                for (let i = 0; i < position.count; i++) {
                    tops.push(position.getY(i) / MM);
                }
            }
        });
        expect(Math.max(...tops)).toBeCloseTo(3000, 0);
        // The partition's square start (y = 100) is under the underside's 2700.
        expect(tops.some((h) => Math.abs(h - 2700) < 0.5)).toBe(true);
    });

    it('leaves out a window lying wholly above the top', () => {
        const window = {
            id: 7,
            name: 'High',
            type: 'Window' as const,
            offset: 300,
            width: 600,
            height: 400,
            sillHeight: 2700,
            leafWidth: null,
            hinge: null,
            side: null,
            entrance: false,
        };
        const tall = wall([5000, 0], [5000, 2000], {height: 3200, openings: [window]});
        const tagged = (group: THREE.Object3D): boolean => {
            let found = false;
            group.traverse((o) => (found ||= o.userData['openingId'] === 7));
            return found;
        };
        expect(tagged(buildWall(tall, materials, section))).toBe(true);
        // Over the window (y 300–900) the roof's underside is 2900–3500, the wall's top 3200: the window still fits;
        // with the roof 600 mm lower (2300 at its low side) it does not, and the wall is solid there.
        const lowered = buildWall(tall, materials, section, undefined, undefined, [{...gable, eaveHeight: 2000}]);
        expect(tagged(lowered)).toBe(false);
        const meshes = (group: THREE.Object3D): number => {
            let count = 0;
            group.traverse((o) => (count += o instanceof THREE.Mesh && o.material !== section ? 1 : 0));
            return count;
        };
        const solid = buildWall({...tall, openings: []}, materials, section, undefined, undefined, [{...gable, eaveHeight: 2000}]);
        expect(meshes(lowered)).toBe(meshes(solid));
    });

    it('rebuilds only what a changed roof reaches', () => {
        expect(roofsReach({minX: 0, minY: 0, maxX: 1000, maxY: 1000}, [gable])).toBe(true);
        // 200 mm past the verge, and 1 mm inside it.
        expect(roofsReach({minX: 10400, minY: 0, maxX: 11000, maxY: 1000}, [gable])).toBe(false);
        expect(roofsReach({minX: 10199, minY: 0, maxX: 11000, maxY: 1000}, [gable])).toBe(true);
        const partition = wallTopAt(wall([0, 0], [0, 1], {height: 3000}), [gable])!;
        expect(lowestTopAlong({x: 5000, y: 100}, {x: 5000, y: 4000}, partition, [gable])).toBeCloseTo(2700, 6);
    });
});

describe('ceilings under a roof', () => {
    const attic = room(1, [[0, 0], [10000, 0], [10000, 8000], [0, 8000]], 2500, {floorElevation: 0});
    const low = {...gable, eaveHeight: 2000};

    it('slopes where the roof comes lower than the ceiling height', () => {
        // The underside less the gap is 2000 − 10 + min(y, 8000 − y): under 2500 within 510 mm of either eaves.
        expect(ceilingHeightAt(attic, 5000, 100, [low])).toBeCloseTo(2100 - CeilingUnderRoof, 6);
        expect(ceilingHeightAt(attic, 5000, 4000, [low])).toBe(2500);
        expect(ceilingHeightAt({...attic, floorElevation: 300}, 5000, 100, [low])).toBeCloseTo(2100 - CeilingUnderRoof - 300, 6);

        const parts = ceilingParts(attic, outlineWithout(attic.polygon, []), [low])!;
        expect(parts).toHaveLength(3);
        const flat = parts.filter((part) => part.heightAt({x: 0, y: 0}) === 2500);
        expect(flat).toHaveLength(1);
        const ys = flat[0].outer.map((p) => Math.round(p.y));
        expect([Math.min(...ys), Math.max(...ys)]).toEqual([510, 7490]);
    });

    it('keeps the plain ceiling where no roof comes lower', () => {
        expect(ceilingParts(attic, outlineWithout(attic.polygon, []), [gable])).toBeNull();
        expect(ceilingParts(attic, outlineWithout(attic.polygon, []), [{...low, x: 50000}])).toBeNull();
        const built = buildRoom(attic, [], materials, [], undefined, [gable]);
        expect(bounds(built.ceiling!).min.y).toBeCloseTo(2500, 0);
    });

    it('draws the sloped ceiling down to the eaves', () => {
        const built = buildRoom(attic, [], materials, [], undefined, [low]);
        const box = bounds(built.ceiling!);
        expect(box.min.y).toBeCloseTo(2000 - CeilingUnderRoof, 0);
        expect(box.max.y).toBeCloseTo(2500, 0);
    });

    it('closes the step between two ceilings only where the roof leaves room for it', () => {
        const west = room(1, [[0, 0], [5000, 0], [5000, 8000], [0, 8000]], 2500);
        const east = room(2, [[5000, 0], [10000, 0], [10000, 8000], [5000, 8000]], 2800);
        const steps = ceilingSteps([west, east], [], [low]);
        expect(steps.length).toBeGreaterThan(0);
        const ys = steps.flatMap((step) => [step.a.y, step.b.y]);
        expect(Math.min(...ys)).toBeCloseTo(510, 0);
        expect(Math.max(...ys)).toBeCloseTo(7490, 0);
        expect(steps.every((step) => Math.abs(step.bottom - 2500) < 1e-6 && Math.abs((step.atB?.bottom ?? 0) - 2500) < 1e-6)).toBe(true);
        expect(Math.max(...steps.map((step) => step.top))).toBeCloseTo(2800, 6);
    });
});
