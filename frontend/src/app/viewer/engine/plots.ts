import {PlanPoint, Plot, Room} from '@app/models/scene';
import polygonClipping, {MultiPolygon, Pair, Polygon, Ring} from 'polygon-clipping';
import * as THREE from 'three';

import {MaterialLibrary} from './materials';
import {MM, pointInPolygon, polygonCentroid} from './plan-geometry';
import {hasTerrain, plotAt, plotTerrain, Terrain, terrainAt, terrainRange} from './terrain';

/** The ground of a plot without a material of its own. */
const LawnColor = '#9fb37c';
/** Under the rooms' floors (0.002) and over the surroundings' ground disc (−0.02) at the plot's level, m. */
const GroundY = -0.01;
/** The boundary line, just above the ground so the floors of terraces and gardens do not hide it. */
const BoundaryY = 0.004;
/** Over a sloping ground the boundary is drawn through points at most this far apart (mm), following it. */
const BoundaryStep = 500;

/** Rooms lying on the ground itself: on a sloping plot their floor follows it (`drapedOn()`). */
const GroundRooms: readonly Room['type'][] = ['Garden', 'Path', 'Driveway'];

/** The lowest level of the plots' ground and the rooms' floors, mm against the floor's level (at most 0). */
export function lowestGround(plots: readonly Plot[], rooms: readonly Room[] = []): number {
    return Math.min(0, ...plots.map((p) => terrainRange(plotTerrain(p))[0]), ...rooms.map((r) => r.floorElevation ?? 0));
}

/** The plot under a room: the one its centre lies in. */
function plotUnder(room: Pick<Room, 'polygon'>, plots: readonly Plot[]): Plot | undefined {
    const centre = polygonCentroid(room.polygon);
    return plotAt(plots, centre.x, centre.y);
}

/** The level of the ground under a room, mm: that of the plot its centre lies in (at the centre), else the floor's level. */
export function groundUnder(room: Pick<Room, 'polygon'>, plots: readonly Plot[]): number {
    const plot = plotUnder(room, plots);
    if (plot === undefined) {
        return 0;
    }
    const centre = polygonCentroid(room.polygon);
    return terrainAt(plotTerrain(plot), centre.x, centre.y);
}

/** The ground beside a room as a function of the plan point (mm): its plot's, else the floor's level. */
export function groundBeside(room: Pick<Room, 'polygon'>, plots: readonly Plot[]): (x: number, y: number) => number {
    const plot = plotUnder(room, plots);
    if (plot === undefined) {
        return () => 0;
    }
    const terrain = plotTerrain(plot);
    return (x, y) => terrainAt(terrain, x, y);
}

/**
 * The sloping ground a room's floor follows, lifted by its `floorElevation`: a garden, path or driveway on a plot with
 * spot heights. Other rooms (a terrace, a deck, the house's rooms) keep their flat floor.
 */
export function drapedOn(room: Pick<Room, 'polygon' | 'type'>, plots: readonly Plot[]): Terrain | null {
    if (!GroundRooms.includes(room.type)) {
        return null;
    }
    const plot = plotUnder(room, plots);
    return plot !== undefined && hasTerrain(plot) ? plotTerrain(plot) : null;
}

const inside = (polygon: readonly PlanPoint[], outline: readonly PlanPoint[]): boolean =>
    polygon.every(([x, y]) => pointInPolygon({x, y}, outline));

/**
 * Whether a room's floor lies below the plot's ground anywhere: at its corners, its centre or a spot height inside it
 * (on a flat plot: below its level). A room draped on the ground is below it where its floor elevation is (a garden
 * a step down).
 */
function belowGround(plot: Plot, room: Room): boolean {
    const floor = room.floorElevation ?? 0;
    if (!hasTerrain(plot)) {
        return floor < (plot.groundLevel ?? 0);
    }
    if (drapedOn(room, [plot]) !== null) {
        return floor < 0;
    }
    const terrain = plotTerrain(plot);
    const centre = polygonCentroid(room.polygon);
    return (
        [...room.polygon, [centre.x, centre.y] as PlanPoint].some(([x, y]) => floor < terrainAt(terrain, x, y)) ||
        terrain.vertices.some(([x, y, z]) => floor < z && pointInPolygon({x, y}, room.polygon))
    );
}

/**
 * The rooms cut out of a plot's ground: those within its boundary whose floor lies below the ground (a sunken terrace,
 * the house's rooms on a raised plot or set into a slope), so the ground does not cover them. A room inside another
 * such room is left to it.
 */
export function groundHoles(plot: Plot, rooms: readonly Room[]): PlanPoint[][] {
    const sunken = rooms.filter((r) => belowGround(plot, r) && inside(r.polygon, plot.polygon));
    return sunken.filter((r) => !sunken.some((other) => other !== r && inside(r.polygon, other.polygon))).map((r) => r.polygon);
}
const BoundaryColor = '#5b5145';
const SelectedBoundaryColor = '#d9822b';

const ring = (polygon: readonly PlanPoint[]): Ring => polygon.map(([x, y]): Pair => [x, y]);

/** A ring without its closing point (polygon-clipping repeats the first point last). */
function openRing(r: Ring): Pair[] {
    const first = r[0];
    const last = r[r.length - 1];
    return r.length > 1 && first[0] === last[0] && first[1] === last[1] ? r.slice(0, -1) : r;
}

/**
 * The outline less the holes laid on the terrain, `lift` mm above it: a non-indexed mesh in scene metres, cut along
 * the terrain's triangles so each piece is planar (heights from `terrainAt()`), with UVs in plan metres as a flat shape
 * has. Parts outside the triangles take the ground's height at each corner.
 */
export function drapedGeometry(
    outline: readonly PlanPoint[],
    holes: readonly PlanPoint[][],
    terrain: Terrain,
    lift = 0,
): THREE.BufferGeometry {
    const subject: MultiPolygon =
        holes.length === 0 ? [[ring(outline)]] : polygonClipping.difference([ring(outline)], ...holes.map((h): Polygon => [ring(h)]));
    const v = terrain.vertices;
    const triangles = terrain.triangles.map(([i, j, k]): Polygon => [[v[i], v[j], v[k]].map(([x, y]): Pair => [x, y])]);
    // A piece clipped by a triangle lies in its plane; the rest (outside the triangles) takes the ground's height.
    const pieces = terrain.triangles.flatMap((triangle, t) =>
        polygonClipping.intersection(subject, triangles[t]).map((polygon) => ({polygon, height: planeOf(terrain, triangle)})),
    );
    const outside = (x: number, y: number): number => terrainAt(terrain, x, y);
    const uncovered = triangles.length === 0 ? subject : polygonClipping.difference(subject, ...triangles);
    const rest = uncovered.map((polygon) => ({polygon, height: outside}));
    const positions: number[] = [];
    const uvs: number[] = [];
    for (const {polygon, height} of [...pieces, ...rest]) {
        const contour = openRing(polygon[0]).map(([x, y]) => new THREE.Vector2(x, y));
        const inner = polygon.slice(1).map((r) => openRing(r).map(([x, y]) => new THREE.Vector2(x, y)));
        const points = [contour, ...inner].flat();
        for (const face of THREE.ShapeUtils.triangulateShape(contour, inner)) {
            const corners = face.map((index) => points[index]);
            // Counter-clockwise in plan, so the face looks up once plan y becomes scene −z.
            if (THREE.ShapeUtils.isClockWise(corners)) {
                corners.reverse();
            }
            for (const {x, y} of corners) {
                positions.push(x * MM, (height(x, y) + lift) * MM, -y * MM);
                uvs.push(x * MM, y * MM);
            }
        }
    }
    const geometry = new THREE.BufferGeometry();
    geometry.setAttribute('position', new THREE.Float32BufferAttribute(positions, 3));
    geometry.setAttribute('uv', new THREE.Float32BufferAttribute(uvs, 2));
    geometry.computeVertexNormals();
    return geometry;
}

/** The height over the plane of one of the terrain's triangles, as a function of the plan point. */
function planeOf(terrain: Terrain, [i, j, k]: readonly [number, number, number]): (x: number, y: number) => number {
    const [ax, ay, az] = terrain.vertices[i];
    const [bx, by, bz] = terrain.vertices[j];
    const [cx, cy, cz] = terrain.vertices[k];
    const det = (by - cy) * (ax - cx) + (cx - bx) * (ay - cy);
    if (det === 0) {
        return (x, y) => terrainAt(terrain, x, y);
    }
    return (x, y) => {
        const l1 = ((by - cy) * (x - cx) + (cx - bx) * (y - cy)) / det;
        const l2 = ((cy - ay) * (x - cx) + (ax - cx) * (y - cy)) / det;
        return l1 * az + l2 * bz + (1 - l1 - l2) * cz;
    };
}

/** The boundary's points, on a sloping ground at most `BoundaryStep` apart along each edge, in scene metres. */
function boundaryPoints(plot: Plot): THREE.Vector3[] {
    if (!hasTerrain(plot)) {
        return plot.polygon.map(([x, y]) => new THREE.Vector3(x * MM, BoundaryY, -y * MM));
    }
    const terrain = plotTerrain(plot);
    return plot.polygon.flatMap(([ax, ay], i) => {
        const [bx, by] = plot.polygon[(i + 1) % plot.polygon.length];
        const steps = Math.max(1, Math.ceil(Math.hypot(bx - ax, by - ay) / BoundaryStep));
        return Array.from({length: steps}, (_, s) => {
            const [x, y] = [ax + ((bx - ax) * s) / steps, ay + ((by - ay) * s) / steps];
            return new THREE.Vector3(x * MM, terrainAt(terrain, x, y) * MM + BoundaryY, -y * MM);
        });
    });
}

/**
 * A plot: its ground inside the boundary (tagged `plotId` for picking), cut out under the rooms lying lower than it
 * (`groundHoles()`), and the boundary as a dashed line, which is not pickable (a line's ray threshold would reach far
 * beyond it). A flat ground lies at the plot's level (the group's height); a sloping one follows the terrain.
 */
export function buildPlot(plot: Plot, materials: MaterialLibrary, rooms: readonly Room[] = []): THREE.Group {
    const group = new THREE.Group();
    group.name = `plot:${plot.name}`;
    group.userData = {plotId: plot.id};
    const holes = groundHoles(plot, rooms);
    let geometry: THREE.BufferGeometry;
    if (hasTerrain(plot)) {
        geometry = drapedGeometry(plot.polygon, holes, plotTerrain(plot));
    } else {
        group.position.y = (plot.groundLevel ?? 0) * MM;
        // Shape in plan (x, y); rotating −90° about x lays it flat with plan y → scene −z. UVs stay in metres.
        const shape = new THREE.Shape(plot.polygon.map(([x, y]) => new THREE.Vector2(x * MM, y * MM)));
        for (const hole of holes) {
            shape.holes.push(new THREE.Path(hole.map(([x, y]) => new THREE.Vector2(x * MM, y * MM))));
        }
        geometry = new THREE.ShapeGeometry(shape);
        geometry.rotateX(-Math.PI / 2);
    }
    const material = plot.groundMaterialId === null ? materials.plain(LawnColor, 1) : materials.get(plot.groundMaterialId);
    const ground = new THREE.Mesh(geometry, material);
    ground.position.y = GroundY;
    ground.receiveShadow = true;
    ground.name = 'ground';
    group.add(ground);

    const boundary = new THREE.LineLoop(
        new THREE.BufferGeometry().setFromPoints(boundaryPoints(plot)),
        new THREE.LineDashedMaterial({color: BoundaryColor, dashSize: 0.6, gapSize: 0.25}),
    );
    boundary.computeLineDistances();
    boundary.name = 'boundary';
    boundary.userData = {plotBoundary: true};
    boundary.raycast = (): void => undefined;
    group.add(boundary);
    return group;
}

/** Marks a plot as selected by its boundary's colour. */
export function highlightPlot(group: THREE.Object3D, selected: boolean): void {
    group.traverse((object) => {
        if (object.userData['plotBoundary'] === true && object instanceof THREE.LineLoop) {
            (object.material as THREE.LineDashedMaterial).color.set(selected ? SelectedBoundaryColor : BoundaryColor);
        }
    });
}
