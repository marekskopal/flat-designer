import {Plot} from '@app/models/scene';
import * as THREE from 'three';
import {describe, expect, it} from 'vitest';

import {MaterialLibrary} from './materials';
import {buildPlot, highlightPlot} from './plots';
import {selectionOf} from './selection';

const plot: Plot = {id: 7, name: 'Parcel', polygon: [[-3000, -2000], [9000, -2000], [9000, 8000], [-3000, 8000]], area: 120, groundMaterialId: null};

describe('buildPlot', () => {
    const materials = new MaterialLibrary([], 1);

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
