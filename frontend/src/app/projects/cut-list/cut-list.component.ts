import {DecimalPipe} from '@angular/common';
import {ChangeDetectionStrategy, Component, computed, effect, inject, input, numberAttribute, signal} from '@angular/core';
import {RouterLink} from '@angular/router';
import {CutList, CutListBoard} from '@app/models/cut-list';
import {Project} from '@app/models/project';
import {FurnitureService} from '@app/services/furniture.service';
import {ProjectService} from '@app/services/project.service';
import {TranslatePipe, TranslateService} from '@ngx-translate/core';

/** The printable cut list of a project's Custom furniture (all floors or one): boards by material, other parts, totals. */
@Component({
    selector: 'fd-cut-list',
    imports: [DecimalPipe, RouterLink, TranslatePipe],
    templateUrl: './cut-list.component.html',
    styleUrl: './cut-list.component.scss',
    changeDetection: ChangeDetectionStrategy.OnPush,
})
export class CutListComponent {
    public readonly projectId = input.required({transform: numberAttribute});

    private readonly projects = inject(ProjectService);
    private readonly translate = inject(TranslateService);
    private readonly furniture = inject(FurnitureService);
    /** The catalogue's names in the language shown, by id: the list's names come from the server in English. */
    private readonly materialNames = signal(new Map<number, string>());

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
        void this.furniture.materials().then((all) => this.materialNames.set(new Map(all.map((m) => [m.id, m.name]))), () => undefined);
        effect(() => void this.loadProject(this.projectId()));
        effect(() => void this.load(this.projectId(), this.floorId()));
    }

    protected setFloor(event: Event): void {
        const value = (event.target as HTMLSelectElement).value;
        this.floorId.set(value === '' ? null : Number(value));
    }

    protected edges(board: CutListBoard): string {
        return board.edges.length === 0 ? '—' : board.edges.map((edge) => this.known(`cutList.edges.${edge}`, edge)).join(', ');
    }

    /** A part type's name; the code itself for a type without one. */
    protected partType(type: string): string {
        return this.known(`cutList.partTypes.${type}`, type);
    }

    protected print(): void {
        window.print();
    }

    private known(key: string, fallback: string): string {
        const text = this.translate.instant(key) as string;
        return text === key ? fallback : text;
    }

    protected materialName(row: {materialId: number | null; material: string}): string {
        return (row.materialId === null ? undefined : this.materialNames().get(row.materialId)) ?? row.material;
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
