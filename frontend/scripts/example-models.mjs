// Downloads the product models the example projects use (backend/examples/*.json) from Poly Haven, where they are CC0,
// and optimises them as the demos' models were (gltf-transform: meshopt, WebP textures, simplified), writing
// `<asset>.glb` with a `<asset>.json` credits file each into the output directory, for `models:import`. Run it with
// `make example-models`, which imports them too; models already in the directory are not fetched again. The models are
// not in the repository: third-party files are never committed.
//
// Usage: node scripts/example-models.mjs <output directory> [examples directory, default ../backend/examples]
//
// Poly Haven's API asks for a User-Agent naming the software; its terms allow this use, commercial included.
import {spawnSync} from 'node:child_process';
import {createHash} from 'node:crypto';
import {mkdir, readdir, readFile, rm, stat, writeFile} from 'node:fs/promises';
import {dirname, join, resolve} from 'node:path';

const GltfTransform = '@gltf-transform/cli@4.5.1';
const UserAgent = 'Izbik-example-models/1.0 (+https://github.com/marekskopal/izbik)';
const Resolution = '1k';
const SourcePrefix = 'https://polyhaven.com/a/';

const [outArg, examplesArg] = process.argv.slice(2);
if (!outArg) {
    console.error('Usage: node scripts/example-models.mjs <output directory> [examples directory]');
    process.exit(1);
}
const out = resolve(outArg);
const examplesDir = resolve(examplesArg ?? new URL('../../backend/examples', import.meta.url).pathname);

/** The Poly Haven models of every example, by asset id: {name, license, author, sourceUrl}. */
const exampleModels = async () => {
    const models = new Map();
    for (const file of (await readdir(examplesDir)).filter((name) => name.endsWith('.json'))) {
        const example = JSON.parse(await readFile(join(examplesDir, file), 'utf8'));
        for (const floor of example.snapshot?.floors ?? []) {
            for (const model of floor.models ?? []) {
                if (typeof model.sourceUrl === 'string' && model.sourceUrl.startsWith(SourcePrefix)) {
                    models.set(model.sourceUrl.slice(SourcePrefix.length).replace(/\/$/, ''), model);
                }
            }
        }
    }
    return models;
};

const exists = async (path) => {
    try {
        await stat(path);
        return true;
    } catch {
        return false;
    }
};

const fetchOk = async (url) => {
    const response = await fetch(url, {headers: {'User-Agent': UserAgent}});
    if (!response.ok) {
        throw new Error(`${url}: HTTP ${response.status}`);
    }
    return response;
};

/** Downloads a file Poly Haven lists, checking its md5. */
const download = async (entry, path) => {
    const bytes = Buffer.from(await (await fetchOk(entry.url)).arrayBuffer());
    const md5 = createHash('md5').update(bytes).digest('hex');
    if (entry.md5 && md5 !== entry.md5) {
        throw new Error(`${entry.url}: checksum mismatch`);
    }
    await mkdir(dirname(path), {recursive: true});
    await writeFile(path, bytes);
};

/** The model's glTF with the files it includes, downloaded into `dir`; the path of the .gltf. */
const fetchModel = async (asset, dir) => {
    const files = await (await fetchOk(`https://api.polyhaven.com/files/${encodeURIComponent(asset)}`)).json();
    const gltf = files.gltf?.[Resolution]?.gltf;
    if (!gltf?.url) {
        throw new Error(`${asset}: no ${Resolution} glTF on Poly Haven`);
    }
    const main = join(dir, gltf.url.split('/').pop());
    await download(gltf, main);
    for (const [path, entry] of Object.entries(gltf.include ?? {})) {
        const target = resolve(dir, path);
        if (!target.startsWith(dir)) {
            throw new Error(`${asset}: unexpected path ${path}`);
        }
        await download(entry, target);
    }
    return main;
};

const optimise = (input, output) => {
    const args = ['--yes', GltfTransform, 'optimize', input, output];
    args.push('--compress', 'meshopt', '--texture-compress', 'webp', '--texture-size', '1024', '--simplify-error', '0.003');
    const run = spawnSync('npx', args, {stdio: 'inherit'});
    if (run.status !== 0) {
        throw new Error(`gltf-transform failed for ${input}`);
    }
};

const models = await exampleModels();
await mkdir(out, {recursive: true});
console.log(`${models.size} Poly Haven model(s) in the examples. Models from Poly Haven (https://polyhaven.com), CC0.`);
let failed = 0;
for (const [asset, model] of models) {
    const glb = join(out, `${asset}.glb`);
    if (await exists(glb)) {
        console.log(`  ${asset}: already downloaded`);
        continue;
    }
    const work = join(out, '.download', asset);
    try {
        console.log(`  ${asset}: downloading`);
        optimise(await fetchModel(asset, work), glb);
        const credits = {
            name: model.name,
            description: `Poly Haven model "${asset}".`,
            license: model.license || 'CC0',
            author: model.author ?? '',
            sourceUrl: model.sourceUrl,
        };
        await writeFile(join(out, `${asset}.json`), `${JSON.stringify(credits, null, 1)}\n`);
    } catch (error) {
        failed++;
        console.error(`  ${asset}: ${error.message}`);
        await rm(glb, {force: true});
    } finally {
        await rm(work, {recursive: true, force: true});
    }
}
await rm(join(out, '.download'), {recursive: true, force: true});
if (failed > 0) {
    console.error(`${failed} model(s) failed; run it again to retry them.`);
    process.exit(1);
}
