import { chromium } from 'playwright';

const baseUrl = process.env.BASE_URL ?? 'http://127.0.0.1:8000';
const password = process.env.E2E_PASSWORD;
const roles = [
    { username: 'e2e_admin', allowed: ['/postavke', '/sistem/health'], forbidden: [] },
    { username: 'e2e_designer', allowed: ['/projekti', '/mapa'], forbidden: ['/postavke', '/sistem/health'] },
    { username: 'e2e_field', allowed: ['/mapa'], forbidden: ['/postavke', '/sistem/health'] },
    { username: 'e2e_viewer', allowed: ['/projekti', '/mapa'], forbidden: ['/postavke', '/sistem/health'] },
];

if (!password) {
    process.stderr.write('✗ E2E_PASSWORD je obavezan za provjeru uloga.\n');
    process.exit(1);
}

const browser = await chromium.launch();
try {
    for (const role of roles) {
        const context = await browser.newContext();
        const page = await context.newPage();
        await page.goto(`${baseUrl}/prijava`, { waitUntil: 'networkidle' });
        await page.fill('input[name="username"]', role.username);
        await page.fill('input[name="password"]', password);
        await Promise.all([
            page.waitForURL(url => !url.pathname.startsWith('/prijava'), { timeout: 15000 }),
            page.click('button[type="submit"]'),
        ]);

        for (const path of role.allowed) {
            const response = await page.goto(`${baseUrl}${path}`, { waitUntil: 'networkidle' });
            if (!response || response.status() >= 400) throw new Error(`${role.username} nema očekivani pristup ${path} (HTTP ${response?.status()})`);
        }
        for (const path of role.forbidden) {
            const response = await page.goto(`${baseUrl}${path}`, { waitUntil: 'networkidle' });
            if (response?.status() !== 403) throw new Error(`${role.username} mora dobiti HTTP 403 za ${path}, dobiveno ${response?.status()}`);
        }

        await page.goto(`${baseUrl}/mapa`, { waitUntil: 'networkidle' });
        if (role.username === 'e2e_field' && await page.locator('#survey-panel-btn').count() !== 1) {
            throw new Error('Terenska uloga nema pristup panelu za terenske tačke.');
        }
        if (role.username === 'e2e_viewer' && await page.locator('#survey-panel-btn').count() !== 0) {
            throw new Error('Viewer vidi panel za terenske tačke.');
        }

        process.stdout.write(`✓ ${role.username}: dozvoljene i zabranjene stranice su ispravne.\n`);
        await context.close();
    }
} catch (error) {
    process.stderr.write(`✗ Provjera uloga nije prošla: ${error.message}\n`);
    process.exitCode = 1;
} finally {
    await browser.close();
}
