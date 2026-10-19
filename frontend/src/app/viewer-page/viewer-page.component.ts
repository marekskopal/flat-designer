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
import {RouterLink} from '@angular/router';
import {errorMessage} from '@app/core/interceptors/error.interceptor';
import {originClientId} from '@app/core/interceptors/origin.interceptor';
import {DrawHudComponent} from '@app/editor/draw-hud.component';
import {EditorStore} from '@app/editor/editor.store';
import {EditorPanelComponent} from '@app/editor/editor-panel.component';
import {VariantsComponent} from '@app/editor/variants.component';
import {ProjectUpdate} from '@app/models/realtime';
import {PlanPoint, Room, RoomType} from '@app/models/scene';
import {ShareLink, shareUrl} from '@app/models/share';
import {AlertService} from '@app/services/alert.service';
import {ProjectService} from '@app/services/project.service';
import {RealtimeService} from '@app/services/realtime.service';
import {ShareLinkFormComponent, ShareSource} from '@app/share/share-link-form.component';
import {ToolStatus} from '@app/viewer/engine/drawing';
import {MeasureStatus} from '@app/viewer/engine/measure';
import {Selection} from '@app/viewer/engine/selection';
import {DoorAim, ViewPreset} from '@app/viewer/engine/viewer-engine';
import {walkthroughTouchMode} from '@app/viewer/engine/walkthrough';
import {MeasureHudComponent} from '@app/viewer/measure-hud.component';
import {SelectEvent, ViewerComponent} from '@app/viewer/viewer.component';
import {WalkPadComponent} from '@app/viewer/walk-pad.component';
import {switchMap} from 'rxjs';

/** Section heights offered by the slider (mm); the top value means "no cut". */
const SectionMin = 900;
const SectionMax = 2700;
/** Plan view cuts walls at this height, like an architectural floor plan. */
const PlanSectionHeight = 1300;

/** Camera presets plus the first-person walkthrough. */
type ViewMode = ViewPreset | 'walkthrough';

interface ViewOption {
    mode: ViewMode;
    label: string;
}

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
    ],
    providers: [EditorStore],
    templateUrl: './viewer-page.component.html',
    styleUrl: './viewer-page.component.scss',
    changeDetection: ChangeDetectionStrategy.OnPush,
    host: {'(document:keydown)': 'onKeydown($event)'},
})
export class ViewerPageComponent {
    public readonly projectId = input.required({transform: numberAttribute});
    public readonly floorId = input.required({transform: numberAttribute});
    /** Id of the saved version to show (query parameter), null for the working copy. */
    public readonly version = input(null, {transform: optionalId});

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
        };
    });
    /** For the editor panel: where new elements are placed. */
    protected readonly viewCenter = (): {x: number; y: number} => this.viewer().viewCenter();

    protected readonly sectionHeight = computed(() => (this.sectionSlider() >= SectionMax ? null : this.sectionSlider()));
    protected readonly rooms = computed(() => this.scene()?.rooms ?? []);
    protected readonly hasVariants = computed(() => (this.scene()?.variantSets ?? []).length > 0);
    protected readonly indoorArea = computed(() => this.areaOf((type) => type !== 'Terrace' && type !== 'Garden'));
    protected readonly terraceArea = computed(() => this.areaOf((type) => type === 'Terrace'));
    protected readonly gardenArea = computed(() => this.areaOf((type) => type === 'Garden'));

    private readonly viewer = viewChild.required(ViewerComponent);

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
                    if (this.activeView() !== 'top') {
                        this.setView('top');
                    }
                });
            }
        });

        // A different floor has different rooms (a live reload of the same floor keeps the selection) and its own links.
        effect(() => {
            this.floorId();
            this.editor.select(null);
            this.sharing.set(null);
        });
    }

    private onProjectUpdate(update: ProjectUpdate): void {
        // Own edits are applied already; a saved version does not change.
        if (update.originClientId === originClientId || this.version() !== null) {
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

    protected setView(mode: ViewMode): void {
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
        this.sectionSlider.set(mode === 'top' ? PlanSectionHeight : SectionMax);
        this.viewer().setView(mode);
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
        if (event.key === 'd' || event.key === 'D') {
            event.preventDefault();
            this.toggleMeasuring();
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
}
