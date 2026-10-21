import {ChangeDetectionStrategy, Component, inject, input, signal} from '@angular/core';
import {Scene} from '@app/models/scene';
import {AlertService} from '@app/services/alert.service';
import {planSheet, roomScheduleSheets, SheetInfo} from '@app/viewer/engine/plan-drawing';

import {downloadBlob, safeFilename} from './download';
import {sheetsPdf} from './sheets-pdf';

/**
 * The viewer panel's exports of the floor as shown (its variants): the plan on A4 as a PDF, with the room schedule on
 * the following page, or the plan page alone as an SVG.
 */
@Component({
    selector: 'fd-plan-export',
    template: `
        <div class="exports">
            <button type="button" class="btn btn--ghost" [disabled]="busy()" title="The plan on A4 with the room schedule" (click)="exportPdf()">
                Plan PDF
            </button>
            <button type="button" class="btn btn--ghost" [disabled]="busy()" title="The plan page as a vector drawing" (click)="exportSvg()">
                Plan SVG
            </button>
        </div>
    `,
    styles: `
        @use 'variables' as *;

        .exports {
            display: flex;
            gap: $space-8;
        }

        .btn {
            flex: 1;
        }
    `,
    changeDetection: ChangeDetectionStrategy.OnPush,
})
export class PlanExportComponent {
    public readonly scene = input.required<Scene>();
    public readonly projectName = input.required<string>();
    public readonly address = input<string | null>(null);

    private readonly alerts = inject(AlertService);
    protected readonly busy = signal(false);

    protected async exportPdf(): Promise<void> {
        const info = this.info();
        this.busy.set(true);
        try {
            const blob = await sheetsPdf([planSheet(this.scene(), info), ...roomScheduleSheets(this.scene(), info)]);
            downloadBlob(blob, this.filename('pdf'));
        } catch (e) {
            this.alerts.error(e instanceof Error ? e.message : 'The PDF could not be made.');
        } finally {
            this.busy.set(false);
        }
    }

    protected exportSvg(): void {
        const sheet = planSheet(this.scene(), this.info());
        downloadBlob(new Blob([sheet.svg], {type: 'image/svg+xml'}), this.filename('svg'));
    }

    private info(): SheetInfo {
        return {projectName: this.projectName(), floorName: this.scene().floor.name, address: this.address(), date: new Date()};
    }

    private filename(extension: string): string {
        return safeFilename(`${this.projectName()} – ${this.scene().floor.name} plan.${extension}`);
    }
}
