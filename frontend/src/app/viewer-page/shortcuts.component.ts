import {ChangeDetectionStrategy, Component, input} from '@angular/core';

/**
 * The keyboard shortcuts of the viewer pages (the rail's panel, `?`): the owner's page lists the views, editing and
 * drawing keys too, a share link's guest what they may do there (look around, measure, comment, walk).
 */
@Component({
    selector: 'fd-shortcuts',
    template: `
        <h3 class="shortcuts__title">Views</h3>
        <dl class="shortcuts">
            @if (owner()) {
                <dt><kbd>1</kbd> <kbd>2</kbd> <kbd>3</kbd></dt>
                <dd>3D · Plan · Walk</dd>
            }
            <dt><kbd>D</kbd></dt>
            <dd>Measure (<kbd>Esc</kbd> clears it)</dd>
            @if (canComment()) {
                <dt><kbd>C</kbd></dt>
                <dd>Pin a comment</dd>
            }
            <dt><kbd>V</kbd></dt>
            <dd>Back to selecting</dd>
            <dt><kbd>?</kbd></dt>
            <dd>These shortcuts</dd>
        </dl>
        @if (owner()) {
            <h3 class="shortcuts__title">Editing</h3>
            <dl class="shortcuts">
                <dt><kbd>E</kbd></dt>
                <dd>Edit / done</dd>
                <dt><kbd>W</kbd> <kbd>M</kbd> <kbd>B</kbd></dt>
                <dd>Draw walls · room from walls · railing</dd>
                <dt>Arrows</dt>
                <dd>Nudge the selection (<kbd>Shift</kbd> × 10)</dd>
                <dt><kbd>R</kbd></dt>
                <dd>Turn by 90°</dd>
                <dt><kbd>Ctrl</kbd>+<kbd>D</kbd></dt>
                <dd>Duplicate</dd>
                <dt><kbd>Del</kbd></dt>
                <dd>Delete</dd>
                <dt><kbd>Ctrl</kbd>+<kbd>Z</kbd></dt>
                <dd>Undo (<kbd>Ctrl</kbd>+<kbd>Y</kbd> or with <kbd>Shift</kbd>: redo)</dd>
                <dt><kbd>Shift</kbd>+click</dt>
                <dd>Pick items to group</dd>
                <dt><kbd>Alt</kbd>+click</dt>
                <dd>An item inside its group</dd>
                <dt><kbd>Esc</kbd></dt>
                <dd>Cancel, deselect</dd>
            </dl>
            <h3 class="shortcuts__title">Drawing walls</h3>
            <dl class="shortcuts">
                <dt><kbd>3200</kbd></dt>
                <dd>Type a length, then <kbd>Enter</kbd></dd>
                <dt><kbd>3200&lt;90</kbd></dt>
                <dd>A length at an angle</dd>
                <dt><kbd>~400</kbd> · <kbd>A</kbd></dt>
                <dd>Curve the segment · arc mode</dd>
                <dt><kbd>Backspace</kbd></dt>
                <dd>Take back the last point</dd>
                <dt><kbd>Enter</kbd> <kbd>Esc</kbd></dt>
                <dd>End the run · cancel it</dd>
            </dl>
        }
        <h3 class="shortcuts__title">Walk</h3>
        <dl class="shortcuts">
            <dt><kbd>W</kbd><kbd>A</kbd><kbd>S</kbd><kbd>D</kbd> · arrows</dt>
            <dd>Walk (<kbd>Shift</kbd> runs), the mouse looks</dd>
            <dt><kbd>E</kbd> · click</dt>
            <dd>Open or close the door or cupboard in view</dd>
            <dt><kbd>PgUp</kbd> <kbd>PgDn</kbd></dt>
            <dd>The floor above · below</dd>
            <dt><kbd>Esc</kbd></dt>
            <dd>Leave the walk</dd>
        </dl>
        <p class="shortcuts__note">
            Drag to orbit, right-drag to pan, scroll to zoom.
            @if (owner()) {
                In the plan, drags snap to the grid and to corners; <kbd>Shift</kbd> moves in 1 mm steps without snapping. Drag a midpoint of
                a selected outline for a new corner, double-click a corner to remove it.
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
