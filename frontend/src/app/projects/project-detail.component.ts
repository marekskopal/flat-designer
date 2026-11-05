import {DecimalPipe, NgTemplateOutlet} from '@angular/common';
import {ChangeDetectionStrategy, Component, computed, effect, inject, input, LOCALE_ID, numberAttribute, signal} from '@angular/core';
import {takeUntilDestroyed, toObservable} from '@angular/core/rxjs-interop';
import {FormBuilder, ReactiveFormsModule, Validators} from '@angular/forms';
import {Router, RouterLink} from '@angular/router';
import {originClientId} from '@app/core/interceptors/origin.interceptor';
import {levelText} from '@app/core/level-text';
import {decimalSeparator} from '@app/core/number-format';
import {downloadBlob, safeFilename} from '@app/export/download';
import {Project, ProjectVersion} from '@app/models/project';
import {Floor, Material} from '@app/models/scene';
import {ShareLink, shareUrl} from '@app/models/share';
import {ElectricalScheduleComponent} from '@app/projects/electrical-schedule/electrical-schedule.component';
import {ProjectFilesComponent} from '@app/projects/files/project-files.component';
import {RoomScheduleComponent} from '@app/projects/room-schedule/room-schedule.component';
import {AlertService} from '@app/services/alert.service';
import {FurnitureService} from '@app/services/furniture.service';
import {ProjectService} from '@app/services/project.service';
import {RealtimeService} from '@app/services/realtime.service';
import {ShareService} from '@app/services/share.service';
import {ShareLinkFormComponent} from '@app/share/share-link-form.component';
import {DateTextPipe} from '@app/shared/i18n/date-text.pipe';
import {TranslatePipe, TranslateService} from '@ngx-translate/core';
import {filter, switchMap} from 'rxjs';

/** One project: its details, floors (the working copy), room and electrical schedules, saved versions, share links and files. */
@Component({
    selector: 'fd-project-detail',
    imports: [
        DateTextPipe,
        DecimalPipe,
        ElectricalScheduleComponent,
        NgTemplateOutlet,
        ProjectFilesComponent,
        ReactiveFormsModule,
        RoomScheduleComponent,
        RouterLink,
        ShareLinkFormComponent,
        TranslatePipe,
    ],
    templateUrl: './project-detail.component.html',
    styleUrl: './project-detail.component.scss',
    changeDetection: ChangeDetectionStrategy.OnPush,
})
export class ProjectDetailComponent {
    private readonly translate = inject(TranslateService);
    public readonly projectId = input.required({transform: numberAttribute});

    private readonly projectService = inject(ProjectService);
    private readonly furnitureService = inject(FurnitureService);
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
    /** A project file being prepared for download. */
    protected readonly exporting = signal(false);
    protected readonly editingDetails = signal(false);
    /** Floor being renamed, or 'new' while adding one. */
    protected readonly editingFloor = signal<number | 'new' | null>(null);

    protected readonly firstFloor = computed(() => this.project()?.floors[0] ?? null);

    protected readonly detailsForm = this.fb.group({
        name: ['', Validators.required],
        address: [''],
        description: [''],
        northAngle: [0, [Validators.required, Validators.min(0), Validators.max(359)]],
        latitude: [null as number | null, [Validators.min(-90), Validators.max(90)]],
        longitude: [null as number | null, [Validators.min(-180), Validators.max(180)]],
        timeZone: [''],
        exteriorMaterialId: [null as number | null],
    });
    /** The materials a project's façade may be in (loaded with the details form). */
    protected readonly materials = signal<Material[]>([]);
    /** The time zones the browser knows, offered for the site's time zone. */
    protected readonly timeZones = Intl.supportedValuesOf('timeZone');
    protected readonly floorForm = this.fb.group({
        name: ['', Validators.required],
        level: [0, Validators.required],
        /** Null stacks a new floor on the one below. */
        elevation: [null as number | null],
        slabThickness: [null as number | null, [Validators.min(0), Validators.max(1000)]],
    });
    protected readonly versionForm = this.fb.group({
        note: [''],
    });

    public constructor() {
        effect(() => void this.load(this.projectId()));

        // Floors, versions and details changed elsewhere (another tab, an agent) show up without a reload.
        const realtime = inject(RealtimeService);
        toObservable(this.projectId)
            .pipe(
                switchMap((projectId) => realtime.projectUpdates(projectId)),
                filter(
                    (update) =>
                        update.originClientId !== originClientId &&
                        (update.type === 'detailsChanged' ||
                            (update.type === 'projectChanged' && update.revision > (this.project()?.revision ?? -1))),
                ),
                takeUntilDestroyed(),
            )
            .subscribe((update) => void (update.type === 'detailsChanged' ? this.loadDetails(update.projectId) : this.load(update.projectId)));
    }

    protected editDetails(project: Project): void {
        this.detailsForm.setValue({
            name: project.name,
            address: project.address ?? '',
            description: project.description ?? '',
            northAngle: project.northAngle,
            latitude: project.latitude,
            longitude: project.longitude,
            timeZone: project.timeZone ?? '',
            exteriorMaterialId: project.exteriorMaterialId ?? null,
        });
        this.editingDetails.set(true);
        if (this.materials().length === 0) {
            void this.furnitureService.materials().then((materials) => this.materials.set(materials));
        }
    }

    protected async saveDetails(project: Project): Promise<void> {
        const {name, address, description, northAngle, latitude, longitude, timeZone, exteriorMaterialId} = this.detailsForm.getRawValue();
        await this.run(async () => {
            this.project.set(
                await this.projectService.update(project.id, {
                    name,
                    address: address || null,
                    description: description || null,
                    northAngle: northAngle ?? 0,
                    latitude,
                    longitude,
                    timeZone: timeZone || null,
                    exteriorMaterialId,
                }),
            );
            this.editingDetails.set(false);
        });
    }

    /** Saves the project as a project file: a zip with its files and models, or the JSON alone. */
    protected async downloadProject(project: Project, withFiles: boolean): Promise<void> {
        this.exporting.set(true);
        try {
            const blob = await this.projectService.exportProject(project.id, withFiles);
            downloadBlob(blob, `${safeFilename(project.name) || 'project'}.izbik.${withFiles ? 'zip' : 'json'}`);
        } catch {
            // The error interceptor shows the toast.
        } finally {
            this.exporting.set(false);
        }
    }

    protected async deleteProject(project: Project): Promise<void> {
        if (!confirm(this.translate.instant('project.confirmDelete', {name: project.name}))) {
            return;
        }
        await this.run(async () => {
            await this.projectService.delete(project.id);
            this.alerts.success(this.translate.instant('project.deleted', {name: project.name}));
            await this.router.navigate(['/projects']);
        });
    }

    private readonly decimal = decimalSeparator(inject(LOCALE_ID));

    /** A floor's level in the page's language (`+3,000` in Czech). */
    protected levelText(mm: number): string {
        return levelText(mm, this.decimal);
    }

    protected startFloor(floor: Floor | null): void {
        const levels = this.project()?.floors.map((f) => f.level) ?? [0];
        this.floorForm.setValue(
            floor === null
                ? {name: '', level: Math.max(...levels) + 1, elevation: null, slabThickness: null}
                : {name: floor.name, level: floor.level, elevation: floor.elevation, slabThickness: floor.slabThickness},
        );
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
        if (!confirm(this.translate.instant('project.floors.confirmDelete', {name: floor.name}))) {
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
            this.alerts.success(this.translate.instant('project.versions.saved', {number: version.number}));
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
        if (!confirm(this.translate.instant('project.versions.confirmRestore', {number: version.number}))) {
            return;
        }
        await this.run(async () => {
            const backup = await this.projectService.restoreVersion(project.id, version.id);
            this.alerts.success(this.translate.instant('project.versions.restored', {number: version.number, backup: backup.number}));
            await this.load(project.id);
        });
    }

    protected async copyShareLink(link: ShareLink): Promise<void> {
        try {
            await navigator.clipboard.writeText(shareUrl(link.token));
            this.alerts.success(this.translate.instant('project.shares.copied'));
        } catch {
            this.alerts.error(this.translate.instant('project.shares.copyFailed'));
        }
    }

    protected onShareSaved(link: ShareLink): void {
        this.shareLinks.update((links) => links.map((l) => (l.id === link.id ? link : l)));
        this.editingShare.set(null);
    }

    protected async refreshShareLink(project: Project, link: ShareLink): Promise<void> {
        if (!confirm(this.translate.instant('project.shares.confirmUpdate', {name: link.name}))) {
            return;
        }
        await this.run(async () => {
            const refreshed = await this.shareService.refresh(project.id, link.id);
            this.shareLinks.update((links) => links.map((l) => (l.id === refreshed.id ? refreshed : l)));
            this.versions.set(await this.projectService.listVersions(project.id));
            this.alerts.success(this.translate.instant('project.shares.updated', {name: refreshed.name, number: refreshed.versionNumber}));
        });
    }

    protected async deleteShareLink(project: Project, link: ShareLink): Promise<void> {
        if (!confirm(this.translate.instant('project.shares.confirmDelete', {name: link.name}))) {
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

    private async loadDetails(projectId: number): Promise<void> {
        try {
            this.project.set(await this.projectService.get(projectId));
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
