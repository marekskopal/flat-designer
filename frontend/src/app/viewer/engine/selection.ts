import * as THREE from 'three';

/** What can be selected in the viewer: an element of the floor, or a group of items (picked through one of them). */
export type ElementKind =
    | 'item'
    | 'wall'
    | 'opening'
    | 'room'
    | 'plot'
    | 'stair'
    | 'void'
    | 'railing'
    | 'roof'
    | 'roofWindow'
    | 'group'
    | 'variantSet';

export interface Selection {
    kind: ElementKind;
    id: number;
}

/**
 * The element a scene object belongs to: the nearest ancestor (or the object itself) tagged by the
 * builders with `itemId`, `openingId`, `wallId`, `roomId`, `plotId`, `stairId`, `voidId`, `railingId`, `roofWindowId` or
 * `roofId` in its `userData`. A
 * door hit therefore selects the opening, not the wall around it, and a roof window hit the window, not its roof.
 */
export function selectionOf(object: THREE.Object3D | null): Selection | null {
    for (let o: THREE.Object3D | null = object; o !== null; o = o.parent) {
        const data = o.userData as Record<string, unknown>;
        for (const [key, kind] of TagKinds) {
            const id = data[key];
            if (typeof id === 'number') {
                return {kind, id};
            }
        }
    }
    return null;
}

export function sameSelection(a: Selection | null, b: Selection | null): boolean {
    return a === b || (a !== null && b !== null && a.kind === b.kind && a.id === b.id);
}

const TagKinds: readonly (readonly [string, ElementKind])[] = [
    ['itemId', 'item'],
    ['openingId', 'opening'],
    ['wallId', 'wall'],
    ['roomId', 'room'],
    ['plotId', 'plot'],
    ['stairId', 'stair'],
    ['voidId', 'void'],
    ['railingId', 'railing'],
    ['roofWindowId', 'roofWindow'],
    ['roofId', 'roof'],
];
