import {ItemKind} from '@app/models/scene';
import {wardrobeDoors} from '@app/viewer/engine/furniture';

import {Params, withParam} from './cabinet-params';

export function isWardrobe(element: Record<string, unknown>): boolean {
    return (element['kind'] as ItemKind) === 'Wardrobe';
}

/** The door count the viewer draws: `params.doors` or the default for the width. */
export function doorCount(params: Params, width: number): number {
    return wardrobeDoors({width, params});
}

/** The mirror doors as the form edits them (missing or malformed: none). */
export function mirrorDoorsOf(params: Params): number[] {
    const doors = params['mirrorDoors'];
    return Array.isArray(doors) ? doors.filter((d): d is number => typeof d === 'number') : [];
}

/** Params with a door count set (undefined: the default for the width); mirror doors beyond it are dropped. */
export function withDoors(params: Params, doors: number | undefined, width: number): Params {
    const next = withParam(params, 'doors', doors);
    const count = doorCount(next, width);
    const mirrors = mirrorDoorsOf(next).filter((door) => door <= count);
    return withParam(next, 'mirrorDoors', mirrors.length > 0 ? mirrors : undefined);
}

/** Params with one door's mirror switched on or off (doors numbered from 1 at the left). */
export function withMirrorDoor(params: Params, door: number, mirror: boolean): Params {
    const others = mirrorDoorsOf(params).filter((d) => d !== door);
    const doors = mirror ? [...others, door].sort((a, b) => a - b) : others;
    return withParam(params, 'mirrorDoors', doors.length > 0 ? doors : undefined);
}
