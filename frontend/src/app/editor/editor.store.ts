import {HttpErrorResponse} from '@angular/common/http';
import {inject, Injectable, signal} from '@angular/core';
import {Change, EditResult, ItemKindInfo} from '@app/models/editor';
import {Scene} from '@app/models/scene';
import {EditorApiService, GroupMove} from '@app/services/editor-api.service';
import {ElementKind, Selection} from '@app/viewer/engine/selection';

import {patchScene} from './scene-patch';

/** What the store needs from the page hosting the viewer. */
export interface EditorContext {
    projectId: () => number;
    floorId: () => number;
    /** The scene on screen; its revision is sent as the base of every edit. */
    scene: () => Scene | null;
    /** Shows the scene with an edit applied. */
    setScene: (scene: Scene) => void;
    /** Loads the floor again (when an edit cannot be applied locally, or after a conflict). */
    reload: () => void;
}

/**
 * Editing state of one viewer: selection (one element, or several items picked with Shift to group them),
 * edit mode, running request and the project's recent changes.
 * Every edit goes to the API with the scene's revision; the answer's element state is then patched into
 * the scene (the viewer rebuilds just that element), or the floor reloads when that is not safe. Errors are
 * shown by the error interceptor; a 409 (someone changed the project meanwhile) also reloads.
 */
@Injectable()
export class EditorStore {
    public readonly enabled = signal(false);
    public readonly selection = signal<Selection | null>(null);
    /** Items picked with Shift+click, e.g. to group them; while any are picked there is no single selection. */
    public readonly picked = signal<number[]>([]);
    public readonly busy = signal(false);
    public readonly changes = signal<Change[]>([]);
    public readonly itemKinds = signal<ItemKindInfo[]>([]);

    private readonly api = inject(EditorApiService);
    private context: EditorContext | null = null;

    public connect(context: EditorContext): void {
        this.context = context;
    }

    public async enable(enabled: boolean): Promise<void> {
        this.enabled.set(enabled);
        if (enabled) {
            await Promise.all([this.loadChanges(), this.itemKinds().length === 0 ? this.loadItemKinds() : Promise.resolve()]);
        }
    }

    /** Adds an element and selects it. */
    public async create(kind: ElementKind, fields: Record<string, unknown>): Promise<void> {
        const result = await this.run((c) => this.api.create(c.projectId(), c.floorId(), kind, fields, revisionOf(c)));
        const id = result?.element?.['id'];
        if (typeof id === 'number') {
            this.select({kind, id});
        }
    }

    /** Resolves true when the element changed. */
    public async update(kind: ElementKind, id: number, fields: Record<string, unknown>): Promise<boolean> {
        const result = await this.run((c) => this.api.update(c.projectId(), c.floorId(), kind, id, fields, revisionOf(c)));
        return result?.change != null;
    }

    /** A click: selects the element (or nothing) and drops the Shift-picked items. */
    public select(selection: Selection | null): void {
        this.picked.set([]);
        this.selection.set(selection);
    }

    /**
     * A Shift+click: adds the clicked item (or all items of a group) to the picked items, or takes them out
     * when they are all picked already. The single selection so far is picked first.
     */
    public togglePicked(selection: Selection | null): void {
        const clicked = this.itemsOf(selection);
        if (clicked.length === 0) {
            return;
        }
        const picking = this.picked().length > 0;
        const picked = new Set(picking ? this.picked() : this.itemsOf(this.selection()));
        // Shift+clicking the selected element itself starts picking with it rather than dropping it.
        const remove = picking && clicked.every((id) => picked.has(id));
        clicked.forEach((id) => (remove ? picked.delete(id) : picked.add(id)));
        this.selection.set(null);
        this.picked.set([...picked]);
    }

    /** Groups the picked items and selects the new group. */
    public async groupPicked(name: string): Promise<void> {
        const itemIds = this.picked();
        const result = await this.run((c) => this.api.createGroup(c.projectId(), c.floorId(), name, itemIds, revisionOf(c)));
        const id = result?.element?.['id'];
        if (typeof id === 'number') {
            this.select({kind: 'group', id});
        }
    }

    /** Resolves true when the group moved. */
    public async moveGroup(id: number, move: GroupMove): Promise<boolean> {
        const result = await this.run((c) => this.api.moveGroup(c.projectId(), c.floorId(), id, move, revisionOf(c)));
        return result?.change != null;
    }

    /** Makes the group the first variant of a new variant set, and selects the set. */
    public async createVariantSet(name: string, groupId: number): Promise<void> {
        const result = await this.run((c) => this.api.createVariantSet(c.projectId(), c.floorId(), name, [groupId], revisionOf(c)));
        const id = result?.element?.['id'];
        if (typeof id === 'number') {
            this.select({kind: 'variantSet', id});
        }
    }

    /** Adds a copy of the variant `fromGroupId` (null: an empty one) to the set; it is shown from then on. */
    public async addVariant(setId: number, fromGroupId: number | null): Promise<void> {
        await this.run((c) => this.api.addVariant(c.projectId(), c.floorId(), setId, fromGroupId, revisionOf(c)));
    }

    /** Shows another variant; a shared change like any edit (it also works outside edit mode). */
    public async switchVariant(setId: number, groupId: number): Promise<void> {
        await this.run((c) => this.api.switchVariant(c.projectId(), c.floorId(), setId, groupId, revisionOf(c)));
    }

    /** Dissolves the selected group; its items stay. */
    public async ungroupSelected(): Promise<void> {
        const selection = this.selection();
        if (selection?.kind !== 'group') {
            return;
        }
        const result = await this.run((c) => this.api.ungroup(c.projectId(), c.floorId(), selection.id, revisionOf(c)));
        if (result !== null) {
            this.select(null);
        }
    }

    public async deleteSelected(): Promise<void> {
        const selection = this.selection();
        if (selection === null) {
            return;
        }
        const result = await this.run((c) => this.api.delete(c.projectId(), c.floorId(), selection.kind, selection.id, revisionOf(c)));
        if (result !== null) {
            this.select(null);
        }
    }

    public async duplicateSelected(): Promise<void> {
        const selection = this.selection();
        if (selection === null) {
            return;
        }
        const result = await this.run((c) => this.api.duplicate(c.projectId(), c.floorId(), selection.kind, selection.id, revisionOf(c)));
        const id = result?.element?.['id'];
        if (typeof id === 'number') {
            this.select({kind: selection.kind, id});
        }
    }

    public async undo(): Promise<void> {
        await this.run((c) => this.api.undo(c.projectId()));
    }

    public async redo(): Promise<void> {
        await this.run((c) => this.api.redo(c.projectId()));
    }

    public async loadChanges(): Promise<void> {
        if (this.context === null || !this.enabled()) {
            return;
        }
        try {
            this.changes.set(await this.api.changes(this.context.projectId()));
        } catch {
            // The error interceptor shows the toast.
        }
    }

    /** The item ids a selection stands for: the item, or the members of a group. */
    private itemsOf(selection: Selection | null): number[] {
        if (selection?.kind === 'item') {
            return [selection.id];
        }
        if (selection?.kind === 'group') {
            return (this.context?.scene()?.items ?? []).filter((i) => i.groupId === selection.id).map((i) => i.id);
        }
        return [];
    }

    private async loadItemKinds(): Promise<void> {
        try {
            // Custom items need a furniture definition, picked elsewhere than the plain kinds.
            this.itemKinds.set((await this.api.itemKinds()).filter((k) => k.kind !== 'Custom'));
        } catch {
            // The error interceptor shows the toast.
        }
    }

    /** One request at a time; applies the result to the scene, and reloads after a conflict to show the newer state. */
    private async run(request: (context: EditorContext) => Promise<EditResult>): Promise<EditResult | null> {
        const context = this.context;
        if (context === null || this.busy()) {
            return null;
        }
        this.busy.set(true);
        try {
            const result = await request(context);
            if (result.change !== null) {
                const scene = context.scene();
                const patched = scene === null ? null : patchScene(scene, result);
                if (patched === null) {
                    context.reload();
                } else {
                    context.setScene(patched);
                }
                void this.loadChanges();
            }
            return result;
        } catch (e: unknown) {
            if (e instanceof HttpErrorResponse && e.status === 409) {
                context.reload();
                void this.loadChanges();
            }
            return null;
        } finally {
            this.busy.set(false);
        }
    }
}

function revisionOf(context: EditorContext): number | undefined {
    return context.scene()?.revision;
}
