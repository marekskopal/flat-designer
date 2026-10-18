import {FurnitureDefinitionBody} from '@app/models/furniture';
import {describe, expect, it} from 'vitest';

import {customParamsOf, definitionError, isCustom, withSlotMaterial, withValue} from './custom-params';

const shelf: FurnitureDefinitionBody = {
    format: 1,
    size: {width: 800, depth: 300, height: 1800},
    parameters: [{key: 'shelves', type: 'int', default: 4, min: 2, max: 12}],
    materialSlots: [{key: 'carcass', default: 'kitchen-oak'}],
    parts: [{type: 'board', name: 'Shelf', repeat: {count: 'shelves'}, x: 0, y: 'i * 100', z: 0, w: 'W', h: 18, d: 'D', material: 'carcass'}],
};

describe('custom params', () => {
    it('recognises Custom items', () => {
        expect(isCustom({kind: 'Custom'})).toBe(true);
        expect(isCustom({kind: 'Box'})).toBe(false);
    });

    it('reads values and materials, tolerating anything else', () => {
        expect(customParamsOf({values: {shelves: 6}, materials: {carcass: 3}})).toEqual({values: {shelves: 6}, materials: {carcass: 3}});
        expect(customParamsOf({values: [1], materials: null})).toEqual({values: {}, materials: {}});
    });

    it('sets and drops values and slot materials, leaving out empty maps', () => {
        const withShelves = withValue({}, 'shelves', 6);
        expect(withShelves).toEqual({values: {shelves: 6}});
        const withOak = withSlotMaterial(withShelves, 'carcass', 3);
        expect(withOak).toEqual({values: {shelves: 6}, materials: {carcass: 3}});
        expect(withValue(withOak, 'shelves', undefined)).toEqual({materials: {carcass: 3}});
        expect(withSlotMaterial(withValue(withOak, 'shelves', undefined), 'carcass', undefined)).toEqual({});
    });

    it('explains why the definition does not work out', () => {
        expect(definitionError(shelf, {width: 800, depth: 300, height: 1800}, {})).toBeNull();
        expect(definitionError(shelf, {width: 800, depth: 300, height: 1800}, {values: {shelves: 20}})).toBe(
            'values.shelves: Must be a whole number from 2 up to 12.',
        );
    });
});
