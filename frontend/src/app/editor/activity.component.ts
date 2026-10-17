import {DatePipe} from '@angular/common';
import {ChangeDetectionStrategy, Component, computed, input} from '@angular/core';
import {Change} from '@app/models/editor';

/** One row of the list: a change, or a batch shown as its first change (which names the operation). */
interface ActivityRow {
    change: Change;
    /** Further changes of the batch (e.g. the items of a moved group). */
    more: number;
}

/** Recent changes of the project: who (a person or an agent) changed what. */
@Component({
    selector: 'fd-activity',
    imports: [DatePipe],
    template: `
        <ul class="activity">
            @for (row of rows(); track row.change.id) {
                @let change = row.change;
                <li class="activity__row" [class.activity__row--undone]="change.undone">
                    <span class="activity__summary">
                        {{ change.summary }}
                        @if (row.more > 0) {
                            <span class="activity__more">+{{ row.more }}</span>
                        }
                    </span>
                    <span class="activity__meta">
                        @if (change.actorType === 'Agent') {
                            <span class="activity__agent">{{ change.actorName }}</span>
                        } @else {
                            {{ change.actorName }}
                        }
                        · {{ change.createdAt | date: 'HH:mm' }}
                        @if (change.undone) {
                            · undone
                        }
                    </span>
                </li>
            } @empty {
                <li class="activity__empty">No changes yet.</li>
            }
        </ul>
    `,
    styles: `
        @use 'variables' as *;

        .activity {
            margin: 0;
            padding: 0;
            list-style: none;
        }

        .activity__row {
            display: flex;
            flex-direction: column;
            padding: 5px 0;
            border-bottom: 1px solid $color-border;

            &:last-child {
                border-bottom: 0;
            }
        }

        .activity__row--undone .activity__summary {
            text-decoration: line-through;
            color: $color-text-subtle;
        }

        .activity__summary {
            font-size: $font-size-12;
        }

        .activity__more {
            color: $color-text-subtle;
        }

        .activity__meta,
        .activity__empty {
            font-size: 11px;
            color: $color-text-subtle;
        }

        .activity__agent {
            padding: 0 5px;
            border-radius: 999px;
            background: $color-accent-soft;
            font-weight: 600;
            color: $color-accent;
        }
    `,
    changeDetection: ChangeDetectionStrategy.OnPush,
})
export class ActivityComponent {
    public readonly changes = input<Change[]>([]);

    /** The changes newest first, each batch collapsed into one row at its newest position. */
    protected readonly rows = computed((): ActivityRow[] => {
        const rows: ActivityRow[] = [];
        const batches = new Map<string, ActivityRow>();
        for (const change of this.changes()) {
            const row = change.batch === null ? undefined : batches.get(change.batch);
            if (row === undefined) {
                const added = {change, more: 0};
                rows.push(added);
                if (change.batch !== null) {
                    batches.set(change.batch, added);
                }
                continue;
            }
            row.more++;
            // The batch's first change (lowest id) carries the operation's summary.
            if (change.id < row.change.id) {
                row.change = change;
            }
        }
        return rows;
    });
}
