/**
 * End-to-end smoke test of a running stack (`make smoke`, and the E2E workflow in CI), driven through the UI as a new
 * user would: sign up, create a project, draw four walls, make the room from them, put a door in a wall, share the
 * floor and open the link as a guest; then start a project from the example, download it as a project file and import
 * that. Needs sign-up open (SIGN_UP_ENABLED=1).
 *
 * Env: FD_URL (default http://localhost:4400), FD_APP_PATH (the app's path, e.g. /app), FD_OUT (where a failure's
 * screenshot goes, default smoke-results), FD_SOFTWARE_GL=1 (WebGL through SwiftShader, for machines without a GPU),
 * FD_HEADED=1.
 */
import {mkdir} from 'node:fs/promises';
import {resolve} from 'node:path';
import {chromium} from 'playwright';

const origin = process.env.FD_URL || 'http://localhost:4400';
const base = origin + (process.env.FD_APP_PATH || '');
const out = resolve(process.env.FD_OUT || 'smoke-results');
const stamp = Date.now().toString(36);
const user = {name: 'Smoke Test', email: `smoke-${stamp}@example.com`, password: `Smoke-${stamp}-Pass1`};
const timeout = 60_000;

const gpu = process.env.FD_SOFTWARE_GL === '1'
    ? ['--use-angle=swiftshader', '--enable-unsafe-swiftshader']
    : ['--use-angle=default', '--enable-gpu', '--ignore-gpu-blocklist'];
const browser = await chromium.launch({headless: process.env.FD_HEADED !== '1', args: gpu});
const context = await browser.newContext({viewport: {width: 1400, height: 900}});
context.setDefaultTimeout(timeout);
const page = await context.newPage();
const errors = [];
const watch = (target, label) => {
    target.on('pageerror', (error) => errors.push(`${label}: ${error.message}`));
    target.on('console', (message) => message.type() === 'error' && errors.push(`${label}: ${message.text()}`));
};
watch(page, 'app');

let current = 'start';
const step = async (name, run) => {
    current = name;
    const started = Date.now();
    await run();
    console.log(`✓ ${name} (${((Date.now() - started) / 1000).toFixed(1)} s)`);
};

/** The API's answer to the request the action makes (a write the step waits for). */
const answer = async (method, pattern, action) => {
    const [response] = await Promise.all([
        page.waitForResponse((r) => r.request().method() === method && pattern.test(new URL(r.url()).pathname), {timeout}),
        action(),
    ]);
    if (!response.ok()) {
        throw new Error(`${method} ${response.url()}: ${response.status()} ${await response.text()}`);
    }
    return response.json().catch(() => null);
};

/** The 3D view's canvas, once the engine draws. */
const canvas = async () => {
    const element = page.locator('canvas').first();
    await element.waitFor();
    const box = await element.boundingBox();
    if (box === null) {
        throw new Error('The 3D view has no canvas.');
    }
    return box;
};

/** A click on the 3D view as a hand makes it: the pointer moves there first, so the tools see where it points. */
const clickAt = async (x, y) => {
    await page.mouse.move(x - 8, y - 8);
    await page.mouse.move(x, y, {steps: 5});
    await page.waitForTimeout(150);
    await page.mouse.click(x, y);
};

try {
    await step('sign up', async () => {
        await page.goto(`${base}/sign-up`);
        await page.getByLabel('Name').fill(user.name);
        await page.getByLabel('Email').fill(user.email);
        await page.getByLabel('Password').fill(user.password);
        await answer('POST', /\/api\/authentication\/sign-up$/, () => page.getByRole('button', {name: 'Sign up'}).click());
        await page.getByRole('heading', {name: 'Projects'}).waitFor();
    });

    let projectUrl = '';
    await step('create a project', async () => {
        // The projects and the examples load after the heading: let them come in before clicking.
        await page.waitForLoadState('networkidle');
        await page.getByRole('button', {name: /New project|Create your first project/}).first().click();
        await page.getByPlaceholder('e.g. Flat 12, 2nd floor').fill('Smoke flat');
        await answer('POST', /\/api\/projects$/, () => page.getByRole('button', {name: 'Create'}).click());
        await page.getByRole('link', {name: 'Open 3D view'}).click();
        await page.waitForURL(/\/floors\/\d+/);
        projectUrl = page.url();
        await canvas();
    });

    await step('draw four walls', async () => {
        // The first steps guide opens by itself on the empty floor; its first step starts the tool.
        await page.getByRole('button', {name: 'Draw walls', exact: true}).first().click();
        await page.getByRole('button', {name: 'Plan', exact: true}).click();
        const box = await canvas();
        // Let the camera settle on the plan, then click a rectangle round the middle of the view (the plan may be turned,
        // so corners are clicked on screen rather than typed by compass angle) and close it on its start, which ends it.
        await page.waitForTimeout(1500);
        const centre = {x: box.x + box.width / 2, y: box.y + box.height / 2};
        const corners = [[-160, 110], [160, 110], [160, -110], [-160, -110], [-160, 110]];
        for (const [index, [dx, dy]] of corners.entries()) {
            const point = {x: centre.x + dx, y: centre.y + dy};
            await page.mouse.move(point.x, point.y, {steps: 5});
            if (index < corners.length - 1) {
                await page.mouse.click(point.x, point.y);
            } else {
                await answer('POST', /\/walls\/polyline$/, () => page.mouse.click(point.x, point.y));
            }
        }
    });

    await step('make the room from the walls', async () => {
        await page.getByRole('button', {name: 'Room from walls', exact: true}).click();
        const box = await canvas();
        // The walls go round the middle of the view.
        await answer('POST', /\/rooms\/detect$/, () => clickAt(box.x + box.width / 2, box.y + box.height / 2));
    });

    await step('put a door in a wall', async () => {
        await page.getByRole('button', {name: 'Select', exact: true}).click();
        // The new room is selected: let it go, so the click picks the wall.
        await page.keyboard.press('Escape');
        const box = await canvas();
        // The first wall runs along the bottom of the rectangle.
        await clickAt(box.x + box.width / 2, box.y + box.height / 2 + 110);
        await page.getByRole('button', {name: 'Add', exact: true}).click();
        await answer('POST', /\/openings$/, () => page.getByRole('button', {name: 'Door', exact: true}).click());
    });

    let shareUrl = '';
    await step('share the floor', async () => {
        await page.keyboard.press('Escape');
        await page.getByRole('button', {name: 'Share', exact: true}).click();
        const card = page.getByRole('region', {name: 'Share link'});
        await answer('POST', /\/share-links$/, () => card.getByRole('button', {name: /Create|Share|Save/}).first().click());
        shareUrl = await card.getByLabel('Share link').inputValue();
    });

    await step('open the share link as a guest', async () => {
        const guest = await browser.newContext({viewport: {width: 1400, height: 900}});
        const guestPage = await guest.newPage();
        watch(guestPage, 'guest');
        await guestPage.goto(shareUrl);
        await guestPage.locator('canvas').first().waitFor({timeout});
        await guest.close();
    });

    await step('start from the example', async () => {
        await page.goto(`${base}/projects`);
        await page.getByRole('button', {name: 'Start from an example'}).click();
        await answer('POST', /\/api\/examples\/[a-z0-9-]+\/projects$/, () => page.getByRole('button', {name: 'Open a copy'}).first().click());
        await page.waitForURL(/\/floors\/\d+/);
        await page.locator('[data-view-ready]').waitFor({timeout: 120_000});
    });

    await step('download the copy and import it', async () => {
        const projectId = /\/projects\/(\d+)\//.exec(new URL(page.url()).pathname)?.[1];
        await page.goto(`${base}/projects/${projectId}`);
        const [download] = await Promise.all([page.waitForEvent('download'), page.getByRole('button', {name: 'Download', exact: true}).click()]);
        const file = resolve(out, await download.suggestedFilename());
        await mkdir(out, {recursive: true});
        await download.saveAs(file);
        await page.goto(`${base}/projects`);
        const [chooser] = await Promise.all([page.waitForEvent('filechooser'), page.locator('label', {hasText: 'Import'}).click()]);
        const imported = await answer('POST', /\/api\/projects\/import$/, () => chooser.setFiles(file));
        if (imported?.warnings?.length > 0) {
            throw new Error(`The import left out: ${imported.warnings.join(' ')}`);
        }
        await page.waitForURL(/\/floors\/\d+/);
        await page.locator('canvas').first().waitFor({timeout});
    });

    if (errors.length > 0) {
        current = 'console';
        throw new Error(`The pages logged errors:\n${errors.join('\n')}`);
    }
    console.log(`Smoke test passed (${projectUrl}).`);
} catch (error) {
    await mkdir(out, {recursive: true});
    const shot = resolve(out, `failed-${current.replaceAll(' ', '-')}.png`);
    await page.screenshot({path: shot}).catch(() => undefined);
    console.error(`✗ ${current}: ${error instanceof Error ? error.message : error}`);
    if (errors.length > 0) {
        console.error(errors.join('\n'));
    }
    console.error(`Screenshot: ${shot}`);
    process.exitCode = 1;
} finally {
    await browser.close();
}
