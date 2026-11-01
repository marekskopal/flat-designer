import {describe, expect, it} from 'vitest';

import {fovFocalLength, lensFov} from './lens';

/** The horizontal field of view (degrees) of a vertical one in a view of `aspect`. */
function horizontal(fov: number, aspect: number): number {
    return (2 * Math.atan(Math.tan((fov * Math.PI) / 360) * aspect) * 180) / Math.PI;
}

describe('lensFov', () => {
    it('lays the 36 mm frame along the width of a landscape view', () => {
        // 18 mm covers 90° across, whatever the aspect.
        expect(horizontal(lensFov(18, 16 / 10), 16 / 10)).toBeCloseTo(90, 6);
        expect(horizontal(lensFov(18, 2.4), 2.4)).toBeCloseTo(90, 6);
    });

    it('lays it along the height of a portrait view', () => {
        expect(lensFov(18, 0.6)).toBeCloseTo(90, 6);
    });

    it('gives a 50 mm lens on 3:2 the angles of a full-frame camera', () => {
        expect(lensFov(50, 1.5)).toBeCloseTo(27, 0);
        expect(horizontal(lensFov(50, 1.5), 1.5)).toBeCloseTo(39.6, 1);
    });
});

describe('fovFocalLength', () => {
    it('inverts lensFov', () => {
        for (const aspect of [0.5, 1, 16 / 10, 2.5]) {
            for (const focalLength of [10, 16, 24, 35, 200]) {
                expect(fovFocalLength(lensFov(focalLength, aspect), aspect)).toBeCloseTo(focalLength, 6);
            }
        }
    });

    it('puts the standard 45° view at about 27 mm on a 16:10 capture', () => {
        expect(fovFocalLength(45, 16 / 10)).toBeCloseTo(27.2, 1);
    });
});
