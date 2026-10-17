import {Scene} from '@app/models/scene';
import {describe, expect, it} from 'vitest';

import {hiddenItemIds} from './variants';

describe('hiddenItemIds', () => {
    const scene = {
        items: [
            {id: 1, groupId: null},
            {id: 2, groupId: 10},
            {id: 3, groupId: 11},
            {id: 4, groupId: 12},
        ],
        groups: [
            {id: 10, name: 'Island', variantSetId: 5},
            {id: 11, name: 'Table', variantSetId: 5},
            {id: 12, name: 'Runs', variantSetId: null},
        ],
        variantSets: [{id: 5, name: 'Layout', activeGroupId: 10}],
    } as unknown as Scene;

    it('hides the items of inactive variants only', () => {
        expect([...hiddenItemIds(scene)]).toEqual([3]);
    });

    it('hides all variants of a set showing none', () => {
        expect([...hiddenItemIds({...scene, variantSets: [{id: 5, name: 'Layout', activeGroupId: null}]})]).toEqual([2, 3]);
    });

    it('shows everything in scenes without variant sets', () => {
        expect(hiddenItemIds({...scene, groups: undefined, variantSets: undefined}).size).toBe(0);
    });
});
