import {Change, EditResult} from '@app/models/editor';
import {Item, Opening, Scene, Wall} from '@app/models/scene';
import {describe, expect, it} from 'vitest';

import {patchScene} from './scene-patch';

function door(id: number, offset: number): Opening {
    return {
        id,
        name: `D${id}`,
        type: 'Door',
        offset,
        width: 900,
        height: 1970,
        sillHeight: 0,
        leafWidth: null,
        hinge: null,
        side: null,
        entrance: false,
    };
}

const wall: Wall = {
    id: 5,
    name: 'South',
    type: 'Interior',
    startX: 0,
    startY: 0,
    endX: 4000,
    endY: 0,
    thickness: 100,
    height: 2600,
    materialId: null,
    openings: [door(1, 1000)],
};
const item = {id: 9, roomId: 2, name: 'Box', x: 0, y: 0} as Item;

const scene: Scene = {
    floor: {id: 3, name: 'Ground', level: 0},
    revision: 7,
    materials: [],
    rooms: [
        {
            id: 2,
            code: 'R1',
            name: 'Room',
            type: 'Living',
            polygon: [[0, 0], [1, 0], [0, 1]],
            area: 0,
            ceilingHeight: null,
            floorMaterialId: null,
            wallFinishes: [],
        },
    ],
    walls: [wall],
    items: [item],
};

function result(
    elementType: Change['elementType'],
    elementId: number,
    element: Record<string, unknown> | null,
    revision = 8,
    floorId = 3,
): EditResult {
    const change: Change = {
        id: 1,
        operation: element === null ? 'Delete' : 'Update',
        elementType,
        elementId,
        floorId,
        summary: '',
        actorType: 'Human',
        actorName: 'Jane',
        revision,
        undone: false,
        createdAt: '',
    };
    return {revision, change, element};
}

describe('patchScene', () => {
    it('replaces, adds and removes items', () => {
        expect(patchScene(scene, result('Item', 9, {...item, x: 500}))?.items).toEqual([{...item, x: 500}]);
        expect(patchScene(scene, result('Item', 10, {...item, id: 10}))?.items.map((i) => i.id)).toEqual([9, 10]);
        expect(patchScene(scene, result('Item', 9, null))?.items).toEqual([]);
    });

    it('takes the revision and leaves other floors alone', () => {
        const patched = patchScene(scene, result('Item', 99, {id: 99}, 8, 4));
        expect(patched).toEqual({...scene, revision: 8});
    });

    it('reloads instead when someone else changed the project meanwhile', () => {
        expect(patchScene(scene, result('Item', 9, item, 9))).toBeNull();
    });

    it('applies a wall with its opening offsets to the openings it already has', () => {
        const {openings: _openings, ...state} = wall;
        const patched = patchScene(scene, result('Wall', 5, {...state, startX: 500, openingOffsets: [{id: 1, offset: 500}]}));
        expect(patched?.walls[0]).toEqual({...wall, startX: 500, openings: [door(1, 500)]});
    });

    it('reloads for a wall that comes back with openings', () => {
        const {openings: _openings, ...state} = wall;
        expect(patchScene({...scene, walls: []}, result('Wall', 5, {...state, openingOffsets: [{id: 1, offset: 1000}]}))).toBeNull();
    });

    it('moves, adds and removes openings within their wall', () => {
        expect(patchScene(scene, result('Opening', 1, {...door(1, 2000), wallId: 5}))?.walls[0].openings).toEqual([door(1, 2000)]);
        expect(patchScene(scene, result('Opening', 2, {...door(2, 100), wallId: 5}))?.walls[0].openings).toEqual([door(2, 100), door(1, 1000)]);
        expect(patchScene(scene, result('Opening', 1, null))?.walls[0].openings).toEqual([]);
    });

    it('unlinks the items of a deleted room', () => {
        const patched = patchScene(scene, result('Room', 2, null));
        expect(patched?.rooms).toEqual([]);
        expect(patched?.items[0].roomId).toBeNull();
    });
});
