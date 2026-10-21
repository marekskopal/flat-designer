import {ElectricalRow} from '@app/models/electrical-schedule';
import {describe, expect, it} from 'vitest';

import {rowValues, totalsSummary} from './electrical-labels';

describe('electrical labels', () => {
    it('lists the parameter values of a row', () => {
        const row: ElectricalRow = {
            category: 'socket',
            definitionId: 1,
            name: 'Socket outlet (Schuko)',
            values: {gangs: 2, usb: true, colour: 'white'},
            z: 1100,
            count: 2,
            outlets: 4,
            itemIds: [1, 2],
        };
        expect(rowValues(row)).toBe('gangs 2 · usb yes · colour white');
        expect(rowValues({...row, values: {}})).toBe('');
    });

    it('sums up the non-zero counts', () => {
        expect(totalsSummary({lights: 1, sockets: 3, outlets: 5, switches: 2, dataSockets: 0, plumbingPoints: 1})).toBe(
            '1 light · 3 sockets (5 outlets) · 2 switches · 1 plumbing point',
        );
        // A socket definition whose gangs are 0 has no outlets to show.
        expect(totalsSummary({lights: 0, sockets: 1, outlets: 0, switches: 0, dataSockets: 0, plumbingPoints: 0})).toBe('1 socket');
        expect(totalsSummary({lights: 0, sockets: 0, outlets: 0, switches: 0, dataSockets: 0, plumbingPoints: 0})).toBe('');
    });
});
