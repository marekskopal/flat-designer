import {Item, Scene} from '@app/models/scene';
import polygonClipping, {MultiPolygon, Pair, Polygon} from 'polygon-clipping';

import {hiddenItemIds} from '../variants';
import type {DefinitionLibrary} from './custom';
import {evaluateDefinition, ResolvedPart} from './definition';

/**
 * Sink and hob cut-outs in worktops (framework-free): a definition's `cutout` says what it is, `worktop` one whose top
 * boards take the holes, `sink` or `hob` one that cuts them. The old built-in `Sink` and `Hob` kinds cut too.
 */
export type CutoutRole = 'worktop' | 'sink' | 'hob';

/** An inset sink's hole lies this far (mm) inside its footprint, under its rim. */
export const SinkRim = 20;
/** A hob's hole lies this far (mm) inside its footprint, under its glass. */
export const HobRim = 10;
/** The worktop stands this far (mm) over the edge of an undermount sink's bowl. */
export const UndermountReveal = 3;
/** A sink or hob cuts a worktop whose top lies within this (mm) of its bottom. */
export const TopTolerance = 15;

/** A sink or hob that cuts the worktop under it: its bottom (mm against the floor's level) and its holes in plan. */
export interface WorktopCutter {
    itemId: number;
    z: number;
    /** Closed rings of plan points (mm), one per hole. */
    holes: Pair[][];
}

/** The definition's `cutout` role, null without one. */
export function cutoutRoleOf(definition: unknown): CutoutRole | null {
    const role = typeof definition === 'object' && definition !== null ? (definition as {cutout?: unknown}).cutout : undefined;
    return role === 'worktop' || role === 'sink' || role === 'hob' ? role : null;
}

/** The shown sinks and hobs of a floor with the holes they cut (`hidden`: items of variants not shown). */
export function worktopCutters(items: readonly Item[], definitions: DefinitionLibrary, hidden: ReadonlySet<number>): WorktopCutter[] {
    const cutters: WorktopCutter[] = [];
    for (const item of items) {
        if (hidden.has(item.id) || item.onGround === true || item.onRoof === true) {
            continue;
        }
        const holes = cutterHoles(item, definitions);
        if (holes.length > 0) {
            cutters.push({itemId: item.id, z: item.z, holes: holes.map((hole) => closed(hole.map(([x, z]) => partsToPlan(item, x, z))))});
        }
    }
    return cutters;
}

/** The cutters of a scene: its shown sinks and hobs (`worktopCutters()`). */
export function sceneCutters(scene: Scene): WorktopCutter[] {
    return worktopCutters(scene.items, new Map((scene.definitions ?? []).map((d) => [d.id, d])), hiddenItemIds(scene));
}

/** Whether an item is a worktop taking the cut-outs (`cutout: worktop`). */
export function isCutWorktop(item: Item, definitions: DefinitionLibrary): boolean {
    const definition = item.kind === 'Custom' && item.definitionId !== null ? definitions.get(item.definitionId) : undefined;
    return definition !== undefined && cutoutRoleOf(definition.definition) === 'worktop';
}

/** The holes a sink or hob cuts, as rectangles in its parts' frame (mm, x along the width, z towards the front). */
function cutterHoles(item: Item, definitions: DefinitionLibrary): Pair[][] {
    if (item.kind === 'Sink') {
        return [inset(footprint(item), SinkRim)];
    }
    if (item.kind === 'Hob') {
        return [inset(footprint(item), HobRim)];
    }
    const definition = item.kind === 'Custom' && item.definitionId !== null ? definitions.get(item.definitionId) : undefined;
    const role = definition === undefined ? null : cutoutRoleOf(definition.definition);
    if (definition === undefined || role === 'hob') {
        return role === 'hob' ? [inset(footprint(item), HobRim)] : [];
    }
    return role === 'sink' ? sinkHoles(item, definition.definition) : [];
}

/**
 * A sink's holes by its `style`: an inset sink's footprint less its rim, an undermount sink's bowls (its `Bowl` parts,
 * else its footprint less a rim) less the worktop's reveal over them, none for a farmhouse sink, which takes the
 * worktop's front itself.
 */
function sinkHoles(item: Item, definition: unknown): Pair[][] {
    const values = (item.params['values'] ?? {}) as Record<string, unknown>;
    const style = values['style'] ?? parameterDefault(definition, 'style');
    if (style === 'farmhouse') {
        return [];
    }
    if (style !== 'undermount') {
        return [inset(footprint(item), SinkRim)];
    }
    let bowls: ResolvedPart[] = [];
    try {
        bowls = evaluateDefinition(definition, item, values).filter((part) => part.name === 'Bowl');
    } catch {
        // A sink that does not evaluate draws as a placeholder; its footprint is cut.
    }
    if (bowls.length === 0) {
        return [inset(footprint(item), SinkRim)];
    }
    return bowls.map((bowl) => inset(rectangle(bowl.x, bowl.z, bowl.w, bowl.d), UndermountReveal));
}

function parameterDefault(definition: unknown, key: string): unknown {
    const parameters = (definition as Record<string, unknown>)['parameters'];
    if (!Array.isArray(parameters)) {
        return undefined;
    }
    const parameter = parameters.find((p) => typeof p === 'object' && p !== null && (p as Record<string, unknown>)['key'] === key) as
        | Record<string, unknown>
        | undefined;
    return parameter?.['default'];
}

/**
 * The holes of the cutters at a worktop's top in each of its top boards: the flat, unturned boards whose top is the
 * highest (`Slab`, `Run`, `Return`, `Top`; not an upright waterfall end), as the part's rectangle in its parts' frame
 * (mm, x and z) less the holes. Boards no hole reaches are left out.
 */
export function boardCutouts(item: Item, parts: readonly ResolvedPart[], cutters: readonly WorktopCutter[]): Map<ResolvedPart, MultiPolygon> {
    const cuts = new Map<ResolvedPart, MultiPolygon>();
    if (cutters.length === 0 || item.onGround === true || item.onRoof === true) {
        return cuts;
    }
    const boards = parts.filter(isFlatBoard);
    const top = Math.max(...boards.map((part) => part.y + part.h));
    const holes: Polygon[] = cutters
        .filter((cutter) => cutter.itemId !== item.id && Math.abs(cutter.z - (item.z + top)) <= TopTolerance)
        .flatMap((cutter) => cutter.holes.map((hole) => [hole.map(([x, y]) => planToParts(item, x, y))]));
    if (holes.length === 0) {
        return cuts;
    }
    for (const board of boards) {
        if (Math.abs(board.y + board.h - top) > 0.5) {
            continue;
        }
        const outline: Polygon = [closed(rectangle(board.x, board.z, board.w, board.d))];
        if (holes.some((hole) => polygonClipping.intersection(outline, hole).length > 0)) {
            cuts.set(board, polygonClipping.difference(outline, ...holes));
        }
    }
    return cuts;
}

/** A board lying flat, as a worktop's top: not turned, tilted, tapered or moving with a front. */
function isFlatBoard(part: ResolvedPart): boolean {
    const plain =
        (part.props['turn'] ?? 0) === 0 &&
        (part.props['tilt'] ?? 0) === 0 &&
        typeof part.props['backWidth'] !== 'number' &&
        part.props['leaf'] === undefined;
    return part.type === 'board' && plain && part.h <= part.w && part.h <= part.d;
}

function footprint(item: Item): Pair[] {
    return rectangle(0, 0, item.width, item.depth);
}

function rectangle(x: number, z: number, w: number, d: number): Pair[] {
    return [
        [x, z],
        [x + w, z],
        [x + w, z + d],
        [x, z + d],
    ];
}

/** A rectangle (as `rectangle()` gives it) shrunk by `by` on every side, at least 1 mm across. */
function inset(rect: Pair[], by: number): Pair[] {
    const [[x0, z0], , [x1, z1]] = rect;
    const dx = Math.min(by, (x1 - x0 - 1) / 2);
    const dz = Math.min(by, (z1 - z0 - 1) / 2);
    return rectangle(x0 + dx, z0 + dz, x1 - x0 - 2 * dx, z1 - z0 - 2 * dz);
}

function closed(ring: Pair[]): Pair[] {
    return [...ring, ring[0]];
}

/**
 * A point of an item's parts' frame (mm: x along the width from its left, z towards the front from its back) in plan:
 * the item's centre turned counter-clockwise by its rotation, its front facing plan −y at 0°.
 */
export function partsToPlan(item: Item, x: number, z: number): Pair {
    const r = (item.rotation * Math.PI) / 180;
    const [c, s] = [Math.cos(r), Math.sin(r)];
    const lx = x - item.width / 2;
    const lz = z - item.depth / 2;
    return [item.x + lx * c + lz * s, item.y + lx * s - lz * c];
}

/** A plan point in an item's parts' frame (the inverse of `partsToPlan()`). */
export function planToParts(item: Item, x: number, y: number): Pair {
    const r = (item.rotation * Math.PI) / 180;
    const [c, s] = [Math.cos(r), Math.sin(r)];
    const [dx, dy] = [x - item.x, y - item.y];
    return [dx * c + dy * s + item.width / 2, dx * s - dy * c + item.depth / 2];
}
