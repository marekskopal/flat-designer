import {describe, expect, it} from 'vitest';

import {herringboneLength, herringboneTiles} from './materials';

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
