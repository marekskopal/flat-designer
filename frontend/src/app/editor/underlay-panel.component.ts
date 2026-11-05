import {DecimalPipe, NgTemplateOutlet} from '@angular/common';
import {ChangeDetectionStrategy, Component, computed, effect, inject, input, signal, untracked} from '@angular/core';
import {RouterLink} from '@angular/router';
import {PdfPages, ProjectFile} from '@app/models/project';
import {Scene, Underlay} from '@app/models/scene';
import {UnderlayInput} from '@app/services/editor-api.service';
import {ProjectService} from '@app/services/project.service';
import {calibrateUnderlay} from '@app/viewer/engine/underlay';
import {TranslatePipe, TranslateService} from '@ngx-translate/core';

import {EditorStore} from './editor.store';
import {PdfPagePickerComponent} from './pdf-page-picker.component';

/** Option values of the picker's PDFs, beside the images' plain ids. */
const PdfOption = 'pdf:';

/** Direction the two calibration points run in reality: as clicked, along x or along y. */
type CalibrationDirection = 'keep' | 'horizontal' | 'vertical';

/**
 * The plan image under the floor's top view, in edit mode: pick an image of the project, or a page of a PDF (made into
 * an image by the server), set its opacity and placement, or calibrate it from two clicked points and their real
 * distance.
 */
@Component({
    selector: 'fd-underlay-panel',
    imports: [DecimalPipe, NgTemplateOutlet, PdfPagePickerComponent, RouterLink, TranslatePipe],
    templateUrl: './underlay-panel.component.html',
    styleUrl: './underlay-panel.component.scss',
    changeDetection: ChangeDetectionStrategy.OnPush,
})
export class UnderlayPanelComponent {
    private readonly translate = inject(TranslateService);
    public readonly scene = input.required<Scene>();
    public readonly projectId = input.required<number>();

    protected readonly editor = inject(EditorStore);
    protected readonly pdfOption = PdfOption;
    /** A PDF of several pages whose page is being picked. */
    protected readonly pdfChoice = signal<{file: ProjectFile; pages: PdfPages} | null>(null);
    /** The pages of a picked PDF are being read. */
    protected readonly readingPdf = signal(false);
    /** A PDF page is being made into an image and laid. */
    protected readonly layingPage = signal(false);

    private readonly projects = inject(ProjectService);

    protected readonly underlay = computed(() => this.scene().underlay ?? null);
    protected readonly imageFiles = computed(() => this.editor.images());
    protected readonly pdfFiles = computed(() => this.editor.pdfFiles());
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
    protected readonly locked = computed(() => this.editor.busy() || this.readingPdf() || this.editor.calibration() !== null);

    public constructor() {
        // Picked points are plan positions of image features under the placement of that moment: a changed placement
        // (a live update from an agent or another tab) makes them meaningless.
        const placementOf = (underlay: Underlay | null): string =>
            underlay === null ? '' : JSON.stringify([underlay.fileId, underlay.x, underlay.y, underlay.scale, underlay.rotation]);
        // Null until the effect first runs: inputs are not set yet in the constructor.
        let placement: string | null = null;
        effect(() => {
            const current = placementOf(this.underlay());
            if (placement === null) {
                placement = current;
            } else if (current !== placement) {
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
        const select = event.target as HTMLSelectElement;
        if (select.value.startsWith(PdfOption)) {
            const fileId = Number(select.value.slice(PdfOption.length));
            // The select keeps showing the underlay's image; the PDF's page comes from the picker.
            select.value = String(this.underlay()?.fileId ?? '');
            void this.openPdf(fileId);
            return;
        }
        const fileId = Number(select.value);
        if (fileId > 0) {
            this.pdfChoice.set(null);
            void this.save({fileId});
        }
    }

    protected async pickPage(page: number): Promise<void> {
        const choice = this.pdfChoice();
        if (choice !== null && (await this.layPage(choice.file.id, page))) {
            this.pdfChoice.set(null);
        }
    }

    protected cancelPdf(): void {
        this.pdfChoice.set(null);
    }

    private async layPage(fileId: number, page: number): Promise<boolean> {
        this.layingPage.set(true);
        try {
            return await this.editor.layPdfPage(fileId, page);
        } finally {
            this.layingPage.set(false);
        }
    }

    /** A one-page PDF is laid at once; for more pages the picker opens. */
    private async openPdf(fileId: number): Promise<void> {
        const file = this.pdfFiles().find((f) => f.id === fileId);
        if (file === undefined || this.readingPdf()) {
            return;
        }
        this.pdfChoice.set(null);
        this.readingPdf.set(true);
        let pages: PdfPages;
        try {
            pages = await this.projects.pdfPages(this.projectId(), fileId);
        } catch {
            // The error interceptor shows the toast (a damaged or password-protected PDF).
            return;
        } finally {
            this.readingPdf.set(false);
        }
        if (pages.pageCount === 1 && pages.pages[0]?.widthPx != null) {
            await this.layPage(fileId, 1);
        } else {
            this.pdfChoice.set({file, pages});
        }
    }

    protected setNumber(field: 'x' | 'y' | 'scale' | 'rotation' | 'opacity', event: Event): void {
        const box = event.target as HTMLInputElement;
        const value = Number(box.value);
        const underlay = this.underlay();
        if (underlay === null || box.value.trim() === '' || !Number.isFinite(value)) {
            // An emptied box is put back to the stored value rather than sent as 0.
            box.value = underlay === null ? '' : field === 'opacity' ? String(underlay.opacity * 100) : this.plain(underlay[field], 4);
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

    /** A number for an input box: rounded, without the locale's group separators (which `Number()` would not read back). */
    protected plain(value: number, decimals: number): string {
        return String(Number(value.toFixed(decimals)));
    }

    protected scaleLabel(underlay: Underlay): string {
        return this.translate.instant('editor.underlay.scaleLabel', {scale: underlay.scale.toFixed(2)});
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
