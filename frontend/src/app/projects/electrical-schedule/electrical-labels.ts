import {ElectricalCategory, ElectricalRow, ElectricalTotals} from '@app/models/electrical-schedule';

export const CategoryLabels: Record<ElectricalCategory, string> = {
    light: 'Light',
    socket: 'Socket',
    switch: 'Switch',
    data: 'Data',
    plumbing: 'Plumbing',
};

/** A row's parameter values for the electrician, e.g. "gangs 2 · rockers single"; booleans as yes / no. */
export function rowValues(row: ElectricalRow): string {
    return Object.entries(row.values)
        .map(([key, value]) => `${key} ${typeof value === 'boolean' ? (value ? 'yes' : 'no') : value}`)
        .join(' · ');
}

/** The non-zero counts, e.g. "3 sockets (5 outlets) · 1 switch"; empty when there are none. */
export function totalsSummary(totals: ElectricalTotals): string {
    const count = (n: number, one: string, many: string): string => (n === 0 ? '' : `${n} ${n === 1 ? one : many}`);
    const sockets = count(totals.sockets, 'socket', 'sockets');
    const outlets = count(totals.outlets, 'outlet', 'outlets');
    return [
        count(totals.lights, 'light', 'lights'),
        outlets === '' ? sockets : `${sockets} (${outlets})`,
        count(totals.switches, 'switch', 'switches'),
        count(totals.dataSockets, 'data socket', 'data sockets'),
        count(totals.plumbingPoints, 'plumbing point', 'plumbing points'),
    ]
        .filter((part) => part !== '')
        .join(' · ');
}
