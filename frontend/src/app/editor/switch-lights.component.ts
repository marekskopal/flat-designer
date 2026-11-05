import {ChangeDetectionStrategy, Component, input, output} from '@angular/core';
import {IconComponent} from '@app/shared/icon/icon.component';

/** A light listed under its switch. */
export interface SwitchLight {
    id: number;
    name: string;
    /** The code of its room, null outside every room. */
    roomCode: string | null;
}

/**
 * The lights a selected switch controls, in the inspector: each can be unlinked or selected, and "Pick lights" starts
 * picking them in the view (a click on a light links or unlinks it; Done saves them as one change). Lights on other
 * floors cannot be linked.
 */
@Component({
    selector: 'fd-switch-lights',
    imports: [IconComponent],
    template: `
        <h3 class="title">{{ linking() ? 'Picking lights' : 'Lights' }}</h3>
        <ul class="lights">
            @for (light of lights(); track light.id) {
                <li class="light">
                    <button type="button" class="light__name" [disabled]="linking()" (click)="selectLight.emit(light.id)">
                        {{ light.name }}
                        @if (light.roomCode !== null) {
                            <span class="light__room">{{ light.roomCode }}</span>
                        }
                    </button>
                    <button
                        type="button"
                        class="icon-button"
                        title="Unlink"
                        [attr.aria-label]="'Unlink ' + light.name"
                        [disabled]="disabled()"
                        (click)="unlink.emit(light.id)"
                    >
                        <fd-icon name="close" [size]="14" />
                    </button>
                </li>
            } @empty {
                <li class="hint">No lights linked.</li>
            }
        </ul>
        @if (linking()) {
            <p class="hint">Click lights in the view to link or unlink them · <kbd>Enter</kbd> done · <kbd>Esc</kbd> cancel</p>
            <div class="buttons">
                <button type="button" class="btn btn--primary btn--small" [disabled]="disabled()" (click)="done.emit()">Done</button>
                <button type="button" class="btn btn--small" (click)="stop.emit()">Cancel</button>
            </div>
        } @else {
            <button
                type="button"
                class="btn btn--small"
                title="Pick the lights this switch controls (L)"
                [disabled]="disabled()"
                (click)="pick.emit()"
            >
                <fd-icon name="bulb" [size]="15" /> Pick lights
            </button>
        }
        <p class="hint">Lights on this floor only. A light linked to two switches is two-way switching.</p>
    `,
    styles: `
        @use 'variables' as *;

        :host {
            display: flex;
            flex-direction: column;
            align-items: flex-start;
            gap: $space-8;
            align-self: stretch;
        }

        .title {
            margin: 0;
            font-size: $font-size-13;
            font-weight: 600;
        }

        .lights {
            align-self: stretch;
            margin: 0;
            padding: 0;
            list-style: none;
        }

        .light {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: $space-8;
        }

        .light__name {
            padding: 2px 0;
            border: 0;
            background: none;
            font: inherit;
            font-size: $font-size-13;
            color: $color-accent;
            text-align: left;
            cursor: pointer;

            &:disabled {
                color: inherit;
                cursor: default;
            }
        }

        .light__room {
            margin-left: 6px;
            font-size: $font-size-12;
            color: $color-text-muted;
        }

        .buttons {
            display: flex;
            gap: $space-8;
        }

        .hint {
            margin: 0;
            font-size: $font-size-12;
            color: $color-text-muted;
        }
    `,
    changeDetection: ChangeDetectionStrategy.OnPush,
})
export class SwitchLightsComponent {
    /** The linked lights (while picking, the picked ones). */
    public readonly lights = input.required<SwitchLight[]>();
    public readonly linking = input(false);
    public readonly disabled = input(false);

    public readonly pick = output<void>();
    public readonly done = output<void>();
    /** Picking ends without saving. */
    public readonly stop = output<void>();
    public readonly unlink = output<number>();
    public readonly selectLight = output<number>();
}
