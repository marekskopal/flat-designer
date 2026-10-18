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
import {CameraPose} from '@app/models/share';
import {environment} from '@environments/environment';

import {Selection} from './engine/selection';
import {EditHandler, ViewerEngine, ViewPreset} from './engine/viewer-engine';

/** A click on the view: the element (or group) under the pointer, `additive` for Shift+click. */
export interface SelectEvent {
    selection: Selection | null;
    additive: boolean;
}

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
        /* CSS2D room labels carry their own z-index; keep them below UI laid over the viewer. */
        :host {
            isolation: isolate;
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
    /** Items picked with Shift+click, outlined each. */
    public readonly picked = input<number[]>([]);
    /** Enables dragging items, walls, wall ends, doors / windows and room corners in the top view. */
    public readonly editMode = input(false);
    /** Commits a drag; resolves true when the edit was applied (the scene input then changes). */
    public readonly editHandler = input<EditHandler>(() => Promise.resolve(false));
    /** Width (px) of UI overlapping the canvas on the left. */
    public readonly insetLeft = input(0);

    public readonly selected = output<SelectEvent>();
    /** The walkthrough ended (Esc); the camera returns to the overview. */
    public readonly walkthroughEnded = output();
    /** Whether the walkthrough has the mouse captured. */
    public readonly walkthroughLocked = output<boolean>();
    /** A scene was built (a new floor starts at the overview; set another view from here). */
    public readonly loaded = output<Scene>();

    private readonly host = viewChild.required<ElementRef<HTMLDivElement>>('host');
    /** Created after the first render, once the host element has its size. */
    private readonly engine = signal<ViewerEngine | null>(null);

    public constructor() {
        afterNextRender(() => {
            const engine = new ViewerEngine(this.host().nativeElement);
            engine.onSelect = (selection, additive): void => this.selected.emit({selection, additive});
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
            this.loaded.emit(scene);
        });
        effect(() => this.engine()?.setCeilingsVisible(this.ceilingsVisible()));
        effect(() => this.engine()?.setLabelsVisible(this.labelsVisible()));
        effect(() => this.engine()?.setSectionHeight(this.sectionHeight()));
        effect(() => this.engine()?.setSelection(this.selection()));
        effect(() => this.engine()?.setPicked(this.picked()));
        effect(() => this.engine()?.setEditMode(this.editMode()));
        effect(() => this.engine()?.setInsetLeft(this.insetLeft()));

        inject(DestroyRef).onDestroy(() => this.engine()?.dispose());
    }

    public setView(preset: ViewPreset, animate = true): void {
        this.engine()?.setView(preset, animate);
    }

    /** First-person walk; call it from a click handler, it captures the mouse. */
    public startWalkthrough(): void {
        this.engine()?.startWalkthrough();
    }

    /** Where the camera looks, in plan mm (placement of new elements). */
    public viewCenter(): {x: number; y: number} {
        return this.engine()?.viewCenter() ?? {x: 0, y: 0};
    }

    /** The 3D camera in plan mm; null before the engine starts. */
    public cameraPose(): CameraPose | null {
        return this.engine()?.cameraPose() ?? null;
    }

    public setCameraPose(pose: CameraPose, animate = true): void {
        this.engine()?.setCameraPose(pose, animate);
    }

    public focusRoom(room: Room): void {
        this.engine()?.focusRoom(room);
    }
}
