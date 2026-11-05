import {ChangeDetectionStrategy, Component, inject} from '@angular/core';
import {WallType} from '@app/models/scene';
import {IconComponent} from '@app/shared/icon/icon.component';
import {TranslatePipe} from '@ngx-translate/core';

import {EditorStore} from './editor.store';

const WallTypes: WallType[] = ['Interior', 'Exterior', 'Shaft'];

/** The Draw walls tool's options: type, thickness and height of the walls drawn next. */
@Component({
    selector: 'fd-wall-draft',
    imports: [IconComponent, TranslatePipe],
    template: `
        @let draft = editor.wallDraft();
        <span class="title"><fd-icon name="wall" [size]="16" /> {{ 'inspector.wallDraft.title' | translate }}</span>
        <label class="draft-field">
            {{ 'fields.type' | translate }}
            <select class="input" [value]="draft.type" (change)="set('type', $event)">
                @for (type of wallTypes; track type) {
                    <option [value]="type">{{ 'enums.wallType.' + type | translate }}</option>
                }
            </select>
        </label>
        <label class="draft-field">
            {{ 'fields.thickness' | translate }}
            <span class="unit">
                <input class="input" type="number" min="10" max="2000" step="10" [value]="draft.thickness" (change)="set('thickness', $event)" />
                <span>mm</span>
            </span>
        </label>
        <label class="draft-field">
            {{ 'fields.height' | translate }}
            <span class="unit">
                <input class="input" type="number" min="100" max="20000" step="10" [value]="draft.height" (change)="set('height', $event)" />
                <span>mm</span>
            </span>
        </label>
    `,
    styles: `
        @use 'variables' as *;

        :host {
            display: flex;
            align-items: center;
            gap: $space-12;
            white-space: nowrap;
        }

        .title {
            display: flex;
            align-items: center;
            gap: 6px;
            font-size: $font-size-13;
            font-weight: 600;
        }

        .draft-field {
            display: flex;
            align-items: center;
            gap: 6px;
            font-size: $font-size-12;
            color: $color-text-muted;
        }

        .input {
            width: auto;
            padding: 4px 6px;
            font-size: $font-size-13;
        }

        input.input {
            width: 84px;
            padding-right: 30px;
        }

        .unit {
            position: relative;

            span {
                position: absolute;
                right: 8px;
                top: 50%;
                transform: translateY(-50%);
                font-size: 11px;
                color: $color-text-subtle;
                pointer-events: none;
            }
        }
    `,
    changeDetection: ChangeDetectionStrategy.OnPush,
})
export class WallDraftComponent {
    protected readonly editor = inject(EditorStore);
    protected readonly wallTypes = WallTypes;

    /** Changes a field of the walls drawn next; values out of range are left as they were. */
    protected set(field: 'type' | 'thickness' | 'height', event: Event): void {
        const value = (event.target as HTMLInputElement | HTMLSelectElement).value;
        const draft = this.editor.wallDraft();
        if (field === 'type') {
            this.editor.wallDraft.set({...draft, type: value as WallType});
            return;
        }
        const [min, max] = field === 'thickness' ? [10, 2000] : [100, 20000];
        const number = Math.round(Number(value));
        if (Number.isFinite(number) && number >= min && number <= max) {
            this.editor.wallDraft.set({...draft, [field]: number});
        }
    }
}
