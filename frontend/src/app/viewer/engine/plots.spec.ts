import {Item, Plot, Room} from '@app/models/scene';
import * as THREE from 'three';
import {describe, expect, it} from 'vitest';

import {buildItem} from './items';
import {MaterialLibrary} from './materials';
import {buildPlot, drapedOn, groundBeside, groundHoles, groundUnder, highlightPlot, lowestGround} from './plots';
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

describe('a sloping plot', () => {
    const materials = new MaterialLibrary([], 1);
    // Falling 3 m from the west (street) to the east (garden) across a 20 m plot.
    const slope: Plot = {
        ...plot,
        polygon: [[0, 0], [20000, 0], [20000, 10000], [0, 10000]],
        terrain: [[0, 0, 3000], [0, 10000, 3000], [20000, 0, 0], [20000, 10000, 0]],
    };
    const groundAt = (group: THREE.Object3D, x: number, y: number): number | null => {
        group.updateMatrixWorld(true);
        const ground = group.getObjectByName('ground') as THREE.Mesh;
        const hit = new THREE.Raycaster(new THREE.Vector3(x * 0.001, 50, -y * 0.001), new THREE.Vector3(0, -1, 0)).intersectObject(ground)[0];
        return hit === undefined ? null : Math.round(hit.point.y * 1000);
    };
    const house = (floorElevation: number, type: Room['type'] = 'Living'): Room => ({
        ...room([[8000, 2000], [14000, 2000], [14000, 8000], [8000, 8000]], floorElevation),
        type,
    });

    it('lays the ground on the spot heights, a plane between them', () => {
        const group = buildPlot(slope, materials);

        expect(group.position.y).toBe(0);
        // 10 mm under the ground, as a flat plot's.
        expect(groundAt(group, 5000, 5000)).toBe(2240);
        expect(groundAt(group, 15000, 1000)).toBe(740);
        expect(lowestGround([slope])).toBe(0);
        expect(lowestGround([{...slope, terrain: [[0, 0, -800]]}])).toBe(-800);
    });

    it('cuts out the rooms set into the slope, not those above it nor the gardens on it', () => {
        // The ground reaches 1800 mm at the house's west wall: a floor at 0 lies below it there.
        expect(groundHoles(slope, [house(0)])).toEqual([house(0).polygon]);
        expect(groundHoles(slope, [house(1900)])).toEqual([]);
        expect(groundHoles(slope, [house(0, 'Garden')])).toEqual([]);
        // A garden a step down into the slope is cut out, so the ground does not cover it.
        expect(groundHoles(slope, [house(-150, 'Garden')])).toEqual([house(-150, 'Garden').polygon]);
        expect(groundAt(buildPlot(slope, materials, [house(0)]), 11000, 5000)).toBeNull();
    });

    it('drapes gardens, paths and driveways on it, and knows the ground beside a room', () => {
        expect(drapedOn(house(0, 'Garden'), [slope])).not.toBeNull();
        expect(drapedOn(house(0, 'Terrace'), [slope])).toBeNull();
        expect(drapedOn(house(0, 'Garden'), [plot])).toBeNull();
        expect(groundBeside(house(0), [slope])(8000, 5000)).toBeCloseTo(1800);
        expect(groundUnder(house(0), [slope])).toBeCloseTo(1350);
    });

    it('stands an item on the ground under its centre when it is on the ground', () => {
        const tree: Item = {
            id: 3,
            roomId: null,
            groupId: null,
            kind: 'Box',
            definitionId: null,
            name: 'Tree',
            x: 10000,
            y: 5000,
            z: 100,
            width: 600,
            depth: 600,
            height: 4000,
            rotation: 0,
            materialId: null,
            params: {},
        };
        const assets = {models: new Map(), modelLibrary: null, pictures: null, plots: [slope]};

        expect(Math.round(buildItem({...tree, onGround: true}, materials, undefined, new Map(), assets).position.y * 1000)).toBe(1600);
        expect(Math.round(buildItem(tree, materials, undefined, new Map(), assets).position.y * 1000)).toBe(100);
    });

    it('draws the boundary along the ground', () => {
        const boundary = buildPlot(slope, materials).getObjectByName('boundary') as THREE.LineLoop;
        const position = boundary.geometry.getAttribute('position');

        // 20 m edges in 500 mm pieces, 10 m edges in 500 mm pieces.
        expect(position.count).toBe(2 * 40 + 2 * 20);
        expect(Math.round(position.getY(0) * 1000)).toBe(3004);
        expect(Math.round(position.getY(40) * 1000)).toBe(4);
    });
});
