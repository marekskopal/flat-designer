import {DecimalPipe} from '@angular/common';
import {ChangeDetectionStrategy, Component, effect, inject, input, signal} from '@angular/core';
import {RouterLink} from '@angular/router';
import {RoomSchedule} from '@app/models/room-schedule';
import {ProjectService} from '@app/services/project.service';

/**
 * The project's room schedule: per floor the rooms' net area, perimeter, clear height and volume, with the
 * indoor and outdoor (terrace, garden) totals. Reloads when the project's `revision` changes.
 */
@Component({
    selector: 'fd-room-schedule',
    imports: [DecimalPipe, RouterLink],
    templateUrl: './room-schedule.component.html',
    styleUrl: './room-schedule.component.scss',
    changeDetection: ChangeDetectionStrategy.OnPush,
})
export class RoomScheduleComponent {
    public readonly projectId = input.required<number>();
    public readonly revision = input.required<number>();

    protected readonly schedule = signal<RoomSchedule | null>(null);

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
            const schedule = await this.projects.roomSchedule(projectId);
            if (load === this.loads) {
                this.schedule.set(schedule);
            }
        } catch {
            // The error interceptor has told the user.
        }
    }
}
