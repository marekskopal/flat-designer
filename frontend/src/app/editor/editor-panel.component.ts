import {ChangeDetectionStrategy, Component, computed, inject, input} from '@angular/core';
import {roomCodeText} from '@app/core/room-code';
import {Group, Item, RoofWindow, Scene, SectionLine, VariantSet} from '@app/models/scene';
import {AlertService} from '@app/services/alert.service';
import {IconComponent} from '@app/shared/icon/icon.component';
import {roofLocal} from '@app/viewer/engine/roof-geometry';

import {EditorStore} from './editor.store';
import {findElement, itemCategory, itemRoom, KindIcons, KindLabels, linkedLights} from './element-kinds';
import {FieldChange, InspectorComponent} from './inspector.component';
import {SwitchLight, SwitchLightsComponent} from './switch-lights.component';



/** Nudge steps for arrow keys, mm (Shift: coarse). */
const NudgeStep = 10;
const NudgeStepCoarse = 100;

/**
 * The editor's inspector panel: the selected element's form and actions (duplicate, turn, delete), or the grouping of
 * the Shift-picked items; nothing while nothing is selected. It also holds the keyboard shortcuts while editing.
 */
@Component({
    selector: 'fd-editor-panel',
    imports: [InspectorComponent, IconComponent, SwitchLightsComponent],
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
    /** The selected switch's lights (while picking, the picked ones); null when no switch is selected. */
    protected readonly switchLights = computed((): SwitchLight[] | null => {
        const selected = this.selected();
        const scene = this.scene();
        const item = selected?.kind === 'item' ? (selected.element as unknown as Item) : null;
        if (item === null || itemCategory(scene, item) !== 'switch') {
            return null;
        }
        const linking = this.editor.linking();
        const ids = linking?.switchId === item.id ? linking.lightIds : (item.controls ?? []);
        return linkedLights(scene, ids).map((light) => {
            const room = itemRoom(scene, light);
            return {id: light.id, name: light.name, roomCode: room === null ? null : roomCodeText(room.code)};
        });
    });
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

    /** Unlinks a light: while picking, from the picked ones; else at once, as one change. */
    protected unlinkLight(lightId: number): void {
        const linking = this.editor.linking();
        const selected = this.selected();
        if (linking !== null) {
            this.editor.toggleLinked(lightId);
        } else if (selected?.kind === 'item') {
            const controls = (selected.element as unknown as Item).controls ?? [];
            void this.editor.update('item', selected.element['id'] as number, {controls: controls.filter((id) => id !== lightId)});
        }
    }

    protected selectLight(lightId: number): void {
        this.editor.select({kind: 'item', id: lightId});
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

        if (this.editor.linking() !== null) {
            this.linkingKey(event, mod, key);
            return;
        }
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
        } else if (!mod && !event.altKey && key === 'l' && this.switchLights() !== null) {
            event.preventDefault();
            this.editor.startLinking();
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

    /**
     * While a switch's lights are picked, Enter or a bare L saves them and Esc cancels; no other editing key acts (Delete
     * would delete the switch, undo would change what the picked list is saved over). Browser shortcuts pass.
     */
    private linkingKey(event: KeyboardEvent, mod: boolean, key: string): void {
        if (mod || event.altKey) {
            return;
        }
        if (event.key === 'Escape') {
            event.preventDefault();
            this.editor.cancelLinking();
        } else if (event.key === 'Enter' || key === 'l') {
            event.preventDefault();
            void this.editor.commitLinking();
        } else if (event.key === 'Delete' || event.key === 'Backspace' || /^arrow/i.test(event.key) || key === 'r') {
            event.preventDefault();
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


function isTyping(target: EventTarget | null): boolean {
    return target instanceof HTMLInputElement || target instanceof HTMLTextAreaElement || target instanceof HTMLSelectElement;
}
