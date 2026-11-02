/** What a furniture definition's items count as in the electrical schedule (its `category`). */
export type ElectricalCategory = 'light' | 'socket' | 'switch' | 'data' | 'plumbing';

/** The categories in the schedule's order, with their names. */
export const CategoryLabels: Record<ElectricalCategory, string> = {
    light: 'Light',
    socket: 'Socket',
    switch: 'Switch',
    data: 'Data',
    plumbing: 'Plumbing',
};

/** The symbol set of the electrical plan, a user setting: IEC 60617 or as Czech plans draw them (ČSN). */
export type ElectricalSymbols = 'Iec' | 'Csn';

export const ElectricalSymbolsLabels: Record<ElectricalSymbols, string> = {
    Iec: 'IEC 60617',
    Csn: 'ČSN (Czech)',
};

/**
 * A definition's settings for the electrical schedule, beside its `category` (`DefinitionSchedule`): `count` names an
 * int parameter multiplying the item, `values` the int, bool and select parameters it lists (all without).
 */
export interface DefinitionScheduleSettings {
    count?: string;
    values?: string[];
}

/** Points of one room alike: one definition, the same parameter values, the same elevation. Mirrors `ElectricalRowDto`. */
export interface ElectricalRow {
    category: ElectricalCategory;
    definitionId: number;
    name: string;
    /** The int, bool and select parameters its `schedule.values` lists (all without), defaults filled in. */
    values: Record<string, number | boolean | string>;
    /** mm, the items' bottom edge. */
    z: number;
    count: number;
    /** The sum of the parameter its `schedule.count` names (a socket's gangs, a data socket's ports); 1 per item without. */
    units: number;
    itemIds: number[];
}

export interface ElectricalTotals {
    lights: number;
    sockets: number;
    outlets: number;
    switches: number;
    dataSockets: number;
    dataPorts: number;
    plumbingPoints: number;
}

/** A room's points; `id` null holds the floor's points outside every room. */
export interface RoomElectrical {
    id: number | null;
    code: string | null;
    name: string;
    rows: ElectricalRow[];
    totals: ElectricalTotals;
}

export interface FloorElectrical {
    id: number;
    name: string;
    level: number;
    /** mm above ±0.000. */
    elevation: number;
    rooms: RoomElectrical[];
    totals: ElectricalTotals;
}

/** The electrical schedule of a project or floor (working copy, shown variants). Mirrors `ElectricalScheduleDto`. */
export interface ElectricalSchedule {
    floors: FloorElectrical[];
    totals: ElectricalTotals;
}
