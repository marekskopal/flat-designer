import {SiteLocation} from '@app/models/scene';
import {describe, expect, it} from 'vitest';

import {daylightAt, DefaultSun, equationOfTime, isSunDown, sunAt, sunDirection, sunInstant, sunPosition, zoneOffsetMinutes} from './sun';

const Prague: SiteLocation = {northAngle: 0, latitude: 50.08, longitude: 14.42, timeZone: 'Europe/Prague'};

describe('sunPosition', () => {
    it('stands at the summer solstice noon of Prague as an almanac gives it', () => {
        // 21 June 2026, 11:04 UTC is about Prague's solar noon: 90 − 50.08 + 23.44.
        const sun = sunPosition(50.08, 14.42, Date.UTC(2026, 5, 21, 11, 4));
        expect(sun.elevation).toBeCloseTo(63.36, 0);
        expect(sun.azimuth).toBeGreaterThan(178);
        expect(sun.azimuth).toBeLessThan(182);
    });

    it('rises in the east and sets in the west', () => {
        const morning = sunPosition(50.08, 14.42, Date.UTC(2026, 2, 21, 6));
        const evening = sunPosition(50.08, 14.42, Date.UTC(2026, 2, 21, 16));
        expect(morning.azimuth).toBeGreaterThan(90);
        expect(morning.azimuth).toBeLessThan(135);
        expect(evening.azimuth).toBeGreaterThan(225);
        expect(evening.azimuth).toBeLessThan(270);
    });

    it('is overhead at the equator at an equinox noon and below the horizon at midnight', () => {
        expect(sunPosition(0, 0, Date.UTC(2026, 2, 20, 12, 7)).elevation).toBeGreaterThan(89);
        expect(sunPosition(0, 0, Date.UTC(2026, 2, 20, 0)).elevation).toBeLessThan(-80);
    });

    it('stands in the north at noon south of the tropics', () => {
        const sydney = sunPosition(-33.87, 151.21, Date.UTC(2026, 11, 21, 1, 50));
        expect(Math.min(sydney.azimuth, 360 - sydney.azimuth)).toBeLessThan(10);
    });
});

describe('sunInstant', () => {
    it('reads the clock in the time zone, summer time included', () => {
        expect(zoneOffsetMinutes('Europe/Prague', Date.UTC(2026, 0, 21, 12))).toBe(60);
        expect(zoneOffsetMinutes('Europe/Prague', Date.UTC(2026, 6, 21, 12))).toBe(120);
        expect(sunInstant(Prague, {month: 7, hour: 14.5}, 2026)).toBe(Date.UTC(2026, 6, 21, 12, 30));
        expect(sunInstant(Prague, {month: 1, hour: 14.5}, 2026)).toBe(Date.UTC(2026, 0, 21, 13, 30));
    });

    it('falls back to local solar time without a known zone', () => {
        // At 15° E mean noon is 11:00 UTC; the equation of time (about −7 min on 21 March) moves the true noon after it.
        const solar = sunInstant({...Prague, longitude: 15, timeZone: null}, {month: 3, hour: 12}, 2026);
        expect(equationOfTime(Date.UTC(2026, 2, 21, 11))).toBeCloseTo(-7.2, 0);
        expect(solar).toBeCloseTo(Date.UTC(2026, 2, 21, 11) - equationOfTime(Date.UTC(2026, 2, 21, 11)) * 60_000, -3);
        expect(sunPosition(50.08, 15, solar).azimuth).toBeCloseTo(180, 0);
        expect(zoneOffsetMinutes('Nowhere/Atlantis', 0)).toBeNull();
        expect(sunInstant({...Prague, longitude: 15, timeZone: 'Nowhere/Atlantis'}, {month: 3, hour: 12}, 2026)).toBe(solar);
    });
});

describe('sunAt', () => {
    it('keeps the default sun without a place or a time', () => {
        expect(sunAt(null, {month: 6, hour: 12}, 2026)).toEqual(DefaultSun);
        expect(sunAt({...Prague, latitude: null, longitude: null}, {month: 6, hour: 12}, 2026)).toEqual(DefaultSun);
        expect(sunAt(Prague, null, 2026)).toEqual(DefaultSun);
    });

    it('is low in a Prague winter afternoon and gone in its night', () => {
        expect(sunAt(Prague, {month: 12, hour: 15}, 2026).elevation).toBeLessThan(10);
        expect(sunAt(Prague, {month: 12, hour: 22}, 2026).elevation).toBeLessThan(0);
        expect(isSunDown(Prague, {month: 12, hour: 22}, 2026)).toBe(true);
        expect(isSunDown(Prague, {month: 12, hour: 15}, 2026)).toBe(false);
        expect(isSunDown(Prague, null, 2026)).toBe(false);
        expect(isSunDown(null, {month: 12, hour: 22}, 2026)).toBe(false);
    });
});

describe('sunDirection', () => {
    it('puts the default sun where the viewer always had it', () => {
        const direction = sunDirection(0, DefaultSun);
        const length = Math.hypot(9, 14, 12);
        expect(direction.x).toBeCloseTo(-9 / length, 6);
        expect(direction.y).toBeCloseTo(14 / length, 6);
        expect(direction.z).toBeCloseTo(12 / length, 6);
    });

    it('turns with the north angle', () => {
        // True north 90° clockwise from plan north: plan east. A sun in the true south then lies in plan west (−x).
        const direction = sunDirection(90, {azimuth: 180, elevation: 0});
        expect(direction.x).toBeCloseTo(-1, 6);
        expect(direction.z).toBeCloseTo(0, 6);
    });
});

describe('daylightAt', () => {
    it('fades the sun and the sky around the horizon', () => {
        expect(daylightAt(30)).toEqual({sun: 1, sky: 1});
        expect(daylightAt(3)).toEqual({sun: 0.5, sky: 0.75});
        expect(daylightAt(-3)).toEqual({sun: 0, sky: 0.25});
        expect(daylightAt(-10)).toEqual({sun: 0, sky: 0});
    });
});
