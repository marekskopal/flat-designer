import {Scene} from '@app/models/scene';

import {Vec2, WallCollider} from './plan-geometry';
import {StairPath,stairPaths} from './stairs';
import {FloorLevels, FloorSurface,floorSurface} from './walkthrough';

/**
 * A walk over all the floors of a building at once: every floor's colliders and what is underfoot, in the
 * shown floor's frame (heights against its level), so the walker climbs a stair onto the next floor without a switch.
 * Each floor holds a band of the walker's heights, from `BandBelow` under its level to as far under the next floor's:
 * its walls, stairs and doors stop the walker only within it (`WallCollider.levels`), and the floor whose band holds
 * the walker's height says what is underfoot, its stairs first, then its rooms, then the ground of any floor's plots
 * (the land is the same for all of them). A stair is its floor's going up and the next one's coming up through its
 * void (`stairPaths()`), so its ramp carries on across the bands.
 */

/** mm under a floor's level from which the walker counts as on it (the top of a stair from below). */
export const BandBelow = 600;

/** A floor of the walk: its scene, its level against the shown floor's, its stairs and its band of walker heights. */
export interface WalkFloor {
    scene: Scene;
    /** mm, its elevation less the shown floor's. */
    offset: number;
    stairs: StairPath[];
    /** [from, to) mm against the shown floor's level. */
    band: [number, number];
}

/** The floors to walk: `shown` and the `others` (any order, the shown one among them is ignored), lowest first. */
export function walkFloors(shown: Scene, others: readonly Scene[]): WalkFloor[] {
    const scenes = [shown, ...otherFloors(others, shown)].sort(byLevel);
    const offsets = scenes.map((s) => s.floor.elevation - shown.floor.elevation);
    // A floor's band starts under its lowest room (a sunken one may lie 3 m lower), but above the floor below's level.
    const starts = scenes.map((scene, i) => {
        if (i === 0) {
            return -Infinity;
        }
        const sunken = Math.min(0, ...scene.rooms.map((r) => r.floorElevation ?? 0));
        return Math.max(offsets[i] + sunken - BandBelow, offsets[i - 1] + 1);
    });
    return scenes.map((scene, i) => ({
        scene,
        offset: offsets[i],
        stairs: stairPaths(scene),
        band: [starts[i], i === scenes.length - 1 ? Infinity : starts[i + 1]],
    }));
}

/** By level, then by elevation (two floors at one level: the lower first). */
const byLevel = (a: Scene, b: Scene): number => a.floor.level - b.floor.level || a.floor.elevation - b.floor.elevation;

/** The floors of `scenes` other than the shown one, each once, by level (a walk over all floors draws them all). */
export function otherFloors(scenes: readonly Scene[], shown: Scene): Scene[] {
    const unique = scenes.filter((s, i) => s.floor.id !== shown.floor.id && scenes.findIndex((o) => o.floor.id === s.floor.id) === i);
    return unique.sort(byLevel);
}

/** The floor whose band holds a height (mm against the shown floor's level). */
export function floorAt(floors: readonly WalkFloor[], level: number): WalkFloor {
    return floors.find((f) => level >= f.band[0] && level < f.band[1]) ?? floors[level < 0 ? 0 : floors.length - 1];
}

/**
 * A floor's colliders in the shown floor's frame: their own `levels` (against the floor's level) moved by its offset,
 * and all of them kept within its band; one whose levels lie wholly outside it is left out.
 */
export function floorColliders(floor: WalkFloor, colliders: readonly WallCollider[]): WallCollider[] {
    const [low, high] = floor.band;
    return colliders.flatMap((collider): WallCollider[] => {
        const [from, to] = collider.levels ?? [-Infinity, Infinity];
        const levels: [number, number] = [Math.max(from + floor.offset, low), Math.min(to + floor.offset, high - 1e-6)];
        return levels[0] > levels[1] ? [] : [{...collider, levels}];
    });
}

/** What is underfoot over all floors (`floorLevels()` for a building), against the shown floor's level. */
export function buildingLevels(floors: readonly WalkFloor[]): FloorLevels {
    const surfaces = new Map<WalkFloor, FloorSurface>(floors.map((f) => [f, floorSurface(f.scene, f.stairs)]));
    const surfaceOf = (floor: WalkFloor): FloorSurface => surfaces.get(floor) as FloorSurface;
    // The ground of the floors with plots, for each floor nearest it first.
    const withGround = floors.filter((f) => (f.scene.plots ?? []).length > 0);
    const grounds = new Map(
        floors.map((floor) => [floor, [...withGround].sort((a, b) => Math.abs(a.offset - floor.offset) - Math.abs(b.offset - floor.offset))]),
    );
    // The floor of the last look-up: sloping ground stops only the walker on the floor whose plot it is.
    let current = floors[0];
    const levels: FloorLevels = (point: Vec2, near = 0) => {
        const floor = floorAt(floors, near);
        current = floor;
        const own = surfaceOf(floor);
        const local = own.stair(point, near - floor.offset) ?? own.room(point);
        if (local !== null) {
            return floor.offset + local;
        }
        for (const other of grounds.get(floor) ?? []) {
            const ground = surfaceOf(other).ground(point);
            if (ground !== null) {
                return other.offset + ground;
            }
        }
        return floor.offset;
    };
    if (withGround.some((f) => surfaceOf(f).tooSteep !== undefined)) {
        levels.tooSteep = (from, to): boolean => surfaceOf(current).tooSteep?.(from, to) === true;
    }
    return levels;
}
