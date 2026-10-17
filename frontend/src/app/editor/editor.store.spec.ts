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
});
