import {ChangeDetectionStrategy, Component, computed, inject, input, output} from '@angular/core';
import {Group, Scene, VariantSet} from '@app/models/scene';

import {EditorStore} from './editor.store';

/**
 * Switches between the variants of each variant set (e.g. the kitchen with or without its island). Switching is
 * a shared change like any edit, so it works outside edit mode too, but not on a saved version. While editing,
 * a set's name selects it and variants can be added. With `local` (a share link's guest, no `EditorStore`) a
 * switch is only emitted, for the page to show it without saving.
 */
@Component({
    selector: 'fd-variants',
    template: `
        @for (entry of entries(); track entry.set.id) {
            <div class="variant-set">
                @if (editing()) {
                    <button type="button" class="variant-set__name variant-set__name--link" (click)="select(entry.set)">{{ entry.set.name }}</button>
                } @else {
                    <span class="variant-set__name">{{ entry.set.name }}</span>
                }
                <div class="choices" role="group" [attr.aria-label]="entry.set.name">
                    @for (variant of entry.variants; track variant.id) {
                        <button
                            type="button"
                            [class.active]="entry.set.activeGroupId === variant.id"
                            [attr.aria-pressed]="entry.set.activeGroupId === variant.id"
                            [disabled]="readonly() || busy()"
                            (click)="switchTo(entry.set, variant)"
                        >
                            {{ variant.name }}
                        </button>
                    }
                </div>
                @if (editing()) {
                    <div class="variant-set__actions">
                        <button type="button" [disabled]="busy() || entry.set.activeGroupId === null" (click)="add(entry.set, true)">
                            + Copy of shown
                        </button>
                        <button type="button" [disabled]="busy()" (click)="add(entry.set, false)">+ Empty variant</button>
                    </div>
                }
            </div>
        }
    `,
    styles: `
        @use 'variables' as *;

        :host {
            display: flex;
            flex-direction: column;
            gap: $space-12;
        }

        .variant-set__name {
            display: block;
            margin-bottom: $space-4;
            padding: 0;
            border: 0;
            background: none;
            font-size: $font-size-13;
            font-weight: 500;
            color: $color-text;
            text-align: left;
        }

        .variant-set__name--link {
            cursor: pointer;

            &:hover {
                color: $color-accent;
                text-decoration: underline;
            }
        }

        .choices {
            display: flex;
            flex-wrap: wrap;
            gap: 2px;
            padding: 3px;
            background: $color-surface-muted;
            border-radius: $radius-10;

            button {
                flex: 1 1 auto;
                padding: 6px 8px;
                border: 0;
                border-radius: 8px;
                background: transparent;
                font-size: $font-size-13;
                font-weight: 500;
                color: $color-text-muted;
                cursor: pointer;
                transition: background 0.15s ease, color 0.15s ease;

                &:hover:not(:disabled) {
                    color: $color-text;
                }

                &.active {
                    background: $color-surface-solid;
                    color: $color-text;
                    box-shadow: 0 1px 2px rgba(35, 33, 30, 0.12);
                }

                &:disabled:not(.active) {
                    cursor: default;
                }
            }
        }

        .variant-set__actions {
            display: flex;
            gap: $space-12;
            margin-top: $space-4;

            button {
                padding: 0;
                border: 0;
                background: none;
                font-size: $font-size-12;
                color: $color-accent;
                cursor: pointer;

                &:disabled {
                    opacity: 0.5;
                    cursor: default;
                }
            }
        }
    `,
    changeDetection: ChangeDetectionStrategy.OnPush,
})
export class VariantsComponent {
    public readonly scene = input.required<Scene>();
    /** A saved version: the variants are shown but cannot be switched. */
    public readonly readonly = input(false);
    public readonly editing = input(false);
    public readonly local = input(false);

    public readonly switched = output<{setId: number; groupId: number}>();

    /** Not provided on the share link page; `editing` is never set there. */
    protected readonly editor = inject(EditorStore, {optional: true});
    protected readonly busy = computed(() => this.editor?.busy() ?? false);

    protected readonly entries = computed((): {set: VariantSet; variants: Group[]}[] => {
        const groups = this.scene().groups ?? [];
        return (this.scene().variantSets ?? []).map((set) => ({set, variants: groups.filter((g) => g.variantSetId === set.id)}));
    });

    protected switchTo(set: VariantSet, variant: Group): void {
        if (set.activeGroupId === variant.id) {
            return;
        }
        if (this.local()) {
            this.switched.emit({setId: set.id, groupId: variant.id});
        } else {
            void this.editor?.switchVariant(set.id, variant.id);
        }
    }

    protected add(set: VariantSet, copy: boolean): void {
        void this.editor?.addVariant(set.id, copy ? set.activeGroupId : null);
    }

    protected select(set: VariantSet): void {
        this.editor?.select({kind: 'variantSet', id: set.id});
    }
}
