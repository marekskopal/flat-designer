import {Scene} from '@app/models/scene';
import * as THREE from 'three';

import {MM, polylineSegments, Vec2, wallFaces, wallJoints} from './plan-geometry';
import {stairGeometry} from './stairs';

/** The floor below the shown one, traced in the top view: its walls, room outlines and stairs. */
export type GhostFloor = Pick<Scene, 'walls' | 'rooms'> & Partial<Pick<Scene, 'stairs' | 'stairRise'>>;

const GhostColor = '#3d6fa8';
/** Over the underlay (0.002), under nothing: the lines draw without a depth test. */
const GhostY = 0.003;

/**
 * The floor below as faint lines on the floor plane: each wall's outline (its faces, mitred where walls meet) and the
 * rooms' outlines dashed. Drawn over the plan without a depth test, like the underlay, so walls can be drawn over the
 * ones below. Not pickable: it carries no element tag and does not raycast.
 */
export function buildGhost(floor: GhostFloor): THREE.Group {
    const group = new THREE.Group();
    group.name = 'ghost';

    const joints = wallJoints(floor.walls);
    const wallSegments = floor.walls.flatMap((wall) => {
        const {left, right} = wallFaces(wall, joints.get(wall.id));
        const outline = [...left, ...[...right].reverse()];
        return polylineSegments([...outline, outline[0]]);
    });
    group.add(ghostLines(wallSegments, new THREE.LineBasicMaterial({color: GhostColor, transparent: true, opacity: 0.55}), 'ghost-walls'));

    const roomSegments = floor.rooms.flatMap((room) => {
        const outline = room.polygon.map(([x, y]): Vec2 => ({x, y}));
        return outline.length < 2 ? [] : polylineSegments([...outline, outline[0]]);
    });
    const dashed = new THREE.LineDashedMaterial({color: GhostColor, transparent: true, opacity: 0.4, dashSize: 0.15, gapSize: 0.1});
    const rooms = ghostLines(roomSegments, dashed, 'ghost-rooms');
    rooms.computeLineDistances();
    group.add(rooms);

    // The stairs: their footprints and treads, so a stairwell can be lined up over them.
    const stairSegments = (floor.stairs ?? []).flatMap((stair) => {
        const geometry = stairGeometry(stair, floor.stairRise ?? 0);
        return [geometry.footprint, ...geometry.treads.map((t) => t.outline), ...geometry.landings.map((l) => l.outline)]
            .filter((outline) => outline.length >= 2)
            .flatMap((outline) => polylineSegments([...outline, outline[0]]));
    });
    if (stairSegments.length > 0) {
        group.add(ghostLines(stairSegments, new THREE.LineBasicMaterial({color: GhostColor, transparent: true, opacity: 0.45}), 'ghost-stairs'));
    }
    return group;
}

export function disposeGhost(group: THREE.Group): void {
    group.traverse((object) => {
        if (object instanceof THREE.LineSegments) {
            object.geometry.dispose();
            (object.material as THREE.Material).dispose();
        }
    });
    group.removeFromParent();
}

function ghostLines(segments: readonly (readonly [Vec2, Vec2])[], material: THREE.LineBasicMaterial, name: string): THREE.LineSegments {
    material.depthTest = false;
    material.depthWrite = false;
    material.toneMapped = false;
    const points = segments.flatMap(([a, b]) => [new THREE.Vector3(a.x * MM, GhostY, -a.y * MM), new THREE.Vector3(b.x * MM, GhostY, -b.y * MM)]);
    const lines = new THREE.LineSegments(new THREE.BufferGeometry().setFromPoints(points), material);
    lines.name = name;
    lines.renderOrder = 11;
    lines.raycast = (): void => undefined;
    return lines;
}
