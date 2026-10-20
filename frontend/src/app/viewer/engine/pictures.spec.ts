import * as THREE from 'three';
import {describe, expect, it} from 'vitest';

import {coverUvs, PictureLibrary} from './pictures';

describe('coverUvs', () => {
    it('crops the sides of a wider image and the top and bottom of a taller one', () => {
        expect(coverUvs(2, 1)).toEqual([0.25, 0, 0.75, 1]);
        expect(coverUvs(0.5, 1)).toEqual([0, 0.25, 1, 0.75]);
    });

    it('shows the whole image when the shapes match, or when one is unknown', () => {
        expect(coverUvs(1.5, 1.5)).toEqual([0, 0, 1, 1]);
        expect(coverUvs(Number.NaN, 1)).toEqual([0, 0, 1, 1]);
    });
});

describe('PictureLibrary.sync', () => {
    it('drops the textures of files no longer shown and lets a failed listed file load again', async () => {
        const calls: number[] = [];
        const library = new PictureLibrary((fileId) => {
            calls.push(fileId);
            return Promise.reject(new Error('gone'));
        });
        expect(library.texture(5)).toBeNull();
        await new Promise((resolve) => setTimeout(resolve, 0));
        expect(library.hasFailed(5)).toBe(true);
        expect(library.texture(5)).toBeNull();
        expect(calls).toEqual([5]);

        // Still listed: the next request tries again. Not listed any more: a loaded texture goes.
        (library as unknown as {textures: {assets: Map<number, THREE.Texture>}}).textures.assets.set(7, new THREE.Texture());
        // Dropped first, then the failed one listed again.
        expect(library.sync([5])).toEqual([7, 5]);
        expect(library.hasFailed(5)).toBe(false);
        expect(library.texture(5)).toBeNull();
        expect(calls).toEqual([5, 5]);
    });
});
