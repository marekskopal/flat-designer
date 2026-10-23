import {PlanPoint, Scene} from '@app/models/scene';
import * as THREE from 'three';

import {buildBuilding} from './building';
import {definitionLibrary} from './custom/custom';
import {leafObjects, LeafStates} from './doors';
import {buildItem, ItemAssets} from './items';
import {MaterialLibrary} from './materials';
import {lowestGround} from './plots';
import {hiddenItemIds} from './variants';

/**
 * A floor below the shown one in the all floors view, drawn by the same builders as the shown floor but not editable:
 * the group carries `userData.contextFloor` (the floor's id) and lies outside the engine's pickable content. Its
 * ceilings show, its doors stand as outside a walk (open, French windows closed) and the items of other variants are
 * hidden. Its fixture lights are created switched off, and stay so.
 */
export function buildContextFloor(scene: Scene, materials: MaterialLibrary, assets: ItemAssets): THREE.Group {
    const group = new THREE.Group();
    group.name = `floor:${scene.floor.name}`;
    group.userData = {contextFloor: scene.floor.id};

    const parts = buildBuilding(scene, materials);
    group.add(parts.root);
    const leaves = new LeafStates();
    leaves.reset(scene.walls);
    leaves.apply(leafObjects(parts.root));

    const rooms = new Map(scene.rooms.map((room) => [room.id, room]));
    const definitions = definitionLibrary(scene.definitions);
    const hidden = hiddenItemIds(scene);
    for (const item of scene.items) {
        const object = buildItem(item, materials, rooms.get(item.roomId ?? 0), definitions, assets);
        object.visible = !hidden.has(item.id);
        group.add(object);
    }
    return group;
}

/** The floors of `stack` that lie under the shown one, by level, lowest first. */
export function floorsBelow(stack: readonly Scene[], shown: Scene): Scene[] {
    return stack.filter((s) => s.floor.id !== shown.floor.id && s.floor.level < shown.floor.level).sort((a, b) => a.floor.level - b.floor.level);
}

/** How far a floor lies above (+) or below (−) the shown one, mm. */
export function floorOffset(floor: Scene, shown: Scene): number {
    return floor.floor.elevation - shown.floor.elevation;
}

/** The plan points a floor covers (its walls' ends and rooms' corners), for the framing and the sun's shadow. */
export function floorPoints(scene: Pick<Scene, 'walls' | 'rooms'>): PlanPoint[] {
    return [
        ...scene.walls.flatMap((w): PlanPoint[] => [
            [w.startX, w.startY],
            [w.endX, w.endY],
        ]),
        ...scene.rooms.flatMap((r) => r.polygon),
    ];
}

/** The lowest ground of the shown floor and the floors under it, mm against the shown floor's level. */
export function stackGround(shown: Scene, below: readonly Scene[]): number {
    return Math.min(
        lowestGround(shown.plots ?? [], shown.rooms),
        ...below.map((s) => floorOffset(s, shown) + lowestGround(s.plots ?? [], s.rooms)),
    );
}
