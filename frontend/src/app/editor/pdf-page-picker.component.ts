import {ChangeDetectionStrategy, Component, DestroyRef, effect, inject, input, output, signal, untracked} from '@angular/core';
import {PdfPage, ProjectFile} from '@app/models/project';
import {ProjectService} from '@app/services/project.service';
import {TranslatePipe, TranslateService} from '@ngx-translate/core';

/** The longer side of a page's thumbnail, px (the panel shows them about half as large, for sharp ones on HiDPI). */
const ThumbnailSize = 240;

/** The pages of a PDF as thumbnails, to pick the one to lay under the plan. */
@Component({
    selector: 'fd-pdf-page-picker',
    imports: [TranslatePipe],
    templateUrl: './pdf-page-picker.component.html',
    styleUrl: './pdf-page-picker.component.scss',
    changeDetection: ChangeDetectionStrategy.OnPush,
})
export class PdfPagePickerComponent {
    private readonly translate = inject(TranslateService);
    public readonly projectId = input.required<number>();
    public readonly file = input.required<ProjectFile>();
    public readonly pages = input.required<PdfPage[]>();
    /** All pages of the PDF; more than `pages` when the server reads only the first ones. */
    public readonly pageCount = input.required<number>();
    public readonly disabled = input(false);
    public readonly picked = output<number>();
    public readonly cancelled = output();

    /** Object URLs of the thumbnails loaded so far, by page. */
    protected readonly thumbnails = signal<ReadonlyMap<number, string>>(new Map());

    private readonly projects = inject(ProjectService);

    public constructor() {
        let urls: string[] = [];
        let generation = 0;
        const revoke = (): void => {
            urls.forEach((url) => URL.revokeObjectURL(url));
            urls = [];
        };
        effect(() => {
            const projectId = this.projectId();
            const fileId = this.file().id;
            const pages = this.pages();
            untracked(() => {
                revoke();
                this.thumbnails.set(new Map());
                const current = ++generation;
                // One at a time, in page order: each is a run of pdftoppm on the server.
                void (async (): Promise<void> => {
                    for (const page of pages.filter((p) => p.widthPx !== null)) {
                        try {
                            const blob = await this.projects.pdfPageThumbnail(projectId, fileId, page.page, ThumbnailSize);
                            if (current !== generation) {
                                return;
                            }
                            const url = URL.createObjectURL(blob);
                            urls.push(url);
                            this.thumbnails.update((map) => new Map(map).set(page.page, url));
                        } catch {
                            // The page stays a blank tile with its number.
                        }
                    }
                })();
            });
        });
        inject(DestroyRef).onDestroy(() => {
            generation++;
            revoke();
        });
    }

    protected aspect(page: PdfPage): string {
        return page.widthPx === null ? '1 / 1' : `${page.widthPt} / ${page.heightPt}`;
    }

    protected title(page: PdfPage): string {
        return page.widthPx === null
            ? this.translate.instant('editor.pdf.emptyPage', {page: page.page})
            : this.translate.instant('editor.pdf.pageTitle', {page: page.page, width: page.widthPx, height: page.heightPx});
    }
}
