import { chromium } from 'playwright';

const baseUrl = process.env.BASE_URL ?? 'http://127.0.0.1:8000';
const username = process.env.E2E_USERNAME;
const password = process.env.E2E_PASSWORD;
if (!username || !password) {
    process.stderr.write('✗ E2E_USERNAME i E2E_PASSWORD su obavezni za vizuelni audit.\n');
    process.exit(1);
}
const browser = await chromium.launch();
const page = await browser.newPage({ viewport: { width: 1440, height: 1000 }, deviceScaleFactor: 1 });
const auditPages = [
    ['dashboard', '/'],
    ['map', '/mapa'], ['projects', '/projekti'], ['odfs', '/odf'], ['cabinets', '/ormarici'],
    ['houses', '/kuce'], ['routes', '/trase'], ['branches', '/krakovi'], ['reports', '/izvjestaji'],
    ['splitters', '/splitteri'], ['fiber-schema', '/fiber-sema'], ['project-check', '/provjera-projekta'],
    ['settings', '/postavke'],
];

try {
    await page.goto(`${baseUrl}/prijava`, { waitUntil: 'domcontentloaded' });
    await page.fill('input[name="username"]', username);
    await page.fill('input[name="password"]', password);
    await Promise.all([
        page.waitForURL(`${baseUrl}/**`),
        page.click('button[type="submit"]'),
    ]);
    await page.evaluate(() => localStorage.setItem('ftthOnboardingComplete', '1'));

    await page.goto(`${baseUrl}/projekti`, { waitUntil: 'networkidle' });
    const projectOverviewPath = await page.locator('a[href*="/projekti/"][href$="/pregled"]').first().getAttribute('href').catch(() => null);
    if (projectOverviewPath) {
        auditPages.push(['project-overview', projectOverviewPath.replace(baseUrl, '')]);
        const projectId = projectOverviewPath.match(/\/projekti\/(\d+)\/pregled/)?.[1];
        const mapAudit = auditPages.find(([name]) => name === 'map');
        if (projectId && mapAudit) mapAudit[1] = `/mapa?project=${projectId}`;
    }

    for (const [name, path] of auditPages) {
        await page.goto(`${baseUrl}${path}`, { waitUntil: 'networkidle' });
        await page.waitForTimeout(450);
        await page.screenshot({ path: `storage/logs/visual-${name}-desktop.png`, fullPage: true });
    }

    // Dashboard also targets the two working environments used in practice:
    // a common laptop and a large planning monitor.
    await page.setViewportSize({ width: 1366, height: 768 });
    await page.goto(`${baseUrl}/`, { waitUntil: 'networkidle' });
    await page.screenshot({ path: 'storage/logs/visual-dashboard-laptop.png', fullPage: true });

    await page.setViewportSize({ width: 2560, height: 1440 });
    await page.goto(`${baseUrl}/`, { waitUntil: 'networkidle' });
    await page.screenshot({ path: 'storage/logs/visual-dashboard-large.png', fullPage: true });
    if (projectOverviewPath) {
        await page.goto(`${baseUrl}${projectOverviewPath.replace(baseUrl, '')}`, { waitUntil: 'networkidle' });
        await page.screenshot({ path: 'storage/logs/visual-project-overview-large.png', fullPage: true });
    }

    await page.setViewportSize({ width: 3840, height: 1600 });
    await page.goto(`${baseUrl}/`, { waitUntil: 'networkidle' });
    await page.screenshot({ path: 'storage/logs/visual-dashboard-ultrawide.png', fullPage: true });
    if (projectOverviewPath) {
        await page.goto(`${baseUrl}${projectOverviewPath.replace(baseUrl, '')}`, { waitUntil: 'networkidle' });
        await page.screenshot({ path: 'storage/logs/visual-project-overview-ultrawide.png', fullPage: true });
    }

    await page.setViewportSize({ width: 390, height: 844 });
    for (const [name, path] of auditPages) {
        await page.goto(`${baseUrl}${path}`, { waitUntil: 'networkidle' });
        await page.waitForTimeout(450);
        await page.screenshot({ path: `storage/logs/visual-${name}-mobile.png`, fullPage: true });
    }

    await page.setViewportSize({ width: 820, height: 1180 });
    for (const [name, path] of auditPages) {
        await page.goto(`${baseUrl}${path}`, { waitUntil: 'networkidle' });
        await page.waitForTimeout(350);
        await page.screenshot({ path: `storage/logs/visual-${name}-tablet.png`, fullPage: true });
    }
} finally {
    await browser.close();
}
