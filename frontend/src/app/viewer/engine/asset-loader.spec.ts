import {describe, expect, it} from 'vitest';

import {AssetLoader} from './asset-loader';

const tick = (): Promise<void> => new Promise((resolve) => setTimeout(resolve, 0));

describe('AssetLoader', () => {
    it('loads an asset once, tells when it is there and releases it when no longer used', async () => {
        const calls: number[] = [];
        const freed: string[] = [];
        const loader = new AssetLoader<string>(
            async (id) => {
                calls.push(id);
                return `asset ${id}`;
            },
            (asset) => freed.push(asset),
            false,
        );
        const loaded: number[] = [];
        loader.onLoaded = (id): void => {
            loaded.push(id);
        };

        expect(loader.get(5)).toBeNull();
        expect(loader.get(5)).toBeNull();
        await tick();
        expect(loader.get(5)).toBe('asset 5');
        expect(calls).toEqual([5]);
        expect(loaded).toEqual([5]);

        expect(loader.sync([])).toEqual([5]);
        expect(freed).toEqual(['asset 5']);
        expect(loader.get(5)).toBeNull();
        await tick();
        expect(calls).toEqual([5, 5]);
    });

    it('remembers a failure until the asset is listed again, telling only when asked', async () => {
        for (const notify of [false, true]) {
            const loaded: number[] = [];
            const loader = new AssetLoader<string>(() => Promise.reject(new Error('gone')), () => undefined, notify);
            loader.onLoaded = (id): void => {
                loaded.push(id);
            };
            loader.get(7);
            await tick();
            expect(loader.hasFailed(7)).toBe(true);
            expect(loaded).toEqual(notify ? [7] : []);
            loader.get(7);
            await tick();
            expect(loaded).toEqual(notify ? [7] : []);
            expect(loader.sync([7])).toEqual([7]);
            expect(loader.hasFailed(7)).toBe(false);
        }
    });

    it('releases an asset that leaves the scene while it is still loading', async () => {
        let resolve: (asset: string) => void = () => undefined;
        const freed: string[] = [];
        const loader = new AssetLoader<string>(() => new Promise((r) => (resolve = r)), (asset) => freed.push(asset), false);
        loader.get(9);
        expect(loader.sync([])).toEqual([]);
        resolve('late');
        await tick();
        expect(freed).toEqual(['late']);
        expect(loader.get(9)).toBeNull();
    });

    it('drops and frees a load that finishes after disposal', async () => {
        let resolve: (asset: string) => void = () => undefined;
        const freed: string[] = [];
        const loader = new AssetLoader<string>(() => new Promise((r) => (resolve = r)), (asset) => freed.push(asset), true);
        const loaded: number[] = [];
        loader.onLoaded = (id): void => {
            loaded.push(id);
        };
        loader.get(3);
        loader.dispose();
        resolve('late');
        await tick();
        expect(freed).toEqual(['late']);
        expect(loaded).toEqual([]);
    });
});
