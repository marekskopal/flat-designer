import {PlanPoint, Plot, Room} from '@app/models/scene';
import * as THREE from 'three';

import {MaterialLibrary} from './materials';
import {MM, pointInPolygon, polygonCentroid} from './plan-geometry';

/** The ground of a plot without a material of its own. */
const LawnColor = '#9fb37c';
/** Under the rooms' floors (0.002) and over the surroundings' ground disc (−0.02) at the plot's level, m. */
const GroundY = -0.01;
/** The boundary line, just above the ground so the floors of terraces and gardens do not hide it. */
const BoundaryY = 0.004;

/** The lowest level of the plots' ground and the rooms' floors, mm against the floor's level (at most 0). */
export function lowestGround(plots: readonly Plot[], rooms: readonly Room[] = []): number {
    return Math.min(0, ...plots.map((p) => p.groundLevel ?? 0), ...rooms.map((r) => r.floorElevation ?? 0));
}

/** The level of the ground under a room, mm: that of the plot its centre lies in, else the floor's level. */
export function groundUnder(room: Pick<Room, 'polygon'>, plots: readonly Plot[]): number {
    const centre = polygonCentroid(room.polygon);
    return plots.find((p) => pointInPolygon(centre, p.polygon))?.groundLevel ?? 0;
}

const inside = (polygon: readonly PlanPoint[], outline: readonly PlanPoint[]): boolean =>
    polygon.every(([x, y]) => pointInPolygon({x, y}, outline));

/**
 * The rooms cut out of a plot's ground: those within its boundary whose floor lies below the ground (a sunken terrace,
 * the house's rooms on a raised plot), so the ground does not cover them. A room inside another such room is left to it.
 */
export function groundHoles(plot: Plot, rooms: readonly Room[]): PlanPoint[][] {
    const sunken = rooms.filter((r) => (r.floorElevation ?? 0) < (plot.groundLevel ?? 0) && inside(r.polygon, plot.polygon));
    return sunken.filter((r) => !sunken.some((other) => other !== r && inside(r.polygon, other.polygon))).map((r) => r.polygon);
}
const BoundaryColor = '#5b5145';
const SelectedBoundaryColor = '#d9822b';

/**
 * A plot: its ground inside the boundary (tagged `plotId` for picking), cut out under the rooms lying lower than it
 * (`groundHoles()`), and the boundary as a dashed line, which is not pickable (a line's ray threshold would reach far
 * beyond it).
 */
export function buildPlot(plot: Plot, materials: MaterialLibrary, rooms: readonly Room[] = []): THREE.Group {
    const group = new THREE.Group();
    group.name = `plot:${plot.name}`;
    group.userData = {plotId: plot.id};
    group.position.y = (plot.groundLevel ?? 0) * MM;

    // Shape in plan (x, y); rotating −90° about x lays it flat with plan y → scene −z. UVs stay in metres.
    const shape = new THREE.Shape(plot.polygon.map(([x, y]) => new THREE.Vector2(x * MM, y * MM)));
    for (const hole of groundHoles(plot, rooms)) {
        shape.holes.push(new THREE.Path(hole.map(([x, y]) => new THREE.Vector2(x * MM, y * MM))));
    }
    const geometry = new THREE.ShapeGeometry(shape);
    geometry.rotateX(-Math.PI / 2);
    const material = plot.groundMaterialId === null ? materials.plain(LawnColor, 1) : materials.get(plot.groundMaterialId);
    const ground = new THREE.Mesh(geometry, material);
    ground.position.y = GroundY;
    ground.receiveShadow = true;
    ground.name = 'ground';
    group.add(ground);

    const points = plot.polygon.map(([x, y]) => new THREE.Vector3(x * MM, BoundaryY, -y * MM));
    const boundary = new THREE.LineLoop(
        new THREE.BufferGeometry().setFromPoints(points),
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
