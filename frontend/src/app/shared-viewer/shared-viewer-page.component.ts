import {DecimalPipe} from '@angular/common';
import {HttpErrorResponse} from '@angular/common/http';
import {ChangeDetectionStrategy, Component, computed, effect, inject, input, linkedSignal, resource, signal, viewChild} from '@angular/core';
import {CommentAction, CommentsComponent} from '@app/comments/comments.component';
import {errorMessage} from '@app/core/interceptors/error.interceptor';
import {VariantsComponent} from '@app/editor/variants.component';
import {ExportComponent} from '@app/export/export.component';
import {commentPinsOf} from '@app/models/comment';
import {Scene} from '@app/models/scene';
import {ShareView, ShareViewLabels} from '@app/models/share';
import {CommentService} from '@app/services/comment.service';
import {ModelAssetService} from '@app/services/model-asset.service';
import {ShareService} from '@app/services/share.service';
import {StorageService} from '@app/services/storage.service';
import {CommentPin, CommentPoint} from '@app/viewer/engine/comments';
import {MeasureStatus} from '@app/viewer/engine/measure';
import {PlanCutHeight} from '@app/viewer/engine/plan-drawing';
import {DoorAim} from '@app/viewer/engine/viewer-engine';
import {walkthroughTouchMode} from '@app/viewer/engine/walkthrough';
import {MeasureHudComponent} from '@app/viewer/measure-hud.component';
import {ViewerComponent} from '@app/viewer/viewer.component';
import {WalkPadComponent} from '@app/viewer/walk-pad.component';

/** Section heights offered by the slider (mm); the top value means "no cut" (as on the viewer page). */
const SectionMin = 900;
const SectionMax = 2700;
/** Where the guest's name for comments is kept (localStorage). */
const GuestNameKey = 'fd.guestName';

/**
 * A share link opened by a guest (route `s/:token`, no sign-in): one floor of a saved version, view only. The link
 * decides which views are offered, the one it opens in, the 3D camera and the starting display; variants can be
 * switched for a look, nothing is saved. When the link allows it, the guest sees the floor's open review comments and may
 * add some, signed with a name kept in the browser.
 */
@Component({
    selector: 'fd-shared-viewer-page',
    imports: [ViewerComponent, WalkPadComponent, DecimalPipe, VariantsComponent, MeasureHudComponent, ExportComponent, CommentsComponent],
    templateUrl: './shared-viewer-page.component.html',
    styleUrl: '../viewer-page/viewer-page.component.scss',
    changeDetection: ChangeDetectionStrategy.OnPush,
    host: {'(document:keydown)': 'onKeydown($event)'},
})
export class SharedViewerPageComponent {
    public readonly token = input.required<string>();

    protected readonly panelInset = 332;
    protected readonly sectionMin = SectionMin;
    protected readonly sectionMax = SectionMax;
    protected readonly viewLabels = ShareViewLabels;

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
    /** The guest hid the panel to see more of the floor (on a phone it covers the lower part of the screen). */
    protected readonly panelHidden = signal(false);
    protected readonly ceilingsVisible = signal(false);
    protected readonly labelsVisible = signal(true);
    protected readonly dimensionsVisible = signal(false);
    /** After dark; the lights follow it (on at night) until switched by hand. */
    protected readonly night = signal(false);
    private readonly lightsChoice = signal<boolean | null>(null);
    protected readonly lightsOn = computed(() => this.lightsChoice() ?? this.night());
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

    private readonly viewer = viewChild.required(ViewerComponent);
    /** The shown floor as a binary glTF, for the exports. */
    protected readonly exportGlb = (): Promise<Blob> => this.viewer().exportGlb();
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
        this.labelsVisible.set(settings.labels);
        this.dimensionsVisible.set(settings.dimensions);
        this.night.set(settings.night ?? false);
        this.presentation.set(settings.presentation ?? false);
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
        if (event.key === 'd' || event.key === 'D') {
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
    }

    protected selectComment(id: number | null, focus: boolean): void {
        this.activeCommentId.set(id);
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
