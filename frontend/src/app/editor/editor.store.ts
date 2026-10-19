import {HttpErrorResponse} from '@angular/common/http';
import {inject, Injectable, signal} from '@angular/core';
import {Change, EditResult, ItemKindInfo} from '@app/models/editor';
import {FurnitureDefinition} from '@app/models/furniture';
import {PlanPoint, Scene, Underlay} from '@app/models/scene';
import {EditorApiService, GroupMove, UnderlayInput, WallDraft} from '@app/services/editor-api.service';
import {FurnitureService} from '@app/services/furniture.service';
import {EditorTool} from '@app/viewer/engine/drawing';
import {Vec2} from '@app/viewer/engine/plan-geometry';
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
    /** Furniture definitions the project's items may use (global, the project's own, its owner's library). */
    public readonly definitions = signal<FurnitureDefinition[]>([]);
    /** What a click in the top view does: select and drag, draw walls, or outline a room. */
    public readonly tool = signal<EditorTool>('select');
    /** Type, thickness and height of the walls drawn next. */
    public readonly wallDraft = signal<WallDraft>({type: 'Interior', thickness: 100, height: 2600});
    /** The plan image under the top view is shown. */
    public readonly underlayVisible = signal(true);
    /**
     * Calibrating the underlay: two points are being clicked ('picking'), or both are there (plan mm, as the image
     * lies now) and wait for their real distance.
     */
    public readonly calibration = signal<'picking' | {a: Vec2; b: Vec2} | null>(null);

    private readonly api = inject(EditorApiService);
    private readonly furniture = inject(FurnitureService);
    private context: EditorContext | null = null;

    public connect(context: EditorContext): void {
        this.context = context;
    }

    public async enable(enabled: boolean): Promise<void> {
        this.enabled.set(enabled);
        if (!enabled) {
            this.tool.set('select');
            this.calibration.set(null);
        }
        if (enabled) {
            await Promise.all([
                this.loadChanges(),
                this.itemKinds().length === 0 ? this.loadItemKinds() : Promise.resolve(),
                this.loadDefinitions(),
            ]);
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

    /** Switches the tool; a drawing tool drops the selection (its handles would get in the way). */
    public setTool(tool: EditorTool): void {
        this.tool.set(tool);
        if (tool !== 'select') {
            this.select(null);
        }
    }

    /** Draws a run of walls with the `wallDraft` fields; resolves true when they were added. */
    public async drawWalls(points: PlanPoint[], closed: boolean): Promise<boolean> {
        const draft = this.wallDraft();
        const result = await this.run((c) => this.api.drawWalls(c.projectId(), c.floorId(), points, closed, draft, revisionOf(c)));
        return result?.change != null;
    }

    /** Adds the room enclosed by the walls around the point and selects it (to name it in the inspector). */
    public async detectRoom(point: PlanPoint): Promise<void> {
        const fields = newRoomFields(this.context?.scene() ?? null);
        const result = await this.run((c) => this.api.detectRoom(c.projectId(), c.floorId(), point, fields, revisionOf(c)));
        const id = result?.element?.['id'];
        if (typeof id === 'number') {
            this.selection.set({kind: 'room', id});
        }
    }

    /** Sets the floor's underlay; resolves true when it was saved. */
    public async saveUnderlay(input: UnderlayInput): Promise<boolean> {
        const saved = await this.underlayRequest((c) => this.api.setUnderlay(c.projectId(), c.floorId(), input, revisionOf(c)));
        if (saved !== null) {
            this.underlayVisible.set(true);
        }
        return saved !== null;
    }

    public async removeUnderlay(): Promise<void> {
        await this.underlayRequest(async (c) => ({underlay: null, ...(await this.api.removeUnderlay(c.projectId(), c.floorId(), revisionOf(c)))}));
        this.calibration.set(null);
    }

    /**
     * An underlay request with the scene's revision as `If-Match`: the answer is patched into the scene, a 409 (someone
     * changed the floor meanwhile) reloads it. Not an element change: there is nothing to log or undo.
     */
    private async underlayRequest(
        request: (context: EditorContext) => Promise<{underlay: Underlay | null; revision: number}>,
    ): Promise<{underlay: Underlay | null; revision: number} | null> {
        const context = this.context;
        if (context === null || this.busy()) {
            return null;
        }
        this.busy.set(true);
        try {
            const result = await request(context);
            const scene = context.scene();
            if (scene === null) {
                context.reload();
            } else {
                context.setScene({...scene, underlay: result.underlay, revision: result.revision});
            }
            return result;
        } catch (e: unknown) {
            // Other errors have been shown by the error interceptor.
            if (e instanceof HttpErrorResponse && e.status === 409) {
                context.reload();
            }
            return null;
        } finally {
            this.busy.set(false);
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

    /** The furniture to pick from, again (e.g. after a definition was added in another tab). */
    public async loadDefinitions(): Promise<void> {
        const context = this.context;
        if (context === null) {
            return;
        }
        try {
            this.definitions.set(await this.furniture.list(context.projectId()));
        } catch {
            // The error interceptor shows the toast.
        }
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

/** Name and code of a room added by hand (the Add menu, the room tool): numbered after the rooms there are. */
export function newRoomFields(scene: Scene | null): {name: string; code: string} {
    return {name: 'New room', code: `R${(scene?.rooms.length ?? 0) + 1}`};
}

function revisionOf(context: EditorContext): number | undefined {
    return context.scene()?.revision;
}
