import {ChangeDetectionStrategy, Component, computed, input, output, signal} from '@angular/core';
import {CabinetParams} from '@app/models/scene';

import {
    addFront,
    frontsOf,
    FrontTypes,
    Handles,
    HandleSides,
    moveFront,
    Params,
    parseMillimetres,
    removeFront,
    withFront,
    withParam,
} from './cabinet-params';

/**
 * Form for a cabinet's `params`: plinth, handle (and the side of a single door's vertical one), the corner blind widths and
 * the fronts from the bottom up, each optionally with its own handle.
 * Every change emits the whole new params object (keys the form does not know are kept).
 */
@Component({
    selector: 'fd-cabinet-params',
    templateUrl: './cabinet-params.component.html',
    styleUrl: './cabinet-params.component.scss',
    changeDetection: ChangeDetectionStrategy.OnPush,
})
export class CabinetParamsComponent {
    public readonly params = input.required<Params>();
    public readonly disabled = input(false);

    public readonly paramsChange = output<Params>();

    protected readonly frontTypes = FrontTypes;
    protected readonly handles = Handles;
    protected readonly handleSides = HandleSides;
    protected readonly fronts = computed(() => frontsOf(this.params()));
    /** Listed top first like the cabinet itself; `index` is the position in the bottom-up list. */
    protected readonly frontsTopDown = computed(() => this.fronts().map((front, index) => ({front, index})).reverse());
    protected readonly cabinet = computed(() => this.params() as CabinetParams);
    protected readonly error = signal<string | null>(null);

    protected setNumber(key: 'plinth' | 'blindRight' | 'blindLeft', event: Event): void {
        const parsed = this.parse(event);
        if (parsed !== null) {
            this.emit(withParam(this.params(), key, parsed.value));
        }
    }

    protected setChoice(key: 'handle' | 'handleSide', event: Event): void {
        const value = (event.target as HTMLSelectElement).value;
        this.emit(withParam(this.params(), key, value === '' ? undefined : value));
    }

    protected setFrontHandle(index: number, event: Event): void {
        const value = (event.target as HTMLSelectElement).value;
        this.emit(withFront(this.params(), index, {handle: value === '' ? undefined : value}));
    }

    protected setFrontType(index: number, event: Event): void {
        this.emit(withFront(this.params(), index, {type: (event.target as HTMLSelectElement).value}));
    }

    protected setFrontNumber(index: number, key: 'height' | 'count', event: Event): void {
        const parsed = this.parse(event);
        if (parsed !== null) {
            this.emit(withFront(this.params(), index, {[key]: parsed.value}));
        }
    }

    protected add(): void {
        this.emit(addFront(this.params()));
    }

    protected remove(index: number): void {
        this.emit(removeFront(this.params(), index));
    }

    protected move(index: number, direction: -1 | 1): void {
        this.emit(moveFront(this.params(), index, direction));
    }

    private parse(event: Event): {value: number | undefined} | null {
        const parsed = parseMillimetres((event.target as HTMLInputElement).value);
        if (!parsed.ok) {
            this.error.set(parsed.error);
            return null;
        }
        return parsed;
    }

    private emit(params: Params): void {
        this.error.set(null);
        if (JSON.stringify(params) !== JSON.stringify(this.params())) {
            this.paramsChange.emit(params);
        }
    }
}
