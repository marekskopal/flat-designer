import {DecimalPipe} from '@angular/common';
import {HttpErrorResponse} from '@angular/common/http';
import {
    ChangeDetectionStrategy,
    Component,
    computed,
    effect,
    inject,
    input,
    numberAttribute,
    resource,
    signal,
    untracked,
    viewChild,
} from '@angular/core';
import {takeUntilDestroyed, toObservable} from '@angular/core/rxjs-interop';
import {Router, RouterLink} from '@angular/router';
import {CommentAction, CommentsComponent} from '@app/comments/comments.component';
import {errorMessage} from '@app/core/interceptors/error.interceptor';
import {originClientId} from '@app/core/interceptors/origin.interceptor';
import {DrawHudComponent} from '@app/editor/draw-hud.component';
import {EditorStore} from '@app/editor/editor.store';
import {EditorPanelComponent} from '@app/editor/editor-panel.component';
import {UnderlayPanelComponent} from '@app/editor/underlay-panel.component';
import {VariantsComponent} from '@app/editor/variants.component';
import {downloadBlob, safeFilename} from '@app/export/download';
import {ExportComponent} from '@app/export/export.component';
import {Comment, commentPinsOf} from '@app/models/comment';
import {ProjectUpdate} from '@app/models/realtime';
import {SavedView, SavedViewSettings} from '@app/models/saved-view';
import {PlanPoint, Room, RoomType} from '@app/models/scene';
import {ShareLink, shareUrl} from '@app/models/share';
import {AlertService} from '@app/services/alert.service';
import {CommentService} from '@app/services/comment.service';
import {ModelAssetService} from '@app/services/model-asset.service';
import {ProjectService} from '@app/services/project.service';
import {RealtimeService} from '@app/services/realtime.service';
import {SavedViewService} from '@app/services/saved-view.service';
import {ShareLinkFormComponent, ShareSource} from '@app/share/share-link-form.component';
import {CommentPin, CommentPoint} from '@app/viewer/engine/comments';
import {ToolStatus} from '@app/viewer/engine/drawing';
import {MeasureStatus} from '@app/viewer/engine/measure';
import {decodeImage} from '@app/viewer/engine/pictures';
import {PlanCutHeight} from '@app/viewer/engine/plan-drawing';
import {Selection} from '@app/viewer/engine/selection';
import {DoorAim, ViewPreset} from '@app/viewer/engine/viewer-engine';
import {walkthroughTouchMode} from '@app/viewer/engine/walkthrough';
import {MeasureHudComponent} from '@app/viewer/measure-hud.component';
import {SelectEvent, ViewerComponent} from '@app/viewer/viewer.component';
import {WalkPadComponent} from '@app/viewer/walk-pad.component';
import {switchMap} from 'rxjs';

import {CaptureTarget, SavedViewsComponent} from './saved-views.component';

/** Section heights offered by the slider (mm); the top value means "no cut". */
const SectionMin = 900;
const SectionMax = 2700;

/** Camera presets plus the first-person walkthrough. */
type ViewMode = ViewPreset | 'walkthrough';

interface ViewOption {
    mode: ViewMode;
    label: string;
}

/** An image file decoded for use as a texture. */
const optionalId = (value: string | number | null | undefined): number | null =>
    value === null || value === undefined || value === '' ? null : Number(value);

/**
 * 3D view of one floor of a project (route `projects/:projectId/floors/:floorId`). With `?version=<id>`
 * it shows that saved version read-only instead of the working copy.
 */
@Component({
    selector: 'fd-viewer-page',
    imports: [
        ViewerComponent,
        WalkPadComponent,
        DecimalPipe,
        RouterLink,
        EditorPanelComponent,
        VariantsComponent,
        ShareLinkFormComponent,
        DrawHudComponent,
        MeasureHudComponent,
        UnderlayPanelComponent,
        SavedViewsComponent,
        ExportComponent,
        CommentsComponent,
    ],
    providers: [EditorStore],
    templateUrl: './viewer-page.component.html',
    styleUrl: './viewer-page.component.scss',
    changeDetection: ChangeDetectionStrategy.OnPush,
    // `data-view-ready`: the saved view of the URL is shown with all its models and pictures (for the screenshot script).
    host: {'(document:keydown)': 'onKeydown($event)', '[attr.data-view-ready]': 'viewReady() ? "" : null'},
})
export class ViewerPageComponent {
    public readonly projectId = input.required({transform: numberAttribute});
    public readonly floorId = input.required({transform: numberAttribute});
    /** Id of the saved version to show (query parameter), null for the working copy. */
    public readonly version = input(null, {transform: optionalId});
    /** Id of a saved view to show once the floor is loaded (query parameter). */
    public readonly view = input(null, {transform: optionalId});

    /** Panel width plus its margins, so the 3D view frames the model beside it. */
    protected readonly panelInset = 332;
    protected readonly sectionMin = SectionMin;
    protected readonly sectionMax = SectionMax;
    protected readonly views: ViewOption[] = [
        {mode: 'overview', label: '3D'},
        {mode: 'top', label: 'Plan'},
        {mode: 'walkthrough', label: 'Walk'},
    ];

    protected readonly editor = inject(EditorStore);
    private readonly modelAssets = inject(ModelAssetService);
    /** The 3D models of `Model` items, with the user's sign-in. */
    protected readonly modelFetcher = (modelId: number): Promise<ArrayBuffer> => this.modelAssets.content(modelId);
    // Quietly: a saved version may show a file deleted since, and then the picture stays plain.
    protected readonly pictureFetcher = (fileId: number): Promise<Blob> => this.projects.downloadFile(this.projectId(), fileId, {quiet: true});
    private readonly projects = inject(ProjectService);
    private readonly alerts = inject(AlertService);

    /** Kept while another floor loads, so the switch does not flash an empty view. */
    private readonly data = resource({
        params: () => ({projectId: this.projectId(), floorId: this.floorId(), versionId: this.version()}),
        loader: async ({params}) => {
            const [project, version, scene] = await Promise.all([
                this.projects.get(params.projectId),
                params.versionId === null ? null : this.projects.getVersion(params.projectId, params.versionId),
                this.projects.getScene(params.projectId, params.floorId, params.versionId),
            ]);
            return {project, version, scene};
        },
    });

    protected readonly scene = computed(() => (this.data.hasValue() ? this.data.value().scene : null));
    /** The variants a saved view shows, locally (the project keeps its own), until the scene's next revision. */
    private readonly variantOverride = signal<{revision: number; variants: Record<string, number>} | null>(null);
    /** The scene as shown: with the variants of the applied saved view. */
    protected readonly viewedScene = computed(() => {
        const scene = this.scene();
        const override = this.variantOverride();
        if (scene === null || override === null || override.revision !== (scene.revision ?? -1)) {
            return scene;
        }
        const variantSets = (scene.variantSets ?? []).map((set) => {
            const groupId = override.variants[String(set.id)];
            return groupId === undefined ? set : {...set, activeGroupId: groupId};
        });
        return {...scene, variantSets};
    });
    private readonly savedViewService = inject(SavedViewService);
    private readonly router = inject(Router);
    private readonly savedViewList = resource({
        params: () => ({projectId: this.projectId()}),
        loader: async ({params}) => this.savedViewService.list(params.projectId),
    });
    protected readonly savedViews = computed(() => (this.savedViewList.hasValue() ? this.savedViewList.value() : []));
    protected readonly viewBusy = signal(false);
    /** The saved view of the URL is shown and its models and pictures have loaded. */
    protected readonly viewReady = signal(false);
    /** The saved view of the URL already applied (it is applied once per floor load). */
    private appliedViewKey: string | null = null;
    /** The floor the engine has built: a view applied before would lose its camera to the first framing. */
    private readonly builtFloorId = signal<number | null>(null);
    private readonly underlayFileId = computed(() => this.scene()?.underlay?.fileId ?? null);
    /** The decoded image of the floor's underlay. */
    private readonly underlayFile = resource({
        params: () => {
            const fileId = this.underlayFileId();
            return fileId === null ? undefined : {projectId: this.projectId(), fileId};
        },
        // Quietly: a saved version may point at a file deleted since, and then just shows no underlay.
        loader: async ({params}) => decodeImage(await this.projects.downloadFile(params.projectId, params.fileId, {quiet: true})),
    });
    protected readonly underlayImage = computed(() =>
        this.underlayFileId() !== null && this.underlayFile.hasValue() ? this.underlayFile.value() : null,
    );
    /** The measure tool is on, for measuring or to pick the underlay's calibration points. */
    protected readonly measureActive = computed(() => this.measuring() || this.editor.calibration() !== null);
    protected readonly project = computed(() => (this.data.hasValue() ? this.data.value().project : null));
    protected readonly viewedVersion = computed(() => (this.data.hasValue() ? this.data.value().version : null));
    /** Floors to switch between: those of the viewed version, or of the working copy. */
    protected readonly floors = computed(() => this.viewedVersion()?.floors ?? this.project()?.floors ?? []);
    /** Where "back to current" leads: the same floor when the working copy still has it. */
    protected readonly currentLink = computed(() => {
        const project = this.project();
        if (project === null) {
            return null;
        }
        const floor = project.floors.find((f) => f.id === this.floorId()) ?? project.floors[0];
        return ['/projects', project.id, 'floors', floor.id];
    });
    protected readonly error = computed(() => {
        const error = this.data.error();
        if (error === undefined) {
            return null;
        }
        return error instanceof HttpErrorResponse ? errorMessage(error) : 'Could not load the floor.';
    });
    protected readonly activeView = signal<ViewMode>('overview');
    /** First-person walk in progress: the panel steps aside for the full view. */
    protected readonly walking = signal(false);
    /** No mouse to capture: the walk shows on-screen arrows instead of the keyboard hint. */
    protected readonly touchWalk = walkthroughTouchMode();
    /** The walk has the mouse captured; otherwise the walker is asked to click the view. */
    protected readonly walkLocked = signal(false);
    /** The door in view during the walk (E opens or closes it). */
    protected readonly doorAim = signal<DoorAim | null>(null);
    /** Readout of the active drawing tool. */
    protected readonly toolStatus = signal<ToolStatus | null>(null);
    protected readonly ceilingsVisible = signal(false);
    protected readonly labelsVisible = signal(true);
    protected readonly dimensionsVisible = signal(false);
    /** After dark; the lights follow it (on at night) until switched by hand. */
    protected readonly night = signal(false);
    private readonly lightsChoice = signal<boolean | null>(null);
    protected readonly lightsOn = computed(() => this.lightsChoice() ?? this.night());
    /** Presentation quality, for screenshots: slower, so off while working. */
    protected readonly presentation = signal(false);
    /** The measure tool (top view only); an edit tool turns it off and the other way round. */
    protected readonly measuring = signal(false);
    protected readonly measureStatus = signal<MeasureStatus | null>(null);
    protected readonly sectionSlider = signal(SectionMax);
    /** The share form is open ('form'), or shows the link just created. */
    protected readonly sharing = signal<'form' | ShareLink | null>(null);
    protected readonly sharedLink = computed(() => {
        const sharing = this.sharing();
        return typeof sharing === 'object' && sharing !== null ? {link: sharing, url: shareUrl(sharing.token)} : null;
    });
    protected readonly selectedRoomId = computed(() => {
        const selection = this.editor.selection();
        return selection?.kind === 'room' ? selection.id : null;
    });
    /** Editing is possible on the working copy only. */
    protected readonly canEdit = computed(() => this.version() === null && this.scene() !== null);
    /** What a new share link starts from: this floor as shown now. */
    protected readonly shareSource = computed((): ShareSource | null => {
        const scene = this.scene();
        const view = this.activeView();
        if (scene === null || view === 'walkthrough') {
            return null;
        }
        return {
            floorId: scene.floor.id,
            floorName: scene.floor.name,
            view,
            camera: () => this.viewer().cameraPose(),
            ceilings: this.ceilingsVisible(),
            labels: this.labelsVisible(),
            sectionHeight: this.sectionHeight(),
            dimensions: this.dimensionsVisible(),
            night: this.night(),
            presentation: this.presentation(),
        };
    });
    /** For the editor panel: where new elements are placed. */
    protected readonly viewCenter = (): {x: number; y: number} => this.viewer().viewCenter();

    private readonly commentService = inject(CommentService);
    /** The review comments of the floor. They belong to the working copy: a saved version (its own floor ids) shows none. */
    private readonly commentList = resource({
        params: () => (this.version() === null ? {projectId: this.projectId(), floorId: this.floorId()} : undefined),
        loader: async ({params}) => this.commentService.list(params.projectId, params.floorId),
    });
    protected readonly comments = computed(() => (this.commentList.hasValue() ? this.commentList.value() : []));
    protected readonly commentsVisible = signal(true);
    /** The thread open in the panel. */
    protected readonly activeCommentId = signal<number | null>(null);
    /** The comment tool: the next click in the view picks the point of a new thread. */
    protected readonly commenting = signal(false);
    /** The point picked for a new thread, while its text is written. */
    protected readonly commentPoint = signal<CommentPoint | null>(null);
    protected readonly commentBusy = signal(false);
    protected readonly commentPins = computed((): CommentPin[] => commentPinsOf(this.comments(), this.activeCommentId(), this.commentPoint()));

    protected readonly sectionHeight = computed(() => (this.sectionSlider() >= SectionMax ? null : this.sectionSlider()));
    protected readonly rooms = computed(() => this.scene()?.rooms ?? []);
    protected readonly hasVariants = computed(() => (this.scene()?.variantSets ?? []).length > 0);
    protected readonly indoorArea = computed(() => this.areaOf((type) => type !== 'Terrace' && type !== 'Garden'));
    protected readonly terraceArea = computed(() => this.areaOf((type) => type === 'Terrace'));
    protected readonly gardenArea = computed(() => this.areaOf((type) => type === 'Garden'));

    private readonly viewer = viewChild.required(ViewerComponent);
    /** The shown floor as a binary glTF, for the exports. */
    protected readonly exportGlb = (): Promise<Blob> => this.viewer().exportGlb();

    public constructor() {
        this.editor.connect({
            projectId: () => this.projectId(),
            floorId: () => this.floorId(),
            scene: () => this.scene(),
            setScene: (scene) => this.data.update((value) => (value === undefined ? value : {...value, scene})),
            reload: () => this.data.reload(),
        });
        // A saved version is read-only.
        effect(() => {
            if (this.version() !== null) {
                void this.editor.enable(false);
            }
        });

        const realtime = inject(RealtimeService);
        // Live updates: reload when someone else (another tab, a person, an agent) changed the project.
        toObservable(this.projectId)
            .pipe(
                switchMap((projectId) => realtime.projectUpdates(projectId)),
                takeUntilDestroyed(),
            )
            .subscribe((update) => this.onProjectUpdate(update));

        // The drawing tools work in the top view, and take the clicks from the measure tool.
        effect(() => {
            if (this.editor.tool() !== 'select') {
                untracked(() => {
                    this.measuring.set(false);
                    this.commenting.set(false);
                    if (this.activeView() !== 'top') {
                        this.setView('top');
                    }
                });
            }
        });

        // Calibration points are clicked in the top view, with the measure tool.
        effect(() => {
            if (this.editor.calibration() === 'picking') {
                untracked(() => {
                    this.editor.setTool('select');
                    this.measuring.set(false);
                    if (this.activeView() !== 'top') {
                        this.setView('top');
                    }
                });
            }
        });
        // The saved view of the URL, once its floor and the list are loaded.
        effect(() => {
            const id = this.view();
            const floorId = this.builtFloorId();
            const view = this.savedViews().find((v) => v.id === id);
            if (id === null || floorId === null || view === undefined || view.floorId !== floorId) {
                return;
            }
            const key = `${id}:${floorId}`;
            if (key !== this.appliedViewKey) {
                this.appliedViewKey = key;
                untracked(() => this.showSavedView(view));
            }
        });
        // Editing works on the project's own variants.
        effect(() => {
            if (this.editor.enabled()) {
                untracked(() => this.variantOverride.set(null));
            }
        });
        // A different floor has different rooms (a live reload of the same floor keeps the selection) and its own links.
        effect(() => {
            this.floorId();
            this.editor.select(null);
            this.sharing.set(null);
            this.activeCommentId.set(null);
            this.commenting.set(false);
            this.commentPoint.set(null);
        });
    }

    private onProjectUpdate(update: ProjectUpdate): void {
        // Own edits are applied already; prices do not show in the viewer.
        if (update.originClientId === originClientId || update.type === 'pricesChanged') {
            return;
        }
        if (update.type === 'commentsChanged') {
            this.commentList.reload();
            return;
        }
        if (update.type === 'detailsChanged') {
            // The name in the header, also over a saved version; the reloaded scene is the same.
            this.data.reload();
            return;
        }
        // A saved version does not change.
        if (this.version() !== null) {
            return;
        }
        if (update.type === 'definitionChanged') {
            // The reloaded scene carries the edited definition; only the items drawn from it are rebuilt.
            this.data.reload();
            void this.editor.loadDefinitions();
            return;
        }
        if (update.type === 'materialChanged') {
            // The reloaded scene carries the changed catalogue; the engine rebuilds everything when materials differ.
            this.data.reload();
            return;
        }
        if (update.revision > (this.scene()?.revision ?? -1)) {
            this.data.reload();
            void this.editor.loadChanges();
            if (update.summary !== null && update.actorName !== null) {
                const summary = update.undone ? `undid ${update.summary.charAt(0).toLowerCase()}${update.summary.slice(1)}` : update.summary;
                this.alerts.success(`${update.actorName}: ${summary}`);
            }
        }
    }

    protected setView(mode: ViewMode, animate = true): void {
        if (mode !== 'top') {
            this.measuring.set(false);
        }
        if (mode === 'walkthrough') {
            this.startWalkthrough();
            return;
        }
        this.activeView.set(mode);
        this.labelsVisible.set(true);
        this.ceilingsVisible.set(false);
        this.sectionSlider.set(mode === 'top' ? PlanCutHeight : SectionMax);
        this.viewer().setView(mode, animate);
    }

    private startWalkthrough(): void {
        // Eye level: closed rooms, without labels floating through the walls or a selection outline.
        this.editor.select(null);
        this.ceilingsVisible.set(true);
        this.labelsVisible.set(false);
        this.sectionSlider.set(SectionMax);
        this.activeView.set('walkthrough');
        this.walkLocked.set(false);
        this.walking.set(true);
        // Synchronously within the click: the pointer lock needs the user gesture.
        this.viewer().startWalkthrough();
    }

    /** Esc: the engine flies back to the overview, the panel returns with its settings. */
    protected onWalkthroughEnded(): void {
        this.walking.set(false);
        this.walkLocked.set(false);
        this.doorAim.set(null);
        this.activeView.set('overview');
        this.labelsVisible.set(true);
        this.ceilingsVisible.set(false);
        this.sectionSlider.set(SectionMax);
    }

    /** Turns the measure tool on (in the top view, instead of an edit tool) or off. */
    protected toggleMeasuring(): void {
        if (this.measuring()) {
            this.measuring.set(false);
            return;
        }
        this.editor.setTool('select');
        this.commenting.set(false);
        if (this.activeView() !== 'top') {
            this.setView('top');
        }
        this.measuring.set(true);
    }

    /** D toggles the measure tool; Esc (once the engine has cleared the measurement) turns it off. */
    protected onKeydown(event: KeyboardEvent): void {
        const target = event.target as HTMLElement | null;
        if (this.walking() || target?.closest('input, textarea, select') != null || event.ctrlKey || event.metaKey || event.altKey) {
            return;
        }
        if ((event.key === 'd' || event.key === 'D') && this.editor.calibration() === null) {
            event.preventDefault();
            this.toggleMeasuring();
        } else if ((event.key === 'c' || event.key === 'C') && this.version() === null && this.editor.calibration() === null) {
            event.preventDefault();
            this.pickCommentPoint(!this.commenting());
        } else if (event.key === 'Escape' && (this.commenting() || this.commentPoint() !== null)) {
            this.pickCommentPoint(false);
        } else if (event.key === 'Escape' && this.editor.calibration() !== null) {
            this.editor.calibration.set(null);
        } else if (event.key === 'Escape' && this.measuring()) {
            this.measuring.set(false);
        }
    }

    protected async copyShareUrl(url: string): Promise<void> {
        try {
            await navigator.clipboard.writeText(url);
            this.alerts.success('Link copied.');
        } catch {
            this.alerts.error('Could not copy; select the link and copy it by hand.');
        }
    }

    protected toggleEdit(): void {
        void this.editor.enable(!this.editor.enabled());
    }

    /** Shift+click picks items to group while editing; any other click selects. */
    protected onSelected(event: SelectEvent): void {
        if (event.additive && this.editor.enabled()) {
            this.editor.togglePicked(event.selection);
        } else {
            this.editor.select(event.selection);
        }
    }

    /** A click with the room tool. */
    protected detectRoom(point: PlanPoint): void {
        void this.editor.detectRoom(point);
    }

    /** A run of walls drawn in the viewer. */
    protected readonly drawWalls = (points: PlanPoint[], closed: boolean): Promise<boolean> => this.editor.drawWalls(points, closed);

    /** Drags in the viewer (moves, wall ends, room corners) go through the editor like inspector edits. */
    protected readonly applyDrag = (selection: Selection, fields: Record<string, unknown>): Promise<boolean> =>
        selection.kind === 'group' ? this.editor.moveGroup(selection.id, fields) : this.editor.update(selection.kind, selection.id, fields);

    /** Turns the comment tool on (instead of the measure and edit tools, or the calibration) or off, dropping a point picked before. */
    protected pickCommentPoint(on: boolean): void {
        this.commentPoint.set(null);
        this.commenting.set(on);
        if (on) {
            this.editor.calibration.set(null);
            this.measuring.set(false);
            this.editor.setTool('select');
            this.activeCommentId.set(null);
        }
    }

    /** The point of a new thread was clicked: the panel asks for its text. */
    protected placeComment(point: CommentPoint): void {
        this.commenting.set(false);
        this.commentPoint.set(point);
    }

    /** A thread picked in the panel (the camera turns to its pin) or on its pin. */
    protected selectComment(id: number | null, focus: boolean): void {
        this.activeCommentId.set(id);
        const comment = this.comments().find((c) => c.id === id);
        if (focus && comment !== undefined) {
            this.viewer().focusPoint(comment);
        }
    }

    protected async onCommentAction(action: CommentAction): Promise<void> {
        const projectId = this.projectId();
        this.commentBusy.set(true);
        try {
            if (action.kind === 'add') {
                const point = this.commentPoint();
                if (point === null) {
                    return;
                }
                const created: Comment = await this.commentService.create(projectId, {floorId: this.floorId(), ...point, body: action.body});
                this.commentPoint.set(null);
                this.activeCommentId.set(created.id);
            } else if (action.kind === 'reply') {
                await this.commentService.create(projectId, {floorId: this.floorId(), parentId: action.parentId, body: action.body});
            } else if (action.kind === 'edit') {
                await this.commentService.update(projectId, action.comment.id, {body: action.body});
            } else if (action.kind === 'resolve') {
                await this.commentService.update(projectId, action.comment.id, {resolved: action.resolved});
            } else {
                await this.commentService.delete(projectId, action.comment.id);
            }
            this.commentList.reload();
        } catch {
            // The error interceptor shows the toast.
        } finally {
            this.commentBusy.set(false);
        }
    }

    protected selectRoom(room: Room): void {
        this.editor.select({kind: 'room', id: room.id});
        this.ceilingsVisible.set(false);
        this.labelsVisible.set(true);
        this.activeView.set('overview');
        this.viewer().focusRoom(room);
    }

    private areaOf(matches: (type: RoomType) => boolean): number {
        return this.rooms().filter((r) => matches(r.type)).reduce((sum, r) => sum + r.area, 0);
    }

    protected onSectionInput(event: Event): void {
        this.sectionSlider.set(Number((event.target as HTMLInputElement).value));
    }

    protected toggleNight(): void {
        this.night.set(!this.night());
        this.lightsChoice.set(null);
    }

    protected toggleLights(): void {
        this.lightsChoice.set(!this.lightsOn());
    }

    /** Shows a saved view: on its floor (the URL carries it there), its view mode, display, variants and camera. */
    protected applySavedView(view: SavedView): void {
        if (view.floorId !== this.floorId()) {
            void this.router.navigate(['/projects', this.projectId(), 'floors', view.floorId], {queryParams: {view: view.id}});
            return;
        }
        this.showSavedView(view);
    }

    protected async saveView(name: string): Promise<void> {
        const existing = this.savedViews().find((v) => v.name === name);
        await this.storeView(existing ?? null, name);
    }

    protected async overwriteView(view: SavedView): Promise<void> {
        await this.storeView(view, view.name);
    }

    protected async removeView(view: SavedView): Promise<void> {
        if (!window.confirm(`Delete the view "${view.name}"?`)) {
            return;
        }
        await this.viewRequest(() => this.savedViewService.delete(this.projectId(), view.id));
    }

    /** The view as a 1600 × 1000 PNG at 2× in the presentation quality: downloaded, or saved among the project's files. */
    protected async captureView(target: CaptureTarget): Promise<void> {
        const scene = this.scene();
        if (scene === null) {
            return;
        }
        this.viewBusy.set(true);
        try {
            const blob = await this.viewer().capture(1600, 1000, 2);
            const name = safeFilename(`${scene.floor.name} ${new Date().toISOString().slice(0, 16).replace('T', ' ')}.png`);
            if (target === 'file') {
                await this.projects.uploadFile(this.projectId(), new File([blob], name, {type: 'image/png'}));
                this.alerts.success(`Saved "${name}" among the project's files.`);
            } else {
                downloadBlob(blob, name);
            }
        } catch (e) {
            this.alerts.error(e instanceof Error ? e.message : 'The view could not be captured.');
        } finally {
            this.viewBusy.set(false);
        }
    }

    private showSavedView(view: SavedView): void {
        const settings = view.settings;
        const scene = this.scene();
        this.viewReady.set(false);
        if (this.walking()) {
            this.viewer().endWalkthrough();
        }
        this.measuring.set(false);
        this.setView(settings.view, false);
        this.ceilingsVisible.set(settings.ceilings);
        this.labelsVisible.set(settings.labels);
        this.sectionSlider.set(settings.sectionHeight ?? SectionMax);
        this.dimensionsVisible.set(settings.dimensions);
        this.night.set(settings.night);
        this.lightsChoice.set(settings.lightsOn);
        this.presentation.set(settings.presentation);
        const hasVariants = Object.keys(settings.variants).length > 0;
        this.variantOverride.set(scene !== null && hasVariants ? {revision: scene.revision ?? -1, variants: settings.variants} : null);
        if (settings.view === 'overview' && settings.camera !== null) {
            this.viewer().setCameraPose(settings.camera, false);
        }
        void this.markReadyWhenLoaded();
    }

    /** Ready once no model or picture has been loading for a few checks in a row (a rebuild may start new loads). */
    private async markReadyWhenLoaded(): Promise<void> {
        let quiet = 0;
        while (quiet < 3) {
            await new Promise((resolve) => setTimeout(resolve, 150));
            quiet = this.viewer().pendingAssets() === 0 ? quiet + 1 : 0;
        }
        this.viewReady.set(true);
    }

    /** What the panel shows now, as a saved view's settings. */
    private currentViewSettings(): SavedViewSettings | null {
        const scene = this.viewedScene();
        if (scene === null) {
            return null;
        }
        const view = this.activeView();
        const variants: Record<string, number> = {};
        for (const set of scene.variantSets ?? []) {
            if (set.activeGroupId !== null) {
                variants[String(set.id)] = set.activeGroupId;
            }
        }
        return {
            // A walk is kept as the same shot in the 3D view.
            view: view === 'top' ? 'top' : 'overview',
            camera: view === 'top' ? null : this.viewer().cameraPose(),
            ceilings: this.ceilingsVisible(),
            labels: this.labelsVisible(),
            sectionHeight: this.sectionHeight(),
            dimensions: this.dimensionsVisible(),
            night: this.night(),
            lightsOn: this.lightsChoice(),
            presentation: this.presentation(),
            variants,
        };
    }

    private async storeView(existing: SavedView | null, name: string): Promise<void> {
        const settings = this.currentViewSettings();
        if (settings === null) {
            return;
        }
        const body = {floorId: this.floorId(), name, settings};
        await this.viewRequest(() =>
            existing === null
                ? this.savedViewService.create(this.projectId(), body)
                : this.savedViewService.update(this.projectId(), existing.id, body),
        );
    }

    private async viewRequest(request: () => Promise<unknown>): Promise<void> {
        this.viewBusy.set(true);
        try {
            await request();
            this.savedViewList.reload();
        } catch {
            // The error interceptor shows the toast.
        } finally {
            this.viewBusy.set(false);
        }
    }

    /** The engine built a scene (also a live reload of the same floor). */
    protected onSceneBuilt(scene: {floor: {id: number}}): void {
        if (this.builtFloorId() !== scene.floor.id) {
            this.appliedViewKey = null;
        }
        this.builtFloorId.set(scene.floor.id);
    }
}
