import * as THREE from 'three';

import {AssetLoader} from './asset-loader';

/** Fetches an image file of the project (with the viewer's sign-in, or through a share link). */
export type PictureFetcher = (fileId: number) => Promise<Blob>;

/**
 * The images `picture` parts show, loaded once per file and shared by every item showing it: a picture's front face
 * crops it through its UVs (`coverUvs()`), so the texture belongs to the library, which disposes it with itself.
 * Framework-free; the host supplies the fetcher.
 */
export class PictureLibrary {
    /** A failed image leaves its pictures plain, as they are: nothing to redraw. */
    private readonly textures: AssetLoader<THREE.Texture>;

    public constructor(fetch: PictureFetcher) {
        this.textures = new AssetLoader(
            (fileId) =>
                fetch(fileId)
                    .then((blob) => decodeImage(blob))
                    .then((image) => {
                        const texture = new THREE.Texture(image);
                        texture.colorSpace = THREE.SRGBColorSpace;
                        texture.anisotropy = 4;
                        texture.needsUpdate = true;
                        return texture;
                    }),
            (texture) => texture.dispose(),
            false,
        );
    }

    /** An image finished loading: rebuild the items that show it. */
    public set onLoaded(listener: (fileId: number) => void) {
        this.textures.onLoaded = listener;
    }

    /** The image as a texture, or null while it loads or after it failed. */
    public texture(fileId: number): THREE.Texture | null {
        return this.textures.get(fileId);
    }

    public hasFailed(fileId: number): boolean {
        return this.textures.hasFailed(fileId);
    }

    public pending(): number {
        return this.textures.pending();
    }

    /**
     * Follows the scene's list of shown files: textures of files no longer listed are dropped (deleted since), and a
     * failed load of a listed file is tried again. Returns the ids changed either way, to redraw their items.
     */
    public sync(shown: Iterable<number>): number[] {
        return this.textures.sync(shown);
    }

    /** Frees the textures; loads still under way are dropped when they finish. */
    public dispose(): void {
        this.textures.dispose();
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
