import * as THREE from 'three';
import {describe, expect, it} from 'vitest';

import {sameSelection, selectionOf} from './selection';

describe('selectionOf', () => {
    it('finds the nearest tagged ancestor', () => {
        const wall = new THREE.Group();
        wall.userData = {wallId: 4};
        const door = new THREE.Group();
        door.userData = {openingId: 9};
        const leaf = new THREE.Mesh();
        const plaster = new THREE.Mesh();
        wall.add(door, plaster);
        door.add(leaf);

        expect(selectionOf(leaf)).toEqual({kind: 'opening', id: 9});
        expect(selectionOf(plaster)).toEqual({kind: 'wall', id: 4});
    });

    it('returns null for untagged objects', () => {
        expect(selectionOf(new THREE.Mesh())).toBeNull();
        expect(selectionOf(null)).toBeNull();
    });

    it('compares selections by kind and id', () => {
        expect(sameSelection({kind: 'item', id: 1}, {kind: 'item', id: 1})).toBe(true);
        expect(sameSelection({kind: 'item', id: 1}, {kind: 'room', id: 1})).toBe(false);
        expect(sameSelection(null, null)).toBe(true);
        expect(sameSelection({kind: 'item', id: 1}, null)).toBe(false);
    });
});
