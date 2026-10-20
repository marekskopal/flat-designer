/**
 * Assets loaded once by id and shared by every item using them (3D models, pictures): what is loaded, loading or has
 * failed, with the items told when a load finishes. Framework-free; the host supplies the loader and the disposal.
 */
export class AssetLoader<T> {
    /** An asset finished loading (also failed, with `notifyFailure`): rebuild the items that use it. */
    public onLoaded: (id: number) => void = () => undefined;

    private readonly assets = new Map<number, T>();
    private readonly failed = new Set<number>();
    private readonly loading = new Set<number>();
    /** The ids the scene uses, from the last `sync()`; null before one. */
    private listed: Set<number> | null = null;
    private disposed = false;

    /**
     * @param load fetches and decodes one asset
     * @param free releases a loaded asset (GPU resources)
     * @param notifyFailure whether a failed load also calls `onLoaded` (the items then show that it is missing)
     */
    public constructor(
        private readonly load: (id: number) => Promise<T>,
        private readonly free: (asset: T) => void,
        private readonly notifyFailure: boolean,
    ) {}

    /** The asset, or null while it loads (started here) or after it failed. */
    public get(id: number): T | null {
        const asset = this.assets.get(id);
        if (asset === undefined) {
            this.start(id);
            return null;
        }
        return asset;
    }

    /** How many assets are still loading. */
    public pending(): number {
        return this.loading.size;
    }

    public hasFailed(id: number): boolean {
        return this.failed.has(id);
    }

    /**
     * Follows the scene's list of used assets: those no longer listed are released (deleted since; one still loading
     * is released when it arrives), and a failed load of a listed one may be tried again. Returns the ids whose state
     * changed either way, so that their items are redrawn (and ask again).
     */
    public sync(used: Iterable<number>): number[] {
        const listed = new Set(used);
        this.listed = listed;
        const changed: number[] = [];
        for (const [id, asset] of this.assets) {
            if (!listed.has(id)) {
                this.free(asset);
                this.assets.delete(id);
                changed.push(id);
            }
        }
        for (const id of listed) {
            if (this.failed.delete(id)) {
                changed.push(id);
            }
        }
        return changed;
    }

    /** Releases the assets; loads still under way are dropped when they finish. */
    public dispose(): void {
        this.disposed = true;
        for (const asset of this.assets.values()) {
            this.free(asset);
        }
        this.assets.clear();
    }

    private start(id: number): void {
        if (this.loading.has(id) || this.failed.has(id)) {
            return;
        }
        this.loading.add(id);
        let loaded = false;
        this.load(id)
            .then((asset) => {
                if (this.disposed || (this.listed !== null && !this.listed.has(id))) {
                    // Gone meanwhile: nothing uses it any more.
                    this.free(asset);
                    return;
                }
                this.assets.set(id, asset);
                loaded = true;
            })
            .catch(() => this.failed.add(id))
            .finally(() => {
                this.loading.delete(id);
                if (!this.disposed && (loaded || this.notifyFailure)) {
                    this.onLoaded(id);
                }
            });
    }
}
