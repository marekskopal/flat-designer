import {DecimalPipe} from '@angular/common';
import {HttpErrorResponse} from '@angular/common/http';
import {ChangeDetectionStrategy, Component, computed, effect, inject, input, linkedSignal, resource, signal, viewChild} from '@angular/core';
import {CommentAction, CommentsComponent} from '@app/comments/comments.component';
import {errorMessage} from '@app/core/interceptors/error.interceptor';
import {VariantsComponent} from '@app/editor/variants.component';
import {ExportComponent} from '@app/export/export.component';
import {commentPinsOf} from '@app/models/comment';
import {ElectricalSymbols} from '@app/models/electrical-schedule';
import {hasSiteLocation, Scene, SunTime} from '@app/models/scene';
import {ShareView, ShareViewLabels} from '@app/models/share';
import {CommentService} from '@app/services/comment.service';
import {ModelAssetService} from '@app/services/model-asset.service';
import {ShareService} from '@app/services/share.service';
import {StorageService} from '@app/services/storage.service';
import {IconComponent, IconName} from '@app/shared/icon/icon.component';
import {CommentPin, CommentPoint} from '@app/viewer/engine/comments';
import {hasElectricalPoints} from '@app/viewer/engine/electrical';
import {MeasureStatus} from '@app/viewer/engine/measure';
import {PlanCutHeight} from '@app/viewer/engine/plan-drawing';
import {isSunDown} from '@app/viewer/engine/sun';
import {DoorAim} from '@app/viewer/engine/viewer-engine';
import {walkthroughTouchMode} from '@app/viewer/engine/walkthrough';
import {MeasureHudComponent} from '@app/viewer/measure-hud.component';
import {SunTimeComponent} from '@app/viewer/sun-time.component';
import {ViewerComponent} from '@app/viewer/viewer.component';
import {WalkPadComponent} from '@app/viewer/walk-pad.component';
import {ShortcutsComponent} from '@app/viewer-page/shortcuts.component';

/** Section heights offered by the slider (mm); the top value means "no cut" (as on the viewer page). */
const SectionMin = 900;
const SectionMax = 2700;
/** Where the guest's name for comments is kept (localStorage). */
const GuestNameKey = 'fd.guestName';

/** The panels of the rail a link may offer. */
type PanelKey = 'comments' | 'variants' | 'export' | 'keys';

const PanelTitles: Record<PanelKey, string> = {comments: 'Comments', variants: 'Variants', export: 'Export', keys: 'Keyboard shortcuts'};

/**
 * A share link opened by a guest (route `s/:token`, no sign-in): one floor of a saved version, view only. The link
 * decides which views are offered, the one it opens in, the 3D camera and the starting display; variants can be
 * switched for a look, nothing is saved. When the link allows it, the guest sees the floor's open review comments and may
 * add some, signed with a name kept in the browser.
 */
@Component({
    selector: 'fd-shared-viewer-page',
    imports: [
        ViewerComponent,
        WalkPadComponent,
        DecimalPipe,
        VariantsComponent,
        MeasureHudComponent,
        ExportComponent,
        CommentsComponent,
        SunTimeComponent,
        IconComponent,
        ShortcutsComponent,
    ],
    templateUrl: './shared-viewer-page.component.html',
    // The viewer page's layout: the rail, the top bar, the dock and their panels.
    styleUrl: '../viewer-page/viewer-page.component.scss',
    changeDetection: ChangeDetectionStrategy.OnPush,
    host: {'(document:keydown)': 'onKeydown($event)', '(document:pointerdown)': 'onDocumentPointerDown($event)'},
})
export class SharedViewerPageComponent {
    public readonly token = input.required<string>();

    /** The rail's width, so the 3D view frames the model beside it. */
    protected readonly railWidth = 56;
    protected readonly sectionMin = SectionMin;
    protected readonly sectionMax = SectionMax;
    protected readonly viewLabels = ShareViewLabels;
    protected readonly viewIcons: Record<ShareView, IconName> = {overview: 'cube', top: 'plan', walkthrough: 'walk'};
    /** The rail's panel shown beside it. */
    protected readonly panel = signal<PanelKey | null>(null);
    protected readonly panelTitle = computed(() => {
        const panel = this.panel();
        return panel === null ? '' : PanelTitles[panel];
    });
    /** The open popover of the dock. */
    protected readonly menu = signal<'display' | null>(null);

    private readonly shares = inject(ShareService);
    private readonly modelAssets = inject(ModelAssetService);
    /** The 3D models of the shared scene, through the share link (no sign-in). */
    protected readonly modelFetcher = (modelId: number): Promise<ArrayBuffer> => this.modelAssets.sharedContent(this.token(), modelId);
    protected readonly pictureFetcher = (fileId: number): Promise<Blob> => this.shares.sharedPicture(this.token(), fileId);

    private readonly data = resource({
        params: () => ({token: this.token()}),
        loader: async ({params}) => this.shares.getShared(params.token),
    });

    protected readonly shared = computed(() => (this.data.hasValue() ? this.data.value() : null));
    protected readonly settings = computed(() => this.shared()?.settings ?? null);
    /** The shared scene with the guest's own variant choices. */
    protected readonly scene = linkedSignal<Scene | null>(() => this.shared()?.scene ?? null);
    /** The floors under the shared one, when the link draws all floors. */
    protected readonly stack = computed<readonly Scene[] | null>(() => {
        const shared = this.shared();
        return shared?.settings.allFloors === true ? (shared.stack ?? []) : null;
    });
    /** The other floors of the link's version, when it draws all floors: a walk goes over all of them. */
    protected readonly walkFloors = computed<readonly Scene[] | null>(() => {
        const shared = this.shared();
        return shared?.settings.allFloors === true ? [...(shared.stack ?? []), ...(shared.above ?? [])] : null;
    });
    protected readonly error = computed(() => {
        const error = this.data.error();
        if (error === undefined) {
            return null;
        }
        return error instanceof HttpErrorResponse ? errorMessage(error) : 'Could not load the shared view.';
    });
    protected readonly views = computed(() => this.settings()?.views ?? []);
    protected readonly hasVariants = computed(() => (this.scene()?.variantSets ?? []).length > 0);

    protected readonly activeView = signal<ShareView>('overview');
    protected readonly walking = signal(false);
    /** No mouse to capture: the walk shows on-screen arrows instead of the keyboard hint. */
    protected readonly touchWalk = walkthroughTouchMode();
    protected readonly walkLocked = signal(false);
    /** The door in view during the walk (E opens or closes it). */
    protected readonly doorAim = signal<DoorAim | null>(null);
    /** The link opens in the walk, which needs a click to capture the mouse: a button asks for it. */
    protected readonly walkPrompt = signal(false);
    protected readonly ceilingsVisible = signal(false);
    protected readonly roofsVisible = signal(true);
    /** Every cupboard front open: session only. */
    protected readonly frontsOpen = signal(false);
    protected readonly labelsVisible = signal(true);
    protected readonly dimensionsVisible = signal(false);
    protected readonly electricalVisible = signal(false);
    /** The project owner's symbol set for the electrical plan. */
    protected readonly electricalSymbols = computed<ElectricalSymbols>(() => this.shared()?.electricalSymbols ?? 'Iec');
    /** The floor has lights, sockets, switches, … to draw (the Display toggle shows only then). */
    protected readonly hasElectrical = computed(() => {
        const scene = this.scene();
        return scene !== null && hasElectricalPoints(scene);
    });
    /** After dark; the lights follow it (on at night, or once the sun time has it set) until switched by hand. */
    protected readonly night = signal(false);
    private readonly lightsChoice = signal<boolean | null>(null);
    protected readonly lightsOn = computed(() => this.lightsChoice() ?? (this.night() || this.sunDown()));
    /** The chosen sun time has the sun below the horizon: the viewer draws it as night. */
    private readonly sunDown = computed(() => isSunDown(this.scene()?.location, this.sunTime(), new Date().getFullYear()));
    /** Where the sun stands (with the project's location); null: the default afternoon sun. */
    protected readonly sunTime = signal<SunTime | null>(null);
    /** The sun can be placed at a time: the scene knows where the building stands. */
    protected readonly hasLocation = computed(() => hasSiteLocation(this.scene()));
    /** Presentation quality, for screenshots: slower, so off while working. */
    protected readonly presentation = signal(false);
    /** The measure tool, in the plan view (when the link offers it). */
    protected readonly measuring = signal(false);
    protected readonly measureStatus = signal<MeasureStatus | null>(null);
    protected readonly sectionSlider = signal(SectionMax);
    protected readonly sectionHeight = computed(() => (this.sectionSlider() >= SectionMax ? null : this.sectionSlider()));

    private readonly commentService = inject(CommentService);
    private readonly storage = inject(StorageService);
    /** What the link lets the guest do with the comments. */
    protected readonly commentAccess = computed(() => this.settings()?.comments ?? 'hidden');
    private readonly commentList = resource({
        params: () => (this.commentAccess() === 'hidden' ? undefined : {token: this.token()}),
        loader: async ({params}) => this.commentService.listShared(params.token),
    });
    protected readonly comments = computed(() => (this.commentList.hasValue() ? this.commentList.value() : []));
    protected readonly commentsVisible = signal(true);
    protected readonly activeCommentId = signal<number | null>(null);
    protected readonly commenting = signal(false);
    protected readonly commentPoint = signal<CommentPoint | null>(null);
    protected readonly commentBusy = signal(false);
    /** The name a guest signs comments with, remembered in this browser. */
    protected readonly guestName = signal(this.storage.get<string>(GuestNameKey) ?? '');
    protected readonly commentPins = computed((): CommentPin[] => commentPinsOf(this.comments(), this.activeCommentId(), this.commentPoint()));
    /** The guest may write once the link allows it and they have given a name. */
    protected readonly canComment = computed(() => this.commentAccess() === 'write' && this.guestName().trim() !== '');
    /** The rail's buttons: the panels the link offers. */
    protected readonly railItems = computed((): {key: PanelKey; label: string; icon: IconName; badge: number}[] => {
        const items: {key: PanelKey; label: string; icon: IconName; badge: number}[] = [];
        if (this.commentAccess() !== 'hidden') {
            const open = this.comments().filter((c) => c.parentId === null && !c.resolved).length;
            items.push({key: 'comments', label: 'Comments', icon: 'comment', badge: open});
        }
        if (this.hasVariants()) {
            items.push({key: 'variants', label: 'Variants', icon: 'variants', badge: 0});
        }
        if (this.settings()?.exports !== false && this.scene() !== null) {
            items.push({key: 'export', label: 'Export drawings & 3D', icon: 'download', badge: 0});
        }
        return items;
    });
    /** The status line: what the tool at hand does. */
    protected readonly statusHint = computed((): string | null => {
        if (this.settings() === null || this.measureStatus() !== null || this.menu() !== null || this.walkPrompt()) {
            return null;
        }
        if (this.commenting()) {
            return 'Click a surface to pin a comment there · Esc cancels';
        }
        return this.activeView() === 'top' ? 'Drag to pan · scroll to zoom' : 'Drag to orbit · right-drag to pan · scroll to zoom';
    });

    private readonly viewer = viewChild.required(ViewerComponent);
    /** The shown floor as a binary glTF, for the exports. */
    protected readonly exportGlb = (): Promise<Blob> => this.viewer().exportGlb();
    /** The building for the façades and sections: the shared floor with the floors below it, when the link shows them. */
    protected readonly building = (): Promise<readonly Scene[]> => {
        const scene = this.scene();
        return Promise.resolve(scene === null ? [] : [scene, ...(this.stack() ?? [])]);
    };
    /** The link's start state is applied once, when its floor is first built; another link starts over. */
    private started = false;

    public constructor() {
        effect(() => {
            this.token();
            this.started = false;
            this.walkPrompt.set(false);
        });
    }

    protected onLoaded(): void {
        if (!this.started) {
            this.started = true;
            this.resetToShared(false);
            this.walkPrompt.set(this.settings()?.initialView === 'walkthrough' && this.views().includes('walkthrough'));
        }
    }

    /** Back to how the link opens: its start view (3D when that is the walk), camera and display. */
    protected resetToShared(animate = true): void {
        const settings = this.settings();
        if (settings === null) {
            return;
        }
        this.ceilingsVisible.set(settings.ceilings);
        this.roofsVisible.set(settings.roofs ?? true);
        this.labelsVisible.set(settings.labels);
        this.dimensionsVisible.set(settings.dimensions);
        this.electricalVisible.set(settings.electrical ?? false);
        this.night.set(settings.night ?? false);
        this.presentation.set(settings.presentation ?? false);
        this.sunTime.set(settings.sun ?? null);
        this.lightsChoice.set(null);
        this.sectionSlider.set(settings.sectionHeight ?? SectionMax);
        this.measuring.set(false);
        const view = settings.initialView === 'walkthrough' ? this.fallbackView() : settings.initialView;
        this.activeView.set(view);
        this.showView(view, animate);
    }

    protected setView(view: ShareView): void {
        this.walkPrompt.set(false);
        if (view !== 'top') {
            this.measuring.set(false);
        }
        if (view === 'walkthrough') {
            this.startWalkthrough();
            return;
        }
        this.activeView.set(view);
        this.labelsVisible.set(true);
        this.ceilingsVisible.set(false);
        this.sectionSlider.set(view === 'top' ? PlanCutHeight : SectionMax);
        this.showView(view, true);
    }

    /** The 3D view starts at the link's camera when it has one. */
    private showView(view: 'overview' | 'top', animate: boolean): void {
        const camera = this.settings()?.camera ?? null;
        if (view === 'overview' && camera !== null) {
            this.viewer().setCameraPose(camera, animate);
        } else {
            this.viewer().setView(view, animate);
        }
    }

    /** A view to show around the walk: 3D if allowed, otherwise the plan (the server requires one of them). */
    private fallbackView(): 'overview' | 'top' {
        const views = this.settings()?.views ?? [];
        return !views.includes('overview') && views.includes('top') ? 'top' : 'overview';
    }

    protected startWalkthrough(): void {
        this.walkPrompt.set(false);
        this.measuring.set(false);
        this.ceilingsVisible.set(true);
        this.roofsVisible.set(true);
        this.labelsVisible.set(false);
        this.sectionSlider.set(SectionMax);
        this.activeView.set('walkthrough');
        this.walkLocked.set(false);
        this.walking.set(true);
        // Synchronously within the click: the pointer lock needs the user gesture.
        this.viewer().startWalkthrough();
    }

    /** Esc: the engine flies back to the overview; continue to the link's own 3D shot or the plan. */
    protected onWalkthroughEnded(): void {
        this.walking.set(false);
        this.walkLocked.set(false);
        this.doorAim.set(null);
        const view = this.fallbackView();
        this.activeView.set(view);
        this.labelsVisible.set(true);
        this.ceilingsVisible.set(false);
        this.sectionSlider.set(view === 'top' ? PlanCutHeight : SectionMax);
        this.showView(view, true);
    }

    /** Turns the measure tool on (switching to the plan) or off. */
    protected toggleMeasuring(): void {
        if (this.measuring()) {
            this.measuring.set(false);
            return;
        }
        this.commenting.set(false);
        if (!this.views().includes('top')) {
            return;
        }
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
        if (event.key === 'Escape' && this.menu() !== null) {
            this.menu.set(null);
            return;
        }
        if (event.key === '?') {
            this.togglePanel('keys');
        } else if (event.key === 'v' || event.key === 'V') {
            this.selectTool();
        } else if (event.key === 'd' || event.key === 'D') {
            event.preventDefault();
            this.toggleMeasuring();
        } else if ((event.key === 'c' || event.key === 'C') && this.canComment()) {
            event.preventDefault();
            this.pickCommentPoint(!this.commenting());
        } else if (event.key === 'Escape' && (this.commenting() || this.commentPoint() !== null)) {
            this.pickCommentPoint(false);
        } else if (event.key === 'Escape' && this.measuring()) {
            this.measuring.set(false);
        }
    }

    protected togglePanel(key: PanelKey): void {
        this.panel.set(this.panel() === key ? null : key);
    }

    protected toggleMenu(key: 'display'): void {
        this.menu.set(this.menu() === key ? null : key);
    }

    /** A press outside the popover and its button closes it. */
    protected onDocumentPointerDown(event: PointerEvent): void {
        const target = event.target as HTMLElement | null;
        if (this.menu() !== null && target?.closest('[data-menu]') == null) {
            this.menu.set(null);
        }
    }

    /** V: back to looking around, from the measure or comment tool. */
    protected selectTool(): void {
        this.measuring.set(false);
        this.pickCommentPoint(false);
    }

    /** The comment tool, or the Comments panel first when the guest has not given a name yet. */
    protected startComment(): void {
        if (this.canComment()) {
            this.pickCommentPoint(!this.commenting());
        } else {
            this.panel.set('comments');
        }
    }

    protected setNight(night: boolean): void {
        if (this.night() !== night) {
            this.toggleNight();
        }
    }

    protected pickCommentPoint(on: boolean): void {
        this.commentPoint.set(null);
        this.commenting.set(on && this.canComment());
        if (this.commenting()) {
            this.measuring.set(false);
            this.activeCommentId.set(null);
        }
    }

    protected placeComment(point: CommentPoint): void {
        this.commenting.set(false);
        this.commentPoint.set(point);
        this.panel.set('comments');
    }

    protected selectComment(id: number | null, focus: boolean): void {
        this.activeCommentId.set(id);
        if (id !== null) {
            this.panel.set('comments');
        }
        const comment = this.comments().find((c) => c.id === id);
        if (focus && comment !== undefined) {
            this.viewer().focusPoint(comment);
        }
    }

    protected setGuestName(event: Event): void {
        const name = (event.target as HTMLInputElement).value;
        this.guestName.set(name);
        try {
            this.storage.set(GuestNameKey, name.trim());
        } catch {
            // Storage blocked (a private window): the name lasts for the page.
        }
    }

    /** Guests add threads and replies only. */
    protected async onCommentAction(action: CommentAction): Promise<void> {
        const guestName = this.guestName().trim();
        const point = this.commentPoint();
        if (guestName === '' || (action.kind !== 'add' && action.kind !== 'reply') || (action.kind === 'add' && point === null)) {
            return;
        }
        this.commentBusy.set(true);
        try {
            const body = action.kind === 'add' ? {...point, body: action.body, guestName} : {parentId: action.parentId, body: action.body, guestName};
            const created = await this.commentService.createShared(this.token(), body);
            if (action.kind === 'add') {
                this.commentPoint.set(null);
                this.activeCommentId.set(created.id);
            }
            this.commentList.reload();
        } catch {
            // The error interceptor shows the toast.
        } finally {
            this.commentBusy.set(false);
        }
    }

    protected switchVariant(change: {setId: number; groupId: number}): void {
        this.scene.update((scene) =>
            scene === null
                ? scene
                : {...scene, variantSets: scene.variantSets?.map((s) => (s.id === change.setId ? {...s, activeGroupId: change.groupId} : s))},
        );
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
}
