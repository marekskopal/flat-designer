import {Sheet} from '@app/viewer/engine/plan-drawing';

/** Inter (SIL OFL, in assets/fonts) for the PDF's text: jsPDF's own fonts lack most accented letters. */
const Fonts = [
    {file: 'Inter-Regular.ttf', style: 'normal'},
    {file: 'Inter-SemiBold.ttf', style: 'bold'},
];

let fontData: Promise<string[]> | null = null;

/** The font files as base64, fetched once on the first export. */
function loadFonts(): Promise<string[]> {
    fontData ??= Promise.all(
        Fonts.map(async ({file}) => {
            const response = await fetch(new URL(`assets/fonts/${file}`, document.baseURI).href);
            if (!response.ok) {
                throw new Error('The PDF font could not be loaded.');
            }
            return toBase64(new Uint8Array(await response.arrayBuffer()));
        }),
    ).catch((e: unknown) => {
        fontData = null;
        throw e;
    });
    return fontData;
}

function toBase64(bytes: Uint8Array): string {
    let binary = '';
    for (let i = 0; i < bytes.length; i += 0x8000) {
        binary += String.fromCharCode(...bytes.subarray(i, i + 0x8000));
    }
    return btoa(binary);
}

/** The sheets as the pages of one PDF, each in its own size and orientation (jsPDF and svg2pdf are loaded on first use). */
export async function sheetsPdf(sheets: readonly Sheet[]): Promise<Blob> {
    const [{jsPDF}, {svg2pdf}, fonts] = await Promise.all([import('jspdf'), import('svg2pdf.js'), loadFonts()]);
    const orientation = (sheet: Sheet): 'landscape' | 'portrait' => (sheet.width > sheet.height ? 'landscape' : 'portrait');
    const pdf = new jsPDF({unit: 'mm', format: [sheets[0].width, sheets[0].height], orientation: orientation(sheets[0]), compress: true});
    Fonts.forEach(({file, style}, i) => {
        pdf.addFileToVFS(file, fonts[i]);
        pdf.addFont(file, 'Inter', style);
    });

    // svg2pdf reads the element's layout, so it is converted attached to the document (out of sight).
    const host = document.createElement('div');
    host.style.cssText = 'position: fixed; left: -10000px; top: 0; width: 0; height: 0; overflow: hidden;';
    document.body.appendChild(host);
    try {
        for (const [i, sheet] of sheets.entries()) {
            if (i > 0) {
                pdf.addPage([sheet.width, sheet.height], orientation(sheet));
            }
            host.innerHTML = sheet.svg;
            const svg = host.firstElementChild;
            if (svg === null) {
                continue;
            }
            await svg2pdf(svg, pdf, {x: 0, y: 0, width: sheet.width, height: sheet.height});
        }
    } finally {
        host.remove();
    }
    return pdf.output('blob');
}
