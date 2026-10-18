import {FurnitureDefinitionBody} from '@app/models/furniture';

/** What a new furniture definition starts from: a simple cabinet showing parameters, derived values, slots and repeats. */
export const NewDefinitionTemplate: FurnitureDefinitionBody = {
    format: 1,
    size: {width: 800, depth: 400, height: 900},
    parameters: [
        {key: 't', label: 'Board thickness', type: 'int', default: 18, min: 12, max: 40, unit: 'mm'},
        {key: 'shelves', label: 'Shelves', type: 'int', default: 1, min: 0, max: 6},
    ],
    derived: {innerW: 'W - 2*t', pitch: '(H - t) / (shelves + 1)'},
    materialSlots: [{key: 'carcass', label: 'Carcass', default: 'kitchen-oak'}],
    parts: [
        {
            type: 'board',
            name: 'Side',
            repeat: {count: 2},
            x: 'i * (W - t)',
            y: 0,
            z: 0,
            w: 't',
            h: 'H',
            d: 'D',
            material: 'carcass',
            edges: ['front'],
        },
        {type: 'board', name: 'Top', x: 't', y: 'H - t', z: 0, w: 'innerW', h: 't', d: 'D', material: 'carcass', grain: 'horizontal'},
        {type: 'board', name: 'Bottom', x: 't', y: 0, z: 0, w: 'innerW', h: 't', d: 'D', material: 'carcass', grain: 'horizontal'},
        {
            type: 'board',
            name: 'Shelf',
            repeat: {count: 'shelves'},
            x: 't',
            y: '(i + 1) * pitch',
            z: 0,
            w: 'innerW',
            h: 't',
            d: 'D - 20',
            material: 'carcass',
            grain: 'horizontal',
        },
    ],
};
