import {ChangeDetectionStrategy, Component, computed, inject, input} from '@angular/core';
import {Group, Item, Opening, RoofWindow, Scene, SectionLine, VariantSet} from '@app/models/scene';
import {AlertService} from '@app/services/alert.service';
import {IconComponent, IconName} from '@app/shared/icon/icon.component';
import {roofLocal} from '@app/viewer/engine/roof-geometry';
import {ElementKind} from '@app/viewer/engine/selection';

import {EditorStore} from './editor.store';
import {FieldChange, InspectorComponent} from './inspector.component';

const KindLabels: Record<ElementKind, string> = {
    item: 'Item',
    wall: 'Wall',
    opening: 'Door / window',
    room: 'Room',
    plot: 'Plot',
    stair: 'Stairs',
    void: 'Void',
    railing: 'Railing',
    section: 'Section line',
    roof: 'Roof',
    roofWindow: 'Roof window',
    group: 'Group',
    variantSet: 'Variant set',
};

const KindIcons: Record<ElementKind, IconName> = {
    item: 'chair',
    wall: 'wall',
    opening: 'door',
    room: 'rooms',
    plot: 'plot',
    stair: 'stairs',
    void: 'void',
    railing: 'railing',
    section: 'section',
    roof: 'roof',
    roofWindow: 'window',
    group: 'group',
    variantSet: 'variants',
};

/** Nudge steps for arrow keys, mm (Shift: coarse). */
const NudgeStep = 10;
const NudgeStepCoarse = 100;

/**
 * The editor's inspector panel: the selected element's form and actions (duplicate, turn, delete), or the grouping of
 * the Shift-picked items; nothing while nothing is selected. It also holds the keyboard shortcuts while editing.
 */
@Component({
    selector: 'fd-editor-panel',
    imports: [InspectorComponent, IconComponent],
    templateUrl: './editor-panel.component.html',
    styleUrl: './editor-panel.component.scss',
    changeDetection: ChangeDetectionStrategy.OnPush,
    host: {'(document:keydown)': 'onKeydown($event)'},
})
export class EditorPanelComponent {
    public readonly scene = input.required<Scene>();

    protected readonly editor = inject(EditorStore);
    private readonly alerts = inject(AlertService);

    /** The selected element's current state, or null when it is gone (deleted, other floor). */
    protected readonly selected = computed(() => {
        const selection = this.editor.selection();
        const scene = this.scene();
        if (selection === null) {
            return null;
        }
        const element = findElement(scene, selection.kind, selection.id);
        if (element === null) {
            return null;
        }
        const name = typeof element['name'] === 'string' && element['name'] !== '' ? element['name'] : KindLabels[selection.kind];
        return {kind: selection.kind, label: KindLabels[selection.kind], icon: KindIcons[selection.kind], name, element};
    });
    /** The selected element turns by 90° (R): items, groups, stairs, roofs; a section line turns round. */
    protected readonly canTurn = computed(() => {
        const kind = this.selected()?.kind;
        return kind === 'item' || kind === 'group' || kind === 'stair' || kind === 'roof' || kind === 'section';
    });
    /** Items of the selected group. */
    protected readonly groupMembers = computed((): Item[] => {
        const selection = this.editor.selection();
        return selection?.kind === 'group' ? this.scene().items.filter((i) => i.groupId === selection.id) : [];
    });
    protected readonly groups = computed((): Group[] => this.scene().groups ?? []);
    /** The variant set the selected group is a variant of. */
    protected readonly groupSet = computed((): VariantSet | null => {
        const selected = this.selected();
        const setId = selected?.kind === 'group' ? (selected.element as unknown as Group).variantSetId : null;
        return (this.scene().variantSets ?? []).find((set) => set.id === setId) ?? null;
    });
    protected onFieldChange(change: FieldChange): void {
        const selection = this.editor.selection();
        if (selection === null) {
            return;
        }
        const fields = selection.kind === 'item' ? this.customItemFields(change) : {[change.key]: change.value};
        if (fields !== null) {
            void this.editor.update(selection.kind, selection.id, fields);
        }
    }

    /**
     * An item's field change with what goes with it for Custom items: another definition starts afresh at its default
     * size with its name (as a new item does), a kind change to or from Custom sets or clears the definition. Null when
     * it cannot be made.
     */
    private customItemFields(change: FieldChange): Record<string, unknown> | null {
        const element = this.selected()?.element ?? {};
        if (change.key === 'definitionId') {
            return this.freshCustom(change.value);
        }
        if (change.key !== 'kind') {
            return {[change.key]: change.value};
        }
        if (change.value === 'Custom') {
            const first = this.editor.definitions()[0];
            if (first === undefined) {
                this.alerts.error('There is no furniture definition to use; add one under Furniture.');
                return null;
            }
            return this.freshCustom(first.id);
        }
        if (change.value === 'Model') {
            const first = this.editor.models()[0];
            if (first === undefined) {
                this.alerts.error('There is no 3D model to use; upload one under Furniture.');
                return null;
            }
            return {kind: 'Model', modelId: first.id, name: first.name, width: first.width, depth: first.depth, height: first.height};
        }
        return element['kind'] === 'Custom' ? {kind: change.value, definitionId: null, params: {}} : {kind: change.value};
    }

    private freshCustom(definitionId: unknown): Record<string, unknown> {
        const definition = this.editor.definitions().find((d) => d.id === definitionId);
        const size = definition?.definition.size;
        return {
            kind: 'Custom',
            definitionId,
            params: {},
            ...(definition === undefined ? {} : {name: definition.name}),
            ...(size === undefined ? {} : {width: size.width, depth: size.depth, height: size.height}),
        };
    }

    protected groupPicked(name: string): void {
        void this.editor.groupPicked(name.trim() === '' ? 'Group' : name.trim());
    }

    protected makeVariants(element: Record<string, unknown>): void {
        const group = element as unknown as Group;
        void this.editor.createVariantSet(`${group.name} variants`, group.id);
    }

    protected selectSet(id: number): void {
        this.editor.select({kind: 'variantSet', id});
    }

    /** The Turn button: as R does. */
    protected turn(): void {
        this.moveItem(new KeyboardEvent('keydown', {key: 'r'}));
        this.moveGroup(new KeyboardEvent('keydown', {key: 'r'}));
    }

    protected selectItem(item: Item): void {
        this.editor.select({kind: 'item', id: item.id});
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
            if (this.editor.tool() === 'select') {
                this.editor.select(null);
            } else {
                this.editor.setTool('select');
            }
        } else if (!mod && !event.altKey && (key === 'w' || key === 'm' || key === 'b')) {
            const tool = key === 'w' ? 'drawWalls' : key === 'm' ? 'detectRoom' : 'drawRailing';
            this.editor.setTool(this.editor.tool() === tool ? 'select' : tool);
        } else if (!mod) {
            this.moveItem(event);
            this.moveGroup(event);
        }
    }

    /** Arrow keys nudge the selected group, R turns it by 90° about its centre. */
    private moveGroup(event: KeyboardEvent): void {
        const selection = this.editor.selection();
        if (selection?.kind !== 'group') {
            return;
        }
        const step = event.shiftKey ? NudgeStepCoarse : NudgeStep;
        const moves: Record<string, {dx?: number; dy?: number; rotation?: number}> = {
            ArrowLeft: {dx: -step},
            ArrowRight: {dx: step},
            ArrowUp: {dy: step},
            ArrowDown: {dy: -step},
            r: {rotation: 90},
            R: {rotation: -90},
        };
        const move = moves[event.key];
        if (move !== undefined) {
            event.preventDefault();
            void this.editor.moveGroup(selection.id, move);
        }
    }

    /**
     * Arrow keys nudge the selected item, stairs, roof, roof window or section line by the step in plan, R turns the
     * first three by 90° and a section line round (it then looks the other way).
     */
    private moveItem(event: KeyboardEvent): void {
        const selected = this.selected();
        if (selected?.kind === 'section') {
            this.moveSectionLine(event, selected.element as unknown as SectionLine);
            return;
        }
        if (selected?.kind === 'roofWindow') {
            this.moveRoofWindow(event, selected.element as unknown as RoofWindow & {roofId: number});
            return;
        }
        if (selected?.kind !== 'item' && selected?.kind !== 'stair' && selected?.kind !== 'roof') {
            return;
        }
        const item = selected.element as unknown as Pick<Item, 'id' | 'x' | 'y' | 'rotation'>;
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
            void this.editor.update(selected.kind, item.id, fields);
        }
    }

    private moveSectionLine(event: KeyboardEvent, line: SectionLine): void {
        const step = event.shiftKey ? NudgeStepCoarse : NudgeStep;
        const shift = (dx: number, dy: number): Partial<SectionLine> => ({
            startX: line.startX + dx,
            startY: line.startY + dy,
            endX: line.endX + dx,
            endY: line.endY + dy,
        });
        const turned: Partial<SectionLine> = {startX: line.endX, startY: line.endY, endX: line.startX, endY: line.startY};
        const moves: Record<string, Partial<SectionLine>> = {
            ArrowLeft: shift(-step, 0),
            ArrowRight: shift(step, 0),
            ArrowUp: shift(0, step),
            ArrowDown: shift(0, -step),
            r: turned,
            R: turned,
        };
        const fields = moves[event.key];
        if (fields !== undefined) {
            event.preventDefault();
            void this.editor.update('section', line.id, fields);
        }
    }

    /** The arrows move the window as they do an item, in plan: the step is turned into the roof's frame (along and across the ridge). */
    private moveRoofWindow(event: KeyboardEvent, window: RoofWindow & {roofId: number}): void {
        const step = event.shiftKey ? NudgeStepCoarse : NudgeStep;
        const moves: Record<string, {x: number; y: number}> = {
            ArrowLeft: {x: -step, y: 0},
            ArrowRight: {x: step, y: 0},
            ArrowUp: {x: 0, y: step},
            ArrowDown: {x: 0, y: -step},
        };
        const move = moves[event.key];
        const roof = (this.scene().roofs ?? []).find((r) => r.id === window.roofId);
        if (move === undefined || roof === undefined) {
            return;
        }
        event.preventDefault();
        const local = roofLocal({x: 0, y: 0, rotation: roof.rotation}, move.x, move.y);
        void this.editor.update('roofWindow', window.id, {along: Math.round(window.along + local.x), across: Math.round(window.across + local.y)});
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
        case 'plot':
            return find(scene.plots ?? []);
        case 'stair': {
            // The rise is the floor's, not the stair's: the inspector's step hint reads it beside the fields.
            const stair = find(scene.stairs ?? []);
            return stair === null ? null : {...stair, stairRise: scene.stairRise ?? 0};
        }
        case 'void':
            return find(scene.voids ?? []);
        case 'railing':
            return find(scene.railings ?? []);
        case 'section':
            return find(scene.sections ?? []);
        case 'roof':
            return find(scene.roofs ?? []);
        case 'roofWindow':
            return find((scene.roofs ?? []).flatMap((roof) => roof.windows.map((window) => ({...window, roofId: roof.id}))));
        case 'opening':
            return find(scene.walls.flatMap((w): Opening[] => w.openings));
        case 'group':
            return find(scene.groups ?? []);
        case 'variantSet':
            return find(scene.variantSets ?? []);
    }
}

function isTyping(target: EventTarget | null): boolean {
    return target instanceof HTMLInputElement || target instanceof HTMLTextAreaElement || target instanceof HTMLSelectElement;
}
