import {describe, expect, it} from 'vitest';

import {SunShadowMapSize, sunShadowMapSize, SunShadowRadius} from './presentation';

describe('presentation quality', () => {
    it('gives the sun a larger shadow map on a large GPU only', () => {
        expect(sunShadowMapSize('fast', 16384)).toBe(SunShadowMapSize.fast);
        expect(sunShadowMapSize('presentation', 16384)).toBe(8192);
        // A phone or an integrated GPU reports 8192 but has not the memory for such a map.
        expect(sunShadowMapSize('presentation', 8192)).toBe(4096);
        expect(sunShadowMapSize('fast', 2048)).toBe(2048);
    });

    it('softens the sun shadow without blurring it away', () => {
        expect(SunShadowRadius.presentation).toBeGreaterThan(SunShadowRadius.fast);
    });
});
