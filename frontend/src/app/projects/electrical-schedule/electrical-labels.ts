import {levelText} from '@app/core/level-text';
import {roomCodeText} from '@app/core/room-code';
import {CircuitItem, ElectricalRow, ElectricalTotals} from '@app/models/electrical-schedule';

/** A row's parameter values for the electrician, e.g. "gangs 2 · rockers single"; booleans as yes / no. */
export function rowValues(row: ElectricalRow): string {
    return Object.entries(row.values)
        .map(([key, value]) => `${key} ${typeof value === 'boolean' ? (value ? 'yes' : 'no') : value}`)
        .join(' · ');
}

/** The non-zero counts, e.g. "3 sockets (5 outlets) · 1 switch · 1 data socket (2 ports)"; empty when there are none. */
export function totalsSummary(totals: ElectricalTotals): string {
    const count = (n: number, one: string, many: string): string => (n === 0 ? '' : `${n} ${n === 1 ? one : many}`);
    const sockets = count(totals.sockets, 'socket', 'sockets');
    const outlets = count(totals.outlets, 'outlet', 'outlets');
    const dataSockets = count(totals.dataSockets, 'data socket', 'data sockets');
    const ports = count(totals.dataPorts, 'port', 'ports');
    return [
        count(totals.lights, 'light', 'lights'),
        outlets === '' ? sockets : `${sockets} (${outlets})`,
        count(totals.switches, 'switch', 'switches'),
        ports === '' ? dataSockets : `${dataSockets} (${ports})`,
        count(totals.plumbingPoints, 'plumbing point', 'plumbing points'),
    ]
        .filter((part) => part !== '')
        .join(' · ');
}

/** A switch or light of a lighting circuit: its name with its room's code, and a switch's elevation, e.g. "Switch (B_04, +1.050)". */
export function circuitItemText(item: CircuitItem, withElevation: boolean): string {
    const details = [item.roomCode === null ? null : roomCodeText(item.roomCode), withElevation ? levelText(item.z) : null].filter((d) => d !== null);
    return details.length === 0 ? item.name : `${item.name} (${details.join(', ')})`;
}
