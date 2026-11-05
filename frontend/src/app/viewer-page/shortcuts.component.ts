import {ChangeDetectionStrategy, Component, input} from '@angular/core';
import {TranslatePipe} from '@ngx-translate/core';

/**
 * The keyboard shortcuts of the viewer pages (the rail's panel, `?`): the owner's page lists the views, editing and
 * drawing keys too, a share link's guest what they may do there (look around, measure, comment, walk).
 */
@Component({
    selector: 'fd-shortcuts',
    imports: [TranslatePipe],
    template: `
        <h3 class="shortcuts__title">{{ 'viewer.shortcuts.views' | translate }}</h3>
        <dl class="shortcuts">
            @if (owner()) {
                <dt><kbd>1</kbd> <kbd>2</kbd> <kbd>3</kbd></dt>
                <dd>{{ 'viewer.shortcuts.modes' | translate }}</dd>
            }
            <dt><kbd>D</kbd></dt>
            <dd>{{ 'viewer.shortcuts.measure' | translate }} (<kbd>Esc</kbd> {{ 'viewer.shortcuts.clearsIt' | translate }})</dd>
            @if (canComment()) {
                <dt><kbd>C</kbd></dt>
                <dd>{{ 'viewer.shortcuts.comment' | translate }}</dd>
            }
            <dt><kbd>V</kbd></dt>
            <dd>{{ 'viewer.shortcuts.select' | translate }}</dd>
            <dt><kbd>?</kbd></dt>
            <dd>{{ 'viewer.shortcuts.these' | translate }}</dd>
        </dl>
        @if (owner()) {
            <h3 class="shortcuts__title">{{ 'viewer.shortcuts.editing' | translate }}</h3>
            <dl class="shortcuts">
                <dt><kbd>E</kbd></dt>
                <dd>{{ 'viewer.shortcuts.editDone' | translate }}</dd>
                <dt><kbd>W</kbd> <kbd>M</kbd> <kbd>B</kbd></dt>
                <dd>{{ 'viewer.shortcuts.draw' | translate }}</dd>
                <dt>{{ 'viewer.shortcuts.arrows' | translate }}</dt>
                <dd>{{ 'viewer.shortcuts.nudge' | translate }} (<kbd>Shift</kbd> × 10)</dd>
                <dt><kbd>R</kbd></dt>
                <dd>{{ 'viewer.shortcuts.turn' | translate }}</dd>
                <dt><kbd>Ctrl</kbd>+<kbd>D</kbd></dt>
                <dd>{{ 'viewer.shortcuts.duplicate' | translate }}</dd>
                <dt><kbd>Del</kbd></dt>
                <dd>{{ 'common.delete' | translate }}</dd>
                <dt><kbd>Ctrl</kbd>+<kbd>Z</kbd></dt>
                <dd>
                    {{ 'viewer.shortcuts.undo' | translate }} (<kbd>Ctrl</kbd>+<kbd>Y</kbd> {{ 'viewer.shortcuts.orWith' | translate }}
                    <kbd>Shift</kbd>: {{ 'viewer.shortcuts.redo' | translate }})
                </dd>
                <dt><kbd>Shift</kbd>+{{ 'viewer.shortcuts.click' | translate }}</dt>
                <dd>{{ 'viewer.shortcuts.pickGroup' | translate }}</dd>
                <dt><kbd>Alt</kbd>+{{ 'viewer.shortcuts.click' | translate }}</dt>
                <dd>{{ 'viewer.shortcuts.inGroup' | translate }}</dd>
                <dt><kbd>L</kbd></dt>
                <dd>{{ 'viewer.shortcuts.lights' | translate }} (<kbd>Enter</kbd> {{ 'viewer.shortcuts.done' | translate }})</dd>
                <dt><kbd>Esc</kbd></dt>
                <dd>{{ 'viewer.shortcuts.cancel' | translate }}</dd>
            </dl>
            <h3 class="shortcuts__title">{{ 'viewer.shortcuts.drawing' | translate }}</h3>
            <dl class="shortcuts">
                <dt><kbd>3200</kbd></dt>
                <dd>{{ 'viewer.shortcuts.typeLength' | translate }} <kbd>Enter</kbd></dd>
                <dt><kbd>3200&lt;90</kbd></dt>
                <dd>{{ 'viewer.shortcuts.angle' | translate }}</dd>
                <dt><kbd>~400</kbd> · <kbd>A</kbd></dt>
                <dd>{{ 'viewer.shortcuts.curve' | translate }}</dd>
                <dt><kbd>Backspace</kbd></dt>
                <dd>{{ 'viewer.shortcuts.backspace' | translate }}</dd>
                <dt><kbd>Enter</kbd> <kbd>Esc</kbd></dt>
                <dd>{{ 'viewer.shortcuts.endRun' | translate }}</dd>
            </dl>
        }
        <h3 class="shortcuts__title">{{ 'viewer.shortcuts.walk' | translate }}</h3>
        <dl class="shortcuts">
            <dt><kbd>W</kbd><kbd>A</kbd><kbd>S</kbd><kbd>D</kbd> · {{ 'viewer.shortcuts.arrowsLower' | translate }}</dt>
            <dd>
                {{ 'viewer.shortcuts.walkWalk' | translate }} (<kbd>Shift</kbd> {{ 'viewer.shortcuts.runs' | translate }}),
                {{ 'viewer.shortcuts.mouseLooks' | translate }}
            </dd>
            <dt><kbd>E</kbd> · {{ 'viewer.shortcuts.click' | translate }}</dt>
            <dd>{{ 'viewer.shortcuts.door' | translate }}</dd>
            <dt><kbd>PgUp</kbd> <kbd>PgDn</kbd></dt>
            <dd>{{ 'viewer.shortcuts.floors' | translate }}</dd>
            <dt><kbd>Esc</kbd></dt>
            <dd>{{ 'viewer.shortcuts.leave' | translate }}</dd>
        </dl>
        <p class="shortcuts__note">
            {{ 'viewer.shortcuts.note' | translate }}
            @if (owner()) {
                {{ 'viewer.shortcuts.notePlan' | translate }} <kbd>Shift</kbd> {{ 'viewer.shortcuts.notePlanShift' | translate }}
            }
        </p>
    `,
    styles: `
        @use 'variables' as *;

        :host {
            display: block;
        }

        .shortcuts__title {
            margin: $space-12 0 6px;
            font-size: 11px;
            font-weight: 650;
            letter-spacing: 0.04em;
            text-transform: uppercase;
            color: $color-text-subtle;

            &:first-child {
                margin-top: 0;
            }
        }

        .shortcuts {
            display: grid;
            grid-template-columns: auto 1fr;
            gap: 6px $space-12;
            align-items: center;
            margin: 0;
            font-size: $font-size-12;

            dt {
                text-align: right;
                white-space: nowrap;
            }

            dd {
                margin: 0;
                color: $color-text-muted;
            }
        }

        .shortcuts__note {
            margin: $space-12 0 0;
            font-size: $font-size-12;
            color: $color-text-muted;
        }
    `,
    changeDetection: ChangeDetectionStrategy.OnPush,
})
export class ShortcutsComponent {
    /** The project's own page (views, editing, drawing), not a share link. */
    public readonly owner = input(true);
    public readonly canComment = input(true);
}
