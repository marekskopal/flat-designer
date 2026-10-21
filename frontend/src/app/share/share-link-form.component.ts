import {ChangeDetectionStrategy, Component, computed, effect, inject, input, output, signal, untracked} from '@angular/core';
import {toSignal} from '@angular/core/rxjs-interop';
import {FormBuilder, ReactiveFormsModule, Validators} from '@angular/forms';
import {localDate} from '@app/core/local-date';
import {CameraPose, ShareLink, ShareLinkInput, ShareSettings, ShareView, ShareViewLabels} from '@app/models/share';
import {ShareService} from '@app/services/share.service';

/** What the viewer shows right now: a new link starts from it. */
export interface ShareSource {
    floorId: number;
    floorName: string;
    view: ShareView;
    /** Read when the link is saved, so the camera can still be moved while the form is open. */
    camera: () => CameraPose | null;
    ceilings: boolean;
    labels: boolean;
    sectionHeight: number | null;
    dimensions: boolean;
    night?: boolean;
    presentation?: boolean;
}

const AllViews: ShareView[] = ['overview', 'top', 'walkthrough'];

/**
 * Creates a share link from the viewer (`source`: the shown floor, view, camera and display) or edits an existing
 * `link` (name, views, start view, exports, expiry; its floor, version and display stay).
 */
@Component({
    selector: 'fd-share-link-form',
    imports: [ReactiveFormsModule],
    template: `
        <form class="form" [formGroup]="form" (ngSubmit)="save()">
            <label class="field">
                <span class="field__label">Name</span>
                <input class="input" type="text" formControlName="name" maxlength="100" placeholder="e.g. Kitchen for the carpenter" />
            </label>

            <fieldset class="field">
                <legend class="field__label">Guest can switch to</legend>
                <div class="checks">
                    @for (view of views; track view) {
                        <label class="check">
                            <input type="checkbox" [formControlName]="view" />
                            {{ labels[view] }}
                        </label>
                    }
                </div>
                @if (!viewsValid()) {
                    <span class="field__hint">Allow 3D or Plan: the walk-through needs a view to return to.</span>
                }
            </fieldset>

            <label class="field">
                <span class="field__label">Opens in</span>
                <select class="input" formControlName="initialView">
                    @for (view of allowedViews(); track view) {
                        <option [value]="view">{{ labels[view] }}</option>
                    }
                </select>
            </label>

            <div class="field">
                <label class="check">
                    <input type="checkbox" formControlName="camera" />
                    {{ link() === null ? 'Save the current 3D camera' : 'Keep the saved 3D camera' }}
                </label>
                @if (cameraHint(); as hint) {
                    <span class="field__hint">{{ hint }}</span>
                }
            </div>

            <div class="field">
                <label class="check">
                    <input type="checkbox" formControlName="exports" />
                    Guests can download the plan (PDF, SVG)
                </label>
            </div>

            <label class="field">
                <span class="field__label">Expires</span>
                <input class="input" type="date" formControlName="expires" [min]="tomorrow" />
                <span class="field__hint">Leave empty to keep the link working until you delete it.</span>
            </label>

            @if (link() === null) {
                <p class="field__hint">
                    Creating the link saves a version of the project: guests see this floor as it is now, later edits do not
                    reach them. The display (ceilings, labels, wall cut, dimensions, night, quality) and the shown variants are kept too.
                </p>
            }
            <div class="form__actions">
                <button type="button" class="btn btn--ghost" (click)="cancelled.emit()">Cancel</button>
                <button type="submit" class="btn btn--primary" [disabled]="form.invalid || !viewsValid() || busy()">
                    {{ link() === null ? 'Create link' : 'Save' }}
                </button>
            </div>
        </form>
    `,
    styles: `
        @use 'variables' as *;

        fieldset {
            margin: 0;
            padding: 0;
            border: 0;
        }

        legend {
            padding: 0;
            margin-bottom: $space-4;
        }

        .checks {
            display: flex;
            gap: $space-16;
        }

        .check {
            display: flex;
            align-items: center;
            gap: $space-8;
            font-size: $font-size-13;
            cursor: pointer;

            input {
                accent-color: $color-accent;
            }
        }

        p.field__hint {
            margin: 0;
        }
    `,
    changeDetection: ChangeDetectionStrategy.OnPush,
})
export class ShareLinkFormComponent {
    public readonly projectId = input.required<number>();
    /** Set when creating from the viewer. */
    public readonly source = input<ShareSource | null>(null);
    /** Set when editing. */
    public readonly link = input<ShareLink | null>(null);

    public readonly saved = output<ShareLink>();
    public readonly cancelled = output();

    private readonly shares = inject(ShareService);

    protected readonly views = AllViews;
    protected readonly labels = ShareViewLabels;
    protected readonly tomorrow = localDate(new Date(Date.now() + 86_400_000));
    protected readonly busy = signal(false);

    protected readonly form = inject(FormBuilder).nonNullable.group({
        name: ['', [Validators.required, Validators.maxLength(100)]],
        overview: [true],
        top: [true],
        walkthrough: [false],
        initialView: ['overview' as ShareView],
        camera: [false],
        exports: [true],
        expires: [''],
    });

    private readonly value = toSignal(this.form.valueChanges, {initialValue: this.form.getRawValue()});

    protected readonly allowedViews = computed(() => {
        const value = this.value();
        return AllViews.filter((view) => value[view] === true);
    });

    private readonly sourceFloorId = computed(() => this.source()?.floorId ?? null);

    /** A camera can be saved (creating, 3D shown) or kept (editing a link that has one), with 3D allowed. */
    private readonly cameraPossible = computed(() => {
        if (!this.allowedViews().includes('overview')) {
            return false;
        }
        const source = this.source();
        const link = this.link();
        return source !== null ? source.view === 'overview' : link?.settings.camera !== null;
    });

    /** At least one view, and the walk-through needs the 3D or the plan view to return to. */
    protected readonly viewsValid = computed(() => this.allowedViews().some((view) => view !== 'walkthrough'));

    /** Why the camera option is off, if it is. */
    protected readonly cameraHint = computed((): string | null => {
        if (!this.allowedViews().includes('overview')) {
            return 'Needs the 3D view allowed.';
        }
        const source = this.source();
        if (source !== null && source.view !== 'overview') {
            return 'Switch the viewer to 3D and frame the shot first.';
        }
        const link = this.link();
        if (link !== null && link.settings.camera === null) {
            return 'This link has no saved camera.';
        }
        return source !== null ? 'Guests start where your camera is now and can return to it.' : null;
    });

    public constructor() {
        // Not on every change of the source (the display toggles, the view): only for another link or floor.
        effect(() => {
            const link = this.link();
            this.sourceFloorId();
            untracked(() => this.reset(link, this.source()));
        });

        // The start view must be an allowed one; the camera needs the 3D view (and, when creating, the 3D view shown).
        effect(() => {
            const allowed = this.allowedViews();
            untracked(() => {
                const initial = this.form.controls.initialView;
                if (allowed.length > 0 && !allowed.includes(initial.value)) {
                    initial.setValue(allowed[0]);
                }
            });
        });
        effect(() => {
            const cameraPossible = this.cameraPossible();
            untracked(() => {
                const camera = this.form.controls.camera;
                if (cameraPossible) {
                    camera.enable({emitEvent: false});
                } else {
                    camera.disable({emitEvent: false});
                    camera.setValue(false, {emitEvent: false});
                }
            });
        });
    }

    private reset(link: ShareLink | null, source: ShareSource | null): void {
        const settings = link?.settings;
        this.form.reset({
            name: link?.name ?? source?.floorName ?? '',
            overview: settings === undefined ? true : settings.views.includes('overview'),
            top: settings === undefined ? true : settings.views.includes('top'),
            walkthrough: settings === undefined ? false : settings.views.includes('walkthrough'),
            initialView: settings?.initialView ?? (source?.view === 'top' ? 'top' : 'overview'),
            camera: settings === undefined ? source?.view === 'overview' : settings.camera !== null,
            exports: settings?.exports ?? true,
            expires: link === null || link.expiresAt === null ? '' : localDate(new Date(link.expiresAt)),
        });
    }

    /** The end of the chosen day (local time); a link's own expiry is kept as it is when its day was not changed. */
    private expiresAt(day: string, link: ShareLink | null): string | null {
        if (day === '') {
            return null;
        }
        if (link?.expiresAt != null && localDate(new Date(link.expiresAt)) === day) {
            return link.expiresAt;
        }
        return new Date(`${day}T23:59:59`).toISOString();
    }

    protected async save(): Promise<void> {
        const value = this.form.getRawValue();
        const link = this.link();
        const source = this.source();
        const display = link?.settings ?? source;
        if (display === null || display === undefined) {
            return;
        }
        const camera = value.camera && this.cameraPossible() ? (source !== null ? source.camera() : (link?.settings.camera ?? null)) : null;
        const settings: ShareSettings = {
            views: this.allowedViews(),
            initialView: value.initialView,
            camera,
            ceilings: display.ceilings,
            labels: display.labels,
            sectionHeight: display.sectionHeight,
            dimensions: display.dimensions,
            night: display.night ?? false,
            presentation: display.presentation ?? false,
            exports: value.exports,
        };
        const body: ShareLinkInput = {
            name: value.name.trim(),
            settings,
            expiresAt: this.expiresAt(value.expires, link),
        };

        this.busy.set(true);
        try {
            this.saved.emit(
                link === null
                    ? await this.shares.create(this.projectId(), {...body, floorId: source?.floorId})
                    : await this.shares.update(this.projectId(), link.id, body),
            );
        } catch {
            // The error interceptor shows the toast.
        } finally {
            this.busy.set(false);
        }
    }
}
