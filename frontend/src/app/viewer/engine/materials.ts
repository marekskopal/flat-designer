import {Material as MaterialDto} from '@app/models/scene';
import * as THREE from 'three';

/** World size (m) one texture tile covers, per procedural pattern. */
const PatternTileSize: Record<string, number> = {
    Wood: 1.2,
    Planks: 2.4,
    Tiles: 1.2,
};

export type GrainDirection = 'vertical' | 'horizontal';

/**
 * Builds three.js materials from the backend material catalogue. Planks and tiles with a `texture`
 * are composed from the photo of one real plank/tile; other wood, plank and tile surfaces get
 * procedural canvas textures. Geometry is expected to carry UVs in metres (see `worldUvBox`), so
 * textures repeat at their real-world size.
 */
export class MaterialLibrary {
    private readonly byId = new Map<number, MaterialDto>();
    private readonly idByCode = new Map<string, number>();
    private readonly cache = new Map<string, THREE.Material>();
    private readonly textures = new Map<string, THREE.Texture>();

    public constructor(materials: readonly MaterialDto[], private readonly maxAnisotropy: number) {
        for (const material of materials) {
            this.byId.set(material.id, material);
            this.idByCode.set(material.code, material.id);
        }
    }

    public get(id: number | null, grain: GrainDirection = 'vertical'): THREE.Material {
        const dto = id === null ? undefined : this.byId.get(id);
        if (dto === undefined) {
            return this.fallback();
        }

        const key = `${dto.id}:${grain}`;
        let material = this.cache.get(key);
        if (material === undefined) {
            material = this.create(dto, grain);
            this.cache.set(key, material);
        }
        return material;
    }

    public byCode(code: string, grain: GrainDirection = 'vertical'): THREE.Material {
        return this.get(this.idByCode.get(code) ?? null, grain);
    }

    /** Plain material by colour, cached; for details (handles, frames, plinth shadows) not in the catalogue. */
    public plain(color: string, roughness = 0.5, metalness = 0, side: THREE.Side = THREE.FrontSide): THREE.MeshStandardMaterial {
        const key = `plain:${color}:${roughness}:${metalness}:${side}`;
        let material = this.cache.get(key);
        if (material === undefined) {
            material = new THREE.MeshStandardMaterial({color, roughness, metalness, side});
            this.cache.set(key, material);
        }
        return material as THREE.MeshStandardMaterial;
    }

    public dispose(): void {
        this.cache.forEach((m) => m.dispose());
        this.textures.forEach((t) => t.dispose());
        this.cache.clear();
        this.textures.clear();
    }

    private create(dto: MaterialDto, grain: GrainDirection): THREE.Material {
        if (dto.pattern === 'Glass') {
            return this.glass(dto);
        }

        const material = new THREE.MeshStandardMaterial({
            color: dto.pattern === 'Plain' ? dto.color : '#ffffff',
            roughness: dto.roughness,
            metalness: dto.metalness,
        });

        if (dto.pattern !== 'Plain') {
            material.map = this.texture(dto, grain);
        }
        if (dto.code.startsWith('led')) {
            material.emissive = new THREE.Color(dto.color);
            material.emissiveIntensity = 2.5;
        }
        return material;
    }

    /** Light glazing is see-through; dark glass (appliance fronts, hob) is an opaque mirror-like surface. */
    private glass(dto: MaterialDto): THREE.Material {
        const color = new THREE.Color(dto.color);
        const luminance = 0.2126 * color.r + 0.7152 * color.g + 0.0722 * color.b;
        if (luminance < 0.3) {
            return new THREE.MeshStandardMaterial({color, roughness: dto.roughness, metalness: Math.max(dto.metalness, 0.3)});
        }
        return new THREE.MeshPhysicalMaterial({
            color,
            roughness: dto.roughness,
            metalness: 0,
            transparent: true,
            opacity: 0.18,
            envMapIntensity: 1.5,
            depthWrite: false,
        });
    }

    private texture(dto: MaterialDto, grain: GrainDirection): THREE.Texture {
        const key = `${dto.id}:${grain}`;
        let texture = this.textures.get(key);
        if (texture !== undefined) {
            return texture;
        }

        const layout = imageLayout(dto);
        if (layout !== null) {
            texture = this.imageTexture(dto, layout);
        } else {
            texture = new THREE.CanvasTexture(drawPattern(dto));
            const repeat = 1 / (PatternTileSize[dto.pattern] ?? 1);
            texture.repeat.set(repeat, repeat);
            // Procedural planks are drawn along the canvas width; turn them to run along v like the photo-based ones.
            if (grain === 'horizontal' || dto.pattern === 'Planks') {
                texture.rotation = Math.PI / 2;
            }
        }

        texture.colorSpace = THREE.SRGBColorSpace;
        texture.wrapS = THREE.RepeatWrapping;
        texture.wrapT = THREE.RepeatWrapping;
        texture.anisotropy = this.maxAnisotropy;
        this.textures.set(key, texture);
        return texture;
    }

    /**
     * Texture composed from the photo of a single plank or tile: the canvas holds a small patch of the
     * real layout and is redrawn once the image has loaded (until then it shows the base colour).
     */
    private imageTexture(dto: MaterialDto, layout: ImageLayout): THREE.CanvasTexture {
        const canvas = document.createElement('canvas');
        canvas.width = Math.round(layout.width / LayoutMmPerPixel);
        canvas.height = Math.round(layout.height / LayoutMmPerPixel);
        const ctx = canvas.getContext('2d');
        if (ctx !== null) {
            ctx.fillStyle = dto.color;
            ctx.fillRect(0, 0, canvas.width, canvas.height);
        }

        const texture = new THREE.CanvasTexture(canvas);
        texture.repeat.set(1000 / layout.width, 1000 / layout.height);

        const image = new Image();
        image.onload = (): void => {
            if (ctx !== null) {
                layout.draw(ctx, image, seededRandom(dto.id * 7919));
                texture.needsUpdate = true;
            }
        };
        image.src = new URL(`assets/${dto.texture}`, document.baseURI).href;
        return texture;
    }

    private fallback(): THREE.Material {
        return this.plain('#d8d4cc', 0.9);
    }
}

/** Resolution of composed plank/tile canvases. */
const LayoutMmPerPixel = 2;

interface ImageLayout {
    /** Physical size (mm) of the patch the canvas represents; it tiles seamlessly. */
    width: number;
    height: number;
    draw(ctx: CanvasRenderingContext2D, image: HTMLImageElement, rng: () => number): void;
}

function imageLayout(dto: MaterialDto): ImageLayout | null {
    if (dto.texture === null || dto.tileWidth === null || dto.tileHeight === null) {
        return null;
    }
    const tileWidth = dto.tileWidth;
    const tileHeight = dto.tileHeight;

    if (dto.pattern === 'Planks') {
        // Planks run along the texture v axis (plan y on floors) in a random staggered bond. The patch
        // is large enough (≈ 3.2 × 3.7 m) that its repetition does not read in a room.
        const columns = 12;
        const rows = 3;
        return {
            width: columns * tileWidth,
            height: rows * tileHeight,
            draw: (ctx, image, rng): void => drawPlankLayout(ctx, image, rng, columns, rows, tileWidth, tileHeight),
        };
    }
    if (dto.pattern === 'Tiles') {
        return {
            width: 2 * tileWidth,
            height: 2 * tileHeight,
            draw: (ctx, image, rng): void => drawTileLayout(ctx, image, rng, tileWidth, tileHeight),
        };
    }
    return null;
}

/**
 * Columns of planks, each column shifted by a random offset. The canvas is `rows` planks tall and the
 * plank cut by the bottom edge reappears at the top, so the patch tiles seamlessly.
 */
function drawPlankLayout(
    ctx: CanvasRenderingContext2D,
    image: HTMLImageElement,
    rng: () => number,
    columns: number,
    rows: number,
    plankWidth: number,
    plankLength: number,
): void {
    const w = plankWidth / LayoutMmPerPixel;
    const l = plankLength / LayoutMmPerPixel;

    for (let column = 0; column < columns; column++) {
        const offset = rng() * l;
        const variants = Array.from({length: rows}, () => ({flipX: rng() < 0.5, flipY: rng() < 0.5, tone: (rng() - 0.5) * 0.14}));
        for (let k = -1; k < rows; k++) {
            const variant = variants[(k + rows) % rows];
            drawPiece(ctx, image, column * w, offset + k * l, w, l, variant);
        }
    }

    // Bevelled joints: long edges between columns, short edges at plank ends.
    ctx.fillStyle = 'rgba(40, 28, 16, 0.45)';
    for (let column = 0; column < columns; column++) {
        ctx.fillRect(column * w, 0, 1, ctx.canvas.height);
    }
}

/** 2 × 2 tiles from the photo, randomly mirrored, with light grout joints. */
function drawTileLayout(ctx: CanvasRenderingContext2D, image: HTMLImageElement, rng: () => number, tileWidth: number, tileHeight: number): void {
    const w = tileWidth / LayoutMmPerPixel;
    const h = tileHeight / LayoutMmPerPixel;
    for (let i = 0; i < 2; i++) {
        for (let j = 0; j < 2; j++) {
            drawPiece(ctx, image, i * w, j * h, w, h, {flipX: rng() < 0.5, flipY: rng() < 0.5, tone: (rng() - 0.5) * 0.06});
        }
    }

    const grout = Math.max(1, Math.round(2 / LayoutMmPerPixel));
    ctx.fillStyle = '#d9d4cb';
    for (let i = 0; i < 2; i++) {
        ctx.fillRect(i * w, 0, grout, ctx.canvas.height);
        ctx.fillRect(0, i * h, ctx.canvas.width, grout);
    }
}

function drawPiece(
    ctx: CanvasRenderingContext2D,
    image: HTMLImageElement,
    x: number,
    y: number,
    w: number,
    h: number,
    variant: {flipX: boolean; flipY: boolean; tone: number},
): void {
    ctx.save();
    ctx.translate(x + (variant.flipX ? w : 0), y + (variant.flipY ? h : 0));
    ctx.scale(variant.flipX ? -1 : 1, variant.flipY ? -1 : 1);
    ctx.drawImage(image, 0, 0, w, h);
    ctx.fillStyle = variant.tone >= 0 ? `rgba(255,255,255,${variant.tone})` : `rgba(0,0,0,${-variant.tone})`;
    ctx.fillRect(0, 0, w, h);
    if (h > w * 2) {
        // Plank end joint.
        ctx.fillStyle = 'rgba(40, 28, 16, 0.45)';
        ctx.fillRect(0, 0, w, 1);
    }
    ctx.restore();
}

function drawPattern(dto: MaterialDto): HTMLCanvasElement {
    const canvas = document.createElement('canvas');
    const ctx = canvas.getContext('2d');
    if (ctx === null) {
        return canvas;
    }
    const rng = seededRandom(dto.id * 7919);

    switch (dto.pattern) {
        case 'Wood':
            canvas.width = 512;
            canvas.height = 1024;
            drawWood(ctx, canvas.width, canvas.height, dto.color, rng);
            break;
        case 'Planks':
            canvas.width = 2048;
            canvas.height = 2048;
            drawPlanks(ctx, canvas.width, dto.color, rng);
            break;
        case 'Tiles':
            canvas.width = 1024;
            canvas.height = 1024;
            drawTiles(ctx, canvas.width, dto.color, rng);
            break;
        default:
            canvas.width = canvas.height = 4;
            ctx.fillStyle = dto.color;
            ctx.fillRect(0, 0, 4, 4);
    }
    return canvas;
}

/** Oak grain running along the canvas height (texture v). */
function drawWood(ctx: CanvasRenderingContext2D, w: number, h: number, color: string, rng: () => number): void {
    const base = new THREE.Color(color);
    ctx.fillStyle = `#${base.getHexString()}`;
    ctx.fillRect(0, 0, w, h);

    // Broad tonal bands.
    for (let i = 0; i < 14; i++) {
        const x = rng() * w;
        const width = 10 + rng() * 60;
        ctx.fillStyle = `#${shade(base, (rng() - 0.5) * 0.18).getHexString()}`;
        ctx.globalAlpha = 0.35;
        ctx.fillRect(x, 0, width, h);
    }

    // Fine wavy grain lines.
    ctx.globalAlpha = 0.22;
    for (let i = 0; i < 160; i++) {
        const x = rng() * w;
        const amplitude = 2 + rng() * 6;
        const phase = rng() * Math.PI * 2;
        ctx.strokeStyle = `#${shade(base, -0.25 - rng() * 0.2).getHexString()}`;
        ctx.lineWidth = 0.6 + rng() * 1.4;
        ctx.beginPath();
        for (let y = 0; y <= h; y += 16) {
            const px = x + Math.sin(y / 140 + phase) * amplitude;
            if (y === 0) {
                ctx.moveTo(px, y);
            } else {
                ctx.lineTo(px, y);
            }
        }
        ctx.stroke();
    }

    // A few knots.
    ctx.globalAlpha = 0.3;
    for (let i = 0; i < 3; i++) {
        const cx = rng() * w;
        const cy = rng() * h;
        for (let r = 14; r > 2; r -= 3) {
            ctx.strokeStyle = `#${shade(base, -0.35).getHexString()}`;
            ctx.beginPath();
            ctx.ellipse(cx, cy, r * 0.6, r * 1.8, 0, 0, Math.PI * 2);
            ctx.stroke();
        }
    }
    ctx.globalAlpha = 1;
}

/** 1200 × 200 mm laminate planks in a staggered bond; the canvas covers 2.4 × 2.4 m. */
function drawPlanks(ctx: CanvasRenderingContext2D, size: number, color: string, rng: () => number): void {
    const base = new THREE.Color(color);
    const rows = 12;
    const rowHeight = size / rows;
    const plankLength = size / 2;

    for (let row = 0; row < rows; row++) {
        const offset = (rng() * 0.6 + 0.2) * plankLength;
        for (let x = -offset; x < size; x += plankLength) {
            const tone = shade(base, (rng() - 0.5) * 0.16);
            ctx.save();
            ctx.beginPath();
            ctx.rect(x, row * rowHeight, plankLength, rowHeight);
            ctx.clip();
            ctx.translate(x + plankLength, row * rowHeight);
            ctx.rotate(Math.PI / 2);
            drawWood(ctx, rowHeight, plankLength, `#${tone.getHexString()}`, rng);
            ctx.restore();

            ctx.strokeStyle = `#${shade(base, -0.45).getHexString()}`;
            ctx.globalAlpha = 0.5;
            ctx.lineWidth = 2;
            ctx.strokeRect(x, row * rowHeight, plankLength, rowHeight);
            ctx.globalAlpha = 1;
        }
    }
}

/** 600 × 600 mm tiles with 3 mm grout; the canvas covers 1.2 × 1.2 m. */
function drawTiles(ctx: CanvasRenderingContext2D, size: number, color: string, rng: () => number): void {
    const base = new THREE.Color(color);
    const tiles = 2;
    const tile = size / tiles;
    const grout = 3;

    ctx.fillStyle = `#${shade(base, -0.18).getHexString()}`;
    ctx.fillRect(0, 0, size, size);
    for (let i = 0; i < tiles; i++) {
        for (let j = 0; j < tiles; j++) {
            ctx.fillStyle = `#${shade(base, (rng() - 0.5) * 0.05).getHexString()}`;
            ctx.fillRect(i * tile + grout, j * tile + grout, tile - 2 * grout, tile - 2 * grout);
            // Subtle stone-like speckle.
            for (let k = 0; k < 600; k++) {
                ctx.fillStyle = `#${shade(base, (rng() - 0.5) * 0.12).getHexString()}`;
                ctx.globalAlpha = 0.25;
                ctx.fillRect(i * tile + grout + rng() * (tile - 2 * grout), j * tile + grout + rng() * (tile - 2 * grout), 2, 2);
            }
            ctx.globalAlpha = 1;
        }
    }
}

function shade(color: THREE.Color, amount: number): THREE.Color {
    const result = color.clone();
    return amount >= 0 ? result.lerp(new THREE.Color('#ffffff'), amount) : result.lerp(new THREE.Color('#000000'), -amount);
}

/** Deterministic PRNG (mulberry32) so textures look the same on every load. */
function seededRandom(seed: number): () => number {
    let a = seed >>> 0;
    return (): number => {
        a = (a + 0x6d2b79f5) >>> 0;
        let t = a;
        t = Math.imul(t ^ (t >>> 15), t | 1);
        t ^= t + Math.imul(t ^ (t >>> 7), t | 61);
        return ((t ^ (t >>> 14)) >>> 0) / 4294967296;
    };
}
