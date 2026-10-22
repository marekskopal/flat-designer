import * as THREE from 'three';
import {describe, expect, it} from 'vitest';

import {FrameStats, median, percentile, sceneCounters, summarize} from './frame-stats';

describe('percentile', () => {
    it('interpolates between the ranks', () => {
        expect(percentile([4, 1, 3, 2], 50)).toBe(2.5);
        expect(percentile([1, 2, 3, 4, 5], 95)).toBeCloseTo(4.8);
        expect(percentile([7], 95)).toBe(7);
        expect(percentile([], 50)).toBe(0);
    });

    it('takes the median of an odd count', () => {
        expect(median([9, 1, 5])).toBe(5);
    });
});

describe('FrameStats', () => {
    it('keeps the last frames only', () => {
        const stats = new FrameStats(3);
        [100, 100, 1, 2, 3].forEach((ms, i) => stats.record(ms, i));
        expect(stats.summary()).toEqual(summarize([1, 2, 3]));
        expect(stats.summary().count).toBe(3);
    });

    it('counts the frames of the last second', () => {
        const stats = new FrameStats();
        [0, 500, 1200, 1900].forEach((at) => stats.record(5, at));
        expect(stats.rendersPerSecond(2000)).toBe(2);
        expect(stats.rendersPerSecond(5000)).toBe(0);
    });
});

describe('sceneCounters', () => {
    it('counts the meshes and lights, with the shadow casters', () => {
        const root = new THREE.Group();
        root.add(new THREE.Mesh(new THREE.BoxGeometry()), new THREE.Mesh(new THREE.BoxGeometry()));
        const sun = new THREE.DirectionalLight();
        sun.castShadow = true;
        root.add(sun, new THREE.PointLight());
        expect(sceneCounters(root)).toEqual({meshes: 2, lights: 2, shadowLights: 1});
    });
});
