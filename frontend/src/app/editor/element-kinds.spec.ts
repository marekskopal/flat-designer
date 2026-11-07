import {Scene} from '@app/models/scene';
import {describe, expect, it} from 'vitest';

import {itemCategory} from './element-kinds';

describe('itemCategory', () => {
    const scene = {definitions: [{id: 5, definition: {category: 'socket'}}]} as unknown as Scene;

    it('takes a Custom item\'s from its definition and counts an LED strip as a light', () => {
        expect(itemCategory(scene, {kind: 'Custom', definitionId: 5})).toBe('socket');
        expect(itemCategory(scene, {kind: 'LedStrip', definitionId: null})).toBe('light');
        expect(itemCategory(scene, {kind: 'Box', definitionId: null})).toBeNull();
    });
});
