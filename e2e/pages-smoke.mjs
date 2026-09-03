// Browser smoke test for the application's authenticated top-level pages.
// It is intentionally read-only: the test signs in and visits pages, but it
// does not submit application forms or change project data.
import { chromium } from "playwright";

const baseUrl = process.env.BASE_URL ?? "http://127.0.0.1:8000";
const username = process.env.E2E_USERNAME;
const password = process.env.E2E_PASSWORD;

const pages = [
    ["Pregled", "/"],
    ["Mapa", "/mapa"],
    ["Projekti", "/projekti"],
    ["ODF-ovi", "/odf"],
    ["ODO ormarići", "/ormarici"],
    ["Kuće", "/kuce"],
    ["Trase", "/trase"],
    ["Krakovi", "/krakovi"],
    ["Izvještaji", "/izvjestaji"],
    ["Splitteri", "/splitteri"],
    ["Fiber šema", "/fiber-sema"],
    ["Provjera projekta", "/provjera-projekta"],
    ["Postavke", "/postavke"],
    ["Uputstvo", "/uputstvo"],
];

if (!username || !password) {
    process.stderr.write(
        "✗ E2E_USERNAME i E2E_PASSWORD su obavezni za prijavu.\n",
    );
    process.exit(1);
}

try {
    const ping = await fetch(`${baseUrl}/prijava`);
    if (!ping.ok) throw new Error(`HTTP ${ping.status}`);
} catch (error) {
    process.stderr.write(
        `✗ Aplikacija nije dostupna na ${baseUrl} (${error.message}).\n`,
    );
    process.exit(1);
}

const browser = await chromium.launch();
const page = await browser.newPage({ viewport: { width: 1440, height: 900 } });

try {
    await page.goto(`${baseUrl}/prijava`, {
        waitUntil: "networkidle",
        timeout: 30000,
    });
    await page.fill('input[name="username"]', username);
    await page.fill('input[name="password"]', password);
    await Promise.all([
        page.waitForURL((url) => !url.pathname.startsWith("/prijava"), { timeout: 15000 }),
        page.click('button[type="submit"]'),
    ]);
    await page.evaluate(() => localStorage.setItem('ftthOnboardingComplete', '1'));

    for (const [label, path] of pages) {
        const pageErrors = [];
        const consoleErrors = [];
        const failedLocalResponses = [];
        const onPageError = (error) => pageErrors.push(error.message);
        const onConsole = (message) => {
            const text = message.text();
            const isBlockedExternalMapResource = text.includes(
                "Failed to load resource: net::ERR_NETWORK_ACCESS_DENIED",
            );
            if (message.type() === "error" && !isBlockedExternalMapResource)
                consoleErrors.push(text);
        };
        const onResponse = (response) => {
            if (
                response.url().startsWith(baseUrl) &&
                response.status() >= 400
            ) {
                failedLocalResponses.push(
                    `${response.status()} ${response.url()}`,
                );
            }
        };

        page.on("pageerror", onPageError);
        page.on("console", onConsole);
        page.on("response", onResponse);

        try {
            const response = await page.goto(`${baseUrl}${path}`, {
                waitUntil: "networkidle",
                timeout: 30000,
            });
            if (!response || response.status() >= 400) {
                throw new Error(
                    `Glavni dokument vraća HTTP ${response?.status() ?? "bez odgovora"}`,
                );
            }
            if (page.url().includes("/prijava"))
                throw new Error("Neočekivano preusmjerenje na prijavu");
            await page.waitForSelector("main", {
                state: "visible",
                timeout: 10000,
            });

            if (path === "/mapa") {
                await page.waitForSelector("#network-map.leaflet-container", {
                    timeout: 15000,
                });
            }

            await page.waitForTimeout(250);
            const bodyText = (await page.locator("body").innerText()).trim();
            if (bodyText.length < 20)
                throw new Error("Stranica nema očekivani vidljivi sadržaj");
            if (pageErrors.length)
                throw new Error(`JavaScript: ${pageErrors.join(" | ")}`);
            if (consoleErrors.length)
                throw new Error(`Console: ${consoleErrors.join(" | ")}`);
            if (failedLocalResponses.length)
                throw new Error(
                    `Lokalni resursi: ${failedLocalResponses.join(" | ")}`,
                );

            process.stdout.write(`✓ ${label} (${path})\n`);
        } catch (error) {
            const slug = path.replaceAll("/", "-").replace(/^-/, "") || "root";
            await page.screenshot({
                path: `storage/logs/pages-smoke-${slug}.png`,
                fullPage: true,
            });
            throw new Error(`${label} (${path}): ${error.message}`);
        } finally {
            page.off("pageerror", onPageError);
            page.off("console", onConsole);
            page.off("response", onResponse);
        }
    }

    process.stdout.write(
        `✓ Svih ${pages.length} glavnih stranica prošlo je browser smoke test.\n`,
    );
} catch (error) {
    process.stderr.write(
        `✗ Browser smoke test nije prošao: ${error.message}\n`,
    );
    process.exitCode = 1;
} finally {
    await browser.close();
}
