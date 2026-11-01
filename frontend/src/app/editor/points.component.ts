import {ChangeDetectionStrategy, Component, computed, input, output} from '@angular/core';
import {PlanPoint} from '@app/models/scene';
import {IconComponent} from '@app/shared/icon/icon.component';

/**
 * The corners of an outline (a room, a plot, a void) or the points of a railing, plan mm: typed in place, removed one
 * by one down to `min`, and a new one added after the last (an outline's in the middle of its closing edge, a
 * railing's a step past its end). The top view drags an outline's corners too.
 */
@Component({
    selector: 'fd-points',
    imports: [IconComponent],
    template: `
        <div class="points">
            <span class="points__head"></span>
            <span class="points__head">X</span>
            <span class="points__head">Y</span>
            <span></span>
            @for (point of list(); track $index; let i = $index) {
                <span class="points__index">{{ i + 1 }}</span>
                @for (axis of axes; track axis) {
                    <input
                        class="input"
                        type="number"
                        step="10"
                        [attr.aria-label]="labels[axis] + ' of point ' + (i + 1)"
                        [value]="point[axis]"
                        [disabled]="disabled()"
                        (change)="set(i, axis, $event)"
                    />
                }
                <button
                    type="button"
                    class="icon-button"
                    title="Remove"
                    [attr.aria-label]="'Remove point ' + (i + 1)"
                    [disabled]="disabled() || list().length <= min()"
                    (click)="remove(i)"
                >
                    <fd-icon name="close" [size]="14" />
                </button>
            }
        </div>
        <button type="button" class="btn btn--ghost btn--small add" [disabled]="disabled() || list().length === 0" (click)="add()">
            <fd-icon name="plus" [size]="14" /> Add {{ closed() ? 'a corner' : 'a point' }}
        </button>
        <p class="hint">
            mm.
            @if (closed()) {
                In the top view drag the corners; drag a midpoint for a new one, double-click one to remove it.
            }
        </p>
    `,
    styles: `
        @use 'variables' as *;

        :host {
            display: flex;
            flex-direction: column;
            gap: $space-8;
        }

        .points {
            display: grid;
            grid-template-columns: 20px minmax(0, 1fr) minmax(0, 1fr) 28px;
            gap: 4px 6px;
            align-items: center;
        }

        .points__head,
        .points__index {
            font-size: 11px;
            color: $color-text-subtle;
            font-variant-numeric: tabular-nums;
        }

        .input {
            min-width: 0;
            padding: 4px 6px;
            font-size: $font-size-13;
        }

        .icon-button {
            width: 28px;
            height: 28px;
        }

        .add {
            align-self: flex-start;
            color: $color-accent;
        }

        .hint {
            margin: 0;
            font-size: 11px;
            color: $color-text-muted;
        }
    `,
    changeDetection: ChangeDetectionStrategy.OnPush,
})
export class PointsComponent {
    /** The field's value: `[[x, y], …]`. */
    public readonly points = input<unknown>([]);
    /** An outline (its last point joins the first) rather than an open run. */
    public readonly closed = input(true);
    /** The fewest points it may keep. */
    public readonly min = input(3);
    public readonly disabled = input(false);
    public readonly pointsChange = output<PlanPoint[]>();

    protected readonly axes = [0, 1] as const;
    protected readonly labels = ['X', 'Y'] as const;
    protected readonly list = computed((): PlanPoint[] => (Array.isArray(this.points()) ? (this.points() as PlanPoint[]) : []));

    protected set(index: number, axis: 0 | 1, event: Event): void {
        const field = event.target as HTMLInputElement;
        const value = Number(field.value);
        if (field.value.trim() === '' || !Number.isInteger(value)) {
            field.value = String(this.list()[index][axis]);
            return;
        }
        this.pointsChange.emit(this.list().map((p, i): PlanPoint => (i !== index ? p : axis === 0 ? [value, p[1]] : [p[0], value])));
    }

    protected remove(index: number): void {
        this.pointsChange.emit(this.list().filter((_, i) => i !== index));
    }

    /** An outline's new corner halves its closing edge; a run's new point continues its last stretch (a metre past one point). */
    protected add(): void {
        const points = this.list();
        const last = points[points.length - 1];
        const before = points.length > 1 ? points[points.length - 2] : [last[0] - 1000, last[1]];
        const next: PlanPoint = this.closed()
            ? [Math.round((last[0] + points[0][0]) / 2), Math.round((last[1] + points[0][1]) / 2)]
            : [2 * last[0] - before[0], 2 * last[1] - before[1]];
        this.pointsChange.emit([...points, next]);
    }
}
