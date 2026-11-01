import {FurnitureDefinitionBody} from '@app/models/furniture';
import {describe, expect, it} from 'vitest';

import {countParameters, toggledValue, valueParameters, withCatalogue, withCategory, withCount, withValues} from './schedule-settings';

const socket: FurnitureDefinitionBody = {
    format: 1,
    size: {width: 80, depth: 10, height: 80},
    parameters: [
        {key: 'gangs', type: 'int', default: 1},
        {key: 'usb', type: 'bool', default: false},
        {key: 'path', type: 'list', fields: [], default: []},
    ],
    parts: [],
};

describe('schedule settings', () => {
    it('offers the int parameters to count by and the int, bool and select ones to list', () => {
        expect(countParameters(socket).map((p) => p.key)).toEqual(['gangs']);
        expect(valueParameters(socket).map((p) => p.key)).toEqual(['gangs', 'usb']);
    });

    it('files the definition in a catalogue after the format and the schedule settings, and takes it out', () => {
        const filed = withCount(withCategory(withCatalogue(socket, 'electrical'), 'socket'), 'gangs');
        expect(Object.keys(filed)).toEqual(['format', 'category', 'schedule', 'catalogue', 'size', 'parameters', 'parts']);
        expect(Object.keys(withCatalogue(filed, null))).toEqual(['format', 'category', 'schedule', 'size', 'parameters', 'parts']);
    });

    it('puts the category and the settings after the format', () => {
        const tagged = withCount(withCategory(socket, 'socket'), 'gangs');
        expect(Object.keys(tagged)).toEqual(['format', 'category', 'schedule', 'size', 'parameters', 'parts']);
        expect(tagged.schedule).toEqual({count: 'gangs'});
    });

    it('drops empty settings, and the settings with the category', () => {
        const tagged = withValues(withCount(withCategory(socket, 'socket'), 'gangs'), ['gangs']);
        expect(withCount(withValues(tagged, null), null).schedule).toBeUndefined();
        expect(withCategory(tagged, null)).toEqual(socket);
    });

    it('lists every value until one is taken out, in the parameter order', () => {
        const tagged = withCategory(socket, 'socket');
        expect(toggledValue(tagged, 'gangs', false)).toEqual(['usb']);
        expect(toggledValue(withValues(tagged, ['usb']), 'gangs', true)).toEqual(['gangs', 'usb']);
    });
});
