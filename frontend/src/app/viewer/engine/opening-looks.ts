import {Opening, Wall} from '@app/models/scene';

import {wallFrame} from './plan-geometry';

/** A rectangle in a leaf's or sash's own frame: x from its hinge edge, y up from its bottom, mm. */
export interface PanelRect {
    x: number;
    y: number;
    width: number;
    height: number;
}

/** Stiles (the vertical edge pieces) and top / middle rails of a panelled or glazed leaf, and its taller bottom rail, mm. */
export const LeafStile = 110;
export const LeafRail = 110;
export const LeafBottomRail = 200;

/**
 * The panels (or panes) of a panelled or glazed door leaf: `panels` stacked between the stiles, separated by rails,
 * sharing the height above the bottom rail evenly. Empty when the leaf is too small to frame.
 */
export function leafPanels(width: number, height: number, panels: number): PanelRect[] {
    const count = Math.max(1, Math.round(panels));
    const inner = width - 2 * LeafStile;
    const free = height - LeafBottomRail - LeafRail * count;
    if (inner <= 0 || free <= 0) {
        return [];
    }
    const panelHeight = free / count;
    return Array.from({length: count}, (_, i) => ({
        x: LeafStile,
        y: LeafBottomRail + i * (panelHeight + LeafRail),
        width: inner,
        height: panelHeight,
    }));
}

/** One leaf of a window's exterior shutters, in the wall's frame: from / to along the wall (mm), hinged on `hinge`. */
export interface ShutterLeaf {
    from: number;
    to: number;
    /** The jamb it hangs on, along the wall (mm). */
    hinge: number;
}

/**
 * The two leaves of a window's shutters, each half the opening wide and hinged on a jamb: across the opening when
 * closed, otherwise folded back flat against the wall beside it. A leaf folds back only into `free` (the wall between
 * the neighbouring openings, or its ends; mm along the wall): one that would hang past them stays closed.
 */
export function shutterLeaves(
    opening: Pick<Opening, 'offset' | 'width'>,
    closed: boolean,
    free: [number, number] = [-Infinity, Infinity],
): [ShutterLeaf, ShutterLeaf] {
    const from = opening.offset;
    const to = opening.offset + opening.width;
    const half = opening.width / 2;
    const left: ShutterLeaf = closed || from - half < free[0] ? {from, to: from + half, hinge: from} : {from: from - half, to: from, hinge: from};
    const right: ShutterLeaf = closed || to + half > free[1] ? {from: to - half, to, hinge: to} : {from: to, to: to + half, hinge: to};
    return [left, right];
}

/** The wall free beside an opening, mm along the wall: up to its neighbouring openings, or the wall's ends. */
export function freeBeside(
    opening: Pick<Opening, 'id' | 'offset' | 'width'>,
    wall: Pick<Wall, 'startX' | 'startY' | 'endX' | 'endY' | 'sagitta' | 'openings'>,
): [number, number] {
    const length = wallFrame(wall).length;
    let from = 0;
    let to = length;
    for (const other of wall.openings) {
        if (other.id === opening.id) {
            continue;
        }
        if (other.offset + other.width <= opening.offset) {
            from = Math.max(from, other.offset + other.width);
        } else if (other.offset >= opening.offset + opening.width) {
            to = Math.min(to, other.offset);
        }
    }
    return [from, to];
}

/**
 * The sign of the exterior face along the wall's local z (plan across is local −z, so the left of the wall is −z):
 * a window's `side` is its interior, Left when not given.
 */
export function exteriorSign(opening: Pick<Opening, 'side'>): 1 | -1 {
    return opening.side === 'Right' ? -1 : 1;
}

/** Slats of a louvred shutter leaf `height` mm tall: their centre heights from its bottom, inside the top and bottom rails. */
export function louvreSlats(height: number, rail: number, pitch: number): number[] {
    const slats: number[] = [];
    for (let y = rail + pitch / 2; y <= height - rail - pitch / 2 + 0.001; y += pitch) {
        slats.push(y);
    }
    return slats;
}
