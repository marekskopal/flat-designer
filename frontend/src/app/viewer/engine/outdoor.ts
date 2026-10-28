import {Item} from '@app/models/scene';
import * as THREE from 'three';

import {MaterialLibrary} from './materials';
import {boxAt, curvedPrismAt} from './meshes';
import {MM} from './plan-geometry';

/**
 * The ground under an item along its width: at x mm from its centre (item-local), mm against the ground under the
 * centre. A fence that follows the ground (FD-87) stands on it.
 */
export type GroundAlong = (x: number) => number;

const Level: GroundAlong = () => 0;

/**
 * Outdoor items in the item-local frame (bottom centre origin, x along the width, +z out of the
 * front). Returns null for kinds handled elsewhere.
 */
export function buildOutdoorItemBody(item: Item, materials: MaterialLibrary, ground: GroundAlong = Level): THREE.Group | null {
    return item.kind === 'Fence' ? buildFence(item, materials, ground) : null;
}

/** How a fence meets a sloping ground (`params.follow`): level, raking with it, or stepping down per bay. */
export type FenceFollow = 'Level' | 'Rake' | 'Step';

/** Whether a fence follows the ground, and so stands on it (as an item `onGround` does). */
export function fenceFollows(item: Pick<Item, 'kind' | 'params'>): boolean {
    return item.kind === 'Fence' && (item.params['follow'] === 'Rake' || item.params['follow'] === 'Step');
}

const PostSize = 60;
const BoardThickness = 50;
/** Welded mesh: vertical wires every 50 mm, horizontal every 200 mm. */
const MeshPitchU = 0.05;
const MeshPitchV = 0.2;
/** A board fence: boards this wide with this gap, on two rails. */
const BoardWidth = 95;
const BoardGap = 10;
const SlatThickness = 20;
const RailHeight = 60;
const RailDepth = 40;
/** The clearance under the boards and panels, mm. */
const Clearance = 30;
/** A sliding gate's track: a steel strip on the ground, reaching a leaf's width behind the opening. */
const TrackWidth = 60;
const TrackHeight = 15;

/**
 * Fence along x: posts every `panelLength` mm and, between them, welded-mesh panels over an optional concrete base
 * board (`baseBoard` mm high), or with `style` "Boards" vertical boards on a top and a bottom rail (the item's material
 * for both). With `follow` it stands on the ground: "Rake" runs the panels with it, "Step" keeps each bay level, high
 * enough for the ground under it. With `gate` true it is one sliding leaf of boards on a track, `open` (0–1) of its
 * width slid along +x.
 */
function buildFence(item: Item, materials: MaterialLibrary, ground: GroundAlong): THREE.Group {
    if (item.params['gate'] === true) {
        return buildSlidingGate(item, materials);
    }
    const group = new THREE.Group();
    const metal = materials.get(item.materialId);
    const panelLength = typeof item.params['panelLength'] === 'number' ? item.params['panelLength'] : 2500;
    const baseBoard = typeof item.params['baseBoard'] === 'number' ? item.params['baseBoard'] : 0;
    const boards = item.params['style'] === 'Boards';
    const follow: FenceFollow = item.params['follow'] === 'Rake' || item.params['follow'] === 'Step' ? item.params['follow'] : 'Level';
    const g = follow === 'Level' ? Level : ground;
    const mesh = boards ? null : weldedMeshMaterial(metal);

    const length = item.width;
    const height = item.height;
    const count = Math.max(1, Math.round(length / panelLength));
    const span = length / count;
    const postAt = (i: number): number => -length / 2 + i * span;
    // Each bay's floor: its ground at every point (Rake), or the highest ground under it (Step), mm.
    const bayLevels = Array.from({length: count}, (_, i) => Math.max(g(postAt(i)), g(postAt(i) + span / 2), g(postAt(i + 1))));
    const bayBase = (i: number): GroundAlong => (follow === 'Step' ? (): number => bayLevels[i] : g);

    for (let i = 0; i <= count; i++) {
        const x = postAt(i);
        // A post reaches the higher of the bays it holds.
        const bottom = g(x);
        const top = Math.max(...[i - 1, i].filter((b) => b >= 0 && b < count).map((b) => bayBase(b)(x))) + height;
        group.add(boxAt(metal, (x - PostSize / 2) * MM, bottom * MM, (-PostSize / 2) * MM, PostSize * MM, (top - bottom) * MM, PostSize * MM));
    }

    for (let i = 0; i < count; i++) {
        const from = postAt(i) + PostSize / 2;
        const to = postAt(i + 1) - PostSize / 2;
        const base = bayBase(i);
        if (mesh === null) {
            addBoards(group, metal, from, to, base, height);
            continue;
        }
        if (baseBoard > 0) {
            group.add(strip(materials.plain('#9c9890', 0.9), from, to, base, 0, baseBoard, BoardThickness));
        }
        group.add(meshPanel(mesh, from, to, base, baseBoard + Clearance / 2, height - Clearance / 2));
    }
    return group;
}

/** Vertical boards between `from` and `to` (mm) on a top and a bottom rail, each board standing on `base` at its middle. */
function addBoards(group: THREE.Group, material: THREE.Material, from: number, to: number, base: GroundAlong, height: number): void {
    const pitch = BoardWidth + BoardGap;
    const count = Math.max(1, Math.floor((to - from + BoardGap) / pitch));
    const start = from + (to - from - (count * pitch - BoardGap)) / 2;
    for (let k = 0; k < count; k++) {
        const x = start + k * pitch;
        const bottom = base(x + BoardWidth / 2) + Clearance;
        const top = base(x + BoardWidth / 2) + height;
        group.add(boxAt(material, x * MM, bottom * MM, (RailDepth / 2) * MM, BoardWidth * MM, (top - bottom) * MM, SlatThickness * MM));
    }
    for (const at of [Clearance + RailHeight, height - RailHeight * 2]) {
        group.add(strip(material, from, to, base, at, at + RailHeight, RailDepth));
    }
}

/**
 * A rail or a base board from `from` to `to` along x (mm), `bottom`–`top` mm over `base` at each end, `depth` mm
 * thick, centred on z = 0: a box on level ground, else a prism raking with it.
 */
function strip(material: THREE.Material, from: number, to: number, base: GroundAlong, bottom: number, top: number, depth: number): THREE.Mesh {
    const [b0, b1] = [base(from), base(to)];
    const half = (depth / 2) * MM;
    const right = [
        {x: from * MM, z: half},
        {x: to * MM, z: half},
    ];
    const left = right.map((p) => ({...p, z: -half}));
    const at = (h: number): {right: number[]; left: number[]} => ({right: [(b0 + h) * MM, (b1 + h) * MM], left: [(b0 + h) * MM, (b1 + h) * MM]});
    return curvedPrismAt(material, right, left, at(bottom), at(top));
}

/** A welded-mesh panel from `from` to `to` (mm), `bottom`–`top` over `base` at each end, with UVs in metres. */
function meshPanel(material: THREE.Material, from: number, to: number, base: GroundAlong, bottom: number, top: number): THREE.Mesh {
    const [b0, b1] = [base(from), base(to)];
    const corners = [
        [from, b0 + bottom],
        [to, b1 + bottom],
        [to, b1 + top],
        [from, b0 + top],
    ];
    const geometry = new THREE.BufferGeometry();
    geometry.setAttribute('position', new THREE.Float32BufferAttribute(corners.flatMap(([x, y]) => [x * MM, y * MM, 0]), 3));
    geometry.setAttribute('uv', new THREE.Float32BufferAttribute(corners.flatMap(([x, y]) => [(x - from) * MM, (y - b0 - bottom) * MM]), 2));
    geometry.setIndex([0, 1, 2, 0, 2, 3]);
    geometry.computeVertexNormals();
    const panel = new THREE.Mesh(geometry, material);
    panel.castShadow = true;
    return panel;
}

/**
 * A sliding gate: one leaf of boards the item's width on a frame, on a track along the ground from its closed place to a
 * leaf's width beyond +x, between two guide posts; slid `open` (0–1) of its width along +x.
 */
function buildSlidingGate(item: Item, materials: MaterialLibrary): THREE.Group {
    const group = new THREE.Group();
    const timber = materials.get(item.materialId);
    const steel = materials.plain('#4a4845', 0.5, 0.6);
    const length = item.width;
    const height = item.height;
    const open = typeof item.params['open'] === 'number' ? Math.max(0, Math.min(1, item.params['open'])) : 0;

    group.add(boxAt(steel, (-length / 2) * MM, 0, (-TrackWidth / 2) * MM, 2 * length * MM, TrackHeight * MM, TrackWidth * MM));
    for (const x of [-length / 2 - PostSize, length / 2]) {
        group.add(boxAt(steel, x * MM, 0, (-PostSize / 2 - RailDepth) * MM, PostSize * MM, (height + 100) * MM, PostSize * MM));
    }
    const leaf = new THREE.Group();
    leaf.name = 'gate-leaf';
    addBoards(leaf, timber, -length / 2, length / 2, Level, height);
    for (const x of [-length / 2, length / 2 - RailHeight]) {
        leaf.add(boxAt(steel, x * MM, Clearance * MM, (-RailDepth / 2) * MM, RailHeight * MM, (height - Clearance) * MM, RailDepth * MM));
    }
    leaf.position.x = open * length * MM;
    group.add(leaf);
    return group;
}

/** Wire grid as an alpha-tested texture in the fence colour, visible from both sides. */
function weldedMeshMaterial(base: THREE.Material): THREE.MeshStandardMaterial {
    const canvas = document.createElement('canvas');
    canvas.width = 64;
    canvas.height = 256;
    const ctx = canvas.getContext('2d');
    if (ctx !== null) {
        ctx.clearRect(0, 0, canvas.width, canvas.height);
        ctx.fillStyle = '#ffffff';
        ctx.fillRect(0, 0, 6, canvas.height);
        ctx.fillRect(0, 0, canvas.width, 8);
    }
    const texture = new THREE.CanvasTexture(canvas);
    texture.wrapS = THREE.RepeatWrapping;
    texture.wrapT = THREE.RepeatWrapping;
    texture.repeat.set(1 / MeshPitchU, 1 / MeshPitchV);
    texture.colorSpace = THREE.SRGBColorSpace;

    const color = base instanceof THREE.MeshStandardMaterial ? base.color : new THREE.Color('#383b3e');
    return new THREE.MeshStandardMaterial({color, map: texture, alphaTest: 0.5, side: THREE.DoubleSide, roughness: 0.5, metalness: 0.3});
}
