// Browser smoke test for the map — the app's most critical and least-tested
// surface. Boots a headless Chromium, loads /mapa and verifies the whole
// front-end actually initialises: Leaflet mounts, the CAD toolbar renders,
// init.js wires up window.ftthNetworkMap, and no uncaught JS error fires.
//
// Opt-in (not part of `composer check` or the pre-commit hook) because it needs
// a running dev server and internet (Leaflet loads from a CDN).
//
// Usage:  (with `composer run dev` running in another terminal)
//     npm run test:e2e
//     BASE_URL=http://127.0.0.1:8000 npm run test:e2e   # custom host/port
import { chromium } from "playwright";

const baseUrl = process.env.BASE_URL ?? "http://127.0.0.1:8000";
const target = `${baseUrl}/mapa`;
const username = process.env.E2E_USERNAME;
const password = process.env.E2E_PASSWORD;

function fail(message) {
    process.stderr.write(`✗ ${message}\n`);
    process.exitCode = 1;
}

// Confirm the dev server is reachable before spinning up a browser.
try {
    const ping = await fetch(target, { redirect: "manual" });
    if (ping.status >= 500) throw new Error(`HTTP ${ping.status}`);
} catch (error) {
    fail(`Dev server nije dostupan na ${baseUrl} (${error.message}).`);
    process.stderr.write(
        "  Pokreni 'composer run dev' u drugom terminalu, pa ponovo.\n",
    );
    process.exit(1);
}

const browser = await chromium.launch();
const page = await browser.newPage();

const pageErrors = [];
page.on("pageerror", (error) => pageErrors.push(error.message));

try {
    await page.goto(target, { waitUntil: "domcontentloaded", timeout: 30000 });

    if (page.url().includes("/prijava")) {
        if (!username || !password) {
            throw new Error(
                "E2E_USERNAME i E2E_PASSWORD su obavezni za prijavu.",
            );
        }

        const loginUrl = `${baseUrl}/prijava`;

        // Preuzmi CSRF token sa login stranice
        const csrfToken = await page.getAttribute(
            'meta[name="csrf-token"]',
            "content",
        );

        if (!csrfToken) {
            throw new Error("CSRF token nije pronađen na login stranici");
        }

        // Pošalji login POST request koristeći page.request
        const loginResponse = await page.request.post(loginUrl, {
            data: {
                username: username,
                password: password,
                _token: csrfToken,
            },
        });

        if (!loginResponse.ok()) {
            throw new Error(
                `Login POST zahtjev nije uspio (status: ${loginResponse.status()})`,
            );
        }

        // Čekaj da se preusmjeri i onda idi na mapu
        await page.goto(target, {
            waitUntil: "domcontentloaded",
            timeout: 30000,
        });
    }

    // 1. Leaflet mounted the map — it stamps `leaflet-container` onto #network-map itself.
    await page.waitForSelector("#network-map.leaflet-container", {
        timeout: 15000,
    });

    // 2. init.js ran and exposed the shared map instance.
    const hasMap = await page.evaluate(
        () =>
            typeof window.ftthNetworkMap === "object" &&
            window.ftthNetworkMap !== null,
    );
    if (!hasMap)
        fail(
            "window.ftthNetworkMap nije inicijalizovan (init.js nije prošao).",
        );

    // 3. The CAD toolbar rendered its live command state (init.js swaps the
    //    static "Command: PAN" for a dynamic hint once it wires up).
    const command = (await page.textContent("#cad-command"))?.trim() ?? "";
    if (command.length === 0)
        fail("Toolbar nije spreman (#cad-command je prazan).");

    // 4. No uncaught JS error during boot.
    if (pageErrors.length > 0)
        fail(`Uncaught JS greške: ${pageErrors.join(" | ")}`);

    if (!process.exitCode) {
        process.stdout.write(
            `✓ Mapa se učitava i inicijalizuje bez grešaka (${target}).\n`,
        );
    }
} catch (error) {
    fail(`Smoke test nije prošao: ${error.message}`);
    await page.screenshot({
        path: "storage/logs/map-smoke-failure.png",
        fullPage: true,
    });
    if (pageErrors.length > 0)
        process.stderr.write(`  JS greške: ${pageErrors.join(" | ")}\n`);
} finally {
    await browser.close();
}
