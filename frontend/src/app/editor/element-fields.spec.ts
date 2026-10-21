import {describe, expect, it} from 'vitest';

import {ElementFields, FieldDef, formatFieldValue, isChanged, parseFieldValue, wallFaceName} from './element-fields';

const field = (key: string, kind: keyof typeof ElementFields = 'item'): FieldDef => {
    const def = ElementFields[kind].find((f) => f.key === key);
    if (def === undefined) {
        throw new Error(`No field ${key}`);
    }
    return def;
};

describe('element fields', () => {
    it('parses whole millimetres only', () => {
        expect(parseFieldValue(field('x'), ' 1200 ')).toEqual({ok: true, value: 1200});
        expect(parseFieldValue(field('x'), '-50')).toEqual({ok: true, value: -50});
        expect(parseFieldValue(field('x'), '12.5')).toEqual({ok: false, error: 'Whole number expected.'});
        expect(parseFieldValue(field('x'), '')).toEqual({ok: false, error: 'X is required.'});
    });

    it('clears nullable fields with an empty input', () => {
        expect(parseFieldValue(field('roomId'), '')).toEqual({ok: true, value: null});
        expect(parseFieldValue(field('leafWidth', 'opening'), '')).toEqual({ok: true, value: null});
        expect(parseFieldValue(field('materialId'), '9')).toEqual({ok: true, value: 9});
    });

    it('round-trips JSON fields', () => {
        const polygon = [[0, 0], [3000, 0], [3000, 2000]];
        const def = field('polygon', 'room');
        expect(parseFieldValue(def, formatFieldValue(def, polygon))).toEqual({ok: true, value: polygon});
        expect(parseFieldValue(def, '[[0,0]')).toEqual({ok: false, error: 'Invalid JSON.'});
    });

    it('reads checkbox flags', () => {
        const def = field('entrance', 'opening');
        expect(parseFieldValue(def, formatFieldValue(def, true))).toEqual({ok: true, value: true});
        expect(parseFieldValue(def, 'false')).toEqual({ok: true, value: false});
    });

    it('detects changes by content', () => {
        expect(isChanged({plinth: 100}, {plinth: 100})).toBe(false);
        expect(isChanged(null, undefined)).toBe(false);
        expect(isChanged(600, 610)).toBe(true);
    });

    it('names a wall face by the direction it faces', () => {
        const east = {startX: 0, startY: 0, endX: 3000, endY: 0};
        expect(wallFaceName(east, 'Left')).toBe('North face');
        expect(wallFaceName(east, 'Right')).toBe('South face');
        expect(wallFaceName({startX: 0, startY: 3000, endX: 0, endY: 0}, 'Left')).toBe('East face');
        expect(wallFaceName({startX: 0, startY: 0, endX: 3000, endY: 3000}, 'Left')).toBe('North-west face');
        expect(parseFieldValue(field('leftFinishes', 'wall'), '')).toEqual({ok: true, value: null});
        expect(parseFieldValue(field('leftFinishes', 'wall'), '[]')).toEqual({ok: true, value: []});
    });
});
