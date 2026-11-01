import * as THREE from 'three';

import {MM} from '../plan-geometry';
import {ResolvedPart} from './definition';

/**
 * Openable fronts of custom furniture (FD-106). A `front` part opens by its `opens` option (a door swings, a drawer
 * slides out by default), and an `assembly` with `opens` moves all its parts as one leaf (`props.leaf`, e.g. a drawer
 * front with its drawer box). The builder (`custom.ts`) puts each such front in a group tagged `userData.front`
 * (`FrontTag`, holding its `FrontSpec`) at the item's parts frame, with one pivot child (`userData.frontPivot`) on the
 * motion's axis, or two for a bi-fold flap; `setFrontPose()` moves the pivots. `FrontStates` keeps how far each front is
 * open, for the session only, and animates it, damping the closing of a soft-closing front. Framework-free.
 */

export type FrontMotion = 'swing' | 'slide' | 'lift' | 'fold' | 'parallel';

/** The tag of a front's group. */
export const FrontTag = 'front';
/** The tag of a pivot inside it: `main`, or `upper` / `lower` for a bi-fold flap. */
export const FrontPivotTag = 'frontPivot';

export type FrontPivot = 'main' | 'upper' | 'lower';

/** A part's box, mm in the parts frame (origin at the item's left-bottom-back corner). */
export interface FrontBox {
    x: number;
    y: number;
    z: number;
    w: number;
    h: number;
    d: number;
}

/** How one front opens, its defaults resolved. */
export interface FrontSpec {
    /** Unique in the item: an assembly's `leaf.key`, else `front:<n>` (the n-th front of its own, in part order). */
    key: string;
    motion: FrontMotion;
    /** The front's box, or the moving assembly's. */
    box: FrontBox;
    hinge: 'left' | 'right';
    /** Degrees a swinging door or a flap turns (a bi-fold's upper panel). */
    angle: number;
    /** Mm a drawer slides out or a parallel flap rises. */
    travel: number;
    softClose: boolean;
    /** A bi-fold flap's panels (mm high, upper first), `FoldJoint` apart; null for the other motions. */
    fold: [number, number] | null;
}

/** The gap between a bi-fold flap's panels, mm; the twin of `FoldingFront::Joint` (the cut list). */
export const FoldJoint = 2;

const DefaultAngles: Record<FrontMotion, number> = {swing: 105, lift: 100, fold: 90, slide: 0, parallel: 0};
/** A drawer slides out this share of its reach into the item. */
const SlideShare = 0.75;
/** A parallel flap rises its height and this much more, mm. */
const ParallelClearance = 10;

/**
 * How the part opens, or null when it does not: a part of a moving assembly by its `leaf`, a front by its own
 * options (`ordinal` numbers the fronts of their own in part order, for their key).
 */
export function frontSpec(part: ResolvedPart, ordinal: number): FrontSpec | null {
    const leaf = part.props['leaf'];
    if (isRecord(leaf)) {
        const box = Array.isArray(leaf['box']) ? (leaf['box'] as unknown[]) : [];
        const [x, y, z, w, h, d] = [0, 1, 2, 3, 4, 5].map((i) => (typeof box[i] === 'number' ? box[i] : 0));
        const motion = motionOf(leaf['opens']);
        return motion === null || motion === 'fold'
            ? null
            : specOf(String(leaf['key']), motion, {x, y, z, w, h, d}, leaf, hingeOf(leaf['hinge'], 'left'));
    }
    if (part.type !== 'front') {
        return null;
    }
    const opens = part.props['opens'] ?? (part.props['kind'] === 'drawer' ? 'slide' : 'swing');
    const motion = motionOf(opens);
    if (motion === null) {
        return null;
    }
    // A handle on the right means a hinge on the left, as on a door.
    const fallback = part.props['handleSide'] === 'left' ? 'right' : 'left';
    const box = {x: part.x, y: part.y, z: part.z, w: part.w, h: part.h, d: part.d};
    return specOf(`front:${ordinal}`, motion, box, part.props, hingeOf(part.props['hinge'], fallback));
}

function specOf(key: string, motion: FrontMotion, box: FrontBox, options: Record<string, unknown>, hinge: 'left' | 'right'): FrontSpec {
    const number = (name: string): number | null => (typeof options[name] === 'number' && options[name] > 0 ? options[name] : null);
    const travel = number('travel') ?? (motion === 'parallel' ? box.h + ParallelClearance : Math.round(SlideShare * (box.z + box.d)));
    const fold = motion === 'fold' ? foldPanels(box.h, number('foldAt')) : null;
    return {
        key,
        // Too low to cut in two, it lifts whole (as the cut list keeps it whole).
        motion: motion === 'fold' && fold === null ? 'lift' : motion,
        box,
        hinge,
        angle: Math.min(number('angle') ?? DefaultAngles[motion], 180),
        travel: Math.max(travel, 1),
        softClose: options['softClose'] === true,
        fold,
    };
}

/**
 * The heights of a bi-fold flap's panels, upper first: it folds `foldAt` mm below its top (default and at most half
 * way), `FoldJoint` between them; null for a front too low to cut in two. The twin of `FoldingFront::panels()`
 * (backend, the cut list).
 */
export function foldPanels(height: number, foldAt: number | null): [number, number] | null {
    if (height < 2 * FoldJoint + 2) {
        return null;
    }
    const half = Math.floor(height / 2);
    const fold = Math.max(FoldJoint, Math.min(foldAt ?? half, half));
    const joint = Math.floor(FoldJoint / 2);
    return [fold - joint, height - fold - joint];
}

function motionOf(value: unknown): FrontMotion | null {
    return value === 'swing' || value === 'slide' || value === 'lift' || value === 'fold' || value === 'parallel' ? value : null;
}

function hingeOf(value: unknown, fallback: 'left' | 'right'): 'left' | 'right' {
    return value === 'left' || value === 'right' ? value : fallback;
}

function isRecord(value: unknown): value is Record<string, unknown> {
    return typeof value === 'object' && value !== null && !Array.isArray(value);
}

/** Where a pivot of the front stands while it is closed, mm in the parts frame. */
export function pivotPoint(spec: FrontSpec, pivot: FrontPivot): THREE.Vector3 {
    const {x, y, z, w, h} = spec.box;
    switch (spec.motion) {
        case 'swing':
            // The hinge edge of the back face.
            return new THREE.Vector3(spec.hinge === 'left' ? x : x + w, y, z);
        case 'lift':
            return new THREE.Vector3(x, y + h, z);
        case 'fold':
            // The upper panel turns about the top back edge, the lower about its own top back edge (the knuckle).
            return new THREE.Vector3(x, pivot === 'lower' ? y + h - (spec.fold?.[0] ?? 0) : y + h, z);
        default:
            return new THREE.Vector3(x, y, z);
    }
}

/** The pose of a pivot at `t` (0 closed … 1 open): its offset from the closed place (mm) and its turn (radians). */
export interface PivotPose {
    offset: THREE.Vector3;
    rotation: THREE.Euler;
}

export function pivotPose(spec: FrontSpec, pivot: FrontPivot, t: number): PivotPose {
    const offset = new THREE.Vector3();
    const rotation = new THREE.Euler();
    const angle = THREE.MathUtils.degToRad(spec.angle) * t;
    switch (spec.motion) {
        case 'swing':
            // The free edge comes out (+z).
            rotation.y = spec.hinge === 'left' ? -angle : angle;
            break;
        case 'slide':
            offset.z = spec.travel * t;
            break;
        case 'lift':
            // The bottom edge comes out and up.
            rotation.x = -angle;
            break;
        case 'parallel': {
            // Upright on parallel arms swinging from 45° below level to 45° above: out and back in, `travel` up.
            const radius = spec.travel / Math.SQRT2;
            const arm = (t * Math.PI) / 2 - Math.PI / 4;
            offset.y = radius * (Math.sin(arm) + Math.SQRT1_2);
            offset.z = radius * (Math.cos(arm) - Math.SQRT1_2);
            break;
        }
        case 'fold': {
            const [upper, lower] = spec.fold ?? [0, 0];
            if (pivot === 'upper') {
                rotation.x = -angle;
                break;
            }
            // The knuckle follows the upper panel's bottom back edge; the lower panel's bottom edge runs up the
            // front's back face (as on a bi-fold lift), so the panels lie back to back once folded.
            const h1 = upper;
            const h2 = lower + FoldJoint;
            const out = h1 * Math.sin(angle);
            offset.y = h1 - h1 * Math.cos(angle);
            offset.z = out;
            rotation.x = Math.atan2(out, Math.sqrt(Math.max(h2 * h2 - out * out, 0)));
            break;
        }
    }
    return {offset, rotation};
}

/** Poses a front's group (tagged `FrontTag`) at `t`, 0 closed … 1 open. */
export function setFrontPose(front: THREE.Object3D, t: number): void {
    const spec = front.userData[FrontTag] as FrontSpec;
    for (const child of front.children) {
        const pivot = child.userData[FrontPivotTag] as FrontPivot | undefined;
        if (pivot === undefined) {
            continue;
        }
        const pose = pivotPose(spec, pivot, t);
        child.position.copy(pivotPoint(spec, pivot).add(pose.offset)).multiplyScalar(MM);
        child.rotation.copy(pose.rotation);
    }
}

/** The fronts' groups of built items, found once per item object (a rebuilt item is a new object). */
const frontsByItem = new WeakMap<THREE.Object3D, THREE.Object3D[]>();

/** The fronts' groups under `root` (a built item: its fronts do not change afterwards). */
export function frontObjects(root: THREE.Object3D): THREE.Object3D[] {
    let fronts = frontsByItem.get(root);
    if (fronts === undefined) {
        const found: THREE.Object3D[] = [];
        root.traverse((object) => {
            if (object.userData[FrontTag] !== undefined) {
                found.push(object);
            }
        });
        fronts = found;
        frontsByItem.set(root, fronts);
    }
    return fronts;
}

/** The front's group holding `object` (a mesh hit by a ray), or null. */
export function frontOf(object: THREE.Object3D | null): THREE.Object3D | null {
    for (let o = object; o !== null; o = o.parent) {
        if (o.userData[FrontTag] !== undefined) {
            return o;
        }
    }
    return null;
}

export function frontSpecOf(front: THREE.Object3D): FrontSpec {
    return front.userData[FrontTag] as FrontSpec;
}

/** Acceleration and top speed of a front, in its travel (0 … 1) per second (squared): about half a second each way. */
const Acceleration = 16;
const TopSpeed = 4;
/** A soft close takes over for the last `SoftZoneMm` of a sliding front or `SoftZoneDegrees` of a turning one (at most a fifth). */
const SoftZoneMm = 50;
const SoftZoneDegrees = 12;
/** Inside it the front slows exponentially with this time constant (s)… */
const SoftTimeConstant = 0.12;
/** … and shuts once this close, mm or degrees. */
const SoftSnapMm = 0.5;
const SoftSnapDegrees = 0.3;
/** Without one it bangs shut: it bounces back by this share of its speed, until slower than `RestSpeed`. */
const Restitution = 0.15;
const RestSpeed = 0.2;

export interface FrontState {
    /** 0 closed … 1 open. */
    position: number;
    /** Travel per second, + opening. */
    velocity: number;
    target: 0 | 1;
    /** Moved since it was last posed. */
    moved?: boolean;
    /** Where the soft close takes over and where it shuts (shares of the travel); null without one. */
    soft: {zone: number; snap: number} | null;
}

/** The soft close of a front, as shares of its travel; null when it has none. */
export function softCloseOf(spec: FrontSpec): {zone: number; snap: number} | null {
    if (!spec.softClose) {
        return null;
    }
    const sliding = spec.motion === 'slide' || spec.motion === 'parallel';
    const range = sliding ? spec.travel : spec.angle;
    const zone = Math.min(sliding ? SoftZoneMm : SoftZoneDegrees, range / 5);
    return {zone: zone / range, snap: (sliding ? SoftSnapMm : SoftSnapDegrees) / range};
}

/**
 * How far each front of the items is open (session only, never stored), by item id and front key, animated towards
 * its target with a speed of its own, so a front turned back mid-way does not jump. Opening speeds up and slows to a
 * stop; closing speeds up and bangs shut with a small rebound, or, with `softClose`, slows down over the last stretch.
 * With every front open (`setAll()`), fronts of items built later open too.
 */
export class FrontStates {
    private readonly states = new Map<number, Map<string, FrontState>>();
    private allOpen = false;

    /** Whether every front was opened (the Display toggle). */
    public get everyOpen(): boolean {
        return this.allOpen;
    }

    /** Whether the front is open or opening. */
    public isOpen(itemId: number, key: string): boolean {
        return this.states.get(itemId)?.get(key)?.target === 1;
    }

    /** Whether any front of the item is open or opening. */
    public anyOpen(itemId: number): boolean {
        return [...(this.states.get(itemId)?.values() ?? [])].some((s) => s.target === 1);
    }

    /** Opens or closes one front; returns whether it is now opening. */
    public toggle(itemId: number, front: THREE.Object3D): boolean {
        const spec = frontSpecOf(front);
        const state = this.state(itemId, spec);
        state.target = state.target === 1 ? 0 : 1;
        return state.target === 1;
    }

    /** Opens or closes the fronts of one item. */
    public setItem(itemId: number, item: THREE.Object3D, open: boolean, animate = true): void {
        for (const front of frontObjects(item)) {
            this.set(this.state(itemId, frontSpecOf(front)), open, animate);
        }
    }

    /** Opens or closes every front of the items; fronts of items built later follow while every one is open. */
    public setAll(open: boolean, items: ReadonlyMap<number, THREE.Object3D>, animate = true): void {
        this.allOpen = open;
        items.forEach((item, itemId) => this.setItem(itemId, item, open, animate));
    }

    /** Forgets every front (another floor), keeping whether every one is open. */
    public clear(): void {
        this.states.clear();
    }

    /** Advances the fronts by `dt` seconds; true when any moved (`apply(…, true)` poses those). */
    public update(dt: number): boolean {
        const step = Math.min(dt, 0.1);
        this.states.forEach((fronts) =>
            fronts.forEach((state) => {
                state.moved = advance(state, step) || state.moved;
            }),
        );
        return [...this.states.values()].some((fronts) => [...fronts.values()].some((state) => state.moved));
    }

    /**
     * Poses the fronts of the items as they are now: those with a state, and while every front is open, the others
     * too (items built since, opened at once). Items are built closed, so the rest stay as they are. With `moved`, only
     * the fronts `update()` moved since (the animation's frames).
     */
    public apply(items: ReadonlyMap<number, THREE.Object3D>, moved = false): void {
        if (this.allOpen && !moved) {
            items.forEach((item, itemId) => {
                for (const front of frontObjects(item)) {
                    const spec = frontSpecOf(front);
                    if (this.states.get(itemId)?.has(spec.key) !== true) {
                        this.set(this.state(itemId, spec), true, false);
                    }
                }
            });
        }
        this.states.forEach((fronts, itemId) => {
            const item = items.get(itemId);
            if (item === undefined || (moved && ![...fronts.values()].some((state) => state.moved))) {
                return;
            }
            for (const front of frontObjects(item)) {
                const state = fronts.get(frontSpecOf(front).key);
                if (state !== undefined && (!moved || state.moved)) {
                    setFrontPose(front, state.position);
                    state.moved = false;
                }
            }
        });
    }

    private state(itemId: number, spec: FrontSpec): FrontState {
        let fronts = this.states.get(itemId);
        if (fronts === undefined) {
            fronts = new Map();
            this.states.set(itemId, fronts);
        }
        let state = fronts.get(spec.key);
        if (state === undefined) {
            state = {position: 0, velocity: 0, target: 0, soft: null};
            fronts.set(spec.key, state);
        }
        // The definition may have changed since.
        state.soft = softCloseOf(spec);
        return state;
    }

    private set(state: FrontState, open: boolean, animate: boolean): void {
        state.target = open ? 1 : 0;
        if (!animate) {
            state.position = state.target;
            state.velocity = 0;
        }
    }
}

/** One step of a front towards its target; true when it moved. */
export function advance(state: FrontState, dt: number): boolean {
    if (state.position === state.target && state.velocity === 0) {
        return false;
    }
    if (state.target === 1) {
        // Speed up, then brake so as to stop at the end.
        const braking = Math.sqrt(2 * Acceleration * Math.max(1 - state.position, 0));
        state.velocity = Math.min(state.velocity + Acceleration * dt, TopSpeed, braking);
        state.position += state.velocity * dt;
        if (state.position >= 1 - 1e-3) {
            state.position = 1;
            state.velocity = 0;
        }
        return true;
    }
    const soft = state.soft;
    if (soft !== null && state.position <= soft.zone) {
        // The damper has caught it: it slows as it comes in.
        state.velocity = -state.position / SoftTimeConstant;
        state.position += state.velocity * dt;
        if (state.position <= soft.snap) {
            state.position = 0;
            state.velocity = 0;
        }
        return true;
    }
    state.velocity = Math.max(state.velocity - Acceleration * dt, -TopSpeed);
    state.position += state.velocity * dt;
    if (soft !== null && state.position < soft.zone) {
        // Caught at the edge of the zone, not past it.
        state.position = soft.zone;
    }
    if (state.position <= 0) {
        // Shut: it bounces back a little, until too slow to.
        const bounce = -state.velocity * Restitution;
        state.position = 0;
        state.velocity = bounce < RestSpeed ? 0 : bounce;
    }
    return true;
}

/** A fresh state, for tests. */
export function frontState(position: number, target: 0 | 1, spec: FrontSpec): FrontState {
    return {position, velocity: 0, target, soft: softCloseOf(spec)};
}
