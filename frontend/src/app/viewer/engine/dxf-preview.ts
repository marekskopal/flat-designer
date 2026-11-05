import {DxfPlan, DxfPlanOpening} from '@app/models/dxf';
import * as THREE from 'three';

import {MM, pointOnWall, polylineSegments, Vec2, wallFaces, WallFrame, wallFrame, wallJoints, WallLine} from './plan-geometry';

const WallColor = '#d9480f';
const UnpairedColor = '#e03131';
/** Over the ghost of the floor below (0.003): the lines draw without a depth test. */
const PreviewY = 0.004;
/** Points along a door's swing. */
const SwingSteps = 12;

/**
 * The walls and openings a DXF would make, over the plan before the import: each wall's outline (mitred where walls
 * meet, as the editor will build them), each opening's jambs with a door's swing or a window's glazing line, and the
 * wall-layer lines that made no wall in red. Drawn without a depth test, like the ghost; not pickable.
 */
export function buildDxfPreview(plan: DxfPlan): THREE.Group {
    const group = new THREE.Group();
    group.name = 'dxf-preview';

    const walls: WallLine[] = plan.walls.map((wall, index) => ({...wall, id: index + 1}));
    const joints = wallJoints(walls);
    const wallSegments = walls.flatMap((wall) => {
        const {left, right} = wallFaces(wall, joints.get(wall.id));
        const outline = [...left, ...[...right].reverse()];
        return polylineSegments([...outline, outline[0]]);
    });
    const openingSegments = plan.openings.flatMap((opening) => {
        const wall = walls[opening.wall];
        return wall === undefined ? [] : openingMarks(opening, wallFrame(wall), wall.thickness);
    });
    group.add(previewLines([...wallSegments, ...openingSegments], WallColor, 0.9, 'dxf-walls'));

    const unpaired = plan.unpaired.map(([x1, y1, x2, y2]): [Vec2, Vec2] => [
        {x: x1, y: y1},
        {x: x2, y: y2},
    ]);
    if (unpaired.length > 0) {
        group.add(previewLines(unpaired, UnpairedColor, 0.9, 'dxf-unpaired'));
    }
    return group;
}

export function disposeDxfPreview(group: THREE.Group): void {
    group.traverse((object) => {
        if (object instanceof THREE.LineSegments) {
            object.geometry.dispose();
            (object.material as THREE.Material).dispose();
        }
    });
    group.removeFromParent();
}

/** An opening's jambs across the wall, plus a door's leaf and swing or a window's glazing along the centre line. */
function openingMarks(opening: DxfPlanOpening, frame: WallFrame, thickness: number): [Vec2, Vec2][] {
    const from = opening.offset;
    const to = opening.offset + opening.width;
    const half = thickness / 2;
    const marks: [Vec2, Vec2][] = [
        [pointOnWall(frame, from, -half), pointOnWall(frame, from, half)],
        [pointOnWall(frame, to, -half), pointOnWall(frame, to, half)],
    ];
    if (opening.type === 'Window') {
        marks.push([pointOnWall(frame, from), pointOnWall(frame, to)]);
        return marks;
    }
    if (opening.hinge === null || opening.side === null) {
        return marks;
    }
    // Each leaf: where it hinges and how far it reaches along the wall (− towards the start).
    const towardsEnd = opening.hinge === 'Start' ? 1 : -1;
    const hingeLeaf = opening.type === 'DoubleDoor' ? (opening.leafWidth ?? opening.width / 2) : opening.width;
    const leaves: [number, number][] = [[opening.hinge === 'Start' ? from : to, hingeLeaf * towardsEnd]];
    if (opening.type === 'DoubleDoor') {
        leaves.push([opening.hinge === 'Start' ? to : from, -(opening.width - hingeLeaf) * towardsEnd]);
    }
    const side = opening.side === 'Left' ? 1 : -1;
    for (const [hingeAt, reach] of leaves) {
        marks.push(...swing(frame, hingeAt, reach, side * half, side));
    }
    return marks;
}

/** A leaf `|reach|` long hinged at `hingeAt` on the face at `across`, standing open square to the wall, and its swing back. */
function swing(frame: WallFrame, hingeAt: number, reach: number, across: number, side: number): [Vec2, Vec2][] {
    const hinge = pointOnWall(frame, hingeAt, across);
    const radius = Math.abs(reach);
    const points: Vec2[] = [];
    for (let i = 0; i <= SwingSteps; i++) {
        const angle = (Math.PI / 2) * (i / SwingSteps);
        points.push(pointOnWall(frame, hingeAt + Math.sign(reach) * radius * Math.cos(angle), across + side * radius * Math.sin(angle)));
    }
    return [[hinge, points[SwingSteps]], ...polylineSegments(points)];
}

function previewLines(segments: readonly (readonly [Vec2, Vec2])[], color: string, opacity: number, name: string): THREE.LineSegments {
    const material = new THREE.LineBasicMaterial({color, transparent: true, opacity, depthTest: false, depthWrite: false, toneMapped: false});
    const points = segments.flatMap(([a, b]) => [new THREE.Vector3(a.x * MM, PreviewY, -a.y * MM), new THREE.Vector3(b.x * MM, PreviewY, -b.y * MM)]);
    const lines = new THREE.LineSegments(new THREE.BufferGeometry().setFromPoints(points), material);
    lines.name = name;
    lines.renderOrder = 12;
    lines.raycast = (): void => undefined;
    return lines;
}
