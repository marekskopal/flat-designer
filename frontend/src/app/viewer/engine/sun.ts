import {SiteLocation, SunTime} from '@app/models/scene';

/** Where the sun stands: `azimuth` degrees clockwise from true north, `elevation` degrees above the horizon. */
export interface SunAngles {
    azimuth: number;
    elevation: number;
}

/** The sun without a place or time: an afternoon sun from the south-west, as the viewer always had. */
export const DefaultSun: SunAngles = {azimuth: 180 + (Math.atan2(9, 12) * 180) / Math.PI, elevation: (Math.atan2(14, 15) * 180) / Math.PI};

/** The day of the month a `SunTime` stands for: the solstices and equinoxes fall about then. */
export const SunDay = 21;

const Rad = Math.PI / 180;

/** The orbit's numbers at a UTC instant, as NOAA's solar calculator takes them (degrees unless said). */
function orbit(instant: number): {meanLongitude: number; meanAnomaly: number; eccentricity: number; obliquity: number; apparentLongitude: number} {
    const julianDay = instant / 86_400_000 + 2_440_587.5;
    const t = (julianDay - 2_451_545) / 36_525;
    const meanLongitude = (((280.46646 + t * (36_000.76983 + t * 0.0003032)) % 360) + 360) % 360;
    const meanAnomaly = 357.52911 + t * (35_999.05029 - 0.0001537 * t);
    const eccentricity = 0.016708634 - t * (0.000042037 + 0.0000001267 * t);
    const centre =
        Math.sin(meanAnomaly * Rad) * (1.914602 - t * (0.004817 + 0.000014 * t)) +
        Math.sin(2 * meanAnomaly * Rad) * (0.019993 - 0.000101 * t) +
        Math.sin(3 * meanAnomaly * Rad) * 0.000289;
    const omega = 125.04 - 1934.136 * t;
    const apparentLongitude = meanLongitude + centre - 0.00569 - 0.00478 * Math.sin(omega * Rad);
    const meanObliquity = 23 + (26 + (21.448 - t * (46.815 + t * (0.00059 - t * 0.001813))) / 60) / 60;
    const obliquity = meanObliquity + 0.00256 * Math.cos(omega * Rad);
    return {meanLongitude, meanAnomaly, eccentricity, obliquity, apparentLongitude};
}

/** How far apparent solar time runs ahead of mean time at an instant, minutes (−14 to +16 over the year). */
export function equationOfTime(instant: number): number {
    const {meanLongitude, meanAnomaly, eccentricity, obliquity} = orbit(instant);
    const y = Math.tan((obliquity * Rad) / 2) ** 2;
    const l0 = meanLongitude * Rad;
    const m = meanAnomaly * Rad;
    return (
        (4 / Rad) *
        (y * Math.sin(2 * l0) -
            2 * eccentricity * Math.sin(m) +
            4 * eccentricity * y * Math.sin(m) * Math.cos(2 * l0) -
            0.5 * y * y * Math.sin(4 * l0) -
            1.25 * eccentricity * eccentricity * Math.sin(2 * m))
    );
}

/**
 * The sun's position seen from a place at a UTC instant (NOAA's solar calculator, without refraction): good to a
 * fraction of a degree, which is plenty for shadows.
 */
export function sunPosition(latitude: number, longitude: number, instant: number): SunAngles {
    const {obliquity, apparentLongitude} = orbit(instant);
    const declination = Math.asin(Math.sin(obliquity * Rad) * Math.sin(apparentLongitude * Rad));

    const utcMinutes = (((instant / 60_000) % 1440) + 1440) % 1440;
    const solarMinutes = (((utcMinutes + equationOfTime(instant) + 4 * longitude) % 1440) + 1440) % 1440;
    const hourAngle = (solarMinutes / 4 - 180) * Rad;
    const lat = latitude * Rad;

    const cosZenith = Math.sin(lat) * Math.sin(declination) + Math.cos(lat) * Math.cos(declination) * Math.cos(hourAngle);
    const elevation = 90 - Math.acos(Math.min(1, Math.max(-1, cosZenith))) / Rad;
    const fromSouth = Math.atan2(Math.sin(hourAngle), Math.cos(hourAngle) * Math.sin(lat) - Math.tan(declination) * Math.cos(lat)) / Rad;
    return {azimuth: (fromSouth + 540) % 360, elevation};
}

/** How far a time zone's clock is ahead of UTC at an instant, minutes; null for a zone the browser does not know. */
export function zoneOffsetMinutes(timeZone: string, instant: number): number | null {
    let parts: Intl.DateTimeFormatPart[];
    try {
        parts = new Intl.DateTimeFormat('en-US', {
            timeZone,
            hourCycle: 'h23',
            year: 'numeric',
            month: 'numeric',
            day: 'numeric',
            hour: 'numeric',
            minute: 'numeric',
        }).formatToParts(new Date(instant));
    } catch {
        return null;
    }
    const part = (type: Intl.DateTimeFormatPartTypes): number => Number(parts.find((p) => p.type === type)?.value ?? 0);
    const wallClock = Date.UTC(part('year'), part('month') - 1, part('day'), part('hour'), part('minute'));
    return Math.round((wallClock - Math.floor(instant / 60_000) * 60_000) / 60_000);
}

/**
 * The UTC instant of a clock time on site in `year`: in the place's time zone (summer time included), or in local
 * apparent solar time (the sun due south at 12:00) from the longitude when it has none.
 */
export function sunInstant(location: SiteLocation, time: SunTime, year: number): number {
    const wallClock = Date.UTC(year, time.month - 1, SunDay) + time.hour * 3_600_000;
    if (location.timeZone !== null) {
        // The offset at the guess is the right one except within an hour of a clock change; a second look settles it.
        const first = zoneOffsetMinutes(location.timeZone, wallClock);
        if (first !== null) {
            const second = zoneOffsetMinutes(location.timeZone, wallClock - first * 60_000) ?? first;
            return wallClock - second * 60_000;
        }
    }
    const meanTime = wallClock - ((location.longitude ?? 0) / 15) * 3_600_000;
    return meanTime - equationOfTime(meanTime) * 60_000;
}

/** The sun of a place at a time on site; the default sun when either is missing. */
export function sunAt(location: SiteLocation | null | undefined, time: SunTime | null, year: number): SunAngles {
    if (location == null || location.latitude === null || location.longitude === null || time === null) {
        return DefaultSun;
    }
    return sunPosition(location.latitude, location.longitude, sunInstant(location, time, year));
}

/** The sun of a place at a time on site stands below the horizon; false without a place or time (the default sun). */
export function isSunDown(location: SiteLocation | null | undefined, time: SunTime | null, year: number): boolean {
    if (location == null || location.latitude === null || location.longitude === null || time === null) {
        return false;
    }
    return sunAt(location, time, year).elevation <= 0;
}

/**
 * Unit vector from the scene towards the sun, in scene axes (x east on the plan, y up, plan y along −z), with the
 * plan turned so that true north lies `northAngle` degrees clockwise from plan +y.
 */
export function sunDirection(northAngle: number, sun: SunAngles): {x: number; y: number; z: number} {
    const bearing = (northAngle + sun.azimuth) * Rad;
    const level = Math.cos(sun.elevation * Rad);
    return {x: Math.sin(bearing) * level, y: Math.sin(sun.elevation * Rad), z: -Math.cos(bearing) * level};
}

/**
 * How much daylight the sun's height leaves, 0 (night) to 1: the sun itself fades in over its first 6° above the
 * horizon, the sky over civil twilight (6° below to 6° above).
 */
export function daylightAt(elevation: number): {sun: number; sky: number} {
    const clamp = (value: number): number => Math.min(1, Math.max(0, value));
    return {sun: clamp(elevation / 6), sky: clamp((elevation + 6) / 12)};
}
