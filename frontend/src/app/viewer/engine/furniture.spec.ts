import {Item, Material} from '@app/models/scene';
import * as THREE from 'three';
import {describe, expect, it} from 'vitest';

import {buildFurnitureItemBody, chairShape, slidingLeaves, wardrobeDoors} from './furniture';
import {MaterialLibrary} from './materials';

const material = (id: number, code: string, color: string): Material => ({
    id,
    code,
    name: code,
    pattern: 'Plain',
    color,
    roughness: 0.5,
    metalness: 0,
    texture: null,
    tileWidth: null,
    tileHeight: null,
});

const wardrobe = (params: Record<string, unknown>, width = 2468): Item =>
    ({id: 1, roomId: null, kind: 'Wardrobe', name: 'W', x: 0, y: 0, z: 0, width, depth: 600, height: 2400, rotation: 0, materialId: 24, params}) as
    Item;

describe('slidingLeaves', () => {
    it('splits the opening into overlapping leaves on alternating tracks', () => {
        const leaves = slidingLeaves(2430, 4);
        expect(leaves).toHaveLength(4);
        expect(leaves.map((l) => l.track)).toEqual(['front', 'back', 'front', 'back']);
        expect(leaves[0].x).toBeCloseTo(-1215);
        expect(leaves[3].x + leaves[3].width).toBeCloseTo(1215);
        // Neighbours overlap by 25 mm.
        expect(leaves[0].x + leaves[0].width - leaves[1].x).toBeCloseTo(25);
    });
});

describe('wardrobeDoors', () => {
    it('uses params.doors within 2–6, else one door per ~900 mm', () => {
        expect(wardrobeDoors(wardrobe({doors: 4}))).toBe(4);
        expect(wardrobeDoors(wardrobe({doors: 9}))).toBe(6);
        expect(wardrobeDoors(wardrobe({}))).toBe(3);
        expect(wardrobeDoors(wardrobe({}, 1000))).toBe(2);
    });
});

describe('chairShape', () => {
    const chair = (params: Record<string, unknown>): Item => ({...wardrobe(params, 400), kind: 'Chair', height: 885}) as Item;

    it('reads the seat height and back width, falling back to 460 and the front width', () => {
        expect(chairShape(chair({seatHeight: 450, backWidth: 375}))).toEqual({seatHeight: 450, backWidth: 375});
        expect(chairShape(chair({}))).toEqual({seatHeight: 460, backWidth: 400});
        expect(chairShape(chair({seatHeight: 900, backWidth: 500}))).toEqual({seatHeight: 460, backWidth: 400});
    });
});

describe('buildFurnitureItemBody', () => {
    const materials = new MaterialLibrary(
        [material(24, 'furniture-white-matt', '#f2f1ed'), material(25, 'aluminium-brushed', '#b9bcbf'), material(19, 'mirror', '#e9eced')],
        1,
    );

    it('builds the carcass and framed leaves with the mirror on the chosen doors', () => {
        const body = buildFurnitureItemBody(wardrobe({doors: 4, mirrorDoors: [3]}), materials);
        expect(body).not.toBeNull();
        const meshes = body?.children.filter((c): c is THREE.Mesh => c instanceof THREE.Mesh) ?? [];
        // Sides, top, interior, plinth, top track, bottom rail + 4 leaves × (4 profiles + infill).
        expect(meshes).toHaveLength(7 + 4 * 5);
        const mirrorMeshes = meshes.filter((m) => m.material === materials.byCode('mirror'));
        expect(mirrorMeshes).toHaveLength(1);
    });

    it('builds a chair inside its box: two seat boards, four legs, three aprons, posts and two rails', () => {
        const chair = {...wardrobe({seatHeight: 460, backWidth: 375}, 400), kind: 'Chair', depth: 395, height: 885} as Item;
        const body = buildFurnitureItemBody(chair, materials);
        expect(body).not.toBeNull();
        const meshes: THREE.Mesh[] = [];
        body?.traverse((o) => {
            if (o instanceof THREE.Mesh) {
                meshes.push(o);
            }
        });
        expect(meshes).toHaveLength(2 + 4 + 3 + 2 + 2);
        body?.updateMatrixWorld(true);
        const box = new THREE.Box3().setFromObject(body as THREE.Object3D);
        expect(box.min.y).toBeCloseTo(0);
        expect(box.max.y).toBeLessThanOrEqual(0.885 + 1e-6);
        expect(box.max.x).toBeCloseTo(0.2);
        expect(box.min.z).toBeGreaterThanOrEqual(-0.1975 - 0.004);
        expect(box.max.z).toBeCloseTo(0.1975);
    });

    it('builds a table top of boards on four legs with aprons', () => {
        const table = {...wardrobe({}, 1320), kind: 'Table', depth: 700, height: 780} as Item;
        const body = buildFurnitureItemBody(table, materials);
        // 700 / 180 → 4 boards, 4 legs, 4 aprons.
        expect(body?.children).toHaveLength(4 + 4 + 4);
        const box = new THREE.Box3().setFromObject(body as THREE.Object3D);
        expect(box.max.y).toBeCloseTo(0.78);
        expect(box.max.x).toBeCloseTo(0.66);
        expect(box.max.z).toBeCloseTo(0.35);
    });

    it('ignores other kinds', () => {
        expect(buildFurnitureItemBody({...wardrobe({}), kind: 'Box'}, materials)).toBeNull();
    });
});
