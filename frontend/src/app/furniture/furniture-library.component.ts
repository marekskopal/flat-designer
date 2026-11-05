import {ChangeDetectionStrategy, Component, computed, effect, inject, input, signal} from '@angular/core';
import {Router, RouterLink} from '@angular/router';
import {FurnitureDefinition, FurnitureScope} from '@app/models/furniture';
import {AlertService} from '@app/services/alert.service';
import {FurnitureService} from '@app/services/furniture.service';
import {LanguageService} from '@app/services/language.service';
import {localizeDefinition} from '@app/shared/i18n/definition-texts';
import {TranslatePipe, TranslateService} from '@ngx-translate/core';

import {NewDefinitionTemplate} from './furniture-template';
import {ModelLibraryComponent} from './model-library.component';

/**
 * The furniture definitions one can use: global ones and my library; with `?projectId=` also that project's own (and
 * its owner's library). Create, copy to my library or the project, delete.
 */
@Component({
    selector: 'fd-furniture-library',
    imports: [ModelLibraryComponent, RouterLink, TranslatePipe],
    templateUrl: './furniture-library.component.html',
    styleUrl: './furniture-library.component.scss',
    changeDetection: ChangeDetectionStrategy.OnPush,
})
export class FurnitureLibraryComponent {
    private readonly language = inject(LanguageService);
    /** From the query string: show what this project's items may use. */
    public readonly projectId = input<string | undefined>(undefined);

    private readonly furniture = inject(FurnitureService);
    private readonly alerts = inject(AlertService);
    private readonly router = inject(Router);
    private readonly translate = inject(TranslateService);

    protected readonly definitions = signal<FurnitureDefinition[]>([]);
    protected readonly loading = signal(true);
    protected readonly busy = signal(false);
    protected readonly project = computed(() => {
        const id = Number(this.projectId());
        return Number.isInteger(id) && id > 0 ? id : null;
    });
    protected readonly sections = computed(() =>
        (['project', 'user', 'global'] as const)
            .map((scope) => ({scope, label: this.sectionLabel(scope), definitions: this.definitions().filter((d) => d.scope === scope)}))
            .filter((section) => section.scope !== 'project' || this.project() !== null),
    );

    public constructor() {
        effect(() => void this.load(this.project()));
    }

    protected async create(scope: FurnitureScope): Promise<void> {
        await this.run(async () => {
            const project = this.project();
            const created = await this.furniture.create({
                name: this.translate.instant('furniture.library.newName') as string,
                description: '',
                definition: NewDefinitionTemplate,
                scope,
                ...(scope === 'project' && project !== null ? {projectId: project} : {}),
            });
            await this.router.navigate(['/furniture', created.id]);
        });
    }

    protected async copy(definition: FurnitureDefinition, scope: FurnitureScope): Promise<void> {
        await this.run(async () => {
            const project = this.project();
            await this.furniture.copy(definition.id, scope, scope === 'project' && project !== null ? project : undefined);
            const key = scope === 'project' ? 'furniture.copiedToProject' : 'furniture.copiedToLibrary';
            this.alerts.success(this.translate.instant(key, {name: definition.name}) as string);
            await this.load(this.project());
        });
    }

    protected async delete(definition: FurnitureDefinition): Promise<void> {
        if (!confirm(this.translate.instant('furniture.library.confirmDelete', {name: definition.name}) as string)) {
            return;
        }
        await this.run(async () => {
            await this.furniture.delete(definition.id);
            this.definitions.update((all) => all.filter((d) => d.id !== definition.id));
        });
    }

    private sectionLabel(scope: FurnitureScope): string {
        const key = scope === 'user' && this.project() !== null ? 'furniture.library.ownerLibrary' : `furniture.scopes.${scope}`;
        return this.translate.instant(key) as string;
    }

    private async load(project: number | null): Promise<void> {
        this.loading.set(true);
        try {
            this.definitions.set((await this.furniture.list(project ?? undefined)).map((d) => localizeDefinition(d, this.language.current())));
        } catch {
            // The error interceptor shows the toast.
        } finally {
            this.loading.set(false);
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
