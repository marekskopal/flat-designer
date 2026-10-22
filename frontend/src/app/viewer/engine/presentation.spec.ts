import * as THREE from 'three';
import {describe, expect, it} from 'vitest';

import {SunShadowMapSize, sunShadowMapSize, SunShadowRadius, viewChanged} from './presentation';

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

describe('viewChanged', () => {
    it('ignores what damping leaves behind and notices a move', () => {
        const a = new THREE.Matrix4().makeTranslation(1, 2, 3);
        expect(viewChanged(a, a.clone().multiply(new THREE.Matrix4().makeTranslation(1e-7, 0, 0)))).toBe(false);
        expect(viewChanged(a, a.clone().multiply(new THREE.Matrix4().makeTranslation(0.001, 0, 0)))).toBe(true);
        expect(viewChanged(a, a.clone().multiply(new THREE.Matrix4().makeRotationY(0.001)))).toBe(true);
    });
});
