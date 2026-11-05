import {FurnitureDefinition} from '@app/models/furniture';
import {localizeDefinition} from '@app/shared/i18n/definition-texts';

const definition = {
    id: 1,
    name: 'Base cabinet',
    description: 'A kitchen base cabinet.',
    definition: {
        format: 1,
        translations: {
            cs: {
                name: 'Spodní skříňka',
                parameters: {handle: 'Úchytka', fronts: 'Čela', 'fronts.type': 'Druh'},
                options: {handle: {bar: 'madlo'}, 'fronts.type': {drawer: 'zásuvka'}},
                slots: {body: 'Korpus'},
            },
        },
        parameters: [
            {key: 'handle', label: 'Handle', type: 'select', default: 'bar', options: ['bar', 'none']},
            {key: 'depth', label: 'Depth', type: 'int', default: 560},
            {
                key: 'fronts',
                label: 'Fronts',
                type: 'list',
                default: [],
                fields: [{key: 'type', label: 'Type', type: 'select', options: ['door', 'drawer']}],
            },
        ],
        materialSlots: [{key: 'body', label: 'Body'}, {key: 'legs', label: 'Legs'}],
        parts: [{type: 'board', name: 'Side'}],
    },
} as unknown as FurnitureDefinition;

describe('localizeDefinition', () => {
    it('puts in the texts of the language, keeping what has none', () => {
        const cs = localizeDefinition(definition, 'cs');

        expect([cs.name, cs.description]).toEqual(['Spodní skříňka', 'A kitchen base cabinet.']);
        expect(cs.definition.parameters?.map((p) => p.label)).toEqual(['Úchytka', 'Depth', 'Čela']);
        expect(cs.definition.parameters?.[0]).toMatchObject({options: ['bar', 'none'], optionLabels: {bar: 'madlo'}});
        const fronts = cs.definition.parameters?.[2];
        expect(fronts?.type === 'list' ? fronts.fields[0] : null).toMatchObject({label: 'Druh', optionLabels: {drawer: 'zásuvka'}});
        expect(cs.definition.materialSlots?.map((s) => s.label)).toEqual(['Korpus', 'Legs']);
        expect(cs.definition.parts).toBe(definition.definition.parts);
    });

    it('leaves a definition as it is in English or without texts of the language', () => {
        expect(localizeDefinition(definition, 'en')).toBe(definition);
    });
});
