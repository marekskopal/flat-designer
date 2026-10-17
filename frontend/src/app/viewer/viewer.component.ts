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

import {Selection} from './engine/selection';
import {EditHandler, ViewerEngine, ViewPreset} from './engine/viewer-engine';

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
    public readonly selection = input<Selection | null>(null);
    /** Enables dragging items, walls, wall ends, doors / windows and room corners in the top view. */
    public readonly editMode = input(false);
    /** Commits a drag; resolves true when the edit was applied (the scene input then changes). */
    public readonly editHandler = input<EditHandler>(() => Promise.resolve(false));
    /** Width (px) of UI overlapping the canvas on the left. */
    public readonly insetLeft = input(0);

    public readonly selected = output<Selection | null>();
    /** The walkthrough ended (Esc); the camera returns to the overview. */
    public readonly walkthroughEnded = output();
    /** Whether the walkthrough has the mouse captured. */
    public readonly walkthroughLocked = output<boolean>();

    private readonly host = viewChild.required<ElementRef<HTMLDivElement>>('host');
    /** Created after the first render, once the host element has its size. */
    private readonly engine = signal<ViewerEngine | null>(null);

    public constructor() {
        afterNextRender(() => {
            const engine = new ViewerEngine(this.host().nativeElement);
            engine.onSelect = (selection): void => this.selected.emit(selection);
            engine.onEdit = (selection, fields): Promise<boolean> => this.editHandler()(selection, fields);
            engine.onWalkthroughEnd = (): void => this.walkthroughEnded.emit();
            engine.onWalkthroughLock = (locked): void => this.walkthroughLocked.emit(locked);
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
            engine.setSelection(untracked(this.selection));
            engine.setInsetLeft(untracked(this.insetLeft));
        });
        effect(() => this.engine()?.setCeilingsVisible(this.ceilingsVisible()));
        effect(() => this.engine()?.setLabelsVisible(this.labelsVisible()));
        effect(() => this.engine()?.setSectionHeight(this.sectionHeight()));
        effect(() => this.engine()?.setSelection(this.selection()));
        effect(() => this.engine()?.setEditMode(this.editMode()));
        effect(() => this.engine()?.setInsetLeft(this.insetLeft()));

        inject(DestroyRef).onDestroy(() => this.engine()?.dispose());
    }

    public setView(preset: ViewPreset): void {
        this.engine()?.setView(preset);
    }

    /** First-person walk; call it from a click handler, it captures the mouse. */
    public startWalkthrough(): void {
        this.engine()?.startWalkthrough();
    }

    /** Where the camera looks, in plan mm (placement of new elements). */
    public viewCenter(): {x: number; y: number} {
        return this.engine()?.viewCenter() ?? {x: 0, y: 0};
    }

    public focusRoom(room: Room): void {
        this.engine()?.focusRoom(room);
    }
}
