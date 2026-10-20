// Captures the README screenshots from the running app: every saved view of a project (applied with `?view=`, waiting
// until its models and pictures have loaded), the editor with a room's inspector open, and the pages around the viewer.
// Run it with `make screenshots`. The plan underlay is hidden: it is a tracing aid, often someone else's drawing.
//
// Environment: FD_EMAIL, FD_PASSWORD (an account that may open the project) or FD_AUTHENTICATION (the JSON the app keeps
// in localStorage under "authentication", from a signed-in browser), FD_PROJECT (the project's id), FD_VIEWS (only the
// saved views whose name starts with it), FD_ROOM (the room to select for the editor shot, default the first one listed),
// FD_URL (default http://localhost:4400), FD_OUT (default ../docs/screenshots), FD_HEADED=1 to watch it work. With
// `cwebp` on the PATH, the images are saved as WebP FD_WIDTH pixels wide (default 1600), otherwise as PNG at 2×.
import {spawnSync} from 'node:child_process';
import {mkdir, unlink} from 'node:fs/promises';
import {resolve} from 'node:path';

import {chromium} from 'playwright';

const base = (process.env.FD_URL ?? 'http://localhost:4400').replace(/\/$/, '');
const projectId = Number(process.env.FD_PROJECT);
const out = resolve(process.env.FD_OUT ?? '../docs/screenshots');
const email = process.env.FD_EMAIL;
const password = process.env.FD_PASSWORD;
const signedIn = process.env.FD_AUTHENTICATION;
const viewPrefix = process.env.FD_VIEWS ?? '';
const editorRoom = process.env.FD_ROOM;
const width = Number(process.env.FD_WIDTH ?? 1600);
const webp = spawnSync('cwebp', ['-version']).status === 0;
if ((!signedIn && (!email || !password)) || !Number.isInteger(projectId) || projectId < 1) {
    console.error('Set FD_PROJECT and FD_EMAIL with FD_PASSWORD (or FD_AUTHENTICATION), e.g. make screenshots PROJECT=4 EMAIL=… PASSWORD=….');
    process.exit(1);
}

/** A file name from a view's name: lower case, dashes. */
const slug = (name) =>
    name
        .normalize('NFKD')
        .replace(/[̀-ͯ]/g, '')
        .toLowerCase()
        .replace(/[^a-z0-9]+/g, '-')
        .replace(/^-|-$/g, '');

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

const authentication = signedIn
    ? JSON.parse(signedIn)
    : await api('/authentication/login', null, {method: 'POST', body: JSON.stringify({email, password})});
const token = authentication.accessToken;
const views = (await api(`/projects/${projectId}/views`, token)).filter((view) => view.name.startsWith(viewPrefix));
const shareLinks = await api(`/projects/${projectId}/share-links`, token);

await mkdir(out, {recursive: true});
// WebGL through the GPU where there is one (headless Chromium would otherwise fall back to a slow software renderer).
const browser = await chromium.launch({headless: process.env.FD_HEADED !== '1', args: ['--use-angle=default', '--enable-gpu', '--ignore-gpu-blocklist']});
const context = await browser.newContext({viewport: {width: 1600, height: 1000}, deviceScaleFactor: 2, colorScheme: 'light'});
// Signed in from the first page on: the app keeps its tokens in localStorage.
await context.addInitScript((value) => localStorage.setItem('authentication', value), JSON.stringify(authentication));
const page = await context.newPage();

const shoot = async (file) => {
    const png = resolve(out, `${file}.png`);
    await page.screenshot({path: png});
    if (!webp) {
        console.log(`  ${png}`);
        return;
    }
    const path = resolve(out, `${file}.webp`);
    const converted = spawnSync('cwebp', ['-quiet', '-q', '82', '-resize', String(width), '0', png, '-o', path]);
    if (converted.status !== 0) {
        throw new Error(`cwebp ${png}: ${converted.stderr}`);
    }
    await unlink(png);
    console.log(`  ${path}`);
};

/** Hides the plan underlay if the page shows it (it is a display option, not part of a saved view). */
const hideUnderlay = async () => {
    const underlay = page.getByLabel('Plan underlay');
    if ((await underlay.count()) > 0 && (await underlay.isChecked())) {
        await underlay.uncheck();
        await page.waitForTimeout(300);
    }
};

console.log(`Saved views of project ${projectId}:`);
for (const view of views) {
    await page.goto(`${base}/projects/${projectId}/floors/${view.floorId}?view=${view.id}`);
    await page.waitForSelector('fd-viewer-page[data-view-ready]', {timeout: 120000});
    await hideUnderlay();
    // A few frames for the shadows and the ambient occlusion to settle.
    await page.waitForTimeout(800);
    await shoot(`view-${slug(view.name)}`);
}

// The editor: a room picked in the sidebar's list (which frames it), then edit mode with its inspector. It starts from
// the first saved view, so that the models and pictures have loaded.
if (views.length > 0) {
    console.log('Editor:');
    await page.goto(`${base}/projects/${projectId}/floors/${views[0].floorId}?view=${views[0].id}`);
    await page.waitForSelector('fd-viewer-page[data-view-ready]', {timeout: 120000});
    await hideUnderlay();
    const rooms = page.locator('button.room');
    const room = editorRoom ? rooms.filter({hasText: editorRoom}).first() : rooms.first();
    // A DOM click: the panel's footer may cover the row at the bottom of the scrolled list.
    await room.evaluate((button) => button.click());
    await page.getByRole('button', {name: 'Edit', exact: true}).click();
    await page.waitForSelector('fd-inspector');
    await page.waitForTimeout(1500);
    await shoot('editor');
}

/** Pages around the viewer; each waits for its own content. */
const pages = [
    {file: 'project', path: `/projects/${projectId}`, ready: 'fd-room-schedule'},
    {file: 'cut-list', path: `/projects/${projectId}/cut-list`, ready: 'table'},
    {file: 'furniture-library', path: `/furniture?projectId=${projectId}`, ready: 'table'},
];
const definitions = await api(`/furniture-definitions?projectId=${projectId}`, token);
const sofa = definitions.find((d) => d.name === 'Sofa') ?? definitions[0];
if (sofa) {
    pages.push({file: 'furniture-editor', path: `/furniture/${sofa.id}`, ready: 'fd-furniture-preview canvas, canvas'});
}
const link = shareLinks.find((l) => !l.expired);
if (link) {
    pages.push({file: 'share-link', path: `/s/${link.token}`, ready: 'fd-viewer canvas'});
}

console.log('Pages:');
for (const target of pages) {
    await page.goto(`${base}${target.path}`);
    await page.waitForSelector(target.ready, {timeout: 60000});
    await page.waitForLoadState('networkidle');
    await page.waitForTimeout(1500);
    await shoot(target.file);
}

await browser.close();
