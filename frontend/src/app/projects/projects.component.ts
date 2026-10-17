import {DatePipe} from '@angular/common';
import {ChangeDetectionStrategy, Component, inject, OnInit, signal} from '@angular/core';
import {FormBuilder, ReactiveFormsModule, Validators} from '@angular/forms';
import {Router, RouterLink} from '@angular/router';
import {Project} from '@app/models/project';
import {CurrentUserService} from '@app/services/current-user.service';
import {ProjectService} from '@app/services/project.service';

/** The user's projects (all projects for admins) and creating a new one. */
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
    protected readonly form = inject(FormBuilder).nonNullable.group({
        name: ['', Validators.required],
        address: [''],
    });

    public async ngOnInit(): Promise<void> {
        try {
            this.projects.set(await this.projectService.list());
        } catch {
            // The error interceptor shows the toast.
        } finally {
            this.loading.set(false);
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
