import {Roof, RoofWindow} from '@app/models/scene';
import * as THREE from 'three';

import {MaterialLibrary} from './materials';
import {mergeStatic} from './merge';
import {MM, Vec2} from './plan-geometry';
import {
    LocalRoofPlane,
    RoofFrame,
    roofHalfExtent,
    roofLocal,
    roofLocalPlanes,
    roofSlope,
    roofToPlan,
    roofVerticalThickness,
    RoofWindowLayout,
    roofWindowLayouts,
} from './roof-geometry';

/** A soffit without a material: white-painted timber. */
const SoffitColor = '#f1eee8';
/** A flat roof without a material: a grey membrane (the seeded `roof-membrane`). */
const MembraneCode = 'roof-membrane';
/** A pitched roof without a material: clay tiles. */
const TilesCode = 'roof-tiles-clay';

/** mm, the shortest side of a roof's eaves rectangle (`RoofHandler::MinSide`). */
export const RoofMinSide = 1000;

/** mm, the ridge and hip caps. */
const CapWidth = 240;
const CapHeight = 70;
/** mm, a flat roof's parapet wall. */
const ParapetThickness = 200;

/** mm: a roof window's frame (from the soffit to above the covering), its sash and the flashing round it. */
const WindowFrameBar = 70;
const WindowFrameRise = 90;
const WindowSashBar = 55;
const WindowFlashing = 60;
/** A roof window without a material: anthracite, like the walls' windows. */
const WindowFrameCode = 'window-frame-anthracite';

const OutlineColor = '#8a5a2b';
const SelectedOutlineColor = '#e07b00';
/** mm either side of the dashed outline that a click picks the roof by in the top view. */
const PickBand = 150;

/**
 * A roof (FD-38) in its own frame: a group at its centre, turned by its rotation, so a drag or turn moves the group. Each
 * plane is a slab `thickness` thick square to the slope: the covering on top, the soffit under it and along its edges
 * (fascia and verges); pitched roofs get caps along the ridge and hips, a flat one its parapet. Its windows go through
 * the slab (`buildRoofWindow()`, holes square to the slope cut in the plane), each tagged `roofWindowId`; a window the
 * roof has left behind (`roofWindowLayouts()` leaves it out) is not drawn. Tagged `roofId`, merged, with a section-fill
 * twin per plane, so the wall cut shows a solid section. The planes are `roofLocalPlanes()`.
 */
export function buildRoof(roof: Roof, materials: MaterialLibrary, sectionMaterial: THREE.Material): THREE.Group {
    const group = new THREE.Group();
    group.name = `roof:${roof.name}`;
    group.userData = {roofId: roof.id};
    placeRoof(group, roof);

    const defaultCovering = roof.shape === 'Flat' ? MembraneCode : TilesCode;
    const covering = roof.coveringMaterialId !== null ? materials.get(roof.coveringMaterialId) : materials.byCode(defaultCovering);
    const soffit = roof.soffitMaterialId !== null ? materials.get(roof.soffitMaterialId) : materials.plain(SoffitColor, 0.7, 0);
    const thick = roofVerticalThickness(roof);
    const windows = roofWindowLayouts(roof);
    const planes = roofLocalPlanes(roof);

    planes.forEach((plane, index) => {
        const holes = windows.filter(({layout}) => layout.plane === index).map(({layout}): SlabHole => ({top: layout.corners, under: layout.soffit}));
        const slab = planeSlab(plane, thick, roofSlope(roof), holes);
        const top = new THREE.Mesh(slab.top, covering);
        const under = new THREE.Mesh(slab.under, soffit);
        for (const mesh of [top, under]) {
            mesh.castShadow = true;
            mesh.receiveShadow = true;
            group.add(mesh);
        }
        group.add(new THREE.Mesh(slab.solid, sectionMaterial));
    });
    for (const {window, layout} of windows) {
        group.add(buildRoofWindow(roof, window, layout, planes[layout.plane], thick, materials));
    }
    if (roof.shape !== 'Flat') {
        const cap = materials.darkened(covering, 0.8);
        for (const [from, to] of capLines(roof)) {
            group.add(capAlong(from, to, cap));
        }
    }
    if (roof.shape === 'Flat' && roof.parapet !== null) {
        for (const wall of parapetWalls(roof, roof.eaveHeight + thick, roof.parapet, soffit)) {
            const twin = new THREE.Mesh(wall.geometry, sectionMaterial);
            twin.position.copy(wall.position);
            group.add(wall, twin);
        }
    }
    mergeStatic(group);
    return group;
}

/** A box (mm) centred at (x, y, z) of its parent. */
function part(material: THREE.Material, x: number, y: number, z: number, width: number, height: number, depth: number, shadow = true): THREE.Mesh {
    const mesh = new THREE.Mesh(new THREE.BoxGeometry(width * MM, height * MM, depth * MM), material);
    mesh.position.set(x * MM, y * MM, z * MM);
    mesh.castShadow = shadow;
    mesh.receiveShadow = true;
    return mesh;
}

/**
 * A roof window in the roof's group: a group on the covering at the window's centre, its x across the slope, z up it
 * and y square to it. The frame lines the hole (square to the slope, as the frame is) from the soffit to `WindowFrameRise`
 * over the covering; the sash sits in it with its glass (casting no shadow), a centre-pivot sash with its handle bar
 * along the top, a top-hung one with a handle at the bottom; metal flashing runs round it on the covering. `plane` is
 * the window's and `thick` the roof's vertical thickness. Tagged `roofWindowId`.
 */
export function buildRoofWindow(
    roof: Roof,
    window: RoofWindow,
    layout: RoofWindowLayout,
    plane: LocalRoofPlane,
    thick: number,
    materials: MaterialLibrary,
): THREE.Group {
    const group = new THREE.Group();
    group.name = `roof-window:${window.name}`;
    group.userData = {roofWindowId: window.id};

    const {x: s, y: t} = layout.centre;
    group.position.copy(at(s, t, plane.base + plane.gs * s + plane.gt * t + thick));
    const across = new THREE.Vector3(layout.side.x, 0, -layout.side.y).normalize();
    const up = new THREE.Vector3(layout.up.x, roofSlope(roof), -layout.up.y).normalize();
    const out = new THREE.Vector3().crossVectors(up, across);
    group.quaternion.setFromRotationMatrix(new THREE.Matrix4().makeBasis(across, out, up));

    const frame = window.frameMaterialId !== null ? materials.get(window.frameMaterialId) : materials.byCode(WindowFrameCode);
    const glass = materials.byCode('glass');
    const flashing = materials.plain('#55585a', 0.45, 0.6);
    const w = window.width;
    const l = window.length;
    const bottom = -roof.thickness;
    const height = roof.thickness + WindowFrameRise;
    const middle = bottom + height / 2;
    const bar = WindowFrameBar;
    group.add(
        part(frame, -(w - bar) / 2, middle, 0, bar, height, l),
        part(frame, (w - bar) / 2, middle, 0, bar, height, l),
        part(frame, 0, middle, -(l - bar) / 2, w - 2 * bar, height, bar),
        part(frame, 0, middle, (l - bar) / 2, w - 2 * bar, height, bar),
    );
    // The sash in the frame's opening, flush with its top.
    const sw = w - 2 * bar;
    const sl = l - 2 * bar;
    const sash = WindowSashBar;
    const sy = WindowFrameRise - 40;
    group.add(
        part(frame, -(sw - sash) / 2, sy, 0, sash, 80, sl),
        part(frame, (sw - sash) / 2, sy, 0, sash, 80, sl),
        part(frame, 0, sy, -(sl - sash) / 2, sw - 2 * sash, 80, sash),
        part(frame, 0, sy, (sl - sash) / 2, sw - 2 * sash, 80, sash),
        part(glass, 0, sy, 0, sw - 2 * sash, 8, sl - 2 * sash, false),
    );
    const rail = (sl - sash) / 2;
    group.add(
        window.type === 'CentrePivot'
            ? part(frame, 0, sy + 50, rail, sw * 0.6, 20, 30)
            : part(frame, 0, sy + 50, -rail, 150, 25, 30),
    );
    // The flashing round the frame, on the covering.
    const fw = w + 2 * WindowFlashing;
    const flash = WindowFlashing;
    group.add(
        part(flashing, -(w + flash) / 2, 6, 0, flash, 12, l + 2 * flash),
        part(flashing, (w + flash) / 2, 6, 0, flash, 12, l + 2 * flash),
        part(flashing, 0, 6, -(l + flash) / 2, fw - 2 * flash, 12, flash),
        part(flashing, 0, 6, (l + flash) / 2, fw - 2 * flash, 12, flash),
    );
    return group;
}

/** Puts a roof's group (or its outline) at the roof's centre, turned by its rotation. */
export function placeRoof(object: THREE.Object3D, roof: Pick<Roof, 'x' | 'y' | 'rotation'>): void {
    object.position.set(roof.x * MM, object.position.y, -roof.y * MM);
    object.rotation.y = (roof.rotation * Math.PI) / 180;
}

/** A point of the roof's frame at a height (mm) in the group's coordinates. */
const at = (s: number, t: number, h: number): THREE.Vector3 => new THREE.Vector3(s * MM, h * MM, -t * MM);

/** A window's hole through a slab: its outline at the covering and at the soffit (further up the slope, the hole being square to it). */
interface SlabHole {
    top: Vec2[];
    under: Vec2[];
}

interface PlaneSlab {
    /** The covering, with UVs in metres across and up the slope. */
    top: THREE.BufferGeometry;
    /** The soffit and the slab's edges. */
    under: THREE.BufferGeometry;
    /** Every face, closed, for the section fill. */
    solid: THREE.BufferGeometry;
}

/**
 * One plane's slab: the outline lifted onto the underside and, `thick` higher, onto the top, with `holes` (its windows,
 * counter-clockwise in the roof's frame) cut through and lined. UVs run across the slope and up it in metres (along the
 * plane, so tiles keep their size on a steep roof); the edges' along the edge and up.
 */
function planeSlab(plane: LocalRoofPlane, thick: number, slope: number, holes: readonly SlabHole[] = []): PlaneSlab {
    const underside = (p: Vec2): number => plane.base + plane.gs * p.x + plane.gt * p.y;
    // Up the slope and across it; a flat roof lays its covering along the frame.
    const length = Math.hypot(plane.gs, plane.gt);
    const up = length > 0 ? {x: plane.gs / length, y: plane.gt / length} : {x: 0, y: 1};
    const stretch = Math.sqrt(1 + slope * slope);
    const uv = (p: Vec2): [number, number] => [(p.x * up.y - p.y * up.x) * MM, (p.x * up.x + p.y * up.y) * stretch * MM];

    const polygon = plane.polygon;
    // A hole's ring at the soffit lies further up the slope than at the top; the slab's own edges are upright.
    const tops = [...polygon, ...holes.flatMap((hole) => hole.top)];
    const unders = [...polygon, ...holes.flatMap((hole) => hole.under)];
    const lower = unders.map((p) => at(p.x, p.y, underside(p)));
    const upper = tops.map((p) => at(p.x, p.y, underside(p) + thick));
    const uvs = tops.map(uv);

    const top = new FaceBuilder();
    const under = new FaceBuilder();
    const toVector = (p: Vec2): THREE.Vector2 => new THREE.Vector2(p.x, p.y);
    const triangles =
        holes.length === 0
            ? polygon.slice(1, -1).map((_, i): number[] => [0, i + 1, i + 2])
            : THREE.ShapeUtils.triangulateShape(polygon.map(toVector), holes.map((hole) => hole.top.map(toVector)));
    for (const [i, j, k] of triangles) {
        top.triangle([upper[i], upper[j], upper[k]], [uvs[i], uvs[j], uvs[k]], 1);
        under.triangle([lower[i], lower[j], lower[k]], [uvs[i], uvs[j], uvs[k]], -1);
    }
    const edges = (start: number, count: number, facing: (i: number) => THREE.Vector3): void => {
        for (let n = 0; n < count; n++) {
            const i = start + n;
            const j = start + ((n + 1) % count);
            const edge = lower[i].distanceTo(lower[j]);
            under.quad([lower[i], lower[j], upper[j], upper[i]], [[0, 0], [edge, 0], [edge, thick * MM], [0, thick * MM]], facing(n));
        }
    };
    edges(0, polygon.length, (n) => outwards(polygon, n));
    let start = polygon.length;
    for (const hole of holes) {
        // A hole's sides face into it: the inward side of its counter-clockwise outline.
        edges(start, hole.top.length, (n) => outwards(hole.top, n).negate());
        start += hole.top.length;
    }
    const solid = top.geometry(true);
    const rest = under.geometry(true);
    return {top: top.geometry(), under: under.geometry(), solid: mergeFaces(solid, rest)};
}

/** The outward direction (in the group's coordinates) of an outline's edge, the outline counter-clockwise in the roof's frame. */
function outwards(polygon: readonly Vec2[], i: number): THREE.Vector3 {
    const a = polygon[i];
    const b = polygon[(i + 1) % polygon.length];
    // Right of a → b in the frame (x, y) is (dy, −dx); y maps to −z.
    return new THREE.Vector3(b.y - a.y, 0, b.x - a.x);
}

/**
 * Collects triangles with flat normals, each turned to face along a hint: up (1), down (−1) or a direction.
 */
class FaceBuilder {
    private readonly positions: number[] = [];
    private readonly normals: number[] = [];
    private readonly uvs: number[] = [];

    public triangle(points: THREE.Vector3[], uvs: [number, number][], facing: number | THREE.Vector3): void {
        const normal = new THREE.Vector3().subVectors(points[1], points[0]).cross(new THREE.Vector3().subVectors(points[2], points[0]));
        if (normal.lengthSq() === 0) {
            return;
        }
        const wanted = typeof facing === 'number' ? new THREE.Vector3(0, facing, 0) : facing;
        const order = normal.dot(wanted) < 0 ? [0, 2, 1] : [0, 1, 2];
        normal.normalize().multiplyScalar(normal.dot(wanted) < 0 ? -1 : 1);
        for (const k of order) {
            this.positions.push(points[k].x, points[k].y, points[k].z);
            this.normals.push(normal.x, normal.y, normal.z);
            this.uvs.push(...uvs[k]);
        }
    }

    public quad(points: THREE.Vector3[], uvs: [number, number][], facing: THREE.Vector3): void {
        this.triangle([points[0], points[1], points[2]], [uvs[0], uvs[1], uvs[2]], facing);
        this.triangle([points[0], points[2], points[3]], [uvs[0], uvs[2], uvs[3]], facing);
    }

    /** `bare`: positions only, for the section fill. */
    public geometry(bare = false): THREE.BufferGeometry {
        const geometry = new THREE.BufferGeometry();
        geometry.setAttribute('position', new THREE.Float32BufferAttribute(this.positions, 3));
        if (!bare) {
            geometry.setAttribute('normal', new THREE.Float32BufferAttribute(this.normals, 3));
            geometry.setAttribute('uv', new THREE.Float32BufferAttribute(this.uvs, 2));
            geometry.userData['ownUvs'] = true;
        }
        return geometry;
    }
}

function mergeFaces(a: THREE.BufferGeometry, b: THREE.BufferGeometry): THREE.BufferGeometry {
    const positions = [...(a.getAttribute('position').array as Float32Array), ...(b.getAttribute('position').array as Float32Array)];
    a.dispose();
    b.dispose();
    const geometry = new THREE.BufferGeometry();
    geometry.setAttribute('position', new THREE.Float32BufferAttribute(positions, 3));
    return geometry;
}

/** The ridge and the hips along the top of a pitched roof, as point pairs in the group's coordinates. */
function capLines(roof: Roof): [THREE.Vector3, THREE.Vector3][] {
    const {x: a, y: b} = roofHalfExtent(roof);
    const thick = roofVerticalThickness(roof);
    const planes = roofLocalPlanes(roof);
    // The top of the covering over a point, from the lowest plane there (every pitched roof's underside is their lowest).
    const topAt = (s: number, t: number): THREE.Vector3 =>
        at(s, t, Math.min(...planes.map((p) => p.base + p.gs * s + p.gt * t)) + thick);
    switch (roof.shape) {
        case 'Gable':
            return [[topAt(-a, 0), topAt(a, 0)]];
        case 'Hip': {
            const ks = Math.max(a - b, 0);
            const kt = Math.max(b - a, 0);
            const lines: [THREE.Vector3, THREE.Vector3][] = [
                [topAt(a, -b), topAt(ks, -kt)],
                [topAt(a, b), topAt(ks, kt)],
                [topAt(-a, b), topAt(-ks, kt)],
                [topAt(-a, -b), topAt(-ks, -kt)],
            ];
            if (ks + kt > 1) {
                lines.push([topAt(-ks, -kt), topAt(ks, kt)]);
            }
            return lines;
        }
        default:
            return [];
    }
}

/** A cap along a line on the roof's top, its middle on the line. */
function capAlong(from: THREE.Vector3, to: THREE.Vector3, material: THREE.Material): THREE.Mesh {
    const length = from.distanceTo(to);
    const cap = new THREE.Mesh(new THREE.BoxGeometry(CapWidth * MM, CapHeight * MM, length), material);
    cap.position.copy(from).add(to).multiplyScalar(0.5);
    // Not yet in the group, so its own coordinates are the world's.
    cap.lookAt(to);
    cap.castShadow = true;
    return cap;
}

/** A flat roof's parapet: four walls along its outer edge, from its top `height` mm up. */
function parapetWalls(roof: Roof, bottom: number, height: number, material: THREE.Material): THREE.Mesh[] {
    const {x: a, y: b} = roofHalfExtent(roof);
    const t = ParapetThickness;
    const wall = (s: number, tt: number, along: number, across: number): THREE.Mesh => {
        const mesh = new THREE.Mesh(new THREE.BoxGeometry(along * MM, height * MM, across * MM), material);
        mesh.position.copy(at(s, tt, bottom + height / 2));
        mesh.castShadow = true;
        mesh.receiveShadow = true;
        return mesh;
    };
    return [
        wall(0, -b + t / 2, 2 * a, t),
        wall(0, b - t / 2, 2 * a, t),
        wall(-a + t / 2, 0, t, 2 * b - 2 * t),
        wall(a - t / 2, 0, t, 2 * b - 2 * t),
    ];
}

/**
 * The roof in the top view, where the wall cut leaves nothing of it: its outline with the overhangs dashed, the eaves line
 * and the ridge and hips, drawn over the plan without a depth test at the group's height (the engine lifts the outlines
 * to just under the wall cut). A band along the outline (not drawn) picks the roof, so it is selected and dragged by its
 * edge. Tagged `roofId`; `planOnly` keeps it out of exports.
 */
export function buildRoofOutline(roof: Roof): THREE.Group {
    const group = new THREE.Group();
    group.name = `roof-outline:${roof.name}`;
    group.userData = {roofId: roof.id, planOnly: true};
    placeRoof(group, roof);

    const {x: a, y: b} = roofHalfExtent(roof);
    const flat = (s: number, t: number): THREE.Vector3 => new THREE.Vector3(s * MM, 0, -t * MM);
    const loop = (half: Vec2): THREE.Vector3[] => [flat(-half.x, -half.y), flat(half.x, -half.y), flat(half.x, half.y), flat(-half.x, half.y)];

    const material = (dashed: boolean): THREE.LineBasicMaterial =>
        dashed
            ? new THREE.LineDashedMaterial({color: OutlineColor, dashSize: 0.3, gapSize: 0.15, depthTest: false, transparent: true})
            : new THREE.LineBasicMaterial({color: OutlineColor, depthTest: false, transparent: true, opacity: 0.6});
    const outline = new THREE.LineLoop(new THREE.BufferGeometry().setFromPoints(loop({x: a, y: b})), material(true));
    outline.computeLineDistances();
    const eaves = new THREE.LineLoop(new THREE.BufferGeometry().setFromPoints(loop({x: roof.width / 2, y: roof.depth / 2})), material(false));
    const lines = capLines({...roof, eaveHeight: 0, thickness: 0}).flatMap(([from, to]) => [from.setY(0), to.setY(0)]);
    const ridges = new THREE.LineSegments(new THREE.BufferGeometry().setFromPoints(lines), material(false));
    for (const line of [outline, eaves, ridges]) {
        line.renderOrder = 5;
        line.userData = {roofLine: true};
        line.raycast = (): void => undefined;
        group.add(line);
    }

    const band = new THREE.Shape([
        new THREE.Vector2(-(a + PickBand) * MM, -(b + PickBand) * MM),
        new THREE.Vector2((a + PickBand) * MM, -(b + PickBand) * MM),
        new THREE.Vector2((a + PickBand) * MM, (b + PickBand) * MM),
        new THREE.Vector2(-(a + PickBand) * MM, (b + PickBand) * MM),
    ]);
    const inner = {x: Math.max(a - PickBand, 0), y: Math.max(b - PickBand, 0)};
    band.holes.push(
        new THREE.Path([
            new THREE.Vector2(-inner.x * MM, -inner.y * MM),
            new THREE.Vector2(-inner.x * MM, inner.y * MM),
            new THREE.Vector2(inner.x * MM, inner.y * MM),
            new THREE.Vector2(inner.x * MM, -inner.y * MM),
        ]),
    );
    const pick = new THREE.Mesh(new THREE.ShapeGeometry(band).rotateX(-Math.PI / 2), new THREE.MeshBasicMaterial({visible: false}));
    group.add(pick);

    // Its windows: their outline, and an undrawn face over it that picks the window.
    for (const {window, layout} of roofWindowLayouts(roof)) {
        const corners = layout.corners.map((c) => flat(c.x, c.y));
        const frame = new THREE.LineLoop(new THREE.BufferGeometry().setFromPoints(corners), material(false));
        frame.renderOrder = 5;
        frame.userData = {roofWindowLine: window.id};
        frame.raycast = (): void => undefined;
        const face = new THREE.Shape(layout.corners.map((c) => new THREE.Vector2(c.x * MM, c.y * MM)));
        const windowPick = new THREE.Mesh(new THREE.ShapeGeometry(face).rotateX(-Math.PI / 2), new THREE.MeshBasicMaterial({visible: false}));
        windowPick.userData = {roofWindowId: window.id};
        group.add(frame, windowPick);
    }
    return group;
}

/** Colours a roof's outline as selected or not, and the outline of its selected window (`windowId`). */
export function highlightRoofOutline(group: THREE.Object3D, selected: boolean, windowId: number | null = null): void {
    group.traverse((object) => {
        if (!(object instanceof THREE.Line)) {
            return;
        }
        const line = object.userData['roofWindowLine'];
        if (object.userData['roofLine'] === true || typeof line === 'number') {
            const on = typeof line === 'number' ? line === windowId : selected;
            (object.material as THREE.LineBasicMaterial).color.set(on ? SelectedOutlineColor : OutlineColor);
        }
    });
}

type RoofRectangle = RoofFrame & Pick<Roof, 'width' | 'depth'>;

/** The corners of the eaves rectangle in the roof's frame, as signs of the half sides, counter-clockwise from the front left. */
const CornerSigns: [number, number][] = [
    [-1, -1],
    [1, -1],
    [1, 1],
    [-1, 1],
];

/** The corners of a roof's eaves rectangle in plan (mm), counter-clockwise from its front left. */
export function roofCorners(roof: RoofRectangle): Vec2[] {
    return CornerSigns.map(([i, j]) => roofToPlan(roof, (i * roof.width) / 2, (j * roof.depth) / 2));
}

/**
 * The roof resized by dragging one corner of its eaves rectangle to a plan point: the opposite corner stays, the
 * rotation too; each side at least `minSide` mm.
 */
export function resizeRoofByCorner(roof: RoofRectangle, index: number, to: Vec2, minSide: number): Pick<Roof, 'x' | 'y' | 'width' | 'depth'> {
    const [i, j] = CornerSigns[index];
    // The fixed corner in the roof's frame, and the pointer there.
    const fixed = {s: (-i * roof.width) / 2, t: (-j * roof.depth) / 2};
    const pointer = roofLocal(roof, to.x, to.y);
    // The dragged corner stays on its side of the fixed one.
    const width = Math.max(minSide, Math.round(i * (pointer.x - fixed.s)));
    const depth = Math.max(minSide, Math.round(j * (pointer.y - fixed.t)));
    const centre = roofToPlan(roof, fixed.s + (i * width) / 2, fixed.t + (j * depth) / 2);
    return {x: Math.round(centre.x), y: Math.round(centre.y), width, depth};
}
