import {DecimalPipe} from '@angular/common';
import {ChangeDetectionStrategy, Component, computed, effect, inject, input, signal} from '@angular/core';
import {RouterLink} from '@angular/router';
import {levelText} from '@app/core/level-text';
import {RoomSchedule, RoomScheduleRow} from '@app/models/room-schedule';
import {ProjectService} from '@app/services/project.service';

/**
 * The project's room schedule: per floor the rooms' net area, perimeter, clear height and volume, with the
 * indoor and outdoor (terrace, garden) totals. Rooms under a roof show their clear height's range, and a column their
 * area with headroom. Reloads when the project's `revision` changes.
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
    protected readonly levelText = levelText;
    /** Whether a room lies under a roof: the area with headroom gets a column. */
    protected readonly underRoofs = computed(() => (this.schedule()?.floors ?? []).some((f) => f.rooms.some((r) => r.headroomArea !== null)));
    protected readonly columns = computed(() => (this.underRoofs() ? 6 : 5));

    private readonly projects = inject(ProjectService);
    /** Bumped by every load; an older request that comes back late is dropped. */
    private loads = 0;

    public constructor() {
        effect(() => {
            this.revision();
            void this.load(this.projectId());
        });
    }

    /** Metres, as the PDF's schedule writes them; a range under a roof (from the eaves). */
    protected clearHeight(room: RoomScheduleRow): string {
        if (room.clearHeight === null) {
            return '–';
        }
        const metres = (mm: number): string => (mm / 1000).toFixed(2);
        const low = room.minClearHeight !== null && room.minClearHeight !== room.clearHeight ? `${metres(room.minClearHeight)}–` : '';
        return low + metres(room.clearHeight);
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
