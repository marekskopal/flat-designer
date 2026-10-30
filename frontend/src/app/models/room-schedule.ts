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
    /** mm; null for an open-air room. Under a roof the highest. */
    clearHeight: number | null;
    /** m³; null without a ceiling. Under a roof it follows the slope. */
    volume: number | null;
    /** Terrace or garden: summed apart from the indoor area. */
    outdoor: boolean;
    /** A garage (indoor, unheated; FD-89): summed apart from the living area. Missing from older servers. */
    ancillary?: boolean;
    /** mm, the lowest clear height under a roof (at the eaves); null for a room no roof reaches. */
    minClearHeight: number | null;
    /** m², the floor area with at least 1.2 m of clear height under a roof; null for a room no roof reaches. */
    headroomArea: number | null;
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
    /** mm above ±0.000. */
    elevation: number;
    rooms: RoomScheduleRow[];
    indoor: ScheduleTotal;
    outdoor: ScheduleTotal;
    ancillary: ScheduleTotal;
}

/** The room schedule of a project or floor (working copy). Mirrors `RoomScheduleDto`. */
export interface RoomSchedule {
    floors: FloorSchedule[];
    indoor: ScheduleTotal;
    outdoor: ScheduleTotal;
    ancillary: ScheduleTotal;
}
