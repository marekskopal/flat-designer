import {Material as MaterialDto} from '@app/models/scene';
import * as THREE from 'three';

/** World size (m) one texture tile covers, per procedural pattern. */
const PatternTileSize: Record<string, number> = {
    Wood: 1.2,
    Planks: 2.4,
    Tiles: 1.2,
    Grass: 2.0,
    Gravel: 1.0,
};

/** Fill of solid cores revealed by the section plane. */
const SectionColor = '#3d3a36';

export type GrainDirection = 'vertical' | 'horizontal';

/** How a material's pattern lies: a grain direction, or planks along an upright face (`MaterialLibrary.upright()`). */
type Lay = GrainDirection | 'upright';

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
    /** Materials of laid tiles, whose UVs `alignTiles` puts on the room's tile grid. */
    private readonly tiles = new WeakSet<THREE.Material>();

    /** A texture image has arrived: a viewer rendering on demand draws again. */
    public onTextureLoaded: () => void = () => undefined;

    public constructor(materials: readonly MaterialDto[], private readonly maxAnisotropy: number) {
        for (const material of materials) {
            this.byId.set(material.id, material);
            this.idByCode.set(material.code, material.id);
        }
    }

    public get(id: number | null, grain: GrainDirection = 'vertical'): THREE.Material {
        const dto = id === null ? undefined : this.byId.get(id);
        return dto === undefined ? this.fallback() : this.material(dto, grain);
    }

    /**
     * The material of an upright face (a wall, its finish bands, a façade, a reveal, a void's lining): planks run along
     * it, as cladding and board-marked concrete do; other patterns as `get()` lays them. Planks ignore the grain
     * direction otherwise (they run along v), so a stair tread or a cabinet keeps its planks as before.
     */
    public upright(id: number | null): THREE.Material {
        const dto = id === null ? undefined : this.byId.get(id);
        return dto === undefined ? this.fallback() : this.material(dto, dto.pattern === 'Planks' ? 'upright' : 'vertical');
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

    /**
     * The material darkened to `factor` of its brightness (a plinth in the shadow under the fronts): a copy keeping its
     * texture, so a wood stays wood. Cached; the copies are the library's and go with it.
     */
    public darkened(material: THREE.Material, factor: number): THREE.Material {
        if (!(material instanceof THREE.MeshStandardMaterial)) {
            return material;
        }
        const key = `dark:${material.uuid}:${factor}`;
        let dark = this.cache.get(key);
        if (dark === undefined) {
            dark = material.clone();
            (dark as THREE.MeshStandardMaterial).color.multiplyScalar(factor);
            this.cache.set(key, dark);
        }
        return dark;
    }

    public isTile(material: THREE.Material): boolean {
        return this.tiles.has(material);
    }

    /**
     * Flat dark fill of solid cores cut by the section plane: drawn on the back faces of a wall piece's (or column's)
     * box, it is hidden until the plane slices the box open.
     */
    public sectionFill(): THREE.Material {
        let material = this.cache.get('section');
        if (material === undefined) {
            material = new THREE.MeshBasicMaterial({color: SectionColor, side: THREE.BackSide});
            this.cache.set('section', material);
        }
        return material;
    }

    /** Whether the material is one of the library's (shared, disposed with the library rather than with a mesh). */
    public owns(material: THREE.Material): boolean {
        for (const cached of this.cache.values()) {
            if (cached === material) {
                return true;
            }
        }
        return false;
    }

    public dispose(): void {
        this.cache.forEach((m) => m.dispose());
        this.textures.forEach((t) => t.dispose());
        this.cache.clear();
        this.textures.clear();
    }

    private material(dto: MaterialDto, lay: Lay): THREE.Material {
        const key = `${dto.id}:${lay}`;
        let material = this.cache.get(key);
        if (material === undefined) {
            material = this.create(dto, lay);
            this.cache.set(key, material);
        }
        return material;
    }

    private create(dto: MaterialDto, lay: Lay): THREE.Material {
        if (dto.pattern === 'Glass') {
            return this.glass(dto);
        }

        const material = new THREE.MeshStandardMaterial({
            color: dto.pattern === 'Plain' ? dto.color : '#ffffff',
            roughness: dto.roughness,
            metalness: dto.metalness,
        });

        if (dto.pattern !== 'Plain') {
            material.map = this.texture(dto, lay);
        }
        // Laid tiles and slabs go on the room's tile grid (`alignTiles`); bricks keep the UVs of their surface.
        if (GridPatterns.includes(dto.pattern) && tileLayout(dto) !== null) {
            this.tiles.add(material);
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

    private texture(dto: MaterialDto, lay: Lay): THREE.Texture {
        const key = `${dto.id}:${lay}`;
        let texture = this.textures.get(key);
        if (texture !== undefined) {
            return texture;
        }

        const layout = tileLayout(dto);
        const woodPhoto = dto.pattern === 'Wood' && dto.texture !== null && dto.tileWidth !== null && dto.tileHeight !== null;
        if (woodPhoto) {
            texture = this.woodTexture(dto, lay === 'horizontal' ? 'horizontal' : 'vertical');
        } else if (layout !== null && dto.texture !== null) {
            texture = this.imageTexture(dto, layout);
        } else if (layout !== null) {
            texture = proceduralLayoutTexture(dto, layout);
            if (dto.pattern === 'Herringbone') {
                // Drawn with the tiles along the axes; the wall shows them at 45°.
                texture.rotation = Math.PI / 4;
            }
        } else {
            texture = new THREE.CanvasTexture(drawPattern(dto));
            const repeat = 1 / (PatternTileSize[dto.pattern] ?? 1);
            texture.repeat.set(repeat, repeat);
            // Procedural planks are drawn along the canvas width; turn them to run along v like the photo-based ones, unless
            // they run along a wall.
            if (dto.pattern === 'Planks' ? lay !== 'upright' : lay === 'horizontal') {
                texture.rotation = Math.PI / 2;
            }
        }
        if (layout !== null && dto.pattern === 'Planks' && lay === 'upright') {
            // Planks of a size run along v; boards on a wall run along it (`upright()`).
            texture.rotation = Math.PI / 2;
        }

        texture.colorSpace = THREE.SRGBColorSpace;
        // A photo of solid wood does not tile seamlessly; mirrored repeats hide the seams.
        const wrapping = woodPhoto ? THREE.MirroredRepeatWrapping : THREE.RepeatWrapping;
        texture.wrapS = wrapping;
        texture.wrapT = wrapping;
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
        canvas.width = Math.round(layout.width / (layout.mmPerPixel ?? LayoutMmPerPixel));
        canvas.height = Math.round(layout.height / (layout.mmPerPixel ?? LayoutMmPerPixel));
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
                this.onTextureLoaded();
            }
        };
        image.src = new URL(`assets/${dto.texture}`, document.baseURI).href;
        return texture;
    }

    /**
     * Solid wood from the photo of a piece of one board, grain along the image height: `tileWidth` × `tileHeight`
     * mm across × along the grain. Horizontal grain turns the image a quarter (the repeat applies after the turn).
     */
    private woodTexture(dto: MaterialDto, grain: GrainDirection): THREE.Texture {
        const texture = new THREE.TextureLoader().load(new URL(`assets/${dto.texture}`, document.baseURI).href, () => this.onTextureLoaded());
        texture.repeat.set(1000 / (dto.tileWidth ?? 1000), 1000 / (dto.tileHeight ?? 1000));
        if (grain === 'horizontal') {
            texture.rotation = Math.PI / 2;
        }
        return texture;
    }

    private fallback(): THREE.Material {
        return this.plain('#d8d4cc', 0.9);
    }
}

/** Resolution of composed plank/tile canvases. */
const LayoutMmPerPixel = 2;

/** Patterns laid in pieces on the room's tile grid (`alignTiles`); the others keep the UVs of their surface. */
const GridPatterns: readonly MaterialDto['pattern'][] = ['Tiles', 'Terrazzo', 'Herringbone', 'Marble', 'Chevron', 'Zellige', 'Encaustic'];

/** Brick-bond materials smoother than this are glazed tiles (metro), with a thin light grout. */
const GlazedRoughness = 0.3;

/** Longest side (px) of a pattern's canvas; a larger patch is drawn at a coarser resolution. */
const MaxCanvasSide = 2048;

/** Resolution (mm per px) for a patch of `width` × `height` mm: `fine`, or coarser where the canvas would exceed `MaxCanvasSide`. */
function patchResolution(width: number, height: number, fine: number): number {
    return Math.max(fine, Math.max(width, height) / MaxCanvasSide);
}

interface ImageLayout {
    /** Physical size (mm) of the patch the canvas represents; it tiles seamlessly. */
    width: number;
    height: number;
    /** Draws the layout; `image` is the photo of one piece, or null to draw the pieces from the base colour. */
    draw(ctx: CanvasRenderingContext2D, image: HTMLImageElement | null, rng: () => number): void;
    /** Canvas resolution, when finer than `LayoutMmPerPixel` (small chips, narrow joints). */
    mmPerPixel?: number;
}

/** Layout of planks or tiles of a known size; planks need the photo, tiles can be drawn without it. */
function tileLayout(dto: MaterialDto): ImageLayout | null {
    if (dto.tileWidth === null || dto.tileHeight === null) {
        return null;
    }
    const tileWidth = dto.tileWidth;
    const tileHeight = dto.tileHeight;

    if (dto.pattern === 'Planks' && dto.texture !== null) {
        // Planks run along the texture v axis (plan y on floors) in a random staggered bond. The patch
        // is large enough (≈ 3.2 × 3.7 m) that its repetition does not read in a room.
        const columns = 12;
        const rows = 3;
        return {
            width: columns * tileWidth,
            height: rows * tileHeight,
            draw: (ctx, image, rng): void => {
                if (image !== null) {
                    drawPlankLayout(ctx, image, rng, columns, rows, tileWidth, tileHeight);
                }
            },
        };
    }
    if (dto.pattern === 'Planks') {
        // Without a photo the planks are drawn at their size: columns at least 2.4 m across, at least 3.6 m long.
        const columns = Math.max(8, Math.ceil(2400 / tileWidth));
        const rows = Math.max(1, Math.ceil(3600 / tileHeight));
        return {
            width: columns * tileWidth,
            height: rows * tileHeight,
            draw: (ctx, _image, rng): void => drawSizedPlanks(ctx, rng, columns, rows, tileWidth, tileHeight, dto.color),
        };
    }
    if (dto.pattern === 'Stone') {
        // Random coursed walling, 6 stones across and 8 courses up.
        return {
            width: 6 * tileWidth,
            height: 8 * tileHeight,
            draw: (ctx, _image, rng): void => drawStones(ctx, rng, tileWidth, tileHeight, dto.color),
        };
    }
    if (dto.pattern === 'Formwork') {
        // 2 pieces along the wall, at least 1.2 m of rows up it.
        const rows = Math.max(2, Math.ceil(1200 / tileHeight));
        return {
            width: 2 * tileWidth,
            height: rows * tileHeight,
            draw: (ctx, _image, rng): void => drawFormwork(ctx, rng, tileWidth, tileHeight, dto.color),
        };
    }
    if (dto.pattern === 'Terrazzo') {
        // 2 × 2 slabs: chips are scattered across the patch and cut by the faint joints like real slabs.
        return {
            width: 2 * tileWidth,
            height: 2 * tileHeight,
            mmPerPixel: 1,
            draw: (ctx, _image, rng): void => drawTerrazzo(ctx, rng, tileWidth, tileHeight, dto.color),
        };
    }
    if (dto.pattern === 'Brick') {
        // Stretcher bond, 4 bricks across and 8 courses up, so the colour variation does not repeat too soon.
        return {
            width: 4 * tileWidth,
            height: 8 * tileHeight,
            draw: (ctx, _image, rng): void => drawBricks(ctx, rng, tileWidth, tileHeight, dto.color, dto.roughness < GlazedRoughness),
        };
    }
    if (dto.pattern === 'BeaverTail') {
        // Half-bond rows a gauge apart, 4 tiles across and 6 rows up (an even count keeps the bond seamless).
        const gauge = tileHeight * BeaverTailGauge;
        return {
            width: 4 * tileWidth,
            height: 6 * gauge,
            draw: (ctx, _image, rng): void => drawBeaverTails(ctx, rng, tileWidth, gauge, dto.color),
        };
    }
    if (dto.pattern === 'Herringbone') {
        const length = herringboneLength(tileWidth, tileHeight);
        return {
            width: 2 * length,
            height: 2 * length,
            mmPerPixel: 1,
            draw: (ctx, _image, rng): void => drawHerringbone(ctx, rng, length, tileHeight, dto.color),
        };
    }
    if (dto.pattern === 'Marble') {
        // A slab of 1.5 m or more is a patch of its own; smaller tiles 2 × 2, each cut from its own slab.
        const count = Math.max(tileWidth, tileHeight) >= 1500 ? 1 : 2;
        const width = count * tileWidth;
        const height = count * tileHeight;
        const mmPerPixel = patchResolution(width, height, LayoutMmPerPixel);
        return {
            width,
            height,
            mmPerPixel,
            draw: (ctx, _image, rng): void => drawMarble(ctx, rng, tileWidth / mmPerPixel, tileHeight / mmPerPixel, dto.color, accentOf(dto)),
        };
    }
    if (dto.pattern === 'Chevron') {
        // Two halves of the zigzag across, at least 1.2 m of planks up, so the shades do not repeat too soon.
        const rows = Math.max(6, Math.ceil(1200 / (tileHeight * Math.SQRT2)));
        const width = 2 * tileWidth * Math.SQRT1_2;
        const height = rows * tileHeight * Math.SQRT2;
        const mmPerPixel = patchResolution(width, height, tileHeight < 120 ? 1 : LayoutMmPerPixel);
        return {
            width,
            height,
            mmPerPixel,
            draw: (ctx, _image, rng): void => drawChevron(ctx, rng, tileWidth / mmPerPixel, tileHeight / mmPerPixel, rows, dto.color),
        };
    }
    if (dto.pattern === 'Zellige') {
        // 6 × 6 tiles, so the shades do not repeat too soon.
        const mmPerPixel = patchResolution(6 * tileWidth, 6 * tileHeight, Math.min(tileWidth, tileHeight) <= 150 ? 1 : LayoutMmPerPixel);
        return {
            width: 6 * tileWidth,
            height: 6 * tileHeight,
            mmPerPixel,
            draw: (ctx, _image, rng): void => drawZellige(ctx, rng, tileWidth / mmPerPixel, tileHeight / mmPerPixel, dto.color),
        };
    }
    if (dto.pattern === 'Encaustic') {
        const mmPerPixel = patchResolution(2 * tileWidth, 2 * tileHeight, 1);
        return {
            width: 2 * tileWidth,
            height: 2 * tileHeight,
            mmPerPixel,
            draw: (ctx, _image, rng): void => drawEncaustic(ctx, rng, tileWidth / mmPerPixel, tileHeight / mmPerPixel, dto.color, accentOf(dto)),
        };
    }
    if (dto.pattern === 'Fluted') {
        // 8 flutes across; they run along the canvas height, the same all the way.
        const mmPerPixel = patchResolution(8 * tileWidth, 8 * tileWidth, 1);
        return {
            width: 8 * tileWidth,
            height: 8 * tileWidth,
            mmPerPixel,
            draw: (ctx): void => drawFluted(ctx, tileWidth / mmPerPixel, dto.color),
        };
    }
    if (dto.pattern === 'Tiles') {
        return {
            width: 2 * tileWidth,
            height: 2 * tileHeight,
            draw: (ctx, image, rng): void => drawTileLayout(ctx, image, rng, tileWidth, tileHeight, dto.color),
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

/** 2 × 2 tiles from the photo (randomly mirrored) or from the base colour, with light grout joints. */
function drawTileLayout(
    ctx: CanvasRenderingContext2D,
    image: HTMLImageElement | null,
    rng: () => number,
    tileWidth: number,
    tileHeight: number,
    color: string,
): void {
    const w = tileWidth / LayoutMmPerPixel;
    const h = tileHeight / LayoutMmPerPixel;
    for (let i = 0; i < 2; i++) {
        for (let j = 0; j < 2; j++) {
            const variant = {flipX: rng() < 0.5, flipY: rng() < 0.5, tone: (rng() - 0.5) * 0.06};
            if (image !== null) {
                drawPiece(ctx, image, i * w, j * h, w, h, variant);
            } else {
                drawSpeckled(ctx, i * w, j * h, w, h, shade(new THREE.Color(color), variant.tone), rng, 0.08);
            }
        }
    }

    // Light grout between ceramic tiles; outdoor paving has darker, wider joints.
    const grout = Math.max(1, Math.round((image !== null ? 2 : 5) / LayoutMmPerPixel));
    ctx.fillStyle = image !== null ? '#d9d4cb' : `#${shade(new THREE.Color(color), -0.3).getHexString()}`;
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

function proceduralLayoutTexture(dto: MaterialDto, layout: ImageLayout): THREE.CanvasTexture {
    const canvas = document.createElement('canvas');
    canvas.width = Math.round(layout.width / (layout.mmPerPixel ?? LayoutMmPerPixel));
    canvas.height = Math.round(layout.height / (layout.mmPerPixel ?? LayoutMmPerPixel));
    const ctx = canvas.getContext('2d');
    if (ctx !== null) {
        layout.draw(ctx, null, seededRandom(dto.id * 7919));
    }
    const texture = new THREE.CanvasTexture(canvas);
    texture.repeat.set(1000 / layout.width, 1000 / layout.height);
    return texture;
}

/**
 * Planks of a known size without a photo: `columns` columns along the canvas height (texture v), each a random
 * staggered run of planks `rows` long, each plank its own shade of the wood, with dark joints along and across. The
 * plank cut by the bottom edge reappears at the top, so the patch tiles seamlessly.
 */
function drawSizedPlanks(
    ctx: CanvasRenderingContext2D,
    rng: () => number,
    columns: number,
    rows: number,
    plankWidth: number,
    plankLength: number,
    color: string,
): void {
    const w = plankWidth / LayoutMmPerPixel;
    const l = plankLength / LayoutMmPerPixel;
    const base = new THREE.Color(color);
    const joint = `#${shade(base, -0.5).getHexString()}`;
    for (let column = 0; column < columns; column++) {
        const offset = rng() * l;
        // Each plank drawn from its own seed, so the one crossing the patch's edge is drawn alike at both ends.
        const planks = Array.from({length: rows}, () => ({seed: Math.floor(rng() * 2 ** 31), tone: shade(base, (rng() - 0.5) * 0.16)}));
        for (let k = -1; k < rows; k++) {
            const y = offset + k * l;
            const plank = planks[(k + rows) % rows];
            ctx.save();
            ctx.beginPath();
            ctx.rect(column * w, y, w, l);
            ctx.clip();
            ctx.translate(column * w, y);
            drawWood(ctx, w, l, `#${plank.tone.getHexString()}`, seededRandom(plank.seed), w / 512);
            ctx.restore();
            ctx.fillStyle = joint;
            ctx.globalAlpha = 0.6;
            ctx.fillRect(column * w, y, w, 1);
            ctx.globalAlpha = 1;
        }
        ctx.fillStyle = joint;
        ctx.globalAlpha = 0.6;
        ctx.fillRect(column * w, 0, 1, ctx.canvas.height);
        ctx.globalAlpha = 1;
    }
}

/**
 * Dry-stone walling, random coursed: courses of 0.6–1.4 × `courseHeight`, filling the patch's height, of flat
 * stones 0.5–1.6 × `stoneLength` long, filling its width, with deep dark joints. Each stone is an irregular polygon in
 * its own shade with a lit top edge and a shaded bottom one; stones crossing the patch's side reappear at the other.
 */
function drawStones(ctx: CanvasRenderingContext2D, rng: () => number, stoneLength: number, courseHeight: number, color: string): void {
    const width = ctx.canvas.width;
    const height = ctx.canvas.height;
    const base = new THREE.Color(color);
    ctx.fillStyle = `#${shade(base, -0.62).getHexString()}`;
    ctx.fillRect(0, 0, width, height);
    const fit = (sizes: number[], total: number): number[] => {
        const sum = sizes.reduce((a, b) => a + b, 0);
        return sizes.map((size) => (size * total) / sum);
    };
    const courses: number[] = [];
    while (courses.reduce((a, b) => a + b, 0) < height) {
        courses.push(((0.6 + rng() * 0.8) * courseHeight) / LayoutMmPerPixel);
    }
    const joint = 18 / LayoutMmPerPixel;
    let y = 0;
    for (const course of fit(courses, height)) {
        const lengths: number[] = [];
        while (lengths.reduce((a, b) => a + b, 0) < width) {
            lengths.push(((0.5 + rng() * 1.1) * stoneLength) / LayoutMmPerPixel);
        }
        let x = rng() * (stoneLength / LayoutMmPerPixel);
        for (const length of fit(lengths, width)) {
            // Mostly darker than the base: lightening mixes in linear colour, so a little goes a long way.
            const tone = shade(base, (rng() - 0.75) * 0.32);
            const specks = [-0.15, -0.05, 0.05, 0.12].map((amount) => `#${vary(tone, amount).getHexString()}`);
            const jitter = (): number => (rng() - 0.5) * joint;
            const [left, right, top, bottom] = [x + joint / 2, x + length - joint / 2, y + joint / 2, y + course - joint / 2];
            const corners: [number, number][] = [
                [left + jitter(), top + jitter()],
                [left + (right - left) * (0.3 + rng() * 0.4), top + jitter() * 0.5],
                [right + jitter(), top + jitter()],
                [right + jitter() * 0.5, top + (bottom - top) * (0.4 + rng() * 0.2)],
                [right + jitter(), bottom + jitter()],
                [left + (right - left) * (0.3 + rng() * 0.4), bottom + jitter() * 0.5],
                [left + jitter(), bottom + jitter()],
                [left + jitter() * 0.5, top + (bottom - top) * (0.4 + rng() * 0.2)],
            ];
            for (const shift of x + length > width ? [0, -width] : [0]) {
                const outline = (): void => {
                    ctx.beginPath();
                    corners.forEach(([cx, cy], i) => (i === 0 ? ctx.moveTo(cx + shift, cy) : ctx.lineTo(cx + shift, cy)));
                    ctx.closePath();
                };
                outline();
                ctx.fillStyle = `#${tone.getHexString()}`;
                ctx.fill();
                ctx.save();
                ctx.clip();
                // The bed's lit top and shaded underside, and a little grain.
                ctx.fillStyle = `#${shade(tone, 0.18).getHexString()}`;
                ctx.globalAlpha = 0.5;
                ctx.fillRect(left + shift - joint, top - joint, right - left + 2 * joint, 3);
                ctx.fillStyle = `#${shade(tone, -0.35).getHexString()}`;
                ctx.fillRect(left + shift - joint, bottom - 4, right - left + 2 * joint, 4 + joint);
                for (const speck of specks) {
                    ctx.fillStyle = speck;
                    for (let k = 0; k < ((right - left) * (bottom - top)) / 200; k++) {
                        ctx.fillRect(left + shift + rng() * (right - left), top + rng() * (bottom - top), 2, 1);
                    }
                }
                ctx.globalAlpha = 1;
                ctx.restore();
            }
            x += length;
        }
        y += course;
    }
}

/**
 * Polished terrazzo: a light base with stone chips of 1–14 mm in greys, black, white and a little ochre, and faint
 * joints between 2 × 2 slabs. Drawn at 1 mm per pixel; chips crossing the patch edge wrap round, so it tiles.
 */
function drawTerrazzo(ctx: CanvasRenderingContext2D, rng: () => number, slabWidth: number, slabHeight: number, color: string): void {
    const width = ctx.canvas.width;
    const height = ctx.canvas.height;
    const base = new THREE.Color(color);
    ctx.fillStyle = `#${base.getHexString()}`;
    ctx.fillRect(0, 0, width, height);
    // Cloudy variation of the binder.
    for (let i = 0; i < 40; i++) {
        ctx.globalAlpha = 0.06;
        ctx.fillStyle = `#${shade(base, (rng() - 0.5) * 0.12).getHexString()}`;
        ctx.beginPath();
        ctx.arc(rng() * width, rng() * height, 60 + rng() * 220, 0, Math.PI * 2);
        ctx.fill();
    }
    ctx.globalAlpha = 1;
    const palette = ['#2b2b2c', '#4a4a4b', '#77777a', '#9c9a96', '#bdbab3', '#f6f4ef', '#fbfaf7', '#b48a5c', '#8c8580'];
    const chips = Math.round((width * height) / 70);
    for (let i = 0; i < chips; i++) {
        // Mostly small grains, some larger chips.
        const size = rng() < 0.85 ? 1 + rng() * 3 : 4 + rng() * 10;
        const x = rng() * width;
        const y = rng() * height;
        const angle = rng() * Math.PI;
        const squash = 0.5 + rng() * 0.5;
        ctx.fillStyle = palette[Math.floor(rng() * palette.length)];
        // A chip crossing the right or top edge reappears at the left or bottom, so the patch tiles.
        const shiftsX = x + size > width ? [0, -width] : [0];
        const shiftsY = y + size > height ? [0, -height] : [0];
        for (const dx of shiftsX) {
            for (const dy of shiftsY) {
                ctx.beginPath();
                ctx.ellipse(x + dx, y + dy, size / 2, (size / 2) * squash, angle, 0, Math.PI * 2);
                ctx.fill();
            }
        }
    }
    ctx.fillStyle = 'rgba(80, 76, 70, 0.35)';
    for (let i = 0; i < 2; i++) {
        ctx.fillRect(i * slabWidth, 0, 1, height);
        ctx.fillRect(0, i * slabHeight, width, 1);
    }
}

/**
 * Bricks in stretcher bond with recessed joints, each brick its own shade (`vary()`, so a red stays red when lightened)
 * and a little speckle. The joint is 7 % of the course, 5–10 mm: mortar for bricks, sand for pavers; glazed tiles in
 * the same bond (metro tiles) get a 2 mm light grey grout.
 */
function drawBricks(
    ctx: CanvasRenderingContext2D,
    rng: () => number,
    brickLength: number,
    brickHeight: number,
    color: string,
    glazed: boolean,
): void {
    const width = ctx.canvas.width;
    const height = ctx.canvas.height;
    const length = brickLength / LayoutMmPerPixel;
    const course = brickHeight / LayoutMmPerPixel;
    const jointMm = glazed ? 2 : Math.min(10, Math.max(5, brickHeight * 0.07));
    const joint = Math.max(1, Math.round(jointMm / LayoutMmPerPixel));
    const base = new THREE.Color(color);
    ctx.fillStyle = glazed ? '#d6d3cc' : '#c9bba5';
    ctx.fillRect(0, 0, width, height);
    const courses = Math.round(height / course);
    const bricks = Math.round(width / length);
    for (let row = 0; row < courses; row++) {
        const offset = row % 2 === 0 ? 0 : length / 2;
        for (let i = -1; i < bricks; i++) {
            const x = i * length + offset;
            // Some bricks fired darker.
            const tone = vary(base, (rng() - 0.5) * 0.1 - (rng() < 0.15 ? 0.04 : 0));
            ctx.fillStyle = `#${tone.getHexString()}`;
            ctx.fillRect(x + joint / 2, row * course + joint / 2, length - joint, course - joint);
            ctx.globalAlpha = 0.5;
            for (let k = 0; k < 40; k++) {
                ctx.fillStyle = `#${vary(tone, (rng() - 0.5) * 0.12).getHexString()}`;
                ctx.fillRect(x + joint / 2 + rng() * (length - joint), row * course + joint / 2 + rng() * (course - joint), 2, 2);
            }
            ctx.globalAlpha = 1;
        }
    }
}

/**
 * Cast concrete showing its formwork: panels or boards `pieceLength` along the wall (canvas x, texture u) by
 * `pieceHeight` up it, in rows from the bottom; each piece a slightly different grey with a fine speckle, a thin joint
 * round it, tie holes on a panel (taller than 300 mm) and a faint grain imprint along a board.
 */
function drawFormwork(ctx: CanvasRenderingContext2D, rng: () => number, pieceLength: number, pieceHeight: number, color: string): void {
    const width = ctx.canvas.width;
    const height = ctx.canvas.height;
    const length = pieceLength / LayoutMmPerPixel;
    const rise = pieceHeight / LayoutMmPerPixel;
    const base = new THREE.Color(color);
    const dark = `#${vary(base, -0.25).getHexString()}`;
    for (let row = 0; row * rise < height - 0.5; row++) {
        for (let column = 0; column * length < width - 0.5; column++) {
            const [x, y] = [column * length, row * rise];
            drawSpeckled(ctx, x, y, length, rise, vary(base, (rng() - 0.5) * 0.06), rng, 0.05);
            ctx.fillStyle = dark;
            if (pieceHeight > 300) {
                // Tie holes, three along and two up.
                for (const [i, j] of [[1, 1], [3, 1], [5, 1], [1, 3], [3, 3], [5, 3]]) {
                    ctx.beginPath();
                    ctx.arc(x + (length * i) / 6, y + (rise * j) / 4, 12 / LayoutMmPerPixel, 0, Math.PI * 2);
                    ctx.fill();
                }
            } else {
                ctx.globalAlpha = 0.12;
                for (let k = 0; k < 5; k++) {
                    ctx.fillRect(x, y + rng() * rise, length, 1);
                }
            }
            ctx.globalAlpha = 0.5;
            ctx.fillRect(x, y, length, 1);
            ctx.fillRect(x, y, 1, rise);
            ctx.globalAlpha = 1;
        }
    }
}

/** A pattern's second colour: the material's `accent`, else a shade well apart from its base (darker on a light one). */
function accentOf(dto: MaterialDto): string {
    if (dto.accent != null) {
        return dto.accent;
    }
    const base = new THREE.Color(dto.color);
    return `#${vary(base, lightness(base) > 0.35 ? -0.4 : 0.45).getHexString()}`;
}

/** sRGB lightness (0–1) of a colour, as the eye judges a #rrggbb; `THREE.Color` holds it linear. */
function lightness(color: THREE.Color): number {
    return color.getHSL({h: 0, s: 0, l: 0}, THREE.SRGBColorSpace).l;
}

/**
 * Veined stone: slabs (or tiles) `w` × `h` px, each a cloudy ground with a few wandering main veins in `accent`, each
 * with a soft halo and thin branches, and hairline veins between; the veins of a slab keep one general direction.
 * A slab's veins stay within it (tiles are cut from different slabs), with a faint joint round it.
 */
function drawMarble(ctx: CanvasRenderingContext2D, rng: () => number, w: number, h: number, color: string, accent: string): void {
    const base = new THREE.Color(color);
    const vein = new THREE.Color(accent);
    const columns = Math.round(ctx.canvas.width / w);
    const rows = Math.round(ctx.canvas.height / h);
    const veinPath = (x: number, y: number, angle: number, length: number, step: number, wander: number): [number, number][] => {
        const points: [number, number][] = [[x, y]];
        for (let travelled = 0; travelled < length; travelled += step) {
            angle += (rng() - 0.5) * wander;
            x += Math.cos(angle) * step;
            y += Math.sin(angle) * step;
            points.push([x, y]);
        }
        return points;
    };
    const stroke = (points: [number, number][], width: number, alpha: number, tone: THREE.Color): void => {
        ctx.globalAlpha = alpha;
        ctx.strokeStyle = `#${tone.getHexString()}`;
        ctx.lineWidth = width;
        ctx.lineCap = 'round';
        ctx.lineJoin = 'round';
        ctx.beginPath();
        points.forEach(([px, py], i) => (i === 0 ? ctx.moveTo(px, py) : ctx.lineTo(px, py)));
        ctx.stroke();
    };
    const size = Math.max(w, h);
    for (let column = 0; column < columns; column++) {
        for (let row = 0; row < rows; row++) {
            const [x0, y0] = [column * w, row * h];
            ctx.save();
            ctx.beginPath();
            ctx.rect(x0, y0, w, h);
            ctx.clip();
            ctx.globalAlpha = 1;
            ctx.fillStyle = `#${vary(base, (rng() - 0.5) * 0.03).getHexString()}`;
            ctx.fillRect(x0, y0, w, h);
            // Clouds in the ground.
            for (let i = 0; i < 24; i++) {
                ctx.globalAlpha = 0.07;
                ctx.fillStyle = `#${vary(base, (rng() - 0.5) * 0.08).getHexString()}`;
                ctx.beginPath();
                const [rx, ry] = [size * (0.05 + rng() * 0.2), size * (0.03 + rng() * 0.1)];
                ctx.ellipse(x0 + rng() * w, y0 + rng() * h, rx, ry, rng() * Math.PI, 0, Math.PI * 2);
                ctx.fill();
            }
            const direction = rng() * Math.PI;
            const step = size / 80;
            // Hairlines first, then the main veins with their halo and branches over them.
            for (let i = 0; i < 14; i++) {
                const points = veinPath(x0 + rng() * w, y0 + rng() * h, direction + (rng() - 0.5) * 1.2, size * (0.1 + rng() * 0.3), step, 0.7);
                stroke(points, Math.max(0.6, size / 2000), 0.18, vein);
            }
            const veins = 2 + Math.floor(rng() * 3);
            for (let i = 0; i < veins; i++) {
                // Start outside the slab, so the vein crosses it.
                const angle = direction + (rng() - 0.5) * 0.6;
                const sx = x0 + w / 2 - Math.cos(angle) * size * 0.75 + (rng() - 0.5) * w;
                const sy = y0 + h / 2 - Math.sin(angle) * size * 0.75 + (rng() - 0.5) * h;
                const points = veinPath(sx, sy, angle, size * 1.6, step, 0.45);
                const width = Math.max(1, size / (250 + rng() * 350));
                stroke(points, width * 5, 0.06, vein);
                stroke(points, width, 0.5 + rng() * 0.3, vein);
                for (let b = 0; b < 3; b++) {
                    const [bx, by] = points[Math.floor(rng() * points.length)];
                    const branch = veinPath(bx, by, angle + (rng() < 0.5 ? -1 : 1) * (0.4 + rng() * 0.6), size * (0.08 + rng() * 0.25), step, 0.6);
                    stroke(branch, width * 0.45, 0.35, vein);
                }
            }
            ctx.restore();
        }
    }
    ctx.globalAlpha = 0.18;
    ctx.fillStyle = `#${shade(base, -0.5).getHexString()}`;
    for (let column = 0; column < columns; column++) {
        ctx.fillRect(column * w, 0, 1, ctx.canvas.height);
    }
    for (let row = 0; row < rows; row++) {
        ctx.fillRect(0, row * h, ctx.canvas.width, 1);
    }
    ctx.globalAlpha = 1;
}

/**
 * Chevron parquet: planks `length` × `width` px at 45°, their ends cut along the canvas height, so the two halves meet
 * in a zigzag down the middle and at the sides. Each plank its own shade with a little grain; `rows` planks up each half
 * before the shades repeat, the plank crossing the edge drawn alike at both ends.
 */
function drawChevron(ctx: CanvasRenderingContext2D, rng: () => number, length: number, width: number, rows: number, color: string): void {
    const half = length * Math.SQRT1_2;
    const pitch = width * Math.SQRT2;
    const base = new THREE.Color(color);
    const joint = `#${shade(base, -0.5).getHexString()}`;
    // The patch is rarely a whole number of pixels: stretch it over the rounded canvas, so no unpainted seam repeats.
    ctx.save();
    ctx.scale(ctx.canvas.width / (2 * half), ctx.canvas.height / (rows * pitch));
    const tones = [0, 1].map(() => Array.from({length: rows}, () => ({tone: shade(base, (rng() - 0.5) * 0.16), seed: Math.floor(rng() * 2 ** 31)})));
    const reach = Math.ceil(half / pitch) + 1;
    for (const side of [0, 1]) {
        for (let k = -reach; k <= rows + 1; k++) {
            const plank = tones[side][((k % rows) + rows) % rows];
            const y = k * pitch;
            // The left half rises to the middle, the right half falls from it.
            const corners: [number, number][] =
                side === 0
                    ? [[0, y], [half, y + half], [half, y + half + pitch], [0, y + pitch]]
                    : [[half, y + half], [2 * half, y], [2 * half, y + pitch], [half, y + half + pitch]];
            ctx.save();
            ctx.beginPath();
            corners.forEach(([px, py], i) => (i === 0 ? ctx.moveTo(px, py) : ctx.lineTo(px, py)));
            ctx.closePath();
            ctx.fillStyle = `#${plank.tone.getHexString()}`;
            ctx.fill();
            ctx.clip();
            // Grain along the plank.
            const grain = seededRandom(plank.seed);
            ctx.strokeStyle = `#${shade(plank.tone, -0.25).getHexString()}`;
            ctx.lineWidth = 1;
            for (let g = 0; g < 7; g++) {
                const offset = y + grain() * pitch;
                ctx.globalAlpha = 0.1 + grain() * 0.12;
                ctx.beginPath();
                if (side === 0) {
                    ctx.moveTo(0, offset);
                    ctx.lineTo(half, offset + half);
                } else {
                    ctx.moveTo(half, offset + half);
                    ctx.lineTo(2 * half, offset);
                }
                ctx.stroke();
            }
            ctx.globalAlpha = 0.55;
            ctx.strokeStyle = joint;
            ctx.beginPath();
            corners.forEach(([px, py], i) => (i === 0 ? ctx.moveTo(px, py) : ctx.lineTo(px, py)));
            ctx.closePath();
            ctx.stroke();
            ctx.restore();
        }
    }
    ctx.restore();
    ctx.globalAlpha = 1;
}

/**
 * Zellige: handmade glazed tiles `w` × `h` px with narrow light joints, each its own shade, its
 * corners a little off square, the glaze pooling darker at its edges and catching a gloss near one corner.
 */
function drawZellige(ctx: CanvasRenderingContext2D, rng: () => number, w: number, h: number, color: string): void {
    const base = new THREE.Color(color);
    const columns = Math.round(ctx.canvas.width / w);
    const rows = Math.round(ctx.canvas.height / h);
    // A light cementitious grout, whatever the glaze.
    ctx.fillStyle = '#d8d2c6';
    ctx.fillRect(0, 0, ctx.canvas.width, ctx.canvas.height);
    const joint = Math.max(1, Math.min(w, h) * 0.02);
    const wobble = Math.min(w, h) * 0.02;
    for (let column = 0; column < columns; column++) {
        for (let row = 0; row < rows; row++) {
            const tone = vary(base, (rng() - 0.5) * 0.14);
            const [x0, y0, x1, y1] = [column * w + joint / 2, row * h + joint / 2, (column + 1) * w - joint / 2, (row + 1) * h - joint / 2];
            const jitter = (): number => (rng() - 0.5) * wobble;
            const corners: [number, number][] = [
                [x0 + jitter(), y0 + jitter()],
                [x1 + jitter(), y0 + jitter()],
                [x1 + jitter(), y1 + jitter()],
                [x0 + jitter(), y1 + jitter()],
            ];
            ctx.save();
            ctx.beginPath();
            corners.forEach(([px, py], i) => (i === 0 ? ctx.moveTo(px, py) : ctx.lineTo(px, py)));
            ctx.closePath();
            ctx.fillStyle = `#${tone.getHexString()}`;
            ctx.fill();
            ctx.clip();
            const glaze = ctx.createRadialGradient(x0 + w * 0.35, y0 + h * 0.35, 0, x0 + w / 2, y0 + h / 2, Math.max(w, h) * 0.75);
            glaze.addColorStop(0, `#${vary(tone, 0.04).getHexString()}`);
            glaze.addColorStop(0.6, `#${tone.getHexString()}`);
            glaze.addColorStop(1, `#${vary(tone, -0.1).getHexString()}`);
            ctx.globalAlpha = 0.7;
            ctx.fillStyle = glaze;
            ctx.fillRect(x0 - wobble, y0 - wobble, w + 2 * wobble, h + 2 * wobble);
            ctx.globalAlpha = 1;
            ctx.restore();
        }
    }
}

/**
 * Encaustic cement tiles `w` × `h` px: the ground in its own slight shade, quarter circles at the corners
 * (whole circles where four tiles meet), a diamond with a ring round it in the middle and dots at the edges, all in
 * `accent`, with a thin grey joint.
 */
function drawEncaustic(ctx: CanvasRenderingContext2D, rng: () => number, w: number, h: number, color: string, accent: string): void {
    const base = new THREE.Color(color);
    const motif = new THREE.Color(accent);
    const s = Math.min(w, h);
    for (let column = 0; column < 2; column++) {
        for (let row = 0; row < 2; row++) {
            const [x0, y0] = [column * w, row * h];
            const [cx, cy] = [x0 + w / 2, y0 + h / 2];
            ctx.save();
            ctx.beginPath();
            ctx.rect(x0, y0, w, h);
            ctx.clip();
            ctx.fillStyle = `#${vary(base, (rng() - 0.5) * 0.04).getHexString()}`;
            ctx.fillRect(x0, y0, w, h);
            ctx.fillStyle = `#${vary(motif, (rng() - 0.5) * 0.04).getHexString()}`;
            ctx.strokeStyle = ctx.fillStyle;
            for (const [px, py] of [[x0, y0], [x0 + w, y0], [x0, y0 + h], [x0 + w, y0 + h]]) {
                ctx.beginPath();
                ctx.arc(px, py, s * 0.3, 0, Math.PI * 2);
                ctx.fill();
            }
            ctx.beginPath();
            ctx.moveTo(cx, cy - s * 0.26);
            ctx.lineTo(cx + s * 0.26, cy);
            ctx.lineTo(cx, cy + s * 0.26);
            ctx.lineTo(cx - s * 0.26, cy);
            ctx.closePath();
            ctx.fill();
            ctx.lineWidth = s * 0.03;
            ctx.beginPath();
            ctx.arc(cx, cy, s * 0.36, 0, Math.PI * 2);
            ctx.stroke();
            for (const [px, py] of [[cx, y0], [x0 + w, cy], [cx, y0 + h], [x0, cy]]) {
                ctx.beginPath();
                ctx.arc(px, py, s * 0.06, 0, Math.PI * 2);
                ctx.fill();
            }
            // The ground shows through a little, as on a worn cement tile.
            ctx.globalAlpha = 0.25;
            for (let k = 0; k < (w * h) / 60; k++) {
                ctx.fillStyle = `#${vary(base, (rng() - 0.5) * 0.1).getHexString()}`;
                ctx.fillRect(x0 + rng() * w, y0 + rng() * h, 1, 1);
            }
            ctx.globalAlpha = 1;
            ctx.restore();
        }
    }
    ctx.fillStyle = `#${shade(base, -0.35).getHexString()}`;
    for (let i = 0; i < 2; i++) {
        ctx.fillRect(i * w, 0, 1, ctx.canvas.height);
        ctx.fillRect(0, i * h, ctx.canvas.width, 1);
    }
}

/**
 * Flutes `pitch` px apart across the canvas, running along its height: each a rounded groove lit from the left, a light
 * ridge where two meet.
 */
function drawFluted(ctx: CanvasRenderingContext2D, pitch: number, color: string): void {
    const base = new THREE.Color(color);
    const flutes = Math.round(ctx.canvas.width / pitch);
    for (let i = 0; i < flutes; i++) {
        const x = i * pitch;
        const light = ctx.createLinearGradient(x, 0, x + pitch, 0);
        light.addColorStop(0, `#${vary(base, 0.08).getHexString()}`);
        light.addColorStop(0.12, `#${base.getHexString()}`);
        light.addColorStop(0.55, `#${vary(base, -0.12).getHexString()}`);
        light.addColorStop(0.88, `#${vary(base, -0.03).getHexString()}`);
        light.addColorStop(1, `#${vary(base, 0.1).getHexString()}`);
        ctx.fillStyle = light;
        ctx.fillRect(x, 0, pitch, ctx.canvas.height);
        ctx.globalAlpha = 0.35;
        ctx.fillStyle = `#${vary(base, -0.3).getHexString()}`;
        ctx.fillRect(x, 0, 1, ctx.canvas.height);
        ctx.globalAlpha = 1;
    }
}

/** The share of a beaver-tail tile's length a row shows (double-lap tiling, `Costing::BeaverTailGauge`). */
export const BeaverTailGauge = 0.4;

/**
 * Beaver-tail clay tiles: round-ended tiles `tileWidth` wide in half-bond rows `gauge` apart, the canvas's top
 * up the slope (the roof's UVs run v up it). A row's tails lie over the row below them, so the rows are drawn from the
 * eaves up, one beyond each edge so the patch tiles seamlessly; each tile its own shade with a darker rim.
 */
function drawBeaverTails(ctx: CanvasRenderingContext2D, rng: () => number, tileWidth: number, gauge: number, color: string): void {
    const width = ctx.canvas.width;
    const height = ctx.canvas.height;
    const w = tileWidth / LayoutMmPerPixel;
    const g = gauge / LayoutMmPerPixel;
    const gap = Math.max(1, Math.round(4 / LayoutMmPerPixel));
    const base = new THREE.Color(color);
    const rows = Math.round(height / g);
    const across = Math.round(width / w);
    ctx.fillStyle = `#${shade(base, -0.45).getHexString()}`;
    ctx.fillRect(0, 0, width, height);
    // Each row's colours repeat with it across the patch's edge.
    const tones = Array.from({length: rows}, () => Array.from({length: across}, () => shade(base, (rng() - 0.5) * 0.28)));
    for (let row = rows; row >= -1; row--) {
        const bottom = (row + 1) * g;
        const offset = ((row % 2) + 2) % 2 === 0 ? 0 : w / 2;
        const tone = tones[((row % rows) + rows) % rows];
        for (let i = -1; i <= across; i++) {
            const left = i * w + offset + gap / 2;
            const right = left + w - gap;
            const radius = (right - left) / 2;
            const shoulder = bottom - radius * 0.7;
            const fill = tone[((i % across) + across) % across];
            ctx.beginPath();
            ctx.moveTo(left, bottom - 2.5 * g);
            ctx.lineTo(left, shoulder);
            ctx.ellipse(left + radius, shoulder, radius, radius * 0.7, 0, Math.PI, 0, true);
            ctx.lineTo(right, bottom - 2.5 * g);
            ctx.closePath();
            ctx.fillStyle = `#${fill.getHexString()}`;
            ctx.fill();
            ctx.strokeStyle = `#${shade(fill, -0.3).getHexString()}`;
            ctx.lineWidth = 1;
            ctx.stroke();
        }
    }
}

/** The tile length of a herringbone, rounded to a whole multiple of the width so that the pattern closes. */
export function herringboneLength(length: number, width: number): number {
    return Math.max(2, Math.round(length / width)) * width;
}

/**
 * The tiles of an axis-aligned herringbone over a patch of `2 · length` × `2 · length` (mm), which tiles seamlessly:
 * staircases of a horizontal tile and a vertical one, repeated along (w, w) and (l + w, w − l). Rectangles are
 * x, y, width, height, overlapping the patch edges.
 */
export function herringboneTiles(length: number, width: number): [number, number, number, number][] {
    const size = 2 * length;
    const tiles: [number, number, number, number][] = [];
    const reach = Math.ceil((size + length) / width) + 2;
    for (let a = -reach; a <= reach; a++) {
        for (let b = -4; b <= 4; b++) {
            const x = a * width + b * (length + width);
            const y = a * width + b * (width - length);
            const horizontal: [number, number, number, number] = [x, y, length, width];
            const vertical: [number, number, number, number] = [x + length, y + width - length, width, length];
            for (const tile of [horizontal, vertical]) {
                if (tile[0] < size && tile[0] + tile[2] > 0 && tile[1] < size && tile[1] + tile[3] > 0) {
                    tiles.push(tile);
                }
            }
        }
    }
    return tiles;
}

/** Glazed tiles in a herringbone, each a slightly different shade, with light grout. Drawn at 1 mm per pixel. */
function drawHerringbone(ctx: CanvasRenderingContext2D, rng: () => number, length: number, width: number, color: string): void {
    const base = new THREE.Color(color);
    ctx.fillStyle = '#8f8c86';
    ctx.fillRect(0, 0, ctx.canvas.width, ctx.canvas.height);
    const grout = 1.5;
    for (const [x, y, w, h] of herringboneTiles(length, width)) {
        const tone = shade(base, (rng() - 0.5) * 0.12);
        ctx.fillStyle = `#${tone.getHexString()}`;
        ctx.fillRect(x + grout / 2, y + grout / 2, w - grout, h - grout);
        // A soft glaze highlight along one edge.
        ctx.fillStyle = 'rgba(255, 255, 255, 0.05)';
        ctx.fillRect(x + grout / 2, y + grout / 2, w > h ? w - grout : 3, w > h ? 3 : h - grout);
    }
}

/** Base colour with fine random speckle (concrete, stone). */
function drawSpeckled(
    ctx: CanvasRenderingContext2D,
    x: number,
    y: number,
    w: number,
    h: number,
    base: THREE.Color,
    rng: () => number,
    contrast: number,
): void {
    ctx.fillStyle = `#${base.getHexString()}`;
    ctx.fillRect(x, y, w, h);
    const dots = Math.round((w * h) / 20);
    for (let k = 0; k < dots; k++) {
        ctx.fillStyle = `#${shade(base, (rng() - 0.5) * 2 * contrast).getHexString()}`;
        ctx.fillRect(x + rng() * w, y + rng() * h, 2, 2);
    }
}

/** Lawn: mottled green base with short blades in lighter and darker greens. Canvas covers 2 × 2 m. */
function drawGrass(ctx: CanvasRenderingContext2D, size: number, color: string, rng: () => number): void {
    const base = new THREE.Color(color);
    ctx.fillStyle = `#${base.getHexString()}`;
    ctx.fillRect(0, 0, size, size);
    for (let i = 0; i < 60; i++) {
        ctx.fillStyle = `#${shade(base, (rng() - 0.5) * 0.16).getHexString()}`;
        ctx.globalAlpha = 0.15;
        ctx.beginPath();
        ctx.arc(rng() * size, rng() * size, 30 + rng() * 120, 0, Math.PI * 2);
        ctx.fill();
    }
    ctx.globalAlpha = 0.6;
    ctx.lineWidth = 1.2;
    for (let i = 0; i < 26000; i++) {
        const x = rng() * size;
        const y = rng() * size;
        ctx.strokeStyle = `#${shade(base, (rng() - 0.45) * 0.5).getHexString()}`;
        ctx.beginPath();
        ctx.moveTo(x, y);
        ctx.lineTo(x + (rng() - 0.5) * 4, y - 4 - rng() * 6);
        ctx.stroke();
    }
    ctx.globalAlpha = 1;
}

/** Washed pebbles 8–32 mm. Canvas covers 1 × 1 m. */
function drawGravel(ctx: CanvasRenderingContext2D, size: number, color: string, rng: () => number): void {
    const base = new THREE.Color(color);
    ctx.fillStyle = `#${shade(base, -0.35).getHexString()}`;
    ctx.fillRect(0, 0, size, size);
    const mmToPx = size / 1000;
    for (let i = 0; i < 9000; i++) {
        const r = (4 + rng() * 12) * mmToPx;
        ctx.fillStyle = `#${shade(base, (rng() - 0.5) * 0.4).getHexString()}`;
        ctx.beginPath();
        ctx.ellipse(rng() * size, rng() * size, r, r * (0.6 + rng() * 0.4), rng() * Math.PI, 0, Math.PI * 2);
        ctx.fill();
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
        case 'Grass':
            canvas.width = canvas.height = 1024;
            drawGrass(ctx, canvas.width, dto.color, rng);
            break;
        case 'Gravel':
            canvas.width = canvas.height = 1024;
            drawGravel(ctx, canvas.width, dto.color, rng);
            break;
        default:
            canvas.width = canvas.height = 4;
            ctx.fillStyle = dto.color;
            ctx.fillRect(0, 0, 4, 4);
    }
    return canvas;
}

/**
 * Oak grain running along the canvas height (texture v). `density` scales the bands and grain lines to the canvas's width
 * against the 512 px of a whole board's texture (a narrow plank gets fewer and narrower ones).
 */
function drawWood(ctx: CanvasRenderingContext2D, w: number, h: number, color: string, rng: () => number, density = 1): void {
    const base = new THREE.Color(color);
    ctx.fillStyle = `#${base.getHexString()}`;
    ctx.fillRect(0, 0, w, h);

    // Broad tonal bands.
    for (let i = 0; i < Math.max(3, Math.round(14 * density)); i++) {
        const x = rng() * w;
        const width = (10 + rng() * 60) * Math.max(density, 0.25);
        ctx.fillStyle = `#${shade(base, (rng() - 0.5) * 0.18).getHexString()}`;
        ctx.globalAlpha = 0.35;
        ctx.fillRect(x, 0, width, h);
    }

    // Fine wavy grain lines.
    ctx.globalAlpha = 0.22;
    for (let i = 0; i < Math.max(12, Math.round(160 * density)); i++) {
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

/** The colour `amount` lighter (or darker) in its sRGB lightness: unlike `shade()`, a lightened red does not turn pink. */
function vary(color: THREE.Color, amount: number): THREE.Color {
    const hsl = color.getHSL({h: 0, s: 0, l: 0}, THREE.SRGBColorSpace);
    return new THREE.Color().setHSL(hsl.h, hsl.s, Math.min(1, Math.max(0, hsl.l + amount)), THREE.SRGBColorSpace);
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
