import {ChangeDetectionStrategy, Component, computed, input} from '@angular/core';

/** A circle as path data (the icons are drawn with paths only). */
const circle = (cx: number, cy: number, r: number): string => `M${cx - r} ${cy}a${r} ${r} 0 1 0 ${2 * r} 0a${r} ${r} 0 1 0 ${-2 * r} 0`;

/** A rectangle as path data, with rounded corners when `rx` is given. */
const rect = (x: number, y: number, w: number, h: number, rx = 0): string =>
    rx === 0
        ? `M${x} ${y}h${w}v${h}h${-w}z`
        : `M${x + rx} ${y}h${w - 2 * rx}a${rx} ${rx} 0 0 1 ${rx} ${rx}v${h - 2 * rx}a${rx} ${rx} 0 0 1 ${-rx} ${rx}h${-(w - 2 * rx)}` +
          `a${rx} ${rx} 0 0 1 ${-rx} ${-rx}v${-(h - 2 * rx)}a${rx} ${rx} 0 0 1 ${rx} ${-rx}z`;

/**
 * The app's icons: 24 × 24 strokes in the style of Lucide (ISC licence, https://lucide.dev); some are Lucide's own,
 * the building ones (walls, rooms, railings, voids, sections) are drawn for this app.
 */
const Icons = {
    cube: [
        'M21 8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16Z',
        'm3.3 7 8.7 5 8.7-5',
        'M12 22V12',
    ],
    plan: [rect(3, 3, 18, 18, 1), 'M3 11h7v10M10 3v4M14 11h7M14 11v3'],
    walk: [circle(13, 4, 2), 'm7 22 3-7 3 3v4', 'm6 12 3-4 4 1 3 4 3 1', 'm10 15 1-6'],
    pointer: ['M4.04 4.69a.5.5 0 0 1 .65-.65l16 6.5a.5.5 0 0 1-.06.95l-6.13 1.58a2 2 0 0 0-1.43 1.43l-1.58 6.13a.5.5 0 0 1-.95.06z'],
    wall: ['M4 20V8h9v6h7', circle(4, 20, 1.6), circle(4, 8, 1.6), circle(13, 8, 1.6), circle(13, 14, 1.6), circle(20, 14, 1.6)],
    roomFromWalls: [rect(3, 3, 18, 18), 'M8 8h2M14 8h2v2M16 14v2h-2M10 16H8v-2M8 10V8', 'M12 11v2M11 12h2'],
    railing: ['M2 6h20', 'M4 6v14M20 6v14M8 6v14M12 6v14M16 6v14'],
    ruler: [
        'M21.3 15.3a2.4 2.4 0 0 1 0 3.4l-2.6 2.6a2.4 2.4 0 0 1-3.4 0L2.7 8.7a2.4 2.4 0 0 1 0-3.4l2.6-2.6a2.4 2.4 0 0 1 3.4 0Z',
        'm14.5 12.5 2-2M11.5 9.5l2-2M8.5 6.5l2-2M17.5 15.5l2-2',
    ],
    comment: ['M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z'],
    plus: ['M5 12h14M12 5v14'],
    undo: ['M9 14 4 9l5-5', 'M4 9h10.5a5.5 5.5 0 0 1 0 11H11'],
    redo: ['m15 14 5-5-5-5', 'M20 9H9.5a5.5 5.5 0 0 0 0 11H13'],
    share: [circle(18, 5, 3), circle(6, 12, 3), circle(18, 19, 3), 'm8.6 13.5 6.8 4M15.4 6.5l-6.8 4'],
    pencil: ['M21.17 6.81a1 1 0 0 0-3.98-3.98L3.84 16.17a2 2 0 0 0-.5.83l-1.32 4.35a.5.5 0 0 0 .62.62l4.35-1.32a2 2 0 0 0 .83-.5z', 'm15 5 4 4'],
    check: ['M20 6 9 17l-5-5'],
    eye: ['M2.06 12.35a1 1 0 0 1 0-.7 10.75 10.75 0 0 1 19.88 0 1 1 0 0 1 0 .7 10.75 10.75 0 0 1-19.88 0', circle(12, 12, 3)],
    layers: ['m12 2 10 5-10 5L2 7z', 'm2 17 10 5 10-5', 'm2 12 10 5 10-5'],
    rooms: [rect(3, 3, 18, 18, 1), 'M3 12h9v9M12 3v5M16 12h5'],
    camera: ['M14.5 4h-5L7 7H4a2 2 0 0 0-2 2v9a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2V9a2 2 0 0 0-2-2h-3z', circle(12, 13, 3)],
    variants: [rect(3, 3, 8, 8, 1), rect(13, 13, 8, 8, 1), 'M11 7h4a2 2 0 0 1 2 2v4', 'm15 11 2 2 2-2'],
    history: ['M3 12a9 9 0 1 0 9-9 9.75 9.75 0 0 0-6.74 2.74L3 8', 'M3 3v5h5M12 7v5l4 2'],
    image: [rect(3, 3, 18, 18, 2), circle(9, 9, 2), 'm21 15-3.1-3.1a2 2 0 0 0-2.8 0L6 21'],
    download: ['M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4', 'm7 10 5 5 5-5M12 15V3'],
    copy: [rect(8, 8, 14, 14, 2), 'M4 16a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h10a2 2 0 0 1 2 2'],
    trash: ['M3 6h18M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2M10 11v6M14 11v6'],
    group: ['M3 7V5a2 2 0 0 1 2-2h2M17 3h2a2 2 0 0 1 2 2v2M21 17v2a2 2 0 0 1-2 2h-2M7 21H5a2 2 0 0 1-2-2v-2', rect(7, 7, 10, 10, 1)],
    chevronDown: ['m6 9 6 6 6-6'],
    close: ['M18 6 6 18M6 6l12 12'],
    sun: [circle(12, 12, 4), 'M12 2v2M12 20v2M4.9 4.9l1.4 1.4M17.7 17.7l1.4 1.4M2 12h2M20 12h2M6.3 17.7l-1.4 1.4M19.1 4.9l-1.4 1.4'],
    moon: ['M12 3a6 6 0 0 0 9 9 9 9 0 1 1-9-9Z'],
    bulb: ['M15 14c.2-1 .7-1.7 1.5-2.5 1-.9 1.5-2.2 1.5-3.5A6 6 0 0 0 6 8c0 1 .2 2.2 1.5 3.5.7.7 1.3 1.5 1.5 2.5', 'M9 18h6M10 22h4'],
    sparkle: ['M12 3l1.9 5.1L19 10l-5.1 1.9L12 17l-1.9-5.1L5 10l5.1-1.9z', 'M19 17v4M17 19h4'],
    ceiling: ['M2 5h20', 'M5 5v4h14V5', 'M12 9v4', circle(12, 15, 2), 'M3 21h18'],
    roof: ['m2 13 10-9 10 9', 'M5 11v10h14V11'],
    tag: [
        'M12.6 2.6A2 2 0 0 0 11.2 2H4a2 2 0 0 0-2 2v7.2a2 2 0 0 0 .6 1.4l8.7 8.7a2.4 2.4 0 0 0 3.4 0l6.6-6.6a2.4 2.4 0 0 0 0-3.4z',
        circle(7.5, 7.5, 1),
    ],
    dimensions: ['M3 7v10M21 7v10M3 12h18M7 9l-4 3 4 3M17 9l4 3-4 3'],
    cut: ['M3 10h3M9 10h3M15 10h3M21 10h0', 'M5 21v-7M19 21v-7M5 6V3M19 6V3'],
    door: [
        'M13 4h3a2 2 0 0 1 2 2v14M2 20h3M13 20h9M10 12v.01',
        'M13 4.56v16.16a.5.5 0 0 1-.68.47L5 19V5.98a1 1 0 0 1 .68-.95l6-2A1 1 0 0 1 13 4.56z',
    ],
    window: [rect(4, 3, 16, 18, 1), 'M12 3v18M4 12h16'],
    stairs: ['M3 21h5v-5h5v-5h5V6h3'],
    column: ['M6 3h12M6 21h12M9 3v18M15 3v18'],
    void: ['M4 4h3M10 4h4M17 4h3v3M20 10v4M20 17v3h-3M14 20h-4M7 20H4v-3M4 14v-4M4 7V4', 'M4 4l16 16M20 4 4 20'],
    plot: ['M3 7l6-3 6 3 6-3v13l-6 3-6-3-6 3z', 'M9 4v13M15 7v13'],
    section: ['M4 12h4M10 12h1M13 12h1M16 12h4', 'M4 12V6l3 3M20 12V6l-3 3'],
    chair: [
        'M19 9V6a2 2 0 0 0-2-2H7a2 2 0 0 0-2 2v3',
        'M3 16a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-5a2 2 0 0 0-4 0v1.5a.5.5 0 0 1-.5.5h-9a.5.5 0 0 1-.5-.5V11a2 2 0 0 0-4 0z',
        'M5 18v2M19 18v2',
    ],
    cabinet: [rect(4, 3, 16, 16, 1), 'M12 3v16M10 10v2M14 10v2M6 19v2M18 19v2'],
    box: [
        'M11 21.7a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16V8a2 2 0 0 0-1-1.7l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.7z',
        'M12 22V12M3.3 7l7.7 4.5a2 2 0 0 0 2 0L20.7 7M7.5 4.3l9 5.1',
    ],
    more: [circle(5, 12, 1), circle(12, 12, 1), circle(19, 12, 1)],
    target: [circle(12, 12, 8), 'M12 2v4M12 18v4M2 12h4M18 12h4'],
    keyboard: [rect(2, 5, 20, 14, 2), 'M6 9h.01M10 9h.01M14 9h.01M18 9h.01M6 13h.01M18 13h.01M8 16h8'],
    folder: ['M20 20a2 2 0 0 0 2-2V8a2 2 0 0 0-2-2h-7.9a2 2 0 0 1-1.7-.9l-.8-1.2A2 2 0 0 0 7.9 3H4a2 2 0 0 0-2 2v13a2 2 0 0 0 2 2Z'],
    pin: ['M20 10c0 5-5.5 10.2-7.4 11.8a1 1 0 0 1-1.2 0C9.5 20.2 4 15 4 10a8 8 0 0 1 16 0', circle(12, 10, 3)],
    info: [circle(12, 12, 10), 'M12 16v-4M12 8h.01'],
    help: [circle(12, 12, 10), 'M9.09 9a3 3 0 0 1 5.83 1c0 2-3 3-3 3', 'M12 17h.01'],
    search: [circle(11, 11, 8), 'm21 21-4.3-4.3'],
    rotate: ['M21 12a9 9 0 1 1-9-9c2.52 0 4.93 1 6.74 2.74L21 8', 'M21 3v5h-5'],
    refresh: ['M3 12a9 9 0 0 1 9-9 9.75 9.75 0 0 1 6.74 2.74L21 8', 'M21 3v5h-5', 'M21 12a9 9 0 0 1-9 9 9.75 9.75 0 0 1-6.74-2.74L3 16', 'M8 16H3v5'],
    table: ['M3 8h18M5 8v12M19 8v12M3 12h18'],
    bed: ['M2 4v16M2 8h18a2 2 0 0 1 2 2v10M2 17h20M6 8v9'],
    tree: ['M12 22v-6', 'M12 2 6 10h3l-4 6h14l-4-6h3z'],
    bath: ['M9 6 6.5 3.5a1.5 1.5 0 0 0-1-.5C4.7 3 4 3.7 4 4.5V17a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-5', 'M2 12h20M7 19v2M17 19v2'],
    plug: ['M12 22v-5M9 8V2M15 8V2M18 8v5a4 4 0 0 1-4 4h-4a4 4 0 0 1-4-4V8z'],
    ungroup: [rect(5, 4, 8, 6, 1), rect(11, 14, 8, 6, 1)],
} satisfies Record<string, readonly string[]>;

export type IconName = keyof typeof Icons;

/** An icon drawn in the current text colour, `size` px square (18 by default). */
@Component({
    selector: 'fd-icon',
    template: `
        <svg
            viewBox="0 0 24 24"
            [attr.width]="size()"
            [attr.height]="size()"
            fill="none"
            stroke="currentColor"
            stroke-width="1.75"
            stroke-linecap="round"
            stroke-linejoin="round"
            aria-hidden="true"
        >
            @for (d of paths(); track $index) {
                <path [attr.d]="d" />
            }
        </svg>
    `,
    styles: `
        :host {
            display: inline-flex;
            flex: none;
            line-height: 0;
        }
    `,
    changeDetection: ChangeDetectionStrategy.OnPush,
})
export class IconComponent {
    public readonly name = input.required<IconName>();
    public readonly size = input(18);

    protected readonly paths = computed(() => Icons[this.name()]);
}
