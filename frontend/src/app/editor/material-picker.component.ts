import {ChangeDetectionStrategy, Component, computed, ElementRef, inject, input, output, signal, viewChild} from '@angular/core';
import {Material} from '@app/models/scene';
import {IconComponent} from '@app/shared/icon/icon.component';
import {TranslatePipe} from '@ngx-translate/core';

/**
 * A material picked from the catalogue by its swatch (the material's colour, its texture over it) and name, with a
 * search; the list opens in place under the button, so the inspector's scroll does not clip it. With `nullable` the
 * first entry clears it (`emptyLabel`).
 */
@Component({
    selector: 'fd-material-picker',
    imports: [IconComponent, TranslatePipe],
    template: `
        <button
            type="button"
            class="trigger"
            [attr.id]="inputId()"
            [attr.aria-expanded]="open()"
            [disabled]="disabled()"
            [title]="current()?.name ?? emptyLabel()"
            (click)="toggle()"
        >
            @if (current(); as material) {
                <span class="swatch" [style.background-color]="material.color" [style.background-image]="texture(material)"></span>
                <span class="trigger__name">{{ material.name }}</span>
            } @else {
                <span class="swatch swatch--empty"></span>
                <span class="trigger__name trigger__name--empty">{{ emptyLabel() }}</span>
            }
            <fd-icon name="chevronDown" [size]="15" />
        </button>
        @if (open()) {
            <div class="list" role="listbox">
                <input
                    #search
                    class="search"
                    type="search"
                    [placeholder]="'inspector.materials.searchPlaceholder' | translate"
                    [attr.aria-label]="'inspector.materials.search' | translate"
                    [value]="query()"
                    (input)="query.set($any($event.target).value)"
                    (keydown.enter)="pickFirst($event)"
                    (keydown.escape)="close($event)"
                />
                <div class="options">
                    @if (nullable() && query() === '') {
                        <button type="button" class="option" role="option" [attr.aria-selected]="value() === null" (click)="pick(null)">
                            <span class="swatch swatch--empty"></span>
                            <span class="option__name option__name--empty">{{ emptyLabel() }}</span>
                        </button>
                    }
                    @for (material of shown(); track material.id) {
                        <button
                            type="button"
                            class="option"
                            role="option"
                            [class.option--selected]="material.id === value()"
                            [attr.aria-selected]="material.id === value()"
                            (click)="pick(material.id)"
                        >
                            <span class="swatch" [style.background-color]="material.color" [style.background-image]="texture(material)"></span>
                            <span class="option__name">{{ material.name }}</span>
                        </button>
                    } @empty {
                        <p class="empty">{{ 'inspector.materials.noMatch' | translate: {query: query()} }}</p>
                    }
                </div>
            </div>
        }
    `,
    styleUrl: './material-picker.component.scss',
    changeDetection: ChangeDetectionStrategy.OnPush,
    host: {'(document:pointerdown)': 'onDocumentPointerDown($event)'},
})
export class MaterialPickerComponent {
    public readonly materials = input<Material[]>([]);
    public readonly value = input<number | null>(null);
    public readonly nullable = input(false);
    /** What no material means here ("—", "White paint", "Lawn"). */
    public readonly emptyLabel = input('—');
    public readonly disabled = input(false);
    /** The button's id, for a label's `for`. */
    public readonly inputId = input<string | null>(null);
    public readonly picked = output<number | null>();

    protected readonly open = signal(false);
    protected readonly query = signal('');
    protected readonly current = computed(() => this.materials().find((m) => m.id === this.value()) ?? null);
    /** The catalogue by name, narrowed by the search. */
    private readonly sorted = computed(() => [...this.materials()].sort((a, b) => a.name.localeCompare(b.name)));
    protected readonly shown = computed(() => {
        const query = this.query().trim().toLowerCase();
        return query === '' ? this.sorted() : this.sorted().filter((m) => m.name.toLowerCase().includes(query));
    });
    private readonly host = inject<ElementRef<HTMLElement>>(ElementRef);
    private readonly search = viewChild<ElementRef<HTMLInputElement>>('search');

    /** The texture photo over the colour, from the app's assets. */
    protected texture(material: Material): string | null {
        return material.texture === null ? null : `url("assets/${material.texture}")`;
    }

    protected toggle(): void {
        this.open.update((open) => !open);
        this.query.set('');
        if (this.open()) {
            queueMicrotask(() => this.search()?.nativeElement.focus());
        }
    }

    protected pick(id: number | null): void {
        this.open.set(false);
        if (id !== this.value()) {
            this.picked.emit(id);
        }
    }

    protected pickFirst(event: Event): void {
        event.preventDefault();
        const first = this.shown()[0];
        if (first !== undefined) {
            this.pick(first.id);
        }
    }

    protected close(event: Event): void {
        // The editor's Esc deselects the element: here it only closes the list.
        event.stopPropagation();
        this.open.set(false);
    }

    protected onDocumentPointerDown(event: PointerEvent): void {
        if (this.open() && !this.host.nativeElement.contains(event.target as Node)) {
            this.open.set(false);
        }
    }
}
