import {FurnitureDefinitionBody} from '@app/models/furniture';
import {evaluateDefinition, ItemSize} from '@app/viewer/engine/custom/definition';

export type Params = Record<string, unknown>;

/** The params of a Custom item: parameter values over the definition's defaults, and materials of its slots. */
export interface CustomParams {
    values: Record<string, unknown>;
    materials: Record<string, number>;
}

export function isCustom(element: Record<string, unknown>): boolean {
    return element['kind'] === 'Custom';
}

export function customParamsOf(params: Params): CustomParams {
    const values = params['values'];
    const materials = params['materials'];
    return {
        values: typeof values === 'object' && values !== null && !Array.isArray(values) ? (values as Record<string, unknown>) : {},
        materials: typeof materials === 'object' && materials !== null && !Array.isArray(materials) ? (materials as Record<string, number>) : {},
    };
}

/** Params with the value of a parameter set, or dropped (back to the default) for undefined; empty maps are left out. */
export function withValue(params: Params, key: string, value: unknown): Params {
    const current = customParamsOf(params);
    return build({...current, values: withParam(current.values, key, value)});
}

/** Params with a slot's material set, or dropped (back to the slot's default) for undefined. */
export function withSlotMaterial(params: Params, slot: string, materialId: number | undefined): Params {
    const current = customParamsOf(params);
    return build({...current, materials: withParam(current.materials, slot, materialId) as Record<string, number>});
}

/** Why the definition does not work out at this size with these params, or null when it does. */
export function definitionError(definition: FurnitureDefinitionBody, size: ItemSize, params: Params): string | null {
    try {
        evaluateDefinition(definition, size, customParamsOf(params).values);
        return null;
    } catch (e) {
        return e instanceof Error ? e.message : String(e);
    }
}

function build(params: CustomParams): Params {
    return {
        ...(Object.keys(params.values).length > 0 ? {values: params.values} : {}),
        ...(Object.keys(params.materials).length > 0 ? {materials: params.materials} : {}),
    };
}

/** One item of a list parameter, as the item stores it (fields left out take their defaults). */
export type ListItem = Record<string, unknown>;

/** The item's own list for the parameter, or null when the definition's default applies. */
export function ownListItems(params: Params, key: string): ListItem[] | null {
    const value = customParamsOf(params).values[key];
    return Array.isArray(value) ? (value as ListItem[]) : null;
}

/** The list parameter's items: the item's own, or the definition's default list (none while the definition lacks one). */
export function listItems(params: Params, key: string, fallback: unknown): ListItem[] {
    return ownListItems(params, key) ?? (Array.isArray(fallback) ? [...(fallback as ListItem[])] : []);
}

/** A whole number typed into an input, or null for anything else (an empty text is the caller's business). */
export function parseWholeNumber(text: string): number | null {
    return /^-?\d+$/.test(text) ? Number(text) : null;
}

/** The list with one field of one item set, or dropped (back to the field's default) for undefined. */
export function withItemField(items: readonly ListItem[], index: number, field: string, value: unknown): ListItem[] {
    return items.map((item, i) => (i === index ? withParam(item, field, value) : item));
}

/** The list with an item of all-default fields added at the end. */
export function withItemAdded(items: readonly ListItem[]): ListItem[] {
    return [...items, {}];
}

export function withItemRemoved(items: readonly ListItem[], index: number): ListItem[] {
    return items.filter((_, i) => i !== index);
}

/** The list with an item swapped with the one before (−1) or after (+1) it. */
export function withItemMoved(items: readonly ListItem[], index: number, direction: -1 | 1): ListItem[] {
    return swapped(items, index, direction);
}

/** Params with `key` set, or removed for undefined (the default applies); other keys are kept. */
export function withParam(params: Params, key: string, value: unknown): Params {
    const {[key]: _old, ...rest} = params;
    return value === undefined ? rest : {...rest, [key]: value};
}

/** The list with an item swapped with the one before (−1) or after (+1) it; unchanged (a copy) at either end. */
export function swapped<T>(items: readonly T[], index: number, direction: -1 | 1): T[] {
    const target = index + direction;
    if (target < 0 || target >= items.length) {
        return [...items];
    }
    const moved = [...items];
    [moved[index], moved[target]] = [moved[target], moved[index]];
    return moved;
}
