import {ChangeDetectionStrategy, Component, computed, input, output, signal} from '@angular/core';
import {Material, Room} from '@app/models/scene';
import {ElementKind} from '@app/viewer/engine/selection';

import {CabinetParamsComponent} from './cabinet-params.component';
import {ElementFields, FieldDef, formatFieldValue, isChanged, parseFieldValue} from './element-fields';
import {WardrobeParamsComponent} from './wardrobe-params.component';

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
    imports: [CabinetParamsComponent, WardrobeParamsComponent],
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

    protected readonly fields = computed(() => ElementFields[this.kind()].filter((def) => def.when?.(this.element()) ?? true));
    protected readonly errors = signal<Record<string, string>>({});

    protected value(def: FieldDef): string {
        return formatFieldValue(def, this.element()[def.key]);
    }

    /** The element's width (mm), which sets the wardrobe's default door count. */
    protected width(): number {
        const value = this.element()['width'];
        return typeof value === 'number' ? value : 0;
    }

    /** Current params of the element for the cabinet and wardrobe forms. */
    protected params(def: FieldDef): Record<string, unknown> {
        const value = this.element()[def.key];
        return typeof value === 'object' && value !== null && !Array.isArray(value) ? (value as Record<string, unknown>) : {};
    }

    protected commitValue(def: FieldDef, value: unknown): void {
        if (isChanged(this.element()[def.key], value)) {
            this.fieldChange.emit({key: def.key, value});
        }
    }

    protected commit(def: FieldDef, event: Event): void {
        const target = event.target as HTMLInputElement | HTMLSelectElement | HTMLTextAreaElement;
        const raw = target instanceof HTMLInputElement && target.type === 'checkbox' ? String(target.checked) : target.value;
        const parsed = parseFieldValue(def, raw);
        if (!parsed.ok) {
            this.errors.update((all) => ({...all, [def.key]: parsed.error}));
            return;
        }
        this.errors.update((all) => {
            const {[def.key]: _removed, ...rest} = all;
            return rest;
        });
        this.commitValue(def, parsed.value);
    }
}
