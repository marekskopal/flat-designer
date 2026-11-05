import {ChangeDetectionStrategy, Component, computed, effect, inject, input, OnDestroy, signal, untracked} from '@angular/core';
import {DxfImportInput, DxfLayer, DxfPlanInput, DxfSummary, DxfUnits} from '@app/models/dxf';
import {Scene} from '@app/models/scene';
import {AlertService} from '@app/services/alert.service';
import {EditorApiService} from '@app/services/editor-api.service';
import {LanguageService} from '@app/services/language.service';
import {ProjectService} from '@app/services/project.service';
import {PluralPipe, pluralText} from '@app/shared/i18n/plural.pipe';
import {TranslatePipe, TranslateService} from '@ngx-translate/core';

import {EditorStore} from './editor.store';

/** What a layer of the DXF gives the import. */
export type DxfLayerRole = 'wall' | 'opening' | 'ignore';

/** How long the options rest before the preview is asked for again, ms. */
const PreviewDelay = 300;

/**
 * Walls and openings from a DXF plan (in the underlay panel, edit mode): pick or upload the DXF, say which layers hold
 * the walls and the openings, its units and where it lies, and what a plan does not say (heights); the walls and
 * openings it would make show over the plan (`EditorStore.dxfPreview`) until they are imported as one change.
 */
@Component({
    selector: 'fd-dxf-import',
    imports: [PluralPipe, TranslatePipe],
    templateUrl: './dxf-import.component.html',
    styleUrl: './dxf-import.component.scss',
    changeDetection: ChangeDetectionStrategy.OnPush,
})
export class DxfImportComponent implements OnDestroy {
    private readonly translate = inject(TranslateService);
    public readonly scene = input.required<Scene>();
    public readonly projectId = input.required<number>();

    protected readonly editor = inject(EditorStore);
    protected readonly units = DxfUnits;

    protected readonly fileId = signal<number | null>(null);
    protected readonly summary = signal<DxfSummary | null>(null);
    protected readonly loading = signal(false);
    protected readonly roles = signal<Record<string, DxfLayerRole>>({});
    /** The drawing's units when its header does not say, or says wrong; null takes the header's. */
    protected readonly unitsCode = signal<number | null>(null);
    protected readonly anchor = signal<'Walls' | 'Drawing'>('Walls');
    protected readonly offsetX = signal(0);
    protected readonly offsetY = signal(0);
    protected readonly rotation = signal(0);
    protected readonly wallHeight = signal<number | null>(null);
    protected readonly doorHeight = signal<number | null>(null);
    protected readonly windowSill = signal<number | null>(null);
    protected readonly windowHeight = signal<number | null>(null);
    protected readonly replaceExisting = signal(false);

    protected readonly preview = computed(() => this.editor.dxfPreview());
    /** The options the shown preview was made with: Import sends these, so it imports what is shown. */
    private readonly previewed = signal<DxfPlanInput | null>(null);
    /** The preview shows the options as they are now (not while a new one is on its way). */
    protected readonly upToDate = computed(() => this.planInput() !== null && this.previewed() === this.planInput());
    /** The layers with something the import reads, those with walls or openings first. */
    protected readonly layers = computed(() => (this.summary()?.layers ?? []).filter((layer) => layer.lines + layer.arcs > 0));
    protected readonly openingCounts = computed(() => {
        const counts = new Map<string, number>();
        for (const opening of this.preview()?.openings ?? []) {
            counts.set(opening.type, (counts.get(opening.type) ?? 0) + 1);
        }
        return [...counts.entries()]
            .map(([type, count]) =>
                OpeningTypes.includes(type) ? this.plural(`editor.dxf.openings.${type}`, count) : `${count} ${type.toLowerCase()}`,
            )
            .join(', ');
    });
    protected readonly hasWalls = computed(() => this.scene().walls.length > 0);
    /** What the preview is asked for: null until a file and a wall layer are chosen. */
    private readonly planInput = computed((): DxfPlanInput | null => {
        const fileId = this.fileId();
        const roles = this.roles();
        const wallLayers = Object.keys(roles).filter((name) => roles[name] === 'wall');
        if (fileId === null || wallLayers.length === 0) {
            return null;
        }
        return {
            fileId,
            wallLayers,
            openingLayers: Object.keys(roles).filter((name) => roles[name] === 'opening'),
            units: this.unitsCode(),
            rotation: this.rotation(),
            anchor: this.anchor(),
            offsetX: this.offsetX(),
            offsetY: this.offsetY(),
        };
    });

    private readonly api = inject(EditorApiService);
    private readonly projects = inject(ProjectService);
    private readonly alerts = inject(AlertService);
    private readonly language = inject(LanguageService);
    private previewTimer: ReturnType<typeof setTimeout> | null = null;
    /** Answers to earlier previews are dropped. */
    private previewRequest = 0;

    public constructor() {
        effect(() => {
            const planInput = this.planInput();
            untracked(() => this.schedulePreview(planInput));
        });
    }

    public ngOnDestroy(): void {
        this.clearTimer();
        this.editor.dxfPreview.set(null);
    }

    protected async choose(event: Event): Promise<void> {
        const fileId = Number((event.target as HTMLSelectElement).value);
        await this.open(fileId > 0 ? fileId : null);
    }

    protected async upload(event: Event): Promise<void> {
        const box = event.target as HTMLInputElement;
        const file = box.files?.[0];
        box.value = '';
        if (file === undefined) {
            return;
        }
        this.loading.set(true);
        try {
            const stored = await this.projects.uploadFile(this.projectId(), file);
            await this.editor.loadImages();
            await this.open(stored.id);
        } catch {
            // The error interceptor shows the toast.
        } finally {
            this.loading.set(false);
        }
    }

    protected setRole(layer: DxfLayer, event: Event): void {
        const role = (event.target as HTMLSelectElement).value as DxfLayerRole;
        this.roles.update((roles) => ({...roles, [layer.name]: role}));
    }

    protected setUnits(event: Event): void {
        const value = Number((event.target as HTMLSelectElement).value);
        this.unitsCode.set(value > 0 ? value : null);
    }

    protected setAnchor(event: Event): void {
        this.anchor.set((event.target as HTMLSelectElement).value === 'Drawing' ? 'Drawing' : 'Walls');
    }

    /** A number box: an emptied or invalid box keeps the value it had. */
    protected setNumber(field: 'offsetX' | 'offsetY' | 'rotation', event: Event): void {
        const box = event.target as HTMLInputElement;
        const value = Number(box.value);
        if (box.value.trim() === '' || !Number.isFinite(value)) {
            box.value = String(this[field]());
            return;
        }
        this[field].set(field === 'rotation' ? value : Math.round(value));
    }

    /** A height box: empty takes the editor's default. */
    protected setHeight(field: 'wallHeight' | 'doorHeight' | 'windowSill' | 'windowHeight', event: Event): void {
        const box = event.target as HTMLInputElement;
        const value = Number(box.value);
        this[field].set(box.value.trim() === '' || !Number.isFinite(value) ? null : Math.round(value));
    }

    protected async import(): Promise<void> {
        const planInput = this.previewed();
        if (planInput === null || !this.upToDate()) {
            return;
        }
        const importInput: DxfImportInput = {
            ...planInput,
            wallHeight: this.wallHeight(),
            doorHeight: this.doorHeight(),
            windowSill: this.windowSill(),
            windowHeight: this.windowHeight(),
            replaceExisting: this.replaceExisting() && this.hasWalls(),
        };
        const imported = await this.editor.importDxf(importInput);
        if (imported === null) {
            return;
        }
        this.alerts.success(this.translate.instant('editor.dxf.imported', {walls: imported.walls, openings: imported.openings}));
        for (const warning of imported.warnings) {
            this.alerts.error(warning);
        }
        this.fileId.set(null);
        this.summary.set(null);
        this.roles.set({});
    }

    protected cancel(): void {
        this.fileId.set(null);
        this.summary.set(null);
        this.roles.set({});
        this.editor.dxfPreview.set(null);
    }

    protected layerCounts(layer: DxfLayer): string {
        const lines = this.plural('editor.dxf.lines', layer.lines);
        const parts = layer.hidden ? [this.translate.instant('editor.dxf.hidden') as string, lines] : [lines];
        if (layer.arcs > 0) {
            parts.push(this.plural('editor.dxf.arcs', layer.arcs));
        }
        if (layer.inserts > 0) {
            parts.push(this.plural('editor.dxf.blocks', layer.inserts));
        }
        return parts.join(', ');
    }

    private async open(fileId: number | null): Promise<void> {
        this.fileId.set(null);
        this.summary.set(null);
        this.roles.set({});
        this.editor.dxfPreview.set(null);
        if (fileId === null) {
            return;
        }
        this.loading.set(true);
        try {
            const summary = await this.api.dxfSummary(this.projectId(), fileId);
            this.summary.set(summary);
            this.unitsCode.set(null);
            this.roles.set(Object.fromEntries(summary.layers.map((layer) => [layer.name, roleOf(layer)])));
            this.fileId.set(fileId);
        } catch {
            // The error interceptor shows the toast (e.g. not an ASCII DXF).
        } finally {
            this.loading.set(false);
        }
    }

    private schedulePreview(planInput: DxfPlanInput | null): void {
        this.clearTimer();
        const request = ++this.previewRequest;
        this.previewed.set(null);
        if (planInput === null) {
            this.editor.dxfPreview.set(null);
            return;
        }
        this.previewTimer = setTimeout(() => void this.loadPreview(planInput, request), PreviewDelay);
    }

    private async loadPreview(planInput: DxfPlanInput, request: number): Promise<void> {
        try {
            const plan = await this.editor.previewDxf(planInput);
            if (request === this.previewRequest) {
                this.editor.dxfPreview.set(plan);
                this.previewed.set(planInput);
            }
        } catch {
            // The error interceptor shows the toast; the walls shown no longer match the options.
            if (request === this.previewRequest) {
                this.editor.dxfPreview.set(null);
                this.previewed.set(null);
            }
        }
    }

    private plural(key: string, count: number): string {
        return pluralText(this.translate, this.language.current(), key, count);
    }

    private clearTimer(): void {
        if (this.previewTimer !== null) {
            clearTimeout(this.previewTimer);
            this.previewTimer = null;
        }
    }
}

/** The opening types a DXF import makes, counted under `editor.dxf.openings.<type>`. */
const OpeningTypes: readonly string[] = ['Door', 'DoubleDoor', 'Window', 'Passage'];

function roleOf(layer: DxfLayer): DxfLayerRole {
    return layer.suggestedRole ?? 'ignore';
}
