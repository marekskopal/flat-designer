import {levelText} from '@app/core/level-text';
import {ElectricalCategory, ElectricalSymbols} from '@app/models/electrical-schedule';
import {FurnitureDefinitionBody} from '@app/models/furniture';
import {Scene} from '@app/models/scene';
import * as THREE from 'three';
import {CSS2DObject} from 'three/examples/jsm/renderers/CSS2DRenderer.js';

import {MM, Vec2} from './plan-geometry';
import {hiddenItemIds} from './variants';

/**
 * The electrical plan: the points of the Custom items whose definition has a `category`, drawn as symbols over
 * the plan by the viewer (`buildElectrical()`) and the electrical plan PDF from the same paths. A symbol is drawn in a
 * unit frame: the point at the origin, +y away from the wall (out of the item's front), about one unit across.
 */
export interface ElectricalPoint {
    itemId: number;
    category: ElectricalCategory;
    /** Plan mm: the middle of a wall point's back edge (on the wall face), a light's footprint centre. */
    at: Vec2;
    /** Unit vector in plan, out of the item's front. */
    out: Vec2;
    /** mm above the floor, the item's bottom edge. */
    z: number;
    /** The value of the parameter the definition's `schedule.count` names (a socket's gangs), 1 without. */
    units: number;
    /** The item's parameter values, defaults filled in (a switch's `rockers`). */
    values: Record<string, unknown>;
    /** A switch's lights as stored (`Item.controls`): ids that may no longer be shown lights; `switchLinks()` skips those. */
    controls: number[];
}

/** A polyline of a symbol in its unit frame. */
export interface SymbolPath {
    points: Vec2[];
    closed?: boolean;
}

/** Plan mm a symbol unit spans in the viewer. */
export const SymbolSize = 260;

export const CategoryColors: Record<ElectricalCategory, string> = {
    light: '#c98a00',
    socket: '#c0392b',
    switch: '#2a62b8',
    data: '#2e8b57',
    plumbing: '#1b8aa6',
};

/** Categories whose points stand on a wall face (their symbol starts there); lights sit at their centre. */
const WallMounted = new Set<ElectricalCategory>(['socket', 'switch', 'data', 'plumbing']);

/** The scene's definitions that have a category, by id. */
const categorized = (scene: Scene): Map<number, FurnitureDefinitionBody> =>
    new Map((scene.definitions ?? []).filter((d) => d.definition.category !== undefined).map((d) => [d.id, d.definition]));

/** The shown points of the floor, in the items' order. */
export function electricalPoints(scene: Scene): ElectricalPoint[] {
    const definitions = categorized(scene);
    const hidden = hiddenItemIds(scene);
    const points: ElectricalPoint[] = [];
    for (const item of scene.items) {
        const definition = item.kind === 'Custom' && item.definitionId !== null ? definitions.get(item.definitionId) : undefined;
        const category = definition?.category;
        if (definition === undefined || category === undefined || hidden.has(item.id)) {
            continue;
        }
        const given = (item.params['values'] ?? {}) as Record<string, unknown>;
        const values: Record<string, unknown> = {};
        for (const parameter of definition.parameters ?? []) {
            values[parameter.key] = given[parameter.key] ?? parameter.default;
        }
        const angle = (item.rotation * Math.PI) / 180;
        // The front faces −y at rotation 0, turned counter-clockwise; a wall point's back stands on the wall face.
        const out = {x: Math.sin(angle), y: -Math.cos(angle)};
        const offset = WallMounted.has(category) ? -item.depth / 2 : 0;
        points.push({
            itemId: item.id,
            category,
            at: {x: item.x + out.x * offset, y: item.y + out.y * offset},
            out,
            z: item.z,
            units: scheduleUnits(definition, given),
            values,
            controls: category === 'switch' ? (item.controls ?? []) : [],
        });
    }
    return points;
}

/**
 * Whether the floor shows any electrical point (the Display toggle and the export offer the plan then): as
 * `electricalPoints(scene).length > 0`, but stopping at the first one without building the points.
 */
export function hasElectricalPoints(scene: Scene): boolean {
    const definitions = categorized(scene);
    if (definitions.size === 0) {
        return false;
    }
    const used = scene.items.filter((item) => item.kind === 'Custom' && item.definitionId !== null && definitions.has(item.definitionId));
    if (used.length === 0) {
        return false;
    }
    const hidden = hiddenItemIds(scene);
    return used.some((item) => !hidden.has(item.id));
}

/**
 * What an item counts as: the value of the int parameter `scheduleCount()` names, its default when the item does not
 * give it (at least 0; 1 when it is no whole number), 1 without one. The twin of `DefinitionSchedule::units()`, kept
 * identical by `backend/tests/fixtures/electrical-units.json`.
 */
export function scheduleUnits(definition: FurnitureDefinitionBody, given: Record<string, unknown>): number {
    const count = scheduleCount(definition);
    const parameter = count === null ? undefined : (definition.parameters ?? []).find((p) => p.key === count);
    if (count === null || parameter === undefined) {
        return 1;
    }
    const value = given[count] ?? parameter.default;
    return typeof value === 'number' && Number.isInteger(value) ? Math.max(0, value) : 1;
}

/** category → the int parameter counted when a definition has no `schedule` settings (`DefinitionSchedule::Defaults`). */
const DefaultCounts: Partial<Record<ElectricalCategory, string>> = {socket: 'gangs', data: 'ports'};

/**
 * The int parameter that multiplies an item: the settings' `count`, or for a definition without settings (older JSON)
 * a socket's `gangs` or a data socket's `ports`, as `DefinitionSchedule::of()` reads it; null counts each item once.
 */
export function scheduleCount(definition: FurnitureDefinitionBody): string | null {
    if (definition.schedule !== undefined) {
        return definition.schedule.count ?? null;
    }
    const count = definition.category === undefined ? undefined : DefaultCounts[definition.category];
    return count !== undefined && (definition.parameters ?? []).some((p) => p.key === count && p.type === 'int') ? count : null;
}

/** Whether the point stands on a wall face, so its elevation matters to the electrician. */
export function isWallMounted(point: ElectricalPoint): boolean {
    return WallMounted.has(point.category);
}

/** The elevation label of a wall point, mm above the floor as plans write it (`+1.100`). */
export function elevationText(point: ElectricalPoint): string {
    return levelText(point.z);
}

const arc = (cx: number, cy: number, r: number, from: number, to: number, steps = 12): Vec2[] =>
    Array.from({length: steps + 1}, (_, i) => {
        const a = from + ((to - from) * i) / steps;
        return {x: cx + r * Math.cos(a), y: cy + r * Math.sin(a)};
    });
const circle = (cx: number, cy: number, r: number): SymbolPath => ({points: arc(cx, cy, r, 0, 2 * Math.PI, 20).slice(0, -1), closed: true});
const line = (...points: [number, number][]): SymbolPath => ({points: points.map(([x, y]) => ({x, y}))});

/** A light: a circle with a cross, centred on the point. */
const lightSymbol = (): SymbolPath[] => {
    const d = 0.4 * Math.SQRT1_2;
    return [circle(0, 0, 0.4), line([-d, -d], [d, d]), line([-d, d], [d, -d])];
};

/** A plumbing point: a circle off the wall with a W in it. */
const plumbingSymbol = (): SymbolPath[] => [
    line([0, 0], [0, 0.2]),
    circle(0, 0.5, 0.3),
    line([-0.2, 0.62], [-0.1, 0.36], [0, 0.55], [0.1, 0.36], [0.2, 0.62]),
];

/** How many strokes a switch shows: two for a double (series) switch. */
const switchStrokes = (point: ElectricalPoint): number => (point.values['rockers'] === 'double' ? 2 : 1);

/** A gang count shown by repeated marks: at least 1, at most 4. */
const marks = (point: ElectricalPoint): number => Math.min(4, Math.max(1, point.units));

/** IEC 60617, simplified. */
const IecSymbols: Record<ElectricalCategory, (point: ElectricalPoint) => SymbolPath[]> = {
    light: lightSymbol,
    // A semicircle on the wall's side of a bar, a stem to the wall; one more arc per gang.
    socket: (point) => [
        line([0, 0], [0, 0.2]),
        line([-0.35, 0.2], [0.35, 0.2]),
        ...Array.from({length: marks(point)}, (_, i) => ({points: arc(0, 0.2, 0.3 + i * 0.1, 0, Math.PI)})),
    ],
    // A small circle and a stroke away from it, ending in a tick; a double switch has two.
    switch: (point) => [
        circle(0, 0.25, 0.1),
        ...Array.from({length: switchStrokes(point)}, (_, i) => {
            const dx = i === 0 ? 0.3 : -0.3;
            return [line([0, 0.25], [dx, 0.7]), line([dx, 0.7], [dx + (dx > 0 ? 0.1 : -0.1), 0.63])];
        }).flat(),
        line([0, 0], [0, 0.15]),
    ],
    // A triangle pointing away from the wall.
    data: () => [line([0, 0], [0, 0.2]), {points: [{x: -0.3, y: 0.2}, {x: 0.3, y: 0.2}, {x: 0, y: 0.7}], closed: true}],
    plumbing: plumbingSymbol,
};

/** As Czech electrical plans draw them (ČSN EN 60617), simplified. */
const CsnSymbols: Record<ElectricalCategory, (point: ElectricalPoint) => SymbolPath[]> = {
    light: lightSymbol,
    // A semicircle with the protective-earth bar through its top; the gangs as ticks across the stem.
    socket: (point) => [
        line([0, 0], [0, 0.2]),
        line([-0.3, 0.2], [0.3, 0.2]),
        {points: arc(0, 0.2, 0.3, 0, Math.PI)},
        line([0, 0.5], [0, 0.72]),
        ...Array.from({length: marks(point)}, (_, i) => line([-0.12, 0.04 + i * 0.05], [0.12, 0.04 + i * 0.05])),
    ],
    // A circle on the wall with a stroke ending in a tick; a series switch has two parallel strokes.
    switch: (point) =>
        [circle(0, 0.15, 0.15)].concat(
            ...Array.from({length: switchStrokes(point)}, (_, i) => {
                const s = i * 0.12;
                return [line([0.08 + s, 0.28], [0.38 + s, 0.7]), line([0.38 + s, 0.7], [0.28 + s, 0.77])];
            }),
        ),
    // A square with a bar across, off the wall.
    data: () => [
        line([0, 0], [0, 0.15]),
        {points: [{x: -0.25, y: 0.15}, {x: 0.25, y: 0.15}, {x: 0.25, y: 0.65}, {x: -0.25, y: 0.65}], closed: true},
        line([-0.25, 0.4], [0.25, 0.4]),
    ],
    plumbing: plumbingSymbol,
};

export const ElectricalSymbolSets: Record<ElectricalSymbols, Record<ElectricalCategory, (point: ElectricalPoint) => SymbolPath[]>> = {
    Iec: IecSymbols,
    Csn: CsnSymbols,
};

/** A point of the symbol's unit frame in plan mm, `size` mm a unit. */
function placeUnit(point: ElectricalPoint, p: Vec2, size: number): Vec2 {
    // The unit frame's x runs along the wall, to the right of one facing the wall from the room.
    const along = {x: -point.out.y, y: point.out.x};
    return {x: point.at.x + (along.x * p.x + point.out.x * p.y) * size, y: point.at.y + (along.y * p.x + point.out.y * p.y) * size};
}

/** The point's symbol as polylines in plan mm, `size` mm a unit, +y turned out of the item's front. */
export function symbolPolylines(point: ElectricalPoint, symbols: ElectricalSymbols, size = SymbolSize): {points: Vec2[]; closed: boolean}[] {
    return ElectricalSymbolSets[symbols][point.category](point).map((path) => ({
        points: path.points.map((p) => placeUnit(point, p, size)),
        closed: path.closed ?? false,
    }));
}

/** A switch with the shown lights it controls (a light may be in several: two-way and intermediate switching). */
export interface SwitchLink {
    switchId: number;
    lightIds: number[];
}

/** The switches' links among the shown points: deleted, hidden and no longer lighting items drop out; switches without any are left out. */
export function switchLinks(points: readonly ElectricalPoint[]): SwitchLink[] {
    const lights = new Set(points.filter((p) => p.category === 'light').map((p) => p.itemId));
    return points
        .filter((p) => p.category === 'switch')
        .map((p) => ({switchId: p.itemId, lightIds: [...new Set(p.controls)].filter((id) => lights.has(id)).sort((a, b) => a - b)}))
        .filter((link) => link.lightIds.length > 0);
}

/** A dashed arc of the electrical plan: from a switch to a light, or between two lights of one circuit. */
export interface LinkArc {
    /** The switch's or the first light's item id. */
    from: number;
    to: number;
    /** Plan mm. */
    points: Vec2[];
}

/** Where a switch symbol's arc starts and its radius there, in the unit frame: the circle of the symbol. */
const SwitchHead: Record<ElectricalSymbols, {at: Vec2; radius: number}> = {
    Iec: {at: {x: 0, y: 0.25}, radius: 0.1},
    Csn: {at: {x: 0, y: 0.15}, radius: 0.15},
};

/** A light symbol's circle, in units. */
const LightRadius = 0.4;

/** The arc's sagitta, a share of its chord. */
const ArcBulge = 0.15;

const ArcSteps = 12;

/**
 * The dashed arcs linking the switches to their lights, as electrical plans draw them: the lights one switch (or a set
 * of switches) controls are chained by a minimum spanning tree, and each switch has one arc to the nearest light of its
 * set. An arc bulges to the left of its direction by `ArcBulge` of its chord and runs between the symbols' circles.
 */
export function linkArcs(points: readonly ElectricalPoint[], links: readonly SwitchLink[], symbols: ElectricalSymbols, size = SymbolSize): LinkArc[] {
    const byId = new Map(points.map((p) => [p.itemId, p]));
    const arcs: LinkArc[] = [];
    const drawn = new Set<string>();
    const add = (from: number, to: number, start: Vec2, startRadius: number): void => {
        const key = `${from}-${to}`;
        const light = byId.get(to);
        if (drawn.has(key) || light === undefined) {
            return;
        }
        drawn.add(key);
        const curve = arcBetween(start, light.at, startRadius, LightRadius * size);
        if (curve !== null) {
            arcs.push({from, to, points: curve});
        }
    };
    const chained = new Set<string>();
    for (const link of links) {
        const lights = link.lightIds.map((id) => byId.get(id)).filter((p): p is ElectricalPoint => p !== undefined);
        const key = lights.map((l) => l.itemId).join(',');
        if (!chained.has(key)) {
            chained.add(key);
            for (const [a, b] of spanningTree(lights)) {
                add(Math.min(a.itemId, b.itemId), Math.max(a.itemId, b.itemId), (a.itemId < b.itemId ? a : b).at, LightRadius * size);
            }
        }
        const switchPoint = byId.get(link.switchId);
        if (switchPoint === undefined || lights.length === 0) {
            continue;
        }
        const head = SwitchHead[symbols];
        const start = placeUnit(switchPoint, head.at, size);
        const nearest = lights.reduce((best, l) => (distance(start, l.at) < distance(start, best.at) ? l : best));
        add(switchPoint.itemId, nearest.itemId, start, head.radius * size);
    }
    return arcs;
}

const distance = (a: Vec2, b: Vec2): number => Math.hypot(b.x - a.x, b.y - a.y);

/** Prim's minimum spanning tree over the lights, from the first; edges as pairs. */
function spanningTree(lights: readonly ElectricalPoint[]): [ElectricalPoint, ElectricalPoint][] {
    const inTree = lights.slice(0, 1);
    const rest = lights.slice(1);
    const edges: [ElectricalPoint, ElectricalPoint][] = [];
    while (inTree.length > 0 && rest.length > 0) {
        let from = inTree[0];
        let index = 0;
        let shortest = Infinity;
        for (const candidate of inTree) {
            for (let i = 0; i < rest.length; i++) {
                const length = distance(candidate.at, rest[i].at);
                if (length < shortest) {
                    [from, index, shortest] = [candidate, i, length];
                }
            }
        }
        const [to] = rest.splice(index, 1);
        edges.push([from, to]);
        inTree.push(to);
    }
    return edges;
}

/**
 * A shallow arc (a parabola, close to a circle at this bulge) from the circle of `startRadius` round `a` to the circle
 * of `endRadius` round `b`, bulging left of a → b; null when the circles overlap.
 */
function arcBetween(a: Vec2, b: Vec2, startRadius: number, endRadius: number): Vec2[] | null {
    const length = distance(a, b);
    if (length <= startRadius + endRadius) {
        return null;
    }
    const dir = {x: (b.x - a.x) / length, y: (b.y - a.y) / length};
    const start = {x: a.x + dir.x * startRadius, y: a.y + dir.y * startRadius};
    const chord = length - startRadius - endRadius;
    const left = {x: -dir.y, y: dir.x};
    const sagitta = ArcBulge * chord;
    return Array.from({length: ArcSteps + 1}, (_, i) => {
        const t = i / ArcSteps;
        const off = 4 * sagitta * t * (1 - t);
        return {x: start.x + dir.x * chord * t + left.x * off, y: start.y + dir.y * chord * t + left.y * off};
    });
}

/** Dashes of the link arcs in the viewer, m. */
const ArcDash = 0.1;
const ArcGap = 0.06;

/** The link arcs not touching the highlighted items, when some are. */
const DimmedOpacity = 0.3;

/**
 * The points' symbols as lines at height `y` (m) over the floor, one `LineSegments` per category colour, with a CSS2D
 * label of each wall point's elevation beside its symbol, and the switches' dashed arcs to their lights (those touching
 * a `highlight`ed item drawn full, the others dimmed while any is); nothing is pickable.
 */
export function buildElectrical(
    points: readonly ElectricalPoint[],
    symbols: ElectricalSymbols,
    options: {y?: number; arcs?: readonly LinkArc[]; highlight?: ReadonlySet<number>} = {},
): THREE.Group {
    const y = options.y ?? 0.04;
    const group = new THREE.Group();
    group.name = 'electrical';
    const byCategory = new Map<ElectricalCategory, number[]>();
    for (const point of points) {
        const positions = byCategory.get(point.category) ?? [];
        byCategory.set(point.category, positions);
        for (const path of symbolPolylines(point, symbols)) {
            const ring = path.closed ? [...path.points, path.points[0]] : path.points;
            ring.slice(1).forEach((p, i) => positions.push(ring[i].x * MM, y, -ring[i].y * MM, p.x * MM, y, -p.y * MM));
        }
        if (isWallMounted(point)) {
            const element = document.createElement('div');
            element.className = 'electrical-label';
            element.textContent = elevationText(point);
            const label = new CSS2DObject(element);
            const at = {x: point.at.x + point.out.x * SymbolSize * 1.1, y: point.at.y + point.out.y * SymbolSize * 1.1};
            label.position.set(at.x * MM, y, -at.y * MM);
            group.add(label);
        }
    }
    for (const [category, positions] of byCategory) {
        const geometry = new THREE.BufferGeometry();
        geometry.setAttribute('position', new THREE.Float32BufferAttribute(positions, 3));
        const material = new THREE.LineBasicMaterial({color: CategoryColors[category], depthTest: false, transparent: true});
        const segments = new THREE.LineSegments(geometry, material);
        segments.renderOrder = 10;
        segments.raycast = (): void => undefined;
        group.add(segments);
    }
    const highlight = options.highlight ?? new Set<number>();
    const lit = (link: LinkArc): boolean => highlight.size === 0 || highlight.has(link.from) || highlight.has(link.to);
    for (const bright of [true, false]) {
        const arcs = (options.arcs ?? []).filter((link) => lit(link) === bright);
        if (arcs.length > 0) {
            group.add(arcLines(arcs, y, bright ? 1 : DimmedOpacity));
        }
    }
    return group;
}

/** The arcs as dashed segments in the switches' colour. */
function arcLines(arcs: readonly LinkArc[], y: number, opacity: number): THREE.LineSegments {
    const positions: number[] = [];
    for (const link of arcs) {
        link.points.slice(1).forEach((p, i) => {
            const q = link.points[i];
            positions.push(q.x * MM, y, -q.y * MM, p.x * MM, y, -p.y * MM);
        });
    }
    const geometry = new THREE.BufferGeometry();
    geometry.setAttribute('position', new THREE.Float32BufferAttribute(positions, 3));
    const material = new THREE.LineDashedMaterial({
        color: CategoryColors.switch,
        dashSize: ArcDash,
        gapSize: ArcGap,
        depthTest: false,
        transparent: true,
        opacity,
    });
    const segments = new THREE.LineSegments(geometry, material);
    segments.computeLineDistances();
    segments.renderOrder = 10;
    segments.raycast = (): void => undefined;
    segments.name = 'electrical-links';
    return segments;
}
