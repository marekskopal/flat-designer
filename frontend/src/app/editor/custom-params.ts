import {FurnitureDefinitionBody} from '@app/models/furniture';
import {evaluateDefinition, ItemSize} from '@app/viewer/engine/custom/definition';

import {Params, withParam} from './cabinet-params';

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
