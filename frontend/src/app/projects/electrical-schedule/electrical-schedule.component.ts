import {DecimalPipe} from '@angular/common';
import {ChangeDetectionStrategy, Component, computed, inject, input, LOCALE_ID} from '@angular/core';
import {levelText} from '@app/core/level-text';
import {decimalSeparator} from '@app/core/number-format';
import {revisionLoader} from '@app/core/revision-loader';
import {roomCodeText} from '@app/core/room-code';
import {CircuitItem, ElectricalRow, ElectricalTotals, FloorElectrical} from '@app/models/electrical-schedule';
import {ProjectService} from '@app/services/project.service';
import {pluralText} from '@app/shared/i18n/plural.pipe';
import {TranslatePipe, TranslateService} from '@ngx-translate/core';

import {circuitItemText, ElectricalTexts, rowValues, totalsSummary} from './electrical-labels';

/**
 * The project's electrical schedule for the electrician: per floor and room the lights, sockets, switches, data
 * sockets and plumbing points with their parameter values and elevations, then the lighting circuits (which switches
 * control which lights) of the floors that have any. Rooms without points are left out.
 * Reloads when the project's `revision` changes.
 */
@Component({
    selector: 'fd-electrical-schedule',
    imports: [DecimalPipe, TranslatePipe],
    templateUrl: './electrical-schedule.component.html',
    styleUrl: './electrical-schedule.component.scss',
    changeDetection: ChangeDetectionStrategy.OnPush,
})
export class ElectricalScheduleComponent {
    public readonly projectId = input.required<number>();
    public readonly revision = input.required<number>();

    private readonly projects = inject(ProjectService);
    private readonly translate = inject(TranslateService);
    private readonly locale = inject(LOCALE_ID);
    private readonly texts: ElectricalTexts = {
        yes: this.translate.instant('schedules.electrical.yes') as string,
        no: this.translate.instant('schedules.electrical.no') as string,
        decimal: decimalSeparator(this.locale),
        count: (key, n) => pluralText(this.translate, this.locale, `schedules.electrical.counts.${key}`, n),
    };

    protected readonly schedule = revisionLoader(this.projectId, this.revision, (id) => this.projects.electricalSchedule(id));
    /** The floors with points, each with only its rooms that have some. */
    protected readonly floors = computed<FloorElectrical[]>(() =>
        (this.schedule()?.floors ?? [])
            .map((floor) => ({...floor, rooms: floor.rooms.filter((room) => room.rows.length > 0)}))
            .filter((floor) => floor.rooms.length > 0),
    );
    /** The floors whose switches have lights linked, for the lighting circuits. */
    protected readonly lightingFloors = computed<FloorElectrical[]>(() =>
        (this.schedule()?.floors ?? []).filter((floor) => floor.lighting.circuits.length > 0),
    );
    protected readonly roomCodeText = roomCodeText;

    protected circuitItemText(item: CircuitItem, withElevation: boolean): string {
        return circuitItemText(item, withElevation, this.texts.decimal);
    }

    protected rowValues(row: ElectricalRow): string {
        return rowValues(row, this.texts);
    }

    protected totalsSummary(totals: ElectricalTotals): string {
        return totalsSummary(totals, this.texts);
    }

    protected levelText(mm: number): string {
        return levelText(mm, this.texts.decimal);
    }
}
