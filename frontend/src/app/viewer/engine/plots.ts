import {Plot} from '@app/models/scene';
import * as THREE from 'three';

import {MaterialLibrary} from './materials';
import {MM} from './plan-geometry';

/** The ground of a plot without a material of its own. */
const LawnColor = '#9fb37c';
/** Under the rooms' floors (0.002) and over the surroundings' ground disc (−0.02), m. */
const GroundY = -0.01;
/** The boundary line, just above the ground so the floors of terraces and gardens do not hide it. */
const BoundaryY = 0.004;
const BoundaryColor = '#5b5145';
const SelectedBoundaryColor = '#d9822b';

/**
 * A plot: its ground inside the boundary (tagged `plotId` for picking) and the boundary as a dashed line, which is not
 * pickable (a line's ray threshold would reach far beyond it).
 */
export function buildPlot(plot: Plot, materials: MaterialLibrary): THREE.Group {
    const group = new THREE.Group();
    group.name = `plot:${plot.name}`;
    group.userData = {plotId: plot.id};

    // Shape in plan (x, y); rotating −90° about x lays it flat with plan y → scene −z. UVs stay in metres.
    const shape = new THREE.Shape(plot.polygon.map(([x, y]) => new THREE.Vector2(x * MM, y * MM)));
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
