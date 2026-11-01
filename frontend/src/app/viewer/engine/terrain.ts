import {PlanPoint, Plot, SpotHeight} from '@app/models/scene';

import {pointInPolygon} from './plan-geometry';

/**
 * The ground of a plot from its spot heights (FD-86): their Delaunay triangulation, extended to the plot's boundary.
 * Each boundary corner takes the height the spot heights give it (inside their triangles, else at the nearest point of
 * their outline), and the spot heights and corners are triangulated together, so the surface covers the whole plot and
 * is planar in each triangle. Outside its triangles the ground has the height of the nearest point of their outline.
 * Without spot heights the ground is flat at the plot's level.
 *
 * The twin is `Service/Editor/Geometry/Terrain.php`, kept identical by `backend/tests/fixtures/terrain.json`: both run
 * the same arithmetic in the same order, so they triangulate co-circular points (a regular grid) the same way.
 */
export interface Terrain {
    /** x, y, z in plan mm (z against the floor's level): the spot heights, then the boundary corners. */
    vertices: readonly SpotHeight[];
    /** Counter-clockwise vertex indices. */
    triangles: readonly (readonly [number, number, number])[];
    /** The ground without spot heights, mm. */
    level: number;
}

/** A boundary corner this close to a spot height (mm) is that spot height. */
const SamePoint = 1;
/** Barycentric tolerance: a point on a triangle's edge lies in it. */
const Inside = -1e-9;

export function terrainOf(points: readonly SpotHeight[], polygon: readonly PlanPoint[], groundLevel: number): Terrain {
    if (points.length === 0) {
        return {vertices: [], triangles: [], level: groundLevel};
    }
    const spots = points.map(([x, y, z]): SpotHeight => [x, y, z]);
    const heights: Terrain = {vertices: spots, triangles: triangulate(spots), level: groundLevel};
    const vertices = [...spots];
    for (const [x, y] of polygon) {
        if (!spots.some((p) => Math.abs(p[0] - x) <= SamePoint && Math.abs(p[1] - y) <= SamePoint)) {
            vertices.push([x, y, terrainAt(heights, x, y)]);
        }
    }
    return {vertices, triangles: triangulate(vertices), level: groundLevel};
}

const cache = new WeakMap<Plot, Terrain>();

/** The plot's terrain, computed once per plot object (a changed plot is a new object). */
export function plotTerrain(plot: Plot): Terrain {
    let terrain = cache.get(plot);
    if (terrain === undefined) {
        terrain = terrainOf(plot.terrain ?? [], plot.polygon, plot.groundLevel ?? 0);
        cache.set(plot, terrain);
    }
    return terrain;
}

/** Whether the plot's ground slopes (it has spot heights). */
export function hasTerrain(plot: Plot): boolean {
    return (plot.terrain ?? []).length > 0;
}

/** The ground's height at a plan point, mm against the floor's level. */
export function terrainAt(terrain: Terrain, x: number, y: number): number {
    const v = terrain.vertices;
    if (v.length === 0) {
        return terrain.level;
    }
    for (const [i, j, k] of terrain.triangles) {
        const [ax, ay, az] = v[i];
        const [bx, by, bz] = v[j];
        const [cx, cy, cz] = v[k];
        const det = (by - cy) * (ax - cx) + (cx - bx) * (ay - cy);
        if (det === 0) {
            continue;
        }
        const l1 = ((by - cy) * (x - cx) + (cx - bx) * (y - cy)) / det;
        const l2 = ((cy - ay) * (x - cx) + (ax - cx) * (y - cy)) / det;
        const l3 = 1 - l1 - l2;
        if (l1 >= Inside && l2 >= Inside && l3 >= Inside) {
            return l1 * az + l2 * bz + l3 * cz;
        }
    }
    return nearestOnOutline(terrain, x, y);
}

/** The lowest and highest ground, mm. */
export function terrainRange(terrain: Terrain): [number, number] {
    if (terrain.vertices.length === 0) {
        return [terrain.level, terrain.level];
    }
    const zs = terrain.vertices.map((p) => p[2]);
    return [Math.min(...zs), Math.max(...zs)];
}

/** The plot whose boundary holds the point (the first one), if any. */
export function plotAt(plots: readonly Plot[], x: number, y: number): Plot | undefined {
    return plots.find((p) => pointInPolygon({x, y}, p.polygon));
}

/** The ground at a plan point: the plot's there, else the floor's level (0), mm. */
export function groundAt(plots: readonly Plot[], x: number, y: number): number {
    const plot = plotAt(plots, x, y);
    return plot === undefined ? 0 : terrainAt(plotTerrain(plot), x, y);
}

/**
 * Delaunay triangulation (Bowyer–Watson, the points inserted in their order into a large enclosing triangle) of points
 * [x, y, …]; counter-clockwise index triples. Collinear or repeated points add no triangles.
 */
export function triangulate(points: readonly (readonly number[])[]): [number, number, number][] {
    const n = points.length;
    if (n < 3) {
        return [];
    }
    const xs = points.map((q) => q[0]);
    const ys = points.map((q) => q[1]);
    const mx = (Math.min(...xs) + Math.max(...xs)) / 2;
    const my = (Math.min(...ys) + Math.max(...ys)) / 2;
    const d = Math.max(Math.max(...xs) - Math.min(...xs), Math.max(...ys) - Math.min(...ys), 1) * 100;
    // Relative to the middle, which keeps the in-circle products small.
    const p: [number, number][] = points.map((q) => [q[0] - mx, q[1] - my]);
    p.push([-20 * d, -d], [20 * d, -d], [0, 20 * d]);
    let triangles: [number, number, number][] = [[n, n + 1, n + 2]];
    for (let i = 0; i < n; i++) {
        const bad: [number, number, number][] = [];
        const kept: [number, number, number][] = [];
        for (const t of triangles) {
            (inCircle(p[t[0]], p[t[1]], p[t[2]], p[i]) ? bad : kept).push(t);
        }
        if (bad.length === 0) {
            continue;
        }
        const edges: [number, number][] = bad.flatMap((t): [number, number][] => [
            [t[0], t[1]],
            [t[1], t[2]],
            [t[2], t[0]],
        ]);
        const keys = new Set(edges.map(([a, b]) => `${a},${b}`));
        for (const [a, b] of edges) {
            if (!keys.has(`${b},${a}`)) {
                kept.push([a, b, i]);
            }
        }
        triangles = kept;
    }
    return triangles.filter((t) => Math.max(...t) < n);
}

/** Whether `d` lies strictly inside the circle through the counter-clockwise `a`, `b`, `c`. */
function inCircle(a: readonly number[], b: readonly number[], c: readonly number[], d: readonly number[]): boolean {
    const adx = a[0] - d[0];
    const ady = a[1] - d[1];
    const bdx = b[0] - d[0];
    const bdy = b[1] - d[1];
    const cdx = c[0] - d[0];
    const cdy = c[1] - d[1];
    const det =
        (adx * adx + ady * ady) * (bdx * cdy - cdx * bdy) +
        (bdx * bdx + bdy * bdy) * (cdx * ady - adx * cdy) +
        (cdx * cdx + cdy * cdy) * (adx * bdy - bdx * ady);
    return det > 0;
}

/** The triangles' outline: the edges of one triangle only, in the order of the triangles. */
export function terrainOutline(terrain: Terrain): [number, number][] {
    const all = new Set(terrain.triangles.flatMap(([i, j, k]) => [`${i},${j}`, `${j},${k}`, `${k},${i}`]));
    return terrain.triangles.flatMap(([i, j, k]) =>
        (
            [
                [i, j],
                [j, k],
                [k, i],
            ] as [number, number][]
        ).filter(([a, b]) => !all.has(`${b},${a}`)),
    );
}

/**
 * The height of the nearest point of the triangles' outline; without triangles, of the points joined in the order of
 * x, then y (collinear spot heights: a line), or the single point.
 */
function nearestOnOutline(terrain: Terrain, x: number, y: number): number {
    const v = terrain.vertices;
    let edges: [number, number][];
    if (terrain.triangles.length > 0) {
        edges = terrainOutline(terrain);
    } else {
        const order = v.map((_, i) => i).sort((a, b) => v[a][0] - v[b][0] || v[a][1] - v[b][1] || a - b);
        edges = [[order[0], order[0]], ...order.slice(1).map((b, i): [number, number] => [order[i], b])];
    }
    let best: number | null = null;
    let height = 0;
    for (const [a, b] of edges) {
        const [ax, ay, az] = v[a];
        const [bx, by, bz] = v[b];
        const dx = bx - ax;
        const dy = by - ay;
        const length = dx * dx + dy * dy;
        const t = length === 0 ? 0 : Math.max(0, Math.min(1, ((x - ax) * dx + (y - ay) * dy) / length));
        const ex = ax + t * dx - x;
        const ey = ay + t * dy - y;
        const distance = ex * ex + ey * ey;
        if (best === null || distance < best) {
            best = distance;
            height = az + t * (bz - az);
        }
    }
    return height;
}

/**
 * Contour lines of the terrain every `interval` mm: per level, the segments where it cuts the triangles (plan mm). A
 * level through a vertex or along an edge is nudged up by a micrometre so each crossing is counted once.
 */
export function terrainContours(terrain: Terrain, interval: number): {level: number; segments: [PlanPoint, PlanPoint][]}[] {
    const [low, high] = terrainRange(terrain);
    const levels: number[] = [];
    for (let level = Math.ceil(low / interval) * interval; level <= high; level += interval) {
        levels.push(level);
    }
    const v = terrain.vertices;
    return levels
        .map((level) => {
            const h = level + 0.001;
            const segments: [PlanPoint, PlanPoint][] = [];
            for (const t of terrain.triangles) {
                const cut: PlanPoint[] = [];
                for (let e = 0; e < 3; e++) {
                    const [ax, ay, az] = v[t[e]];
                    const [bx, by, bz] = v[t[(e + 1) % 3]];
                    if (az < h !== bz < h) {
                        const s = (h - az) / (bz - az);
                        cut.push([ax + s * (bx - ax), ay + s * (by - ay)]);
                    }
                }
                if (cut.length === 2) {
                    segments.push([cut[0], cut[1]]);
                }
            }
            return {level, segments};
        })
        .filter((c) => c.segments.length > 0);
}
