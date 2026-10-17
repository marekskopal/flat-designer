import {describe, expect, it} from 'vitest';

import {ElementFields} from './element-fields';
import {doorCount, isWardrobe, mirrorDoorsOf, withDoors, withMirrorDoor} from './wardrobe-params';

describe('wardrobe params', () => {
    it('recognises wardrobes and gives them the wardrobe form instead of JSON', () => {
        expect(isWardrobe({kind: 'Wardrobe'})).toBe(true);
        expect(isWardrobe({kind: 'TallCabinet'})).toBe(false);
        const paramsFields = (kind: string): string[] =>
            ElementFields.item.filter((def) => def.key === 'params' && (def.when?.({kind}) ?? true)).map((def) => def.type);
        expect(paramsFields('Wardrobe')).toEqual(['wardrobe']);
        expect(paramsFields('Box')).toEqual(['json']);
    });

    it('counts doors from params or the width', () => {
        expect(doorCount({doors: 4}, 2468)).toBe(4);
        expect(doorCount({}, 2468)).toBe(3);
    });

    it('sets the door count and drops mirror doors beyond it', () => {
        expect(withDoors({mirrorDoors: [1, 4], custom: 1}, 3, 2468)).toEqual({custom: 1, doors: 3, mirrorDoors: [1]});
        expect(withDoors({doors: 4, mirrorDoors: [4]}, undefined, 1800)).toEqual({});
    });

    it('switches mirror doors on and off', () => {
        expect(mirrorDoorsOf(withMirrorDoor({mirrorDoors: [3]}, 1, true))).toEqual([1, 3]);
        expect(withMirrorDoor({mirrorDoors: [3]}, 3, false)).toEqual({});
        expect(mirrorDoorsOf({mirrorDoors: 'x'})).toEqual([]);
    });
});
