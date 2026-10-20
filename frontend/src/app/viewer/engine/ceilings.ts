import {PlanPoint, Room, Wall} from '@app/models/scene';

import {counterClockwise, pointInPolygon, Vec2, wallFrame, wallGapsAlongEdge} from './plan-geometry';

/** Directions of a vaulted ceiling in plan: `u` along the beams, `v` across them (u turned 90° counter-clockwise). */
export interface VaultFrame {
    u: Vec2;
    v: Vec2;
}

export function vaultFrame(angle: number): VaultFrame {
    const radians = (angle * Math.PI) / 180;
    const u = {x: Math.cos(radians), y: Math.sin(radians)};
    return {u, v: {x: -u.y, y: u.x}};
}

/** The shape of a vaulted ceiling, mm: beams every `spacing` (their centre lines at whole multiples of it, from the plan origin). */
export interface VaultProfile {
    spacing: number;
    rise: number;
    beamWidth: number;
    /** Height of the vaults' crowns. */
    crown: number;
}

/** The profile of a room with a vaulted ceiling; null when its ceiling is flat or it has none. */
export function vaultProfile(room: Room): VaultProfile | null {
    if (room.ceilingHeight === null || room.vaultSpacing == null) {
        return null;
    }
    return {spacing: room.vaultSpacing, rise: room.vaultRise ?? 130, beamWidth: room.beamWidth ?? 80, crown: room.ceilingHeight};
}

/** Height of the vaulted ceiling (mm) at `v` across the beams: a sine arch from beam to beam, flat at the springing over a beam. */
export function vaultHeight(v: number, profile: VaultProfile): number {
    const {spacing, rise, beamWidth, crown} = profile;
    const springing = crown - rise;
    const inBay = v - Math.floor(v / spacing) * spacing;
    const half = beamWidth / 2;
    if (inBay <= half || inBay >= spacing - half) {
        return springing;
    }
    const t = (inBay - half) / (spacing - beamWidth);
    return springing + rise * Math.sin(Math.PI * t);
}

/**
 * Height (mm) of the room's ceiling surface at a plan point: its ceiling height, or on a vaulted ceiling the vault
 * there, or the underside of a beam. Null for an open-air room.
 */
export function ceilingHeightAt(room: Room, x: number, y: number): number | null {
    const profile = vaultProfile(room);
    if (profile === null) {
        return room.ceilingHeight;
    }
    const v = dot({x, y}, vaultFrame(room.vaultAngle ?? 0).v);
    const inBay = v - Math.floor(v / profile.spacing) * profile.spacing;
    const onBeam = inBay <= profile.beamWidth / 2 || inBay >= profile.spacing - profile.beamWidth / 2;
    return onBeam ? profile.crown - profile.rise - (room.beamDrop ?? 30) : vaultHeight(v, profile);
}

/**
 * Where the ceiling surface is cut into strips across the beams between `from` and `to` (mm along v): at the
 * edges of every beam and `segments` times across each vault, so that straight strips follow the arches.
 */
export function vaultBreaks(from: number, to: number, profile: VaultProfile, segments: number): number[] {
    const {spacing, beamWidth} = profile;
    const breaks = new Set<number>([from, to]);
    for (let k = Math.floor(from / spacing) - 1; k * spacing <= to + spacing; k++) {
        const start = k * spacing + beamWidth / 2;
        const end = (k + 1) * spacing - beamWidth / 2;
        for (let i = 0; i <= segments; i++) {
            const v = start + ((end - start) * i) / segments;
            if (v > from && v < to) {
                breaks.add(v);
            }
        }
    }
    return [...breaks].sort((a, b) => a - b);
}

const dot = (p: Vec2, d: Vec2): number => p.x * d.x + p.y * d.y;

/**
 * The part of a polygon between `lo` and `hi` along the direction `v` (Sutherland–Hodgman against the two lines).
 * A concave polygon cut into several parts comes back as one outline joined along the lines, which triangulates
 * the same.
 */
export function clipToBand(polygon: readonly Vec2[], v: Vec2, lo: number, hi: number): Vec2[] {
    const clip = (points: readonly Vec2[], keep: (p: Vec2) => number): Vec2[] => {
        const out: Vec2[] = [];
        points.forEach((current, i) => {
            const previous = points[(i + points.length - 1) % points.length];
            const dc = keep(current);
            const dp = keep(previous);
            if (dc >= 0) {
                if (dp < 0) {
                    out.push(cross(previous, current, dp, dc));
                }
                out.push(current);
            } else if (dp >= 0) {
                out.push(cross(previous, current, dp, dc));
            }
        });
        return out;
    };
    const cross = (a: Vec2, b: Vec2, da: number, db: number): Vec2 => {
        const t = da / (da - db);
        return {x: a.x + (b.x - a.x) * t, y: a.y + (b.y - a.y) * t};
    };
    const above = clip(polygon, (p) => dot(p, v) - lo);
    return above.length < 3 ? [] : clip(above, (p) => hi - dot(p, v));
}

/** Where the line at `v` across the beams runs inside the polygon: [from, to] along u (mm), sorted. */
export function beamSpans(polygon: readonly PlanPoint[], frame: VaultFrame, v: number): [number, number][] {
    const hits: number[] = [];
    polygon.forEach(([ax, ay], i) => {
        const [bx, by] = polygon[(i + 1) % polygon.length];
        const va = dot({x: ax, y: ay}, frame.v) - v;
        const vb = dot({x: bx, y: by}, frame.v) - v;
        // Half-open, so a vertex on the line is counted once.
        if (va > 0 !== vb > 0) {
            const t = va / (va - vb);
            hits.push(dot({x: ax + (bx - ax) * t, y: ay + (by - ay) * t}, frame.u));
        }
    });
    hits.sort((a, b) => a - b);
    const spans: [number, number][] = [];
    for (let i = 0; i + 1 < hits.length; i += 2) {
        spans.push([hits[i], hits[i + 1]]);
    }
    return spans;
}

/** The beams of a vaulted room: centre line (plan mm) and length along it for every beam inside the outline. */
export function roomBeams(room: Room, profile: VaultProfile): {start: Vec2; end: Vec2}[] {
    const frame = vaultFrame(room.vaultAngle ?? 0);
    const vs = room.polygon.map(([x, y]) => dot({x, y}, frame.v));
    // Not a beam lying along the outline, half in the wall.
    const half = profile.beamWidth / 2;
    const from = Math.ceil((Math.min(...vs) + half) / profile.spacing);
    const to = Math.floor((Math.max(...vs) - half) / profile.spacing);
    const beams: {start: Vec2; end: Vec2}[] = [];
    for (let k = from; k <= to; k++) {
        const v = k * profile.spacing;
        for (const [u0, u1] of beamSpans(room.polygon, frame, v)) {
            const at = (u: number): Vec2 => ({x: frame.u.x * u + frame.v.x * v, y: frame.u.y * u + frame.v.y * v});
            beams.push({start: at(u0), end: at(u1)});
        }
    }
    return beams;
}

/**
 * Stretches of the edge a → b (mm from a) that lie along a wall face: walls parallel to the edge whose centre line is
 * half a thickness (± tolerance) away, projected onto it and merged.
 */
export function wallSpansAlongEdge(a: Vec2, b: Vec2, walls: readonly Wall[], tolerance = 30): [number, number][] {
    const length = Math.hypot(b.x - a.x, b.y - a.y);
    if (length === 0) {
        return [];
    }
    const dir = {x: (b.x - a.x) / length, y: (b.y - a.y) / length};
    const spans: [number, number][] = [];
    for (const wall of walls) {
        const frame = wallFrame(wall);
        const parallel = Math.abs(dir.x * frame.dir.y - dir.y * frame.dir.x) < 1e-3;
        const distance = Math.abs((a.x - frame.start.x) * frame.normal.x + (a.y - frame.start.y) * frame.normal.y);
        if (!parallel || Math.abs(distance - wall.thickness / 2) > tolerance) {
            continue;
        }
        // The wall's ends reach half a thickness on at corners (its mitre).
        const t0 = dot({x: wall.startX - a.x, y: wall.startY - a.y}, dir);
        const t1 = dot({x: wall.endX - a.x, y: wall.endY - a.y}, dir);
        const from = Math.max(Math.min(t0, t1) - wall.thickness / 2, 0);
        const to = Math.min(Math.max(t0, t1) + wall.thickness / 2, length);
        if (to > from) {
            spans.push([from, to]);
        }
    }
    return mergeSpans(spans);
}

function mergeSpans(spans: [number, number][]): [number, number][] {
    const merged: [number, number][] = [];
    for (const [from, to] of [...spans].sort((x, y) => x[0] - y[0])) {
        const last = merged.at(-1);
        if (last !== undefined && from <= last[1] + 1) {
            last[1] = Math.max(last[1], to);
        } else {
            merged.push([from, to]);
        }
    }
    return merged;
}

/** Stretches of `spans` with the `holes` taken out. */
function subtractSpans(spans: readonly [number, number][], holes: readonly [number, number][]): [number, number][] {
    let pieces = spans.map(([from, to]): [number, number] => [from, to]);
    for (const [holeFrom, holeTo] of holes) {
        pieces = pieces.flatMap(([from, to]): [number, number][] => {
            if (holeTo <= from || holeFrom >= to) {
                return [[from, to]];
            }
            return [
                [from, holeFrom],
                [holeTo, to],
            ].filter(([f, t]) => t - f > 1) as [number, number][];
        });
    }
    return pieces;
}

/** Where skirting runs along the edge a → b (mm from a): along the walls on it, except their doorways. */
export function skirtingPieces(a: Vec2, b: Vec2, walls: readonly Wall[]): [number, number][] {
    const doorways = wallGapsAlongEdge(a, b, walls)
        .filter((gap) => gap.bottom <= 0)
        .map((gap): [number, number] => [gap.from, gap.to]);
    return subtractSpans(wallSpansAlongEdge(a, b, walls), doorways);
}

/** An upright face where one room's ceiling is lower than its neighbour's across an edge without a wall. */
export interface CeilingStep {
    a: Vec2;
    b: Vec2;
    bottom: number;
    top: number;
}

/** How far across an open edge the neighbouring room is looked for, mm. */
const NeighbourProbe = 60;

/**
 * The faces closing the gap between ceilings of different heights where rooms meet without a wall (an open-plan
 * corridor with a lower ceiling beside the living room): from the lower ceiling up to the higher one, along the lower
 * room's open edges.
 */
export function ceilingSteps(rooms: readonly Room[], walls: readonly Wall[]): CeilingStep[] {
    const steps: CeilingStep[] = [];
    for (const room of rooms) {
        if (room.ceilingHeight === null) {
            continue;
        }
        const polygon = counterClockwise(room.polygon);
        polygon.forEach(([ax, ay], i) => {
            const [bx, by] = polygon[(i + 1) % polygon.length];
            const a = {x: ax, y: ay};
            const b = {x: bx, y: by};
            const length = Math.hypot(bx - ax, by - ay);
            if (length < 1) {
                return;
            }
            const dir = {x: (bx - ax) / length, y: (by - ay) / length};
            // The interior is on the left; outside is to the right.
            const outward = {x: dir.y, y: -dir.x};
            for (const [from, to] of subtractSpans([[0, length]], wallSpansAlongEdge(a, b, walls))) {
                const mid = (from + to) / 2;
                const probe = {x: ax + dir.x * mid + outward.x * NeighbourProbe, y: ay + dir.y * mid + outward.y * NeighbourProbe};
                const neighbour = rooms.find((r) => r.id !== room.id && r.ceilingHeight !== null && pointInPolygon(probe, r.polygon));
                const top = neighbour?.ceilingHeight ?? null;
                if (top !== null && top > room.ceilingHeight!) {
                    steps.push({
                        a: {x: ax + dir.x * from, y: ay + dir.y * from},
                        b: {x: ax + dir.x * to, y: ay + dir.y * to},
                        bottom: room.ceilingHeight!,
                        top,
                    });
                }
            }
        });
    }
    return steps;
}
