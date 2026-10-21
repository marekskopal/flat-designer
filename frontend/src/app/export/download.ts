/** Saves a blob as a file through the browser. */
export function downloadBlob(blob: Blob, filename: string): void {
    const url = URL.createObjectURL(blob);
    const link = document.createElement('a');
    link.href = url;
    link.download = filename;
    link.click();
    URL.revokeObjectURL(url);
}

/** A file name without the characters file systems refuse. */
export function safeFilename(name: string): string {
    return name.replace(/[\\/:*?"<>|]/g, '-').trim();
}
