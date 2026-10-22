import * as THREE from 'three';
import {CSS2DObject} from 'three/examples/jsm/renderers/CSS2DRenderer.js';

import {MM} from './plan-geometry';

/** A point a review comment is pinned to: plan mm, z above the floor. */
export interface CommentPoint {
    x: number;
    y: number;
    z: number;
}

/** A pin drawn in the view: a thread's number at its point. */
export interface CommentPin extends CommentPoint {
    /** The thread's id; the draft of a new thread has none. */
    id: number | null;
    label: string;
    resolved: boolean;
}

/** Where a pin stands in the scene (metres; plan y is scene −z). */
export function pinPosition(point: CommentPoint): THREE.Vector3 {
    return new THREE.Vector3(point.x * MM, point.z * MM, -point.y * MM);
}

/** The plan point of a scene point (a click on a surface), rounded to whole mm. */
export function commentPointOf(position: THREE.Vector3): CommentPoint {
    return {x: Math.round(position.x / MM), y: Math.round(-position.z / MM), z: Math.max(0, Math.round(position.y / MM))};
}

/**
 * The pins of the review comments: numbered CSS2D badges whose tip sits on the commented point. A click on a pin reports
 * its thread (`onPick`); a pin above the wall cut is dimmed, as what it points at is cut away.
 */
export class CommentPins {
    public onPick: (id: number) => void = () => undefined;

    public readonly group = new THREE.Group();
    private pins: {object: CSS2DObject; z: number}[] = [];
    private visible = true;
    private sectionHeight: number | null = null;
    /** The z-index base the pins were last raised to. */
    private raised = -1;

    public constructor() {
        this.group.name = 'comments';
    }

    /** Replaces the pins; `activeId` is the thread shown in the panel. */
    public set(pins: readonly CommentPin[], activeId: number | null): void {
        this.clear();
        for (const pin of pins) {
            const element = document.createElement('button');
            element.type = 'button';
            element.className = 'comment-pin';
            element.textContent = pin.label;
            element.classList.toggle('comment-pin--draft', pin.id === null);
            element.classList.toggle('comment-pin--resolved', pin.resolved);
            element.classList.toggle('comment-pin--active', pin.id !== null && pin.id === activeId);
            element.classList.toggle('comment-pin--cut', this.sectionHeight !== null && pin.z > this.sectionHeight);
            const id = pin.id;
            if (id !== null) {
                element.title = `Comment ${pin.label}`;
                element.addEventListener('pointerdown', (event) => event.stopPropagation());
                element.addEventListener('click', (event) => {
                    event.stopPropagation();
                    this.onPick(id);
                });
            }
            const object = new CSS2DObject(element);
            // The badge stands on its tip.
            object.center.set(0.5, 1);
            object.position.copy(pinPosition(pin));
            object.visible = this.visible;
            this.group.add(object);
            this.pins.push({object, z: pin.z});
        }
        this.raised = -1;
    }

    public setVisible(visible: boolean): void {
        this.visible = visible;
        this.pins.forEach((pin) => (pin.object.visible = visible));
    }

    /** The wall cut (mm, null for none): a pin above it is dimmed, as what it points at is cut away. */
    public setSectionHeight(height: number | null): void {
        this.sectionHeight = height;
        this.pins.forEach((pin) => pin.object.element.classList.toggle('comment-pin--cut', height !== null && pin.z > height));
    }

    /** Keeps the pins above the other labels (the renderer stacks labels by distance from `base` down). */
    public raise(base: number): void {
        if (base === this.raised) {
            return;
        }
        this.raised = base;
        this.pins.forEach((pin, i) => (pin.object.element.style.zIndex = String(base + i + 1)));
    }

    public clear(): void {
        for (const pin of this.pins) {
            pin.object.element.remove();
            pin.object.removeFromParent();
        }
        this.pins = [];
    }
}
