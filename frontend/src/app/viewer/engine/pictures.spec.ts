import {describe, expect, it} from 'vitest';

import {coverUvs} from './pictures';

describe('coverUvs', () => {
    it('crops the sides of a wider image and the top and bottom of a taller one', () => {
        expect(coverUvs(2, 1)).toEqual([0.25, 0, 0.75, 1]);
        expect(coverUvs(0.5, 1)).toEqual([0, 0.25, 1, 0.75]);
    });

    it('shows the whole image when the shapes match, or when one is unknown', () => {
        expect(coverUvs(1.5, 1.5)).toEqual([0, 0, 1, 1]);
        expect(coverUvs(Number.NaN, 1)).toEqual([0, 0, 1, 1]);
    });
});
