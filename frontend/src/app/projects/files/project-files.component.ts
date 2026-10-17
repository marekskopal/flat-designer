import {DatePipe} from '@angular/common';
import {ChangeDetectionStrategy, Component, DestroyRef, effect, inject, input, signal} from '@angular/core';
import {DomSanitizer, SafeResourceUrl} from '@angular/platform-browser';
import {ProjectFile} from '@app/models/project';
import {AlertService} from '@app/services/alert.service';
import {ProjectService} from '@app/services/project.service';

/** Types the browser can show inline; everything else is only downloaded. */
const PreviewableTypes = ['image/png', 'image/jpeg', 'image/gif', 'image/webp', 'application/pdf'];

interface Preview {
    file: ProjectFile;
    /** Object URL of the downloaded blob; revoked on close. */
    url: string;
    /** The same URL trusted for the PDF iframe. */
    resourceUrl: SafeResourceUrl;
}

/** Files of a project: drop or pick files to upload, preview images and PDFs, download, delete. */
@Component({
    selector: 'fd-project-files',
    imports: [DatePipe],
    templateUrl: './project-files.component.html',
    styleUrl: './project-files.component.scss',
    changeDetection: ChangeDetectionStrategy.OnPush,
    host: {'(document:keydown.escape)': 'closePreview()'},
})
export class ProjectFilesComponent {
    public readonly projectId = input.required<number>();

    private readonly projectService = inject(ProjectService);
    private readonly alerts = inject(AlertService);
    private readonly sanitizer = inject(DomSanitizer);

    protected readonly files = signal<ProjectFile[]>([]);
    protected readonly uploading = signal(0);
    protected readonly dragOver = signal(false);
    protected readonly preview = signal<Preview | null>(null);

    public constructor() {
        effect(() => void this.load(this.projectId()));
        inject(DestroyRef).onDestroy(() => this.closePreview());
    }

    protected canPreview(file: ProjectFile): boolean {
        return PreviewableTypes.includes(file.mimeType);
    }

    protected kind(file: ProjectFile): string {
        if (file.mimeType.startsWith('image/')) {
            return 'IMG';
        }
        if (file.mimeType === 'application/pdf') {
            return 'PDF';
        }
        const extension = file.filename.includes('.') ? file.filename.split('.').pop() : '';
        return (extension ?? '').slice(0, 4).toUpperCase() || 'FILE';
    }

    protected formatSize(bytes: number): string {
        if (bytes < 1024) {
            return `${bytes} B`;
        }
        if (bytes < 1024 * 1024) {
            return `${Math.round(bytes / 1024)} kB`;
        }
        return `${(bytes / 1024 / 1024).toFixed(1)} MB`;
    }

    protected onPick(event: Event): void {
        const picker = event.target as HTMLInputElement;
        void this.uploadAll(Array.from(picker.files ?? []));
        picker.value = '';
    }

    protected onDragOver(event: DragEvent): void {
        event.preventDefault();
        this.dragOver.set(true);
    }

    protected onDrop(event: DragEvent): void {
        event.preventDefault();
        this.dragOver.set(false);
        void this.uploadAll(Array.from(event.dataTransfer?.files ?? []));
    }

    protected async open(file: ProjectFile): Promise<void> {
        const url = await this.objectUrl(file);
        if (url === null) {
            return;
        }
        this.closePreview();
        // A blob: URL we just created from our own API response, so it is safe to frame.
        this.preview.set({file, url, resourceUrl: this.sanitizer.bypassSecurityTrustResourceUrl(url)});
    }

    protected async download(file: ProjectFile): Promise<void> {
        const url = await this.objectUrl(file);
        if (url === null) {
            return;
        }
        const link = document.createElement('a');
        link.href = url;
        link.download = file.filename;
        link.click();
        setTimeout(() => URL.revokeObjectURL(url), 1000);
    }

    protected async delete(file: ProjectFile): Promise<void> {
        if (!confirm(`Delete "${file.filename}"?`)) {
            return;
        }
        try {
            await this.projectService.deleteFile(this.projectId(), file.id);
            this.files.update((all) => all.filter((f) => f.id !== file.id));
        } catch {
            // The error interceptor shows the toast.
        }
    }

    protected closePreview(): void {
        const current = this.preview();
        if (current !== null) {
            URL.revokeObjectURL(current.url);
            this.preview.set(null);
        }
    }

    private async uploadAll(files: File[]): Promise<void> {
        for (const file of files) {
            this.uploading.update((n) => n + 1);
            try {
                const uploaded = await this.projectService.uploadFile(this.projectId(), file);
                this.files.update((all) => [...all, uploaded].sort((a, b) => a.filename.localeCompare(b.filename)));
            } catch {
                // The error interceptor shows the toast; go on with the next file.
            } finally {
                this.uploading.update((n) => n - 1);
            }
        }
        if (files.length > 0) {
            this.alerts.success(files.length === 1 ? 'File uploaded.' : `${files.length} files processed.`);
        }
    }

    private async objectUrl(file: ProjectFile): Promise<string | null> {
        try {
            return URL.createObjectURL(await this.projectService.downloadFile(this.projectId(), file.id));
        } catch {
            return null;
        }
    }

    private async load(projectId: number): Promise<void> {
        try {
            this.files.set(await this.projectService.listFiles(projectId));
        } catch {
            // The error interceptor shows the toast.
        }
    }
}
