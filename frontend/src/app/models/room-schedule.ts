import {RoomType} from './scene';

/** One room of the schedule. Mirrors `RoomScheduleRowDto`. */
export interface RoomScheduleRow {
    id: number;
    code: string;
    name: string;
    type: RoomType;
    /** Net floor area inside the wall faces, m². */
    area: number;
    /** m */
    perimeter: number;
    /** mm; null for an open-air room. */
    clearHeight: number | null;
    /** m³; null without a ceiling. */
    volume: number | null;
    /** Terrace or garden: summed apart from the indoor area. */
    outdoor: boolean;
}

export interface ScheduleTotal {
    rooms: number;
    area: number;
    perimeter: number;
}

export interface FloorSchedule {
    id: number;
    name: string;
    level: number;
    rooms: RoomScheduleRow[];
    indoor: ScheduleTotal;
    outdoor: ScheduleTotal;
}

/** The room schedule of a project or floor (working copy). Mirrors `RoomScheduleDto`. */
export interface RoomSchedule {
    floors: FloorSchedule[];
    indoor: ScheduleTotal;
    outdoor: ScheduleTotal;
}
