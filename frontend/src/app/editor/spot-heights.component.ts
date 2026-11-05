import {ChangeDetectionStrategy, Component, input, output} from '@angular/core';
import {SpotHeight} from '@app/models/scene';
import {TranslatePipe} from '@ngx-translate/core';

/**
 * The spot heights of a plot's sloping ground: x, y and the height of each, typed in place, and removed one by
 * one. New ones are added by double-clicking the plot's ground in the top view, where they are also dragged.
 */
@Component({
    selector: 'fd-spot-heights',
    imports: [TranslatePipe],
    template: `
        @if (spots().length === 0) {
            <p class="spots__empty">{{ 'inspector.spots.empty' | translate }}</p>
        } @else {
            <div class="spots">
                <span class="spots__head">X</span>
                <span class="spots__head">Y</span>
                <span class="spots__head">{{ 'fields.height' | translate }}</span>
                <span></span>
                @for (spot of spots(); track $index; let i = $index) {
                    @for (axis of axes; track axis) {
                        <input
                            class="input input--compact"
                            type="number"
                            step="1"
                            [attr.aria-label]="'inspector.spots.coordinate' | translate: {axis: (labels[axis] | translate), n: i + 1}"
                            [value]="spot[axis]"
                            [disabled]="disabled()"
                            (change)="set(i, axis, $event)"
                        />
                    }
                    <button
                        type="button"
                        class="btn btn--ghost spots__remove"
                        [attr.aria-label]="'inspector.spots.remove' | translate: {n: i + 1}"
                        [disabled]="disabled()"
                        (click)="remove(i)"
                    >
                        ×
                    </button>
                }
            </div>
            <p class="spots__hint">{{ 'inspector.spots.hint' | translate }}</p>
        }
    `,
    styleUrl: './spot-heights.component.scss',
    changeDetection: ChangeDetectionStrategy.OnPush,
})
export class SpotHeightsComponent {
    public readonly spots = input<SpotHeight[]>([]);
    public readonly disabled = input(false);
    public readonly spotsChange = output<SpotHeight[]>();

    protected readonly axes = [0, 1, 2] as const;
    /** The columns' labels: translation keys (X and Y read alike in every language). */
    protected readonly labels = ['fields.x', 'fields.y', 'fields.height'] as const;

    protected set(index: number, axis: 0 | 1 | 2, event: Event): void {
        const field = event.target as HTMLInputElement;
        const value = Number(field.value);
        if (field.value.trim() === '' || !Number.isInteger(value)) {
            field.value = String(this.spots()[index][axis]);
            return;
        }
        const changed = (spot: SpotHeight): SpotHeight => spot.map((v, a) => (a === axis ? value : v)) as SpotHeight;
        this.spotsChange.emit(this.spots().map((spot, i) => (i === index ? changed(spot) : spot)));
    }

    protected remove(index: number): void {
        this.spotsChange.emit(this.spots().filter((_, i) => i !== index));
    }
}
