import {HttpErrorResponse} from '@angular/common/http';
import {provideZonelessChangeDetection} from '@angular/core';
import {TestBed} from '@angular/core/testing';
import {EditResult} from '@app/models/editor';
import {Scene} from '@app/models/scene';
import {EditorApiService} from '@app/services/editor-api.service';
import {afterEach, beforeEach, describe, expect, it, vi} from 'vitest';

import {EditorStore} from './editor.store';

const change = {
    id: 1,
    operation: 'Create' as const,
    elementType: 'Item' as const,
    elementId: 42,
    floorId: 3,
    summary: 'Added item "Base"',
    actorType: 'Human' as const,
    actorName: 'Jane',
    revision: 8,
    undone: false,
    createdAt: '2026-10-17T10:00:00+00:00',
    batch: null,
};

describe('EditorStore', () => {
    let api: Record<string, ReturnType<typeof vi.fn>>;
    let reload: ReturnType<typeof vi.fn>;
    let setScene: ReturnType<typeof vi.fn>;
    let scene: Scene;
    let store: EditorStore;

    beforeEach(() => {
        api = {
            create: vi.fn(async (): Promise<EditResult> => ({revision: 8, change, element: {id: 42}})),
            update: vi.fn(async (): Promise<EditResult> => ({revision: 7, change: null, element: {id: 42}})),
            delete: vi.fn(async (): Promise<EditResult> => ({revision: 9, change, element: null})),
            changes: vi.fn(async () => [change]),
            itemKinds: vi.fn(async () => []),
        };
        reload = vi.fn();
        setScene = vi.fn();
        scene = {floor: {id: 3, name: 'Ground', level: 0}, revision: 7, materials: [], rooms: [], walls: [], items: []};
        TestBed.configureTestingModule({
            providers: [provideZonelessChangeDetection(), EditorStore, {provide: EditorApiService, useValue: api}],
        });
        store = TestBed.inject(EditorStore);
        store.connect({projectId: () => 1, floorId: () => 3, scene: () => scene, setScene, reload});
    });

    afterEach(() => TestBed.resetTestingModule());

    it('creates with the scene revision, selects the new element and adds it to the scene', async () => {
        await store.enable(true);
        await store.create('item', {kind: 'Box', x: 0, y: 0});

        expect(api['create']).toHaveBeenCalledWith(1, 3, 'item', {kind: 'Box', x: 0, y: 0}, 7);
        expect(store.selection()).toEqual({kind: 'item', id: 42});
        expect(setScene).toHaveBeenCalledWith({...scene, revision: 8, items: [{id: 42}]});
        expect(reload).not.toHaveBeenCalled();
        expect(store.changes()).toEqual([change]);
    });

    it('reloads when the project moved on by more than the edit', async () => {
        api['create'].mockResolvedValueOnce({revision: 10, change, element: {id: 42}});

        await store.create('item', {kind: 'Box'});

        expect(reload).toHaveBeenCalledTimes(1);
        expect(setScene).not.toHaveBeenCalled();
    });

    it('leaves the scene alone after an edit that changed nothing', async () => {
        expect(await store.update('item', 42, {x: 0})).toBe(false);

        expect(reload).not.toHaveBeenCalled();
        expect(setScene).not.toHaveBeenCalled();
    });

    it('reloads after a conflict so the newer state shows', async () => {
        api['update'].mockRejectedValueOnce(new HttpErrorResponse({status: 409}));

        await store.update('item', 42, {x: 10});

        expect(reload).toHaveBeenCalledTimes(1);
        expect(store.busy()).toBe(false);
    });

    it('runs one request at a time', async () => {
        const first = store.update('item', 42, {x: 10});
        const second = store.update('item', 42, {x: 20});
        await Promise.all([first, second]);

        expect(api['update']).toHaveBeenCalledTimes(1);
    });

    it('clears the selection after deleting', async () => {
        store.selection.set({kind: 'item', id: 42});

        await store.deleteSelected();

        expect(api['delete']).toHaveBeenCalledWith(1, 3, 'item', 42, 7);
        expect(store.selection()).toBeNull();
    });

    it('picks items with Shift, a group as all its items, and groups them', async () => {
        scene = {
            ...scene,
            items: [{id: 1, groupId: null}, {id: 2, groupId: 5}, {id: 3, groupId: 5}] as Scene['items'],
            groups: [{id: 5, name: 'Pair'}],
        };
        api['createGroup'] = vi.fn(async (): Promise<EditResult> => ({revision: 8, change: null, element: {id: 6, name: 'Bench'}}));
        store.select({kind: 'item', id: 1});

        store.togglePicked({kind: 'group', id: 5});
        expect(store.picked()).toEqual([1, 2, 3]);
        expect(store.selection()).toBeNull();

        store.togglePicked({kind: 'group', id: 5});
        expect(store.picked()).toEqual([1]);

        store.togglePicked({kind: 'item', id: 3});
        await store.groupPicked('Bench');
        expect(api['createGroup']).toHaveBeenCalledWith(1, 3, 'Bench', [1, 3], 7);
        expect(store.selection()).toEqual({kind: 'group', id: 6});
        expect(store.picked()).toEqual([]);
    });

    it('draws a run of walls with the draft fields and the scene revision', async () => {
        api['drawWalls'] = vi.fn(async (): Promise<EditResult> => ({revision: 8, change, element: null}));
        store.wallDraft.set({type: 'Exterior', thickness: 300, height: 2700});
        const points: [number, number][] = [
            [0, 0],
            [4000, 0],
            [4000, 3000],
        ];

        expect(await store.drawWalls(points, true)).toBe(true);

        expect(api['drawWalls']).toHaveBeenCalledWith(1, 3, points, true, {type: 'Exterior', thickness: 300, height: 2700}, 7, null);

        await store.drawWalls(points, false, [-500, 0]);
        expect(api['drawWalls']).toHaveBeenLastCalledWith(1, 3, points, false, {type: 'Exterior', thickness: 300, height: 2700}, 7, [-500, 0]);
    });

    it('adds the room around a point as the next numbered room and selects it', async () => {
        api['detectRoom'] = vi.fn(async (): Promise<EditResult> => ({revision: 8, change, element: {id: 12}}));
        store.setTool('detectRoom');

        await store.detectRoom([2000, 1500]);

        expect(api['detectRoom']).toHaveBeenCalledWith(1, 3, [2000, 1500], {name: 'New room', code: 'R1'}, 7);
        expect(store.selection()).toEqual({kind: 'room', id: 12});
        expect(store.tool()).toBe('detectRoom');
    });

    it('drops the drawing tool when edit mode ends', async () => {
        store.setTool('drawWalls');

        await store.enable(false);

        expect(store.tool()).toBe('select');
    });
});
