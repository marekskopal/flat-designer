import * as THREE from 'three';
import {describe, expect, it} from 'vitest';

import {applianceFinishOf, applianceKindOf, buildAppliance} from './kitchen';
import {MaterialLibrary} from './materials';

const materials = new MaterialLibrary([], 1);
const front = {left: 0, width: 596, bottom: 0, height: 596, z: 0};

/** The colour of the appliance's face (its first mesh). */
function faceColour(group: THREE.Group): string {
    return ((group.children[0] as THREE.Mesh).material as THREE.MeshStandardMaterial).color.getHexString();
}

describe('appliance fronts', () => {
    it('reads unknown kinds as an oven and unknown finishes as black', () => {
        expect(applianceKindOf('coffee')).toBe('coffee');
        expect(applianceKindOf('toaster')).toBe('oven');
        expect(applianceFinishOf('retro')).toBe('retro');
        expect(applianceFinishOf(undefined)).toBe('black');
    });

    it('draws a steam oven with a display between two knobs and a coffee machine with its recess', () => {
        const oven = buildAppliance('oven', front, materials);
        const steam = buildAppliance('steam', front, materials);
        const coffee = buildAppliance('coffee', {...front, height: 450}, materials);
        // Face, window, handle, four knobs; face, window, handle, two knobs, display.
        expect(oven.children).toHaveLength(7);
        expect(steam.children).toHaveLength(6);
        // Face, display, recess, spouts, drip tray, knob.
        expect(coffee.children).toHaveLength(6);
    });

    it('takes the finish of the face', () => {
        expect(faceColour(buildAppliance('oven', front, materials, 19, 'white'))).toBe('f1f1ee');
        expect(faceColour(buildAppliance('oven', front, materials, 19, 'retro'))).toBe('e8dec6');
        expect(faceColour(buildAppliance('microwave', front, materials, 19, 'steel'))).toBe('b7b8b8');
    });
});
