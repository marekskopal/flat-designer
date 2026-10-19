import {Underlay, UnderlayPlacement} from '@app/models/scene';
import * as THREE from 'three';

import {MM, Vec2} from './plan-geometry';

/** An image pixel (py counted down from the top) and the plan point (mm) where it belongs. */
export interface CalibrationPoint {
    px: number;
    py: number;
    x: number;
    y: number;
}

/** Where pixel (px, py) of the image lies in the plan, mm. */
export function pixelToPlan(placement: UnderlayPlacement, px: number, py: number): Vec2 {
    const angle = THREE.MathUtils.degToRad(placement.rotation);
    const cos = Math.cos(angle);
    const sin = Math.sin(angle);
    return {
        x: placement.x + placement.scale * (px * cos + py * sin),
        y: placement.y + placement.scale * (px * sin - py * cos),
    };
}

/** The image pixel under a plan point (the inverse of `pixelToPlan`). */
export function planToPixel(placement: UnderlayPlacement, point: Vec2): {px: number; py: number} {
    const angle = THREE.MathUtils.degToRad(placement.rotation);
    const cos = Math.cos(angle);
    const sin = Math.sin(angle);
    const dx = (point.x - placement.x) / placement.scale;
    const dy = (point.y - placement.y) / placement.scale;
    return {px: dx * cos + dy * sin, py: dx * sin - dy * cos};
}

/**
 * The placement that puts two image pixels onto two plan points. Twin of the backend's
 * `UnderlayService::placementFromPoints()` (rounded the same way); null when the points coincide.
 */
export function placementFromPoints(a: CalibrationPoint, b: CalibrationPoint): UnderlayPlacement | null {
    // Image y grows downwards, plan y upwards.
    const pixelDx = b.px - a.px;
    const pixelDy = -(b.py - a.py);
    const planDx = b.x - a.x;
    const planDy = b.y - a.y;
    const pixelLength = Math.hypot(pixelDx, pixelDy);
    const planLength = Math.hypot(planDx, planDy);
    if (pixelLength < 1 || planLength < 1) {
        return null;
    }
    const scale = planLength / pixelLength;
    const angle = Math.atan2(planDy, planDx) - Math.atan2(pixelDy, pixelDx);
    const cos = Math.cos(angle);
    const sin = Math.sin(angle);
    const ax = a.px * scale;
    const ay = -a.py * scale;
    return {
        x: Math.round(a.x - (ax * cos - ay * sin)),
        y: Math.round(a.y - (ax * sin + ay * cos)),
        scale: Math.round(scale * 1e9) / 1e9,
        rotation: normalizeAngle(THREE.MathUtils.radToDeg(angle)),
    };
}

/**
 * Calibration in the editor: two points clicked on the image as it lies now, `distance` mm apart in reality and,
 * with `angle`, running at that angle (degrees counter-clockwise from +x; null keeps their direction). The first
 * point stays where it is.
 */
export function calibrateUnderlay(placement: UnderlayPlacement, a: Vec2, b: Vec2, distance: number, angle: number | null): UnderlayPlacement | null {
    const direction = angle === null ? Math.atan2(b.y - a.y, b.x - a.x) : THREE.MathUtils.degToRad(angle);
    const target = {x: a.x + distance * Math.cos(direction), y: a.y + distance * Math.sin(direction)};
    const pixelA = planToPixel(placement, a);
    const pixelB = planToPixel(placement, b);
    return placementFromPoints({...pixelA, ...a}, {...pixelB, ...target});
}

function normalizeAngle(degrees: number): number {
    const normalized = Math.round((((degrees % 360) + 360) % 360) * 1e6) / 1e6;
    return normalized >= 360 ? 0 : normalized;
}

/**
 * The image as a flat quad on the floor plane, drawn over the plan at its opacity (no depth test) so walls can be
 * traced on it. Not pickable: it carries no element tag.
 */
export function buildUnderlay(underlay: Underlay, image: TexImageSource): THREE.Mesh {
    const corners = [
        pixelToPlan(underlay, 0, 0),
        pixelToPlan(underlay, underlay.widthPx, 0),
        pixelToPlan(underlay, 0, underlay.heightPx),
        pixelToPlan(underlay, underlay.widthPx, underlay.heightPx),
    ];
    const geometry = new THREE.BufferGeometry();
    // Plan y maps to scene −z; a hair above the floor so it never fights with the room floors.
    geometry.setAttribute('position', new THREE.Float32BufferAttribute(corners.flatMap((c) => [c.x * MM, 0.002, -c.y * MM]), 3));
    geometry.setAttribute('uv', new THREE.Float32BufferAttribute([0, 1, 1, 1, 0, 0, 1, 0], 2));
    geometry.setIndex([0, 2, 1, 1, 2, 3]);
    geometry.computeVertexNormals();

    const texture = new THREE.Texture(image);
    texture.colorSpace = THREE.SRGBColorSpace;
    texture.anisotropy = 4;
    texture.needsUpdate = true;
    const material = new THREE.MeshBasicMaterial({
        map: texture,
        transparent: true,
        opacity: underlay.opacity,
        depthTest: false,
        depthWrite: false,
        side: THREE.DoubleSide,
        toneMapped: false,
    });
    const mesh = new THREE.Mesh(geometry, material);
    mesh.renderOrder = 10;
    mesh.name = 'underlay';
    return mesh;
}

export function disposeUnderlay(mesh: THREE.Mesh): void {
    mesh.removeFromParent();
    mesh.geometry.dispose();
    const material = mesh.material as THREE.MeshBasicMaterial;
    material.map?.dispose();
    material.dispose();
}
