import {ChangeDetectionStrategy, Component, computed, inject, input, signal} from '@angular/core';
import {ElectricalSymbols} from '@app/models/electrical-schedule';
import {Scene} from '@app/models/scene';
import {AlertService} from '@app/services/alert.service';
import {electricalPoints} from '@app/viewer/engine/electrical';
import {elevationSheets} from '@app/viewer/engine/elevations';
import {planSheet, roomScheduleSheets} from '@app/viewer/engine/plan-drawing';
import {facadeSheets, SectionCut, sectionSheets} from '@app/viewer/engine/sections';
import {Sheet, SheetInfo} from '@app/viewer/engine/sheet-svg';

import {downloadBlob, safeFilename} from './download';
import {sheetsPdf} from './sheets-pdf';

/** ms between the SVG files of one export. */
const DownloadSpacing = 400;

/**
 * The viewer panel's exports of the floor as shown (its variants): the plan on A4 as a PDF, with the room schedule on
 * the following page, or the plan page alone as an SVG; the electrical plan (FD-64, when the floor has electrical points);
 * the rooms' wall elevations as a PDF; and the 3D model as glTF
 * (`glb`, made by the viewer). The building drawings (FD-94) take the shown floor with the floors below it (`building`):
 * the façades on A3 and a section along each of their section lines, as a PDF or SVG files (one per sheet).
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
            @if (hasElectrical()) {
                <button
                    type="button"
                    class="btn btn--ghost"
                    [disabled]="busy()"
                    title="The plan with the lights, sockets, switches, data sockets and plumbing points and their elevations, for the electrician"
                    (click)="exportElectrical()"
                >
                    Electrical PDF
                </button>
            }
            <button
                type="button"
                class="btn btn--ghost"
                [disabled]="busy()"
                title="The building seen from all four sides, with this floor and those below"
                (click)="exportFacades('pdf')"
            >
                Façades PDF
            </button>
            <button
                type="button"
                class="btn btn--ghost"
                [disabled]="busy()"
                title="The façade sheets as vector drawings"
                (click)="exportFacades('svg')"
            >
                Façades SVG
            </button>
            <button
                type="button"
                class="btn btn--ghost"
                [disabled]="busy()"
                title="A section through the building along each section line (Add → Section line in the editor)"
                (click)="exportSections('pdf')"
            >
                Sections PDF
            </button>
            <button
                type="button"
                class="btn btn--ghost"
                [disabled]="busy()"
                title="The section sheets as vector drawings"
                (click)="exportSections('svg')"
            >
                Sections SVG
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
    /** The building for its drawings: the shown floor's scene with the floors below it (any order). */
    public readonly building = input<() => Promise<readonly Scene[]>>(() => Promise.resolve([this.scene()]));
    /** The symbol set of the electrical plan (the user's, a share guest's owner's). */
    public readonly electricalSymbols = input<ElectricalSymbols>('Iec');

    /** The floor has electrical points: the electrical plan is offered. */
    protected readonly hasElectrical = computed(() => electricalPoints(this.scene()).length > 0);

    private readonly alerts = inject(AlertService);
    protected readonly busy = signal(false);

    protected async exportPdf(): Promise<void> {
        const info = this.info();
        await this.export(async () => {
            const blob = await sheetsPdf([planSheet(this.scene(), info), ...roomScheduleSheets(this.scene(), info)]);
            downloadBlob(blob, this.filename('plan.pdf'));
        });
    }

    protected async exportElectrical(): Promise<void> {
        await this.export(async () => {
            const blob = await sheetsPdf([planSheet(this.scene(), this.info(), this.electricalSymbols())]);
            downloadBlob(blob, this.filename('electrical.pdf'));
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

    protected async exportFacades(format: 'pdf' | 'svg'): Promise<void> {
        await this.export(async () => {
            const scenes = await this.building()();
            const sheets = facadeSheets(scenes, this.buildingInfo(scenes), this.scene().location?.northAngle ?? 0);
            await this.download(sheets, format, 'façades');
        });
    }

    protected async exportSections(format: 'pdf' | 'svg'): Promise<void> {
        await this.export(async () => {
            const scenes = await this.building()();
            const cuts = scenes.flatMap((scene) =>
                (scene.sections ?? []).map((line): SectionCut => ({
                    name: line.name.length <= 2 ? `${line.name}–${line.name}` : line.name,
                    start: {x: line.startX, y: line.startY},
                    end: {x: line.endX, y: line.endY},
                    depth: line.depth,
                })),
            );
            if (cuts.length === 0) {
                throw new Error('There is no section line on this floor or below: add one in the editor (Add → Section line).');
            }
            await this.download(sectionSheets(scenes, cuts, this.buildingInfo(scenes)), format, 'sections');
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

    /**
     * The sheets as one PDF, or as an SVG file each (numbered when there are several), spaced out: browsers drop
     * downloads started all at once (the first may ask to allow multiple downloads).
     */
    private async download(sheets: readonly Sheet[], format: 'pdf' | 'svg', name: string): Promise<void> {
        if (format === 'pdf') {
            downloadBlob(await sheetsPdf([...sheets]), this.filename(`${name}.pdf`));
            return;
        }
        for (const [i, sheet] of sheets.entries()) {
            if (i > 0) {
                await new Promise((resolve) => setTimeout(resolve, DownloadSpacing));
            }
            const suffix = sheets.length === 1 ? `${name}.svg` : `${name} ${i + 1}.svg`;
            downloadBlob(new Blob([sheet.svg], {type: 'image/svg+xml'}), this.filename(suffix));
        }
    }

    /** The title block of the building drawings: the floors from the lowest to the shown one, at the shown one's level. */
    private buildingInfo(scenes: readonly Scene[]): SheetInfo {
        const info = this.info();
        const lowest = [...scenes].sort((a, b) => a.floor.level - b.floor.level)[0];
        if (lowest === undefined || lowest.floor.id === this.scene().floor.id) {
            return info;
        }
        return {...info, floorName: `${lowest.floor.name} – ${info.floorName}`};
    }

    private info(): SheetInfo {
        const floor = this.scene().floor;
        return {projectName: this.projectName(), floorName: floor.name, floorElevation: floor.elevation, address: this.address(), date: new Date()};
    }

    private filename(suffix: string): string {
        return safeFilename(`${this.projectName()} – ${this.scene().floor.name} ${suffix}`);
    }
}
