// Local Leaflet fixture: tests the actual export without login or network tiles.
import { chromium } from 'playwright';
import assert from 'node:assert/strict';
import { mkdir } from 'node:fs/promises';

const browser = await chromium.launch();
try {
    const page = await browser.newPage({ viewport: { width: 1100, height: 850 } });
    const errors = [];
    page.on('pageerror', error => errors.push(error.message));
    await page.setContent('<button id="btn-map-print">PDF područje</button><select id="active-project-id"><option value="1">Test &lt;projekat&gt;</option></select><div id="network-map" style="width:1000px;height:700px"></div>');
    await page.addStyleTag({ path: 'public/vendor/leaflet/leaflet.css' });
    await page.addScriptTag({ path: 'public/vendor/leaflet/leaflet.js' });
    await page.addScriptTag({ path: 'public/js/map/area-print.js' });
    await page.evaluate(() => {
        window.ftthMapConfig = { permissions: { export: true } };
        const map = window.ftthNetworkMap = L.map('network-map', { zoomAnimation: false }).setView([44.4, 18.4], 17);
        const Tiles = L.GridLayer.extend({ createTile() {
            const tile = document.createElement('canvas');
            tile.width = tile.height = 256;
            const context = tile.getContext('2d');
            context.fillStyle = '#cce2bb'; context.fillRect(0, 0, 256, 256);
            context.strokeStyle = '#eeeeee'; context.lineWidth = 20;
            context.beginPath(); context.moveTo(0, 0); context.lineTo(256, 256); context.stroke();
            return tile;
        } });
        new Tiles({ attribution: 'Test podloga' }).addTo(map);
        L.polyline([[44.399, 18.395], [44.4, 18.4], [44.401, 18.405]], { color: '#ef7b00', weight: 4 }).addTo(map);
        L.marker([44.4, 18.4], { icon: L.divIcon({ html: '<b style="background:white;color:#111;padding:4px">ODO 1</b>', iconSize: [70, 22] }) }).addTo(map);
        // A translated map pane exercises pan transforms in the printed clone.
        map.panBy([40, 20], { animate: false });
        initMapAreaPrint();
    });
    const bounds = await page.locator('#network-map').boundingBox();
    await page.locator('#btn-map-print').click();
    assert.equal(await page.getByRole('button', { name: 'Pregled PDF-a' }).isDisabled(), true);
    const before = await page.evaluate(() => window.ftthNetworkMap.getCenter());
    await page.mouse.move(bounds.x + 150, bounds.y + 140);
    await page.mouse.down();
    await page.mouse.move(bounds.x + 850, bounds.y + 530, { steps: 8 });
    await page.mouse.up();
    assert.deepEqual(await page.evaluate(() => window.ftthNetworkMap.getCenter()), before);
    const popupPromise = page.waitForEvent('popup');
    await page.getByRole('button', { name: 'Pregled PDF-a' }).click();
    const popup = await popupPromise;
    popup.on('pageerror', error => errors.push(error.message));
    await popup.getByRole('button', { name: 'Štampaj / Sačuvaj PDF' }).waitFor();
    assert.equal(await popup.getByRole('button', { name: 'Štampaj / Sačuvaj PDF' }).isEnabled(), true);
    assert.equal(await popup.locator('h1').textContent(), 'Test <projekat>');
    assert.equal(await popup.locator('#map-pdf-selection, .leaflet-control-container').count(), 0);
    assert.match(await popup.locator('footer').textContent(), /Test podloga/);
    const geometry = await popup.evaluate(() => {
        const crop = document.getElementById('crop');
        const copy = document.getElementById('map-copy');
        return { left: getComputedStyle(copy).left, top: getComputedStyle(copy).top, ratio: crop.getBoundingClientRect().width / crop.getBoundingClientRect().height, paths: copy.querySelectorAll('path').length, images: copy.querySelectorAll('img').length };
    });
    assert.equal(geometry.left, '-150px');
    assert.equal(geometry.top, '-140px');
    assert.ok(Math.abs(geometry.ratio - 700 / 390) < 0.001);
    assert.ok(geometry.paths > 0 && geometry.images > 0);
    await popup.emulateMedia({ media: 'print' });
    assert.equal(await popup.locator('.tools').isVisible(), false);
    await mkdir('storage/framework/testing/map-pdf', { recursive: true });
    await popup.screenshot({ path: 'storage/framework/testing/map-pdf/preview.png', fullPage: true });
    const pdf = await popup.pdf({ path: 'storage/framework/testing/map-pdf/selection.pdf', preferCSSPageSize: true, printBackground: true });
    assert.ok(pdf.length > 3000);
    assert.equal((pdf.toString('latin1').match(/\/Type\s*\/Page\b/g) || []).length, 1);
    // Redraw bottom-right to top-left and verify a portrait export too.
    await page.mouse.move(bounds.x + 650, bounds.y + 650);
    await page.mouse.down();
    await page.mouse.move(bounds.x + 400, bounds.y + 120, { steps: 5 });
    await page.mouse.up();
    const portraitPromise = page.waitForEvent('popup');
    await page.getByRole('button', { name: 'Pregled PDF-a' }).click();
    const portrait = await portraitPromise;
    await portrait.getByRole('button', { name: 'Štampaj / Sačuvaj PDF' }).waitFor();
    const portraitPdf = await portrait.pdf({ preferCSSPageSize: true, printBackground: true });
    assert.equal((portraitPdf.toString('latin1').match(/\/Type\s*\/Page\b/g) || []).length, 1);
    assert.match(await portrait.locator('head style').textContent(), /A4 portrait/);
    await page.keyboard.press('Escape');
    assert.equal(await page.locator('#map-pdf-selection').count(), 0);
    assert.deepEqual(await page.evaluate(() => window.ftthNetworkMap.getCenter()), before);
    assert.deepEqual(errors, []);
    console.log('PASS: selected extent, map layers, canvas tiles, PDF page, cancellation.');
} finally {
    await browser.close();
}
