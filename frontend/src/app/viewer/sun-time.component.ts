import {ChangeDetectionStrategy, Component, model} from '@angular/core';
import {SunTime} from '@app/models/scene';
import {SunDay} from '@app/viewer/engine/sun';

/** A summer afternoon: where the sun time starts when switched on. */
const DefaultSunTime: SunTime = {month: 6, hour: 15};
const MonthNames = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];

/** The Display option placing the sun at a month and clock time on site; for scenes with a location. */
@Component({
    selector: 'fd-sun-time',
    template: `
        @let t = time();
        <label class="toggle">
            <input type="checkbox" [checked]="t !== null" (change)="time.set(t === null ? defaultTime : null)" />
            <span>Sun at a time</span>
        </label>
        @if (t !== null) {
            <label class="slider">
                <span class="slider__label">
                    Date
                    <span class="slider__value">{{ day }} {{ monthNames[t.month - 1] }}</span>
                </span>
                <input type="range" min="1" max="12" step="1" [value]="t.month" (input)="setMonth($event)" aria-label="Month of the sun" />
            </label>
            <label class="slider">
                <span class="slider__label">
                    Time
                    <span class="slider__value">{{ clock(t.hour) }}</span>
                </span>
                <input type="range" min="0" max="24" step="0.25" [value]="t.hour" (input)="setHour($event)" aria-label="Time of the sun" />
            </label>
        }
    `,
    styles: `
        @use 'variables' as *;

        :host {
            display: block;
        }

        .toggle {
            display: flex;
            align-items: center;
            gap: $space-8;
            padding: 3px 0;
            font-size: $font-size-13;
            cursor: pointer;

            input {
                accent-color: $color-accent;
            }
        }

        .slider {
            display: flex;
            flex-direction: column;
            gap: $space-4;
            margin: $space-4 0 $space-8;
            font-size: $font-size-13;

            input {
                width: 100%;
                accent-color: $color-accent;
            }
        }

        .slider__label {
            display: flex;
            justify-content: space-between;
        }

        .slider__value {
            color: $color-text-muted;
            font-variant-numeric: tabular-nums;
        }
    `,
    changeDetection: ChangeDetectionStrategy.OnPush,
})
export class SunTimeComponent {
    /** Null: the default afternoon sun. */
    public readonly time = model<SunTime | null>(null);

    protected readonly defaultTime = DefaultSunTime;
    protected readonly monthNames = MonthNames;
    protected readonly day = SunDay;

    protected clock(hour: number): string {
        const minutes = Math.round(hour * 60);
        return `${Math.floor(minutes / 60)}:${String(minutes % 60).padStart(2, '0')}`;
    }

    protected setMonth(event: Event): void {
        this.time.update((t) => ({hour: t?.hour ?? DefaultSunTime.hour, month: Number((event.target as HTMLInputElement).value)}));
    }

    protected setHour(event: Event): void {
        this.time.update((t) => ({month: t?.month ?? DefaultSunTime.month, hour: Number((event.target as HTMLInputElement).value)}));
    }
}
