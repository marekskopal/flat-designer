import {SectionLine} from '@app/models/scene';
import * as THREE from 'three';

import {MM} from './plan-geometry';

const LineColor = '#5a2d91';
const SelectedColor = '#2f7de1';
/** mm: the arrows at the ends, and the band either side of the line that picks it. */
const ArrowLength = 600;
const ArrowHead = 180;
const PickHalf = 150;

/**
 * A section line in the top view (FD-94): the cut as a dash-dot line, with an arrow at either end pointing the way it
 * looks (to the left of start → end), drawn over the plan without a depth test; an undrawn band along it picks it.
 * Tagged `sectionId`; `planOnly` keeps it out of exports.
 */
export function buildSectionLine(line: SectionLine): THREE.Group {
    const group = new THREE.Group();
    group.name = `section-line:${line.name}`;
    group.userData = {sectionId: line.id, planOnly: true};
    const length = Math.hypot(line.endX - line.startX, line.endY - line.startY);
    if (length < 1) {
        return group;
    }
    const dir = {x: (line.endX - line.startX) / length, y: (line.endY - line.startY) / length};
    const look = {x: -dir.y, y: dir.x};
    const at = (x: number, y: number): THREE.Vector3 => new THREE.Vector3(x * MM, 0.02, -y * MM);
    const material = new THREE.LineDashedMaterial({color: LineColor, dashSize: 0.3, gapSize: 0.08, depthTest: false, transparent: true});
    const cut = new THREE.Line(new THREE.BufferGeometry().setFromPoints([at(line.startX, line.startY), at(line.endX, line.endY)]), material);
    cut.computeLineDistances();
    const solid = new THREE.LineBasicMaterial({color: LineColor, depthTest: false, transparent: true});
    const arrows: THREE.Vector3[] = [];
    for (const [x, y] of [
        [line.startX, line.startY],
        [line.endX, line.endY],
    ]) {
        const tip = {x: x + look.x * ArrowLength, y: y + look.y * ArrowLength};
        arrows.push(at(x, y), at(tip.x, tip.y));
        for (const side of [-1, 1]) {
            const back = {x: tip.x + (dir.x * side * 0.5 - look.x) * ArrowHead, y: tip.y + (dir.y * side * 0.5 - look.y) * ArrowHead};
            arrows.push(at(tip.x, tip.y), at(back.x, back.y));
        }
    }
    const heads = new THREE.LineSegments(new THREE.BufferGeometry().setFromPoints(arrows), solid);
    for (const object of [cut, heads]) {
        object.renderOrder = 6;
        object.userData = {sectionLine: true};
        object.raycast = (): void => undefined;
        group.add(object);
    }
    const band = new THREE.Shape(
        [
            [line.startX + look.x * PickHalf, line.startY + look.y * PickHalf],
            [line.endX + look.x * PickHalf, line.endY + look.y * PickHalf],
            [line.endX - look.x * PickHalf, line.endY - look.y * PickHalf],
            [line.startX - look.x * PickHalf, line.startY - look.y * PickHalf],
        ].map(([x, y]) => new THREE.Vector2(x * MM, y * MM)),
    );
    const pick = new THREE.Mesh(new THREE.ShapeGeometry(band).rotateX(-Math.PI / 2), new THREE.MeshBasicMaterial({visible: false}));
    pick.position.y = 0.02;
    group.add(pick);
    return group;
}

/** Colours a section line as selected or not. */
export function highlightSectionLine(group: THREE.Object3D, selected: boolean): void {
    group.traverse((object) => {
        if (object instanceof THREE.Line && object.userData['sectionLine'] === true) {
            (object.material as THREE.LineBasicMaterial).color.set(selected ? SelectedColor : LineColor);
        }
    });
}
