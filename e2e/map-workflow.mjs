// Isolated browser workflow for the map editor. The test creates its own
// temporary project, draws an ODF, a house and a route, verifies draft restore
// and permanent persistence, and deletes the temporary project in `finally`.
import { chromium } from "playwright";

const baseUrl = process.env.BASE_URL ?? "http://127.0.0.1:8000";
const username = process.env.E2E_USERNAME;
const password = process.env.E2E_PASSWORD;
const runId = `${Date.now()}-${Math.random().toString(36).slice(2, 8)}`;
const projectName = `Codex Map Audit ${runId}`;

if (!username || !password) {
    process.stderr.write(
        "✗ E2E_USERNAME i E2E_PASSWORD su obavezni za prijavu.\n",
    );
    process.exit(1);
}

const browser = await chromium.launch();
const page = await browser.newPage({ viewport: { width: 1440, height: 900 } });
let projectId = null;

async function csrfFetch(path, options = {}) {
    return page.evaluate(
        async ({ path, options }) => {
            const token = document.querySelector(
                'meta[name="csrf-token"]',
            )?.content;
            const response = await fetch(path, {
                ...options,
                headers: {
                    Accept: "application/json",
                    "X-CSRF-TOKEN": token,
                    "X-Requested-With": "XMLHttpRequest",
                    ...(options.headers || {}),
                },
            });
            const text = await response.text();
            let body = null;
            try {
                body = JSON.parse(text);
            } catch {
                body = text;
            }
            return { ok: response.ok, status: response.status, body };
        },
        { path, options },
    );
}

try {
    await page.goto(`${baseUrl}/prijava`, {
        waitUntil: "networkidle",
        timeout: 30000,
    });
    await page.fill('input[name="username"]', username);
    await page.fill('input[name="password"]', password);
    await Promise.all([
        page.waitForURL(`${baseUrl}/**`, { timeout: 15000 }),
        page.click('button[type="submit"]'),
    ]);

    const created = await csrfFetch("/projekti", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({
            name: projectName,
            code: `E2E-${runId}`,
            location: "Automatski browser test",
            status: "planning",
        }),
    });
    if (!created.ok || !created.body?.project?.id) {
        throw new Error(
            `Kreiranje testnog projekta nije uspjelo (HTTP ${created.status}).`,
        );
    }
    projectId = created.body.project.id;

    await page.goto(`${baseUrl}/mapa?project=${projectId}`, {
        waitUntil: "networkidle",
        timeout: 30000,
    });
    await page.waitForSelector("#network-map.leaflet-container", {
        timeout: 15000,
    });
    await page.waitForFunction(
        (id) =>
            document.querySelector("#active-project-id")?.value === String(id),
        projectId,
    );

    await page.click("#project-snapshot-btn");
    await page.waitForSelector("#snapshot-overlay:not(.hidden)");
    await page.fill("#snapshot-label", "E2E početna kopija");
    await page.click("#snapshot-create");
    await page.waitForFunction(() =>
        [...document.querySelectorAll(".snapshot-row strong")].some((node) =>
            node.textContent.includes("E2E početna kopija"),
        ),
    );
    const downloadHref = await page.getAttribute(".snapshot-download", "href");
    if (!downloadHref)
        throw new Error("Backup modal nije prikazao JSON download link.");
    const downloadResponse = await page.request.get(
        new URL(downloadHref, baseUrl).toString(),
    );
    if (!downloadResponse.ok())
        throw new Error(`JSON backup vraća HTTP ${downloadResponse.status()}.`);
    const backupPayload = await downloadResponse.json();
    if (
        backupPayload.format !== "ftth-manager-project-snapshot" ||
        backupPayload.version !== 1
    )
        throw new Error("JSON backup nema očekivani format/verziju.");
    await page.click("#snapshot-close");

    // Čekaj da se toolbar učita nakon što se modal zatvori
    await page.waitForSelector("#survey-panel-btn", { timeout: 15000 });
    await page.click("#survey-panel-btn");
    // Panel trebam biti skrit inicijalno, čekaj da se pokaže (uklanja .hidden klasu)
    await page.waitForFunction(
        () =>
            !document
                .querySelector("#survey-panel")
                ?.classList.contains("hidden"),
        { timeout: 15000 },
    );
    if (!(await page.isEnabled("#survey-choose-btn")))
        throw new Error("TXT panel nije spreman za izbor fajla.");
    await page.click("#survey-panel-close", { force: true });

    await page.evaluate(() =>
        renderDraftPreflight(
            collectDraftPreflightIssues({
                odfs: [],
                cabinets: [
                    { name: "FTTH TEST", odf_index: null, odf_id: null },
                ],
                routes: [{ name: "Neispravna trasa", path: [] }],
            }),
        ),
    );
    const preflightCount = await page
        .locator("#preflight-list [data-preflight-index]")
        .count();
    if (preflightCount !== 3)
        throw new Error(
            `Kontrola prije spremanja očekuje 3 problema, prikazano: ${preflightCount}.`,
        );
    await page.evaluate(() => renderDraftPreflight([]));

    await page.click("#mode-odf");
    await page.evaluate(() =>
        window.ftthNetworkMap.fire("click", {
            latlng: L.latLng(44.4493, 18.6498),
        }),
    );
    await page.waitForSelector("#element-editor:not(.hidden)");
    await page.fill("#element-editor-name", "ODF E2E Centar");
    await page.fill("#element-editor-address", "Testna lokacija 1");
    await page.selectOption("#element-editor-fiber-capacity", "288");
    await page.fill("#element-editor-port-count", "96");
    await page.click("#save-element-name");

    await page.evaluate(() => {
        document.querySelector("#route-draw-type").closest("details").open =
            true;
    });
    await page.selectOption("#route-draw-type", "distribution");
    await page.fill("#route-draw-name", "E2E sekundarna trasa");
    await page.click("#mode-draw");
    await page.evaluate(() => {
        window.ftthNetworkMap.fire("click", {
            latlng: L.latLng(44.4493, 18.6498),
        });
        window.ftthNetworkMap.fire("click", {
            latlng: L.latLng(44.44975, 18.65035),
        });
    });
    await page.keyboard.press("Enter");

    await page.click("#mode-house");
    await page.evaluate(() =>
        window.ftthNetworkMap.fire("click", {
            latlng: L.latLng(44.44982, 18.65042),
        }),
    );
    await page.waitForSelector("#element-editor:not(.hidden)");
    await page.fill("#element-editor-name", "K-E2E-001");
    await page.fill("#element-editor-address", "Testna kuća 1");
    await page.click("#save-element-name");

    const draftPlan = JSON.parse(await page.inputValue("#bulk-plan-json"));
    if (
        draftPlan.odfs.length !== 1 ||
        draftPlan.houses.length !== 1 ||
        draftPlan.routes.length !== 1
    ) {
        throw new Error(
            `Neočekivan nacrt: ${draftPlan.odfs.length} ODF, ${draftPlan.houses.length} kuća, ${draftPlan.routes.length} trasa.`,
        );
    }
    if (
        draftPlan.odfs[0].fiber_capacity !== 288 ||
        draftPlan.odfs[0].port_count !== 96 ||
        draftPlan.odfs[0].address !== "Testna lokacija 1"
    ) {
        throw new Error(
            `Pametni ODF editor nije sačuvao tehničke podatke: ${JSON.stringify(draftPlan.odfs[0])}.`,
        );
    }
    if (
        draftPlan.houses[0].label !== "K-E2E-001" ||
        draftPlan.houses[0].address !== "Testna kuća 1"
    ) {
        throw new Error(
            `Pametni editor kuće nije sačuvao podatke: ${JSON.stringify(draftPlan.houses[0])}.`,
        );
    }

    const draftResponsePromise = page.waitForResponse(
        (response) =>
            response.url().includes("/mapa/draft") &&
            response.request().method() === "POST",
        { timeout: 15000 },
    );
    await page.click("#save-draft");
    const draftResponse = await draftResponsePromise;
    if (!draftResponse.ok())
        throw new Error(`Čuvanje nacrta vraća HTTP ${draftResponse.status()}.`);

    await page.reload({ waitUntil: "networkidle", timeout: 30000 });
    await page.waitForSelector("#network-map.leaflet-container", {
        timeout: 15000,
    });
    await page.waitForFunction(
        () => {
            try {
                const plan = JSON.parse(
                    document.querySelector("#bulk-plan-json")?.value || "{}",
                );
                return (
                    plan.odfs?.length === 1 &&
                    plan.houses?.length === 1 &&
                    plan.routes?.length === 1
                );
            } catch {
                return false;
            }
        },
        null,
        { timeout: 15000 },
    );

    const planResponsePromise = page.waitForResponse(
        (response) =>
            response.url().includes("/mapa/plan") &&
            response.request().method() === "POST",
        { timeout: 20000 },
    );
    const reloadPromise = page.waitForNavigation({
        waitUntil: "networkidle",
        timeout: 20000,
    });
    await page.click("#bulk-plan-form .sb-btn-emerald");
    const planResponse = await planResponsePromise;
    if (!planResponse.ok())
        throw new Error(
            `Trajno čuvanje plana vraća HTTP ${planResponse.status()}.`,
        );
    await reloadPromise;
    await page.waitForSelector("#network-map.leaflet-container", {
        timeout: 15000,
    });

    const persisted = await page.evaluate(() => ({
        odfs: window.ftthMapConfig.data.odfs.filter(
            (item) =>
                item.name === "ODF E2E Centar" &&
                Number(item.fiber_capacity) === 288,
        ).length,
        houses: window.ftthMapConfig.data.houses.filter(
            (item) => item.label === "K-E2E-001",
        ).length,
        routes: window.ftthMapConfig.data.routes.filter(
            (item) => item.name === "E2E sekundarna trasa",
        ).length,
    }));
    if (
        persisted.odfs !== 1 ||
        persisted.houses !== 1 ||
        persisted.routes !== 1
    ) {
        throw new Error(
            `Trajni podaci nisu očekivani: ${JSON.stringify(persisted)}.`,
        );
    }

    await page.evaluate(() => {
        const odf = window.ftthMapConfig.data.odfs.find(
            (item) => item.name === "ODF E2E Centar",
        );
        selectDraftElement("odf", {
            ...odf,
            marker: odfMarkerById[odf.id],
            saved: true,
        });
    });
    await page.fill("#element-editor-address", "Trajno uređeno na mapi");
    await page.fill("#element-editor-port-count", "144");
    const savedEditResponsePromise = page.waitForResponse(
        (response) =>
            response.url().includes("/odf/") &&
            response.request().method() === "PATCH",
        { timeout: 15000 },
    );
    await page.click("#save-element-name");
    const savedEditResponse = await savedEditResponsePromise;
    if (!savedEditResponse.ok())
        throw new Error(
            `Direktno uređivanje spremljenog ODF-a vraća HTTP ${savedEditResponse.status()}.`,
        );
    await page.waitForFunction(() =>
        document
            .querySelector("#element-editor-status")
            ?.textContent.includes("Trajne izmjene"),
    );

    await page.evaluate(() => {
        const route = window.ftthMapConfig.data.routes.find(
            (item) => item.name === "E2E sekundarna trasa",
        );
        openRouteAttributePanel(route);
    });
    await page.selectOption("#route-attr-installation", "aerial");
    await page.fill("#route-attr-microduct-count", "2");
    const routeAttributeResponsePromise = page.waitForResponse(
        (response) =>
            response.url().includes("/trase/") &&
            response.request().method() === "PATCH",
        { timeout: 15000 },
    );
    await page.click("#save-route-attributes");
    const routeAttributeResponse = await routeAttributeResponsePromise;
    if (!routeAttributeResponse.ok())
        throw new Error(
            `Direktno uređivanje atributa trase vraća HTTP ${routeAttributeResponse.status()}.`,
        );
    await page.waitForFunction(() =>
        document
            .querySelector("#route-attribute-status")
            ?.textContent.includes("sačuvani"),
    );

    await page.evaluate(() => {
        document.querySelector("#suggest-cabinets").closest("details").open =
            true;
    });
    const previewResponsePromise = page.waitForResponse(
        (response) =>
            response.url().includes("/odo-plan/preview") &&
            response.request().method() === "POST",
        { timeout: 20000 },
    );
    await page.click("#suggest-cabinets");
    const previewResponse = await previewResponsePromise;
    if (!previewResponse.ok()) {
        throw new Error(
            `Auto ODO preview vraća HTTP ${previewResponse.status()}: ${await previewResponse.text()}`,
        );
    }
    await page.waitForSelector("#save-suggestions:not(.hidden)", {
        timeout: 15000,
    });

    const confirmResponsePromise = page.waitForResponse(
        (response) =>
            response.url().includes("/odo-plan/confirm") &&
            response.request().method() === "POST",
        { timeout: 20000 },
    );
    const autoOdoReloadPromise = page.waitForNavigation({
        waitUntil: "networkidle",
        timeout: 20000,
    });
    await page.click("#save-suggestions");
    const confirmResponse = await confirmResponsePromise;
    if (!confirmResponse.ok()) {
        throw new Error(
            `Auto ODO potvrda vraća HTTP ${confirmResponse.status()}: ${await confirmResponse.text()}`,
        );
    }
    await autoOdoReloadPromise;
    await page.waitForSelector("#network-map.leaflet-container", {
        timeout: 15000,
    });

    const linked = await page.evaluate(() => ({
        cabinets: window.ftthMapConfig.data.cabinets.length,
        linkedHouses: window.ftthMapConfig.data.houses.filter(
            (item) => item.cabinet_id,
        ).length,
        dropRoutes: window.ftthMapConfig.data.routes.filter(
            (item) => item.type === "drop",
        ).length,
    }));
    if (linked.cabinets < 1 || linked.linkedHouses !== 1) {
        throw new Error(
            `Auto ODO nije trajno povezao mrežu: ${JSON.stringify(linked)}.`,
        );
    }

    await page.evaluate(() => {
        const cabinet = window.ftthMapConfig.data.cabinets[0];
        selectDraftElement("cabinet", {
            ...cabinet,
            marker: cabinetMarkerById[cabinet.id],
            saved: true,
        });
    });
    await page.fill("#element-editor-address", "ODO uređen direktno na mapi");
    const cabinetEditPromise = page.waitForResponse(
        (response) =>
            response.url().includes("/ormarici/") &&
            response.request().method() === "PATCH",
        { timeout: 15000 },
    );
    await page.click("#save-element-name");
    if (!(await cabinetEditPromise).ok())
        throw new Error(
            "Direktno uređivanje spremljenog ODO ormarića nije uspjelo.",
        );

    await page.evaluate(() => {
        const house = window.ftthMapConfig.data.houses[0];
        selectDraftElement("house", {
            ...house,
            marker: houseMarkerById[house.id],
            saved: true,
        });
    });
    await page.fill("#element-editor-address", "Kuća uređena direktno na mapi");
    const houseEditPromise = page.waitForResponse(
        (response) =>
            response.url().includes("/kuce/") &&
            response.request().method() === "PATCH",
        { timeout: 15000 },
    );
    await page.click("#save-element-name");
    if (!(await houseEditPromise).ok())
        throw new Error("Direktno uređivanje spremljene kuće nije uspjelo.");

    await page.evaluate(() => {
        document.querySelector("#run-project-check").closest("details").open =
            true;
    });
    const dropResponsePromise = page.waitForResponse(
        (response) =>
            response.url().includes("/drop-trase/popuni") &&
            response.request().method() === "POST",
        { timeout: 15000 },
    );
    await page.click("#fill-missing-drops");
    const dropResponse = await dropResponsePromise;
    if (!dropResponse.ok())
        throw new Error(
            `Popunjavanje drop trasa vraća HTTP ${dropResponse.status()}.`,
        );
    const dropResult = await dropResponse.json();
    if (dropResult.created !== 1)
        throw new Error(
            `Očekivana je jedna nova drop trasa, kreirano: ${dropResult.created}.`,
        );

    const validationResponsePromise = page.waitForResponse(
        (response) =>
            response.url().includes("/validacija") &&
            response.request().method() === "GET",
        { timeout: 15000 },
    );
    await page.click("#run-project-check");
    const validationResponse = await validationResponsePromise;
    if (!validationResponse.ok())
        throw new Error(
            `Validacija vraća HTTP ${validationResponse.status()}.`,
        );
    await page.waitForFunction(
        () =>
            !document
                .querySelector("#project-check-summary")
                ?.textContent.includes("Provjeravam"),
    );
    const validation = await validationResponse.json();
    if (!Array.isArray(validation.items))
        throw new Error("Validacija nije vratila listu stavki.");

    const geoJson = await page.evaluate(async (id) => {
        const response = await fetch(`/projekti/${id}/geojson`, {
            headers: { Accept: "application/json" },
        });
        return { status: response.status, body: await response.json() };
    }, projectId);
    if (
        geoJson.status !== 200 ||
        geoJson.body.type !== "FeatureCollection" ||
        geoJson.body.features.length < 4
    ) {
        throw new Error(
            `GeoJSON izvoz nije potpun: HTTP ${geoJson.status}, ${geoJson.body.features?.length ?? 0} elemenata.`,
        );
    }

    const exports = await page.evaluate(
        async ({ id, expectedProjectName }) => {
            const csrf =
                document.querySelector('meta[name="csrf-token"]')?.content ||
                "";
            const inspectBinary = async (url, options = {}) => {
                const response = await fetch(url, options);
                const bytes = new Uint8Array(await response.arrayBuffer());
                return {
                    status: response.status,
                    type: response.headers.get("content-type") || "",
                    disposition:
                        response.headers.get("content-disposition") || "",
                    size: bytes.length,
                    prefix: new TextDecoder().decode(bytes.slice(0, 24)),
                    text: new TextDecoder().decode(bytes),
                };
            };

            const networkDxf = await inspectBinary(`/projekti/${id}/dxf`, {
                method: "POST",
                headers: {
                    "Content-Type": "application/json",
                    "X-CSRF-TOKEN": csrf,
                },
                body: JSON.stringify({ background_layers: [] }),
            });
            const fiberDxf = await inspectBinary(
                `/projekti/${id}/fiber-schema-dxf`,
            );
            const pdf = await inspectBinary(`/projekti/${id}/fiber-sema/pdf`);
            const backup = await inspectBinary("/postavke/backup");
            const printResponse = await fetch(`/projekti/${id}/print`);
            const printHtml = await printResponse.text();

            return {
                networkDxf,
                fiberDxf,
                pdf: { ...pdf, text: undefined },
                backup: { ...backup, text: undefined },
                print: {
                    status: printResponse.status,
                    type: printResponse.headers.get("content-type") || "",
                    hasProject: printHtml.includes(expectedProjectName),
                    hasMaterialSummary: printHtml.includes(
                        "Materijalni obračun",
                    ),
                    hasInvestorSummary:
                        printHtml.includes("Sažetak za investitora") &&
                        printHtml.includes("Spremnost projekta"),
                    size: printHtml.length,
                },
            };
        },
        { id: projectId, expectedProjectName: projectName },
    );

    if (
        exports.networkDxf.status !== 200 ||
        !exports.networkDxf.type.includes("application/dxf") ||
        exports.networkDxf.size < 500 ||
        !exports.networkDxf.text.includes("SECTION") ||
        !exports.networkDxf.text.includes("EOF")
    ) {
        throw new Error(
            `Mrežni DXF nije validan: ${JSON.stringify({ status: exports.networkDxf.status, type: exports.networkDxf.type, size: exports.networkDxf.size })}.`,
        );
    }
    if (
        exports.fiberDxf.status !== 200 ||
        !exports.fiberDxf.type.includes("application/dxf") ||
        exports.fiberDxf.size < 500 ||
        !exports.fiberDxf.text.includes("SECTION") ||
        !exports.fiberDxf.text.includes("EOF")
    ) {
        throw new Error(
            `Fiber-schema DXF nije validan: ${JSON.stringify({ status: exports.fiberDxf.status, type: exports.fiberDxf.type, size: exports.fiberDxf.size })}.`,
        );
    }
    if (
        exports.pdf.status !== 200 ||
        !exports.pdf.type.includes("application/pdf") ||
        exports.pdf.size < 1000 ||
        !exports.pdf.prefix.startsWith("%PDF")
    ) {
        throw new Error(
            `Fiber PDF nije validan: ${JSON.stringify(exports.pdf)}.`,
        );
    }
    if (
        exports.backup.status !== 200 ||
        !exports.backup.type.includes("application/octet-stream") ||
        exports.backup.size < 1000 ||
        !exports.backup.prefix.startsWith("SQLite format 3")
    ) {
        throw new Error(
            `SQLite backup nije validan: ${JSON.stringify(exports.backup)}.`,
        );
    }
    if (
        exports.print.status !== 200 ||
        !exports.print.type.includes("text/html") ||
        exports.print.size < 500 ||
        !exports.print.hasProject ||
        !exports.print.hasMaterialSummary ||
        !exports.print.hasInvestorSummary
    ) {
        throw new Error(
            `Print prikaz nije potpun: ${JSON.stringify(exports.print)}.`,
        );
    }

    process.stdout.write(
        "✓ Mapa, Auto ODO, validacija, svi izvozi i SQLite backup rade.\n",
    );
} catch (error) {
    process.stderr.write(`✗ Map workflow nije prošao: ${error.message}\n`);
    await page
        .screenshot({
            path: "storage/logs/map-workflow-failure.png",
            fullPage: true,
        })
        .catch(() => {});
    process.exitCode = 1;
} finally {
    if (projectId) {
        await page
            .goto(`${baseUrl}/projekti`, {
                waitUntil: "networkidle",
                timeout: 30000,
            })
            .catch(() => {});
        await csrfFetch(`/projekti/${projectId}`, {
            method: "DELETE",
        }).catch(() => null);
        const verification = await page
            .evaluate(async (id) => {
                const response = await fetch(`/projekti/${id}/pregled`, {
                    headers: { Accept: "application/json" },
                });
                return response.status;
            }, projectId)
            .catch(() => null);
        if (verification !== 404) {
            process.stderr.write(
                `✗ Privremeni projekat ${projectId} nije automatski uklonjen.\n`,
            );
            process.exitCode = 1;
        }
    }
    await browser.close();
}
