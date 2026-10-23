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
    leftFinishes: null,
    rightFinishes: null,
    openings: [door(1, 1000)],
};
const item = {id: 9, roomId: 2, name: 'Box', x: 0, y: 0} as Item;

const scene: Scene = {
    floor: {id: 3, name: 'Ground', level: 0, elevation: 0, slabThickness: 300},
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
            perimeter: 0,
            ceilingHeight: null,
            floorMaterialId: null,
            wallFinishes: [],
            tileOriginX: null,
            tileOriginY: null,
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
        batch: null,
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

    it('reloads for an item whose furniture definition the scene does not have', () => {
        const custom = {...item, kind: 'Custom', definitionId: 3};
        expect(patchScene(scene, result('Item', 9, custom))).toBeNull();
        const definition = {id: 3, name: 'Shelf', definition: {format: 1 as const, parts: []}, updatedAt: '2026-10-18T10:00:00+00:00'};
        expect(patchScene({...scene, definitions: [definition]}, result('Item', 9, custom))?.items).toEqual([custom]);
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

    it('adds, changes and removes plots, also in scenes saved before them', () => {
        const plot = {id: 4, name: 'Parcel', polygon: [[0, 0], [9000, 0], [9000, 9000]], area: 40.5, groundMaterialId: null};
        const added = patchScene(scene, result('Plot', 4, plot));
        expect(added?.plots).toEqual([plot]);
        expect(patchScene({...added!, revision: 7}, result('Plot', 4, {...plot, name: 'Lot'}))?.plots?.[0].name).toBe('Lot');
        expect(patchScene({...added!, revision: 7}, result('Plot', 4, null))?.plots).toEqual([]);
    });

    it('applies every change of a batch', () => {
        const grouped = result('Group', 4, {id: 4, name: 'Pair', variantSetId: null});
        const member = result('Item', 9, {...item, groupId: 4});
        if (grouped.change === null || member.change === null) {
            throw new Error('changes expected');
        }
        const patched = patchScene(scene, {
            ...grouped,
            batch: [
                {change: grouped.change, element: grouped.element},
                {change: member.change, element: member.element},
            ],
        });
        expect(patched?.groups).toEqual([{id: 4, name: 'Pair', variantSetId: null}]);
        expect(patched?.items[0].groupId).toBe(4);
        expect(patched?.revision).toBe(8);
    });

    it('adds the walls of a drawn run in order', () => {
        const {openings: _openings, ...state} = wall;
        const first = result('Wall', 6, {...state, id: 6, endX: 0, endY: 3000, openingOffsets: []});
        const second = result('Wall', 7, {...state, id: 7, startY: 3000, endY: 3000, openingOffsets: []});
        if (first.change === null || second.change === null) {
            throw new Error('changes expected');
        }
        const patched = patchScene(scene, {
            ...first,
            batch: [
                {change: {...first.change, operation: 'Create'}, element: first.element},
                {change: {...second.change, operation: 'Create'}, element: second.element},
            ],
        });
        expect(patched?.walls.map((w) => [w.id, w.openings])).toEqual([
            [5, [door(1, 1000)]],
            [6, []],
            [7, []],
        ]);
    });

    it('unlinks the items of a deleted group', () => {
        const grouped: Scene = {...scene, groups: [{id: 4, name: 'Pair', variantSetId: null}], items: [{...item, groupId: 4}]};
        const patched = patchScene(grouped, result('Group', 4, null));
        expect(patched?.groups).toEqual([]);
        expect(patched?.items[0].groupId).toBeNull();
    });

    it('switches a variant set and frees the variants of a deleted one', () => {
        const withSet: Scene = {
            ...scene,
            groups: [{id: 4, name: 'Island', variantSetId: 6}],
            variantSets: [{id: 6, name: 'Layout', activeGroupId: 4}],
        };
        expect(patchScene(withSet, result('VariantSet', 6, {id: 6, name: 'Layout', activeGroupId: null}))?.variantSets).toEqual([
            {id: 6, name: 'Layout', activeGroupId: null},
        ]);
        const deleted = patchScene(withSet, result('VariantSet', 6, null));
        expect(deleted?.variantSets).toEqual([]);
        expect(deleted?.groups).toEqual([{id: 4, name: 'Island', variantSetId: null}]);
    });
});
