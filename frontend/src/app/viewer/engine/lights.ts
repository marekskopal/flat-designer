import * as THREE from 'three';

/**
 * The artificial lights of a scene (light parts of furniture definitions, LED strips): each three.js light is tagged
 * `userData.fixtureLight` with its intensity when on, and glowing bulbs are tagged `userData.bulb`. Switching them
 * off sets the intensity to 0 instead of removing them, so the shaders (compiled per number of lights) stay.
 */
export interface FixtureLight {
    /** Intensity while the lights are on. */
    intensity: number;
    /** Asked to cast shadows; only the first `MaxFixtureShadows` of them do. */
    shadow: boolean;
}

/** Shadow-casting fixture lights at most: a point light's shadow renders the scene six times. */
export const MaxFixtureShadows = 2;

/**
 * Light output per lumen in the scene's units. three.js's lights are physical (candela), but the viewer's daylight is not
 * (its sun is 2.4): unscaled, a 400 lm bulb would burn a room out at exposure 1. With this a lit room reads like a
 * photo taken at night, and by day the lamps barely add to the sun, as in reality.
 */
export const LumenScale = 0.08;

/**
 * How far a light's colour is pulled towards white: the viewer white-balances for warm light as a camera would, so a
 * 2200 K bulb looks warm rather than orange (the kelvin still orders warm and cool).
 */
export const WhiteBalance = 0.25;

/** Emissive intensity of a glowing bulb while its light is on. */
const BulbGlow = 4;

export type FixtureKind = 'point' | 'spot';

export interface FixtureOptions {
    kind: FixtureKind;
    lumens: number;
    kelvin: number;
    /** The spot's whole cone, degrees. */
    angle: number;
    shadow: boolean;
}

/**
 * The RGB colour of a light of `kelvin` (1000–40000; Tanner Helland's fit of the black-body curve), in sRGB.
 */
export function kelvinToColor(kelvin: number): THREE.Color {
    const t = Math.min(Math.max(kelvin, 1000), 40000) / 100;
    const r = t <= 66 ? 255 : 329.698727446 * Math.pow(t - 60, -0.1332047592);
    const g = t <= 66 ? 99.4708025861 * Math.log(t) - 161.1195681661 : 288.1221695283 * Math.pow(t - 60, -0.0755148492);
    const b = t >= 66 ? 255 : t <= 19 ? 0 : 138.5177312231 * Math.log(t - 10) - 305.0447927307;
    const channel = (value: number): number => Math.min(Math.max(value, 0), 255) / 255;
    return new THREE.Color().setRGB(channel(r), channel(g), channel(b), THREE.SRGBColorSpace);
}

/**
 * A light of `lumens` at the origin: a point light, or a spot shining down (−y) within `angle`. Created off; the engine
 * switches it with `applyFixtureLights()`.
 */
export function createFixtureLight(options: FixtureOptions): THREE.Group {
    const group = new THREE.Group();
    const color = balanced(options.kelvin);
    const lumens = Math.max(options.lumens, 0);
    let light: THREE.PointLight | THREE.SpotLight;
    if (options.kind === 'spot') {
        const half = THREE.MathUtils.degToRad(Math.min(Math.max(options.angle, 1), 179) / 2);
        light = new THREE.SpotLight(color, 0, 0, half, 0.4, 2);
        // A spot's power is intensity × π (candela over its cone in three.js's physically based units).
        light.target.position.set(0, -1, 0);
        group.add(light.target);
        group.userData['fixtureIntensity'] = (lumens * LumenScale) / Math.PI;
    } else {
        light = new THREE.PointLight(color, 0, 0, 2);
        group.userData['fixtureIntensity'] = (lumens * LumenScale) / (4 * Math.PI);
    }
    light.shadow.mapSize.set(512, 512);
    light.shadow.bias = -0.002;
    light.shadow.camera.near = 0.05;
    light.userData['fixtureLight'] = {intensity: group.userData['fixtureIntensity'] as number, shadow: options.shadow} satisfies FixtureLight;
    group.add(light);
    return group;
}

/** The light's colour as the viewer shows it: `kelvinToColor()` white-balanced. */
function balanced(kelvin: number): THREE.Color {
    return kelvinToColor(kelvin).lerp(new THREE.Color('#ffffff'), WhiteBalance);
}

/** Tags an existing light (an LED strip's area light) as a fixture with its intensity when on. */
export function markFixtureLight(light: THREE.Light, intensity: number): void {
    light.userData['fixtureLight'] = {intensity, shadow: false} satisfies FixtureLight;
    light.intensity = 0;
}

/** A glowing bulb's material in the light's colour; dark until the lights are switched on. */
export function bulbMaterial(kelvin: number): THREE.MeshStandardMaterial {
    return new THREE.MeshStandardMaterial({color: '#f4f1ea', emissive: balanced(kelvin), emissiveIntensity: 0, roughness: 0.25});
}

/** Tags a mesh as a bulb that glows while the lights are on. */
export function markBulb(mesh: THREE.Mesh): void {
    mesh.userData['bulb'] = true;
}

/**
 * Switches the fixture lights under `root` on or off and gives the first `MaxFixtureShadows` shadow-asking ones their
 * shadows. Returns how many lights are on, e.g. for tests.
 */
export function applyFixtureLights(root: THREE.Object3D, on: boolean): number {
    let shadows = 0;
    let lit = 0;
    root.traverse((o) => {
        const fixture = o.userData['fixtureLight'] as FixtureLight | undefined;
        if (o instanceof THREE.Light && fixture !== undefined) {
            o.intensity = on ? fixture.intensity : 0;
            const cast = on && fixture.shadow && shadows < MaxFixtureShadows;
            if (cast) {
                shadows++;
            }
            o.castShadow = cast;
            lit += on ? 1 : 0;
        }
        if (o instanceof THREE.Mesh && o.userData['bulb'] === true && o.material instanceof THREE.MeshStandardMaterial) {
            o.material.emissiveIntensity = on ? BulbGlow : 0;
        }
    });
    return lit;
}
