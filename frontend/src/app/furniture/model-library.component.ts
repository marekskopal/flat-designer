import {DecimalPipe} from '@angular/common';
import {ChangeDetectionStrategy, Component, computed, effect, inject, input, signal} from '@angular/core';
import {FurnitureScope} from '@app/models/furniture';
import {ModelAsset} from '@app/models/model-asset';
import {AlertService} from '@app/services/alert.service';
import {CurrentUserService} from '@app/services/current-user.service';
import {ModelAssetService} from '@app/services/model-asset.service';

const ScopeLabels: Record<FurnitureScope, string> = {project: 'This project', user: 'My library', global: 'Global'};

/**
 * The 3D models of bought products: the ones a project's items (or I) may use, upload of a `.glb` with its credits into
 * the project, my library or (admins) the global catalogue, and delete.
 */
@Component({
    selector: 'fd-model-library',
    imports: [DecimalPipe],
    templateUrl: './model-library.component.html',
    styleUrl: './furniture-library.component.scss',
    changeDetection: ChangeDetectionStrategy.OnPush,
})
export class ModelLibraryComponent {
    /** Show what this project's items may use, and upload into it by default. */
    public readonly project = input<number | null>(null);

    private readonly service = inject(ModelAssetService);
    private readonly alerts = inject(AlertService);
    protected readonly isAdmin = inject(CurrentUserService).isAdmin;

    protected readonly models = signal<ModelAsset[]>([]);
    protected readonly busy = signal(false);
    protected readonly scopes = computed((): FurnitureScope[] => [
        ...(this.project() !== null ? (['project'] as const) : []),
        'user',
        ...(this.isAdmin() ? (['global'] as const) : []),
    ]);
    protected readonly scopeLabels = ScopeLabels;

    public constructor() {
        effect(() => void this.load(this.project()));
    }

    protected async upload(form: HTMLFormElement): Promise<void> {
        const data = new FormData(form);
        const file = data.get('file');
        if (!(file instanceof File) || file.size === 0) {
            this.alerts.error('Choose a .glb file.');
            return;
        }
        const text = (key: string): string => String(data.get(key) ?? '').trim();
        const scope = text('scope') as FurnitureScope;
        this.busy.set(true);
        try {
            const model = await this.service.upload(file, {
                name: text('name') || file.name.replace(/\.glb$/i, ''),
                description: text('description'),
                license: text('license'),
                author: text('author'),
                sourceUrl: text('sourceUrl'),
                scope,
                projectId: scope === 'project' ? (this.project() ?? undefined) : undefined,
            });
            this.alerts.success(`Uploaded “${model.name}” (${model.width} × ${model.depth} × ${model.height} mm).`);
            form.reset();
            await this.load(this.project());
        } catch {
            // The error interceptor shows the message.
        } finally {
            this.busy.set(false);
        }
    }

    protected async delete(model: ModelAsset): Promise<void> {
        if (!confirm(`Delete “${model.name}”? Items already using it keep showing it.`)) {
            return;
        }
        this.busy.set(true);
        try {
            await this.service.delete(model.id);
            await this.load(this.project());
        } catch {
            // The error interceptor shows the message.
        } finally {
            this.busy.set(false);
        }
    }

    private async load(project: number | null): Promise<void> {
        try {
            this.models.set(await this.service.list(project ?? undefined));
        } catch {
            this.models.set([]);
        }
    }
}
