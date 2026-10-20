// Captures the README screenshots from the running app: every saved view of a project (applied with `?view=`, waiting
// until its models and pictures have loaded) and the pages around the viewer. Run it with `make screenshots`.
//
// Environment: FD_EMAIL, FD_PASSWORD (an account that may open the project) or FD_AUTHENTICATION (the JSON the app keeps
// in localStorage under "authentication", from a signed-in browser), FD_PROJECT (the project's id), FD_URL (default
// http://localhost:4400), FD_OUT (default ../docs/screenshots), FD_HEADED=1 to watch it work.
import {mkdir} from 'node:fs/promises';
import {resolve} from 'node:path';

import {chromium} from 'playwright';

const base = (process.env.FD_URL ?? 'http://localhost:4400').replace(/\/$/, '');
const projectId = Number(process.env.FD_PROJECT);
const out = resolve(process.env.FD_OUT ?? '../docs/screenshots');
const email = process.env.FD_EMAIL;
const password = process.env.FD_PASSWORD;
const signedIn = process.env.FD_AUTHENTICATION;
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
const views = await api(`/projects/${projectId}/views`, token);
const shareLinks = await api(`/projects/${projectId}/share-links`, token);

await mkdir(out, {recursive: true});
// WebGL through the GPU where there is one (headless Chromium would otherwise fall back to a slow software renderer).
const browser = await chromium.launch({headless: process.env.FD_HEADED !== '1', args: ['--use-angle=default', '--enable-gpu', '--ignore-gpu-blocklist']});
const context = await browser.newContext({viewport: {width: 1600, height: 1000}, deviceScaleFactor: 2, colorScheme: 'light'});
// Signed in from the first page on: the app keeps its tokens in localStorage.
await context.addInitScript((value) => localStorage.setItem('authentication', value), JSON.stringify(authentication));
const page = await context.newPage();

const shoot = async (file) => {
    const path = resolve(out, `${file}.png`);
    await page.screenshot({path});
    console.log(`  ${path}`);
};

console.log(`Saved views of project ${projectId}:`);
for (const view of views) {
    await page.goto(`${base}/projects/${projectId}/floors/${view.floorId}?view=${view.id}`);
    await page.waitForSelector('fd-viewer-page[data-view-ready]', {timeout: 120000});
    // A few frames for the shadows and the ambient occlusion to settle.
    await page.waitForTimeout(800);
    await shoot(`view-${slug(view.name)}`);
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
