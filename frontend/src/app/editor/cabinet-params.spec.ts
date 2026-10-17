import {describe, expect, it} from 'vitest';

import {addFront, frontsOf, isCabinet, moveFront, parseMillimetres, removeFront, withFront, withParam} from './cabinet-params';

const params = {plinth: 100, handle: 'horizontal', fronts: [{type: 'drawer', height: 180}, {type: 'door', count: 2}], custom: 1};

describe('cabinet params', () => {
    it('recognises cabinets', () => {
        expect(isCabinet({kind: 'BaseCabinet'})).toBe(true);
        expect(isCabinet({kind: 'Sink'})).toBe(false);
    });

    it('sets and removes top-level params, keeping unknown keys', () => {
        expect(withParam(params, 'plinth', 150)).toEqual({...params, plinth: 150});
        expect(withParam(params, 'handle', undefined)).toEqual({plinth: 100, fronts: params.fronts, custom: 1});
    });

    it('edits one front', () => {
        expect(frontsOf(withFront(params, 1, {height: 500, count: undefined}))).toEqual([{type: 'drawer', height: 180}, {type: 'door', height: 500}]);
        expect(frontsOf(withFront(params, 0, {type: 'oven'}))[0]).toEqual({type: 'oven', height: 180});
        expect(frontsOf(withFront(params, 1, {handle: 'none'}))[1]).toEqual({type: 'door', count: 2, handle: 'none'});
    });

    it('adds, removes and reorders fronts', () => {
        expect(frontsOf(addFront(params))).toHaveLength(3);
        expect(frontsOf(addFront({}))).toEqual([{type: 'door'}]);
        expect(frontsOf(removeFront(params, 0))).toEqual([{type: 'door', count: 2}]);
        expect(removeFront({fronts: [{type: 'door'}]}, 0)).toEqual({});
        expect(frontsOf(moveFront(params, 0, 1)).map((f) => f.type)).toEqual(['door', 'drawer']);
        expect(moveFront(params, 0, -1)).toBe(params);
    });

    it('parses millimetres', () => {
        expect(parseMillimetres(' ')).toEqual({ok: true, value: undefined});
        expect(parseMillimetres('120')).toEqual({ok: true, value: 120});
        expect(parseMillimetres('1.5').ok).toBe(false);
    });
});
