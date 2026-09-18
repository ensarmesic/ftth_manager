// Capture the rendered map without re-fitting bounds: the selected pixels are
// the printed extent, including clipped labels and the currently visible layers.
function initMapAreaPrint() {
    const button = document.getElementById('btn-map-print');
    if (!button || !window.ftthMapConfig.permissions.export) return;
    let cancelSelection = null;
    button.addEventListener('click', () => {
        if (cancelSelection) { cancelSelection(); return; }
        const map = window.ftthNetworkMap;
        map.stop();
        const container = map.getContainer();
        const overlay = document.createElement('div');
        overlay.id = 'map-pdf-selection';
        overlay.style.cssText = 'position:absolute;inset:0;z-index:10000;cursor:crosshair;touch-action:none;background:#0f172a22';
        const box = document.createElement('div');
        box.style.cssText = 'position:absolute;border:2px solid #0284c7;background:#38bdf822;pointer-events:none;display:none;box-sizing:border-box';
        const bar = document.createElement('div');
        bar.style.cssText = 'position:absolute;top:12px;left:12px;right:12px;background:white;color:#0f172a;padding:12px;border-radius:8px;box-shadow:0 2px 12px #0004;font:13px Arial;cursor:default;display:flex;gap:12px;align-items:center;flex-wrap:wrap';
        const help = document.createElement('span');
        help.textContent = 'Povuci pravougaonik preko dijela mape za PDF.';
        help.style.flex = '1';
        const preview = document.createElement('button');
        preview.type = 'button';
        preview.textContent = 'Pregled PDF-a';
        preview.disabled = true;
        preview.style.cssText = 'padding:8px 12px;background:#0369a1;color:white;border:0;border-radius:5px;cursor:pointer';
        const cancel = document.createElement('button');
        cancel.type = 'button';
        cancel.textContent = 'Otkaži';
        cancel.style.cssText = 'padding:8px;border:1px solid #cbd5e1;border-radius:5px;cursor:pointer';
        bar.append(help, preview, cancel);
        overlay.append(box, bar);
        container.append(overlay);
        let start = null, selection = null;
        const point = event => {
            const bounds = container.getBoundingClientRect();
            return { x: Math.max(0, Math.min(container.clientWidth, event.clientX - bounds.left)), y: Math.max(0, Math.min(container.clientHeight, event.clientY - bounds.top)) };
        };
        const update = event => {
            const end = point(event);
            selection = { x: Math.min(start.x, end.x), y: Math.min(start.y, end.y), width: Math.abs(end.x - start.x), height: Math.abs(end.y - start.y) };
            Object.assign(box.style, { display: 'block', left: `${selection.x}px`, top: `${selection.y}px`, width: `${selection.width}px`, height: `${selection.height}px` });
            preview.disabled = selection.width < 30 || selection.height < 30;
        };
        ['click', 'dblclick', 'contextmenu', 'wheel', 'pointerdown', 'pointermove', 'pointerup', 'mousedown', 'mouseup', 'touchstart', 'touchmove', 'touchend'].forEach(type => overlay.addEventListener(type, event => {
            event.stopPropagation();
            if (type === 'wheel' || type === 'contextmenu') event.preventDefault();
        }, { passive: false }));
        overlay.addEventListener('pointerdown', event => {
            if (bar.contains(event.target) || event.button !== 0) return;
            start = point(event);
            overlay.setPointerCapture(event.pointerId);
            update(event);
        });
        overlay.addEventListener('pointermove', event => { if (start) update(event); });
        overlay.addEventListener('pointerup', event => {
            if (!start) return;
            update(event);
            start = null;
            overlay.releasePointerCapture(event.pointerId);
            help.textContent = preview.disabled ? 'Odaberi veće područje.' : 'Područje je odabrano. Za promjenu povuci novi pravougaonik.';
        });
        overlay.addEventListener('pointercancel', () => { start = null; });
        const onKey = event => {
            // Do not send selection keystrokes to the CAD editing shortcuts.
            if (event.key === 'Escape') { event.preventDefault(); cancelSelection(); }
            if (event.key !== 'Tab') event.stopImmediatePropagation();
        };
        document.addEventListener('keydown', onKey, true);
        cancelSelection = () => {
            overlay.remove();
            document.removeEventListener('keydown', onKey, true);
            window.removeEventListener('resize', cancelSelection);
            cancelSelection = null;
            button.focus();
        };
        window.addEventListener('resize', cancelSelection);
        cancel.addEventListener('click', () => cancelSelection());
        preview.addEventListener('click', () => {
            try { openMapAreaPrint(container, selection); }
            catch (error) { help.textContent = error.message; }
        });
        cancel.focus();
    });
}

function copyMapPrintNode(source) {
    if (source.matches?.('.leaflet-popup-pane, script')) return null;
    if (source.nodeType !== Node.ELEMENT_NODE) return source.cloneNode(true);
    const clone = source.tagName === 'CANVAS' ? document.createElement('img') : source.cloneNode(false);
    const computed = getComputedStyle(source);
    for (const property of computed) clone.style.setProperty(property, computed.getPropertyValue(property));
    clone.style.setProperty('animation', 'none');
    clone.style.setProperty('transition', 'none');
    for (const attribute of [...clone.attributes]) {
        if (attribute.name.startsWith('on') || attribute.name === 'id') clone.removeAttribute(attribute.name);
    }
    if (source.tagName === 'CANVAS') {
        try { clone.src = source.toDataURL(); }
        catch { throw new Error('Jedan sloj mape nije moguće izvesti. Isključi taj sloj i pokušaj ponovo.'); }
    } else {
        if (source.tagName === 'IMG') clone.src = source.currentSrc || source.src;
        source.childNodes.forEach(child => {
            const copied = copyMapPrintNode(child);
            if (copied) clone.append(copied);
        });
    }
    return clone;
}

function openMapAreaPrint(container, area) {
    if (!area || area.width < 30 || area.height < 30) return;
    const mapPane = container.querySelector('.leaflet-map-pane');
    if (!mapPane) throw new Error('Mapa još nije spremna.');
    const clone = copyMapPrintNode(mapPane);
    const preview = window.open('', '_blank');
    if (!preview) throw new Error('Dozvoli otvaranje novog prozora za pregled PDF-a.');
    preview.opener = null;
    const doc = preview.document;
    doc.open();
    doc.write('<!doctype html><html lang="bs"><head><meta charset="utf-8"><title>Odabrani dio mape</title></head><body></body></html>');
    doc.close();
    const landscape = area.width >= area.height;
    const pageWidth = landscape ? 277 : 190;
    const pageHeight = landscape ? 190 : 277;
    const scale = Math.min(pageWidth * 96 / 25.4 / area.width, (pageHeight - 25) * 96 / 25.4 / area.height);
    const style = doc.createElement('style');
    style.textContent = `@page{size:A4 ${landscape ? 'landscape' : 'portrait'};margin:10mm}*{box-sizing:border-box}body{margin:0;background:#e2e8f0;font:12px Arial;color:#0f172a}main{width:${pageWidth}mm;margin:16px auto;background:white;padding:0}h1{font-size:16px;margin:0 0 8px}header{padding:10px 0}footer{font-size:9px;padding:8px 0;overflow-wrap:anywhere}.tools{padding:14px;text-align:center}.tools button{padding:10px 18px;cursor:pointer}#crop{position:relative;overflow:hidden;width:${area.width * scale}px;height:${area.height * scale}px;isolation:isolate;background:${getComputedStyle(container).backgroundColor}}#scale{position:absolute;transform:scale(${scale});transform-origin:0 0;width:${area.width}px;height:${area.height}px}#map-copy{position:absolute;left:${-area.x}px;top:${-area.y}px;width:${container.clientWidth}px;height:${container.clientHeight}px;overflow:hidden}#map-copy *{print-color-adjust:exact!important;-webkit-print-color-adjust:exact!important}@media print{body{background:white}.tools{display:none}main{margin:0;break-inside:avoid}}`;
    doc.head.append(style);
    const tools = doc.createElement('div');
    tools.className = 'tools';
    const print = doc.createElement('button');
    print.textContent = 'Učitavam podlogu…';
    print.disabled = true;
    print.addEventListener('click', () => preview.print());
    const instructions = doc.createElement('p');
    instructions.textContent = 'U dijalogu odaberi „Sačuvaj kao PDF“. Isključi zaglavlja i podnožja preglednika.';
    tools.append(print, instructions);
    const main = doc.createElement('main');
    const header = doc.createElement('header');
    const title = doc.createElement('h1');
    const project = document.getElementById('active-project-id');
    title.textContent = project?.value ? project.selectedOptions[0].textContent : 'Odabrani dio mape';
    header.append(title);
    const crop = doc.createElement('div'); crop.id = 'crop';
    const scaled = doc.createElement('div'); scaled.id = 'scale';
    const mapCopy = doc.createElement('div'); mapCopy.id = 'map-copy';
    mapCopy.append(doc.adoptNode(clone)); scaled.append(mapCopy); crop.append(scaled);
    const footer = doc.createElement('footer');
    footer.textContent = container.querySelector('.leaflet-control-attribution')?.textContent || '';
    main.append(header, crop, footer); doc.body.append(tools, main);
    Promise.all([...mapCopy.querySelectorAll('img')].map(img => img.decode().catch(() => { throw new Error('Dio podloge nije učitan. Zatvori pregled, sačekaj učitavanje mape i pokušaj ponovo.'); })))
        .then(() => { print.disabled = false; print.textContent = 'Štampaj / Sačuvaj PDF'; })
        .catch(error => { instructions.textContent = error.message; print.textContent = 'Podloga nije učitana'; });
}
