import {ChangeDetectionStrategy, Component, computed, input, output, signal} from '@angular/core';
import {Material, WardrobeParams} from '@app/models/scene';

import {Params, parseMillimetres, withParam} from './cabinet-params';
import {doorCount, mirrorDoorsOf, withDoors, withMirrorDoor} from './wardrobe-params';

/**
 * Form for a sliding-door wardrobe's `params`: door count, plinth, profile material and which doors are
 * mirrors. Every change emits the whole new params object (keys the form does not know are kept).
 */
@Component({
    selector: 'fd-wardrobe-params',
    templateUrl: './wardrobe-params.component.html',
    styleUrl: './wardrobe-params.component.scss',
    changeDetection: ChangeDetectionStrategy.OnPush,
})
export class WardrobeParamsComponent {
    public readonly params = input.required<Params>();
    public readonly width = input.required<number>();
    public readonly materials = input<Material[]>([]);
    public readonly disabled = input(false);

    public readonly paramsChange = output<Params>();

    protected readonly wardrobe = computed(() => this.params() as WardrobeParams);
    protected readonly doors = computed(() => doorCount(this.params(), this.width()));
    /** Door numbers from 1 at the left, with whether each is a mirror. */
    protected readonly doorRows = computed(() => {
        const mirrors = mirrorDoorsOf(this.params());
        return Array.from({length: this.doors()}, (_, i) => ({door: i + 1, mirror: mirrors.includes(i + 1)}));
    });
    protected readonly error = signal<string | null>(null);

    protected setDoors(event: Event): void {
        const parsed = this.parse(event);
        if (parsed !== null) {
            this.emit(withDoors(this.params(), parsed.value, this.width()));
        }
    }

    protected setPlinth(event: Event): void {
        const parsed = this.parse(event);
        if (parsed !== null) {
            this.emit(withParam(this.params(), 'plinth', parsed.value));
        }
    }

    protected setProfileMaterial(event: Event): void {
        const value = (event.target as HTMLSelectElement).value;
        this.emit(withParam(this.params(), 'profileMaterialId', value === '' ? undefined : Number(value)));
    }

    protected setMirror(door: number, event: Event): void {
        this.emit(withMirrorDoor(this.params(), door, (event.target as HTMLInputElement).checked));
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
