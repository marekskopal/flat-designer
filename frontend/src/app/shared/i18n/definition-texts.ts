import {DefinitionField, DefinitionParameter, DefinitionTranslation, FurnitureDefinition, MaterialSlot} from '@app/models/furniture';

/**
 * A definition with its texts in `locale` from its `translations`: name, description, parameter, field and slot labels,
 * and `optionLabels` for the selects. The parts are left as they are (their names tag the meshes and the cut list
 * names them on the server). The editor and the libraries show definitions so; the furniture editor edits the stored
 * JSON as it is.
 */
export function localizeDefinition(definition: FurnitureDefinition, locale: string): FurnitureDefinition {
    const texts = definition.definition.translations?.[locale];
    if (texts === undefined) {
        return definition;
    }
    const body = definition.definition;
    return {
        ...definition,
        name: texts.name ?? definition.name,
        description: texts.description ?? definition.description,
        definition: {
            ...body,
            parameters: body.parameters?.map((parameter) => localizeParameter(parameter, texts)),
            materialSlots: body.materialSlots?.map((slot): MaterialSlot => ({...slot, label: texts.slots?.[slot.key] ?? slot.label})),
        },
    };
}

function localizeParameter(parameter: DefinitionParameter, texts: DefinitionTranslation): DefinitionParameter {
    const label = texts.parameters?.[parameter.key] ?? parameter.label;
    switch (parameter.type) {
        case 'select':
            return {...parameter, label, optionLabels: texts.options?.[parameter.key]};
        case 'list':
            return {...parameter, label, fields: parameter.fields.map((field) => localizeField(parameter.key, field, texts))};
        default:
            return {...parameter, label};
    }
}

function localizeField(list: string, field: DefinitionField, texts: DefinitionTranslation): DefinitionField {
    const key = `${list}.${field.key}`;
    const label = texts.parameters?.[key] ?? field.label;
    return field.type === 'select' ? {...field, label, optionLabels: texts.options?.[key]} : {...field, label};
}
