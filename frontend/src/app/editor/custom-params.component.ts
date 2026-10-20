import {ChangeDetectionStrategy, Component, computed, input, output, signal} from '@angular/core';
import {DefinitionField, DefinitionParameter, FurnitureDefinitionBody} from '@app/models/furniture';
import {ProjectFile} from '@app/models/project';
import {Material} from '@app/models/scene';

import {Params} from './cabinet-params';
import {
    customParamsOf,
    definitionError,
    ListItem,
    listItems,
    ownListItems,
    parseWholeNumber,
    withItemAdded,
    withItemField,
    withItemMoved,
    withItemRemoved,
    withSlotMaterial,
    withValue,
} from './custom-params';

type ListParameter = Extract<DefinitionParameter, {type: 'list'}>;

/**
 * Form for the params of a Custom item, generated from its furniture definition: an input per parameter (empty = the
 * default), a list parameter as rows of its fields (add, remove, move; empty field = its default) and a material per
 * slot (empty = the slot's default, or the item material for the first slot and for a slot without a default). Shows why the definition does not
 * work out at the item's size. Every change emits the whole new params object.
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
    /** The project's image files, for `image` parameters. */
    public readonly images = input<ProjectFile[]>([]);
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
        const number = parseWholeNumber(text);
        if (number === null) {
            this.inputError.set(`${this.label(parameter)}: whole number expected.`);
            return;
        }
        this.emit(withValue(this.params(), parameter.key, number));
    }

    protected setBool(parameter: DefinitionParameter, event: Event): void {
        const checked = (event.target as HTMLInputElement).checked;
        this.emit(withValue(this.params(), parameter.key, checked === parameter.default ? undefined : checked));
    }

    /** An image parameter: a file id, or none (no picture). */
    protected setImage(parameter: DefinitionParameter, event: Event): void {
        const value = (event.target as HTMLSelectElement).value;
        this.emit(withValue(this.params(), parameter.key, value === '' ? undefined : Number(value)));
    }

    protected setSelect(parameter: DefinitionParameter, event: Event): void {
        const value = (event.target as HTMLSelectElement).value;
        this.emit(withValue(this.params(), parameter.key, value === '' ? undefined : value));
    }

    /** The items of a list parameter as shown: the item's list, or the definition's default. */
    protected items(parameter: ListParameter): ListItem[] {
        return listItems(this.params(), parameter.key, parameter.default);
    }

    /** Whether the item has its own list (not the definition's default). */
    protected hasOwnList(parameter: ListParameter): boolean {
        return ownListItems(this.params(), parameter.key) !== null;
    }

    /** The fields of a list parameter (none while the definition lacks them, as the preview shows the error). */
    protected fields(parameter: ListParameter): DefinitionField[] {
        return Array.isArray(parameter.fields) ? parameter.fields : [];
    }

    protected fieldLabel(field: DefinitionField): string {
        return field.type === 'int' && field.unit !== undefined ? `${field.label ?? field.key} (${field.unit})` : (field.label ?? field.key);
    }

    /** What an empty input of the field means: its default, or "auto" for an optional one without (shared, e.g. a height). */
    protected placeholder(field: DefinitionField): string {
        return field.default === undefined || field.default === null ? (field.optional === true ? 'auto' : '') : String(field.default);
    }

    /** A field of one item; an empty input, or a checkbox at the field's default, drops the field (its default applies). */
    protected setItemField(parameter: ListParameter, index: number, field: DefinitionField, event: Event): void {
        const target = event.target as HTMLInputElement | HTMLSelectElement;
        const items = this.items(parameter);
        const text = target.value.trim();
        let value: unknown;
        if (field.type === 'bool') {
            const checked = (target as HTMLInputElement).checked;
            value = checked === field.default ? undefined : checked;
        } else if (text === '') {
            value = undefined;
        } else if (field.type === 'int') {
            value = parseWholeNumber(text);
            if (value === null) {
                this.inputError.set(`${this.fieldLabel(field)}: whole number expected.`);
                // Back to what the item has, so the rejected text cannot stay on a row that moves.
                target.value = String(items[index]?.[field.key] ?? '');
                return;
            }
        } else {
            value = text;
        }
        this.setList(parameter, withItemField(items, index, field.key, value));
    }

    protected addItem(parameter: ListParameter): void {
        this.setList(parameter, withItemAdded(this.items(parameter)));
    }

    protected removeItem(parameter: ListParameter, index: number): void {
        this.setList(parameter, withItemRemoved(this.items(parameter), index));
    }

    protected moveItem(parameter: ListParameter, index: number, direction: -1 | 1): void {
        this.setList(parameter, withItemMoved(this.items(parameter), index, direction));
    }

    protected resetList(parameter: ListParameter): void {
        this.emit(withValue(this.params(), parameter.key, undefined));
    }

    protected checkedField(item: ListItem, field: DefinitionField): boolean {
        const value = item[field.key];
        return typeof value === 'boolean' ? value : field.default === true;
    }

    private setList(parameter: ListParameter, items: ListItem[]): void {
        this.emit(withValue(this.params(), parameter.key, items));
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
