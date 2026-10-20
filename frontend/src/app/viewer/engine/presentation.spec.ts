import {describe, expect, it} from 'vitest';

import {SunShadowMapSize, sunShadowMapSize, SunShadowRadius} from './presentation';

describe('presentation quality', () => {
    it('gives the sun a larger shadow map, as large as the GPU allows', () => {
        expect(sunShadowMapSize('fast', 16384)).toBe(SunShadowMapSize.fast);
        expect(sunShadowMapSize('presentation', 16384)).toBe(8192);
        expect(sunShadowMapSize('presentation', 4096)).toBe(4096);
    });

    it('softens the sun shadow without blurring it away', () => {
        expect(SunShadowRadius.presentation).toBeGreaterThan(SunShadowRadius.fast);
    });
});
