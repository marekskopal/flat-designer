import {ElectricalRow} from '@app/models/electrical-schedule';
import {describe, expect, it} from 'vitest';

import {circuitItemText, ElectricalCount, ElectricalTexts, rowValues, totalsSummary} from './electrical-labels';

const nouns: Record<ElectricalCount, [string, string]> = {
    lights: ['light', 'lights'],
    sockets: ['socket', 'sockets'],
    outlets: ['outlet', 'outlets'],
    switches: ['switch', 'switches'],
    dataSockets: ['data socket', 'data sockets'],
    dataPorts: ['port', 'ports'],
    plumbingPoints: ['plumbing point', 'plumbing points'],
};
const texts: ElectricalTexts = {yes: 'yes', no: 'no', decimal: '.', count: (key, n) => `${n} ${nouns[key][n === 1 ? 0 : 1]}`};

describe('electrical labels', () => {
    it('lists the parameter values of a row', () => {
        const row: ElectricalRow = {
            category: 'socket',
            definitionId: 1,
            name: 'Socket outlet (Schuko)',
            values: {gangs: 2, usb: true, colour: 'white'},
            z: 1100,
            count: 2,
            units: 4,
            itemIds: [1, 2],
        };
        expect(rowValues(row, texts)).toBe('gangs 2 · usb yes · colour white');
        expect(rowValues({...row, values: {}}, texts)).toBe('');
    });

    it('sums up the non-zero counts', () => {
        expect(totalsSummary({lights: 1, sockets: 3, outlets: 5, switches: 2, dataSockets: 1, dataPorts: 2, plumbingPoints: 1}, texts)).toBe(
            '1 light · 3 sockets (5 outlets) · 2 switches · 1 data socket (2 ports) · 1 plumbing point',
        );
        // A socket definition whose gangs are 0 has no outlets to show.
        const none = {lights: 0, sockets: 0, outlets: 0, switches: 0, dataSockets: 0, dataPorts: 0, plumbingPoints: 0};
        expect(totalsSummary({...none, sockets: 1}, texts)).toBe('1 socket');
        expect(totalsSummary(none, texts)).toBe('');
    });

    it('names a circuit\'s switch with its room and elevation, a light with its room', () => {
        const item = {itemId: 1, name: 'Switch', roomId: 4, roomCode: 'H1/B_04', z: 1050};
        expect(circuitItemText(item, true)).toBe('Switch (B_04, +1.050)');
        expect(circuitItemText({...item, name: 'Pendant'}, false)).toBe('Pendant (B_04)');
        expect(circuitItemText({...item, roomId: null, roomCode: null}, false)).toBe('Switch');
        expect(circuitItemText(item, true, ',')).toBe('Switch (B_04, +1,050)');
    });
});
