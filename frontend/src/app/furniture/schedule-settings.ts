import {ElectricalCategory} from '@app/models/electrical-schedule';
import {DefinitionParameter, FurnitureCatalogue, FurnitureDefinitionBody} from '@app/models/furniture';

/** Parameter types the electrical schedule lists (`DefinitionSchedule::ValueTypes`). */
const ValueTypes = new Set<DefinitionParameter['type']>(['int', 'bool', 'select']);

/** The parameters `schedule.values` may list. */
export function valueParameters(body: FurnitureDefinitionBody): DefinitionParameter[] {
    return (body.parameters ?? []).filter((p) => ValueTypes.has(p.type));
}

/** The int parameters `schedule.count` may name. */
export function countParameters(body: FurnitureDefinitionBody): DefinitionParameter[] {
    return (body.parameters ?? []).filter((p) => p.type === 'int');
}

/**
 * The definition with the category (`category` right after `format`, as in the seed files); none drops the
 * `schedule` settings too, as they need one.
 */
export function withCategory(body: FurnitureDefinitionBody, category: ElectricalCategory | null): FurnitureDefinitionBody {
    const rest = {...body};
    delete rest.category;
    if (category === null) {
        delete rest.schedule;
        return rest;
    }
    return reorder({...rest, category});
}

/** The definition with `schedule.count` set (null drops it); empty settings are dropped. */
export function withCount(body: FurnitureDefinitionBody, count: string | null): FurnitureDefinitionBody {
    const schedule = {...body.schedule};
    if (count === null) {
        delete schedule.count;
    } else {
        schedule.count = count;
    }
    return withSchedule(body, schedule);
}

/** The definition with `schedule.values` set (null lists every value parameter); empty settings are dropped. */
export function withValues(body: FurnitureDefinitionBody, values: string[] | null): FurnitureDefinitionBody {
    const schedule = {...body.schedule};
    if (values === null) {
        delete schedule.values;
    } else {
        schedule.values = values;
    }
    return withSchedule(body, schedule);
}

/** The listed values with `key` added in the definition's parameter order, or taken out. */
export function toggledValue(body: FurnitureDefinitionBody, key: string, listed: boolean): string[] {
    const current = new Set(body.schedule?.values ?? valueParameters(body).map((p) => p.key));
    if (listed) {
        current.add(key);
    } else {
        current.delete(key);
    }
    return valueParameters(body)
        .map((p) => p.key)
        .filter((k) => current.has(k));
}

function withSchedule(body: FurnitureDefinitionBody, schedule: NonNullable<FurnitureDefinitionBody['schedule']>): FurnitureDefinitionBody {
    const rest = {...body};
    delete rest.schedule;
    return reorder(Object.keys(schedule).length === 0 ? rest : {...rest, schedule});
}

/** The definition filed in the catalogue (after `format`, `category` and `schedule`, as in the seed files); null takes it out (Other). */
export function withCatalogue(body: FurnitureDefinitionBody, catalogue: FurnitureCatalogue | null): FurnitureDefinitionBody {
    const rest = {...body};
    delete rest.catalogue;
    return reorder(catalogue === null ? rest : {...rest, catalogue});
}

/** `format`, `category`, `schedule` and `catalogue` first, the other keys in their order. */
function reorder(body: FurnitureDefinitionBody): FurnitureDefinitionBody {
    const {format, catalogue, category, schedule, ...rest} = body;
    return {
        format,
        ...(category === undefined ? {} : {category}),
        ...(schedule === undefined ? {} : {schedule}),
        ...(catalogue === undefined ? {} : {catalogue}),
        ...rest,
    };
}
