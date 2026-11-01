import {ChangeDetectionStrategy, Component, computed, input, output} from '@angular/core';
import {Material, WallFinish} from '@app/models/scene';
import {IconComponent} from '@app/shared/icon/icon.component';

import {MaterialPickerComponent} from './material-picker.component';

/** Height of a new band, mm. */
const NewBandHeight = 1200;

type FaceMode = 'room' | 'own' | 'bare';

/**
 * Finish bands (`{materialId, bottom, top}`, mm above the floor) as rows: a room's `wallFinishes`, or a wall face's own
 * (`nullable`): null takes the room's, `[]` leaves the face bare. Every change sends the whole list; the backend checks
 * it (`WallFinishBands`).
 */
@Component({
    selector: 'fd-finish-bands',
    imports: [IconComponent, MaterialPickerComponent],
    template: `
        @if (nullable()) {
            <div class="modes" role="group" aria-label="Finishes of this face">
                <button type="button" [class.active]="mode() === 'room'" [disabled]="disabled()" (click)="setMode('room')">As the room</button>
                <button type="button" [class.active]="mode() === 'own'" [disabled]="disabled() || materials().length === 0" (click)="setMode('own')">
                    Own bands
                </button>
                <button type="button" [class.active]="mode() === 'bare'" [disabled]="disabled()" (click)="setMode('bare')">Bare</button>
            </div>
        }
        @if (mode() === 'own' || !nullable()) {
            @if (list().length > 0) {
                <div class="bands">
                    @for (band of list(); track $index; let i = $index) {
                        <div class="band">
                            <fd-material-picker
                                class="band__material"
                                [materials]="materials()"
                                [value]="band.materialId"
                                [disabled]="disabled()"
                                (picked)="setMaterial(i, $event)"
                            />
                            <button
                                type="button"
                                class="icon-button"
                                [attr.aria-label]="'Remove band ' + (i + 1)"
                                title="Remove"
                                [disabled]="disabled()"
                                (click)="remove(i)"
                            >
                                <fd-icon name="close" [size]="14" />
                            </button>
                            <label class="band__height">
                                <span>From</span>
                                <input
                                    class="input"
                                    type="number"
                                    step="10"
                                    [value]="band.bottom"
                                    [disabled]="disabled()"
                                    (change)="setHeight(i, 'bottom', $event)"
                                />
                            </label>
                            <label class="band__height">
                                <span>To</span>
                                <input
                                    class="input"
                                    type="number"
                                    step="10"
                                    [value]="band.top"
                                    [disabled]="disabled()"
                                    (change)="setHeight(i, 'top', $event)"
                                />
                            </label>
                        </div>
                    }
                </div>
                <p class="hint">mm above the floor.</p>
            } @else {
                <p class="hint">Painted walls, no tiles or panelling.</p>
            }
            <button type="button" class="btn btn--ghost btn--small add" [disabled]="disabled() || materials().length === 0" (click)="add()">
                <fd-icon name="plus" [size]="14" /> Add band
            </button>
        } @else if (mode() === 'room') {
            <p class="hint">This face takes the bands of the room in front of it.</p>
        } @else {
            <p class="hint">This face stays bare, whatever the room has.</p>
        }
    `,
    styles: `
        @use 'variables' as *;

        :host {
            display: flex;
            flex-direction: column;
            gap: $space-8;
        }

        .modes {
            display: flex;
            padding: 2px;
            border-radius: $radius-10;
            background: $color-surface-muted;

            button {
                flex: 1;
                padding: 4px 6px;
                border: 0;
                border-radius: 8px;
                background: transparent;
                font-size: $font-size-12;
                color: $color-text-muted;
                cursor: pointer;

                &.active {
                    background: $color-surface-solid;
                    color: $color-text;
                    box-shadow: 0 1px 2px rgba(35, 33, 30, 0.12);
                }
            }
        }

        .bands {
            display: flex;
            flex-direction: column;
            gap: $space-8;
        }

        // A band: its material across, then where it runs from and to.
        .band {
            display: grid;
            grid-template-columns: minmax(0, 1fr) minmax(0, 1fr) 28px;
            gap: 6px;
            padding: $space-8;
            border: 1px solid $color-border;
            border-radius: $radius-10;
            background: $color-surface-solid;
        }

        .band__material {
            grid-column: 1 / 3;
        }

        .band__height {
            display: flex;
            align-items: center;
            gap: 6px;
            min-width: 0;
            font-size: 11px;
            color: $color-text-muted;
        }

        .input {
            flex: 1;
            min-width: 0;
            height: 30px;
            padding: 4px 6px;
            font-size: $font-size-13;
        }

        .icon-button {
            width: 28px;
            height: 32px;
        }

        .hint {
            margin: 0;
            font-size: 11px;
            color: $color-text-muted;
        }

        .add {
            align-self: flex-start;
            color: $color-accent;
        }
    `,
    changeDetection: ChangeDetectionStrategy.OnPush,
})
export class FinishBandsComponent {
    /** The field's value: a list of bands, or null (a wall face taking the room's). */
    public readonly bands = input<unknown>(null);
    /** A wall face's own bands, which may be null. */
    public readonly nullable = input(false);
    public readonly materials = input<Material[]>([]);
    public readonly disabled = input(false);
    public readonly bandsChange = output<WallFinish[] | null>();

    protected readonly list = computed((): WallFinish[] => (Array.isArray(this.bands()) ? (this.bands() as WallFinish[]) : []));
    protected readonly mode = computed((): FaceMode => {
        const bands = this.bands();
        if (!Array.isArray(bands)) {
            return 'room';
        }
        return bands.length === 0 ? 'bare' : 'own';
    });

    protected setMode(mode: FaceMode): void {
        if (mode === this.mode() || (mode === 'own' && this.materials().length === 0)) {
            return;
        }
        if (mode === 'room') {
            this.bandsChange.emit(null);
        } else if (mode === 'bare') {
            this.bandsChange.emit([]);
        } else {
            this.bandsChange.emit([this.newBand([])]);
        }
    }

    protected add(): void {
        this.bandsChange.emit([...this.list(), this.newBand(this.list())]);
    }

    protected remove(index: number): void {
        this.bandsChange.emit(this.list().filter((_, i) => i !== index));
    }

    protected setMaterial(index: number, materialId: number | null): void {
        if (materialId !== null) {
            this.bandsChange.emit(this.list().map((band, i) => (i === index ? {...band, materialId} : band)));
        }
    }

    protected setHeight(index: number, edge: 'bottom' | 'top', event: Event): void {
        const field = event.target as HTMLInputElement;
        const value = Number(field.value);
        if (field.value.trim() === '' || !Number.isInteger(value)) {
            field.value = String(this.list()[index][edge]);
            return;
        }
        this.bandsChange.emit(this.list().map((band, i) => (i === index ? {...band, [edge]: value} : band)));
    }

    /** A band over the last one (from the floor for the first), in the last band's material or the catalogue's first. */
    private newBand(bands: WallFinish[]): WallFinish {
        const last = bands.at(-1);
        const bottom = last?.top ?? 0;
        return {materialId: last?.materialId ?? this.materials()[0].id, bottom, top: bottom + NewBandHeight};
    }
}
