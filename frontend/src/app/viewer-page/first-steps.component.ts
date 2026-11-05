import {ChangeDetectionStrategy, Component, computed, input, output} from '@angular/core';
import {RouterLink} from '@angular/router';
import {Scene} from '@app/models/scene';
import {IconComponent} from '@app/shared/icon/icon.component';
import {TranslatePipe} from '@ngx-translate/core';

import {FirstStep, firstSteps, StepView} from './first-steps';

/**
 * The first steps of a new floor (the rail's guide, opened by itself on an empty floor): walls, rooms, a door,
 * furniture and a walk, each ticked off once the floor has it and with a button that starts it.
 */
@Component({
    selector: 'fd-first-steps',
    imports: [IconComponent, RouterLink, TranslatePipe],
    template: `
        <p class="intro">{{ 'viewer.firstSteps.intro' | translate }}</p>
        <ol class="steps">
            @for (step of steps(); track step.key; let i = $index) {
                <li class="step" [class.step--done]="step.done">
                    <span class="step__mark" aria-hidden="true">
                        @if (step.done) {
                            <fd-icon name="check" [size]="14" />
                        } @else {
                            {{ i + 1 }}
                        }
                    </span>
                    <span class="step__body">
                        <strong>{{ step.title | translate }}</strong>
                        <span class="step__text">{{ step.text | translate }}</span>
                        @if (!step.done || step.key === 'walk') {
                            <button type="button" class="btn btn--small step__action" (click)="start.emit(step.key)">
                                {{ step.action | translate }}
                            </button>
                        }
                    </span>
                </li>
            }
        </ol>
        <p class="note">
            {{ 'viewer.firstSteps.done' | translate: {done: doneCount(), total: steps().length} }}
            {{ 'viewer.firstSteps.press' | translate }} <kbd>?</kbd> {{ 'viewer.firstSteps.forShortcuts' | translate }}
            <a routerLink="/projects">{{ 'viewer.firstSteps.example' | translate }}</a> {{ 'viewer.firstSteps.onProjects' | translate }}
        </p>
    `,
    styles: `
        @use 'variables' as *;

        :host {
            display: block;
            font-size: $font-size-13;
        }

        .intro {
            margin: 0 0 $space-12;
            color: $color-text-muted;
        }

        .steps {
            display: flex;
            flex-direction: column;
            gap: $space-12;
            margin: 0;
            padding: 0;
            list-style: none;
        }

        .step {
            display: grid;
            grid-template-columns: 24px 1fr;
            gap: $space-8;
        }

        .step__mark {
            display: grid;
            place-items: center;
            width: 24px;
            height: 24px;
            border: 1px solid $color-border-strong;
            border-radius: 50%;
            font-size: $font-size-12;
            font-weight: 650;
            color: $color-text-muted;
        }

        .step--done .step__mark {
            border-color: $color-accent;
            background: $color-accent;
            color: #fff;
        }

        .step--done strong {
            color: $color-text-muted;
        }

        .step__body {
            display: flex;
            flex-direction: column;
            align-items: flex-start;
            gap: 2px;
        }

        .step__text {
            font-size: $font-size-12;
            color: $color-text-muted;
        }

        .step__action {
            margin-top: 4px;
        }

        .note {
            margin: $space-16 0 0;
            font-size: $font-size-12;
            color: $color-text-muted;
        }
    `,
    changeDetection: ChangeDetectionStrategy.OnPush,
})
export class FirstStepsComponent {
    public readonly scene = input.required<Scene>();
    /** A walk was taken on this page. */
    public readonly walked = input(false);
    public readonly start = output<FirstStep>();

    protected readonly steps = computed((): StepView[] => firstSteps(this.scene(), this.walked()));
    protected readonly doneCount = computed(() => this.steps().filter((step) => step.done).length);
}
