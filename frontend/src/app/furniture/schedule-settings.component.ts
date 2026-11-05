import {ChangeDetectionStrategy, Component, computed, input, output} from '@angular/core';
import {CategoryLabels, ElectricalCategory} from '@app/models/electrical-schedule';
import {FurnitureCatalogue, FurnitureCatalogues, FurnitureDefinitionBody} from '@app/models/furniture';
import {scheduleCount} from '@app/viewer/engine/electrical';
import {TranslatePipe} from '@ngx-translate/core';

import {countParameters, toggledValue, valueParameters, withCatalogue, withCategory, withCount, withValues} from './schedule-settings';

/**
 * The definition's settings picked above its JSON: its `catalogue` (where the Add library files it), and its
 * `category` and `schedule` settings for the electrical schedule: what its items count as, the int parameter
 * multiplying an item and the parameters the schedule lists. A change emits the
 * whole definition, which the editor writes back into the JSON.
 */
@Component({
    selector: 'fd-schedule-settings',
    imports: [TranslatePipe],
    templateUrl: './schedule-settings.component.html',
    styleUrl: './schedule-settings.component.scss',
    changeDetection: ChangeDetectionStrategy.OnPush,
})
export class ScheduleSettingsComponent {
    public readonly body = input.required<FurnitureDefinitionBody>();
    public readonly readOnly = input(false);
    public readonly bodyChange = output<FurnitureDefinitionBody>();

    protected readonly catalogues = FurnitureCatalogues;
    protected readonly categories = Object.keys(CategoryLabels) as ElectricalCategory[];
    protected readonly countOptions = computed(() => countParameters(this.body()));
    /** What an older definition without settings counts by (a socket's gangs, `scheduleCount()`); null once it has them. */
    protected readonly defaultCount = computed(() => (this.body().schedule === undefined ? scheduleCount(this.body()) : null));
    protected readonly valueOptions = computed(() => valueParameters(this.body()));
    /** The parameters the schedule lists: all of them without `schedule.values`. */
    protected readonly listed = computed(() => new Set(this.body().schedule?.values ?? this.valueOptions().map((p) => p.key)));

    protected setCatalogue(event: Event): void {
        const value = (event.target as HTMLSelectElement).value;
        this.bodyChange.emit(withCatalogue(this.body(), value === '' ? null : (value as FurnitureCatalogue)));
    }

    protected setCategory(event: Event): void {
        const value = (event.target as HTMLSelectElement).value;
        this.bodyChange.emit(withCategory(this.body(), value === '' ? null : (value as ElectricalCategory)));
    }

    protected setCount(event: Event): void {
        const value = (event.target as HTMLSelectElement).value;
        this.bodyChange.emit(withCount(this.body(), value === '' ? null : value));
    }

    protected toggleValue(key: string, event: Event): void {
        const values = toggledValue(this.body(), key, (event.target as HTMLInputElement).checked);
        // Every one listed again is the default.
        this.bodyChange.emit(withValues(this.body(), values.length === this.valueOptions().length ? null : values));
    }
}
