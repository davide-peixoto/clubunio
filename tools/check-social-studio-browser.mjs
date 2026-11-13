import assert from 'node:assert/strict';
import { readFile, mkdir } from 'node:fs/promises';
import { resolve } from 'node:path';

// Use an isolated Playwright install; no browser dependency in the deployed bundle.
const { chromium } = await import(process.env.CLUBUNIO_PLAYWRIGHT_MODULE || 'playwright');
const labels = {
    day_heading: 'Today at the grounds', result_heading: 'Final score', free_heading: 'Club news',
    day: 'Matchday', result: 'Final score', save_post: 'Save draft', save: 'Save template', crop: 'Crop',
    background_image: 'Use as background', open: 'Open', 'new_template': 'New template', saved: 'Saved.',
    job_new: 'New scheduled job', job_save: 'Save job', job_teams: 'Teams', job_time: 'Time',
};
const manifest = JSON.parse(await readFile('public/build/manifest.json', 'utf8'));
const entry = manifest['resources/js/social-studio.js'];
const blade = await readFile('resources/views/clubunio/pages/social-studio.blade.php', 'utf8');
const markup = blade.slice(blade.indexOf('<section'), blade.lastIndexOf('</section>') + 10)
    .replace(/<nav[\s\S]*?<\/nav>/, '<nav><button data-tab="today">Fixtures</button><button data-tab="posts">Posts</button><button data-tab="templates">Templates</button><button data-tab="media">Media</button><button data-tab="jobs" hidden>Scheduled jobs</button><label data-archive><input type="checkbox" data-archived></label><label data-date-label><input type="date" data-date></label></nav>')
    .replace(/data-config="[^"]*"/, "data-config='" + JSON.stringify({ url: '/social-data/state', csrf: 'mock', labels }) + "'")
    .replace(/{{[^}]+}}/g, 'Label').replace(/<x-filament::icon[^>]*\/>/g, '<svg></svg>');
const mediaId = 'a93b41c8-f8a5-46f4-8a9d-79f302d38026';
const fixture = { id: 1, date: '2026-11-13', time: '13:00', home: 'Test United', away: 'Visitors FC', place: 'Main Field', score: [3, 1] };
const data = { date: fixture.date, club: 'Synthetic Club', fixtures: [fixture], templates: [],
    media: [{ id: mediaId, name: 'Synthetic background' }], posts: [], more: {},
    permissions: { create: true, update: true, upload: true, approve: true, schedule: true }, test_channels: [] };
const browser = await chromium.launch({ executablePath: process.env.CLUBUNIO_CHROMIUM || undefined, headless: true });
const out = resolve('storage/app/private/social-qa');
await mkdir(out, { recursive: true });
try {
    const page = await browser.newPage({ viewport: { width: 1440, height: 950 } });
    const errors = [];
    const exports = [];
    const savedJobs = [];
    let background;
    page.on('pageerror', error => errors.push(error.message));
    await page.route('http://clubunio.test/**', async route => {
        const path = new URL(route.request().url()).pathname;
        if (path === '/') return route.fulfill({ contentType: 'text/html', body: '<link rel="stylesheet" href="/build/' + entry.css[0] + '">'
            + '<script type="module" src="/build/' + entry.file + '"></script>' + markup });
        if (path.startsWith('/build/')) return route.fulfill({ path: 'public' + path,
            contentType: path.endsWith('.css') ? 'text/css' : 'text/javascript' });
        if (path.endsWith('/media')) return route.fulfill({ contentType: 'image/png', body: background });
        if (path.endsWith('/jobs')) return route.fulfill({ json: { jobs: [], teams: [{ id: 42, name: 'Women United' }],
            templates: data.templates, runs: [], languages: ['de', 'en'], saved_versions: {}, auto_allowed: true, runner_enabled: true } });
        if (path.endsWith('/job-save')) {
            savedJobs.push(route.request().postDataJSON());
            return route.fulfill({ json: { id: mediaId } });
        }
        if (path.endsWith('/template-save')) {
            const body = route.request().postDataJSON();
            data.templates.push({ id: mediaId, name: body.name, revision: 1, design: body.design });
            return route.fulfill({ json: { id: mediaId, revision: 1 } });
        }
        if (path.endsWith('/post-save')) {
            const upload = new Response(route.request().postDataBuffer(), { headers: { 'Content-Type': route.request().headers()['content-type'] } });
            const form = await upload.formData();
            const metadata = JSON.parse(form.get('metadata'));
            exports.push({ metadata, bytes: Buffer.from(await form.get('image').arrayBuffer()) });
            data.posts.push({ id: mediaId, title: metadata.title, caption: '', kind: metadata.kind, state: 'draft', revision: 1, targets: metadata.targets, deliveries: [] });
            return route.fulfill({ json: { id: mediaId } });
        }
        return route.fulfill({ json: path.endsWith('/template') ? { design: data.templates[0].design } : data });
    });
    await page.goto('about:blank');
    background = Buffer.from(await page.evaluate(() => {
        const c = document.createElement('canvas'); c.width = 240; c.height = 160;
        const ctx = c.getContext('2d'); ctx.fillStyle = '#a53645'; ctx.fillRect(0, 0, 120, 160);
        ctx.fillStyle = '#327c5d'; ctx.fillRect(120, 0, 120, 160);
        return c.toDataURL().split(',')[1];
    }), 'base64');
    await page.goto('http://clubunio.test/');
    await page.getByRole('button', { name: 'Templates', exact: true }).click();
    await page.getByRole('button', { name: 'New template: Matchday' }).click();
    await page.locator('[data-tools] select').first().selectOption(mediaId);
    await page.getByRole('button', { name: 'Use as background' }).click();
    await page.locator('[data-properties]').getByRole('button', { name: 'Crop' }).click();
    const crop = page.locator('dialog[open]').last();
    await crop.locator('input').nth(0).fill('20');
    await crop.locator('input').nth(0).press('Tab');
    await crop.getByRole('button', { name: 'Crop', exact: true }).click();
    await page.screenshot({ path: resolve(out, 'desktop.png') });
    await page.getByRole('button', { name: 'Save template' }).click();
    await page.getByText('Saved.').waitFor();
    assert.equal(data.templates[0].design.layers[0].crop.x, .2);
    await page.getByRole('button', { name: 'Scheduled jobs' }).click();
    await page.getByRole('button', { name: 'New scheduled job' }).click();
    const job = page.getByRole('dialog', { name: 'New scheduled job' });
    await job.locator('input[type=text]').fill('Women Matchday');
    await job.getByLabel('Time', { exact: true }).fill('08:15');
    await job.locator('select[multiple]').selectOption('42');
    await job.getByRole('button', { name: 'Save job' }).click();
    await page.getByText('Saved.').waitFor();
    assert.equal(savedJobs.length, 1);
    assert.equal(savedJobs[0].local_time, '08:15');
    assert.deepEqual(savedJobs[0].team_ids, [42]);
    assert.equal(savedJobs[0].template_revision, 1);
    await page.getByRole('button', { name: 'Fixtures', exact: true }).click();
    await page.getByRole('checkbox', { name: /Test United/ }).check();
    await page.getByRole('button', { name: 'Matchday', exact: true }).click();
    await page.locator('[data-details] select').last().selectOption(mediaId);
    await page.getByRole('button', { name: 'Save draft' }).click();
    await page.locator('[data-studio-dialog]').waitFor({ state: 'hidden' });
    await page.getByText('Saved.').waitFor();
    await page.setViewportSize({ width: 390, height: 844 });
    await page.getByRole('button', { name: 'Fixtures', exact: true }).click();
    await page.getByRole('button', { name: 'Final score', exact: true }).click();
    const overflow = await page.locator('[data-studio-dialog]').evaluate(node => node.scrollWidth > node.clientWidth + 2);
    assert.equal(overflow, false);
    await page.screenshot({ path: resolve(out, 'mobile.png') });
    await page.getByRole('button', { name: 'Save draft' }).click();
    await page.locator('[data-studio-dialog]').waitFor({ state: 'hidden' });
    await page.getByText('Saved.').waitFor();
    assert.equal(exports.length, 2);
    assert.deepEqual(exports.map(item => item.metadata.kind), ['day', 'result']);
    assert.deepEqual(exports[1].metadata.seen.fixtures[0].score, [3, 1]);
    for (const item of exports) {
        const image = await page.evaluate(async data => {
            const img = new Image(); img.src = 'data:image/jpeg;base64,' + data; await img.decode();
            const c = document.createElement('canvas'); c.width = img.width; c.height = img.height;
            const ctx = c.getContext('2d'); ctx.drawImage(img, 0, 0);
            const pixels = ctx.getImageData(0, 0, c.width, c.height).data;
            const colours = new Set();
            for (let index = 0; index < pixels.length; index += 188) colours.add(pixels[index] + ',' + pixels[index + 1] + ',' + pixels[index + 2]);
            return [img.width, img.height, colours.size];
        }, item.bytes.toString('base64'));
        assert.deepEqual(image.slice(0, 2), [1080, 1350]);
        assert.ok(image[2] > 20);
    }
    assert.deepEqual(errors, []);
    console.log('Desktop/mobile editor, background crop, reusable template and two nonblank 1080 x 1350 JPEGs verified.');
} finally {
    await browser.close();
}
