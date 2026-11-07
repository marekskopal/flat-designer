import {Item} from '@app/models/scene';
import * as THREE from 'three';
import {describe, expect, it} from 'vitest';

import {buildKitchenItemBody} from './kitchen';
import {applyFixtureLights, createFixtureLight, kelvinToColor, LumenScale, MaxFixtureShadows} from './lights';
import {MaterialLibrary} from './materials';

describe('kelvinToColor', () => {
    it('is warm at 2200 K, near white at 6500 K', () => {
        const warm = kelvinToColor(2200).getHexString(THREE.SRGBColorSpace);
        const daylight = kelvinToColor(6500).getHexString(THREE.SRGBColorSpace);
        expect(warm.slice(0, 2)).toBe('ff');
        expect(parseInt(warm.slice(4, 6), 16)).toBeLessThan(100);
        expect(parseInt(daylight.slice(4, 6), 16)).toBeGreaterThan(240);
    });
});

describe('createFixtureLight', () => {
    it('aims a spot straight down from wherever the fixture is placed', () => {
        const fixture = createFixtureLight({kind: 'spot', lumens: 500, kelvin: 3000, angle: 60, shadow: false});
        fixture.position.set(1.2, 2.5, -0.7);
        fixture.updateMatrixWorld(true);
        const spot = fixture.children.find((o) => o instanceof THREE.SpotLight) as THREE.SpotLight;
        const target = spot.target.getWorldPosition(new THREE.Vector3());
        expect(target.toArray().map((n) => Math.round(n * 1000) / 1000)).toEqual([1.2, 1.5, -0.7]);
    });
});

describe('applyFixtureLights', () => {
    it('switches lights on and off and gives shadows to the first few that ask', () => {
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
        expect(lights.every((l) => l.intensity === 0 && !l.castShadow && !l.visible)).toBe(true);
    });
});

describe('LED strips', () => {
    const strip = (params: Record<string, unknown>): Item =>
        ({
            id: 1,
            roomId: null,
            groupId: null,
            kind: 'LedStrip',
            definitionId: null,
            name: 'LED',
            x: 0,
            y: 0,
            z: 2200,
            width: 1200,
            depth: 20,
            height: 8,
            rotation: 0,
            materialId: null,
            params,
        }) as Item;
    /** Where the strip's light sits (mm, item frame) and the way it shines (a rect area light shines along its local −z). */
    const light = (params: Record<string, unknown>): {at: number[]; shines: number[]} => {
        const body = buildKitchenItemBody(strip(params), new MaterialLibrary([], 1)) as THREE.Group;
        const lights: THREE.RectAreaLight[] = [];
        body.traverse((o) => o instanceof THREE.RectAreaLight && lights.push(o));
        expect(lights).toHaveLength(1);
        body.updateMatrixWorld(true);
        const shines = new THREE.Vector3(0, 0, -1).applyQuaternion(lights[0].getWorldQuaternion(new THREE.Quaternion()));
        return {
            at: lights[0].position.toArray().map((v) => Math.round(v * 1000) + 0),
            shines: shines.toArray().map((v) => Math.round(v) + 0),
        };
    };

    it('shines down from its bottom by default, as under a cabinet', () => {
        expect(light({})).toEqual({at: [0, 0, 0], shines: [0, -1, 0]});
    });

    it('shines up from its top as a cove light, or out of its front', () => {
        expect(light({facing: 'up'})).toEqual({at: [0, 8, 0], shines: [0, 1, 0]});
        expect(light({facing: 'out'})).toEqual({at: [0, 4, 10], shines: [0, 0, 1]});
    });
});
