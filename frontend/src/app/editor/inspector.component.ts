import {ChangeDetectionStrategy, Component, computed, input, output, signal} from '@angular/core';
import {Material, Room} from '@app/models/scene';
import {ElementKind} from '@app/viewer/engine/selection';

import {ElementFields, FieldDef, formatFieldValue, isChanged, parseFieldValue} from './element-fields';

export interface FieldChange {
    key: string;
    value: unknown;
}

/**
 * Form for the selected element, built from `ElementFields`. Each field commits on its own (change event:
 * blur, Enter, picking an option) when the value is valid and differs from the element's.
 */
@Component({
    selector: 'fd-inspector',
    templateUrl: './inspector.component.html',
    styleUrl: './inspector.component.scss',
    changeDetection: ChangeDetectionStrategy.OnPush,
})
export class InspectorComponent {
    public readonly kind = input.required<ElementKind>();
    public readonly element = input.required<Record<string, unknown>>();
    public readonly materials = input<Material[]>([]);
    public readonly rooms = input<Room[]>([]);
    public readonly disabled = input(false);

    public readonly fieldChange = output<FieldChange>();

    protected readonly fields = computed(() => ElementFields[this.kind()]);
    protected readonly errors = signal<Record<string, string>>({});

    protected value(def: FieldDef): string {
        return formatFieldValue(def, this.element()[def.key]);
    }

    protected commit(def: FieldDef, event: Event): void {
        const target = event.target as HTMLInputElement | HTMLSelectElement | HTMLTextAreaElement;
        const parsed = parseFieldValue(def, target.value);
        if (!parsed.ok) {
            this.errors.update((all) => ({...all, [def.key]: parsed.error}));
            return;
        }
        this.errors.update((all) => {
            const {[def.key]: _removed, ...rest} = all;
            return rest;
        });
        if (isChanged(this.element()[def.key], parsed.value)) {
            this.fieldChange.emit({key: def.key, value: parsed.value});
        }
    }
}
