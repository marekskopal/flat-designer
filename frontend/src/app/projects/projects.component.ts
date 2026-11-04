import {DatePipe} from '@angular/common';
import {ChangeDetectionStrategy, Component, inject, OnInit, signal} from '@angular/core';
import {FormBuilder, ReactiveFormsModule, Validators} from '@angular/forms';
import {Router, RouterLink} from '@angular/router';
import {Example, Project} from '@app/models/project';
import {CurrentUserService} from '@app/services/current-user.service';
import {ProjectService} from '@app/services/project.service';

/** The user's projects (all projects for admins), creating a new one and starting from an example. */
@Component({
    selector: 'fd-projects',
    imports: [DatePipe, ReactiveFormsModule, RouterLink],
    templateUrl: './projects.component.html',
    styleUrl: './projects.component.scss',
    changeDetection: ChangeDetectionStrategy.OnPush,
})
export class ProjectsComponent implements OnInit {
    protected readonly currentUser = inject(CurrentUserService);
    private readonly projectService = inject(ProjectService);
    private readonly router = inject(Router);

    protected readonly projects = signal<Project[]>([]);
    protected readonly loading = signal(true);
    protected readonly creating = signal(false);
    protected readonly saving = signal(false);
    protected readonly examples = signal<Example[]>([]);
    protected readonly showExamples = signal(false);
    /** The example being copied. */
    protected readonly copying = signal<string | null>(null);
    protected readonly form = inject(FormBuilder).nonNullable.group({
        name: ['', Validators.required],
        address: [''],
    });

    public async ngOnInit(): Promise<void> {
        void this.loadExamples();
        try {
            this.projects.set(await this.projectService.list());
        } catch {
            // The error interceptor shows the toast.
        } finally {
            this.loading.set(false);
        }
    }

    /** Copies the example into a new project and opens it at the example's start view. */
    protected async startFrom(example: Example): Promise<void> {
        this.copying.set(example.slug);
        try {
            const created = await this.projectService.createFromExample(example.slug);
            if (created.floorId === null) {
                await this.router.navigate(['/projects', created.project.id]);
            } else {
                const queryParams = created.viewId === null ? {} : {view: created.viewId};
                await this.router.navigate(['/projects', created.project.id, 'floors', created.floorId], {queryParams});
            }
        } catch {
            // The error interceptor shows the toast.
        } finally {
            this.copying.set(null);
        }
    }

    protected toggleExamples(): void {
        this.showExamples.update((shown) => !shown);
        this.creating.set(false);
    }

    private async loadExamples(): Promise<void> {
        try {
            this.examples.set(await this.projectService.examples());
        } catch {
            // The error interceptor shows the toast; the page works without examples.
        }
    }

    protected async create(): Promise<void> {
        if (this.form.invalid) {
            return;
        }
        this.saving.set(true);
        try {
            const {name, address} = this.form.getRawValue();
            const project = await this.projectService.create({name, address: address || null, description: null});
            await this.router.navigate(['/projects', project.id]);
        } catch {
            // The error interceptor shows the toast.
        } finally {
            this.saving.set(false);
        }
    }

    protected cancel(): void {
        this.form.reset();
        this.creating.set(false);
    }
}
