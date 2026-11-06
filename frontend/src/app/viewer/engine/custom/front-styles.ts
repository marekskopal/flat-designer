import * as THREE from 'three';

import {boxAt} from '../meshes';
import {MM} from '../plan-geometry';

/** A front's look (`PartTypeEnum::FrontStyles`). */
export type FrontStyle = 'flat' | 'shaker' | 'fluted' | 'glass' | 'reeded';

/** The frame width (mm) of a style when the front gives none (`FramedFront::FrameWidths`). */
const FrameWidths: Partial<Record<FrontStyle, number>> = {shaker: 70, glass: 40, reeded: 40};

/** At least this much of the middle (mm) stays inside the frame, across and up (`FramedFront::MinMiddle`). */
const MinMiddle = 20;

/** A shaker panel lies this far (mm) behind the frame's face (`FramedFront::PanelRecess`). */
const PanelRecess = 6;

/** A glass pane's thickness (mm, `FramedFront::GlassThickness`). */
const GlassThickness = 4;

/** Flutes on a fluted front: `FlutePitch` apart, `FluteDepth` deep, a `FluteGap` groove between them (mm). */
const FlutePitch = 30;
const FluteDepth = 5;
const FluteGap = 5;

/** Reeds on reeded glass: `ReedPitch` apart (mm). */
const ReedPitch = 14;

/** The style of a front part (`style` option), flat when it has none or an unknown one. */
export function frontStyleOf(props: Record<string, unknown>): FrontStyle {
    const style = props['style'];
    return style === 'shaker' || style === 'fluted' || style === 'glass' || style === 'reeded' ? style : 'flat';
}

/**
 * The frame width (mm) of a front of `style`, `frame` given (0 or none: the style's own), held to leave `MinMiddle` of
 * its middle; the twin of `FramedFront::width()`, which cuts the same frame.
 */
export function frontFrame(style: FrontStyle, frame: unknown, w: number, h: number): number {
    const wanted = typeof frame === 'number' && frame > 0 ? frame : (FrameWidths[style] ?? 0);
    return Math.max(0, Math.min(wanted, Math.floor((Math.min(w, h) - MinMiddle) / 2)));
}

/** A front's box (mm, in its parent's frame; +z is its face). */
export interface FrontBody {
    x: number;
    y: number;
    z: number;
    w: number;
    h: number;
    d: number;
}

/**
 * The meshes of a front's body in its style: a slab; a shaker frame (stiles the full height, rails between) in
 * `frameMaterial` round a panel recessed `PanelRecess`; a slab with flutes standing on its face; a frame round a pane of
 * clear glass, or of reeded glass (frosted, with reeds along it).
 */
export function buildFrontBody(
    part: FrontBody,
    style: FrontStyle,
    frame: unknown,
    material: THREE.Material,
    frameMaterial: THREE.Material,
): THREE.Mesh[] {
    const add = (m: THREE.Material, x: number, y: number, z: number, w: number, h: number, d: number): THREE.Mesh =>
        boxAt(m, x * MM, y * MM, z * MM, w * MM, h * MM, d * MM);
    if (style === 'fluted') {
        const flutes = Math.max(1, Math.round(part.w / FlutePitch));
        const pitch = part.w / flutes;
        const meshes = [add(material, part.x, part.y, part.z, part.w, part.h, part.d - FluteDepth)];
        for (let i = 0; i < flutes; i++) {
            meshes.push(add(material, part.x + i * pitch + FluteGap / 2, part.y, part.z + part.d - FluteDepth, pitch - FluteGap, part.h, FluteDepth));
        }
        return meshes;
    }
    const f = style === 'flat' ? 0 : frontFrame(style, frame, part.w, part.h);
    if (f <= 0) {
        return [add(material, part.x, part.y, part.z, part.w, part.h, part.d)];
    }
    const inner = part.w - 2 * f;
    const meshes = [
        add(frameMaterial, part.x, part.y, part.z, f, part.h, part.d),
        add(frameMaterial, part.x + part.w - f, part.y, part.z, f, part.h, part.d),
        add(frameMaterial, part.x + f, part.y, part.z, inner, f, part.d),
        add(frameMaterial, part.x + f, part.y + part.h - f, part.z, inner, f, part.d),
    ];
    const [mx, my, mh] = [part.x + f, part.y + f, part.h - 2 * f];
    if (style === 'shaker') {
        meshes.push(add(material, mx, my, part.z, inner, mh, Math.max(1, part.d - PanelRecess)));
        return meshes;
    }
    const paneZ = part.z + part.d / 2 - GlassThickness / 2;
    const glass = paneMaterial(style === 'reeded');
    const pane = add(glass, mx, my, paneZ, inner, mh, GlassThickness);
    pane.castShadow = false;
    meshes.push(pane);
    if (style === 'reeded') {
        const reeds = Math.max(1, Math.floor(inner / ReedPitch));
        for (let i = 0; i < reeds; i++) {
            const reed = add(glass, mx + (i + 0.25) * (inner / reeds), my, paneZ + GlassThickness, (inner / reeds) * 0.5, mh, 1.5);
            reed.castShadow = false;
            meshes.push(reed);
        }
    }
    return meshes;
}

/**
 * Glass of a front: clear and see-through, or reeded (frosted, letting less through). One per front, disposed with its
 * meshes (not one of the library's materials); transparent rather than transmissive, as `glassMaterial()` in custom.ts.
 */
function paneMaterial(reeded: boolean): THREE.Material {
    return reeded
        ? new THREE.MeshPhysicalMaterial({color: '#e8eeed', roughness: 0.45, transparent: true, opacity: 0.55, depthWrite: false})
        : new THREE.MeshPhysicalMaterial({color: '#dfeff2', roughness: 0.05, transparent: true, opacity: 0.15, depthWrite: false});
}
