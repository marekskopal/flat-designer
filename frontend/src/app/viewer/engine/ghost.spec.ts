import {Room, Wall} from '@app/models/scene';
import * as THREE from 'three';
import {describe, expect, it} from 'vitest';

import {buildGhost, disposeGhost} from './ghost';

const wall = (id: number, startX: number, startY: number, endX: number, endY: number): Wall => ({
    id,
    name: `Wall ${id}`,
    type: 'Exterior',
    startX,
    startY,
    endX,
    endY,
    thickness: 200,
    height: 2600,
    materialId: null,
    leftFinishes: null,
    rightFinishes: null,
    openings: [],
});

const room = {id: 1, name: 'Hall', polygon: [[100, 100], [3900, 100], [3900, 2900], [100, 2900]]} as unknown as Room;

/** The plan points (mm) of a line object's vertices. */
const planPoints = (lines: THREE.LineSegments): {x: number; y: number}[] => {
    const position = lines.geometry.getAttribute('position');
    return Array.from({length: position.count}, (_, i) => ({x: Math.round(position.getX(i) * 1000), y: Math.round(-position.getZ(i) * 1000)}));
};

describe('buildGhost', () => {
    const walls = [wall(1, 0, 0, 4000, 0), wall(2, 4000, 0, 4000, 3000)];
    const ghost = buildGhost({walls, rooms: [room]});
    const [wallLines, roomLines] = ghost.children as THREE.LineSegments[];

    it("outlines each wall by its faces, mitred where walls meet, and the rooms' outlines dashed", () => {
        // A straight wall: four sides of two points each.
        expect(planPoints(wallLines)).toHaveLength(2 * 4 * 2);
        // The mitre: the outer faces of the L meet at (4100, −100).
        expect(planPoints(wallLines)).toContainEqual({x: 4100, y: -100});
        expect(planPoints(roomLines)).toHaveLength(4 * 2);
        expect(roomLines.material).toBeInstanceOf(THREE.LineDashedMaterial);
    });

    it('draws over the plan without a depth test and cannot be picked', () => {
        for (const lines of [wallLines, roomLines]) {
            expect((lines.material as THREE.Material).depthTest).toBe(false);
            expect(new THREE.Raycaster(new THREE.Vector3(2, 1, -0.05), new THREE.Vector3(0, -1, 0)).intersectObject(lines)).toEqual([]);
        }
    });

    it('disposes of its lines and leaves the scene', () => {
        const scene = new THREE.Scene();
        scene.add(ghost);
        disposeGhost(ghost);
        expect(ghost.parent).toBeNull();
    });
});
