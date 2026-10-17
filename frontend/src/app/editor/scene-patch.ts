import {Change, EditResult} from '@app/models/editor';
import {Item, Opening, Room, Scene, Wall} from '@app/models/scene';

/**
 * The scene after an edit, undo or redo, built from the result's element state instead of loading the
 * floor again. Null when the result cannot be applied safely: the project moved on by more than this one
 * change (someone else edited meanwhile), or a wall came back with openings the result does not carry.
 */
export function patchScene(scene: Scene, result: EditResult): Scene | null {
    const change = result.change;
    if (change === null) {
        return scene;
    }
    if (scene.revision === undefined || result.revision !== scene.revision + 1) {
        return null;
    }
    const next = {...scene, revision: result.revision};
    if (change.floorId !== scene.floor.id) {
        return next;
    }
    return patchElements(next, change, result.element);
}

function patchElements(scene: Scene, change: Change, element: Record<string, unknown> | null): Scene | null {
    const id = change.elementId;
    switch (change.elementType) {
        case 'Item':
            return {...scene, items: upsert(scene.items, id, element as unknown as Item | null)};
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
