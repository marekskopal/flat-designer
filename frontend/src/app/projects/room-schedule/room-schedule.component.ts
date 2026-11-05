import {DecimalPipe} from '@angular/common';
import {ChangeDetectionStrategy, Component, computed, inject, input, LOCALE_ID} from '@angular/core';
import {RouterLink} from '@angular/router';
import {levelText} from '@app/core/level-text';
import {decimalSeparator, fixed} from '@app/core/number-format';
import {revisionLoader} from '@app/core/revision-loader';
import {roomCodeText} from '@app/core/room-code';
import {RoomScheduleRow} from '@app/models/room-schedule';
import {ProjectService} from '@app/services/project.service';
import {PluralPipe} from '@app/shared/i18n/plural.pipe';
import {TranslatePipe} from '@ngx-translate/core';

/**
 * The project's room schedule: per floor the rooms' net area, perimeter, clear height and volume, with the
 * indoor, garage and outdoor (terrace, garden) totals. Rooms under a roof show their clear height's range, and a column their
 * area with headroom. Reloads when the project's `revision` changes.
 */
@Component({
    selector: 'fd-room-schedule',
    imports: [DecimalPipe, PluralPipe, RouterLink, TranslatePipe],
    templateUrl: './room-schedule.component.html',
    styleUrl: './room-schedule.component.scss',
    changeDetection: ChangeDetectionStrategy.OnPush,
})
export class RoomScheduleComponent {
    public readonly projectId = input.required<number>();
    public readonly revision = input.required<number>();

    private readonly projects = inject(ProjectService);
    private readonly decimal = decimalSeparator(inject(LOCALE_ID));

    protected readonly schedule = revisionLoader(this.projectId, this.revision, (id) => this.projects.roomSchedule(id));
    protected readonly roomCodeText = roomCodeText;
    /** Whether a room lies under a roof: the area with headroom gets a column. */
    protected readonly underRoofs = computed(() => (this.schedule()?.floors ?? []).some((f) => f.rooms.some((r) => r.headroomArea !== null)));
    protected readonly columns = computed(() => (this.underRoofs() ? 6 : 5));

    /** Metres, as the PDF's schedule writes them; a range under a roof (from the eaves). */
    protected clearHeight(room: RoomScheduleRow): string {
        if (room.clearHeight === null) {
            return '–';
        }
        const metres = (mm: number): string => fixed(mm / 1000, 2, this.decimal);
        const low = room.minClearHeight !== null && room.minClearHeight !== room.clearHeight ? `${metres(room.minClearHeight)}–` : '';
        return low + metres(room.clearHeight);
    }

    protected levelText(mm: number): string {
        return levelText(mm, this.decimal);
    }
}
