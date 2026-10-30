import {ChangeDetectionStrategy, Component, computed, inject, input} from '@angular/core';
import {FurnitureDefinition, ScopeLabels} from '@app/models/furniture';
import {Group, Item, Opening, OpeningType, Roof, RoofWindow, Room, Scene, VariantSet, Wall, WallType} from '@app/models/scene';
import {AlertService} from '@app/services/alert.service';
import {EditorTool} from '@app/viewer/engine/drawing';
import {polygonCentroid, wallLength} from '@app/viewer/engine/plan-geometry';
import {roofLocal} from '@app/viewer/engine/roof-geometry';
import {ElementKind} from '@app/viewer/engine/selection';

import {ActivityComponent} from './activity.component';
import {EditorStore, newRoomFields} from './editor.store';
import {OpeningTypeLabels} from './element-fields';
import {FieldChange, InspectorComponent} from './inspector.component';

const KindLabels: Record<ElementKind, string> = {
    item: 'Item',
    wall: 'Wall',
    opening: 'Door / window',
    room: 'Room',
    plot: 'Plot',
    stair: 'Stairs',
    roof: 'Roof',
    roofWindow: 'Roof window',
    group: 'Group',
    variantSet: 'Variant set',
};

/** The tools of the top view, with their shortcuts. */
const Tools: {tool: EditorTool; label: string; title: string}[] = [
    {tool: 'select', label: 'Select', title: 'Select and drag elements (Esc)'},
    {tool: 'drawWalls', label: 'Draw walls', title: 'Click the centre-line points of a run of walls; type a length for an exact one (W)'},
    {tool: 'detectRoom', label: 'Room from walls', title: 'Click inside walls to add the room they enclose (M)'},
];

const WallTypes: WallType[] = ['Interior', 'Exterior', 'Shaft'];

/** Size of a new wall and a new room, mm. */
const NewWallLength = 3000;
const NewRoomSize = 3000;
/** Width of an opening added from the Add menu (the server's default, used to centre it on the wall). */
const NewOpeningWidths: Record<OpeningType, number> = {
    Door: 900,
    DoubleDoor: 1400,
    SlidingDoor: 900,
    PocketDoor: 900,
    Passage: 900,
    Window: 1200,
    Niche: 600,
    GarageDoor: 2500,
};

/** Nudge steps for arrow keys, mm (Shift: coarse). */
const NudgeStep = 10;
const NudgeStepCoarse = 100;

/**
 * Editing tools of the viewer panel: undo/redo, add, duplicate, delete, the inspector of the selected
 * element (or grouping of the Shift-picked items) and the activity list, plus the keyboard shortcuts while editing.
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
    protected readonly tools = Tools;
    protected readonly wallTypes = WallTypes;
    protected readonly openingTypes = Object.entries(OpeningTypeLabels);
    private readonly alerts = inject(AlertService);

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
    protected readonly selectedWall = computed(() => {
        const selected = this.selected();
        return selected?.kind === 'wall' ? (selected.element as unknown as Wall) : null;
    });
    /** A pitched roof selected, which a roof window can be added to. */
    protected readonly selectedPitchedRoof = computed(() => {
        const selected = this.selected();
        return selected?.kind === 'roof' && selected.element['shape'] !== 'Flat' ? (selected.element as unknown as Roof) : null;
    });

    /** Item kinds of the Add menu; columns and beams go under Building. */
    protected readonly currentKinds = computed(() => this.editor.itemKinds().filter((k) => !k.structural));
    protected readonly structureKinds = computed(() => this.editor.itemKinds().filter((k) => k.structural));

    /** The furniture of the Add menu by where it comes from. */
    protected readonly furnitureSections = computed(() => {
        const sections: {label: string; definitions: FurnitureDefinition[]}[] = [];
        for (const scope of ['project', 'user', 'global'] as const) {
            const definitions = this.editor.definitions().filter((d) => d.scope === scope);
            if (definitions.length > 0) {
                const label = scope === 'user' ? 'Furniture — library' : `Furniture — ${ScopeLabels[scope].toLowerCase()}`;
                sections.push({label, definitions});
            }
        }
        return sections;
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

    protected selectItem(item: Item): void {
        this.editor.select({kind: 'item', id: item.id});
    }

    protected add(event: Event): void {
        const select = event.target as HTMLSelectElement;
        const choice = select.value;
        select.value = '';
        const center = this.viewCenter()();
        const room = this.selectedRoom();

        const at = room === null ? center : roundPoint(polygonCentroid(room.polygon));
        if (choice.startsWith('item:')) {
            void this.editor.create('item', {kind: choice.slice(5), x: at.x, y: at.y, roomId: room?.id ?? null});
        } else if (choice.startsWith('custom:')) {
            void this.editor.create('item', {kind: 'Custom', definitionId: Number(choice.slice(7)), x: at.x, y: at.y, roomId: room?.id ?? null});
        } else if (choice.startsWith('model:')) {
            void this.editor.create('item', {kind: 'Model', modelId: Number(choice.slice(6)), x: at.x, y: at.y, roomId: room?.id ?? null});
        } else if (choice === 'wall') {
            void this.editor.create('wall', {
                startX: center.x - NewWallLength / 2,
                startY: center.y,
                endX: center.x + NewWallLength / 2,
                endY: center.y,
            });
        } else if (choice.startsWith('opening:')) {
            const wall = this.selectedWall();
            if (wall !== null) {
                const type = choice.slice(8) as OpeningType;
                const length = wallLength(wall);
                const width = NewOpeningWidths[type];
                // Centred; a sliding or pocket door opens towards the wall start, so it sits past the middle.
                const offset = type === 'SlidingDoor' || type === 'PocketDoor' ? Math.round((length + width) / 2) : Math.round((length - width) / 2);
                void this.editor.create('opening', {wallId: wall.id, type, offset: Math.max(0, Math.min(offset, length - width))});
            }
        } else if (choice === 'roof') {
            // Its rectangle comes from the backend: round the floor's walls, the ridge along the longer side.
            void this.editor.create('roof', {});
        } else if (choice === 'roofWindow') {
            // The backend puts it low on the roof's front plane.
            const roof = this.selectedPitchedRoof();
            if (roof !== null) {
                void this.editor.create('roofWindow', {roofId: roof.id});
            }
        } else if (choice === 'stair') {
            // The first riser at the view's centre (or the selected room's), walking up north.
            void this.editor.create('stair', {x: at.x, y: at.y});
        } else if (choice === 'plot') {
            // Its boundary comes from the backend: a rectangle round the floor's walls and rooms.
            void this.editor.create('plot', {});
        } else if (choice === 'room') {
            const half = NewRoomSize / 2;
            void this.editor.create('room', {
                ...newRoomFields(this.scene()),
                polygon: [
                    [center.x - half, center.y - half],
                    [center.x + half, center.y - half],
                    [center.x + half, center.y + half],
                    [center.x - half, center.y + half],
                ],
            });
        }
    }

    /** Changes a field of the walls drawn next; values out of range are left as they were. */
    protected setDraft(field: 'type' | 'thickness' | 'height', event: Event): void {
        const value = (event.target as HTMLInputElement | HTMLSelectElement).value;
        const draft = this.editor.wallDraft();
        if (field === 'type') {
            this.editor.wallDraft.set({...draft, type: value as WallType});
            return;
        }
        const [min, max] = field === 'thickness' ? [10, 2000] : [100, 20000];
        const number = Math.round(Number(value));
        if (Number.isFinite(number) && number >= min && number <= max) {
            this.editor.wallDraft.set({...draft, [field]: number});
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
            if (this.editor.tool() === 'select') {
                this.editor.select(null);
            } else {
                this.editor.setTool('select');
            }
        } else if (!mod && !event.altKey && (key === 'w' || key === 'm')) {
            const tool = key === 'w' ? 'drawWalls' : 'detectRoom';
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

    /** Arrow keys nudge the selected item, stairs, roof or roof window by the step in plan, R turns the first three by 90°. */
    private moveItem(event: KeyboardEvent): void {
        const selected = this.selected();
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
        case 'plot':
            return find(scene.plots ?? []);
        case 'stair': {
            // The rise is the floor's, not the stair's: the inspector's step hint reads it beside the fields.
            const stair = find(scene.stairs ?? []);
            return stair === null ? null : {...stair, stairRise: scene.stairRise ?? 0};
        }
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

function roundPoint(p: {x: number; y: number}): {x: number; y: number} {
    return {x: Math.round(p.x), y: Math.round(p.y)};
}

function isTyping(target: EventTarget | null): boolean {
    return target instanceof HTMLInputElement || target instanceof HTMLTextAreaElement || target instanceof HTMLSelectElement;
}
