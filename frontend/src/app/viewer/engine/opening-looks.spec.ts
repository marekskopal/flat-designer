import {describe, expect, it} from 'vitest';

import {exteriorSign, LeafBottomRail, leafPanels, LeafRail, LeafStile, louvreSlats, shutterLeaves} from './opening-looks';

describe('leafPanels', () => {
    it('stacks the panels between the stiles above the bottom rail, separated by rails', () => {
        const panels = leafPanels(800, 2100, 2);
        expect(panels).toHaveLength(2);
        const free = 2100 - LeafBottomRail - 2 * LeafRail;
        expect(panels[0]).toEqual({x: LeafStile, y: LeafBottomRail, width: 800 - 2 * LeafStile, height: free / 2});
        expect(panels[1].y).toBeCloseTo(LeafBottomRail + free / 2 + LeafRail, 6);
        // The top rail is left above the last panel.
        expect(panels[1].y + panels[1].height).toBeCloseTo(2100 - LeafRail, 6);
    });

    it('frames nothing on a leaf too narrow or low for its stiles and rails', () => {
        expect(leafPanels(200, 2000, 2)).toEqual([]);
        expect(leafPanels(800, 400, 3)).toEqual([]);
    });
});

describe('shutterLeaves', () => {
    const window = {offset: 1000, width: 1200};

    it('covers the window with two half leaves when closed', () => {
        expect(shutterLeaves(window, true)).toEqual([
            {from: 1000, to: 1600, hinge: 1000},
            {from: 1600, to: 2200, hinge: 2200},
        ]);
    });

    it('folds the leaves back against the wall beside the window when open', () => {
        expect(shutterLeaves(window, false)).toEqual([
            {from: 400, to: 1000, hinge: 1000},
            {from: 2200, to: 2800, hinge: 2200},
        ]);
    });
});

describe('exteriorSign', () => {
    it('is the face opposite the interior `side` (Left when not given)', () => {
        expect(exteriorSign({side: 'Left'})).toBe(1);
        expect(exteriorSign({side: null})).toBe(1);
        expect(exteriorSign({side: 'Right'})).toBe(-1);
    });
});

describe('louvreSlats', () => {
    it('spaces the slats by the pitch between the rails', () => {
        const slats = louvreSlats(600, 70, 45);
        expect(slats[0]).toBeCloseTo(92.5, 6);
        expect(slats.at(-1)!).toBeLessThanOrEqual(600 - 70 - 22.5 + 0.001);
        expect(slats).toHaveLength(10);
    });
});
