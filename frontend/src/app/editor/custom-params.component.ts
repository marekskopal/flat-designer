import {ChangeDetectionStrategy, Component, computed, input, output, signal} from '@angular/core';
import {DefinitionParameter, FurnitureDefinitionBody} from '@app/models/furniture';
import {Material} from '@app/models/scene';

import {Params} from './cabinet-params';
import {customParamsOf, definitionError, withSlotMaterial, withValue} from './custom-params';

/**
 * Form for the params of a Custom item, generated from its furniture definition: an input per parameter (empty = the
 * default) and a material per slot (empty = the item material for the first slot, otherwise the slot's default). Shows
 * why the definition does not work out at the item's size. Every change emits the whole new params object.
 */
@Component({
    selector: 'fd-custom-params',
    templateUrl: './custom-params.component.html',
    styleUrl: './custom-params.component.scss',
    changeDetection: ChangeDetectionStrategy.OnPush,
})
export class CustomParamsComponent {
    public readonly definition = input.required<FurnitureDefinitionBody | null>();
    public readonly params = input.required<Params>();
    public readonly width = input.required<number>();
    public readonly depth = input.required<number>();
    public readonly height = input.required<number>();
    public readonly materials = input<Material[]>([]);
    public readonly disabled = input(false);

    public readonly paramsChange = output<Params>();

    protected readonly parameters = computed(() => this.definition()?.parameters ?? []);
    protected readonly slots = computed(() => {
        const slots = this.definition()?.materialSlots;
        return Array.isArray(slots) ? slots : [];
    });
    protected readonly current = computed(() => customParamsOf(this.params()));
    protected readonly problem = computed(() => {
        const definition = this.definition();
        return definition === null
            ? 'The furniture definition is not available.'
            : definitionError(definition, {width: this.width(), depth: this.depth(), height: this.height()}, this.params());
    });
    protected readonly inputError = signal<string | null>(null);

    protected label(parameter: DefinitionParameter): string {
        return parameter.label ?? parameter.key;
    }

    protected unit(parameter: DefinitionParameter): string | null {
        return parameter.type === 'int' ? (parameter.unit ?? null) : null;
    }

    protected value(parameter: DefinitionParameter): unknown {
        return this.current().values[parameter.key];
    }

    protected checked(parameter: DefinitionParameter): boolean {
        const value = this.value(parameter);
        return typeof value === 'boolean' ? value : parameter.default === true;
    }

    protected materialName(code: string | undefined): string {
        return this.materials().find((m) => m.code === code)?.name ?? code ?? 'item material';
    }

    protected setInt(parameter: DefinitionParameter, event: Event): void {
        const text = (event.target as HTMLInputElement).value.trim();
        if (text === '') {
            this.emit(withValue(this.params(), parameter.key, undefined));
            return;
        }
        if (!/^-?\d+$/.test(text)) {
            this.inputError.set(`${this.label(parameter)}: whole number expected.`);
            return;
        }
        this.emit(withValue(this.params(), parameter.key, Number(text)));
    }

    protected setBool(parameter: DefinitionParameter, event: Event): void {
        const checked = (event.target as HTMLInputElement).checked;
        this.emit(withValue(this.params(), parameter.key, checked === parameter.default ? undefined : checked));
    }

    protected setSelect(parameter: DefinitionParameter, event: Event): void {
        const value = (event.target as HTMLSelectElement).value;
        this.emit(withValue(this.params(), parameter.key, value === '' ? undefined : value));
    }

    protected setMaterial(slot: string, event: Event): void {
        const value = (event.target as HTMLSelectElement).value;
        this.emit(withSlotMaterial(this.params(), slot, value === '' ? undefined : Number(value)));
    }

    private emit(params: Params): void {
        this.inputError.set(null);
        if (JSON.stringify(params) !== JSON.stringify(this.params())) {
            this.paramsChange.emit(params);
        }
    }
}
