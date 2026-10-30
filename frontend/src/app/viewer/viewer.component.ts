import {
    afterNextRender,
    ChangeDetectionStrategy,
    Component,
    computed,
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
import {PlanPoint, Room, Scene, SunTime} from '@app/models/scene';
import {CameraPose} from '@app/models/share';
import {environment} from '@environments/environment';

import {CommentPin, CommentPoint} from './engine/comments';
import {EditorTool, ToolStatus} from './engine/drawing';
import {BenchmarkOptions, BenchmarkResult, ViewerStats} from './engine/frame-stats';
import {GhostFloor} from './engine/ghost';
import {MeasureStatus} from './engine/measure';
import {ModelFetcher} from './engine/models';
import {PictureFetcher} from './engine/pictures';
import {Vec2} from './engine/plan-geometry';
import {Selection} from './engine/selection';
import {DoorAim, EditHandler, ViewerEngine, ViewPreset, WallDraftShape} from './engine/viewer-engine';
import {StatsHudComponent} from './stats-hud.component';

/** A click on the view: the element (or group) under the pointer, `additive` for Shift+click. */
export interface SelectEvent {
    selection: Selection | null;
    additive: boolean;
}

/** Hosts the three.js canvas. Persistent display options are inputs; one-off camera moves are methods. */
@Component({
    selector: 'fd-viewer',
    imports: [StatsHudComponent],
    template: `
        <div #host class="host"></div>
        @if (showStats) {
            @if (engine(); as e) {
                <fd-stats-hud [engine]="e" />
            }
        }
    `,
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
    public readonly roofsVisible = input(true);
    public readonly labelsVisible = input(true);
    /** Dimension lines along the room edges, in the top view. */
    public readonly dimensionsVisible = input(false);
    /** After dark: no daylight. */
    public readonly night = input(false);
    /** The flat's lights (light parts, LED strips) are on. */
    public readonly lightsOn = input(false);
    /** The clock time on site the sun stands at (with the scene's location); null: the default afternoon sun. */
    public readonly sunTime = input<SunTime | null>(null);
    /** Presentation quality (ambient occlusion, antialiasing, supersampling) instead of the fast one. */
    public readonly presentation = input(false);
    /** The measure tool: clicks in the top view place two points and show their distance (before any edit tool). */
    public readonly measuring = input(false);
    /** How the 3D models of `Model` items are fetched; null leaves them as placeholders. */
    public readonly modelFetcher = input<ModelFetcher | null>(null);
    /** How the images of `picture` parts are fetched; null leaves them plain. */
    public readonly pictureFetcher = input<PictureFetcher | null>(null);
    /** The decoded image of the scene's underlay file (null while loading, or none). */
    public readonly underlayImage = input<TexImageSource | null>(null);
    /** The underlay in the top view. */
    public readonly underlayVisible = input(true);
    /** The floor below, traced in the top view; null shows none. */
    public readonly ghost = input<GhostFloor | null>(null);
    /** All floors: the scenes of the floors to draw under this one (null: this floor only). */
    public readonly stack = input<readonly Scene[] | null>(null);
    /** With `measuring`: the two points clicked next calibrate the underlay (`calibrated`). */
    public readonly calibrating = input(false);
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
    /** What a click does in edit mode: select and drag, draw walls or a railing, or add the room around the point. */
    public readonly tool = input<EditorTool>('select');
    /** Thickness and height of the walls drawn next (for their preview). */
    public readonly wallDraft = input<WallDraftShape>({thickness: 100, height: 2600});
    /** Adds a drawn run of walls; resolves true when it was added (the scene input then changes). */
    public readonly drawWallsHandler = input<(points: PlanPoint[], closed: boolean, sagittas: number[] | null) => Promise<boolean>>(() =>
        Promise.resolve(false),
    );
    /** Adds a drawn railing (its points; a closed one repeats the first); resolves true when it was added. */
    public readonly drawRailingHandler = input<(points: PlanPoint[]) => Promise<boolean>>(() => Promise.resolve(false));
    /**
     * Takes the walker to the floor above (+1) or below (−1): the id of the floor the page goes to (the walk carries on
     * on it), null when there is none. Without a handler, PageUp / PageDown do nothing.
     */
    public readonly walkFloorHandler = input<(step: 1 | -1) => number | null>(() => null);
    /** Pins of the review comments, numbered. */
    public readonly comments = input<CommentPin[]>([]);
    /** The thread open in the panel, its pin highlighted. */
    public readonly activeCommentId = input<number | null>(null);
    public readonly commentsVisible = input(true);
    /** The comment tool: a click reports the point under the pointer (`commentPlaced`), before the measure and edit tools. */
    public readonly commenting = input(false);

    public readonly selected = output<SelectEvent>();
    /** The walkthrough ended (Esc); the camera returns to the overview. */
    public readonly walkthroughEnded = output();
    /** Whether the walkthrough has the mouse captured. */
    public readonly walkthroughLocked = output<boolean>();
    /** The door the walker looks at (within reach) and whether it is open; null when none. */
    public readonly walkthroughAim = output<DoorAim | null>();
    /** A scene was built (the first floor shown starts at the overview, another floor keeps the view; set another from here). */
    public readonly loaded = output<Scene>();
    /** A click with the room tool inside closed walls: the point to outline a room around. */
    public readonly roomDetect = output<PlanPoint>();
    /** The readout of the drawing tool (null when none is active). */
    public readonly toolStatus = output<ToolStatus | null>();
    /** The readout of the measure tool (null when it is off). */
    public readonly measureStatus = output<MeasureStatus | null>();
    /** Both calibration points were clicked: where they lie on the underlay as placed now (plan mm). */
    public readonly calibrated = output<{a: Vec2; b: Vec2}>();
    /** A comment pin was clicked: its thread's id. */
    public readonly commentPicked = output<number>();
    /** A click with the comment tool: the point to pin a new comment to. */
    public readonly commentPlaced = output<CommentPoint>();

    private readonly host = viewChild.required<ElementRef<HTMLDivElement>>('host');
    /** Rebuilt only when the underlay itself changes, not with every edit of the floor. */
    private readonly underlay = computed(() => this.scene()?.underlay ?? null, {equal: (a, b) => JSON.stringify(a) === JSON.stringify(b)});
    /** Created after the first render, once the host element has its size. */
    protected readonly engine = signal<ViewerEngine | null>(null);
    /** `?stats` in the URL: the rendering readout over the view and the `fdBench` handle for `make benchmark`. */
    protected readonly showStats = new URLSearchParams(window.location.search).has('stats');

    public constructor() {
        afterNextRender(() => {
            const engine = new ViewerEngine(this.host().nativeElement);
            engine.onSelect = (selection, additive): void => this.selected.emit({selection, additive});
            engine.onEdit = (selection, fields): Promise<boolean> => this.editHandler()(selection, fields);
            engine.onWalkthroughEnd = (): void => this.walkthroughEnded.emit();
            engine.onWalkthroughLock = (locked): void => this.walkthroughLocked.emit(locked);
            engine.onWalkthroughAim = (aim): void => this.walkthroughAim.emit(aim);
            engine.onWalkthroughFloor = (step): number | null => this.walkFloorHandler()(step);
            engine.onDrawWalls = (points, closed, sagittas): Promise<boolean> => this.drawWallsHandler()(points, closed, sagittas);
            engine.onDrawRailing = (points): Promise<boolean> => this.drawRailingHandler()(points);
            engine.onDetectRoom = (point): void => this.roomDetect.emit(point);
            engine.onToolStatus = (status): void => this.toolStatus.emit(status);
            engine.onMeasureStatus = (status): void => this.measureStatus.emit(status);
            engine.onCalibrate = (a, b): void => this.calibrated.emit({a, b});
            engine.onCommentPick = (id): void => this.commentPicked.emit(id);
            engine.onCommentPlace = (point): void => this.commentPlaced.emit(point);
            this.engine.set(engine);
            if (!environment.production) {
                // Handle for poking at the engine from the browser console during development.
                (window as unknown as Record<string, unknown>)['fdViewer'] = engine;
            }
            if (this.showStats) {
                // For `make benchmark`, which runs against the production build.
                (window as unknown as Record<string, unknown>)['fdBench'] = {
                    stats: (): ViewerStats => engine.stats(),
                    benchmark: (options?: BenchmarkOptions): Promise<BenchmarkResult> => engine.benchmark(options),
                    gpu: (): string => engine.gpu(),
                };
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
            engine.setRoofsVisible(untracked(this.roofsVisible));
            engine.setSelection(untracked(this.selection));
            engine.setInsetLeft(untracked(this.insetLeft));
            this.loaded.emit(scene);
        });
        effect(() => this.engine()?.setCeilingsVisible(this.ceilingsVisible()));
        effect(() => this.engine()?.setRoofsVisible(this.roofsVisible()));
        effect(() => this.engine()?.setLabelsVisible(this.labelsVisible()));
        effect(() => this.engine()?.setDimensionsVisible(this.dimensionsVisible()));
        effect(() => this.engine()?.setLighting(this.night(), this.lightsOn()));
        effect(() => this.engine()?.setSunTime(this.sunTime()));
        effect(() => this.engine()?.setQuality(this.presentation() ? 'presentation' : 'fast'));
        effect(() => {
            const fetcher = this.modelFetcher();
            if (fetcher !== null) {
                this.engine()?.setModelFetcher(fetcher);
            }
        });
        effect(() => {
            const fetcher = this.pictureFetcher();
            if (fetcher !== null) {
                this.engine()?.setPictureFetcher(fetcher);
            }
        });
        effect(() => this.engine()?.setMeasuring(this.measuring()));
        effect(() => this.engine()?.setCalibrating(this.calibrating()));
        effect(() => this.engine()?.setUnderlay(this.underlay(), this.underlayImage()));
        effect(() => this.engine()?.setUnderlayVisible(this.underlayVisible()));
        effect(() => this.engine()?.setGhost(this.ghost()));
        effect(() => this.engine()?.setStack(this.stack()));
        effect(() => this.engine()?.setSectionHeight(this.sectionHeight()));
        effect(() => this.engine()?.setSelection(this.selection()));
        effect(() => this.engine()?.setPicked(this.picked()));
        effect(() => this.engine()?.setEditMode(this.editMode()));
        effect(() => this.engine()?.setInsetLeft(this.insetLeft()));
        effect(() => this.engine()?.setTool(this.tool()));
        effect(() => this.engine()?.setWallDraft(this.wallDraft()));
        effect(() => this.engine()?.setComments(this.comments(), this.activeCommentId()));
        effect(() => this.engine()?.setCommentsVisible(this.commentsVisible()));
        effect(() => this.engine()?.setCommenting(this.commenting()));

        inject(DestroyRef).onDestroy(() => this.engine()?.dispose());
    }

    public setView(preset: ViewPreset, animate = true): void {
        this.engine()?.setView(preset, animate);
    }

    /**
     * Places the next point of the run being drawn, `length` mm on (towards the pointer, or at `angle` degrees), the wall
     * to it curved by `sagitta` (mm; null: as the arc mode has it).
     */
    public placeRunPoint(length: number, angle: number | null, sagitta: number | null = null): void {
        this.engine()?.placeRunPoint(length, angle, sagitta);
    }

    /** Arc mode of the draw tool: each wall drawn next waits for its curve. */
    public setDrawArc(arc: boolean): void {
        this.engine()?.setDrawArc(arc);
    }

    /** Curves the wall of the run waiting for its curve by a typed sagitta (mm, + to the left). */
    public bendRun(sagitta: number): void {
        this.engine()?.bendRun(sagitta);
    }

    /** Ends the run being drawn, open. */
    public finishRun(): void {
        this.engine()?.finishRun();
    }

    /** Drops the run being drawn; false when there was none. */
    public cancelRun(): boolean {
        return this.engine()?.cancelRun() ?? false;
    }

    /** Clears the measurement; false when there was none. */
    public clearMeasurement(): boolean {
        return this.engine()?.clearMeasurement() ?? false;
    }

    /** First-person walk; call it from a click handler, it captures the mouse. */
    public startWalkthrough(): void {
        this.engine()?.startWalkthrough();
    }

    /** Keys held on the on-screen arrows of a touch walk (`KeyboardEvent.code` values). */
    public setWalkKeys(codes: readonly string[]): void {
        this.engine()?.setWalkKeys(codes);
    }

    /** Walks to the floor above (+1) or below (−1), as PageUp / PageDown do. */
    public walkToFloor(step: 1 | -1): void {
        this.engine()?.walkToFloor(step);
    }

    /** Ends the walk as Esc does (`walkthroughEnded` follows). */
    public endWalkthrough(): void {
        this.engine()?.endWalkthrough();
    }

    /** Where the camera looks, in plan mm (placement of new elements). */
    public viewCenter(): {x: number; y: number} {
        return this.engine()?.viewCenter() ?? {x: 0, y: 0};
    }

    /** Models and pictures still loading (0 without an engine yet). */
    public pendingAssets(): number {
        return this.engine()?.pendingAssets() ?? 0;
    }

    /** The shown floor as a binary glTF (see `ViewerEngine.exportGlb()`). */
    public exportGlb(): Promise<Blob> {
        const engine = this.engine();
        return engine === null ? Promise.reject(new Error('The viewer is not ready.')) : engine.exportGlb();
    }

    /** The view as a PNG (see `ViewerEngine.capture()`). */
    public capture(width: number, height: number, pixelRatio = 2): Promise<Blob> {
        const engine = this.engine();
        return engine === null ? Promise.reject(new Error('The viewer is not ready.')) : engine.capture(width, height, pixelRatio);
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

    /** Turns the camera to a commented point. */
    public focusPoint(point: CommentPoint): void {
        this.engine()?.focusPoint(point);
    }
}
