import {WallFinish} from '@app/models/scene';

/** A stretch of a wall face (mm along the wall's centre line) and the bands of its own lining it; null: none. */
export interface FaceBandStretch {
    from: number;
    to: number;
    bands: WallFinish[] | null;
}

/** Whether a band lines only a stretch of its face (`from` / `to`). */
export function isRangedBand(band: WallFinish): boolean {
    return band.from != null || band.to != null;
}

/**
 * The bands of a face's own (`leftFinishes` / `rightFinishes`) lining it at `along` (mm along the wall's centre line):
 * those without a stretch and those whose stretch holds it, in order. Null where it has none of its own there (the
 * room's bands, or a façade's default, line it): no list, or a list of stretched bands none of which reaches it (an
 * empty list keeps the face bare). Twin of `FaceBands::at()`.
 */
export function faceBandsAt(bands: readonly WallFinish[] | null | undefined, along: number): WallFinish[] | null {
    if (bands == null) {
        return null;
    }
    const lining = bands.filter((band) => !isRangedBand(band) || ((band.from ?? -Infinity) <= along && along <= (band.to ?? Infinity)));
    return lining.length === 0 && bands.length > 0 && bands.every(isRangedBand) ? null : lining;
}

/**
 * The stretches of `from`–`to` along the wall and the bands of the face's own lining each (`faceBandsAt()`), in order,
 * neighbours with the same bands joined. Twin of `FaceBands::stretches()`, kept identical by
 * `backend/tests/fixtures/face-bands.json`.
 */
export function faceBandStretches(bands: readonly WallFinish[] | null | undefined, from: number, to: number): FaceBandStretch[] {
    const ends = (bands ?? []).flatMap((band) => [band.from, band.to]).filter((end): end is number => end != null && end > from && end < to);
    const cuts = [...new Set([from, to, ...ends])].sort((x, y) => x - y);
    const stretches: FaceBandStretch[] = [];
    for (let i = 1; i < cuts.length; i++) {
        const lining = faceBandsAt(bands, (cuts[i - 1] + cuts[i]) / 2);
        const last = stretches.at(-1);
        if (last !== undefined && sameBands(last.bands, lining)) {
            last.to = cuts[i];
            continue;
        }
        stretches.push({from: cuts[i - 1], to: cuts[i], bands: lining});
    }
    return stretches;
}

function sameBands(a: readonly WallFinish[] | null, b: readonly WallFinish[] | null): boolean {
    return a === null || b === null ? a === b : a.length === b.length && a.every((band, i) => band === b[i]);
}
