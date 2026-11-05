import {DecimalPipe} from '@angular/common';
import {ChangeDetectionStrategy, Component, computed, inject, input} from '@angular/core';
import {levelText} from '@app/core/level-text';
import {revisionLoader} from '@app/core/revision-loader';
import {roomCodeText} from '@app/core/room-code';
import {FloorElectrical} from '@app/models/electrical-schedule';
import {ProjectService} from '@app/services/project.service';
import {TranslatePipe} from '@ngx-translate/core';

import {circuitItemText, rowValues, totalsSummary} from './electrical-labels';

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
    protected readonly circuitItemText = circuitItemText;
    protected readonly rowValues = rowValues;
    protected readonly totalsSummary = totalsSummary;
    protected readonly levelText = levelText;
    protected readonly roomCodeText = roomCodeText;

}
