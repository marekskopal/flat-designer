import {Scene} from '@app/models/scene';
import * as THREE from 'three';

import {collideCircle, MM, pointInPolygon, polygonSignedArea, Vec2, WallCollider} from './plan-geometry';

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

/** Where the walker stands on the floor's level, mm: plan point → height of the floor under it. */
export type FloorLevels = (point: Vec2) => number;

/**
 * The height of what is underfoot at a plan point, mm against the floor's level: the floor of the room there (the
 * smallest one where rooms overlap: a terrace inside a garden), else the ground of the plot there, else the floor's
 * level. Steps do not block the walk. Keeps only the outlines and levels, not the scene.
 */
export function floorLevels(scene: Pick<Scene, 'rooms' | 'plots'>): FloorLevels {
    const rooms = scene.rooms
        .map((r) => ({polygon: r.polygon, level: r.floorElevation ?? 0, area: Math.abs(polygonSignedArea(r.polygon))}))
        .sort((a, b) => a.area - b.area);
    const plots = (scene.plots ?? []).map((p) => ({polygon: p.polygon, level: p.groundLevel ?? 0}));
    return (point) => [...rooms, ...plots].find((o) => pointInPolygon(point, o.polygon))?.level ?? 0;
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

    /** Rooms or plots changed (a live update of the floor): the eye follows their levels. */
    public setFloorLevels(levels: FloorLevels): void {
        this.levels = levels;
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
            this.position = collideCircle({x: this.position.x + dx / steps, y: this.position.y + dy / steps}, BodyRadius, colliders);
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
            this.underfoot = {x, y, level: this.levels(this.position)};
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
