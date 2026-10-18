import {FurnitureDefinitionBody} from '@app/models/furniture';
import {describe, expect, it} from 'vitest';

import {
    customParamsOf,
    definitionError,
    isCustom,
    listItems,
    withItemAdded,
    withItemField,
    withItemMoved,
    withItemRemoved,
    withSlotMaterial,
    withValue,
} from './custom-params';

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

describe('list parameter items', () => {
    const fallback = [{type: 'drawer', height: 180}, {type: 'door'}];

    it('are the item\'s list or the definition\'s default', () => {
        expect(listItems({}, 'fronts', fallback)).toEqual(fallback);
        expect(listItems({values: {fronts: [{type: 'door'}]}}, 'fronts', fallback)).toEqual([{type: 'door'}]);
        expect(listItems({values: {fronts: 3}}, 'fronts', fallback)).toEqual(fallback);
        // A definition still being typed may lack the default list.
        expect(listItems({}, 'fronts', undefined)).toEqual([]);
        expect(listItems({}, 'fronts', {})).toEqual([]);
    });

    it('set and drop fields, add, remove and move items', () => {
        expect(withItemField(fallback, 1, 'height', 300)).toEqual([{type: 'drawer', height: 180}, {type: 'door', height: 300}]);
        expect(withItemField(fallback, 0, 'height', undefined)).toEqual([{type: 'drawer'}, {type: 'door'}]);
        expect(withItemAdded(fallback)).toEqual([...fallback, {}]);
        expect(withItemRemoved(fallback, 0)).toEqual([{type: 'door'}]);
        expect(withItemMoved(fallback, 0, 1)).toEqual([{type: 'door'}, {type: 'drawer', height: 180}]);
        expect(withItemMoved(fallback, 0, -1)).toEqual(fallback);
        // The default list itself is never changed.
        expect(fallback).toEqual([{type: 'drawer', height: 180}, {type: 'door'}]);
    });

    it('are stored as the parameter value, and the default comes back when it is dropped', () => {
        const params = withValue({}, 'fronts', withItemAdded(fallback));
        expect(params).toEqual({values: {fronts: [...fallback, {}]}});
        expect(withValue(params, 'fronts', undefined)).toEqual({});
    });
});
