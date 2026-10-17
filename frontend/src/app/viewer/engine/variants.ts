import {Scene} from '@app/models/scene';

/** Items not shown: members of a group that is a variant of a set, but not the set's active one. */
export function hiddenItemIds(scene: Scene): Set<number> {
    const active = new Map((scene.variantSets ?? []).map((set) => [set.id, set.activeGroupId]));
    const hiddenGroups = new Set(
        (scene.groups ?? []).filter((g) => g.variantSetId !== null && active.get(g.variantSetId) !== g.id).map((g) => g.id),
    );
    return new Set(scene.items.filter((i) => i.groupId !== null && hiddenGroups.has(i.groupId)).map((i) => i.id));
}
