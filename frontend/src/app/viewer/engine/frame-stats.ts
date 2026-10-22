import * as THREE from 'three';

import {RenderQuality} from './presentation';

/** Frames kept for the live readout (`?stats`). */
const StatsFrames = 120;

/** Frame times in ms: the middle, the slow tail and the mean. */
export interface FrameSummary {
    median: number;
    p95: number;
    mean: number;
    count: number;
}

/** What one frame drew, summed over all its passes (sun shadow, scene, post-processing, overlay). */
export interface RenderCounters {
    calls: number;
    triangles: number;
    points: number;
    lines: number;
    geometries: number;
    textures: number;
    programs: number;
}

/** What the built floor holds. */
export interface SceneCounters {
    meshes: number;
    /** Lights in the scene, sun and sky included; switched-off fixtures count, as their shaders still loop over them. */
    lights: number;
    shadowLights: number;
}

export interface ViewerStats {
    frame: FrameSummary;
    render: RenderCounters;
    scene: SceneCounters;
    /** Time the last full build of the floor took, ms. */
    buildMs: number;
}

export interface BenchmarkOptions {
    /** Measured frames. */
    frames?: number;
    /** Frames rendered first, unmeasured: shader compiles, shadow maps. */
    warmup?: number;
    /** Turns the camera a full circle about its target over the measured frames. */
    orbit?: boolean;
    /** The scenario; the current state where left out, restored afterwards. */
    quality?: RenderQuality;
    night?: boolean;
    lightsOn?: boolean;
}

export interface BenchmarkResult {
    /** CPU and GPU time per frame (the GPU waited for after each), ms. */
    median: number;
    p95: number;
    min: number;
    frames: number;
    render: RenderCounters;
    scene: SceneCounters;
    buildMs: number;
    /** Drawing buffer size in device pixels. */
    width: number;
    height: number;
}

/** The p-th percentile (0–100) of the values, by linear interpolation; 0 for none. */
export function percentile(values: readonly number[], p: number): number {
    if (values.length === 0) {
        return 0;
    }
    const sorted = [...values].sort((a, b) => a - b);
    const rank = (Math.min(Math.max(p, 0), 100) / 100) * (sorted.length - 1);
    const below = Math.floor(rank);
    const above = Math.ceil(rank);
    return sorted[below] + (sorted[above] - sorted[below]) * (rank - below);
}

export function median(values: readonly number[]): number {
    return percentile(values, 50);
}

export function summarize(values: readonly number[]): FrameSummary {
    const mean = values.length === 0 ? 0 : values.reduce((sum, v) => sum + v, 0) / values.length;
    return {median: median(values), p95: percentile(values, 95), mean, count: values.length};
}

/** The last frame times, in a ring. */
export class FrameStats {
    private readonly times: number[] = [];
    private next = 0;

    public constructor(private readonly size = StatsFrames) {}

    public record(ms: number): void {
        if (this.times.length < this.size) {
            this.times.push(ms);
        } else {
            this.times[this.next] = ms;
        }
        this.next = (this.next + 1) % this.size;
    }

    public summary(): FrameSummary {
        return summarize(this.times);
    }
}

/** The renderer's counters since its last `info.reset()` (`autoReset` off, so a frame's passes add up). */
export function renderCounters(renderer: THREE.WebGLRenderer): RenderCounters {
    const info = renderer.info;
    return {
        calls: info.render.calls,
        triangles: info.render.triangles,
        points: info.render.points,
        lines: info.render.lines,
        geometries: info.memory.geometries,
        textures: info.memory.textures,
        programs: info.programs?.length ?? 0,
    };
}

export function sceneCounters(root: THREE.Object3D): SceneCounters {
    const counters: SceneCounters = {meshes: 0, lights: 0, shadowLights: 0};
    root.traverse((object) => {
        if ((object as THREE.Mesh).isMesh) {
            counters.meshes++;
        } else if ((object as THREE.Light).isLight) {
            counters.lights++;
            if (object.castShadow) {
                counters.shadowLights++;
            }
        }
    });
    return counters;
}

/** The GPU as the browser names it, so that benchmark results are compared on the same machine only. */
export function gpuName(renderer: THREE.WebGLRenderer): string {
    const gl = renderer.getContext();
    const debug = gl.getExtension('WEBGL_debug_renderer_info');
    return debug === null ? String(gl.getParameter(gl.RENDERER)) : String(gl.getParameter(debug.UNMASKED_RENDERER_WEBGL));
}
