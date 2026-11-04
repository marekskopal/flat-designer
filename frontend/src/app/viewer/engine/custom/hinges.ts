import * as THREE from 'three';

import {MM} from '../plan-geometry';
import type {FrontBox, FrontSpec} from './fronts';

/**
 * The hinges of a swinging cupboard door. A concealed (cup) hinge is a four-bar linkage: two links join pivots in the
 * arm's nose, fixed on the carcass side, to pivots in the cup sunk into the door's back, so the door moves forward and
 * in over the side while it turns, its hinge edge never swinging out past the side. A butt hinge turns the door about
 * its back hinge edge. The linkage is given in the hinge frame: u in from the door's hinge edge, v out of its back
 * face, mm, the door closed. Framework-free.
 */

export type HingeType = 'concealed' | 'butt';

/** A concealed hinge opens at most this far (a wide-angle hinge), degrees. */
export const ConcealedMaxAngle = 155;

/** The arm's fixed pivots, then the cup's matching moving ones, (u, v) mm. */
const ArmPivots: readonly (readonly [number, number])[] = [
    [26, -0.9],
    [35.4, 8.3],
];
const CupPivots: readonly (readonly [number, number])[] = [
    [6.2, 5.7],
    [9.5, 11.5],
];
/** The arm's nose round its pivots (it lies in the cup while the door is closed), mm. */
const nose = {
    u0: Math.min(...ArmPivots.map(([u]) => u)) - 3,
    u1: Math.max(...ArmPivots.map(([u]) => u)) + 3,
    v0: Math.min(...ArmPivots.map(([, v]) => v)) - 2,
    v1: Math.max(...ArmPivots.map(([, v]) => v)) + 2,
};
/** The cup: Ø35, bored 4 mm from the door's edge, 12 deep; its flange stands 1 mm proud of the back. */
const CupRadius = 17.5;
const CupCentre = 4 + CupRadius;
const FlangeRadius = 19.5;
const FlangeThickness = 1;
/** The mounting plate on the side's inner face (its middle 37 mm behind the front) and the arm on it, mm. */
const PlateSetback = 37;
const PlateLength = 40;
const PlateHeight = 42;
const PlateThickness = 3;
const ArmWidth = 10;
const ArmHeight = 14;
const LinkThickness = 2;
const LinkHeights = [10, 6];
/** A butt hinge: a barrel on the hinge axis, half on the door, half on the carcass, with a leaf on each. */
const BarrelRadius = 3;
const ButtLength = 60;
const ButtLeaf = 20;
/** Hinges stand this far from the door's top and bottom, at most a quarter of its height. */
const HingeInset = 100;

/** The tag of a concealed hinge's link (in the front's group, posed by `poseHingeLinks()`). */
export const FrontLinkTag = 'frontLink';

interface LinkTag {
    /** Which link: 0 or 1. */
    link: number;
    /** Its height, mm in the parts frame. */
    y: number;
}

/** The door's offset (u, v) in the hinge frame at each half degree of its turn, from closed. */
let offsets: [number, number][] | null = null;
const Step = 0.5;

/** How far the linkage carries the door (u in, v out, mm) when it has turned `degrees`. */
export function concealedOffset(degrees: number): [number, number] {
    const table = offsetTable();
    const at = Math.min(Math.max(degrees, 0), ConcealedMaxAngle) / Step;
    const i = Math.min(Math.floor(at), table.length - 2);
    const f = at - i;
    const [u0, v0] = table[i];
    const [u1, v1] = table[i + 1];
    return [u0 + (u1 - u0) * f, v0 + (v1 - v0) * f];
}

/**
 * The linkage solved from closed by continuation: for a turn θ the door's offset T puts each cup pivot at its link's
 * length from its arm pivot, so T lies on two circles; of their two crossings the one nearer the last pose is taken.
 */
function offsetTable(): [number, number][] {
    if (offsets !== null) {
        return offsets;
    }
    const lengths = ArmPivots.map(([au, av], i) => Math.hypot(au - CupPivots[i][0], av - CupPivots[i][1]));
    const table: [number, number][] = [[0, 0]];
    for (let step = 1; step <= ConcealedMaxAngle / Step; step++) {
        const [cu, cv] = turned(CupPivots[0], step * Step);
        const [du, dv] = turned(CupPivots[1], step * Step);
        const c1: [number, number] = [ArmPivots[0][0] - cu, ArmPivots[0][1] - cv];
        const c2: [number, number] = [ArmPivots[1][0] - du, ArmPivots[1][1] - dv];
        table.push(nearestCrossing(c1, lengths[0], c2, lengths[1], table[table.length - 1]));
    }
    offsets = table;
    return table;
}

/** A point of the door (hinge frame) turned `degrees` about the hinge frame's origin, the free edge coming out. */
function turned([u, v]: readonly [number, number], degrees: number): [number, number] {
    const a = THREE.MathUtils.degToRad(degrees);
    return [u * Math.cos(a) - v * Math.sin(a), u * Math.sin(a) + v * Math.cos(a)];
}

function nearestCrossing(c1: [number, number], r1: number, c2: [number, number], r2: number, last: [number, number]): [number, number] {
    const dx = c2[0] - c1[0];
    const dz = c2[1] - c1[1];
    const distance = Math.hypot(dx, dz);
    const along = (r1 * r1 - r2 * r2 + distance * distance) / (2 * distance);
    const across = Math.sqrt(Math.max(r1 * r1 - along * along, 0));
    const mu = c1[0] + (along * dx) / distance;
    const mv = c1[1] + (along * dz) / distance;
    const a: [number, number] = [mu + (across * dz) / distance, mv - (across * dx) / distance];
    const b: [number, number] = [mu - (across * dz) / distance, mv + (across * dx) / distance];
    const gap = (p: [number, number]): number => Math.hypot(p[0] - last[0], p[1] - last[1]);
    return gap(a) <= gap(b) ? a : b;
}

/** The heights of a door's hinges, mm from its bottom: 2 up to 900 mm, 3 up to 1600, 4 up to 2000, else 5. */
export function frontHingeHeights(height: number): number[] {
    const count = height <= 900 ? 2 : height <= 1600 ? 3 : height <= 2000 ? 4 : 5;
    const inset = Math.min(HingeInset, height / 4);
    return Array.from({length: count}, (_, i) => inset + ((height - 2 * inset) * i) / (count - 1));
}

/** A point of the hinge frame (u, v) at height y, mm in the parts frame, the door closed. */
function partsPoint(door: FrontBox, hinge: 'left' | 'right', u: number, v: number, y: number): THREE.Vector3 {
    return new THREE.Vector3(hinge === 'left' ? door.x + u : door.x + door.w - u, y, door.z + v);
}

/** The hinges of a swinging door, mm in the parts frame. */
export interface FrontHinges {
    /** On the door: turning with it (its pivot's content). */
    door: THREE.Object3D[];
    /** On the carcass: standing still. */
    carcass: THREE.Object3D[];
    /** A concealed hinge's links, tagged `FrontLinkTag`, posed by `poseHingeLinks()`. */
    links: THREE.Object3D[];
}

/**
 * The hinges of a swinging door: a concealed hinge's cup in the door's back, its mounting plate and arm on the carcass
 * side's inner face (`sideInner`, u mm), and its links; or a butt hinge's barrel and leaves. Without a side to fix them
 * to (`sideInner` null) only what the door carries is drawn. Positions in metres in the parts frame, the door closed.
 */
export function buildFrontHinges(spec: FrontSpec, sideInner: number | null, material: THREE.Material): FrontHinges {
    const hinges: FrontHinges = {door: [], carcass: [], links: []};
    const {door, hinge} = spec;
    const block = (u0: number, u1: number, v0: number, v1: number, y: number, h: number): THREE.Mesh => {
        const a = partsPoint(door, hinge, u0, v0, y - h / 2);
        const b = partsPoint(door, hinge, u1, v1, y + h / 2);
        const size = new THREE.Vector3().subVectors(b, a);
        const mesh = metal(shared('box', Math.abs(size.x), Math.abs(size.y), Math.abs(size.z)), material);
        mesh.position.copy(a.add(b).multiplyScalar(MM / 2));
        return mesh;
    };
    for (const height of frontHingeHeights(door.h)) {
        const y = door.y + height;
        if (spec.hingeType === 'butt') {
            hinges.door.push(
                barrel(door, hinge, y, y + ButtLength / 2, material),
                block(BarrelRadius, BarrelRadius + ButtLeaf, -1, 0, y, ButtLength),
            );
            if (sideInner !== null) {
                hinges.carcass.push(
                    barrel(door, hinge, y - ButtLength / 2, y, material),
                    block(-1, 0, -BarrelRadius - ButtLeaf, -BarrelRadius, y, ButtLength),
                );
            }
            continue;
        }
        const cup = metal(shared('cylinder', FlangeRadius, FlangeThickness, 24), material);
        cup.rotation.x = Math.PI / 2;
        cup.position.copy(partsPoint(door, hinge, CupCentre, -FlangeThickness / 2, y)).multiplyScalar(MM);
        hinges.door.push(cup);
        if (sideInner === null) {
            continue;
        }
        const plateBack = -PlateSetback - PlateLength / 2;
        hinges.carcass.push(
            block(sideInner, sideInner + PlateThickness, plateBack, plateBack + PlateLength, y, PlateHeight),
            block(sideInner + PlateThickness, sideInner + PlateThickness + ArmWidth, plateBack + 2, -2, y, ArmHeight),
            block(Math.min(sideInner + PlateThickness, nose.u0), nose.u1, Math.min(nose.v0, -2), nose.v1, y, ArmHeight - 2),
        );
        ArmPivots.forEach(([au, av], i) => {
            const length = Math.hypot(au - CupPivots[i][0], av - CupPivots[i][1]);
            const link = metal(shared('box', length, LinkHeights[i], LinkThickness), material);
            // Small and inside the carcass: no shadow.
            link.castShadow = false;
            link.userData[FrontLinkTag] = {link: i, y} satisfies LinkTag;
            hinges.links.push(link);
        });
    }
    hinges.links.forEach((link) => poseLink(link, spec, 0));
    return hinges;
}

function barrel(door: FrontBox, hinge: 'left' | 'right', bottom: number, top: number, material: THREE.Material): THREE.Mesh {
    const mesh = metal(shared('cylinder', BarrelRadius, top - bottom, 12), material);
    mesh.position.copy(partsPoint(door, hinge, 0, 0, (bottom + top) / 2)).multiplyScalar(MM);
    return mesh;
}

/**
 * The hinges' geometries, shared by every hinge of every item (they are few sizes). An item's rebuild disposes them
 * with the item, which frees their buffers only: three.js uploads them again when they are next drawn.
 */
const geometries = new Map<string, THREE.BufferGeometry>();

/** A box (width, height, depth) or an upright cylinder (radius, height, segments), mm, made once. */
function shared(shape: 'box' | 'cylinder', a: number, b: number, c: number): THREE.BufferGeometry {
    const key = `${shape}:${a.toFixed(2)}:${b.toFixed(2)}:${c.toFixed(2)}`;
    let geometry = geometries.get(key);
    if (geometry === undefined) {
        geometry = shape === 'box' ? new THREE.BoxGeometry(a * MM, b * MM, c * MM) : new THREE.CylinderGeometry(a * MM, a * MM, b * MM, c);
        geometries.set(key, geometry);
    }
    return geometry;
}

function metal(geometry: THREE.BufferGeometry, material: THREE.Material): THREE.Mesh {
    const mesh = new THREE.Mesh(geometry, material);
    mesh.name = 'part:Hinge';
    mesh.userData['partName'] = 'Hinge';
    mesh.castShadow = true;
    mesh.receiveShadow = true;
    return mesh;
}

/** Poses the links of a front's concealed hinges (children of its group tagged `FrontLinkTag`) for the door's turn. */
export function poseHingeLinks(front: THREE.Object3D, spec: FrontSpec, degrees: number): void {
    for (const child of front.children) {
        if (child.userData[FrontLinkTag] !== undefined) {
            poseLink(child, spec, degrees);
        }
    }
}

function poseLink(link: THREE.Object3D, spec: FrontSpec, degrees: number): void {
    const {link: i, y} = link.userData[FrontLinkTag] as LinkTag;
    const [tu, tv] = concealedOffset(degrees);
    const [cu, cv] = turned(CupPivots[i], degrees);
    const from = partsPoint(spec.door, spec.hinge, ArmPivots[i][0], ArmPivots[i][1], y);
    const to = partsPoint(spec.door, spec.hinge, cu + tu, cv + tv, y);
    link.position.copy(from).add(to).multiplyScalar(MM / 2);
    link.rotation.set(0, -Math.atan2(to.z - from.z, to.x - from.x), 0);
    // Closed, a link lies in the cup, unseen: not drawn.
    link.visible = degrees > 0;
}
