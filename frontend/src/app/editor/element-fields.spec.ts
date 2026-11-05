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
        expect(parseFieldValue(field('x'), '12.5')).toEqual({ok: false, error: {key: 'fields.errors.wholeNumber'}});
        expect(parseFieldValue(field('x'), '')).toEqual({ok: false, error: {key: 'fields.errors.required', params: {label: {key: 'fields.x'}}}});
    });

    it('clears nullable fields with an empty input', () => {
        expect(parseFieldValue(field('roomId'), '')).toEqual({ok: true, value: null});
        expect(parseFieldValue(field('leafWidth', 'opening'), '')).toEqual({ok: true, value: null});
        expect(parseFieldValue(field('materialId'), '9')).toEqual({ok: true, value: 9});
    });

    it('round-trips JSON fields', () => {
        const downpipes = [{edge: 'Front', along: 1200}];
        const def = field('downpipes', 'roof');
        expect(parseFieldValue(def, formatFieldValue(def, downpipes))).toEqual({ok: true, value: downpipes});
        expect(parseFieldValue(def, '[{')).toEqual({ok: false, error: {key: 'fields.errors.invalidJson'}});
        expect(parseFieldValue(def, '')).toEqual({ok: true, value: null});
    });

    it('edits outlines, railing points and finish bands with their own editors, not JSON', () => {
        expect(['room', 'plot', 'void'].map((kind) => field('polygon', kind as 'room').type)).toEqual(['points', 'points', 'points']);
        expect(field('points', 'railing')).toMatchObject({type: 'points', minPoints: 2, openRun: true});
        expect([field('wallFinishes', 'room'), field('leftFinishes', 'wall'), field('rightFinishes', 'wall')].map((f) => f.type)).toEqual([
            'finishes',
            'finishes',
            'finishes',
        ]);
        expect(field('leftFinishes', 'wall').nullable).toBe(true);
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
        expect(wallFaceName(east, 'Left')).toEqual({key: 'fields.faces.north'});
        expect(wallFaceName(east, 'Right')).toEqual({key: 'fields.faces.south'});
        expect(wallFaceName({startX: 0, startY: 3000, endX: 0, endY: 0}, 'Left')).toEqual({key: 'fields.faces.east'});
        expect(wallFaceName({startX: 0, startY: 0, endX: 3000, endY: 3000}, 'Left')).toEqual({key: 'fields.faces.northWest'});
        expect(wallFaceName({...east, sagitta: 200}, 'Left')).toEqual({key: 'fields.faces.convex', params: {face: {key: 'fields.faces.north'}}});
    });
});
