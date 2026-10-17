import {
    afterNextRender,
    ChangeDetectionStrategy,
    Component,
    DestroyRef,
    effect,
    ElementRef,
    inject,
    input,
    output,
    signal,
    untracked,
    viewChild,
} from '@angular/core';
import {Room, Scene} from '@app/models/scene';
import {environment} from '@environments/environment';

import {ViewerEngine, ViewPreset} from './engine/viewer-engine';

/** Hosts the three.js canvas. Persistent display options are inputs; one-off camera moves are methods. */
@Component({
    selector: 'fd-viewer',
    template: '<div #host class="host"></div>',
    styles: `
        :host, .host {
            position: relative;
            display: block;
            width: 100%;
            height: 100%;
        }
        .host ::ng-deep canvas {
            display: block;
            outline: none;
        }
    `,
    changeDetection: ChangeDetectionStrategy.OnPush,
})
export class ViewerComponent {
    public readonly scene = input<Scene | null>(null);
    public readonly ceilingsVisible = input(false);
    public readonly labelsVisible = input(true);
    /** Section plane height in mm; null shows everything. */
    public readonly sectionHeight = input<number | null>(null);
    public readonly selectedRoomId = input<number | null>(null);
    /** Width (px) of UI overlapping the canvas on the left. */
    public readonly insetLeft = input(0);

    public readonly roomSelected = output<number | null>();

    private readonly host = viewChild.required<ElementRef<HTMLDivElement>>('host');
    /** Created after the first render, once the host element has its size. */
    private readonly engine = signal<ViewerEngine | null>(null);

    public constructor() {
        afterNextRender(() => {
            const engine = new ViewerEngine(this.host().nativeElement);
            engine.onRoomSelected = (roomId): void => this.roomSelected.emit(roomId);
            this.engine.set(engine);
            if (!environment.production) {
                // Handle for poking at the engine from the browser console during development.
                (window as unknown as Record<string, unknown>)['fdViewer'] = engine;
            }
        });

        effect(() => {
            const engine = this.engine();
            const scene = this.scene();
            if (engine === null || scene === null) {
                return;
            }
            engine.load(scene);
            // Re-apply the display state onto the freshly built scene graph.
            engine.setCeilingsVisible(untracked(this.ceilingsVisible));
            engine.setSelectedRoom(untracked(this.selectedRoomId));
            engine.setInsetLeft(untracked(this.insetLeft));
        });
        effect(() => this.engine()?.setCeilingsVisible(this.ceilingsVisible()));
        effect(() => this.engine()?.setLabelsVisible(this.labelsVisible()));
        effect(() => this.engine()?.setSectionHeight(this.sectionHeight()));
        effect(() => this.engine()?.setSelectedRoom(this.selectedRoomId()));
        effect(() => this.engine()?.setInsetLeft(this.insetLeft()));

        inject(DestroyRef).onDestroy(() => this.engine()?.dispose());
    }

    public setView(preset: ViewPreset): void {
        this.engine()?.setView(preset);
    }

    public focusRoom(room: Room): void {
        this.engine()?.focusRoom(room);
    }
}
