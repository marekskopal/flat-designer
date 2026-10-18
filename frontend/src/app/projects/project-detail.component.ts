import {DatePipe, NgTemplateOutlet} from '@angular/common';
import {ChangeDetectionStrategy, Component, computed, effect, inject, input, numberAttribute, signal} from '@angular/core';
import {takeUntilDestroyed, toObservable} from '@angular/core/rxjs-interop';
import {FormBuilder, ReactiveFormsModule, Validators} from '@angular/forms';
import {Router, RouterLink} from '@angular/router';
import {originClientId} from '@app/core/interceptors/origin.interceptor';
import {Project, ProjectVersion} from '@app/models/project';
import {Floor} from '@app/models/scene';
import {ShareLink, shareUrl} from '@app/models/share';
import {ProjectFilesComponent} from '@app/projects/files/project-files.component';
import {AlertService} from '@app/services/alert.service';
import {ProjectService} from '@app/services/project.service';
import {RealtimeService} from '@app/services/realtime.service';
import {ShareService} from '@app/services/share.service';
import {ShareLinkFormComponent} from '@app/share/share-link-form.component';
import {filter, switchMap} from 'rxjs';

/** One project: its details, floors (the working copy), saved versions, share links and files. */
@Component({
    selector: 'fd-project-detail',
    imports: [DatePipe, NgTemplateOutlet, ProjectFilesComponent, ReactiveFormsModule, RouterLink, ShareLinkFormComponent],
    templateUrl: './project-detail.component.html',
    styleUrl: './project-detail.component.scss',
    changeDetection: ChangeDetectionStrategy.OnPush,
})
export class ProjectDetailComponent {
    public readonly projectId = input.required({transform: numberAttribute});

    private readonly projectService = inject(ProjectService);
    private readonly shareService = inject(ShareService);
    private readonly alerts = inject(AlertService);
    private readonly router = inject(Router);
    private readonly fb = inject(FormBuilder).nonNullable;

    protected readonly project = signal<Project | null>(null);
    protected readonly versions = signal<ProjectVersion[]>([]);
    protected readonly shareLinks = signal<ShareLink[]>([]);
    /** Share link whose settings are being edited. */
    protected readonly editingShare = signal<number | null>(null);
    protected readonly shareUrl = shareUrl;
    protected readonly busy = signal(false);
    protected readonly editingDetails = signal(false);
    /** Floor being renamed, or 'new' while adding one. */
    protected readonly editingFloor = signal<number | 'new' | null>(null);

    protected readonly firstFloor = computed(() => this.project()?.floors[0] ?? null);

    protected readonly detailsForm = this.fb.group({
        name: ['', Validators.required],
        address: [''],
        description: [''],
    });
    protected readonly floorForm = this.fb.group({
        name: ['', Validators.required],
        level: [0, Validators.required],
    });
    protected readonly versionForm = this.fb.group({
        note: [''],
    });

    public constructor() {
        effect(() => void this.load(this.projectId()));

        // Floors and versions changed elsewhere (another tab, an agent) show up without a reload.
        const realtime = inject(RealtimeService);
        toObservable(this.projectId)
            .pipe(
                switchMap((projectId) => realtime.projectUpdates(projectId)),
                filter(
                    (update) =>
                        update.type === 'projectChanged' &&
                        update.originClientId !== originClientId &&
                        update.revision > (this.project()?.revision ?? -1),
                ),
                takeUntilDestroyed(),
            )
            .subscribe((update) => void this.load(update.projectId));
    }

    protected editDetails(project: Project): void {
        this.detailsForm.setValue({name: project.name, address: project.address ?? '', description: project.description ?? ''});
        this.editingDetails.set(true);
    }

    protected async saveDetails(project: Project): Promise<void> {
        const {name, address, description} = this.detailsForm.getRawValue();
        await this.run(async () => {
            this.project.set(await this.projectService.update(project.id, {name, address: address || null, description: description || null}));
            this.editingDetails.set(false);
        });
    }

    protected async deleteProject(project: Project): Promise<void> {
        if (!confirm(`Delete project "${project.name}" with all its floors and versions? This cannot be undone.`)) {
            return;
        }
        await this.run(async () => {
            await this.projectService.delete(project.id);
            this.alerts.success(`Project "${project.name}" was deleted.`);
            await this.router.navigate(['/projects']);
        });
    }

    protected startFloor(floor: Floor | null): void {
        const levels = this.project()?.floors.map((f) => f.level) ?? [0];
        this.floorForm.setValue(floor === null ? {name: '', level: Math.max(...levels) + 1} : {name: floor.name, level: floor.level});
        this.editingFloor.set(floor?.id ?? 'new');
    }

    protected async saveFloor(project: Project): Promise<void> {
        const editing = this.editingFloor();
        const floorInput = this.floorForm.getRawValue();
        await this.run(async () => {
            if (editing === 'new') {
                await this.projectService.createFloor(project.id, floorInput);
            } else if (editing !== null) {
                await this.projectService.updateFloor(project.id, editing, floorInput);
            }
            this.editingFloor.set(null);
            await this.load(project.id);
        });
    }

    protected async deleteFloor(project: Project, floor: Floor): Promise<void> {
        if (!confirm(`Delete floor "${floor.name}" with everything on it?`)) {
            return;
        }
        await this.run(async () => {
            await this.projectService.deleteFloor(project.id, floor.id);
            await this.load(project.id);
        });
    }

    protected async saveVersion(project: Project): Promise<void> {
        await this.run(async () => {
            const version = await this.projectService.saveVersion(project.id, this.versionForm.getRawValue().note || null);
            this.versionForm.reset();
            this.alerts.success(`Saved version ${version.number}.`);
            await this.load(project.id);
        });
    }

    protected async viewVersion(project: Project, version: ProjectVersion): Promise<void> {
        await this.run(async () => {
            const detail = await this.projectService.getVersion(project.id, version.id);
            await this.router.navigate(['/projects', project.id, 'floors', detail.floors[0].id], {queryParams: {version: version.id}});
        });
    }

    protected async restoreVersion(project: Project, version: ProjectVersion): Promise<void> {
        if (!confirm(`Replace the current floors with version ${version.number}? The current state is saved as a new version first.`)) {
            return;
        }
        await this.run(async () => {
            const backup = await this.projectService.restoreVersion(project.id, version.id);
            this.alerts.success(`Restored version ${version.number}. The previous state is version ${backup.number}.`);
            await this.load(project.id);
        });
    }

    protected async copyShareLink(link: ShareLink): Promise<void> {
        try {
            await navigator.clipboard.writeText(shareUrl(link.token));
            this.alerts.success('Link copied.');
        } catch {
            this.alerts.error('Could not copy the link.');
        }
    }

    protected onShareSaved(link: ShareLink): void {
        this.shareLinks.update((links) => links.map((l) => (l.id === link.id ? link : l)));
        this.editingShare.set(null);
    }

    protected async refreshShareLink(project: Project, link: ShareLink): Promise<void> {
        if (!confirm(`Update "${link.name}" to the floor as it is now? It saves a new version; the link stays the same.`)) {
            return;
        }
        await this.run(async () => {
            const refreshed = await this.shareService.refresh(project.id, link.id);
            this.shareLinks.update((links) => links.map((l) => (l.id === refreshed.id ? refreshed : l)));
            this.versions.set(await this.projectService.listVersions(project.id));
            this.alerts.success(`"${refreshed.name}" now shows version ${refreshed.versionNumber}.`);
        });
    }

    protected async deleteShareLink(project: Project, link: ShareLink): Promise<void> {
        if (!confirm(`Delete the share link "${link.name}"? Anyone who has it loses access. Its version stays.`)) {
            return;
        }
        await this.run(async () => {
            await this.shareService.delete(project.id, link.id);
            this.shareLinks.update((links) => links.filter((l) => l.id !== link.id));
        });
    }

    private async load(projectId: number): Promise<void> {
        try {
            const [project, versions, shareLinks] = await Promise.all([
                this.projectService.get(projectId),
                this.projectService.listVersions(projectId),
                this.shareService.list(projectId),
            ]);
            this.project.set(project);
            this.versions.set(versions);
            this.shareLinks.set(shareLinks);
        } catch {
            // The error interceptor shows the toast.
        }
    }

    /** Runs one user action at a time; failures are already shown by the error interceptor. */
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
