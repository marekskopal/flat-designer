import {CabinetParams, FrontSpec, FrontType, ItemKind} from '@app/models/scene';

/** Item kinds whose params are edited with the cabinet form instead of raw JSON. */
export const CabinetKinds: readonly ItemKind[] = ['BaseCabinet', 'WallCabinet', 'TallCabinet'];
export const FrontTypes: readonly FrontType[] = ['door', 'drawer', 'oven', 'microwave'];
export const Handles: readonly NonNullable<CabinetParams['handle']>[] = ['horizontal', 'vertical', 'none'];

export type Params = Record<string, unknown>;

export function isCabinet(element: Record<string, unknown>): boolean {
    return CabinetKinds.includes(element['kind'] as ItemKind);
}

/** The fronts as the form edits them (missing or malformed: none). */
export function frontsOf(params: Params): FrontSpec[] {
    const fronts = params['fronts'];
    return Array.isArray(fronts) ? fronts.filter((f): f is FrontSpec => typeof f === 'object' && f !== null) : [];
}

/** Params with `key` set, or removed for undefined (the viewer's default applies); other keys are kept. */
export function withParam(params: Params, key: string, value: unknown): Params {
    const {[key]: _old, ...rest} = params;
    return value === undefined ? rest : {...rest, [key]: value};
}

/** Params with one front changed; an undefined field is removed (height: shares the rest, count: one leaf). */
export function withFront(params: Params, index: number, patch: Partial<Record<keyof FrontSpec, unknown>>): Params {
    const fronts = frontsOf(params).map((front, i) => {
        if (i !== index) {
            return front;
        }
        let next: Params = {...front};
        for (const [key, value] of Object.entries(patch)) {
            next = withParam(next, key, value);
        }
        return next as unknown as FrontSpec;
    });
    return withParam(params, 'fronts', fronts);
}

/** Params with a door added on top. */
export function addFront(params: Params): Params {
    return withParam(params, 'fronts', [...frontsOf(params), {type: 'door'} satisfies FrontSpec]);
}

export function removeFront(params: Params, index: number): Params {
    const fronts = frontsOf(params).filter((_, i) => i !== index);
    return withParam(params, 'fronts', fronts.length > 0 ? fronts : undefined);
}

/** Params with a front swapped with its neighbour below (−1) or above (+1). */
export function moveFront(params: Params, index: number, direction: -1 | 1): Params {
    const fronts = frontsOf(params);
    const target = index + direction;
    if (target < 0 || target >= fronts.length) {
        return params;
    }
    [fronts[index], fronts[target]] = [fronts[target], fronts[index]];
    return withParam(params, 'fronts', fronts);
}

/** A whole-mm input: blank = unset (undefined), otherwise a non-negative integer or an error. */
export function parseMillimetres(raw: string): {ok: true; value: number | undefined} | {ok: false; error: string} {
    const text = raw.trim();
    if (text === '') {
        return {ok: true, value: undefined};
    }
    return /^\d+$/.test(text) ? {ok: true, value: Number(text)} : {ok: false, error: 'Whole mm expected.'};
}
