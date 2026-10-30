import {DecimalPipe} from '@angular/common';
import {ChangeDetectionStrategy, Component, ElementRef, input, output, signal, viewChild} from '@angular/core';
import {parseDrawInput, ToolStatus} from '@app/viewer/engine/drawing';
import {RoomDetectError} from '@app/viewer/engine/room-detect';

/**
 * A typed segment: `length` mm, at `angle` degrees counter-clockwise from east (null: towards the pointer), curved by
 * `sagitta` mm (+ to the left; null: as the arc mode has it).
 */
export interface TypedSegment {
    length: number;
    angle: number | null;
    sagitta: number | null;
}

const DetectErrors: Record<RoomDetectError, string> = {
    onWall: 'Inside a wall: click inside a room',
    open: 'The walls around here do not close',
    invalid: 'The walls around here do not make a usable outline',
};

/**
 * Readout of the editor's drawing tools over the viewer: the length and direction of the wall (or railing) being
 * drawn (with an input for exact lengths, focused by typing a digit) and the arc mode, in which a placed wall waits for
 * its curve (typed or clicked), or the area of the room a click would add.
 */
@Component({
    selector: 'fd-draw-hud',
    imports: [DecimalPipe],
    template: `
        @let s = status();
        <div class="hud" role="status">
            @if (s.tool === 'drawWalls') {
                <button
                    type="button"
                    class="hud__arc"
                    [class.hud__arc--on]="s.arc"
                    [attr.aria-pressed]="s.arc"
                    title="Curved walls: each wall waits for its curve (A)"
                    (click)="arc.emit(!s.arc)"
                >
                    Arc <kbd>A</kbd>
                </button>
                @if (s.sagitta !== null) {
                    <span class="hud__value">Curve {{ s.sagitta | number: '1.0-0' }} mm</span>
                    <strong class="hud__prompt">Move the middle of the wall out, click to fix it, or type its curve</strong>
                    <input
                        #lengthInput
                        class="input input--compact hud__input"
                        [class.hud__input--invalid]="invalid()"
                        type="text"
                        inputmode="numeric"
                        placeholder="curve, mm (+ left)"
                        aria-label="Curve of the wall: how far its middle lies off the straight line, mm, + to the left"
                        [value]="typed()"
                        (input)="onInput($event)"
                        (keydown.enter)="placeCurve()"
                        (keydown.escape)="clear()"
                    />
                } @else if (s.points === 0) {
                    <strong class="hud__prompt">Click where the wall starts</strong>
                } @else {
                    @if (s.length !== null) {
                        <span class="hud__value">
                            {{ s.length | number: '1.0-0' }} mm
                            @if (s.angle !== null) {
                                · {{ s.angle | number: '1.0-1' }}°
                            }
                        </span>
                    } @else {
                        <strong class="hud__prompt">Point to the next corner, or type a length</strong>
                    }
                    <input
                        #lengthInput
                        class="input input--compact hud__input"
                        [class.hud__input--invalid]="invalid()"
                        type="text"
                        inputmode="decimal"
                        placeholder="length<angle~curve"
                        aria-label="Length of the next wall, mm (optionally <angle in degrees and ~curve in mm)"
                        [value]="typed()"
                        (input)="onInput($event)"
                        (keydown.enter)="place()"
                        (keydown.escape)="clear()"
                    />
                }
                <span class="hud__keys">
                    Click the first point to close · double-click or <kbd>Enter</kbd> ends · <kbd>Backspace</kbd> undoes a point ·
                    <kbd>A</kbd> arcs · <kbd>Shift</kbd> no snapping · <kbd>Esc</kbd> cancels
                </span>
            } @else if (s.tool === 'drawRailing') {
                @if (s.points === 0) {
                    <strong class="hud__prompt">Click where the railing starts</strong>
                } @else {
                    @if (s.length !== null) {
                        <span class="hud__value">
                            {{ s.length | number: '1.0-0' }} mm
                            @if (s.angle !== null) {
                                · {{ s.angle | number: '1.0-1' }}°
                            }
                        </span>
                    } @else {
                        <strong class="hud__prompt">Point to the next post, or type a length</strong>
                    }
                    <input
                        #lengthInput
                        class="input input--compact hud__input"
                        [class.hud__input--invalid]="invalid()"
                        type="text"
                        inputmode="decimal"
                        placeholder="length<angle"
                        aria-label="Length of the next stretch of railing, mm (optionally <angle in degrees)"
                        [value]="typed()"
                        (input)="onInput($event)"
                        (keydown.enter)="place()"
                        (keydown.escape)="clear()"
                    />
                }
                <span class="hud__keys">
                    Click the first point to close · double-click or <kbd>Enter</kbd> ends · <kbd>Backspace</kbd> undoes a point ·
                    <kbd>Shift</kbd> no snapping · <kbd>Esc</kbd> cancels
                </span>
            } @else {
                @if (s.error !== null) {
                    <strong class="hud__prompt hud__prompt--error">{{ errorText(s.error) }}</strong>
                } @else if (s.area !== null) {
                    <strong class="hud__prompt">Click to add this room · {{ s.area | number: '1.1-2' }} m²</strong>
                } @else {
                    <strong class="hud__prompt">Point inside walls that enclose a room</strong>
                }
                <span class="hud__keys"><kbd>Esc</kbd> back to selecting</span>
            }
        </div>
    `,
    styles: `
        @use 'variables' as *;

        .hud {
            position: absolute;
            left: 50%;
            bottom: calc(#{$space-16} + env(safe-area-inset-bottom));
            transform: translateX(-50%);
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: $space-4;
            max-width: calc(100% - 2 * #{$space-16});
            padding: $space-8 $space-16;
            background: $color-surface;
            backdrop-filter: blur(14px);
            border: 1px solid $color-border;
            border-radius: $radius-10;
            box-shadow: $shadow-panel;
            font-size: $font-size-12;
            color: $color-text-muted;
            text-align: center;
        }

        .hud__prompt,
        .hud__value {
            font-size: $font-size-13;
            color: $color-text;
        }

        .hud__prompt--error {
            color: $color-danger;
        }

        .hud__arc {
            padding: 2px 8px;
            border: 1px solid $color-border-strong;
            border-radius: $radius-6;
            background: $color-surface-solid;
            font-size: $font-size-12;
            cursor: pointer;
        }

        .hud__arc--on {
            border-color: $color-accent;
            background: $color-accent-soft;
            color: $color-accent;
        }

        .hud__input {
            width: 180px;
            text-align: center;
        }

        .hud__input--invalid {
            border-color: $color-danger;
        }

        kbd {
            display: inline-block;
            min-width: 18px;
            margin: 0 1px;
            padding: 0 4px;
            border: 1px solid $color-border;
            border-radius: 4px;
            font: inherit;
        }
    `,
    host: {'(document:keydown)': 'onKeydown($event)'},
    changeDetection: ChangeDetectionStrategy.OnPush,
})
export class DrawHudComponent {
    public readonly status = input.required<ToolStatus>();
    /** An exact segment was typed. */
    public readonly segment = output<TypedSegment>();
    /** The arc mode was switched. */
    public readonly arc = output<boolean>();
    /** The curve of the wall waiting for it was typed: its sagitta, mm. */
    public readonly bend = output<number>();

    protected readonly typed = signal('');
    protected readonly invalid = signal(false);

    private readonly lengthInput = viewChild<ElementRef<HTMLInputElement>>('lengthInput');

    protected errorText(error: RoomDetectError): string {
        return DetectErrors[error];
    }

    protected onInput(event: Event): void {
        this.typed.set((event.target as HTMLInputElement).value);
        this.invalid.set(false);
    }

    protected place(): void {
        const parsed = parseDrawInput(this.typed());
        if (parsed === null) {
            this.invalid.set(true);
            return;
        }
        this.segment.emit(parsed);
        this.clear();
    }

    protected placeCurve(): void {
        const text = this.typed().trim();
        if (!/^-?\d+$/.test(text)) {
            this.invalid.set(true);
            return;
        }
        this.bend.emit(Number(text));
        this.clear();
    }

    protected clear(): void {
        this.typed.set('');
        this.invalid.set(false);
        this.lengthInput()?.nativeElement.blur();
    }

    /** Typing a digit while drawing starts an exact length (or a curve, which may start with its minus). */
    protected onKeydown(event: KeyboardEvent): void {
        const field = this.lengthInput()?.nativeElement;
        const target = event.target as HTMLElement | null;
        const s = this.status();
        const starts = s.tool === 'drawWalls' && s.sagitta !== null ? /^[\d-]$/ : /^\d$/;
        if (field === undefined || target === field || target?.closest('input, textarea, select') != null || !starts.test(event.key)) {
            return;
        }
        event.preventDefault();
        this.typed.set(event.key);
        field.value = event.key;
        field.focus();
    }
}
