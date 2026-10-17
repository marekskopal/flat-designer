import {ElementKind} from '@app/viewer/engine/selection';

export type FieldType = 'int' | 'text' | 'select' | 'material' | 'room' | 'json' | 'bool';

/** One editable field of an element, as the inspector shows it. */
export interface FieldDef {
    key: string;
    label: string;
    type: FieldType;
    /** May be cleared (empty input = null). */
    nullable?: boolean;
    options?: readonly string[];
    unit?: string;
    /** Shown in pairs on one row. */
    half?: boolean;
}

const ItemKinds = [
    'BaseCabinet', 'WallCabinet', 'TallCabinet', 'Worktop', 'Backsplash', 'Sink', 'Hob', 'LedStrip', 'Box',
    'Bathtub', 'Washbasin', 'Toilet', 'WashingMachine', 'Mirror', 'TowelRadiator', 'FlushPlate', 'Fixture', 'Fence',
] as const;

const RoomTypes = ['Living', 'Bedroom', 'Bathroom', 'Toilet', 'Hallway', 'Terrace', 'Garden'] as const;

/** The editable fields per element kind; keys are those of the scene JSON and the edit API. */
export const ElementFields: Record<ElementKind, readonly FieldDef[]> = {
    item: [
        {key: 'name', label: 'Name', type: 'text'},
        {key: 'kind', label: 'Kind', type: 'select', options: ItemKinds},
        {key: 'x', label: 'X', type: 'int', unit: 'mm', half: true},
        {key: 'y', label: 'Y', type: 'int', unit: 'mm', half: true},
        {key: 'width', label: 'Width', type: 'int', unit: 'mm', half: true},
        {key: 'depth', label: 'Depth', type: 'int', unit: 'mm', half: true},
        {key: 'height', label: 'Height', type: 'int', unit: 'mm', half: true},
        {key: 'z', label: 'Elevation', type: 'int', unit: 'mm', half: true},
        {key: 'rotation', label: 'Rotation', type: 'int', unit: '°'},
        {key: 'roomId', label: 'Room', type: 'room', nullable: true},
        {key: 'materialId', label: 'Material', type: 'material', nullable: true},
        {key: 'params', label: 'Params (JSON)', type: 'json'},
    ],
    wall: [
        {key: 'name', label: 'Name', type: 'text'},
        {key: 'type', label: 'Type', type: 'select', options: ['Exterior', 'Interior', 'Shaft']},
        {key: 'startX', label: 'Start X', type: 'int', unit: 'mm', half: true},
        {key: 'startY', label: 'Start Y', type: 'int', unit: 'mm', half: true},
        {key: 'endX', label: 'End X', type: 'int', unit: 'mm', half: true},
        {key: 'endY', label: 'End Y', type: 'int', unit: 'mm', half: true},
        {key: 'thickness', label: 'Thickness', type: 'int', unit: 'mm', half: true},
        {key: 'height', label: 'Height', type: 'int', unit: 'mm', half: true},
        {key: 'materialId', label: 'Material', type: 'material', nullable: true},
    ],
    opening: [
        {key: 'name', label: 'Name', type: 'text'},
        {key: 'type', label: 'Type', type: 'select', options: ['Door', 'Window']},
        {key: 'offset', label: 'Offset from wall start', type: 'int', unit: 'mm'},
        {key: 'width', label: 'Width', type: 'int', unit: 'mm', half: true},
        {key: 'height', label: 'Height', type: 'int', unit: 'mm', half: true},
        {key: 'sillHeight', label: 'Sill', type: 'int', unit: 'mm', half: true},
        {key: 'leafWidth', label: 'Leaf width', type: 'int', unit: 'mm', nullable: true, half: true},
        {key: 'hinge', label: 'Hinge', type: 'select', options: ['Start', 'End'], nullable: true, half: true},
        {key: 'side', label: 'Swing side', type: 'select', options: ['Left', 'Right'], nullable: true, half: true},
        {key: 'entrance', label: 'Main entrance (walkthrough starts here)', type: 'bool'},
    ],
    room: [
        {key: 'name', label: 'Name', type: 'text'},
        {key: 'code', label: 'Code', type: 'text', half: true},
        {key: 'type', label: 'Type', type: 'select', options: RoomTypes, half: true},
        {key: 'ceilingHeight', label: 'Ceiling height', type: 'int', unit: 'mm', nullable: true},
        {key: 'floorMaterialId', label: 'Floor material', type: 'material', nullable: true},
        {key: 'polygon', label: 'Outline [[x, y], …] (JSON)', type: 'json'},
        {key: 'wallFinishes', label: 'Wall finishes [{materialId, bottom, top}] (JSON)', type: 'json'},
    ],
};

export type ParsedValue = {ok: true; value: unknown} | {ok: false; error: string};

/** The input text for a field's current value. */
export function formatFieldValue(def: FieldDef, value: unknown): string {
    if (value === null || value === undefined) {
        return '';
    }
    if (def.type === 'json') {
        return JSON.stringify(value);
    }
    return String(value);
}

/** Turns what the user typed into the value the API expects, or explains why it is not valid. */
export function parseFieldValue(def: FieldDef, raw: string): ParsedValue {
    const text = raw.trim();
    if (text === '') {
        if (def.nullable === true) {
            return {ok: true, value: null};
        }
        if (def.type === 'json') {
            return {ok: false, error: 'Enter JSON.'};
        }
        return {ok: false, error: `${def.label} is required.`};
    }

    switch (def.type) {
        case 'bool':
            return {ok: true, value: text === 'true'};
        case 'int': {
            if (!/^-?\d+$/.test(text)) {
                return {ok: false, error: 'Whole number expected.'};
            }
            return {ok: true, value: Number(text)};
        }
        case 'material':
        case 'room':
            return {ok: true, value: Number(text)};
        case 'json':
            try {
                return {ok: true, value: JSON.parse(text) as unknown};
            } catch {
                return {ok: false, error: 'Invalid JSON.'};
            }
        default:
            return {ok: true, value: text};
    }
}

/** Whether a parsed value differs from the current one (JSON values compared by content). */
export function isChanged(current: unknown, next: unknown): boolean {
    return JSON.stringify(current ?? null) !== JSON.stringify(next ?? null);
}
