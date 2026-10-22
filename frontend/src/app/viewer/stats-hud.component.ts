import {DecimalPipe} from '@angular/common';
import {ChangeDetectionStrategy, Component, DestroyRef, inject, input, signal} from '@angular/core';

import {ViewerStats} from './engine/frame-stats';
import {ViewerEngine} from './engine/viewer-engine';

/** How often the readout refreshes, ms. */
const RefreshInterval = 500;

/**
 * Readout of the rendering cost over the viewer (`?stats`): frame times and what a frame draws. The frame time is the
 * CPU's part only (the GPU runs behind it); `make benchmark` measures whole frames.
 */
@Component({
    selector: 'fd-stats-hud',
    imports: [DecimalPipe],
    template: `
        @if (stats(); as s) {
            <dl class="stats" aria-label="Rendering statistics">
                <dt>Frame</dt>
                <dd>{{ s.frame.median | number: '1.1-1' }} ms · p95 {{ s.frame.p95 | number: '1.1-1' }}</dd>
                <dt>Calls</dt>
                <dd>{{ s.render.calls | number }}</dd>
                <dt>Triangles</dt>
                <dd>{{ s.render.triangles / 1000 | number: '1.0-0' }} k</dd>
                <dt>Meshes</dt>
                <dd>{{ s.scene.meshes | number }}</dd>
                <dt>Geometries</dt>
                <dd>{{ s.render.geometries | number }}</dd>
                <dt>Textures</dt>
                <dd>{{ s.render.textures | number }}</dd>
                <dt>Programs</dt>
                <dd>{{ s.render.programs | number }}</dd>
                <dt>Lights</dt>
                <dd>{{ s.scene.lights }} ({{ s.scene.shadowLights }} shadow)</dd>
                <dt>Build</dt>
                <dd>{{ s.buildMs | number: '1.0-0' }} ms</dd>
            </dl>
        }
    `,
    styles: `
        @use 'variables' as *;

        .stats {
            position: absolute;
            top: $space-8;
            right: $space-8;
            z-index: 10;
            display: grid;
            grid-template-columns: auto auto;
            gap: 0 $space-8;
            margin: 0;
            padding: $space-8;
            background: $color-surface;
            border: 1px solid $color-border;
            border-radius: $radius-10;
            font-size: $font-size-12;
            font-variant-numeric: tabular-nums;
            color: $color-text;
            pointer-events: none;
        }

        dt {
            color: $color-text-muted;
        }

        dd {
            margin: 0;
            text-align: right;
        }
    `,
    changeDetection: ChangeDetectionStrategy.OnPush,
})
export class StatsHudComponent {
    public readonly engine = input.required<ViewerEngine>();
    protected readonly stats = signal<ViewerStats | null>(null);

    public constructor() {
        const timer = setInterval(() => this.stats.set(this.engine().stats()), RefreshInterval);
        inject(DestroyRef).onDestroy(() => clearInterval(timer));
    }
}
