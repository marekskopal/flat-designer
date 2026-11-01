import {Wall} from '@app/models/scene';
import * as THREE from 'three';

import {leafOf, setLeafPose} from './building';
import {hasLeaves, isDoor} from './plan-geometry';

/** Seconds a leaf takes to swing or slide fully open or closed. */
const SwingDuration = 0.45;

interface LeafState {
    /** 0 closed … 1 open. */
    current: number;
    target: number;
    /** Shown outside the walkthrough. */
    display: number;
}

/**
 * How far each door with a leaf and walkable French window is open (session only, never stored), animated
 * towards its target. The display state, shown outside the walkthrough, is doors open and French windows and garage
 * doors closed.
 */
export class LeafStates {
    private readonly states = new Map<number, LeafState>();

    /** Every opening of the walls back to its display state; `animate` swings the leaves there. */
    public reset(walls: readonly Wall[], animate = false): void {
        this.sync(walls);
        this.states.forEach((state) => {
            state.target = state.display;
            if (!animate) {
                state.current = state.display;
            }
        });
    }

    /** Adds openings that appeared (at their display state) and drops removed ones; the others keep their state. */
    public sync(walls: readonly Wall[]): void {
        const present = new Set<number>();
        for (const opening of walls.flatMap((w) => w.openings).filter(hasLeaves)) {
            present.add(opening.id);
            if (!this.states.has(opening.id)) {
                const display = isDoor(opening) && opening.type !== 'GarageDoor' ? 1 : 0;
                this.states.set(opening.id, {current: display, target: display, display});
            }
        }
        for (const id of this.states.keys()) {
            if (!present.has(id)) {
                this.states.delete(id);
            }
        }
    }

    /** Whether the opening opens at all (a door with a leaf or a walkable French window). */
    public has(openingId: number): boolean {
        return this.states.has(openingId);
    }

    /** 0 closed … 1 open; 0 for openings without leaves. */
    public openness(openingId: number): number {
        return this.states.get(openingId)?.current ?? 0;
    }

    /** How far the leaves are swung or slid, 0 … 1: `openness` eased, for the meshes and colliders alike. */
    public swing(openingId: number): number {
        return easeSwing(this.openness(openingId));
    }

    /** Opening or open (the way it is heading). */
    public isOpen(openingId: number): boolean {
        return (this.states.get(openingId)?.target ?? 0) > 0.5;
    }

    /** Sets where an opening heads; without `animate` it gets there at once. */
    public setOpen(openingId: number, open: boolean, animate = true): void {
        const state = this.states.get(openingId);
        if (state !== undefined) {
            state.target = open ? 1 : 0;
            if (!animate) {
                state.current = state.target;
            }
        }
    }

    /** Closes everything at once. */
    public closeAll(): void {
        this.states.forEach((state) => (state.current = state.target = 0));
    }

    /** Reverses an opening's way (also mid-swing); returns whether it is now opening. */
    public toggle(openingId: number): boolean {
        const open = !this.isOpen(openingId);
        this.setOpen(openingId, open);
        return open;
    }

    /** Advances the swings by `dt` seconds; true when any leaf moved. */
    public update(dt: number): boolean {
        const step = Math.min(dt, 0.1) / SwingDuration;
        let moved = false;
        this.states.forEach((state) => {
            if (state.current !== state.target) {
                const delta = state.target - state.current;
                state.current = Math.abs(delta) <= step ? state.target : state.current + Math.sign(delta) * step;
                moved = true;
            }
        });
        return moved;
    }

    /** Moves the leaves (objects tagged by `buildWall` with `userData.leaf`) to their openings' state, eased. */
    public apply(leaves: readonly THREE.Object3D[]): void {
        for (const pivot of leaves) {
            setLeafPose(pivot, this.swing(leafOf(pivot).openingId));
        }
    }
}

/** Leaf objects under `root`, for `LeafStates.apply()`. */
export function leafObjects(root: THREE.Object3D): THREE.Object3D[] {
    const leaves: THREE.Object3D[] = [];
    root.traverse((object) => {
        if ((object.userData as Record<string, unknown>)['leaf'] !== undefined) {
            leaves.push(object);
        }
    });
    return leaves;
}

/** Smooth start and stop of a swing. */
function easeSwing(t: number): number {
    return t * t * (3 - 2 * t);
}
