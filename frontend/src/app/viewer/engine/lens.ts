/**
 * Camera lenses as photographers give them: a focal length in mm on 35 mm film, whose 36 mm frame width lies along the
 * view's longer side. A landscape view of a given lens covers the same angle across, however wide the window is.
 */

/** The width of 35 mm film's frame, mm. */
const FilmWidth = 36;

/** The vertical field of view (degrees) of a lens of `focalLength` mm in a view of `aspect` (width / height). */
export function lensFov(focalLength: number, aspect: number): number {
    const slope = FilmWidth / 2 / focalLength / Math.max(aspect, 1);
    return (2 * Math.atan(slope) * 180) / Math.PI;
}

/** The focal length (mm) of the lens that gives a vertical field of view of `fov` degrees in a view of `aspect`. */
export function fovFocalLength(fov: number, aspect: number): number {
    const slope = Math.tan((fov * Math.PI) / 360);
    return FilmWidth / 2 / slope / Math.max(aspect, 1);
}
