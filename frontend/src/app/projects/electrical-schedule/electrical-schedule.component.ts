import {DecimalPipe} from '@angular/common';
import {ChangeDetectionStrategy, Component, computed, effect, inject, input, signal} from '@angular/core';
import {levelText} from '@app/core/level-text';
import {ElectricalSchedule, FloorElectrical} from '@app/models/electrical-schedule';
import {ProjectService} from '@app/services/project.service';

import {CategoryLabels, rowValues, totalsSummary} from './electrical-labels';

/**
 * The project's electrical schedule for the electrician: per floor and room the lights, sockets, switches, data
 * sockets and plumbing points with their parameter values and elevations. Rooms without points are left out.
 * Reloads when the project's `revision` changes.
 */
@Component({
    selector: 'fd-electrical-schedule',
    imports: [DecimalPipe],
    templateUrl: './electrical-schedule.component.html',
    styleUrl: './electrical-schedule.component.scss',
    changeDetection: ChangeDetectionStrategy.OnPush,
})
export class ElectricalScheduleComponent {
    public readonly projectId = input.required<number>();
    public readonly revision = input.required<number>();

    protected readonly schedule = signal<ElectricalSchedule | null>(null);
    /** The floors with points, each with only its rooms that have some. */
    protected readonly floors = computed<FloorElectrical[]>(() =>
        (this.schedule()?.floors ?? [])
            .map((floor) => ({...floor, rooms: floor.rooms.filter((room) => room.rows.length > 0)}))
            .filter((floor) => floor.rooms.length > 0),
    );
    protected readonly categoryLabels = CategoryLabels;
    protected readonly rowValues = rowValues;
    protected readonly totalsSummary = totalsSummary;
    protected readonly levelText = levelText;

    private readonly projects = inject(ProjectService);
    /** Bumped by every load; an older request that comes back late is dropped. */
    private loads = 0;

    public constructor() {
        effect(() => {
            this.revision();
            void this.load(this.projectId());
        });
    }

    private async load(projectId: number): Promise<void> {
        const load = ++this.loads;
        try {
            const schedule = await this.projects.electricalSchedule(projectId);
            if (load === this.loads) {
                this.schedule.set(schedule);
            }
        } catch {
            // The error interceptor has told the user.
        }
    }
}
