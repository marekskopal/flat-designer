import {DecimalPipe} from '@angular/common';
import {ChangeDetectionStrategy, Component, computed, effect, inject, input, numberAttribute, signal} from '@angular/core';
import {RouterLink} from '@angular/router';
import {CutList, CutListBoard} from '@app/models/cut-list';
import {Project} from '@app/models/project';
import {ProjectService} from '@app/services/project.service';

/** The printable cut list of a project's Custom furniture (all floors or one): boards by material, other parts, totals. */
@Component({
    selector: 'fd-cut-list',
    imports: [DecimalPipe, RouterLink],
    templateUrl: './cut-list.component.html',
    styleUrl: './cut-list.component.scss',
    changeDetection: ChangeDetectionStrategy.OnPush,
})
export class CutListComponent {
    public readonly projectId = input.required({transform: numberAttribute});

    private readonly projects = inject(ProjectService);

    protected readonly project = signal<Project | null>(null);
    protected readonly floorId = signal<number | null>(null);
    protected readonly list = signal<CutList | null>(null);
    protected readonly loading = signal(true);
    /** The boards of each material, in the server's order. */
    protected readonly materials = computed(() => {
        const list = this.list();
        if (list === null) {
            return [];
        }
        return list.materials.map((total) => ({
            ...total,
            boards: list.boards.filter((b) => b.material === total.material && b.materialId === total.materialId),
        }));
    });

    public constructor() {
        effect(() => void this.loadProject(this.projectId()));
        effect(() => void this.load(this.projectId(), this.floorId()));
    }

    protected setFloor(event: Event): void {
        const value = (event.target as HTMLSelectElement).value;
        this.floorId.set(value === '' ? null : Number(value));
    }

    protected edges(board: CutListBoard): string {
        return board.edges.length === 0 ? '—' : board.edges.join(', ');
    }

    protected print(): void {
        window.print();
    }

    private async loadProject(projectId: number): Promise<void> {
        try {
            this.project.set(await this.projects.get(projectId));
        } catch {
            // The error interceptor shows the toast.
        }
    }

    private async load(projectId: number, floorId: number | null): Promise<void> {
        this.loading.set(true);
        try {
            this.list.set(await this.projects.cutList(projectId, floorId ?? undefined));
        } catch {
            // The error interceptor shows the toast.
        } finally {
            this.loading.set(false);
        }
    }
}
