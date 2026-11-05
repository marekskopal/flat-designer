import {levelText} from '@app/core/level-text';
import {roomCodeText} from '@app/core/room-code';
import {CircuitItem, ElectricalRow, ElectricalTotals} from '@app/models/electrical-schedule';

/** The counts of a summary, each named by a plural key `schedules.electrical.counts.<count>`. */
export type ElectricalCount = 'lights' | 'sockets' | 'outlets' | 'switches' | 'dataSockets' | 'dataPorts' | 'plumbingPoints';

/** The page's texts the labels need: kept out of this file, so it stays plain functions. */
export interface ElectricalTexts {
    yes: string;
    no: string;
    /** The decimal separator of the levels. */
    decimal: string;
    /** `n` of a count with its noun, e.g. "3 sockets". */
    count: (count: ElectricalCount, n: number) => string;
}

/** A row's parameter values for the electrician, e.g. "gangs 2 · rockers single"; booleans as yes / no. */
export function rowValues(row: ElectricalRow, texts: ElectricalTexts): string {
    return Object.entries(row.values)
        .map(([key, value]) => `${key} ${typeof value === 'boolean' ? (value ? texts.yes : texts.no) : value}`)
        .join(' · ');
}

/** The non-zero counts, e.g. "3 sockets (5 outlets) · 1 switch · 1 data socket (2 ports)"; empty when there are none. */
export function totalsSummary(totals: ElectricalTotals, texts: ElectricalTexts): string {
    const count = (n: number, key: ElectricalCount): string => (n === 0 ? '' : texts.count(key, n));
    const sockets = count(totals.sockets, 'sockets');
    const outlets = count(totals.outlets, 'outlets');
    const dataSockets = count(totals.dataSockets, 'dataSockets');
    const ports = count(totals.dataPorts, 'dataPorts');
    return [
        count(totals.lights, 'lights'),
        outlets === '' ? sockets : `${sockets} (${outlets})`,
        count(totals.switches, 'switches'),
        ports === '' ? dataSockets : `${dataSockets} (${ports})`,
        count(totals.plumbingPoints, 'plumbingPoints'),
    ]
        .filter((part) => part !== '')
        .join(' · ');
}

/** A switch or light of a lighting circuit: its name with its room's code, and a switch's elevation, e.g. "Switch (B_04, +1.050)". */
export function circuitItemText(item: CircuitItem, withElevation: boolean, decimal = '.'): string {
    const details = [item.roomCode === null ? null : roomCodeText(item.roomCode), withElevation ? levelText(item.z, decimal) : null].filter(
        (d) => d !== null,
    );
    return details.length === 0 ? item.name : `${item.name} (${details.join(', ')})`;
}
