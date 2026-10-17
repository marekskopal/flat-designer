import {ChangeDetectionStrategy, Component, computed, inject, input} from '@angular/core';
import {Item, Opening, Room, Scene, Wall} from '@app/models/scene';
import {polygonCentroid} from '@app/viewer/engine/plan-geometry';
import {ElementKind} from '@app/viewer/engine/selection';

import {ActivityComponent} from './activity.component';
import {EditorStore} from './editor.store';
import {FieldChange, InspectorComponent} from './inspector.component';

const KindLabels: Record<ElementKind, string> = {item: 'Item', wall: 'Wall', opening: 'Door / window', room: 'Room'};

/** Size of a new wall and a new room, mm. */
const NewWallLength = 3000;
const NewRoomSize = 3000;

/** Nudge steps for arrow keys, mm (Shift: coarse). */
const NudgeStep = 10;
const NudgeStepCoarse = 100;

/**
 * Editing tools of the viewer panel: undo/redo, add, duplicate, delete, the inspector of the selected
 * element and the activity list, plus the keyboard shortcuts while editing.
 */
@Component({
    selector: 'fd-editor-panel',
    imports: [ActivityComponent, InspectorComponent],
    templateUrl: './editor-panel.component.html',
    styleUrl: './editor-panel.component.scss',
    changeDetection: ChangeDetectionStrategy.OnPush,
    host: {'(document:keydown)': 'onKeydown($event)'},
})
export class EditorPanelComponent {
    public readonly scene = input.required<Scene>();
    /** Plan point the camera looks at, where new elements go. */
    public readonly viewCenter = input.required<() => {x: number; y: number}>();

    protected readonly editor = inject(EditorStore);

    /** The selected element's current state, or null when it is gone (deleted, other floor). */
    protected readonly selected = computed(() => {
        const selection = this.editor.selection();
        const scene = this.scene();
        if (selection === null) {
            return null;
        }
        const element = findElement(scene, selection.kind, selection.id);
        return element === null ? null : {kind: selection.kind, label: KindLabels[selection.kind], element};
    });
    protected readonly selectedWall = computed(() => {
        const selected = this.selected();
        return selected?.kind === 'wall' ? (selected.element as unknown as Wall) : null;
    });

    protected onFieldChange(change: FieldChange): void {
        const selection = this.editor.selection();
        if (selection !== null) {
            void this.editor.update(selection.kind, selection.id, {[change.key]: change.value});
        }
    }

    protected add(event: Event): void {
        const select = event.target as HTMLSelectElement;
        const choice = select.value;
        select.value = '';
        const center = this.viewCenter()();
        const room = this.selectedRoom();

        if (choice.startsWith('item:')) {
            const at = room === null ? center : roundPoint(polygonCentroid(room.polygon));
            void this.editor.create('item', {kind: choice.slice(5), x: at.x, y: at.y, roomId: room?.id ?? null});
        } else if (choice === 'wall') {
            void this.editor.create('wall', {
                startX: center.x - NewWallLength / 2,
                startY: center.y,
                endX: center.x + NewWallLength / 2,
                endY: center.y,
            });
        } else if (choice === 'Door' || choice === 'Window') {
            const wall = this.selectedWall();
            if (wall !== null) {
                const length = Math.floor(Math.hypot(wall.endX - wall.startX, wall.endY - wall.startY));
                const width = choice === 'Door' ? 900 : 1200;
                void this.editor.create('opening', {wallId: wall.id, type: choice, offset: Math.max(0, Math.round((length - width) / 2))});
            }
        } else if (choice === 'room') {
            const half = NewRoomSize / 2;
            void this.editor.create('room', {
                name: 'New room',
                code: `R${this.scene().rooms.length + 1}`,
                polygon: [
                    [center.x - half, center.y - half],
                    [center.x + half, center.y - half],
                    [center.x + half, center.y + half],
                    [center.x - half, center.y + half],
                ],
            });
        }
    }

    protected kindLabel(kind: string): string {
        return kind.replace(/([a-z])([A-Z])/g, '$1 $2');
    }

    protected onKeydown(event: KeyboardEvent): void {
        if (!this.editor.enabled() || isTyping(event.target)) {
            return;
        }
        const mod = event.ctrlKey || event.metaKey;
        const key = event.key.toLowerCase();

        if (mod && key === 'z') {
            event.preventDefault();
            void (event.shiftKey ? this.editor.redo() : this.editor.undo());
        } else if (mod && key === 'y') {
            event.preventDefault();
            void this.editor.redo();
        } else if (mod && key === 'd') {
            event.preventDefault();
            void this.editor.duplicateSelected();
        } else if (!mod && (event.key === 'Delete' || event.key === 'Backspace')) {
            event.preventDefault();
            void this.editor.deleteSelected();
        } else if (event.key === 'Escape') {
            this.editor.selection.set(null);
        } else if (!mod) {
            this.moveItem(event);
        }
    }

    /** Arrow keys nudge the selected item, R turns it by 90°. */
    private moveItem(event: KeyboardEvent): void {
        const selected = this.selected();
        if (selected?.kind !== 'item') {
            return;
        }
        const item = selected.element as unknown as Item;
        const step = event.shiftKey ? NudgeStepCoarse : NudgeStep;
        const moves: Record<string, Partial<Item>> = {
            ArrowLeft: {x: item.x - step},
            ArrowRight: {x: item.x + step},
            ArrowUp: {y: item.y + step},
            ArrowDown: {y: item.y - step},
            r: {rotation: (item.rotation + 90) % 360},
            R: {rotation: (item.rotation + 270) % 360},
        };
        const fields = moves[event.key];
        if (fields !== undefined) {
            event.preventDefault();
            void this.editor.update('item', item.id, fields);
        }
    }

    private selectedRoom(): Room | null {
        const selected = this.selected();
        return selected?.kind === 'room' ? (selected.element as unknown as Room) : null;
    }
}

function findElement(scene: Scene, kind: ElementKind, id: number): Record<string, unknown> | null {
    const find = <T extends {id: number}>(list: readonly T[]): Record<string, unknown> | null =>
        (list.find((e) => e.id === id) as unknown as Record<string, unknown> | undefined) ?? null;
    switch (kind) {
        case 'item':
            return find(scene.items);
        case 'wall':
            return find(scene.walls);
        case 'room':
            return find(scene.rooms);
        case 'opening':
            return find(scene.walls.flatMap((w): Opening[] => w.openings));
    }
}

function roundPoint(p: {x: number; y: number}): {x: number; y: number} {
    return {x: Math.round(p.x), y: Math.round(p.y)};
}

function isTyping(target: EventTarget | null): boolean {
    return target instanceof HTMLInputElement || target instanceof HTMLTextAreaElement || target instanceof HTMLSelectElement;
}
