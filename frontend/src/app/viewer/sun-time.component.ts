import {formatDate} from '@angular/common';
import {ChangeDetectionStrategy, Component, inject, LOCALE_ID, model} from '@angular/core';
import {SunTime} from '@app/models/scene';
import {SunDay} from '@app/viewer/engine/sun';
import {TranslatePipe} from '@ngx-translate/core';

/** A summer afternoon: where the sun time starts when switched on. */
const DefaultSunTime: SunTime = {month: 6, hour: 15};

/** The Display option placing the sun at a month and clock time on site; for scenes with a location. */
@Component({
    selector: 'fd-sun-time',
    imports: [TranslatePipe],
    template: `
        @let t = time();
        <label class="toggle">
            <input type="checkbox" [checked]="t !== null" (change)="time.set(t === null ? defaultTime : null)" />
            <span>{{ 'viewer.sun.atTime' | translate }}</span>
        </label>
        @if (t !== null) {
            <label class="slider">
                <span class="slider__label">
                    {{ 'viewer.sun.date' | translate }}
                    <span class="slider__value">{{ dayText(t.month, 'viewer.sun.dateFormat' | translate) }}</span>
                </span>
                <input
                    type="range"
                    min="1"
                    max="12"
                    step="1"
                    [value]="t.month"
                    (input)="setMonth($event)"
                    [attr.aria-label]="'viewer.sun.monthLabel' | translate"
                />
            </label>
            <label class="slider">
                <span class="slider__label">
                    {{ 'viewer.sun.time' | translate }}
                    <span class="slider__value">{{ clock(t.hour) }}</span>
                </span>
                <input
                    type="range"
                    min="0"
                    max="24"
                    step="0.25"
                    [value]="t.hour"
                    (input)="setHour($event)"
                    [attr.aria-label]="'viewer.sun.timeLabel' | translate"
                />
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
    private readonly localeId = inject(LOCALE_ID);

    /** The sun's day of the month (`SunDay`) in the language's own pattern (`viewer.sun.dateFormat`). */
    protected dayText(month: number, pattern: string): string {
        return formatDate(new Date(2001, month - 1, SunDay), pattern, this.localeId);
    }

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
