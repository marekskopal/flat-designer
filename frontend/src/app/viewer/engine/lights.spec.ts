import * as THREE from 'three';
import {describe, expect, it} from 'vitest';

import {applyFixtureLights, createFixtureLight, kelvinToColor, LumenScale, MaxFixtureShadows} from './lights';

describe('kelvinToColor', () => {
    it('is warm at 2200 K, near white at 6500 K', () => {
        const warm = kelvinToColor(2200).getHexString(THREE.SRGBColorSpace);
        const daylight = kelvinToColor(6500).getHexString(THREE.SRGBColorSpace);
        expect(warm.slice(0, 2)).toBe('ff');
        expect(parseInt(warm.slice(4, 6), 16)).toBeLessThan(100);
        expect(parseInt(daylight.slice(4, 6), 16)).toBeGreaterThan(240);
    });
});

describe('applyFixtureLights', () => {
    it('switches lights by intensity and gives shadows to the first few that ask', () => {
        const root = new THREE.Group();
        for (let i = 0; i < 4; i++) {
            root.add(createFixtureLight({kind: i % 2 === 0 ? 'point' : 'spot', lumens: 800, kelvin: 2700, angle: 60, shadow: true}));
        }
        const lights: THREE.Light[] = [];
        root.traverse((o) => o instanceof THREE.Light && lights.push(o));

        expect(applyFixtureLights(root, true)).toBe(4);
        expect(lights.filter((l) => l.castShadow)).toHaveLength(MaxFixtureShadows);
        expect((lights[0] as THREE.PointLight).power).toBeCloseTo(800 * LumenScale, 6);
        expect((lights[1] as THREE.SpotLight).power).toBeCloseTo(800 * LumenScale, 6);

        applyFixtureLights(root, false);
        expect(lights.every((l) => l.intensity === 0 && !l.castShadow)).toBe(true);
    });
});
