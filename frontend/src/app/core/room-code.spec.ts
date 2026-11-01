import {describe, expect, it} from 'vitest';

import {roomCodeText} from './room-code';

describe('roomCodeText', () => {
    it('drops the sample project\'s building prefix', () => {
        expect(roomCodeText('H1/B_04')).toBe('B_04');
    });

    it('keeps other codes, slashes included', () => {
        expect(roomCodeText('B_04')).toBe('B_04');
        expect(roomCodeText('1/01')).toBe('1/01');
        expect(roomCodeText('')).toBe('');
    });
});
