import {Item, Material} from '@app/models/scene';
import * as THREE from 'three';
import {describe, expect, it} from 'vitest';

import {buildFurnitureItemBody, slidingLeaves, wardrobeDoors} from './furniture';
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

    it('ignores other kinds', () => {
        expect(buildFurnitureItemBody({...wardrobe({}), kind: 'Box'}, materials)).toBeNull();
    });
});
