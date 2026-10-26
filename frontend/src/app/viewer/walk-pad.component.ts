import {ChangeDetectionStrategy, Component, input, signal} from '@angular/core';

import {ViewerComponent} from './viewer.component';

type Direction = 'up' | 'down' | 'left' | 'right';

/** The keys each arrow stands for. */
const DirectionKeys: Record<Direction, string> = {up: 'KeyW', down: 'KeyS', left: 'KeyA', right: 'KeyD'};
/** A thumb closer to the pad's centre than this (px) holds nothing. */
const DeadZone = 12;
/** Components of the unit vector from the centre above this hold the arrow: eight 45° sectors, diagonals hold two. */
const SectorThreshold = Math.cos((67.5 * Math.PI) / 180);

/**
 * On-screen controls of a walk on a touch screen: arrows in the corner hold W/A/S/D while the thumb rests on them
 * (sliding between them changes direction, the corners between two arrows walk diagonally), a bar on top says
 * how to look around, takes the walker to the floor above or below (as PageUp / PageDown) and ends the walk in place
 * of Esc.
 */
@Component({
    selector: 'fd-walk-pad',
    template: `
        <div class="bar" role="status">
            <span>Drag the view to look around · tap a door to open it</span>
            @if (floorUp(); as name) {
                <button type="button" class="bar__floor" [attr.aria-label]="'Up to ' + name" (click)="viewer().walkToFloor(1)">
                    ↑ {{ name }}
                </button>
            }
            @if (floorDown(); as name) {
                <button type="button" class="bar__floor" [attr.aria-label]="'Down to ' + name" (click)="viewer().walkToFloor(-1)">
                    ↓ {{ name }}
                </button>
            }
            <button type="button" class="bar__exit" (click)="viewer().endWalkthrough()">Back to 3D</button>
        </div>
        <div
            class="pad"
            aria-label="Walk"
            (pointerdown)="onPointerDown($event)"
            (pointermove)="onPointerMove($event)"
            (pointerup)="release($event)"
            (pointercancel)="release($event)"
            (contextmenu)="$event.preventDefault()"
        >
            @for (direction of directions; track direction) {
                <span class="arrow arrow--{{ direction }}" [class.arrow--active]="held().includes(direction)">
                    <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M6 15l6-6 6 6" /></svg>
                </span>
            }
        </div>
    `,
    styles: `
        @use 'variables' as *;

        .bar {
            position: absolute;
            top: calc(#{$space-12} + env(safe-area-inset-top));
            left: 50%;
            transform: translateX(-50%);
            display: flex;
            align-items: center;
            gap: $space-12;
            max-width: calc(100% - 2 * #{$space-16});
            padding: $space-4 $space-4 $space-4 $space-12;
            background: $color-surface;
            backdrop-filter: blur(14px);
            border: 1px solid $color-border;
            border-radius: 999px;
            box-shadow: $shadow-panel;
            font-size: $font-size-12;
            color: $color-text-muted;
            white-space: nowrap;
        }

        .bar__floor {
            padding: 6px 12px;
            border: 1px solid $color-border;
            border-radius: 999px;
            background: transparent;
            font-size: $font-size-12;
            font-weight: 600;
            color: $color-text;
        }

        .bar__exit {
            padding: 6px 12px;
            border: 0;
            border-radius: 999px;
            background: $color-accent;
            font-size: $font-size-12;
            font-weight: 600;
            color: #fff;
        }

        .pad {
            position: absolute;
            left: calc(#{$space-16} + env(safe-area-inset-left));
            bottom: calc(#{$space-16} + env(safe-area-inset-bottom));
            width: 152px;
            height: 152px;
            border: 1px solid $color-border;
            border-radius: 50%;
            background: $color-surface;
            backdrop-filter: blur(14px);
            box-shadow: $shadow-panel;
            touch-action: none;
            user-select: none;
            -webkit-user-select: none;
            -webkit-touch-callout: none;
        }

        .arrow {
            position: absolute;
            display: grid;
            place-items: center;
            width: 48px;
            height: 48px;
            margin: -24px 0 0 -24px;
            border-radius: 50%;
            color: $color-text-muted;
            pointer-events: none;

            svg {
                width: 28px;
                height: 28px;
                fill: none;
                stroke: currentColor;
                stroke-width: 2.5;
                stroke-linecap: round;
                stroke-linejoin: round;
            }
        }

        .arrow--active {
            background: $color-accent;
            color: #fff;
        }

        .arrow--up {
            left: 50%;
            top: 22%;
        }

        .arrow--down {
            left: 50%;
            top: 78%;

            svg {
                transform: rotate(180deg);
            }
        }

        .arrow--left {
            left: 22%;
            top: 50%;

            svg {
                transform: rotate(-90deg);
            }
        }

        .arrow--right {
            left: 78%;
            top: 50%;

            svg {
                transform: rotate(90deg);
            }
        }
    `,
    changeDetection: ChangeDetectionStrategy.OnPush,
})
export class WalkPadComponent {
    public readonly viewer = input.required<ViewerComponent>();
    /** The names of the floors above and below, where the walker can go; null where there is none. */
    public readonly floorUp = input<string | null>(null);
    public readonly floorDown = input<string | null>(null);

    protected readonly directions: Direction[] = ['up', 'left', 'right', 'down'];
    protected readonly held = signal<Direction[]>([]);
    private pointerId: number | null = null;

    protected onPointerDown(event: PointerEvent): void {
        if (this.pointerId !== null) {
            return;
        }
        event.preventDefault();
        this.pointerId = event.pointerId;
        (event.currentTarget as HTMLElement).setPointerCapture(event.pointerId);
        this.hold(event);
    }

    protected onPointerMove(event: PointerEvent): void {
        if (event.pointerId === this.pointerId) {
            this.hold(event);
        }
    }

    protected release(event: PointerEvent): void {
        if (event.pointerId === this.pointerId) {
            this.pointerId = null;
            this.setHeld([]);
        }
    }

    /** Holds the arrows towards the thumb, seen from the pad's centre. */
    private hold(event: PointerEvent): void {
        const rect = (event.currentTarget as HTMLElement).getBoundingClientRect();
        const dx = event.clientX - (rect.left + rect.width / 2);
        const dy = event.clientY - (rect.top + rect.height / 2);
        const length = Math.hypot(dx, dy);
        if (length < DeadZone) {
            this.setHeld([]);
            return;
        }
        const held: Direction[] = [];
        if (dy / length < -SectorThreshold) {
            held.push('up');
        } else if (dy / length > SectorThreshold) {
            held.push('down');
        }
        if (dx / length < -SectorThreshold) {
            held.push('left');
        } else if (dx / length > SectorThreshold) {
            held.push('right');
        }
        this.setHeld(held);
    }

    private setHeld(held: Direction[]): void {
        this.held.set(held);
        this.viewer().setWalkKeys(held.map((direction) => DirectionKeys[direction]));
    }
}
