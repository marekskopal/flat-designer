import {levelText} from '@app/core/level-text';
import {localDate} from '@app/core/local-date';

import {Vec2} from './plan-geometry';

// Writing the A4 drawing sheets (the plan, the room schedule, the wall elevations) as SVG text in paper mm.

/** A4 in mm. */
export const PaperLong = 297;
export const PaperShort = 210;
/** From the paper's edge to the frame, and from the frame to the drawing, mm. */
export const Margin = 10;
export const Padding = 6;
/** Height of the band at the foot of a drawing with the title block, mm. */
export const TitleBand = 24;
/** Text widths are estimated (no font metrics): one character is this many times the font size on average. */
export const CharWidth = 0.56;

export const Font = 'Inter, Helvetica, Arial, sans-serif';
export const Ink = '#2f2c28';
export const Muted = '#6b665e';
export const RuleColor = '#d8d3ca';

/** A page: its SVG and size in mm. */
export interface Sheet {
    width: number;
    height: number;
    svg: string;
}

/** What the title block says. */
export interface SheetInfo {
    projectName: string;
    floorName: string;
    /** mm above ±0.000, written after the floor's name. */
    floorElevation: number;
    address: string | null;
    date: Date;
}

export interface Style {
    fill?: string;
    stroke?: string;
    /** Stroke width, paper mm. */
    width?: number;
    /** Dash pattern, paper mm. */
    dash?: string;
}

export function sheetSvg(width: number, height: number, body: readonly string[]): string {
    return (
        `<svg xmlns="http://www.w3.org/2000/svg" width="${width}mm" height="${height}mm" viewBox="0 0 ${width} ${height}" ` +
        `font-family="${Font}"><rect x="0" y="0" width="${width}" height="${height}" fill="#ffffff"/>${body.join('')}</svg>`
    );
}

/** The frame around the drawing. */
export function frameSvg(width: number, height: number): string {
    return rect({x: Margin, y: Margin}, width - 2 * Margin, height - 2 * Margin, {stroke: Ink, width: 0.35});
}

/**
 * The band at the foot of the sheet with the title block at its right: the project, the floor (and address) and
 * `caption` (what the drawing is, its scale). The band's left part is the caller's.
 */
export function titleBlockSvg(width: number, height: number, info: SheetInfo, caption: string): string {
    const top = height - Margin - TitleBand;
    const bottom = height - Margin;
    const right = width - Margin;
    const blockLeft = right - 110;
    const x = blockLeft + 4;
    const name = `${info.floorName} ${levelText(info.floorElevation)}`;
    const floor = info.address === null || info.address === '' ? name : `${name} · ${info.address}`;
    return [
        line({x: Margin, y: top}, {x: right, y: top}, {stroke: Ink, width: 0.25}),
        line({x: blockLeft, y: top}, {x: blockLeft, y: bottom}, {stroke: Ink, width: 0.25}),
        text({x, y: top + 7}, truncate(info.projectName, 102, 4), 4, {bold: true}),
        text({x, y: top + 13}, truncate(floor, 102, 2.8), 2.8),
        text({x, y: top + 19}, truncate(`${caption} · ${localDate(info.date)} · Flat Designer`, 102, 2.2), 2.2, {color: Muted}),
    ].join('');
}

/** A polygon or polyline through paper points. */
export function path(points: readonly Vec2[], closed: boolean, style: Style): string {
    const d = points.map((p, i) => `${i === 0 ? 'M' : 'L'} ${n(p.x)} ${n(p.y)}`).join(' ');
    return `<path d="${d}${closed ? ' Z' : ''}"${styleAttributes(style, closed)}/>`;
}

export function line(a: Vec2, b: Vec2, style: Style): string {
    return path([a, b], false, style);
}

export function rect(at: Vec2, width: number, height: number, style: Style): string {
    return `<rect x="${n(at.x)}" y="${n(at.y)}" width="${n(width)}" height="${n(height)}"${styleAttributes(style, true)}/>`;
}

function styleAttributes(style: Style, closed: boolean): string {
    let attributes = ` fill="${closed ? (style.fill ?? 'none') : 'none'}"`;
    if (style.stroke !== undefined) {
        attributes += ` stroke="${style.stroke}" stroke-width="${style.width ?? 0.2}" stroke-linejoin="round"`;
        if (style.dash !== undefined) {
            attributes += ` stroke-dasharray="${style.dash}"`;
        }
    }
    return attributes;
}

export function text(
    at: Vec2,
    value: string,
    size: number,
    options: {bold?: boolean; color?: string; anchor?: 'start' | 'middle' | 'end'; rotate?: number} = {},
): string {
    const anchor = options.anchor === undefined || options.anchor === 'start' ? '' : ` text-anchor="${options.anchor}"`;
    const weight = options.bold === true ? ' font-weight="bold"' : '';
    const rotate = options.rotate === undefined ? '' : ` transform="rotate(${n(options.rotate)} ${n(at.x)} ${n(at.y)})"`;
    const attributes = `x="${n(at.x)}" y="${n(at.y)}" font-size="${n(size)}" fill="${options.color ?? Ink}"${anchor}${weight}${rotate}`;
    return `<text ${attributes}>${escapeXml(value)}</text>`;
}

/**
 * A north arrow about `at` (its centre), turned `northAngle` degrees clockwise from the paper's up as true north lies
 * from plan north; `scale` 1 is 9 mm long. The N stays upright beyond its tip.
 */
export function northArrowSvg(at: Vec2, northAngle: number, scale = 1): string {
    const s = scale;
    const corners: Vec2[] = [
        {x: at.x, y: at.y - 6 * s},
        {x: at.x + 2.6 * s, y: at.y + 3 * s},
        {x: at.x, y: at.y + 1.2 * s},
        {x: at.x - 2.6 * s, y: at.y + 3 * s},
    ];
    const arrow = `M ${corners.map((c) => `${n(c.x)} ${n(c.y)}`).join(' L ')} Z`;
    const turn = northAngle % 360 === 0 ? '' : ` transform="rotate(${n(northAngle)} ${n(at.x)} ${n(at.y)})"`;
    const radians = (northAngle * Math.PI) / 180;
    const letter = {x: at.x + Math.sin(radians) * 8.6 * s, y: at.y - Math.cos(radians) * 8.6 * s + 0.9 * s};
    return `<path d="${arrow}" fill="${Ink}"${turn}/>${text(letter, 'N', 2.6 * s, {bold: true, anchor: 'middle'})}`;
}

/** Estimated width of a text in mm. */
export function textWidth(value: string, size: number): number {
    return value.length * size * CharWidth;
}

/** The text, shortened with an ellipsis to fit `width` mm. */
export function truncate(value: string, width: number, size: number): string {
    const max = Math.floor(width / (size * CharWidth));
    return value.length <= max ? value : `${value.slice(0, Math.max(max - 1, 1)).trimEnd()}…`;
}

export function escapeXml(value: string): string {
    return value.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
}

/** A number for SVG, to 0.01 mm. */
export function n(value: number): string {
    return String(Math.round(value * 100) / 100);
}
