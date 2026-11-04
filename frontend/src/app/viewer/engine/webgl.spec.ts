import {afterEach, describe, expect, it, vi} from 'vitest';

import {webglAvailable} from './webgl';

describe('webglAvailable', () => {
    afterEach(() => vi.restoreAllMocks());

    it('is false without a WebGL 2 context', () => {
        vi.spyOn(HTMLCanvasElement.prototype, 'getContext').mockReturnValue(null);
        expect(webglAvailable()).toBe(false);
    });

    it('is false when asking for one throws', () => {
        vi.spyOn(HTMLCanvasElement.prototype, 'getContext').mockImplementation(() => {
            throw new Error('blocked');
        });
        expect(webglAvailable()).toBe(false);
    });

    it('is true with one, and frees the probe at once', () => {
        const loseContext = vi.fn();
        const context = {getExtension: (name: string): {loseContext: () => void} | null => (name === 'WEBGL_lose_context' ? {loseContext} : null)};
        const getContext = vi.spyOn(HTMLCanvasElement.prototype, 'getContext').mockReturnValue(context as unknown as WebGL2RenderingContext);
        expect(webglAvailable()).toBe(true);
        expect(getContext).toHaveBeenCalledWith('webgl2');
        expect(loseContext).toHaveBeenCalled();
    });
});
