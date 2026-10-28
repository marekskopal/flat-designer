import {PlanPoint, Scene} from '@app/models/scene';
import * as THREE from 'three';

import {collideCircle, MM, pointInPolygon, polygonSignedArea, Vec2, WallCollider} from './plan-geometry';
import {drapedOn} from './plots';
import {StairPath, stairSpot} from './stairs';
import {hasTerrain, plotTerrain, Terrain, terrainAt} from './terrain';

/** Eye level of a 180 cm tall person, mm above the floor. */
const EyeHeight = 1630;
/** Half the shoulder width: how close the eye may get to a wall (mm). */
const BodyRadius = 250;
/** mm per second. */
const WalkSpeed = 1400;
const RunSpeed = 3000;
/** Longest move checked against the walls at once (mm); thinner than any wall plus the body radius, so nothing is skipped. */
const MaxStep = 100;
/** Radians per pixel of mouse movement. */
const LookSensitivity = 0.0022;
/** Radians per pixel of a finger dragging the view (it grabs the scene, so it turns the other way than the mouse). */
const TouchLookSensitivity = 0.005;
const MaxPitch = THREE.MathUtils.degToRad(85);
/** A wider lens than the orbit view: at eye level 45° feels like looking through a telephoto. */
export const WalkthroughFov = 70;

const ForwardKeys = ['KeyW', 'ArrowUp'];
const BackwardKeys = ['KeyS', 'ArrowDown'];
const LeftKeys = ['KeyA', 'ArrowLeft'];
const RightKeys = ['KeyD', 'ArrowRight'];
const MoveKeys = new Set([...ForwardKeys, ...BackwardKeys, ...LeftKeys, ...RightKeys]);
/** Opens or closes the door the walker looks at. */
const UseKey = 'KeyE';
/** Take the walker to the floor above or below. */
const FloorKeys: Record<string, 1 | -1> = {PageUp: 1, PageDown: -1};

/**
 * Where the walker stands on the floor's level, mm: plan point → height of the floor under it. `near` is the height
 * the walker stood at last, for a stair whose walking line passes over itself. `tooSteep` tells whether a step
 * between two points climbs or drops the ground steeper than `MaxSlope` (stairs and room floors never do).
 */
export type FloorLevels = ((point: Vec2, near?: number) => number) & {tooSteep?: (from: Vec2, to: Vec2) => boolean};

/** The steepest sloping ground the walker goes up or down, as rise over run (35°). */
const MaxSlope = Math.tan(THREE.MathUtils.degToRad(35));

/**
 * The height of what is underfoot at a plan point, mm against the floor's level: a stair there (the floor's own going
 * up, or one coming up from below through its void: `stairSpot()`, a smooth ramp along its walking line; `stairs` are
 * `stairPaths()` of the scene, computed once by the caller), else the floor of the room there (the smallest one where
 * rooms overlap: a terrace inside a garden; a garden, path or driveway on a sloping plot follows its ground), else the
 * ground of the plot there (sloping with spot heights), else the floor's level. Steps do not block the walk; the
 * sloping ground does where it is steeper than `MaxSlope`. Keeps only the outlines and levels, not the scene.
 */
export function floorLevels(scene: Pick<Scene, 'rooms' | 'plots'>, stairs: readonly StairPath[] = []): FloorLevels {
    const plots = scene.plots ?? [];
    type Level = {polygon: PlanPoint[]; level: number; terrain: Terrain | null};
    const rooms: (Level & {area: number})[] = scene.rooms
        .map((r) => ({
            polygon: r.polygon,
            level: r.floorElevation ?? 0,
            terrain: drapedOn(r, plots),
            area: Math.abs(polygonSignedArea(r.polygon)),
        }))
        .sort((a, b) => a.area - b.area);
    const all: Level[] = [...rooms, ...plots.map((p): Level => ({polygon: p.polygon, level: 0, terrain: plotTerrain(p)}))];
    const under = (point: Vec2): Level | undefined => all.find((o) => pointInPolygon(point, o.polygon));
    const height = (o: Level | undefined, point: Vec2): number =>
        o === undefined ? 0 : o.level + (o.terrain === null ? 0 : terrainAt(o.terrain, point.x, point.y));
    const levels: FloorLevels = (point, near) => {
        const spot = stairs.length === 0 ? null : stairSpot(stairs, point, near);
        return spot !== null ? spot.path.offset + spot.height : height(under(point), point);
    };
    if (plots.some(hasTerrain)) {
        // Between two points on sloping ground (the plot's, or a garden draped on it); a step onto or off a flat floor
        // is a step, which never blocks.
        levels.tooSteep = (from, to): boolean => {
            const a = under(from);
            const b = under(to);
            if (a?.terrain == null || b?.terrain == null || (stairs.length > 0 && stairSpot(stairs, to) !== null)) {
                return false;
            }
            const run = Math.hypot(to.x - from.x, to.y - from.y);
            return run > 0 && Math.abs(height(b, to) - height(a, from)) > MaxSlope * run;
        };
    }
    return levels;
}

/**
 * Without a mouse (phones, tablets) there is no pointer lock: a finger drags the view around and on-screen arrows
 * stand in for the keys (`Walkthrough.setVirtualKeys()`).
 */
export function walkthroughTouchMode(): boolean {
    return !('exitPointerLock' in document) || !matchMedia('(pointer: fine)').matches;
}

/**
 * First-person walk through the floor: WASD / arrows move (Shift runs), the mouse looks around while the
 * pointer is locked to the canvas. Positions are plan millimetres; the eye stays at eye height over the floor
 * underfoot (`floorLevels()`: a room's floor elevation, a plot's ground level). The walker collides with the walls and
 * with door leaves and sashes, whose colliders change as they swing (`setLeafColliders()`). E asks to open or
 * close the door in view (`onUse`), PageUp / PageDown asks for the floor above or below (`onFloor`; `moveTo()` puts the
 * walker there, keeping the pointer lock and the view's direction).
 * Esc ends it: the browser releases the pointer lock (the key itself never reaches the page while locked),
 * or, before the lock was acquired, the keydown arrives here. In touch mode (`walkthroughTouchMode()`) a finger
 * dragging the canvas looks around, and `end()` stands in for Esc.
 */
export class Walkthrough {
    /** The walk ended (Esc or the pointer lock was released). */
    public onExit: () => void = () => undefined;
    /** The pointer lock was acquired or lost (or refused: the walker has to click the view). */
    public onLockChange: (locked: boolean) => void = () => undefined;
    /** E was pressed: open or close the door in view. */
    public onUse: () => void = () => undefined;
    /** PageUp (+1) or PageDown (−1) was pressed: walk to the floor above or below. */
    public onFloor: (step: 1 | -1) => void = () => undefined;

    private readonly keys = new Set<string>();
    /** Keys held on the on-screen arrows. */
    private virtualKeys: ReadonlySet<string> = new Set<string>();
    private readonly document: Document;
    private readonly touch = walkthroughTouchMode();
    /** The finger dragging the view and where it was last. */
    private lookPointer: {id: number; x: number; y: number} | null = null;
    private position: Vec2;
    private yaw: number;
    private leafColliders: readonly WallCollider[] = [];
    /** The floor level under the walker, looked up again only once they move. */
    private underfoot: {x: number; y: number; level: number} | null = null;
    /** The height last stood at, which a stair passing over itself is read against. */
    private lastLevel = 0;
    private pitch = 0;
    private locked = false;
    private active = true;

    public constructor(
        private readonly camera: THREE.PerspectiveCamera,
        private readonly element: HTMLElement,
        private wallColliders: readonly WallCollider[],
        start: Vec2,
        yaw: number,
        private levels: FloorLevels = () => 0,
    ) {
        this.document = element.ownerDocument;
        this.position = collideCircle(start, BodyRadius, wallColliders);
        this.yaw = yaw;
        this.applyCamera();

        this.document.addEventListener('keydown', this.handleKeyDown);
        this.document.addEventListener('keyup', this.handleKeyUp);
        this.document.addEventListener('mousemove', this.handleMouseMove);
        this.document.addEventListener('pointerlockchange', this.handleLockChange);
        this.document.addEventListener('pointerlockerror', this.handleLockError);
        window.addEventListener('blur', this.handleBlur);
        this.element.addEventListener('pointerdown', this.handleTouchDown);
        this.element.addEventListener('pointermove', this.handleTouchMove);
        this.element.addEventListener('pointerup', this.handleTouchUp);
        this.element.addEventListener('pointercancel', this.handleTouchUp);
    }

    /** Captures the mouse; must run inside a user gesture (a click). Touch mode has nothing to capture. */
    public lock(): void {
        if (this.locked || this.touch) {
            return;
        }
        // Chrome refuses a lock within about a second after the last one was released.
        Promise.resolve(this.element.requestPointerLock()).catch(() => this.handleLockError());
    }

    public isLocked(): boolean {
        return this.locked;
    }

    /** Walls changed (a live update of the floor). */
    public setColliders(colliders: readonly WallCollider[]): void {
        this.wallColliders = colliders;
        this.collide();
    }

    /**
     * Rooms, plots or stairs changed (a live update of the floor, or another floor): the eye follows their levels.
     * `level` is the height the walker stands at against the new floor's level, when the caller knows it (a stair walked
     * onto another floor), so a stair passing over itself keeps them on their flight.
     */
    public setFloorLevels(levels: FloorLevels, level?: number): void {
        this.levels = levels;
        if (level !== undefined) {
            this.lastLevel = level;
        }
        this.underfoot = null;
        this.applyCamera();
    }

    /** Door leaves and sashes moved; one swinging into the walker pushes them aside. */
    public setLeafColliders(colliders: readonly WallCollider[]): void {
        this.leafColliders = colliders;
        this.collide();
    }

    /** Keys held on the on-screen arrows (codes as in `KeyboardEvent.code`), replacing the previous ones. */
    public setVirtualKeys(codes: readonly string[]): void {
        this.virtualKeys = new Set(codes);
    }

    /** Where the walker stands, plan mm. */
    public where(): Vec2 {
        return this.position;
    }

    /** The height the walker stands at, mm against the floor's level (a stair's step, a sunken terrace). */
    public level(): number {
        return this.lastLevel;
    }

    /**
     * Puts the walker at a plan point (another floor: set its colliders and levels first), facing `yaw` if given;
     * the pitch and the pointer lock stay.
     */
    public moveTo(position: Vec2, yaw?: number): void {
        this.position = collideCircle(position, BodyRadius, this.colliders());
        if (yaw !== undefined) {
            this.yaw = yaw;
        }
        this.underfoot = null;
        this.applyCamera();
    }

    /** Ends the walk as Esc does (notifies `onExit`). */
    public end(): void {
        this.exit();
    }

    /** Advances by `dt` seconds of held keys. */
    public update(dt: number): void {
        const held = new Set([...this.keys, ...this.virtualKeys]);
        const forward = axis(held, ForwardKeys, BackwardKeys);
        const strafe = axis(held, RightKeys, LeftKeys);
        if (forward === 0 && strafe === 0) {
            return;
        }

        const speed = held.has('ShiftLeft') || held.has('ShiftRight') ? RunSpeed : WalkSpeed;
        const length = Math.hypot(forward, strafe);
        const distance = speed * Math.min(dt, 0.1);
        // Plan directions of the view: forward (−sin yaw, cos yaw), right (cos yaw, sin yaw).
        const sin = Math.sin(this.yaw);
        const cos = Math.cos(this.yaw);
        const dx = ((forward * -sin + strafe * cos) / length) * distance;
        const dy = ((forward * cos + strafe * sin) / length) * distance;

        const colliders = this.colliders();
        const steps = Math.ceil(distance / MaxStep);
        for (let i = 0; i < steps; i++) {
            const next = collideCircle({x: this.position.x + dx / steps, y: this.position.y + dy / steps}, BodyRadius, colliders);
            if (this.levels.tooSteep?.(this.position, next) === true) {
                break;
            }
            this.position = next;
        }
        this.applyCamera();
    }

    /** Ends the walk without notifying `onExit`; releases the pointer lock. */
    public dispose(): void {
        this.active = false;
        this.document.removeEventListener('keydown', this.handleKeyDown);
        this.document.removeEventListener('keyup', this.handleKeyUp);
        this.document.removeEventListener('mousemove', this.handleMouseMove);
        this.document.removeEventListener('pointerlockchange', this.handleLockChange);
        this.document.removeEventListener('pointerlockerror', this.handleLockError);
        window.removeEventListener('blur', this.handleBlur);
        this.element.removeEventListener('pointerdown', this.handleTouchDown);
        this.element.removeEventListener('pointermove', this.handleTouchMove);
        this.element.removeEventListener('pointerup', this.handleTouchUp);
        this.element.removeEventListener('pointercancel', this.handleTouchUp);
        if (this.document.pointerLockElement === this.element) {
            this.document.exitPointerLock();
        }
    }

    private exit(): void {
        if (this.active) {
            this.active = false;
            this.onExit();
        }
    }

    private colliders(): readonly WallCollider[] {
        return this.leafColliders.length === 0 ? this.wallColliders : [...this.wallColliders, ...this.leafColliders];
    }

    private collide(): void {
        this.position = collideCircle(this.position, BodyRadius, this.colliders());
        this.applyCamera();
    }

    private applyCamera(): void {
        const {x, y} = this.position;
        if (this.underfoot === null || this.underfoot.x !== x || this.underfoot.y !== y) {
            this.underfoot = {x, y, level: this.levels(this.position, this.lastLevel)};
            this.lastLevel = this.underfoot.level;
        }
        this.camera.position.set(x * MM, (EyeHeight + this.underfoot.level) * MM, -y * MM);
        this.camera.quaternion.setFromEuler(new THREE.Euler(this.pitch, this.yaw, 0, 'YXZ'));
    }

    private readonly handleKeyDown = (event: KeyboardEvent): void => {
        if (event.key === 'Escape') {
            this.exit();
            return;
        }
        if (event.ctrlKey || event.metaKey || event.altKey) {
            return;
        }
        if (event.code === UseKey) {
            if (!event.repeat) {
                this.onUse();
            }
            return;
        }
        const step = FloorKeys[event.code];
        if (step !== undefined) {
            event.preventDefault();
            if (!event.repeat) {
                this.onFloor(step);
            }
            return;
        }
        this.keys.add(event.code);
        if (MoveKeys.has(event.code)) {
            event.preventDefault();
        }
    };

    private readonly handleKeyUp = (event: KeyboardEvent): void => {
        this.keys.delete(event.code);
    };

    private readonly handleMouseMove = (event: MouseEvent): void => {
        if (!this.locked) {
            return;
        }
        this.yaw -= event.movementX * LookSensitivity;
        this.pitch = THREE.MathUtils.clamp(this.pitch - event.movementY * LookSensitivity, -MaxPitch, MaxPitch);
        this.applyCamera();
    };

    private readonly handleTouchDown = (event: PointerEvent): void => {
        if (!this.touch || this.lookPointer !== null) {
            return;
        }
        this.lookPointer = {id: event.pointerId, x: event.clientX, y: event.clientY};
        this.element.setPointerCapture(event.pointerId);
    };

    private readonly handleTouchMove = (event: PointerEvent): void => {
        if (this.lookPointer?.id !== event.pointerId) {
            return;
        }
        this.yaw += (event.clientX - this.lookPointer.x) * TouchLookSensitivity;
        this.pitch = THREE.MathUtils.clamp(this.pitch + (event.clientY - this.lookPointer.y) * TouchLookSensitivity, -MaxPitch, MaxPitch);
        this.lookPointer = {id: event.pointerId, x: event.clientX, y: event.clientY};
        this.applyCamera();
    };

    private readonly handleTouchUp = (event: PointerEvent): void => {
        if (this.lookPointer?.id === event.pointerId) {
            this.lookPointer = null;
        }
    };

    private readonly handleLockChange = (): void => {
        const locked = this.document.pointerLockElement === this.element;
        if (locked === this.locked) {
            return;
        }
        this.locked = locked;
        this.onLockChange(locked);
        if (!locked) {
            // Released by Esc (or by the browser, e.g. switching tabs): back to the orbit view.
            this.exit();
        }
    };

    private readonly handleLockError = (): void => {
        this.onLockChange(false);
    };

    /** Keys released while the window has no focus never send keyup. */
    private readonly handleBlur = (): void => {
        this.keys.clear();
        this.virtualKeys = new Set<string>();
    };
}

/** +1, −1 or 0 depending on which of the two key groups is held. */
function axis(keys: ReadonlySet<string>, positive: readonly string[], negative: readonly string[]): number {
    return (positive.some((k) => keys.has(k)) ? 1 : 0) - (negative.some((k) => keys.has(k)) ? 1 : 0);
}
