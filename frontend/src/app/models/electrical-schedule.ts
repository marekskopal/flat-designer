/** What a furniture definition's items count as in the electrical schedule (its `category`). */
export type ElectricalCategory = 'light' | 'socket' | 'switch' | 'data' | 'plumbing';

/** Points of one room alike: one definition, the same parameter values, the same elevation. Mirrors `ElectricalRowDto`. */
export interface ElectricalRow {
    category: ElectricalCategory;
    definitionId: number;
    name: string;
    /** The int, bool and select parameters, defaults filled in (a socket's gangs, a switch's rockers). */
    values: Record<string, number | boolean | string>;
    /** mm, the items' bottom edge. */
    z: number;
    count: number;
    /** A socket's gangs times its count; 0 for other categories. */
    outlets: number;
    itemIds: number[];
}

export interface ElectricalTotals {
    lights: number;
    sockets: number;
    outlets: number;
    switches: number;
    dataSockets: number;
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
