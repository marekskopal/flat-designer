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

/** The sun's shadow map side in each quality (capped by the GPU's texture size). */
export const SunShadowMapSize: Readonly<Record<RenderQuality, number>> = {fast: 4096, presentation: 8192};

/** How far the sun's shadow edges are blurred, in shadow-map texels: softer in the presentation quality. */
export const SunShadowRadius: Readonly<Record<RenderQuality, number>> = {fast: 3, presentation: 4};

/**
 * Ambient occlusion, tuned for rooms: the floor along the walls, under tables and around legs darkens, open surfaces stay
 * clean. `radius` is how far (m) a surface looks for occluders; a smaller one only darkens tight contacts.
 */
const AoParameters = {radius: 0.6, distanceExponent: 1.5, thickness: 1.5, scale: 1.6};
const AoBlend = 1;

/**
 * The presentation pipeline: the scene rendered into a half-float buffer, ground-truth ambient occlusion (GTAO), the
 * renderer's tone mapping and colour space applied by the output pass, then SMAA against jagged edges. The canvas
 * receives the result, so overlays rendered afterwards (handles, measurements) still draw on top.
 */
export class PresentationPipeline {
    private readonly composer: EffectComposer;
    private readonly ao: GTAOPass;
    private readonly smaa: SMAAPass;

    public constructor(renderer: THREE.WebGLRenderer, scene: THREE.Scene, camera: THREE.PerspectiveCamera) {
        const size = renderer.getSize(new THREE.Vector2());
        this.composer = new EffectComposer(renderer);
        this.composer.addPass(new RenderPass(scene, camera));
        this.ao = new GTAOPass(scene, camera, size.x, size.y);
        this.ao.updateGtaoMaterial(AoParameters);
        this.ao.blendIntensity = AoBlend;
        this.composer.addPass(this.ao);
        this.composer.addPass(new OutputPass());
        this.smaa = new SMAAPass();
        this.composer.addPass(this.smaa);
        this.setSize(size.x, size.y, renderer.getPixelRatio());
    }

    /** The canvas size in CSS pixels and the renderer's pixel ratio. */
    public setSize(width: number, height: number, pixelRatio: number): void {
        this.composer.setPixelRatio(pixelRatio);
        this.composer.setSize(width, height);
    }

    public render(): void {
        this.composer.render();
    }

    public dispose(): void {
        this.ao.dispose();
        this.smaa.dispose();
        this.composer.dispose();
    }
}

/** The sun's shadow map side for the quality, as large as the GPU allows. */
export function sunShadowMapSize(quality: RenderQuality, maxTextureSize: number): number {
    return Math.min(SunShadowMapSize[quality], maxTextureSize);
}
