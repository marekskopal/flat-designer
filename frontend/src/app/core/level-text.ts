/** A level in metres as plans write them: ±0.000, +3.000, −0.150. */
export const levelText = (mm: number): string => `${mm === 0 ? '±' : mm > 0 ? '+' : '−'}${(Math.abs(mm) / 1000).toFixed(3)}`;
