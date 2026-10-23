import {Plot, Room} from '@app/models/scene';
import * as THREE from 'three';
import {describe, expect, it} from 'vitest';

import {MaterialLibrary} from './materials';
import {buildPlot, groundHoles, groundUnder, highlightPlot, lowestGround} from './plots';
import {selectionOf} from './selection';

const plot: Plot = {id: 7, name: 'Parcel', polygon: [[-3000, -2000], [9000, -2000], [9000, 8000], [-3000, 8000]], area: 120, groundMaterialId: null};

const room = (polygon: Room['polygon'], floorElevation: number): Room => ({
    id: polygon[0][0],
    code: 'R',
    name: 'Room',
    type: 'Terrace',
    polygon,
    area: 0,
    ceilingHeight: null,
    floorMaterialId: null,
    wallFinishes: [],
    tileOriginX: null,
    tileOriginY: null,
    floorElevation,
});

describe('groundHoles', () => {
    const terrace = room([[0, 0], [3000, 0], [3000, 2000], [0, 2000]], -150);
    const garden = room([[-1000, -1000], [5000, -1000], [5000, 4000], [-1000, 4000]], -100);

    it('cuts the ground out under the rooms below it that lie within the boundary', () => {
        expect(groundHoles(plot, [terrace, room([[0, 3000], [3000, 3000], [3000, 5000], [0, 5000]], 450)])).toEqual([terrace.polygon]);
        // A raised plot: the house's rooms at the floor's level are below it too.
        expect(groundHoles({...plot, groundLevel: 200}, [room(terrace.polygon, 0)])).toEqual([terrace.polygon]);
        // A room reaching past the boundary is not cut out.
        expect(groundHoles(plot, [room([[-4000, 0], [3000, 0], [3000, 2000], [-4000, 2000]], -150)])).toEqual([]);
    });

    it('leaves a room inside another cut-out room to that one', () => {
        expect(groundHoles(plot, [terrace, garden])).toEqual([garden.polygon]);
    });

    it('knows the ground under a room', () => {
        expect(groundUnder(terrace, [{...plot, groundLevel: -300}])).toBe(-300);
        expect(groundUnder(room([[20000, 0], [23000, 0], [23000, 2000], [20000, 2000]], 0), [{...plot, groundLevel: -300}])).toBe(0);
        expect(lowestGround([plot], [terrace])).toBe(-150);
    });
});

describe('buildPlot', () => {
    const materials = new MaterialLibrary([], 1);

    it('has no ground over a sunken room', () => {
        const terrace = room([[0, 0], [3000, 0], [3000, 2000], [0, 2000]], -150);
        const group = buildPlot(plot, materials, [terrace]);
        group.updateMatrixWorld(true);
        const ground = group.getObjectByName('ground') as THREE.Mesh;
        const hits = (x: number, y: number): number => {
            const ray = new THREE.Raycaster(new THREE.Vector3(x * 0.001, 5, -y * 0.001), new THREE.Vector3(0, -1, 0));
            return ray.intersectObject(ground).length;
        };

        expect(hits(1500, 1000)).toBe(0);
        expect(hits(-2000, 5000)).toBe(1);
    });

    it('lays the ground under the floors inside the boundary, picked as the plot', () => {
        const group = buildPlot(plot, materials);
        const ground = group.getObjectByName('ground') as THREE.Mesh;
        const box = new THREE.Box3().setFromObject(ground);

        // Plan y 8000 is scene z −8; the ground lies below the rooms' floors (0.002).
        expect([box.min.x, box.max.x]).toEqual([-3, 9]);
        expect([box.min.z, box.max.z]).toEqual([-8, 2]);
        expect(box.max.y).toBeLessThan(0);
        expect(ground.receiveShadow).toBe(true);
        expect(selectionOf(ground)).toEqual({kind: 'plot', id: 7});
    });

    it('lies at its ground level; the surroundings go under the lowest plot', () => {
        expect(buildPlot({...plot, groundLevel: -600}, materials).position.y).toBeCloseTo(-0.6, 6);
        expect(lowestGround([{...plot, groundLevel: -600}, {...plot, groundLevel: 200}])).toBe(-600);
        expect(lowestGround([{...plot, groundLevel: 200}])).toBe(0);
        expect(lowestGround([])).toBe(0);
    });

    it('draws the boundary as a dashed loop that takes no clicks and lights up when selected', () => {
        const group = buildPlot(plot, materials);
        const boundary = group.getObjectByName('boundary') as THREE.LineLoop;
        const material = boundary.material as THREE.LineDashedMaterial;
        const hits: THREE.Intersection[] = [];

        expect(boundary.geometry.getAttribute('position').count).toBe(4);
        boundary.raycast(new THREE.Raycaster(), hits);
        expect(hits).toEqual([]);
        const plain = material.color.getHexString();
        highlightPlot(group, true);
        expect(material.color.getHexString()).not.toBe(plain);
        highlightPlot(group, false);
        expect(material.color.getHexString()).toBe(plain);
    });
});
