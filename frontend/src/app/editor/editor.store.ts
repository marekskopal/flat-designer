import {HttpErrorResponse} from '@angular/common/http';
import {inject, Injectable, signal} from '@angular/core';
import {Change, EditResult, ItemKindInfo} from '@app/models/editor';
import {Scene} from '@app/models/scene';
import {EditorApiService} from '@app/services/editor-api.service';
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
 * Editing state of one viewer: selection, edit mode, running request and the project's recent changes.
 * Every edit goes to the API with the scene's revision; the answer's element state is then patched into
 * the scene (the viewer rebuilds just that element), or the floor reloads when that is not safe. Errors are
 * shown by the error interceptor; a 409 (someone changed the project meanwhile) also reloads.
 */
@Injectable()
export class EditorStore {
    public readonly enabled = signal(false);
    public readonly selection = signal<Selection | null>(null);
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
            this.selection.set({kind, id});
        }
    }

    /** Resolves true when the element changed. */
    public async update(kind: ElementKind, id: number, fields: Record<string, unknown>): Promise<boolean> {
        const result = await this.run((c) => this.api.update(c.projectId(), c.floorId(), kind, id, fields, revisionOf(c)));
        return result?.change != null;
    }

    public async deleteSelected(): Promise<void> {
        const selection = this.selection();
        if (selection === null) {
            return;
        }
        const result = await this.run((c) => this.api.delete(c.projectId(), c.floorId(), selection.kind, selection.id, revisionOf(c)));
        if (result !== null) {
            this.selection.set(null);
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
            this.selection.set({kind: selection.kind, id});
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

    private async loadItemKinds(): Promise<void> {
        try {
            this.itemKinds.set(await this.api.itemKinds());
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
