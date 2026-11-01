import {levelText} from '@app/core/level-text';
import {ElectricalCategory, ElectricalSymbols} from '@app/models/electrical-schedule';
import {FurnitureDefinitionBody} from '@app/models/furniture';
import {Scene} from '@app/models/scene';
import * as THREE from 'three';
import {CSS2DObject} from 'three/examples/jsm/renderers/CSS2DRenderer.js';

import {MM, Vec2} from './plan-geometry';
import {hiddenItemIds} from './variants';

/**
 * The electrical plan (FD-64): the points of the Custom items whose definition has a `category`, drawn as symbols over
 * the plan by the viewer (`buildElectrical()`) and the electrical plan PDF from the same paths. A symbol is drawn in a
 * unit frame: the point at the origin, +y away from the wall (out of the item's front), about one unit across.
 */
export interface ElectricalPoint {
    itemId: number;
    category: ElectricalCategory;
    /** Plan mm: the middle of a wall point's front edge (on the wall face), a light's footprint centre. */
    at: Vec2;
    /** Unit vector in plan, out of the item's front. */
    out: Vec2;
    /** mm above the floor, the item's bottom edge. */
    z: number;
    /** The value of the parameter the definition's `schedule.count` names (a socket's gangs), 1 without. */
    units: number;
    /** The item's parameter values, defaults filled in (a switch's `rockers`). */
    values: Record<string, unknown>;
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

/** The shown points of the floor, in the items' order. */
export function electricalPoints(scene: Scene): ElectricalPoint[] {
    const definitions = new Map((scene.definitions ?? []).filter((d) => d.definition.category !== undefined).map((d) => [d.id, d.definition]));
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
        const count = scheduleCount(definition);
        const units = count === null ? 1 : values[count];
        const angle = (item.rotation * Math.PI) / 180;
        // The front faces −y at rotation 0, turned counter-clockwise.
        const out = {x: Math.sin(angle), y: -Math.cos(angle)};
        const offset = WallMounted.has(category) ? item.depth / 2 : 0;
        points.push({
            itemId: item.id,
            category,
            at: {x: item.x + out.x * offset, y: item.y + out.y * offset},
            out,
            z: item.z,
            units: typeof units === 'number' && Number.isInteger(units) ? Math.max(0, units) : 1,
            values,
        });
    }
    return points;
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

/** The point's symbol as polylines in plan mm, `size` mm a unit, +y turned out of the item's front. */
export function symbolPolylines(point: ElectricalPoint, symbols: ElectricalSymbols, size = SymbolSize): {points: Vec2[]; closed: boolean}[] {
    // The unit frame's x runs along the wall, to the right of one facing the wall from the room.
    const along = {x: -point.out.y, y: point.out.x};
    const place = (p: Vec2): Vec2 => ({
        x: point.at.x + (along.x * p.x + point.out.x * p.y) * size,
        y: point.at.y + (along.y * p.x + point.out.y * p.y) * size,
    });
    return ElectricalSymbolSets[symbols][point.category](point).map((path) => ({points: path.points.map(place), closed: path.closed ?? false}));
}

/**
 * The points' symbols as lines at height `y` (m) over the floor, one `LineSegments` per category colour, with a CSS2D
 * label of each wall point's elevation beside its symbol; nothing is pickable.
 */
export function buildElectrical(points: readonly ElectricalPoint[], symbols: ElectricalSymbols, y = 0.04): THREE.Group {
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
    return group;
}
