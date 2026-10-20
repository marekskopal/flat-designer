import * as THREE from 'three';

/** Fetches an image file of the project (with the viewer's sign-in, or through a share link). */
export type PictureFetcher = (fileId: number) => Promise<Blob>;

/**
 * The images `picture` parts show, loaded once per file and shared by every item showing it: a picture's front face
 * crops it through its UVs (`coverUvs()`), so the texture belongs to the library, which disposes it with itself.
 * Framework-free; the host supplies the fetcher.
 */
export class PictureLibrary {
    /** An image finished loading (or failed): rebuild the items that show it. */
    public onLoaded: (fileId: number) => void = () => undefined;

    private readonly textures = new Map<number, THREE.Texture>();
    private readonly failed = new Set<number>();
    private readonly loading = new Set<number>();
    private disposed = false;

    public constructor(private readonly fetch: PictureFetcher) {}

    /** The image as a texture, or null while it loads or after it failed. */
    public texture(fileId: number): THREE.Texture | null {
        const texture = this.textures.get(fileId);
        if (texture === undefined) {
            this.load(fileId);
            return null;
        }
        return texture;
    }

    public hasFailed(fileId: number): boolean {
        return this.failed.has(fileId);
    }

    /** Frees the textures; loads still under way are dropped when they finish. */
    public dispose(): void {
        this.disposed = true;
        for (const texture of this.textures.values()) {
            texture.dispose();
        }
        this.textures.clear();
    }

    private load(fileId: number): void {
        if (this.loading.has(fileId) || this.failed.has(fileId)) {
            return;
        }
        this.loading.add(fileId);
        this.fetch(fileId)
            .then((blob) => decodeImage(blob))
            .then((image) => {
                if (this.disposed) {
                    return;
                }
                const texture = new THREE.Texture(image);
                texture.colorSpace = THREE.SRGBColorSpace;
                texture.anisotropy = 4;
                texture.needsUpdate = true;
                this.textures.set(fileId, texture);
            })
            .catch(() => this.failed.add(fileId))
            .finally(() => {
                this.loading.delete(fileId);
                if (!this.disposed) {
                    this.onLoaded(fileId);
                }
            });
    }
}

/** The blob as a decoded image element (the object URL is released again). */
export async function decodeImage(blob: Blob): Promise<HTMLImageElement> {
    const url = URL.createObjectURL(blob);
    try {
        const image = new Image();
        image.src = url;
        await image.decode();
        return image;
    } finally {
        URL.revokeObjectURL(url);
    }
}

/**
 * The texture coordinates (u0, v0, u1, v1) that make an image of `imageAspect` (width / height) fill a face of
 * `faceAspect` without stretching: centred, the overflowing sides cropped.
 */
export function coverUvs(imageAspect: number, faceAspect: number): [number, number, number, number] {
    if (!(imageAspect > 0) || !(faceAspect > 0)) {
        return [0, 0, 1, 1];
    }
    if (imageAspect > faceAspect) {
        const crop = (1 - faceAspect / imageAspect) / 2;
        return [crop, 0, 1 - crop, 1];
    }
    const crop = (1 - imageAspect / faceAspect) / 2;
    return [0, crop, 1, 1 - crop];
}
