import {HttpErrorResponse} from '@angular/common/http';
import {
    afterNextRender,
    ChangeDetectionStrategy,
    Component,
    computed,
    effect,
    ElementRef,
    inject,
    input,
    numberAttribute,
    OnDestroy,
    signal,
    viewChild,
} from '@angular/core';
import {Router, RouterLink} from '@angular/router';
import {CustomParamsComponent} from '@app/editor/custom-params.component';
import {FurnitureDefinition, FurnitureDefinitionBody, ScopeLabels} from '@app/models/furniture';
import {Material} from '@app/models/scene';
import {AlertService} from '@app/services/alert.service';
import {FurnitureService} from '@app/services/furniture.service';
import {FurniturePreview} from '@app/viewer/engine/custom/preview';

type Parsed = {ok: true; body: FurnitureDefinitionBody} | {ok: false; error: string};

/**
 * One furniture definition: name, description and the definition JSON, with a live 3D preview at a size and with
 * parameter values one can try out (they are not saved). The preview evaluates the definition in the browser while
 * typing; saving runs the server's full check (part options, material codes), whose error names the path in the JSON.
 */
@Component({
    selector: 'fd-furniture-editor',
    imports: [CustomParamsComponent, RouterLink],
    templateUrl: './furniture-editor.component.html',
    styleUrl: './furniture-editor.component.scss',
    changeDetection: ChangeDetectionStrategy.OnPush,
})
export class FurnitureEditorComponent implements OnDestroy {
    public readonly definitionId = input.required({transform: numberAttribute});

    private readonly furniture = inject(FurnitureService);
    private readonly alerts = inject(AlertService);
    private readonly router = inject(Router);
    private readonly previewHost = viewChild.required<ElementRef<HTMLElement>>('preview');
    private preview: FurniturePreview | null = null;

    protected readonly scopeLabels = ScopeLabels;
    protected readonly definition = signal<FurnitureDefinition | null>(null);
    protected readonly materials = signal<Material[]>([]);
    protected readonly name = signal('');
    protected readonly description = signal('');
    protected readonly json = signal('');
    protected readonly tryWidth = signal(800);
    protected readonly tryDepth = signal(400);
    protected readonly tryHeight = signal(900);
    protected readonly tryParams = signal<Record<string, unknown>>({});
    protected readonly busy = signal(false);
    /** The server's answer to the last save or check. */
    protected readonly serverError = signal<string | null>(null);
    protected readonly serverOk = signal(false);
    /** Why the preview shows the red placeholder. */
    protected readonly previewError = signal<string | null>(null);

    protected readonly parsed = computed((): Parsed => {
        try {
            const body: unknown = JSON.parse(this.json());
            return typeof body === 'object' && body !== null && !Array.isArray(body)
                ? {ok: true, body: body as FurnitureDefinitionBody}
                : {ok: false, error: 'The definition must be a JSON object.'};
        } catch (e) {
            return {ok: false, error: `Invalid JSON: ${e instanceof Error ? e.message : String(e)}`};
        }
    });
    protected readonly body = computed(() => {
        const parsed = this.parsed();
        return parsed.ok ? parsed.body : null;
    });
    protected readonly readOnly = computed(() => this.definition()?.canEdit !== true);
    protected readonly dirty = computed(() => {
        const definition = this.definition();
        return (
            definition !== null &&
            (this.name() !== definition.name ||
                this.description() !== definition.description ||
                this.normalized(this.json()) !== JSON.stringify(definition.definition))
        );
    });

    public constructor() {
        effect(() => void this.load(this.definitionId()));
        afterNextRender(() => {
            this.preview = new FurniturePreview(this.previewHost().nativeElement);
            if (this.materials().length > 0) {
                this.preview.setMaterials(this.materials());
            }
            this.updatePreview();
        });
        effect(() => this.updatePreview());
    }

    public ngOnDestroy(): void {
        this.preview?.dispose();
    }

    protected setNumber(target: 'width' | 'depth' | 'height', event: Event): void {
        const value = Number((event.target as HTMLInputElement).value);
        if (Number.isInteger(value) && value > 0) {
            ({width: this.tryWidth, depth: this.tryDepth, height: this.tryHeight})[target].set(value);
        }
    }

    protected text(event: Event): string {
        return (event.target as HTMLInputElement | HTMLTextAreaElement).value;
    }

    /** Size and parameter values back to the definition's defaults. */
    protected resetTryout(): void {
        const size = this.body()?.size;
        this.tryWidth.set(size?.width ?? 800);
        this.tryDepth.set(size?.depth ?? 400);
        this.tryHeight.set(size?.height ?? 900);
        this.tryParams.set({});
    }

    protected format(): void {
        const body = this.body();
        if (body !== null) {
            this.json.set(JSON.stringify(body, null, 2));
        }
    }

    protected async check(): Promise<void> {
        const body = this.body();
        if (body === null) {
            return;
        }
        await this.run(async () => {
            const result = await this.furniture.validate(body);
            this.serverError.set(result.error?.message ?? null);
            this.serverOk.set(result.valid);
        });
    }

    protected async save(): Promise<void> {
        const definition = this.definition();
        const body = this.body();
        if (definition === null || body === null) {
            return;
        }
        await this.run(async () => {
            try {
                const saved = await this.furniture.update(definition.id, {name: this.name(), description: this.description(), definition: body});
                this.definition.set({...saved, itemCount: definition.itemCount});
                this.serverError.set(null);
                this.serverOk.set(true);
                this.alerts.success(`"${saved.name}" was saved.`);
            } catch (e) {
                this.serverOk.set(false);
                this.serverError.set(errorMessage(e));
            }
        });
    }

    protected async copyToLibrary(): Promise<void> {
        const definition = this.definition();
        if (definition === null) {
            return;
        }
        await this.run(async () => {
            const copy = await this.furniture.copy(definition.id, 'user');
            this.alerts.success(`"${definition.name}" was copied to your library.`);
            await this.router.navigate(['/furniture', copy.id]);
        });
    }

    private async load(definitionId: number): Promise<void> {
        try {
            const [definition, materials] = await Promise.all([this.furniture.get(definitionId), this.furniture.materials()]);
            this.materials.set(materials);
            this.preview?.setMaterials(materials);
            this.definition.set(definition);
            this.name.set(definition.name);
            this.description.set(definition.description);
            this.json.set(JSON.stringify(definition.definition, null, 2));
            this.serverError.set(null);
            this.serverOk.set(false);
            this.resetTryout();
        } catch {
            // The error interceptor shows the toast.
        }
    }

    private updatePreview(): void {
        const body = this.body();
        const definition = this.definition();
        const preview = this.preview;
        // Built from the materials once they are there (load).
        if (preview === null || body === null || definition === null || this.materials().length === 0) {
            return;
        }
        const params = this.tryParams();
        this.previewError.set(
            preview.show({
                definition: {id: definition.id, name: this.name(), definition: body, updatedAt: definition.updatedAt},
                width: this.tryWidth(),
                depth: this.tryDepth(),
                height: this.tryHeight(),
                values: (params['values'] as Record<string, unknown> | undefined) ?? {},
                materials: (params['materials'] as Record<string, number> | undefined) ?? {},
            }),
        );
    }

    /** The JSON with whitespace removed, to compare with the stored definition. */
    private normalized(text: string): string {
        try {
            return JSON.stringify(JSON.parse(text));
        } catch {
            return text;
        }
    }

    private async run(action: () => Promise<void>): Promise<void> {
        this.busy.set(true);
        try {
            await action();
        } catch {
            // The error interceptor shows the toast.
        } finally {
            this.busy.set(false);
        }
    }
}

function errorMessage(error: unknown): string {
    if (error instanceof HttpErrorResponse) {
        const message: unknown = (error.error as {message?: unknown} | null)?.message;
        return typeof message === 'string' ? message : error.message;
    }
    return error instanceof Error ? error.message : String(error);
}
