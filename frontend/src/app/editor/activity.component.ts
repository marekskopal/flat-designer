import {DatePipe} from '@angular/common';
import {ChangeDetectionStrategy, Component, input} from '@angular/core';
import {Change} from '@app/models/editor';

/** Recent changes of the project: who (a person or an agent) changed what. */
@Component({
    selector: 'fd-activity',
    imports: [DatePipe],
    template: `
        <ul class="activity">
            @for (change of changes(); track change.id) {
                <li class="activity__row" [class.activity__row--undone]="change.undone">
                    <span class="activity__summary">{{ change.summary }}</span>
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
}
