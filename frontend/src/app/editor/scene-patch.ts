import {Change, EditResult} from '@app/models/editor';
import {Group, Item, Opening, Room, Scene, VariantSet, Wall} from '@app/models/scene';

/**
 * The scene after an edit, undo or redo, built from the result's element state instead of loading the
 * floor again; a batch (a group operation) applies each of its changes in order. Null when the result
 * cannot be applied safely: the project moved on by more than this one change (someone else edited
 * meanwhile), a wall came back with openings the result does not carry, or an item uses a furniture
 * definition the scene does not have.
 */
export function patchScene(scene: Scene, result: EditResult): Scene | null {
    const change = result.change;
    if (change === null) {
        return scene;
    }
    if (scene.revision === undefined || result.revision !== scene.revision + 1) {
        return null;
    }
    let next: Scene | null = {...scene, revision: result.revision};
    for (const entry of result.batch ?? [{change, element: result.element}]) {
        if (next === null) {
            return null;
        }
        if (entry.change.floorId === scene.floor.id) {
            next = patchElements(next, entry.change, entry.element);
        }
    }
    return next;
}

function patchElements(scene: Scene, change: Change, element: Record<string, unknown> | null): Scene | null {
    const id = change.elementId;
    switch (change.elementType) {
        case 'Item': {
            const item = element as unknown as Item | null;
            // The result carries the item only, not a furniture definition or model the scene does not have yet.
            const definitionId = item?.definitionId ?? null;
            if (definitionId !== null && !(scene.definitions ?? []).some((d) => d.id === definitionId)) {
                return null;
            }
            const modelId = item?.modelId ?? null;
            if (modelId !== null && !(scene.models ?? []).some((m) => m.id === modelId)) {
                return null;
            }
            return {...scene, items: upsert(scene.items, id, item)};
        }
        case 'Room': {
            const room = element as unknown as Room | null;
            // Items of a deleted room no longer belong to it.
            const items = room === null ? scene.items.map((i) => (i.roomId === id ? {...i, roomId: null} : i)) : scene.items;
            return {...scene, rooms: upsert(scene.rooms, id, room), items};
        }
        case 'Wall':
            return patchWall(scene, id, element);
        case 'Opening':
            return patchOpening(scene, id, element);
        case 'Group': {
            const group = element as unknown as Group | null;
            // Items of a deleted group no longer belong to it.
            const items = group === null ? scene.items.map((i) => (i.groupId === id ? {...i, groupId: null} : i)) : scene.items;
            return {...scene, groups: upsert(scene.groups ?? [], id, group), items};
        }
        case 'VariantSet': {
            const set = element as unknown as VariantSet | null;
            // Variants of a deleted set are plain groups.
            const groups = set === null ? (scene.groups ?? []).map((g) => (g.variantSetId === id ? {...g, variantSetId: null} : g)) : scene.groups;
            return {...scene, variantSets: upsert(scene.variantSets ?? [], id, set), groups};
        }
    }
}

/** A wall's state has its openings' offsets only (`openingOffsets`); the openings themselves stay. */
function patchWall(scene: Scene, id: number, element: Record<string, unknown> | null): Scene | null {
    if (element === null) {
        return {...scene, walls: scene.walls.filter((w) => w.id !== id)};
    }
    const {openingOffsets, ...fields} = element as unknown as Omit<Wall, 'openings'> & {openingOffsets?: {id: number; offset: number}[]};
    const offsets = new Map((openingOffsets ?? []).map((o) => [o.id, o.offset]));
    const current = scene.walls.find((w) => w.id === id);
    if (current === undefined && offsets.size > 0) {
        return null;
    }
    const openings = (current?.openings ?? []).map((o) => ({...o, offset: offsets.get(o.id) ?? o.offset}));
    return {...scene, walls: upsert(scene.walls, id, {...fields, openings})};
}

function patchOpening(scene: Scene, id: number, element: Record<string, unknown> | null): Scene | null {
    const withoutOpening = scene.walls.map((w) =>
        w.openings.some((o) => o.id === id) ? {...w, openings: w.openings.filter((o) => o.id !== id)} : w,
    );
    if (element === null) {
        return {...scene, walls: withoutOpening};
    }
    const {wallId, ...opening} = element as unknown as Opening & {wallId: number};
    if (!scene.walls.some((w) => w.id === wallId)) {
        return null;
    }
    const walls = withoutOpening.map((w) => (w.id === wallId ? {...w, openings: [...w.openings, opening].sort((a, b) => a.offset - b.offset)} : w));
    return {...scene, walls};
}

/** The list with the element replaced in place, appended, or (null) removed. */
function upsert<T extends {id: number}>(list: readonly T[], id: number, element: T | null): T[] {
    if (element === null) {
        return list.filter((e) => e.id !== id);
    }
    const index = list.findIndex((e) => e.id === id);
    if (index < 0) {
        return [...list, element];
    }
    const next = [...list];
    next[index] = element;
    return next;
}
