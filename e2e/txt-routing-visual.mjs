import { chromium } from 'playwright';
import path from 'node:path';

const baseUrl = process.env.BASE_URL ?? 'http://127.0.0.1:8000';
const username = process.env.E2E_USERNAME;
const password = process.env.E2E_PASSWORD;
const fixture = path.resolve('tests/Fixtures/survey/demo-feature-koordinate.txt');

if (!username || !password) throw new Error('E2E_USERNAME i E2E_PASSWORD su obavezni.');

const browser = await chromium.launch();
const page = await browser.newPage({ viewport: { width: 1600, height: 1000 } });
let projectId = null;

async function csrfFetch(url, options = {}) {
    return page.evaluate(async ({ url, options }) => {
        const response = await fetch(url, {
            ...options,
            headers: {
                Accept: 'application/json',
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content ?? '',
                'X-Requested-With': 'XMLHttpRequest',
                ...(options.headers ?? {}),
            },
        });
        return { status: response.status, body: await response.json().catch(() => ({})) };
    }, { url, options });
}

try {
    await page.goto(`${baseUrl}/prijava`, { waitUntil: 'networkidle' });
    await page.fill('input[name="username"]', username);
    await page.fill('input[name="password"]', password);
    await Promise.all([
        page.waitForURL(url => !url.pathname.startsWith('/prijava')),
        page.click('button[type="submit"]'),
    ]);
    await page.evaluate(() => localStorage.setItem('ftthOnboardingComplete', '1'));

    const created = await csrfFetch('/projekti', {
        method: 'POST',
        body: JSON.stringify({
            name: `TXT routing visual ${Date.now()}`,
            code: `TXT-VIS-${Date.now()}`,
            location: 'Izolovani E2E audit',
            status: 'planning',
        }),
    });
    if (created.status !== 201 || !created.body?.project?.id) throw new Error(`Kreiranje projekta: HTTP ${created.status}`);
    projectId = created.body.project.id;

    await page.goto(`${baseUrl}/mapa?project=${projectId}`, { waitUntil: 'networkidle' });
    await page.waitForSelector('#network-map.leaflet-container');
    await page.click('#survey-panel-btn');
    const [previewResponse] = await Promise.all([
        page.waitForResponse(response => response.url().includes('/tacke/preview')),
        page.setInputFiles('#survey-file-input', fixture),
    ]);
    const preview = await previewResponse.json();
    await page.waitForFunction(() => document.querySelector('#survey-status')?.textContent.includes('Pregled spreman'));

    const complete = preview.ducts.filter(duct => duct.routing_status === 'complete' && duct.target_zo_found);
    if (!complete.length) throw new Error('Stvarni TXT nije prikazao nijednu strogo završenu korisničku rutu.');
    if (complete.some(duct => !duct.entry_point || !duct.own_geometry.length || !duct.shared_main_geometry.length)) {
        throw new Error('Preview nema odvojene own/shared/entry podatke za sve završene rute.');
    }
    if (complete.some(duct => duct.full_geometry.at(-1).join(',') !== duct.target_zo_coordinate.join(','))) {
        throw new Error('Završetak full_geometry nije ciljni ZO iz opisa.');
    }

    const colors = await page.locator('#network-map path').evaluateAll(nodes => nodes.map(node => ({
        stroke: node.getAttribute('stroke'),
        fill: node.getAttribute('fill'),
    })));
    if (!colors.some(item => item.stroke === '#16a34a')) throw new Error('Own geometrija nije nacrtana zeleno.');
    if (!colors.some(item => item.stroke === '#7c3aed')) throw new Error('Shared geometrija nije nacrtana ljubičasto.');
    if (!colors.some(item => item.fill === '#f59e0b')) throw new Error('Entry point nije nacrtan narandžasto.');

    await page.screenshot({ path: 'storage/logs/txt-routing-visual.png', fullPage: true });
    process.stdout.write(`✓ TXT visual: ${complete.length} strogo rekonstruisanih ruta, own/shared/entry/ZO prikaz potvrđen.\n`);
} finally {
    if (projectId) {
        await csrfFetch(`/projekti/${projectId}`, { method: 'DELETE' }).catch(() => null);
    }
    await browser.close();
}
