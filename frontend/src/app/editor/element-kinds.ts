import {levelText} from '@app/core/level-text';
import {fixed} from '@app/core/number-format';
import {ElectricalCategory} from '@app/models/electrical-schedule';
import {Item, Opening, Room, Scene, Wall} from '@app/models/scene';
import {IconName} from '@app/shared/icon/icon.component';
import {electricalPoints, switchLinks} from '@app/viewer/engine/electrical';
import {pointInPolygon, wallLength} from '@app/viewer/engine/plan-geometry';
import {ElementKind} from '@app/viewer/engine/selection';
import {TranslateService} from '@ngx-translate/core';


/** What each kind of element is called in the inspector and the viewer's card: translation keys. */
export const KindLabelKeys: Record<ElementKind, string> = {
    item: 'kinds.item',
    wall: 'kinds.wall',
    opening: 'kinds.opening',
    room: 'kinds.room',
    plot: 'kinds.plot',
    stair: 'kinds.stair',
    void: 'kinds.void',
    railing: 'kinds.railing',
    section: 'kinds.section',
    roof: 'kinds.roof',
    roofWindow: 'kinds.roofWindow',
    group: 'kinds.group',
    variantSet: 'kinds.variantSet',
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

/** What an item counts as in the electrical schedule: its definition's `category` (an LED strip is a light), null for none. */
export function itemCategory(scene: Scene, item: Pick<Item, 'kind' | 'definitionId'>): ElectricalCategory | null {
    if (item.kind === 'LedStrip') {
        return 'light';
    }
    if (item.kind !== 'Custom' || item.definitionId === null) {
        return null;
    }
    return (scene.definitions ?? []).find((d) => d.id === item.definitionId)?.definition.category ?? null;
}

/**
 * The shown lights among `controls` (a switch's, or the ones being picked), as the electrical plan draws them
 * (`electricalPoints()`: shown variants, deleted lights not in the scene), in the scene's order.
 */
export function linkedLights(scene: Scene, controls: readonly number[]): Item[] {
    const ids = new Set(controls);
    const lights = new Set(electricalPoints(scene).filter((p) => p.category === 'light' && ids.has(p.itemId)).map((p) => p.itemId));
    return scene.items.filter((i) => lights.has(i.id));
}

/** The shown switches controlling a light, as the electrical plan links them (`switchLinks()`). */
export function switchesOf(scene: Scene, lightId: number): Item[] {
    const switches = new Set(switchLinks(electricalPoints(scene)).filter((l) => l.lightIds.includes(lightId)).map((l) => l.switchId));
    return scene.items.filter((i) => switches.has(i.id));
}

/** The item's room as the schedules count it: its `roomId`, else the room whose outline holds its centre; null outside every room. */
export function itemRoom(scene: Scene, item: Pick<Item, 'roomId' | 'x' | 'y'>): Room | null {
    return (
        scene.rooms.find((r) => r.id === item.roomId)
        ?? scene.rooms.find((r) => pointInPolygon({x: item.x, y: item.y}, r.polygon))
        ?? null
    );
}

/** A fact the viewer's card shows about a selected element, translated. */
export interface ElementFact {
    label: string;
    value: string;
}

/** A switch's lights, or the switches of a light. */
function switchFacts(scene: Scene, element: Record<string, unknown>, t: (key: string) => string): ElementFact[] {
    const item = element as unknown as Item;
    const names = (items: Item[]): string => items.map((i) => i.name).join(', ');
    switch (itemCategory(scene, item)) {
        case 'switch': {
            const lights = linkedLights(scene, item.controls ?? []);
            return [{label: t('kinds.facts.controls'), value: lights.length === 0 ? t('kinds.facts.noLights') : names(lights)}];
        }
        case 'light': {
            const switches = switchesOf(scene, item.id);
            return switches.length === 0 ? [] : [{label: t('kinds.facts.switchedBy'), value: names(switches)}];
        }
        default:
            return [];
    }
}


/**
 * What the viewer's card says about a selected element (not a room, which has a card of its own): its size and where
 * it is, by kind; nothing for kinds without a measure worth showing. The labels come translated by `translate`.
 */
export function elementFacts(
    scene: Scene,
    kind: ElementKind,
    element: Record<string, unknown>,
    translate: TranslateService,
    decimal = '.',
): ElementFact[] {
    const t = (key: string): string => translate.instant(key) as string;
    const metres = (mm: unknown): string => (typeof mm === 'number' ? `${fixed(mm / 1000, 2, decimal)} m` : '—');
    const size = (...mm: unknown[]): string => mm.map((v) => (typeof v === 'number' ? fixed(v / 1000, 2, decimal) : '?')).join(' × ') + ' m';
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
                {label: t('kinds.facts.size'), value: size(element['width'], element['depth'], element['height'])},
                {label: t('kinds.facts.elevation'), value: typeof element['z'] === 'number' ? levelText(element['z'], decimal) : '—'},
                ...(from === undefined ? [] : [{label: t('kinds.facts.madeFrom'), value: from}]),
                ...(room === undefined ? [] : [{label: t('kinds.facts.room'), value: room.name}]),
                ...switchFacts(scene, element, t),
            ];
        }
        case 'wall':
            return [
                {label: t('kinds.facts.length'), value: metres(wallLength(element as unknown as Wall))},
                {label: t('kinds.facts.thickness'), value: metres(element['thickness'])},
                {label: t('kinds.facts.height'), value: metres(element['height'])},
            ];
        case 'opening':
            return [
                {label: t('kinds.facts.type'), value: t(`enums.openingType.${String(element['type'])}`)},
                {label: t('kinds.facts.size'), value: size(element['width'], element['height'])},
                {label: t('kinds.facts.sill'), value: metres(element['sillHeight'])},
            ];
        case 'stair':
            return [
                {label: t('kinds.facts.shape'), value: String(element['shape']).replace(/([a-z])([A-Z])/g, '$1 $2')},
                {label: t('kinds.facts.width'), value: metres(element['width'])},
                {label: t('kinds.facts.going'), value: metres(element['going'])},
            ];
        case 'roof':
            return [
                {label: t('kinds.facts.shape'), value: String(element['shape'])},
                {label: t('kinds.facts.size'), value: size(element['width'], element['depth'])},
                ...(element['shape'] === 'Flat' ? [] : [{label: t('kinds.facts.pitch'), value: `${String(element['pitch'])}°`}]),
            ];
        case 'railing':
            return [
                {label: t('kinds.facts.style'), value: String(element['style'])},
                {label: t('kinds.facts.height'), value: metres(element['height'])},
            ];
        case 'group':
            return [{label: t('kinds.facts.items'), value: String(scene.items.filter((i) => i.groupId === element['id']).length)}];
        default:
            return [];
    }
}
