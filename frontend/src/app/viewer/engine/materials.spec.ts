import {Material} from '@app/models/scene';
import * as THREE from 'three';
import {describe, expect, it} from 'vitest';

import {herringboneLength, herringboneTiles, MaterialLibrary} from './materials';

describe('herringbone', () => {
    it('rounds the tile length to a whole multiple of the width', () => {
        expect(herringboneLength(240, 60)).toBe(240);
        expect(herringboneLength(250, 60)).toBe(240);
        expect(herringboneLength(100, 60)).toBe(120);
    });

    it('covers its patch exactly once and repeats seamlessly across the edges', () => {
        const length = 240;
        const width = 60;
        const size = 2 * length;
        const tiles = herringboneTiles(length, width);
        // Count the tiles over sample points, the patch's own and those one patch further (which must match).
        for (let x = 2.5; x < size; x += 5) {
            for (let y = 2.5; y < size; y += 5) {
                const at = (px: number, py: number): number =>
                    tiles.filter(([tx, ty, tw, th]) => px > tx && px < tx + tw && py > ty && py < ty + th).length;
                expect(at(x, y)).toBe(1);
            }
        }
        // A tile cut by the left or bottom edge reappears, shifted by the patch, cut by the right or top edge.
        const keys = new Set(tiles.map(([tx, ty, tw, th]) => `${tx},${ty},${tw},${th}`));
        const leftCut = tiles.filter(([tx]) => tx < 0);
        const bottomCut = tiles.filter(([, ty]) => ty < 0);
        expect(leftCut.length).toBeGreaterThan(0);
        expect(bottomCut.length).toBeGreaterThan(0);
        for (const [tx, ty, tw, th] of leftCut) {
            expect(keys.has(`${tx + size},${ty},${tw},${th}`)).toBe(true);
        }
        for (const [tx, ty, tw, th] of bottomCut) {
            expect(keys.has(`${tx},${ty + size},${tw},${th}`)).toBe(true);
        }
    });
});

describe('MaterialLibrary (FD-92)', () => {
    const dto = (id: number, pattern: Material['pattern'], tileWidth: number | null, tileHeight: number | null): Material => ({
        id,
        code: `m${id}`,
        name: `Material ${id}`,
        pattern,
        color: '#a0a0a0',
        roughness: 0.8,
        metalness: 0,
        tileWidth,
        tileHeight,
        texture: null,
    });
    const library = new MaterialLibrary([dto(1, 'Planks', 120, 4000), dto(2, 'Stone', 400, 130), dto(3, 'Tiles', 600, 300)], 1);
    const map = (material: THREE.Material): THREE.Texture => (material as THREE.MeshStandardMaterial).map!;

    it('draws planks of a size at that size, along v on floors and along a wall on upright faces', () => {
        const floor = map(library.get(1));
        // A patch of 20 boards 120 wide (2.4 m) by one 4 m length.
        expect(floor.repeat.x).toBeCloseTo(1000 / 2400, 9);
        expect(floor.repeat.y).toBeCloseTo(1000 / 4000, 9);
        expect(floor.rotation).toBe(0);
        expect(map(library.upright(1)).rotation).toBeCloseTo(Math.PI / 2, 9);
        // A grain asked for elsewhere (a stair tread, a cabinet) leaves planks along v, as before.
        expect(map(library.get(1, 'horizontal')).rotation).toBe(0);
        // Planks without a size are drawn along the canvas width: turned onto v, and left along a wall.
        const unsized = new MaterialLibrary([dto(4, 'Planks', null, null)], 1);
        expect(map(unsized.get(4)).rotation).toBeCloseTo(Math.PI / 2, 9);
        expect(map(unsized.get(4, 'horizontal')).rotation).toBeCloseTo(Math.PI / 2, 9);
        expect(map(unsized.upright(4)).rotation).toBe(0);
    });

    it('lays stone walling in a patch of 6 stones by 8 courses, and other patterns alike on upright faces', () => {
        const stone = map(library.get(2));
        expect(stone.repeat.x).toBeCloseTo(1000 / 2400, 9);
        expect(stone.repeat.y).toBeCloseTo(1000 / 1040, 9);
        expect(library.upright(2)).toBe(library.get(2));
        expect(library.upright(3)).toBe(library.get(3));
    });
});
