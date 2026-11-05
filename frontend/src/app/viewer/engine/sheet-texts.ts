import {localDate} from '@app/core/local-date';
import {ElectricalCategory} from '@app/models/electrical-schedule';

/** A side of the building a façade is seen from, by the compass (`facadeName()`). */
export type CompassSide = 'North' | 'East' | 'South' | 'West';

/**
 * Every text the drawing sheets write (the plan, the electrical plan, the room schedule, the wall elevations, the
 * façades and sections) and how they write numbers and dates, in the language of the export. The engine stays
 * framework-free: the export UI builds these from its translations; `EnglishSheetTexts` is the default.
 */
export interface SheetTexts {
    /** The decimal separator of the numbers written (areas, lengths, levels): '.' or ','. */
    decimal: string;
    /** A date as the title blocks and footers write it. */
    date: (date: Date) => string;
    /** The north arrow's letter. */
    north: string;
    floorPlan: string;
    electricalPlan: string;
    /** The electrical legend's categories. */
    categories: Readonly<Record<ElectricalCategory, string>>;
    /** The electrical legend's entry for the switches' arcs to their lights. */
    switchedLights: string;
    roomSchedule: string;
    /** The room schedule's column headers. */
    columns: {
        code: string;
        room: string;
        area: string;
        perimeter: string;
        clearHeight: string;
        volume: string;
        /** The area with at least `metres` of headroom, its value already written with the separator. */
        headroom: (metres: string) => string;
    };
    /** A void's row in the room schedule. */
    voidRow: (name: string) => string;
    /** The room schedule's totals. */
    totals: {indoor: string; garage: string; outdoor: string};
    wallElevations: string;
    /** The elevations' legend of materials. */
    finishes: string;
    /** An opening's sill height under its size on an elevation, mm. */
    sill: (height: number) => string;
    facadeElevations: string;
    /** A façade's caption by the compass side it faces. */
    facade: Readonly<Record<CompassSide, string>>;
    /** A section's title, by its name (`A–A`). */
    section: (name: string) => string;
    /** The level marks of roofs: a flat roof's top, the eaves and the ridge. */
    levels: {roof: string; eaves: string; ridge: string};
}

/** The sheets as they are written in English. */
export const EnglishSheetTexts: SheetTexts = {
    decimal: '.',
    date: localDate,
    north: 'N',
    floorPlan: 'Floor plan',
    electricalPlan: 'Electrical plan',
    categories: {light: 'Light', socket: 'Socket', switch: 'Switch', data: 'Data', plumbing: 'Plumbing'},
    switchedLights: 'Switched lights',
    roomSchedule: 'Room schedule',
    columns: {
        code: 'Code',
        room: 'Room',
        area: 'Area m²',
        perimeter: 'Perimeter m',
        clearHeight: 'Clear height m',
        volume: 'Volume m³',
        headroom: (metres) => `≥ ${metres} m, m²`,
    },
    voidRow: (name) => `${name} (void)`,
    totals: {indoor: 'Indoor', garage: 'Garage', outdoor: 'Outdoor'},
    wallElevations: 'Wall elevations',
    finishes: 'Finishes',
    sill: (height) => `sill ${height}`,
    facadeElevations: 'Façade elevations',
    facade: {North: 'North elevation', East: 'East elevation', South: 'South elevation', West: 'West elevation'},
    section: (name) => `Section ${name}`,
    levels: {roof: 'Roof', eaves: 'Eaves', ridge: 'Ridge'},
};

/** `value.toFixed(digits)` with the sheets' decimal separator. */
export function sheetNumber(value: number, digits: number, texts: SheetTexts): string {
    return value.toFixed(digits).replace('.', texts.decimal);
}
