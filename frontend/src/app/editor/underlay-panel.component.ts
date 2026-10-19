import {DecimalPipe} from '@angular/common';
import {ChangeDetectionStrategy, Component, computed, effect, inject, input, resource, signal, untracked} from '@angular/core';
import {RouterLink} from '@angular/router';
import {Scene, Underlay} from '@app/models/scene';
import {UnderlayInput} from '@app/services/editor-api.service';
import {ProjectService} from '@app/services/project.service';
import {calibrateUnderlay} from '@app/viewer/engine/underlay';

import {EditorStore} from './editor.store';

const ImageTypes = ['image/png', 'image/jpeg', 'image/webp', 'image/gif'];

/** Direction the two calibration points run in reality: as clicked, along x or along y. */
type CalibrationDirection = 'keep' | 'horizontal' | 'vertical';

/**
 * The plan image under the floor's top view, in edit mode: pick an image of the project, set its opacity and
 * placement, or calibrate it from two clicked points and their real distance.
 */
@Component({
    selector: 'fd-underlay-panel',
    imports: [DecimalPipe, RouterLink],
    templateUrl: './underlay-panel.component.html',
    styleUrl: './underlay-panel.component.scss',
    changeDetection: ChangeDetectionStrategy.OnPush,
})
export class UnderlayPanelComponent {
    public readonly scene = input.required<Scene>();
    public readonly projectId = input.required<number>();

    protected readonly editor = inject(EditorStore);
    private readonly projects = inject(ProjectService);

    protected readonly underlay = computed(() => this.scene().underlay ?? null);
    protected readonly images = resource({
        params: () => ({projectId: this.projectId()}),
        loader: async ({params}) => (await this.projects.listFiles(params.projectId)).filter((f) => ImageTypes.includes(f.mimeType)),
    });
    protected readonly imageFiles = computed(() => (this.images.hasValue() ? this.images.value() : []));
    protected readonly points = computed(() => {
        const calibration = this.editor.calibration();
        return typeof calibration === 'object' ? calibration : null;
    });
    /** The distance between the clicked points on the image as it lies now, mm. */
    protected readonly clickedDistance = computed(() => {
        const points = this.points();
        return points === null ? null : Math.round(Math.hypot(points.b.x - points.a.x, points.b.y - points.a.y));
    });
    protected readonly distance = signal<number | null>(null);
    protected readonly direction = signal<CalibrationDirection>('keep');
    /** The placement fields and the image stay as they are while points are picked, which refer to them. */
    protected readonly locked = computed(() => this.editor.busy() || this.editor.calibration() !== null);

    public constructor() {
        // Picked points are plan positions of image features under the placement of that moment: a changed placement
        // (a live update from an agent or another tab) makes them meaningless.
        let placement = JSON.stringify(untracked(this.underlay));
        effect(() => {
            const current = JSON.stringify(this.underlay());
            if (current !== placement) {
                placement = current;
                untracked(() => {
                    if (this.editor.calibration() !== null) {
                        this.editor.calibration.set(null);
                    }
                });
            }
        });
    }

    protected choose(event: Event): void {
        const fileId = Number((event.target as HTMLSelectElement).value);
        if (fileId > 0) {
            void this.save({fileId});
        }
    }

    protected setNumber(field: 'x' | 'y' | 'scale' | 'rotation' | 'opacity', event: Event): void {
        const box = event.target as HTMLInputElement;
        const value = Number(box.value);
        const underlay = this.underlay();
        if (underlay === null || box.value.trim() === '' || !Number.isFinite(value)) {
            // An emptied box is put back to the stored value rather than sent as 0.
            box.value = String(underlay === null ? '' : field === 'opacity' ? underlay.opacity * 100 : underlay[field]);
            return;
        }
        // x and y are whole mm; the others are floats.
        const sent = field === 'opacity' ? value / 100 : field === 'x' || field === 'y' ? Math.round(value) : value;
        void this.save({fileId: underlay.fileId, [field]: sent});
    }

    protected startCalibration(): void {
        this.distance.set(null);
        this.direction.set('keep');
        this.editor.calibration.set('picking');
    }

    protected cancelCalibration(): void {
        this.editor.calibration.set(null);
    }

    protected setDistance(event: Event): void {
        const value = Number((event.target as HTMLInputElement).value);
        this.distance.set(Number.isFinite(value) && value > 0 ? value : null);
    }

    protected setDirection(event: Event): void {
        this.direction.set((event.target as HTMLSelectElement).value as CalibrationDirection);
    }

    protected async applyCalibration(): Promise<void> {
        const underlay = this.underlay();
        const points = this.points();
        const distance = this.distance();
        if (underlay === null || points === null || distance === null) {
            return;
        }
        const placement = calibrateUnderlay(underlay, points.a, points.b, distance, this.angle(points));
        if (placement === null) {
            return;
        }
        if (await this.save({fileId: underlay.fileId, ...placement})) {
            this.editor.calibration.set(null);
        }
    }

    protected remove(): void {
        void this.editor.removeUnderlay();
    }

    protected scaleLabel(underlay: Underlay): string {
        return `1 px = ${underlay.scale.toFixed(2)} mm`;
    }

    /** The direction to straighten the points to: the axis nearest to how they were clicked, keeping their sense. */
    private angle(points: {a: {x: number; y: number}; b: {x: number; y: number}}): number | null {
        const dx = points.b.x - points.a.x;
        const dy = points.b.y - points.a.y;
        switch (this.direction()) {
            case 'horizontal':
                return dx >= 0 ? 0 : 180;
            case 'vertical':
                return dy >= 0 ? 90 : 270;
            default:
                return null;
        }
    }

    private save(fields: UnderlayInput): Promise<boolean> {
        return this.editor.saveUnderlay(fields);
    }
}
