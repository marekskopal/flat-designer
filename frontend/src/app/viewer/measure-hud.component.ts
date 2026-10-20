import {DecimalPipe} from '@angular/common';
import {ChangeDetectionStrategy, Component, input} from '@angular/core';

import {MeasureStatus} from './engine/measure';

/** Readout of the measure tool over the viewer: the distance between the points and its east / north parts. */
@Component({
    selector: 'fd-measure-hud',
    imports: [DecimalPipe],
    template: `
        @let s = status();
        <div class="hud" role="status">
            @if (s.points === 0) {
                <strong class="hud__prompt">
                    {{ calibrating() ? 'Click the first calibration point on the plan image' : 'Click the first point to measure from' }}
                </strong>
            } @else {
                @if (s.distance !== null) {
                    <span class="hud__value">
                        {{ s.distance | number: '1.0-0' }} mm
                        <span class="hud__parts">Δx {{ s.dx | number: '1.0-0' }} · Δy {{ s.dy | number: '1.0-0' }}</span>
                    </span>
                }
                @if (s.points === 1) {
                    <strong class="hud__prompt">Click the second point</strong>
                }
            }
            <span class="hud__keys">
                @if (calibrating()) {
                    No snapping · <kbd>Esc</kbd> cancels the calibration
                } @else {
                    Snaps to wall ends, corners and faces · <kbd>Shift</kbd> no snapping · <kbd>Esc</kbd> clears, again ends
                }
            </span>
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

        .hud__value {
            font-weight: 600;
            font-variant-numeric: tabular-nums;
        }

        .hud__parts {
            margin-left: $space-8;
            font-weight: 400;
            color: $color-text-muted;
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
    changeDetection: ChangeDetectionStrategy.OnPush,
})
export class MeasureHudComponent {
    /** The points calibrate the plan underlay (no snapping). */
    public readonly calibrating = input(false);
    public readonly status = input.required<MeasureStatus>();
}
