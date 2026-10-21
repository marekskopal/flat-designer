import {ChangeDetectionStrategy, Component, inject, input, signal} from '@angular/core';
import {Scene} from '@app/models/scene';
import {AlertService} from '@app/services/alert.service';
import {elevationSheets} from '@app/viewer/engine/elevations';
import {planSheet, roomScheduleSheets} from '@app/viewer/engine/plan-drawing';
import {SheetInfo} from '@app/viewer/engine/sheet-svg';

import {downloadBlob, safeFilename} from './download';
import {sheetsPdf} from './sheets-pdf';

/**
 * The viewer panel's exports of the floor as shown (its variants): the plan on A4 as a PDF, with the room schedule on
 * the following page, or the plan page alone as an SVG; the rooms' wall elevations as a PDF; and the 3D model as glTF
 * (`glb`, made by the viewer).
 */
@Component({
    selector: 'fd-export',
    template: `
        <div class="exports">
            <button type="button" class="btn btn--ghost" [disabled]="busy()" title="The plan on A4 with the room schedule" (click)="exportPdf()">
                Plan PDF
            </button>
            <button type="button" class="btn btn--ghost" [disabled]="busy()" title="The plan page as a vector drawing" (click)="exportSvg()">
                Plan SVG
            </button>
            <button
                type="button"
                class="btn btn--ghost"
                [disabled]="busy()"
                title="Every room's walls seen from inside, with tiling, openings and dimensions, a page per room"
                (click)="exportElevations()"
            >
                Elevations PDF
            </button>
            <button
                type="button"
                class="btn btn--ghost"
                [disabled]="busy()"
                title="The floor as a 3D model (.glb) for Blender, SketchUp or a game engine"
                (click)="exportGlb()"
            >
                3D glTF
            </button>
        </div>
    `,
    styles: `
        @use 'variables' as *;

        .exports {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: $space-8;
        }

        .btn {
            padding-inline: $space-4;
        }
    `,
    changeDetection: ChangeDetectionStrategy.OnPush,
})
export class ExportComponent {
    public readonly scene = input.required<Scene>();
    public readonly projectName = input.required<string>();
    public readonly address = input<string | null>(null);
    /** Writes the shown floor as a binary glTF. */
    public readonly glb = input.required<() => Promise<Blob>>();

    private readonly alerts = inject(AlertService);
    protected readonly busy = signal(false);

    protected async exportPdf(): Promise<void> {
        const info = this.info();
        await this.export(async () => {
            const blob = await sheetsPdf([planSheet(this.scene(), info), ...roomScheduleSheets(this.scene(), info)]);
            downloadBlob(blob, this.filename('plan.pdf'));
        });
    }

    protected async exportElevations(): Promise<void> {
        await this.export(async () => {
            const sheets = elevationSheets(this.scene(), this.info());
            if (sheets.length === 0) {
                throw new Error('No room on this floor has walls to draw.');
            }
            downloadBlob(await sheetsPdf(sheets), this.filename('elevations.pdf'));
        });
    }

    protected async exportSvg(): Promise<void> {
        await this.export(async () => {
            const sheet = planSheet(this.scene(), this.info());
            downloadBlob(new Blob([sheet.svg], {type: 'image/svg+xml'}), this.filename('plan.svg'));
        });
    }

    protected async exportGlb(): Promise<void> {
        await this.export(async () => downloadBlob(await this.glb()(), this.filename('3D.glb')));
    }

    /** Runs one export with the buttons disabled; what goes wrong is shown as a toast. */
    private async export(run: () => Promise<void>): Promise<void> {
        this.busy.set(true);
        try {
            await run();
        } catch (e) {
            this.alerts.error(e instanceof Error ? e.message : 'The export failed.');
        } finally {
            this.busy.set(false);
        }
    }

    private info(): SheetInfo {
        return {projectName: this.projectName(), floorName: this.scene().floor.name, address: this.address(), date: new Date()};
    }

    private filename(suffix: string): string {
        return safeFilename(`${this.projectName()} – ${this.scene().floor.name} ${suffix}`);
    }
}
