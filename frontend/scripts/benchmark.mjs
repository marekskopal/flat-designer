// Measures the rendering cost of a project's saved views (FD-58): each view is opened with `?view=…&stats`, and the
// engine renders frames back to back in each scenario (fast / presentation quality, day / night with the lights on, and
// presentation-moving: the plain frames rendered while the camera moves), waiting for the GPU after every frame, so
// the times are what a frame costs rather than the display's refresh rate.
// Run it with `make benchmark`. The results go to benchmarks/<date>-<commit>.json; compare them on the same machine only.
//
// Environment: FD_EMAIL, FD_PASSWORD (or FD_AUTHENTICATION) and FD_PROJECT as for scripts/screenshots.mjs, FD_VIEWS
// (only the saved views whose name starts with it), FD_SCENARIOS (comma-separated names, default all), FD_FRAMES
// (default 120), FD_RUNS (runs per scenario, the median kept; default 3), FD_BASELINE (an earlier result to compare
// with), FD_URL (default http://localhost:4400), FD_OUT (default benchmarks), FD_HEADED=1 to watch it work.
import {spawnSync} from 'node:child_process';
import {mkdir, readFile, writeFile} from 'node:fs/promises';
import {resolve} from 'node:path';

import {chromium} from 'playwright';

const base = (process.env.FD_URL ?? 'http://localhost:4400').replace(/\/$/, '');
const projectId = Number(process.env.FD_PROJECT);
const out = resolve(process.env.FD_OUT ?? 'benchmarks');
const email = process.env.FD_EMAIL;
const password = process.env.FD_PASSWORD;
const signedIn = process.env.FD_AUTHENTICATION;
const viewPrefix = process.env.FD_VIEWS ?? '';
const frames = Number(process.env.FD_FRAMES || 120);
const runs = Number(process.env.FD_RUNS || 3);
const baselineFile = process.env.FD_BASELINE;
if ((!signedIn && (!email || !password)) || !Number.isInteger(projectId) || projectId < 1) {
    console.error('Set FD_PROJECT and FD_EMAIL with FD_PASSWORD (or FD_AUTHENTICATION), e.g. make benchmark PROJECT=5 EMAIL=… PASSWORD=….');
    process.exit(1);
}

/** Frame time changes smaller than this (relative) are taken for noise. */
const Noise = 0.05;

const allScenarios = [
    {name: 'fast-day', quality: 'fast', night: false, lightsOn: false},
    {name: 'fast-night', quality: 'fast', night: true, lightsOn: true},
    {name: 'presentation-day', quality: 'presentation', night: false, lightsOn: false},
    {name: 'presentation-night', quality: 'presentation', night: true, lightsOn: true},
    // What orbiting costs in the presentation quality: the plain frames rendered until the camera stops.
    {name: 'presentation-moving', quality: 'presentation', night: false, lightsOn: false, moving: true},
];
const wanted = (process.env.FD_SCENARIOS ?? '').split(',').map((s) => s.trim()).filter((s) => s !== '');
const scenarios = wanted.length === 0 ? allScenarios : allScenarios.filter((s) => wanted.includes(s.name));
if (scenarios.length === 0) {
    console.error(`FD_SCENARIOS names none of ${allScenarios.map((s) => s.name).join(', ')}.`);
    process.exit(1);
}

const api = async (path, token, init = {}) => {
    const response = await fetch(`${base}/api${path}`, {
        ...init,
        headers: {'Content-Type': 'application/json', ...(token ? {Authorization: `Bearer ${token}`} : {}), ...init.headers},
    });
    if (!response.ok) {
        throw new Error(`${init.method ?? 'GET'} ${path}: ${response.status} ${await response.text()}`);
    }
    return response.json();
};

const git = (...args) => spawnSync('git', args, {encoding: 'utf8'}).stdout.trim();
const median = (values) => {
    const sorted = [...values].sort((a, b) => a - b);
    const middle = Math.floor(sorted.length / 2);
    return sorted.length % 2 === 1 ? sorted[middle] : (sorted[middle - 1] + sorted[middle]) / 2;
};

const authentication = signedIn
    ? JSON.parse(signedIn)
    : await api('/authentication/login', null, {method: 'POST', body: JSON.stringify({email, password})});
const token = authentication.accessToken;
const views = (await api(`/projects/${projectId}/views`, token)).filter((view) => view.name.startsWith(viewPrefix));
if (views.length === 0) {
    console.error(`Project ${projectId} has no saved views${viewPrefix ? ` starting with "${viewPrefix}"` : ''}.`);
    process.exit(1);
}

// WebGL through the GPU (headless Chromium would otherwise fall back to a slow software renderer); no frame rate cap.
const browser = await chromium.launch({
    headless: process.env.FD_HEADED !== '1',
    args: ['--use-angle=default', '--enable-gpu', '--ignore-gpu-blocklist', '--disable-gpu-vsync', '--disable-frame-rate-limit'],
});
// Pixel ratio 1, so the fast quality renders as on an ordinary screen; the presentation quality takes at least 2 itself.
const context = await browser.newContext({viewport: {width: 1600, height: 1000}, deviceScaleFactor: 1, colorScheme: 'light'});
await context.addInitScript((value) => localStorage.setItem('authentication', value), JSON.stringify(authentication));
const page = await context.newPage();

const commit = git('rev-parse', '--short', 'HEAD');
const dirty = git('status', '--porcelain') !== '';
const result = {date: new Date().toISOString(), commit: dirty ? `${commit}+dirty` : commit, project: projectId, frames, runs, gpu: '', rows: []};

for (const view of views) {
    console.log(`${view.name}`);
    await page.goto(`${base}/projects/${projectId}/floors/${view.floorId}?view=${view.id}&stats`);
    await page.waitForSelector('fd-viewer-page[data-view-ready]', {timeout: 120000});
    result.gpu ||= await page.evaluate(() => window.fdBench.gpu());
    // Frames rendered on demand: once the view has settled, an idle viewer renders none.
    await page.waitForTimeout(2000);
    const idleRenders = await page.evaluate(() => window.fdBench.stats().rendersPerSecond);
    console.log(`  idle                 ${idleRenders} renders/s`);
    for (const scenario of scenarios) {
        const measured = [];
        for (let run = 0; run < runs; run++) {
            const options = {frames, warmup: 20, orbit: true, quality: scenario.quality, night: scenario.night, lightsOn: scenario.lightsOn, moving: scenario.moving};
            measured.push(await page.evaluate((o) => window.fdBench.benchmark(o), options));
        }
        const kept = measured.toSorted((a, b) => a.median - b.median)[Math.floor(measured.length / 2)];
        const row = {
            view: view.name,
            scenario: scenario.name,
            median: kept.median,
            p95: median(measured.map((m) => m.p95)),
            min: Math.min(...measured.map((m) => m.min)),
            spread: (Math.max(...measured.map((m) => m.median)) - Math.min(...measured.map((m) => m.median))) / kept.median,
            calls: kept.render.calls,
            triangles: kept.render.triangles,
            programs: kept.render.programs,
            geometries: kept.render.geometries,
            textures: kept.render.textures,
            meshes: kept.scene.meshes,
            lights: kept.scene.lights,
            buildMs: kept.buildMs,
            idleRenders,
            size: `${kept.width}×${kept.height}`,
        };
        result.rows.push(row);
        console.log(`  ${scenario.name.padEnd(20)} ${row.median.toFixed(2)} ms (p95 ${row.p95.toFixed(2)}, runs ±${(row.spread * 50).toFixed(1)}%)`);
    }
}
await browser.close();

await mkdir(out, {recursive: true});
const file = resolve(out, `${result.date.slice(0, 10)}-${result.commit}.json`);
await writeFile(file, `${JSON.stringify(result, null, 4)}\n`);
console.log(`\nGPU: ${result.gpu}\nWritten ${file}\n`);

const baseline = baselineFile ? JSON.parse(await readFile(resolve(baselineFile), 'utf8')) : null;
if (baseline !== null && baseline.gpu !== result.gpu) {
    console.warn(`Warning: the baseline was measured on another GPU (${baseline.gpu}); the times do not compare.\n`);
}

/** A metric as `new`, or `old → new (±%)` against the baseline; `flag` marks frame times beyond the noise. */
const cell = (now, before, digits, flag) => {
    if (before === undefined) {
        return now.toFixed(digits);
    }
    const change = before === 0 ? 0 : (now - before) / before;
    const mark = flag && Math.abs(change) > Noise ? (change < 0 ? ' ✓' : ' ✗') : '';
    return `${before.toFixed(digits)} → ${now.toFixed(digits)} (${change >= 0 ? '+' : ''}${(change * 100).toFixed(1)}%)${mark}`;
};

const columns = ['view', 'scenario', 'median ms', 'p95 ms', 'calls', 'triangles k', 'programs', 'build ms', 'idle /s'];
const table = result.rows.map((row) => {
    const old = baseline?.rows.find((r) => r.view === row.view && r.scenario === row.scenario);
    return [
        row.view,
        row.scenario,
        cell(row.median, old?.median, 2, true),
        cell(row.p95, old?.p95, 2, true),
        cell(row.calls, old?.calls, 0, false),
        cell(row.triangles / 1000, old === undefined ? undefined : old.triangles / 1000, 0, false),
        cell(row.programs, old?.programs, 0, false),
        cell(row.buildMs, old?.buildMs, 0, false),
        cell(row.idleRenders, old?.idleRenders, 0, false),
    ];
});
const widths = columns.map((c, i) => Math.max(c.length, ...table.map((r) => r[i].length)));
const line = (cells) => `| ${cells.map((c, i) => c.padEnd(widths[i])).join(' | ')} |`;
console.log(line(columns));
console.log(line(widths.map((w) => '-'.repeat(w))));
table.forEach((r) => console.log(line(r)));
