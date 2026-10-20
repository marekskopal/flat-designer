import {describe, expect, it} from 'vitest';

import {exteriorSign, freeBeside, LeafBottomRail, leafPanels, LeafRail, LeafStile, louvreSlats, shutterLeaves} from './opening-looks';

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

    it('keeps a leaf closed when there is no wall to fold it back onto', () => {
        // 500 mm of wall on the left (not enough for a 600 leaf), plenty on the right.
        expect(shutterLeaves(window, false, [500, 5000])).toEqual([
            {from: 1000, to: 1600, hinge: 1000},
            {from: 2200, to: 2800, hinge: 2200},
        ]);
    });
});

describe('freeBeside', () => {
    it('runs from the previous opening to the next, or to the wall ends', () => {
        const wall = {startX: 0, startY: 0, endX: 5000, endY: 0, openings: [] as {id: number; offset: number; width: number}[]};
        const window = {id: 2, offset: 2000, width: 1000};
        expect(freeBeside(window, wall as never)).toEqual([0, 5000]);
        wall.openings = [{id: 1, offset: 200, width: 900}, window, {id: 3, offset: 4000, width: 800}];
        expect(freeBeside(window, wall as never)).toEqual([1100, 4000]);
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
