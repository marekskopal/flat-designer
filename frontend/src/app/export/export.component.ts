import {formatDate} from '@angular/common';
import {ChangeDetectionStrategy, Component, computed, inject, input, LOCALE_ID, signal} from '@angular/core';
import {DefaultLocale, toLocale} from '@app/core/locale';
import {decimalSeparator} from '@app/core/number-format';
import {ElectricalCategory, ElectricalSymbols} from '@app/models/electrical-schedule';
import {Scene} from '@app/models/scene';
import {AlertService} from '@app/services/alert.service';
import {DateFormats} from '@app/shared/i18n/date-text.pipe';
import {hasElectricalPoints} from '@app/viewer/engine/electrical';
import {elevationSheets} from '@app/viewer/engine/elevations';
import {planSheet, roomScheduleSheets} from '@app/viewer/engine/plan-drawing';
import {facadeSheets, SectionCut, sectionSheets} from '@app/viewer/engine/sections';
import {Sheet, SheetInfo} from '@app/viewer/engine/sheet-svg';
import {EnglishSheetTexts} from '@app/viewer/engine/sheet-texts';
import {CompassSide, SheetTexts} from '@app/viewer/engine/sheet-texts';
import {TranslatePipe, TranslateService} from '@ngx-translate/core';

import {downloadBlob, safeFilename} from './download';
import {sheetsPdf} from './sheets-pdf';

/** ms between the SVG files of one export. */
const DownloadSpacing = 400;

const ElectricalCategories: readonly ElectricalCategory[] = ['light', 'socket', 'switch', 'data', 'plumbing'];
const CompassSides: readonly CompassSide[] = ['North', 'East', 'South', 'West'];

/**
 * The viewer panel's exports of the floor as shown (its variants): the plan on A4 as a PDF, with the room schedule on
 * the following page, or the plan page alone as an SVG; the electrical plan (when the floor has electrical points);
 * the rooms' wall elevations as a PDF; and the 3D model as glTF
 * (`glb`, made by the viewer). The building drawings take the shown floor with the floors below it (`building`):
 * the façades on A3 and a section along each of their section lines, as a PDF or SVG files (one per sheet).
 */
@Component({
    selector: 'fd-export',
    imports: [TranslatePipe],
    template: `
        <div class="exports">
            <button type="button" class="btn btn--ghost" [disabled]="busy()" [title]="'export.planPdfTitle' | translate" (click)="exportPdf()">
                {{ 'export.planPdf' | translate }}
            </button>
            <button type="button" class="btn btn--ghost" [disabled]="busy()" [title]="'export.planSvgTitle' | translate" (click)="exportSvg()">
                {{ 'export.planSvg' | translate }}
            </button>
            <button
                type="button"
                class="btn btn--ghost"
                [disabled]="busy()"
                [title]="'export.elevationsPdfTitle' | translate"
                (click)="exportElevations()"
            >
                {{ 'export.elevationsPdf' | translate }}
            </button>
            @if (hasElectrical()) {
                <button
                    type="button"
                    class="btn btn--ghost"
                    [disabled]="busy()"
                    [title]="'export.electricalPdfTitle' | translate"
                    (click)="exportElectrical()"
                >
                    {{ 'export.electricalPdf' | translate }}
                </button>
            }
            <button
                type="button"
                class="btn btn--ghost"
                [disabled]="busy()"
                [title]="'export.facadesPdfTitle' | translate"
                (click)="exportFacades('pdf')"
            >
                {{ 'export.facadesPdf' | translate }}
            </button>
            <button
                type="button"
                class="btn btn--ghost"
                [disabled]="busy()"
                [title]="'export.facadesSvgTitle' | translate"
                (click)="exportFacades('svg')"
            >
                {{ 'export.facadesSvg' | translate }}
            </button>
            <button
                type="button"
                class="btn btn--ghost"
                [disabled]="busy()"
                [title]="'export.sectionsPdfTitle' | translate"
                (click)="exportSections('pdf')"
            >
                {{ 'export.sectionsPdf' | translate }}
            </button>
            <button
                type="button"
                class="btn btn--ghost"
                [disabled]="busy()"
                [title]="'export.sectionsSvgTitle' | translate"
                (click)="exportSections('svg')"
            >
                {{ 'export.sectionsSvg' | translate }}
            </button>
            <button
                type="button"
                class="btn btn--ghost"
                [disabled]="busy()"
                [title]="'export.glbTitle' | translate"
                (click)="exportGlb()"
            >
                {{ 'export.glb' | translate }}
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
    protected readonly hasElectrical = computed(() => hasElectricalPoints(this.scene()));

    private readonly alerts = inject(AlertService);
    private readonly translate = inject(TranslateService);
    private readonly locale = inject(LOCALE_ID);
    protected readonly busy = signal(false);

    protected async exportPdf(): Promise<void> {
        const info = this.info();
        const texts = this.sheetTexts();
        await this.export(async () => {
            const blob = await sheetsPdf([planSheet(this.scene(), info, undefined, texts), ...roomScheduleSheets(this.scene(), info, texts)]);
            downloadBlob(blob, this.filename(`${this.t('export.files.plan')}.pdf`));
        });
    }

    protected async exportElectrical(): Promise<void> {
        await this.export(async () => {
            const blob = await sheetsPdf([planSheet(this.scene(), this.info(), this.electricalSymbols(), this.sheetTexts())]);
            downloadBlob(blob, this.filename(`${this.t('export.files.electrical')}.pdf`));
        });
    }

    protected async exportElevations(): Promise<void> {
        await this.export(async () => {
            const sheets = elevationSheets(this.scene(), this.info(), this.sheetTexts());
            if (sheets.length === 0) {
                throw new Error(this.t('export.noWalls'));
            }
            downloadBlob(await sheetsPdf(sheets), this.filename(`${this.t('export.files.elevations')}.pdf`));
        });
    }

    protected async exportSvg(): Promise<void> {
        await this.export(async () => {
            const sheet = planSheet(this.scene(), this.info(), undefined, this.sheetTexts());
            downloadBlob(new Blob([sheet.svg], {type: 'image/svg+xml'}), this.filename(`${this.t('export.files.plan')}.svg`));
        });
    }

    protected async exportFacades(format: 'pdf' | 'svg'): Promise<void> {
        await this.export(async () => {
            const scenes = await this.building()();
            const sheets = facadeSheets(scenes, this.buildingInfo(scenes), this.scene().location?.northAngle ?? 0, this.sheetTexts());
            await this.download(sheets, format, this.t('export.files.facades'));
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
                throw new Error(this.t('export.noSections'));
            }
            await this.download(sectionSheets(scenes, cuts, this.buildingInfo(scenes), this.sheetTexts()), format, this.t('export.files.sections'));
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
            this.alerts.error(e instanceof Error ? e.message : this.t('export.failed'));
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

    private t(key: string, params?: Record<string, string>): string {
        return this.translate.instant(key, params) as string;
    }

    /** What the sheets write, in the language shown: its texts, decimal separator and dates. */
    private sheetTexts(): SheetTexts {
        const t = (key: string, params?: Record<string, string>): string => this.t(`sheets.${key}`, params);
        const locale = toLocale(this.locale) ?? DefaultLocale;
        return {
            decimal: decimalSeparator(this.locale),
            // English sheets keep their ISO dates (the README's plan images); other languages write their own.
            date: locale === 'en' ? EnglishSheetTexts.date : (date): string => formatDate(date, DateFormats[locale].date, this.locale),
            north: t('north'),
            floorPlan: t('floorPlan'),
            electricalPlan: t('electricalPlan'),
            categories: Object.fromEntries(
                ElectricalCategories.map((category) => [category, this.t(`enums.electricalCategory.${category}`)]),
            ) as Record<ElectricalCategory, string>,
            switchedLights: t('switchedLights'),
            roomSchedule: t('roomSchedule'),
            columns: {
                code: t('columns.code'),
                room: t('columns.room'),
                area: t('columns.area'),
                perimeter: t('columns.perimeter'),
                clearHeight: t('columns.clearHeight'),
                volume: t('columns.volume'),
                headroom: (metres) => t('columns.headroom', {metres}),
            },
            voidRow: (name) => t('voidRow', {name}),
            totals: {indoor: t('totals.indoor'), garage: t('totals.garage'), outdoor: t('totals.outdoor')},
            wallElevations: t('wallElevations'),
            finishes: t('finishes'),
            sill: (height) => t('sill', {height: String(height)}),
            facadeElevations: t('facadeElevations'),
            facade: Object.fromEntries(CompassSides.map((side) => [side, t(`facade.${side}`)])) as Record<CompassSide, string>,
            section: (name) => t('section', {name}),
            levels: {roof: t('levels.roof'), eaves: t('levels.eaves'), ridge: t('levels.ridge')},
        };
    }

    private filename(suffix: string): string {
        return safeFilename(`${this.projectName()} – ${this.scene().floor.name} ${suffix}`);
    }
}
