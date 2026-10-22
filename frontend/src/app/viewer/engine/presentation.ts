import * as THREE from 'three';
import {EffectComposer} from 'three/examples/jsm/postprocessing/EffectComposer.js';
import {GTAOPass} from 'three/examples/jsm/postprocessing/GTAOPass.js';
import {OutputPass} from 'three/examples/jsm/postprocessing/OutputPass.js';
import {RenderPass} from 'three/examples/jsm/postprocessing/RenderPass.js';
import {SMAAPass} from 'three/examples/jsm/postprocessing/SMAAPass.js';

/** How the viewer renders: `fast` for working in it, `presentation` for screenshots and shared views. */
export type RenderQuality = 'fast' | 'presentation';

/** Pixel ratio of the presentation quality: at least 2, so that a 1× display is supersampled too. */
export const PresentationPixelRatio = 2;

/**
 * The sun's shadow map side in each quality. The presentation's 8192 (256 MB of depth) is for GPUs reporting a texture
 * size of 16384 or more (discrete ones); a phone or an integrated GPU reporting 8192 gets the fast size, which it can hold.
 */
export const SunShadowMapSize: Readonly<Record<RenderQuality, number>> = {fast: 4096, presentation: 8192};
const LargeGpuTextureSize = 16384;

/** How far the sun's shadow edges are blurred, in shadow-map texels: softer in the presentation quality. */
export const SunShadowRadius: Readonly<Record<RenderQuality, number>> = {fast: 3, presentation: 4};

/**
 * Ambient occlusion, tuned for rooms: the floor along the walls, under tables and around legs darkens, open surfaces stay
 * clean. `radius` is how far (m) a surface looks for occluders; a smaller one only darkens tight contacts.
 */
const AoParameters = {radius: 0.6, distanceExponent: 1.5, thickness: 1.5, scale: 1.6};
const AoBlend = 1;

/**
 * Time (ms) the camera has to stand still before the presentation pipeline renders: while it moves, the plain scene is
 * rendered at the fast quality's resolution, so orbiting and walking stay smooth, and the supersampling, ambient occlusion
 * and SMAA come back once it stops.
 */
export const PresentationSettleMs = 150;

/**
 * How far two camera matrices may differ and still count as the same view (1 mm, 0.06°), as the controls' own threshold:
 * damping keeps nudging the camera by less for a second or more.
 */
const ViewTolerance = 1e-3;

/**
 * GTAO at half the canvas resolution: the normal pass, the occlusion and its denoising get a quarter of the pixels. The
 * presentation canvas is supersampled at least 2×, so this is still one occlusion sample per screen pixel; the blend
 * samples it smoothly at full resolution.
 */
class HalfResolutionGtaoPass extends GTAOPass {
    public override setSize(width: number, height: number): void {
        super.setSize(Math.max(1, Math.round(width / 2)), Math.max(1, Math.round(height / 2)));
    }
}

/** Samples of the moving frames' multisampled buffer (the canvas's own antialiasing does not reach a render target). */
const MovingSamples = 4;

/**
 * The presentation pipeline: the scene rendered into a half-float buffer, ground-truth ambient occlusion (GTAO), the
 * renderer's tone mapping and colour space applied by the output pass, then SMAA against jagged edges. The canvas
 * receives the result, so overlays rendered afterwards (handles, measurements) still draw on top.
 *
 * While the camera moves, `renderMoving()` renders the plain scene at `movingPixelRatio` (the fast quality's) into a
 * multisampled buffer and stretches it over the supersampled canvas.
 */
export class PresentationPipeline {
    private readonly composer: EffectComposer;
    private readonly ao: GTAOPass;
    private readonly smaa: SMAAPass;
    private readonly moving: EffectComposer;

    public constructor(
        renderer: THREE.WebGLRenderer,
        scene: THREE.Scene,
        camera: THREE.PerspectiveCamera,
        private readonly movingPixelRatio: number,
    ) {
        const size = renderer.getSize(new THREE.Vector2());
        this.composer = new EffectComposer(renderer);
        this.composer.addPass(new RenderPass(scene, camera));
        this.ao = new HalfResolutionGtaoPass(scene, camera, size.x, size.y);
        this.ao.updateGtaoMaterial(AoParameters);
        this.ao.blendIntensity = AoBlend;
        this.composer.addPass(this.ao);
        this.composer.addPass(new OutputPass());
        this.smaa = new SMAAPass();
        this.composer.addPass(this.smaa);
        const target = new THREE.WebGLRenderTarget(size.x, size.y, {type: THREE.HalfFloatType, samples: MovingSamples});
        this.moving = new EffectComposer(renderer, target);
        this.moving.addPass(new RenderPass(scene, camera));
        this.moving.addPass(new OutputPass());
        this.setSize(size.x, size.y, renderer.getPixelRatio());
    }

    /** The canvas size in CSS pixels and the renderer's pixel ratio. */
    public setSize(width: number, height: number, pixelRatio: number): void {
        this.composer.setPixelRatio(pixelRatio);
        this.composer.setSize(width, height);
        this.moving.setPixelRatio(Math.min(this.movingPixelRatio, pixelRatio));
        this.moving.setSize(width, height);
    }

    public render(): void {
        this.composer.render();
    }

    public renderMoving(): void {
        this.moving.render();
    }

    public dispose(): void {
        this.ao.dispose();
        this.smaa.dispose();
        this.composer.dispose();
        this.moving.dispose();
    }
}

/** The sun's shadow map side for the quality, within what the GPU holds comfortably. */
export function sunShadowMapSize(quality: RenderQuality, maxTextureSize: number): number {
    const wanted = quality === 'presentation' && maxTextureSize < LargeGpuTextureSize ? SunShadowMapSize.fast : SunShadowMapSize[quality];
    return Math.min(wanted, maxTextureSize);
}

/** The camera's view or projection has changed beyond what damping leaves behind. */
export function viewChanged(previous: THREE.Matrix4, current: THREE.Matrix4): boolean {
    return previous.elements.some((value, i) => Math.abs(value - current.elements[i]) > ViewTolerance);
}
