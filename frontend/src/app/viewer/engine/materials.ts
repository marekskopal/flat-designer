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
 * Builds three.js materials from the backend material catalogue. Wood, plank and tile surfaces get
 * procedural canvas textures, so no image assets are needed. Geometry is expected to carry UVs in
 * metres (see `worldUvBox`), textures repeat at their real-world size.
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

        const canvas = drawPattern(dto);
        texture = new THREE.CanvasTexture(canvas);
        texture.colorSpace = THREE.SRGBColorSpace;
        texture.wrapS = THREE.RepeatWrapping;
        texture.wrapT = THREE.RepeatWrapping;
        texture.anisotropy = this.maxAnisotropy;
        const repeat = 1 / (PatternTileSize[dto.pattern] ?? 1);
        texture.repeat.set(repeat, repeat);
        if (grain === 'horizontal') {
            texture.rotation = Math.PI / 2;
        }
        this.textures.set(key, texture);
        return texture;
    }

    private fallback(): THREE.Material {
        return this.plain('#d8d4cc', 0.9);
    }
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
