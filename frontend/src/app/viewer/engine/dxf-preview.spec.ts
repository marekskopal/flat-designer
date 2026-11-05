import {DxfPlan} from '@app/models/dxf';
import * as THREE from 'three';
import {describe, expect, it} from 'vitest';

import {buildDxfPreview, disposeDxfPreview} from './dxf-preview';

/** The plan points (mm) of a line object's vertices. */
const planPoints = (lines: THREE.LineSegments): {x: number; y: number}[] => {
    const position = lines.geometry.getAttribute('position');
    return Array.from({length: position.count}, (_, i) => ({x: Math.round(position.getX(i) * 1000), y: Math.round(-position.getZ(i) * 1000)}));
};

const plan: DxfPlan = {
    units: 4,
    walls: [
        {startX: 0, startY: 0, endX: 4000, endY: 0, sagitta: 0, thickness: 200},
        {startX: 4000, startY: 0, endX: 4000, endY: 3000, sagitta: 0, thickness: 200},
    ],
    openings: [
        {wall: 0, type: 'Door', offset: 1000, width: 800, leafWidth: null, hinge: 'Start', side: 'Left'},
        {wall: 1, type: 'Window', offset: 1000, width: 1200, leafWidth: null, hinge: null, side: null},
    ],
    unpaired: [[0, 2000, 3000, 2000]],
    warnings: [],
};

describe('buildDxfPreview', () => {
    it('outlines the walls mitred at their corner and marks the openings', () => {
        const group = buildDxfPreview(plan);
        const walls = group.getObjectByName('dxf-walls') as THREE.LineSegments;
        const points = planPoints(walls);

        // The corner's outer face meets at (4100, -100), the mitre.
        expect(points).toContainEqual({x: 4100, y: -100});
        // The door's jambs, its leaf standing open from the hinge on the left face, and the end of its swing.
        expect(points).toContainEqual({x: 1000, y: -100});
        expect(points).toContainEqual({x: 1800, y: 100});
        expect(points).toContainEqual({x: 1000, y: 900});
        // The window's glazing along the centre line.
        expect(points).toContainEqual({x: 4000, y: 1000});
        expect(points).toContainEqual({x: 4000, y: 2200});
        expect(planPoints(group.getObjectByName('dxf-unpaired') as THREE.LineSegments)).toEqual([
            {x: 0, y: 2000},
            {x: 3000, y: 2000},
        ]);
    });

    it('draws over the plan without a depth test and is not pickable', () => {
        const group = buildDxfPreview({...plan, unpaired: []});
        const walls = group.getObjectByName('dxf-walls') as THREE.LineSegments;

        expect((walls.material as THREE.Material).depthTest).toBe(false);
        expect(group.getObjectByName('dxf-unpaired')).toBeUndefined();
        const hits: THREE.Intersection[] = [];
        walls.raycast(new THREE.Raycaster(), hits);
        expect(hits).toEqual([]);

        const parent = new THREE.Group();
        parent.add(group);
        disposeDxfPreview(group);
        expect(parent.children).toEqual([]);
    });
});
