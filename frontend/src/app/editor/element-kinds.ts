import {levelText} from '@app/core/level-text';
import {Opening, OpeningType, Scene, Wall} from '@app/models/scene';
import {IconName} from '@app/shared/icon/icon.component';
import {wallLength} from '@app/viewer/engine/plan-geometry';
import {ElementKind} from '@app/viewer/engine/selection';

import {OpeningTypeLabels} from './element-fields';

/** What each kind of element is called and drawn as in the inspector and the viewer's card. */
export const KindLabels: Record<ElementKind, string> = {
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

export const KindIcons: Record<ElementKind, IconName> = {
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

/** The selected element's current state in the scene, or null when it is gone (deleted, another floor). */
export function findElement(scene: Scene, kind: ElementKind, id: number): Record<string, unknown> | null {
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

/** A fact the viewer's card shows about a selected element. */
export interface ElementFact {
    label: string;
    value: string;
}

const metres = (mm: unknown): string => (typeof mm === 'number' ? `${(mm / 1000).toFixed(2)} m` : '—');
const size = (...mm: unknown[]): string => mm.map((v) => (typeof v === 'number' ? (v / 1000).toFixed(2) : '?')).join(' × ') + ' m';

/**
 * What the viewer's card says about a selected element (not a room, which has a card of its own): its size and where
 * it is, by kind; nothing for kinds without a measure worth showing.
 */
export function elementFacts(scene: Scene, kind: ElementKind, element: Record<string, unknown>): ElementFact[] {
    switch (kind) {
        case 'item': {
            const room = scene.rooms.find((r) => r.id === element['roomId']);
            const from =
                element['kind'] === 'Custom'
                    ? (scene.definitions ?? []).find((d) => d.id === element['definitionId'])?.name
                    : element['kind'] === 'Model'
                      ? (scene.models ?? []).find((m) => m.id === element['modelId'])?.name
                      : String(element['kind']).replace(/([a-z])([A-Z])/g, '$1 $2');
            return [
                {label: 'Size', value: size(element['width'], element['depth'], element['height'])},
                {label: 'Elevation', value: typeof element['z'] === 'number' ? levelText(element['z']) : '—'},
                ...(from === undefined ? [] : [{label: 'Made from', value: from}]),
                ...(room === undefined ? [] : [{label: 'Room', value: room.name}]),
            ];
        }
        case 'wall':
            return [
                {label: 'Length', value: metres(wallLength(element as unknown as Wall))},
                {label: 'Thickness', value: metres(element['thickness'])},
                {label: 'Height', value: metres(element['height'])},
            ];
        case 'opening':
            return [
                {label: 'Type', value: OpeningTypeLabels[element['type'] as OpeningType] ?? String(element['type'])},
                {label: 'Size', value: size(element['width'], element['height'])},
                {label: 'Sill', value: metres(element['sillHeight'])},
            ];
        case 'stair':
            return [
                {label: 'Shape', value: String(element['shape']).replace(/([a-z])([A-Z])/g, '$1 $2')},
                {label: 'Width', value: metres(element['width'])},
                {label: 'Going', value: metres(element['going'])},
            ];
        case 'roof':
            return [
                {label: 'Shape', value: String(element['shape'])},
                {label: 'Size', value: size(element['width'], element['depth'])},
                ...(element['shape'] === 'Flat' ? [] : [{label: 'Pitch', value: `${String(element['pitch'])}°`}]),
            ];
        case 'railing':
            return [
                {label: 'Style', value: String(element['style'])},
                {label: 'Height', value: metres(element['height'])},
            ];
        case 'group':
            return [{label: 'Items', value: String(scene.items.filter((i) => i.groupId === element['id']).length)}];
        default:
            return [];
    }
}
