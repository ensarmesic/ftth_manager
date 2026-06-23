const data = window.ftthMapConfig.data;
const appConfig = window.ftthMapConfig.endpoints;
const defaultCenter = [44.4493, 18.6498];
const map = L.map('network-map', { zoomSnap: 0.25 }).setView(defaultCenter, 17);
window.ftthNetworkMap = map;
let mode = 'pan';
let mapViewMode = 'cad';
let activeBranch = [];
let activeBranchSnapTargets = [];
let quickBranchWorkflow = false;
let activeBranchMarkers = [];
let activeBranchLine = null;
let previewBranchLine = null;
let snapIndicator = null;
let routeEdit = null;
let selectedAttributeRoute = null;
const selectionRegistry = []; // { triggerLayer, allLayers, url, title, origStyle }
let currentSelection = [];    // trenutno selektovani elementi
let connectOdf = null;
let connectCabinet = null;
let connectHouseIds = new Set();
let joinRoutes = [];
let validationHighlightLayers = [];
let branches = [];
let branchLines = [];
let branchMeta = [];
let branchLabels = [];
let branchLabelGroups = [];
let trenchLines = [];
let housePoints = [];
let houseMarkers = [];
let houseMarkerByKey = {};
let houseMarkerById = {};
let suggestionLayers = [];
let draftOdfCount = 0;
let draftCabinetCount = 0;
let draftElements = [];
let draftOdfs = [];
let draftCabinets = [];
let draftAppendixItems = [];
let suggestedCabinets = [];
let expandedMap = false;
let activeDraftOdfIndex = null;
let activeOdfSelection = null;
let cadContext = null;
let autosaveTimer = null;
let autosaveReady = false;
let restoringDraft = false;
let selectedDraftElement = null;
let keepCurrentDraftOnProjectChange = false;
let savedRoutePoints = [];
const snapPixelTolerance = 30;
let orthoEnabled = false;
let osmRoutingEnabled = false;
let osmRoutingLoading = false;
let currentAutoPlan = null;
const undoStack = [];
const redoStack = [];
const routeEditUndoStack = [];
const routeEditRedoStack = [];
const mapHistory = {
    stack: [],
    histRedoStack: [],
    busy: false,
    maxSize: 30,
    push(cmd) {
        if (this.busy) return;
        this.stack.push(cmd);
        if (this.stack.length > this.maxSize) this.stack.shift();
        this.histRedoStack = [];
        updateMapHistoryUI();
    },
    async undo() {
        if (this.busy || !this.stack.length) return;
        const cmd = this.stack.pop();
        this.busy = true;
        try {
            await cmd.undo();
            this.histRedoStack.push(cmd);
            document.getElementById('cad-command').textContent = `Undo: ${cmd.label}`;
        } catch (e) {
            document.getElementById('cad-command').textContent = `Undo nije uspio: ${e.message}`;
        } finally {
            this.busy = false;
        }
        updateMapHistoryUI();
    },
    async redo() {
        if (this.busy || !this.histRedoStack.length) return;
        const cmd = this.histRedoStack.pop();
        this.busy = true;
        try {
            await cmd.redo();
            this.stack.push(cmd);
            document.getElementById('cad-command').textContent = `Redo: ${cmd.label}`;
        } catch (e) {
            document.getElementById('cad-command').textContent = `Redo nije uspio: ${e.message}`;
        } finally {
            this.busy = false;
        }
        updateMapHistoryUI();
    },
};
const odfMarkerById = {};
const cabinetMarkerById = {};
const routeLayerById = {};
const routeHitLayerById = {};
const routeLabelsById = {};
let activeTraceHouseId = null;
let colorByFibers = false;
let showCableSpecs = true;
let rulerStart = null;
let rulerLine = null;
let rulerStartMarker = null;
let rulerEndMarker = null;
let rulerLabelMarker = null;
const layerRegistry = {
    odf: [],
    odo: [],
    houses: [],
    trench: [],
    backbone: [],
    distribution: [],
    drop: [],
    dxf: [],
    preview: [],
    measure: [],
    trace: [],
};
const layerLocks = {};
const draftsByProject = {};
const deleteUrls = {
    odf: id => `${appConfig.odfsBase}/${id}`,
    cabinet: id => `${appConfig.cabinetsBase}/${id}`,
    house: id => `${appConfig.housesBase}/${id}`,
    route: id => `${appConfig.routesBase}/${id}`,
};
const positionUrls = {
    odf: id => `${appConfig.odfsBase}/${id}/pozicija`,
    cabinet: id => `${appConfig.cabinetsBase}/${id}/pozicija`,
    house: id => `${appConfig.housesBase}/${id}/pozicija`,
};
async function readJsonResponse(response, fallbackMessage) {
    const text = await response.text();
    let payload = null;
    try {
        payload = text ? JSON.parse(text) : {};
    } catch {
        throw new Error(response.ok ? fallbackMessage : `${fallbackMessage} Server je vratio neispravan odgovor.`);
    }
    if (!response.ok) {
        const validationMessage = payload.errors ? Object.values(payload.errors).flat()[0] : null;
        throw new Error(validationMessage || payload.message || fallbackMessage);
    }
    return payload;
}

const imagery = L.tileLayer('https://server.arcgisonline.com/ArcGIS/rest/services/World_Imagery/MapServer/tile/{z}/{y}/{x}', {
    maxNativeZoom: 18,
    maxZoom: 22,
    attribution: 'Tiles &copy; Esri'
}).addTo(map);

const osm = L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', { maxZoom: 22, maxNativeZoom: 19, attribution: '&copy; OpenStreetMap' });
const cartodbDark = L.tileLayer('https://{s}.basemaps.cartocdn.com/dark_all/{z}/{x}/{y}{r}.png', { maxNativeZoom: 19, maxZoom: 22, attribution: '&copy; CARTO' });
L.control.layers({ 'Satelit': imagery, 'OpenStreetMap': osm, 'CAD tamni': cartodbDark }, {}, { position: 'bottomleft' }).addTo(map);

L.control.scale({ imperial: false, position: 'bottomright' }).addTo(map);

(function addNorthArrow() {
    const NorthArrow = L.Control.extend({
        options: { position: 'bottomright' },
        onAdd() {
            const div = L.DomUtil.create('div', '');
            div.style.cssText = 'background:rgba(255,255,255,.82);border:1px solid rgba(15,23,42,.35);border-radius:2px;padding:3px 5px;font:800 9px/1 ui-monospace,SFMono-Regular,Menlo,Monaco,Consolas,monospace;color:#0f172a;text-align:center;cursor:default;user-select:none;';
            div.innerHTML = '<svg width="14" height="20" viewBox="0 0 14 20" style="display:block;margin:0 auto 1px"><polygon points="7,0 11,10 7,8 3,10" fill="#0f172a"/><polygon points="7,20 11,10 7,12 3,10" fill="#94a3b8"/></svg>N';
            L.DomEvent.disableClickPropagation(div);
            return div;
        },
    });
    new NorthArrow().addTo(map);
}());

const cabinetPalette = [
    '#2563eb', '#dc2626', '#16a34a', '#f59e0b', '#7c3aed', '#0891b2', '#db2777',
    '#65a30d', '#ea580c', '#0f766e', '#9333ea', '#be123c', '#4f46e5', '#ca8a04',
];
function cabinetColor(id) { return cabinetPalette[(Math.max(Number(id) || 1, 1) - 1) % cabinetPalette.length]; }
function cabinetOccupancyColor(c) {
    const pct = (Number(c.used_ports) || 0) / Math.max(Number(c.capacity) || 1, 1);
    if (pct >= 1.0) return '#dc2626';
    if (pct >= 0.8) return '#ea580c';
    if (pct >= 0.6) return '#f59e0b';
    return '#16a34a';
}
const mapLegend = L.control({ position: 'bottomright' });
mapLegend.onAdd = () => {
    const box = L.DomUtil.create('div', 'cad-map-legend');
    box.innerHTML = `
        <b>CAD LEGENDA</b>
        <div style="color:${routeColor('trench')}"><span class="cad-line-sample dashed"></span><span>Glavni rov</span></div>
        <div style="color:${routeColor('distribution')}"><span class="cad-line-sample"></span><span>Sekundarni krak</span></div>
        <div style="color:${routeColor('drop')}"><span class="cad-line-sample dashed"></span><span>Drop trasa</span></div>
        <div><span class="cad-point-sample" style="background:#0f5fa8"></span><span>ODF</span></div>
        <div><span class="cad-point-sample" style="background:#16a34a"></span><span>FTTH</span></div>
        <div><span class="cad-point-sample circle" style="background:#16a34a"></span><span>Kuca</span></div>
        <b style="margin-top:4px">VLAKNA</b>
        <div style="color:#f59e0b"><span class="cad-line-sample"></span><span>≤4F</span></div>
        <div style="color:#16a34a"><span class="cad-line-sample"></span><span>12F</span></div>
        <div style="color:#2563eb"><span class="cad-line-sample"></span><span>24F</span></div>
        <div style="color:#ea580c"><span class="cad-line-sample"></span><span>48F</span></div>
        <div style="color:#dc2626"><span class="cad-line-sample"></span><span>96F+</span></div>
    `;
    return box;
};
mapLegend.addTo(map);

function fiberCountColor(fibers) {
    const f = Number(fibers) || 0;
    if (f <= 4)  return '#f59e0b';
    if (f <= 12) return '#16a34a';
    if (f <= 24) return '#2563eb';
    if (f <= 48) return '#ea580c';
    return '#dc2626';
}
function routeLabelSpecs(route) {
    const parts = [];
    const f = route.fiber_count || route.fibers;
    if (f) parts.push(`${f}F`);
    const md = route.microduct_type || route.microduct;
    if (md) parts.push(md);
    return parts.length ? parts.join('·') : null;
}
function clearRuler() {
    [rulerStartMarker, rulerLine, rulerEndMarker, rulerLabelMarker].forEach(l => { if (l && map.hasLayer(l)) map.removeLayer(l); });
    rulerStart = null; rulerLine = null; rulerStartMarker = null; rulerEndMarker = null; rulerLabelMarker = null;
}
function rulerClick(latlng) {
    if (!rulerStart) {
        rulerStart = latlng;
        rulerStartMarker = L.circleMarker(latlng, { radius: 5, color: '#b91c1c', weight: 2, fillColor: '#fee2e2', fillOpacity: 1, interactive: false }).addTo(map);
        document.getElementById('cad-command').textContent = 'MJERAČ: klikni drugu tačku. ESC za odustajanje.';
        return;
    }
    const d = Math.round(map.distance(rulerStart, latlng));
    if (rulerLine) map.removeLayer(rulerLine);
    if (rulerEndMarker) map.removeLayer(rulerEndMarker);
    if (rulerLabelMarker) map.removeLayer(rulerLabelMarker);
    rulerLine = L.polyline([rulerStart, latlng], { color: '#b91c1c', weight: 2, dashArray: '6 5', opacity: .9, interactive: false }).addTo(map);
    rulerEndMarker = L.circleMarker(latlng, { radius: 5, color: '#b91c1c', weight: 2, fillColor: '#fee2e2', fillOpacity: 1, interactive: false }).addTo(map);
    rulerLabelMarker = L.marker(latlng, { interactive: false, keyboard: false, icon: L.divIcon({ className: 'ruler-label', html: `<span>${d} m</span>`, iconAnchor: [0, 0] }) }).addTo(map);
    document.getElementById('cad-command').textContent = `MJERAČ: ${d} m. Klikni za nastavak lanca ili ESC za završetak.`;
    rulerStart = latlng;
    if (rulerStartMarker) map.removeLayer(rulerStartMarker);
    rulerStartMarker = L.circleMarker(latlng, { radius: 5, color: '#b91c1c', weight: 2, fillColor: '#fee2e2', fillOpacity: 1, interactive: false }).addTo(map);
}
function icon(type, text = '', color = null) {
    const cls = type === 'odf' ? 'odf' : type === 'cabinet' ? 'cabinet' : type === 'suggest' ? 'suggest' : type === 'manhole' ? 'manhole' : type === 'boring' ? 'boring' : 'house';
    const style = color ? ` style="background:${color}"` : '';
    if (type === 'cabinet') {
        const match = String(text || '').trim().match(/^FTTH\s+(.+)$/i);
        const title = match ? 'FTTH' : String(text || '').trim();
        const code = match ? normalizeFtthDisplayCode(match[1]) : '';
        const html = code
            ? `<div class="ftth-tag ${cls} ftth-cabinet-tag"><span class="ftth-cabinet-symbol"${style}>ODO</span><span class="ftth-cabinet-text"><span class="ftth-cabinet-title">${title}</span><span class="ftth-cabinet-code">${code}</span></span></div>`
            : `<div class="ftth-tag ${cls} ftth-cabinet-tag"><span class="ftth-cabinet-symbol"${style}>ODO</span><span class="ftth-cabinet-text"><span class="ftth-cabinet-title">${title}</span></span></div>`;
        return L.divIcon({ className: 'ftth-label', html, iconSize: [2, 2], iconAnchor: [1, 1] });
    }
    return L.divIcon({ className: 'ftth-label', html: `<div class="ftth-tag ${cls}"${style}>${text}</div>`, iconSize: [2, 2], iconAnchor: [1, 1] });
}
function normalizeFtthDisplayCode(code) {
    const raw = String(code || '').trim();
    const chunks = raw.split('-').filter(Boolean);
    if (chunks.length < 3) return raw;

    let cabinetNo = chunks.pop();
    const branchChunks = [...chunks];

    // Legacy names like 1-6-3.1-1-2 include the parent cabinet number (3)
    // and sometimes an automatic duplicate suffix (-2). Display them as 1-6.1-1.
    if (branchChunks.length >= 3 && branchChunks[2].includes('.')) {
        if (chunks.length >= 4 && /^\d+$/.test(cabinetNo)) {
            cabinetNo = branchChunks.pop();
        }
        const [, childCode] = branchChunks[2].split('.', 2);
        branchChunks.splice(2, 1, childCode);
    }

    const branchCode = normalizeBranchCode(branchChunks.join('-'));
    const parts = branchCode.split('.').filter(Boolean);
    if (parts.length < 2) return `${branchCode}-${cabinetNo}`;

    const root = `${parts[0]}-${parts[1]}`;
    const children = parts.slice(2);

    return `${children.length ? `${root}.${children.join('.')}` : root}-${cabinetNo}`;
}
function boringGripIcon(text) {
    return L.divIcon({
        className: 'boring-grip',
        html: `<span>${text}</span>`,
        iconSize: [8, 8],
        iconAnchor: [4, 4],
    });
}

const bounds = [];
data.routes.forEach(route => {
    if (!route.path?.length) return;
    const points = route.path.map(point => L.latLng(point[0], point[1]));
    savedRoutePoints.push(points);
    const occupancy = route.occupancy || {};
    const line = L.polyline(points, { ...routeLineStyle(route.type, routeLineColor(route)), interactive: false })
        .bindPopup(`<b>${route.name}</b><br>${routeTypeLabel(route.type)}<br>${route.duct_length_m} m<br>Fiber: ${occupancy.fiber_capacity ?? route.fibers ?? 0}F<br>Zauzeto: ${occupancy.used_fibers ?? '-'}<br>Slobodno: ${occupancy.free_fibers ?? '-'}<br>Iskorištenost: ${occupancy.utilization_percent ?? '-'}%`)
        .addTo(map);
    const hitLine = L.polyline(points, { weight: 14, opacity: 0, interactive: true }).addTo(map);
    if (route.type === 'trench') { line.bringToBack(); hitLine.bringToBack(); }
    const labels = route.type === 'trench' ? [] : addRouteLabel(points, route.name, false, routeLabelSpecs(route));
    routeLayerById[route.id] = line;
    routeHitLayerById[route.id] = hitLine;
    routeLabelsById[route.id] = labels || [];
    trackLayer(line, routeLayerType(route.type));
    trackLayer(hitLine, routeLayerType(route.type));
    labels?.forEach(label => trackLayer(label, routeLayerType(route.type)));
    registerSavedContext([hitLine, line, ...labels], route.name, deleteUrls.route(route.id), null, event => {
        if (mode === 'join') selectJoinRoute(route, line);
        else if (routeEdit?.route.id === route.id) addRouteEditVertex(event.latlng);
        else {
            openRouteAttributePanel(route);
            startRouteEdit(route, line);
        }
    }, [], () => deleteRouteWithHistory(route, [hitLine, line, ...labels]));
    points.forEach(p => bounds.push([p.lat, p.lng]));
});
data.odfs.forEach(odf => {
    const p = L.latLng(odf.lat, odf.lng);
    const connectedCabinets = data.cabinets.filter(c => c.odf === odf.name).length;
    const marker = L.marker(p, { icon: icon('odf', 'ODF'), draggable: false })
        .bindTooltip(`${odf.name} · ${connectedCabinets} FTTH`, { direction: 'top', offset: [0, -10] })
        .bindPopup(`<b>ODF: ${odf.name}</b><br>${odf.address}<br>FTTH ormarića: ${connectedCabinets}`)
        .addTo(map);
    marker.on('click', event => {
        if (layerLocked('odf')) return document.getElementById('cad-command').textContent = 'Layer ODF je zaključan.';
        if (mode === 'connect') {
            L.DomEvent.stop(event);
            selectConnectOdf(odf);
            return;
        }
        if (mode === 'draw') {
            map.closePopup();
            addDrawPoint(event.latlng);
        }
    });
    registerSavedContext(marker, `ODF: ${odf.name}`, deleteUrls.odf(odf.id), positionUrls.odf(odf.id));
    odfMarkerById[odf.id] = marker;
    trackLayer(marker, 'odf');
    bounds.push([odf.lat, odf.lng]);
});
data.cabinets.forEach(c => {
    const p = L.latLng(c.lat, c.lng);
    const color = cabinetColor(c.id);
    const pct = Math.round((Number(c.used_ports) || 0) / Math.max(Number(c.capacity) || 1, 1) * 100);
    const marker = L.marker(p, { icon: icon('cabinet', c.name?.startsWith('FTTH') ? c.name : `FTTH ${c.id}`, color), draggable: false })
        .bindTooltip(`${c.used_ports}/${c.capacity} (${pct}%)`, { direction: 'top', offset: [0, -10] })
        .bindPopup(`<b>${c.name}</b><br>${c.used_ports}/${c.capacity} portova (${pct}%)<br>ODF: ${c.odf}`)
        .addTo(map);
    marker.on('click', event => {
        if (layerLocked('odo')) return document.getElementById('cad-command').textContent = 'Layer ODO je zaključan.';
        if (mode === 'connect-houses') {
            L.DomEvent.stop(event);
            selectHouseConnectCabinet(c);
            return;
        }
        if (mode === 'connect') {
            L.DomEvent.stop(event);
            connectSelectedOdfToCabinet(c);
            return;
        }
        if (mode === 'branch-source') {
            L.DomEvent.stop(event);
            startBranchFromCabinet(c);
            return;
        }
        if (mode === 'draw') {
            map.closePopup();
            addDrawPoint(event.latlng);
            const source = document.getElementById('route-start-source')?.value || '';
            if (source.startsWith('cabinet:') && Number(source.split(':')[1]) !== Number(c.id)) finishBranch();
        }
    });
    registerSavedContext(marker, c.name, deleteUrls.cabinet(c.id), positionUrls.cabinet(c.id), null, [
        { label: 'Novi krak odavde', run: () => startBranchFromCabinet(c) },
    ]);
    cabinetMarkerById[c.id] = marker;
    trackLayer(marker, 'odo');
    bounds.push([c.lat, c.lng]);
});
    const savedHouseKeys = new Set();
    const savedHouseColorByKey = {};
    const houseDataByKey = {};
function pointKey(lat, lng) { return `${Number(lat).toFixed(7)},${Number(lng).toFixed(7)}`; }
    data.houses.forEach(h => {
        const p = L.latLng(h.lat, h.lng);
        const key = pointKey(h.lat, h.lng);
        const color = h.cabinet_id ? cabinetColor(h.cabinet_id) : null;
        savedHouseKeys.add(key);
        savedHouseColorByKey[key] = color;
        houseDataByKey[key] = h;
        const marker = L.marker(p, { icon: icon('house', '', color), draggable: false }).bindPopup(`<b>${h.label}</b><br>ODO: ${h.cabinet}`).addTo(map);
        marker.on('click', event => {
            if (layerLocked('houses')) return document.getElementById('cad-command').textContent = 'Layer kuće je zaključan.';
            if (mode === 'connect-houses') {
                L.DomEvent.stop(event);
                toggleHouseConnect(h);
                return;
            }
            if (mode === 'draw') {
                map.closePopup();
                addDrawPoint(event.latlng);
                return;
            }
            if (mode === 'trace') showFiberTrace(h.id);
        });
        registerSavedContext(marker, h.label, deleteUrls.house(h.id), positionUrls.house(h.id));
        trackLayer(marker, 'houses');
        houseMarkerByKey[key] = marker;
        houseMarkerById[h.id] = marker;
    housePoints.push(p);
    bounds.push([h.lat, h.lng]);
});
data.appendix_items?.forEach(item => {
    const p = L.latLng(item.lat, item.lng);
    if (item.type === 'boring_fi_130') {
        drawSavedBoring(item);
    } else {
        const marker = L.marker(p, { icon: icon('manhole', 'S'), draggable: false })
            .bindTooltip(`Prolazni saht: ${item.quantity} ${item.unit}`, { direction: 'top', offset: [0, -10] })
            .bindPopup(`<b>Prolazni saht</b><br>${item.quantity} ${item.unit}${item.note ? `<br>${item.note}` : ''}`)
            .addTo(map);
        trackLayer(marker, 'preview');
    }
    bounds.push([item.lat, item.lng]);
});
let savedHouseCount = housePoints.length;
if (bounds.length) map.fitBounds(bounds, { padding: [40, 40], maxZoom: 19 }); else map.setView(defaultCenter, 17);

function setMode(next) {
    if (routeEdit && next !== 'pan') cancelRouteEdit();
    if (mode === 'ruler' && next !== 'ruler') clearRuler();
    mode = next;
    if (next !== 'connect') connectOdf = null;
    if (next !== 'connect-houses') resetHouseConnect();
    if (next !== 'join') resetJoinRoutes();
    if (next !== 'draw') hideSnapIndicator();
    if (next !== 'split') hideSplitPreview();
    document.querySelectorAll('.tool-btn').forEach(btn => btn.classList.remove('ring-2', 'ring-zinc-900'));
    const button = document.getElementById(`mode-${next}`);
    if (button) button.classList.add('ring-2', 'ring-zinc-900');
    const labels = {
        pan: 'PAN: pomjeraj mapu. Izaberi alat za crtanje.',
        select: 'SELEKT: drag da označiš više elemenata, zatim ih obriši.',
        odf: 'ODF: klikni lokaciju centrale/cvora. Novi ODF postaje aktivan.',
        cabinet: 'FTTH: klikni lokacije zelenih ormarića. Vezuju se na aktivni ODF.',
        house: 'KUCE: klikni svaku kucu/prikljucak. CTRL+Z vraca zadnju.',
        draw: 'TRASA: klik po klik crtaj trasu. Blizu postojece trase/tacke automatski se spoji. ENTER, dupla klik ili desni klik zavrsava krak. ESC prekida.',
        manhole: 'SAHT: klikni lokaciju prolaznog sahta.',
        'boring-fi-130': 'RAKETA FI130: klikni lokaciju podbusivanja ispod ceste.',
        ruler: 'MJERAČ: klikni prvu tačku. Svaki naredni klik mjeri od prethodne tačke. ESC završava.',
        'branch-source': 'NOVI KRAK IZ ODO: klikni ormarić iz kojeg novi krak polazi.',
        connect: 'CONNECT: odaberi ODF',
        'connect-houses': 'CONNECT HOUSES: odaberi ODO',
        trace: 'TRACE: klikni kuću za prikaz optičkog puta',
        join: 'JOIN: označi trase klikom, zatim pritisni ENTER',
        split: 'SPLIT: pomjeri miša na trasu i klikni gdje hoćeš da je podijeliš. ESC prekida.',
    };
    document.getElementById('cad-command').textContent = labels[next] ?? next.toUpperCase();
    updateCommandBar();
    // Select mode: zaustavi map drag da rubber-band može normalno raditi
    if (next === 'select') {
        map.dragging.disable();
        document.getElementById('network-map').style.cursor = 'crosshair';
    } else {
        map.dragging.enable();
        document.getElementById('network-map').style.cursor = '';
        // Ako izlazimo iz select moda, poništi selekciju
        if (currentSelection.length) {
            currentSelection.forEach(e => {
                if (e.isDxf) {
                    e._geomLayer?.setHighlight(false);
                } else {
                    e.allLayers.forEach((l, i) => {
                        if (!l || !map.hasLayer(l)) return;
                        if (typeof l.setStyle === 'function' && e._origStyles?.[i]) l.setStyle(e._origStyles[i]);
                        else if (l.getElement) { const el = l.getElement(); if (el) el.style.filter = ''; }
                    });
                    e._origStyles = null;
                }
            });
            currentSelection = [];
            document.getElementById('select-actions').style.display = 'none';
        }
    }
}
['pan','select','odf','cabinet','house','draw','manhole','boring-fi-130','ruler','branch-source','connect','connect-houses','trace','join','split'].forEach(m => document.getElementById(`mode-${m}`).addEventListener('click', () => {
    setMode(m);
    if (m === 'draw' && document.getElementById('route-draw-type').value === 'trench') {
        document.getElementById('cad-command').textContent = 'GLAVNI ROV: klikni tacke fizickog iskopa. ENTER/desni klik zavrsava rov.';
    }
}));
document.getElementById('osm-routing-toggle').addEventListener('change', function () {
    osmRoutingEnabled = this.checked;
    updateCommandBar();
});

document.getElementById('mode-trench-draw').addEventListener('click', () => {
    document.getElementById('route-draw-type').value = 'trench';
    document.getElementById('route-draw-name').placeholder = `npr. ${nextRouteName('trench')}`;
    refreshTrenchGroupStatus();
    setMode('draw');
    document.getElementById('cad-command').textContent = 'GLAVNI ROV: klikni tacke fizickog iskopa. ENTER/desni klik zavrsava rov.';
});

function distance(points) { return Math.round(points.slice(1).reduce((sum, p, i) => sum + map.distance(points[i], p), 0)); }
function normalizeAngle(deg) {
    return ((deg % 360) + 360) % 360;
}
function metersOffset(origin, eastMeters, northMeters) {
    const latRad = origin.lat * Math.PI / 180;
    const lat = origin.lat + (northMeters / 111320);
    const lng = origin.lng + (eastMeters / (111320 * Math.max(Math.cos(latRad), 0.00001)));
    return L.latLng(lat, lng);
}
function boringGeometry(center, lengthM = 12, angleDeg = 0, widthM = 1.8) {
    const angle = normalizeAngle(angleDeg) * Math.PI / 180;
    const dx = Math.cos(angle);
    const dy = Math.sin(angle);
    const nx = -dy;
    const ny = dx;
    const halfLength = Math.max(Number(lengthM) || 0, 1) / 2;
    const halfWidth = Math.max(Number(widthM) || 0, 1) / 2;
    const edge = (side, end) => metersOffset(center, dx * halfLength * end + nx * halfWidth * side, dy * halfLength * end + ny * halfWidth * side);
    return {
        top: [edge(1, -1), edge(1, 1)],
        bottom: [edge(-1, -1), edge(-1, 1)],
        labelPoint: metersOffset(center, nx * (halfWidth + 4.5), ny * (halfWidth + 4.5)),
        lengthHandle: metersOffset(center, dx * (halfLength + 2), dy * (halfLength + 2)),
        rotateHandle: metersOffset(center, -nx * (halfWidth + 5), -ny * (halfWidth + 5)),
    };
}
function angleFromCenter(center, point) {
    const latRad = center.lat * Math.PI / 180;
    const east = (point.lng - center.lng) * 111320 * Math.max(Math.cos(latRad), 0.00001);
    const north = (point.lat - center.lat) * 111320;
    return normalizeAngle(Math.atan2(north, east) * 180 / Math.PI);
}
function formatMeters(value) {
    return `${Number(value || 0).toLocaleString('bs-BA', { minimumFractionDigits: 2, maximumFractionDigits: 2 })} m`;
}
function boringTitle(item) {
    return `Podbusivanje FI130: ${formatMeters(item.length_m)} / ${Math.round(normalizeAngle(item.angle_deg || 0))}°`;
}
function boringLengthIcon(lengthM) {
    return L.divIcon({
        className: 'boring-length-label',
        html: `<span>${formatMeters(lengthM)}</span>`,
        iconSize: [1, 1],
        iconAnchor: [0, 0],
    });
}
function drawSavedBoring(item) {
    const center = L.latLng(item.lat, item.lng);
    const lengthM = item.length_m || item.quantity || 12;
    const geometry = boringGeometry(center, lengthM, item.angle_deg || 0, item.width_m || 1.8);
    const popup = `<b>Podbusivanje raketom FI 130</b><br>Duzina: ${formatMeters(item.length_m || item.quantity || 0)}<br>Ugao: ${Math.round(normalizeAngle(item.angle_deg || 0))}°${item.note ? `<br>${item.note}` : ''}`;
    const top = L.polyline(geometry.top, { color: '#dc2626', weight: 4, opacity: .95 }).bindPopup(popup).addTo(map);
    const bottom = L.polyline(geometry.bottom, { color: '#dc2626', weight: 4, opacity: .95 }).bindPopup(popup).addTo(map);
    const label = L.marker(center, { icon: icon('boring', 'FI130'), draggable: false })
        .bindTooltip(boringTitle({ length_m: item.length_m || item.quantity || 0, angle_deg: item.angle_deg || 0 }), { direction: 'top', offset: [0, -10] })
        .bindPopup(popup)
        .addTo(map);
    const lengthLabel = L.marker(geometry.labelPoint, { icon: boringLengthIcon(lengthM), interactive: false }).addTo(map);
    trackLayer(top, 'preview');
    trackLayer(bottom, 'preview');
    trackLayer(label, 'preview');
    trackLayer(lengthLabel, 'preview');
}
function updateBoringDraft(item) {
    const center = item.marker.getLatLng();
    const geometry = boringGeometry(center, item.length_m, item.angle_deg, item.width_m);
    item.lines[0].setLatLngs(geometry.top);
    item.lines[1].setLatLngs(geometry.bottom);
    item.lengthLabel.setLatLng(geometry.labelPoint);
    item.lengthLabel.setIcon(boringLengthIcon(item.length_m));
    item.lengthHandle.setLatLng(geometry.lengthHandle);
    item.rotateHandle.setLatLng(geometry.rotateHandle);
    item.marker.setTooltipContent(boringTitle(item));
    item.marker.setPopupContent(`<b>Podbusivanje raketom FI 130</b><br>Duzina: ${formatMeters(item.length_m)}<br>Ugao: ${Math.round(normalizeAngle(item.angle_deg))}°`);
}
function createBoringDraft(center, options = {}) {
    const item = {
        type: 'boring_fi_130',
        marker: null,
        lines: [],
        lengthLabel: null,
        lengthHandle: null,
        rotateHandle: null,
        quantity: Number(options.length_m ?? options.quantity ?? 12),
        length_m: Number(options.length_m ?? options.quantity ?? 12),
        angle_deg: normalizeAngle(Number(options.angle_deg ?? 0)),
        width_m: Number(options.width_m ?? 1.8),
        note: options.note || '',
    };
    const geometry = boringGeometry(center, item.length_m, item.angle_deg, item.width_m);
    item.lines = [
        L.polyline(geometry.top, { color: '#dc2626', weight: 4, opacity: .95 }).addTo(map),
        L.polyline(geometry.bottom, { color: '#dc2626', weight: 4, opacity: .95 }).addTo(map),
    ];
    item.marker = L.marker(center, { icon: icon('boring', 'FI130'), draggable: true })
        .bindTooltip(boringTitle(item), { direction: 'top', offset: [0, -10] })
        .bindPopup('')
        .addTo(map);
    item.lengthLabel = L.marker(geometry.labelPoint, { icon: boringLengthIcon(item.length_m), interactive: false }).addTo(map);
    item.lengthHandle = L.marker(geometry.lengthHandle, { icon: boringGripIcon('L'), draggable: true })
        .bindTooltip('Povuci za duzinu', { direction: 'top', offset: [0, -10] })
        .addTo(map);
    item.rotateHandle = L.marker(geometry.rotateHandle, { icon: boringGripIcon('R'), draggable: true })
        .bindTooltip('Povuci za rotaciju 360°', { direction: 'top', offset: [0, -10] })
        .addTo(map);
    item.marker.on('drag', () => {
        updateBoringDraft(item);
        refreshPlanSummary();
    });
    item.lengthHandle.on('drag', event => {
        const centerPoint = item.marker.getLatLng();
        const handlePoint = event.target.getLatLng();
        item.angle_deg = angleFromCenter(centerPoint, handlePoint);
        item.length_m = Math.max(1, Math.round(map.distance(centerPoint, handlePoint) * 2 - 4));
        item.quantity = item.length_m;
        updateBoringDraft(item);
        refreshPlanSummary();
    });
    item.rotateHandle.on('drag', event => {
        item.angle_deg = angleFromCenter(item.marker.getLatLng(), event.target.getLatLng()) + 90;
        updateBoringDraft(item);
        refreshPlanSummary();
    });
    updateBoringDraft(item);
    return item;
}
function removeAppendixDraftItem(item) {
    if (!item) return;
    [item.marker, item.lengthLabel, item.lengthHandle, item.rotateHandle, ...(item.lines || [])].forEach(layer => {
        if (layer && map.hasLayer(layer)) map.removeLayer(layer);
    });
}
function draftNetworkPoints() { return [...branches, activeBranch].filter(b => b.length > 1); }
function allNetworkPoints() { return [...savedRoutePoints, ...branches, activeBranch].filter(b => b.length > 1); }
function allDistance() { return draftNetworkPoints().reduce((sum, b) => sum + distance(b), 0); }
function routeTypeLabel(type) {
    return type === 'trench' ? 'Glavni rov' : type === 'backbone' ? 'Backbone' : type === 'feeder' ? 'Primarni' : type === 'drop' ? 'Drop' : 'Sekundarni';
}
function routeColor(type) {
    return type === 'trench' ? '#111827' : type === 'backbone' ? '#1d4ed8' : type === 'feeder' ? '#0e7490' : type === 'drop' ? '#f59e0b' : '#f97316';
}
function routeWeight(type) {
    return type === 'trench' ? 4 : type === 'drop' ? 2 : 4;
}
function routeDashArray(type) {
    return type === 'trench' ? '10 8' : type === 'drop' ? '4 6' : null;
}
function routeLineStyle(type, color = routeColor(type)) {
    if (mapViewMode === 'gis') {
        return {
            color,
            weight: type === 'trench' ? 6 : type === 'drop' ? 3 : 5,
            opacity: .9,
            dashArray: type === 'trench' ? '12 8' : (type === 'drop' ? '5 7' : null),
            lineCap: type === 'trench' ? 'butt' : 'round',
            lineJoin: 'round',
        };
    }

    return {
        color,
        weight: routeWeight(type),
        opacity: type === 'drop' ? .85 : .95,
        dashArray: routeDashArray(type),
        lineCap: type === 'trench' ? 'butt' : 'round',
        lineJoin: 'round',
    };
}
function routeLineColor(route) {
    if (route.type === 'trench') return routeColor('trench');
    const fibers = route.fiber_count || route.fibers;
    if (colorByFibers && fibers) return fiberCountColor(fibers);
    return route.cabinet_id ? cabinetColor(route.cabinet_id) : routeColor(route.type);
}
function refreshAllRouteStyles() {
    data.routes.forEach(route => {
        const line = routeLayerById[route.id];
        if (line?.setStyle) line.setStyle(routeLineStyle(route.type, routeLineColor(route)));
    });
    branchLines.forEach((line, idx) => {
        const meta = branchMeta[idx] || {};
        if (line?.setStyle) line.setStyle(routeLineStyle(meta.route_type || 'distribution', colorByFibers && meta.fiber_count ? fiberCountColor(meta.fiber_count) : undefined));
    });
}
function refreshAllRouteLabels() {
    data.routes.forEach(route => {
        const oldLayerType = routeLayerType(route.type);
        refreshRouteLabels(route, oldLayerType);
    });
}
function usedRouteNames(type = null) {
    return [...data.routes, ...branchMeta]
        .filter(route => !type || (route.type || route.route_type) === type)
        .map(route => String(route.name || '').trim())
        .filter(Boolean);
}
function nextNumberedName(prefix, usedNames) {
    const escaped = prefix.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
    const pattern = new RegExp(`^${escaped}\\s+(\\d+)$`, 'i');
    const used = usedNames
        .map(name => name.match(pattern))
        .filter(Boolean)
        .map(match => Number(match[1]))
        .filter(Number.isFinite);

    return `${prefix} ${Math.max(0, ...used) + 1}`;
}
function nextRouteName(type) {
    const labels = {
        trench: 'Glavni rov',
        feeder: 'Primarni krak',
        backbone: 'Backbone',
        drop: 'Drop trasa',
        distribution: 'Sekundarni krak',
    };

    return nextNumberedName(labels[type] || 'Trasa', usedRouteNames(type));
}
function selectConnectOdf(odf) {
    connectOdf = odf;
    document.getElementById('cad-command').textContent = `CONNECT: odaberi ODO za ${odf.name}`;
}
function selectHouseConnectCabinet(cabinet) {
    resetHouseConnect();
    connectCabinet = cabinet;
    document.getElementById('house-connect-actions').classList.remove('hidden');
    document.getElementById('house-connect-actions').classList.add('flex');
    document.getElementById('cad-command').textContent = `CONNECT HOUSES: ${cabinet.name}, odaberi kuće`;
}
function toggleHouseConnect(house) {
    if (!connectCabinet) return document.getElementById('cad-command').textContent = 'CONNECT HOUSES: prvo odaberi ODO';
    if (Number(house.project_id) !== Number(connectCabinet.project_id)) return document.getElementById('cad-command').textContent = 'CONNECT HOUSES: kuća i ODO moraju biti u istom projektu.';
    if (house.cabinet_id && Number(house.cabinet_id) !== Number(connectCabinet.id)) return document.getElementById('cad-command').textContent = `${house.label} je već povezana na drugi ODO.`;
    const available = Math.max(12 - Number(connectCabinet.used_ports || 0), 0);
    if (!connectHouseIds.has(house.id) && connectHouseIds.size >= available) return document.getElementById('cad-command').textContent = `${connectCabinet.name} nema više slobodnih portova.`;
    const marker = houseMarkerByKey[pointKey(house.lat, house.lng)];
    if (connectHouseIds.has(house.id)) {
        connectHouseIds.delete(house.id);
        marker?.setIcon(icon('house', '', savedHouseColorByKey[pointKey(house.lat, house.lng)] || null));
    } else {
        connectHouseIds.add(house.id);
        marker?.setIcon(icon('house', '', '#a855f7'));
    }
    document.getElementById('cad-command').textContent = `CONNECT HOUSES: ${connectCabinet.name} | odabrano ${connectHouseIds.size}/${available}`;
}
function resetHouseConnect() {
    connectHouseIds.forEach(id => {
        const house = data.houses.find(item => Number(item.id) === Number(id));
        if (house) houseMarkerByKey[pointKey(house.lat, house.lng)]?.setIcon(icon('house', '', savedHouseColorByKey[pointKey(house.lat, house.lng)] || null));
    });
    connectCabinet = null;
    connectHouseIds = new Set();
    document.getElementById('house-connect-actions')?.classList.add('hidden');
    document.getElementById('house-connect-actions')?.classList.remove('flex');
}
async function finishHouseConnect() {
    if (!connectCabinet || !connectHouseIds.size) return document.getElementById('cad-command').textContent = 'CONNECT HOUSES: odaberi ODO i najmanje jednu kuću.';
    const cabinet = connectCabinet, houseIds = [...connectHouseIds];
    const response = await fetch(`${appConfig.cabinetsBase}/${cabinet.id}/povezi-kuce`, {
        method: 'POST',
        headers: { 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '', 'Accept': 'application/json', 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
        body: JSON.stringify({ house_ids: houseIds }),
    });
    const result = await readJsonResponse(response, 'ODO-kuće povezivanje nije uspjelo.');
    result.routes.forEach(route => addSavedRouteToMap({ ...route, type: 'drop', length: route.duct_length_m }));
    data.houses.filter(house => houseIds.includes(house.id)).forEach(house => { house.cabinet_id = cabinet.id; house.cabinet = cabinet.name; });
    cabinet.used_ports = Number(cabinet.used_ports || 0) + result.routes.length;
    resetHouseConnect();
    document.getElementById('cad-command').textContent = `CONNECT HOUSES: kreirano ${result.routes.length} drop veza za ${cabinet.name}`;
}
async function connectSelectedOdfToCabinet(cabinet) {
    if (!connectOdf) {
        document.getElementById('cad-command').textContent = 'CONNECT: prvo odaberi ODF';
        return;
    }
    if (Number(connectOdf.project_id) !== Number(cabinet.project_id)) {
        document.getElementById('cad-command').textContent = 'CONNECT: ODF i ODO moraju pripadati istom projektu.';
        return;
    }
    const points = [L.latLng(connectOdf.lat, connectOdf.lng), L.latLng(cabinet.lat, cabinet.lng)];
    const length = distance(points);
    const payload = {
        project_id: connectOdf.project_id,
        odf_id: connectOdf.id,
        cabinet_id: cabinet.id,
        from_type: 'odf',
        from_id: connectOdf.id,
        to_type: 'cabinet',
        to_id: cabinet.id,
        name: `${connectOdf.name} - ${cabinet.name}`,
        route_type: 'backbone',
        installation_type: 'underground',
        duct_length_m: length,
        fiber_length_m: length,
        fiber_count: 24,
        microduct_type: '14/10',
        microduct_count: 1,
        status: 'planned',
        path: JSON.stringify(points.map(point => [Number(point.lat.toFixed(7)), Number(point.lng.toFixed(7))])),
    };
    document.getElementById('cad-command').textContent = `CONNECT: kreiram vezu ${connectOdf.name} → ${cabinet.name}`;
    try {
        const response = await fetch(appConfig.routesStore, {
            method: 'POST',
            headers: {
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '',
                'Accept': 'application/json',
                'Content-Type': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
            },
            body: JSON.stringify(payload),
        });
        const result = await readJsonResponse(response, 'ODF-ODO veza nije kreirana.');
        addSavedRouteToMap({
            ...result.route,
            project_id: connectOdf.project_id,
            project: connectOdf.project,
            cabinet_id: cabinet.id,
            installation_type: 'underground',
            fiber_count: 24,
            duct_length_m: result.route.length,
            fiber_length_m: result.route.length,
        });
        document.getElementById('cad-command').textContent = `CONNECT: kreirana veza ${connectOdf.name} → ${cabinet.name}`;
        connectOdf = null;
    } catch (error) {
        document.getElementById('cad-command').textContent = `CONNECT: ${error.message}`;
    }
}
function addSavedRouteToMap(route) {
    const points = route.path.map(point => L.latLng(point[0], point[1]));
    const line = L.polyline(points, { ...routeLineStyle(route.type, routeLineColor(route)), interactive: false })
        .bindPopup(`<b>${route.name}</b><br>${routeTypeLabel(route.type)}<br>${route.length ?? route.duct_length_m ?? 0} m`)
        .addTo(map);
    const hitLine = L.polyline(points, { weight: 14, opacity: 0, interactive: true }).addTo(map);
    const labels = route.type === 'trench' ? [] : addRouteLabel(points, route.name, false, routeLabelSpecs(route));
    data.routes.push(route);
    savedRoutePoints.push(points);
    routeLayerById[route.id] = line;
    routeHitLayerById[route.id] = hitLine;
    routeLabelsById[route.id] = labels || [];
    trackLayer(line, routeLayerType(route.type));
    trackLayer(hitLine, routeLayerType(route.type));
    labels?.forEach(label => trackLayer(label, routeLayerType(route.type)));
    registerSavedContext([hitLine, line, ...labels], route.name, deleteUrls.route(route.id), null, event => {
        if (mode === 'join') selectJoinRoute(route, line);
        else if (routeEdit?.route.id === route.id) addRouteEditVertex(event.latlng);
        else startRouteEdit(route, line);
    }, [], () => deleteRouteWithHistory(route, [hitLine, line, ...labels]));
}
function resetJoinRoutes() {
    joinRoutes.forEach(item => item.line.setStyle(routeLineStyle(item.route.type, routeLineColor(item.route))));
    joinRoutes = [];
}
function selectJoinRoute(route, line) {
    const selectedIndex = joinRoutes.findIndex(item => Number(item.route.id) === Number(route.id));
    if (selectedIndex >= 0) {
        joinRoutes.splice(selectedIndex, 1);
        line.setStyle(routeLineStyle(route.type, routeLineColor(route)));
        document.getElementById('cad-command').textContent = `JOIN: označeno ${joinRoutes.length} trasa. ENTER spaja.`;
        return;
    }
    joinRoutes.push({ route, line });
    line.setStyle({ color: joinRoutes.length === 1 ? '#e11d48' : '#fb7185', weight: 7, opacity: 1 });
    document.getElementById('cad-command').textContent = `JOIN: označeno ${joinRoutes.length} trasa. Glavna: ${joinRoutes[0].route.name}. ENTER spaja.`;
}
let splitPreview = null;

function nearestRouteProjection(latlng) {
    let best = null;
    data.routes.forEach(route => {
        if (!route.path || route.path.length < 2) return;
        const line = routeLayerById[route.id];
        if (!line) return;
        for (let i = 0; i < route.path.length - 1; i++) {
            const a = L.latLng(route.path[i][0], route.path[i][1]);
            const b = L.latLng(route.path[i + 1][0], route.path[i + 1][1]);
            const proj = projectOnSegment(latlng, a, b);
            const dist = layerPixelDistance(latlng, proj);
            if (!best || dist < best.dist) {
                best = { route, latlng: proj, dist, line, labels: routeLabelsById[route.id] || [] };
            }
        }
    });
    return best && best.dist <= 20 ? best : null;
}

function showSplitPreview(proj) {
    const html = `<div style="position:relative;width:0;height:0">` +
        `<div style="position:absolute;transform:translate(-50%,-50%);font-size:18px;line-height:1;filter:drop-shadow(0 1px 3px rgba(0,0,0,.6));cursor:crosshair">✂</div>` +
        `<div style="position:absolute;left:14px;top:-16px;background:#ea580c;color:#fff;font:700 10px/1.4 system-ui,sans-serif;padding:2px 7px;border-radius:5px;white-space:nowrap;box-shadow:0 2px 6px rgba(0,0,0,.3)">${proj.route.name}</div>` +
        `</div>`;
    const icon = L.divIcon({ className: '', html, iconSize: [0, 0], iconAnchor: [0, 0] });
    if (!splitPreview) {
        splitPreview = { marker: L.marker(proj.latlng, { icon, interactive: false, zIndexOffset: 3000 }).addTo(map), ...proj };
    } else {
        splitPreview.marker.setLatLng(proj.latlng);
        splitPreview.marker.setIcon(icon);
        if (!map.hasLayer(splitPreview.marker)) splitPreview.marker.addTo(map);
        Object.assign(splitPreview, proj);
    }
    map.getContainer().style.cursor = 'crosshair';
}

function hideSplitPreview() {
    if (splitPreview?.marker && map.hasLayer(splitPreview.marker)) map.removeLayer(splitPreview.marker);
    splitPreview = null;
    if (mode !== 'split') map.getContainer().style.cursor = '';
}

async function splitSavedRoute(route, latlng, line, labels) {
    try {
        document.getElementById('cad-command').textContent = 'Dijeli trasu...';
        const routeSnapshot = { ...route };
        const latlngSnapshot = { lat: latlng.lat, lng: latlng.lng };
        const response = await fetch(`${appConfig.routesBase}/${route.id}/split`, {
            method: 'POST',
            headers: {
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '',
                'Accept': 'application/json',
                'Content-Type': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
            },
            body: JSON.stringify({ lat: latlng.lat, lng: latlng.lng }),
        });
        const result = await readJsonResponse(response, 'Podjela nije uspjela.');

        // Ukloni staru trasu sa mape
        removeRouteFromMap(route.id);

        // Dodaj dvije nove trase
        addSavedRouteToMap(result.first);
        addSavedRouteToMap(result.second);

        document.getElementById('cad-command').textContent = `Trasa podijeljena: ${result.first.name} i ${result.second.name}. CTRL+Z za poništavanje.`;

        const idRefs = { firstId: result.first.id, secondId: result.second.id };
        const splitRef = { restoredId: null };
        mapHistory.push({
            label: `Split ${routeSnapshot.name}`,
            undo: async () => {
                await deleteRouteOnServer(idRefs.firstId);
                removeRouteFromMap(idRefs.firstId);
                await deleteRouteOnServer(idRefs.secondId);
                removeRouteFromMap(idRefs.secondId);
                const restored = await recreateRoute(routeSnapshot);
                addSavedRouteToMap(restored);
                splitRef.restoredId = restored.id;
            },
            redo: async () => {
                if (!splitRef.restoredId) return;
                const csrf = document.querySelector('meta[name="csrf-token"]')?.content || '';
                const reRes = await fetch(`${appConfig.routesBase}/${splitRef.restoredId}/split`, {
                    method: 'POST',
                    headers: { 'X-CSRF-TOKEN': csrf, 'Accept': 'application/json', 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                    body: JSON.stringify(latlngSnapshot),
                });
                const reResult = await readJsonResponse(reRes, 'Podjela nije uspjela.');
                removeRouteFromMap(splitRef.restoredId);
                addSavedRouteToMap(reResult.first);
                addSavedRouteToMap(reResult.second);
                idRefs.firstId = reResult.first.id;
                idRefs.secondId = reResult.second.id;
                splitRef.restoredId = null;
            },
        });
    } catch (error) {
        document.getElementById('cad-command').textContent = `SPLIT: ${error.message}`;
    }
}

async function joinSelectedRoutes() {
    if (joinRoutes.length < 2) {
        document.getElementById('cad-command').textContent = 'JOIN: označi najmanje dvije trase, zatim pritisni ENTER.';
        return;
    }
    const first = joinRoutes[0];
    const others = joinRoutes.slice(1);
    for (const item of others) {
      try {
        const response = await fetch(`${appConfig.routesBase}/${first.route.id}/join/${item.route.id}`, {
            method: 'POST',
            headers: { 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '', 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
        });
        const result = await readJsonResponse(response, 'Trase nisu spojene.');
        const points = result.route.path.map(point => L.latLng(point[0], point[1]));
        first.line.setLatLngs(points);
        first.route.path = result.route.path;
        first.route.length = result.route.length;
        first.route.duct_length_m = result.route.length;
        first.line.setPopupContent(`<b>${first.route.name}</b><br>${routeTypeLabel(first.route.type)}<br>${result.route.length} m`);
        (routeLabelsById[first.route.id] || []).forEach(label => {
            map.removeLayer(label);
            untrackLayer(label);
        });
        routeLabelsById[first.route.id] = first.route.type === 'trench' ? [] : addRouteLabel(points, first.route.name, false, routeLabelSpecs(first.route));
        routeLabelsById[first.route.id].forEach(label => trackLayer(label, routeLayerType(first.route.type)));
        routeHitLayerById[first.route.id]?.setLatLngs(points);
        const removedLine = routeLayerById[result.deleted_route_id];
        if (removedLine) map.removeLayer(removedLine);
        (routeLabelsById[result.deleted_route_id] || []).forEach(label => {
            map.removeLayer(label);
            untrackLayer(label);
        });
        const removedHitLine = routeHitLayerById[result.deleted_route_id];
        if (removedHitLine) { map.removeLayer(removedHitLine); untrackLayer(removedHitLine); }
        delete routeHitLayerById[result.deleted_route_id];
        delete routeLayerById[result.deleted_route_id];
        delete routeLabelsById[result.deleted_route_id];
        data.routes = data.routes.filter(item => Number(item.id) !== Number(result.deleted_route_id));
        const firstIndex = data.routes.findIndex(item => Number(item.id) === Number(first.route.id));
        if (firstIndex >= 0) data.routes[firstIndex] = first.route;
      } catch (error) {
        document.getElementById('cad-command').textContent = `JOIN: ${error.message}`;
        resetJoinRoutes();
        return;
      }
    }
    removeDraftBranchesOnPath(first.line.getLatLngs());
    document.getElementById('cad-command').textContent = `JOIN: spojeno ${others.length + 1} trasa u ${first.route.name} (${first.route.length} m)`;
    resetJoinRoutes();
}
function removeDraftBranchesOnPath(joinedPath) {
    const matchesJoinedPath = branch => branch.length > 1 && branch.every(point => {
        let nearest = Infinity;
        for (let index = 1; index < joinedPath.length; index++) {
            nearest = Math.min(nearest, map.distance(point, projectOnSegment(point, joinedPath[index - 1], joinedPath[index])));
        }
        return nearest <= 3;
    });
    branches.map((branch, index) => matchesJoinedPath(branch) ? index : -1)
        .filter(index => index >= 0)
        .reverse()
        .forEach(removeBranchAt);
}
function currentRouteDraftMeta() {
    const type = document.getElementById('route-draw-type').value;
    const manualName = document.getElementById('route-draw-name').value.trim();
    const odf = activeOdfPayload();
    const startSource = document.getElementById('route-start-source')?.value || '';
    const [fromType, fromId] = startSource ? startSource.split(':') : [null, null];
    const sourceCabinet = fromType === 'cabinet' ? data.cabinets.find(cabinet => Number(cabinet.id) === Number(fromId)) : null;
    const isTrench = type === 'trench';
    return {
        name: manualName || nextRouteName(type),
        route_type: type,
        installation_type: document.getElementById('route-draw-installation').value,
        counts_as_trench: isTrench,
        trench_length_m: null,
        microduct_type: isTrench ? null : document.getElementById('route-draw-microduct-type').value,
        fiber_count: isTrench ? 4 : Number(document.getElementById('route-draw-fiber-count').value || 12),
        microduct_count: isTrench ? 0 : Math.max(1, Number(document.getElementById('route-draw-microducts').value || 1)),
        odf_index: fromType === 'odf' ? null : odf.odf_index,
        odf_id: fromType === 'odf' ? Number(fromId) : (sourceCabinet?.odf_id || odf.odf_id),
        from_type: fromType || null,
        from_id: fromId ? Number(fromId) : null,
    };
}
function nextCabinetBranchName(cabinet) {
    const base = cabinetBranchCode(cabinet);
    const prefix = `Sekundarni krak ${base}.`;
    const used = [...data.routes, ...branchMeta]
        .map(route => String(route.name || ''))
        .map(name => branchCodeFromLabel(name))
        .filter(code => code && code.startsWith(`${base}.`))
        .map(code => code.slice(base.length + 1))
        .filter(rest => /^\d+$/.test(rest))
        .map(Number)
        .filter(Number.isFinite);
    return `${prefix}${Math.max(0, ...used) + 1}`;
}
function cabinetBranchCode(cabinet) {
    const ftthCode = branchCodeFromFtthName(cabinet.name);
    if (ftthCode) return ftthCode;

    const label = [cabinet.branch_code, cabinet.branch_name].filter(Boolean).join(' ');
    const code = branchCodeFromLabel(label);
    if (code) return code;

    return '1.1';
}
function branchCodeFromLabel(label) {
    const match = String(label || '').match(/(\d+(?:[.-]\d+)*)/);
    return match ? normalizeBranchCode(match[1]) : null;
}
function branchCodeFromFtthName(name) {
    const match = String(name || '').trim().match(/^FTTH\s+(.+)$/i);
    if (!match) return null;
    const chunks = match[1].split('-').filter(Boolean);
    if (chunks.length < 3) return null;
    chunks.pop();

    return normalizeBranchCode(chunks.join('-'));
}
function normalizeBranchCode(code) {
    return String(code || '')
        .trim()
        .replace(/-/g, '.')
        .replace(/\.+/g, '.')
        .replace(/^\.|\.$/g, '');
}
function nextCabinetName() {
    const usedNames = [
        ...data.cabinets.map(cabinet => cabinet.name),
        ...draftCabinets.map(item => item.name),
        ...suggestedCabinets.map(cabinet => cabinet.name),
    ].map(name => String(name || '').trim()).filter(Boolean);
    const used = usedNames
        .map(name => name.match(/^FTTH\s+1-1-(\d+)$/i))
        .filter(Boolean)
        .map(match => Number(match[1]))
        .filter(Number.isFinite);

    return `FTTH 1-1-${Math.max(0, ...used) + 1}`;
}
function draftCabinetName(index) {
    const savedNumbers = data.cabinets
        .map(cabinet => String(cabinet.name || '').match(/^FTTH\s+1-1-(\d+)$/i))
        .filter(Boolean)
        .map(match => Number(match[1]))
        .filter(Number.isFinite);
    const base = Math.max(0, ...savedNumbers);

    return `FTTH 1-1-${base + index + 1}`;
}
function startBranchFromCabinet(cabinet) {
    cancelActiveDrawing();
    quickBranchWorkflow = true;
    document.getElementById('route-start-source').value = `cabinet:${cabinet.id}`;
    document.getElementById('route-draw-type').value = 'distribution';
    document.getElementById('route-draw-name').value = nextCabinetBranchName(cabinet);
    setMode('draw');
    addDrawPoint(cabinetMarkerById[cabinet.id]?.getLatLng() || L.latLng(cabinet.lat, cabinet.lng));
    document.getElementById('cad-command').textContent = `NOVI KRAK: polazi iz ${cabinet.name}. Klikni dalje po trasi, ENTER završava krak.`;
}
function trackLayer(layer, type) {
    if (layerRegistry[type]) layerRegistry[type].push(layer);
    layer._ftthLayerType = type;
    applyLayerVisibility(type);
    updateLayerCount(type);
    return layer;
}
function untrackLayer(layer, type = null) {
    Object.entries(layerRegistry).forEach(([key, layers]) => {
        if (type && key !== type) return;
        const index = layers.indexOf(layer);
        if (index >= 0) {
            layers.splice(index, 1);
            updateLayerCount(key);
        }
    });
}
function layerVisible(type) {
    return document.querySelector(`[data-layer-toggle="${type}"]`)?.checked !== false;
}
function applyLayerVisibility(type) {
    (layerRegistry[type] || []).forEach(layer => {
        if (layerVisible(type)) {
            if (!map.hasLayer(layer)) layer.addTo(map);
        } else if (map.hasLayer(layer)) {
            map.removeLayer(layer);
        }
    });
}
function updateCommandBar(snap = '-', previewPoint = null) {
    if (routeEdit) {
        updateRouteEditStatus();
        return;
    }
    const metrics = document.getElementById('cad-metrics');
    if (!metrics) return;
    let total = distance(activeBranch);
    let segment = 0;
    if (previewPoint && activeBranch.length) {
        segment = Math.round(map.distance(activeBranch[activeBranch.length - 1], previewPoint));
        total += segment;
    }
    metrics.textContent = `Points: ${activeBranch.length} | Total: ${total}m | Segment: ${segment}m | Snap: ${snap || '-'} | ORTHO: ${orthoEnabled ? 'ON' : 'OFF'} | OSM: ${osmRoutingEnabled ? (osmRoutingLoading ? '...' : 'ON') : 'OFF'}`;
}
function pushUndo(action) {
    undoStack.push(action);
    redoStack.length = 0;
}
function undoLast() {
    const action = undoStack.pop();
    if (!action) return;
    action.undo?.();
    redoStack.push(action);
    updateCommandBar();
}
function redoLast() {
    const action = redoStack.pop();
    if (!action) return;
    action.redo?.();
    undoStack.push(action);
    updateCommandBar();
}
function pushRouteEditUndo(action) {
    routeEditUndoStack.push(action);
    routeEditRedoStack.length = 0;
    syncRouteEditUndoButtons();
}
function undoRouteEdit() {
    const action = routeEditUndoStack.pop();
    if (!action) return;
    action.undo();
    routeEditRedoStack.push(action);
    syncRouteEditUndoButtons();
}
function redoRouteEdit() {
    const action = routeEditRedoStack.pop();
    if (!action) return;
    action.redo();
    routeEditUndoStack.push(action);
    syncRouteEditUndoButtons();
}
function syncRouteEditUndoButtons() {
    const u = document.getElementById('undo-route-edit');
    const r = document.getElementById('redo-route-edit');
    if (u) u.disabled = routeEditUndoStack.length === 0;
    if (r) r.disabled = routeEditRedoStack.length === 0;
}
function updateMapHistoryUI() {
    const u = document.getElementById('btn-map-undo');
    const r = document.getElementById('btn-map-redo');
    if (u) u.disabled = mapHistory.stack.length === 0;
    if (r) r.disabled = mapHistory.histRedoStack.length === 0;
}
function removeRouteFromMap(routeId) {
    const line = routeLayerById[routeId];
    const hitLine = routeHitLayerById[routeId];
    if (line) { map.removeLayer(line); untrackLayer(line); }
    if (hitLine) { map.removeLayer(hitLine); untrackLayer(hitLine); }
    (routeLabelsById[routeId] || []).forEach(l => { map.removeLayer(l); untrackLayer(l); });
    delete routeLayerById[routeId];
    delete routeHitLayerById[routeId];
    delete routeLabelsById[routeId];
    const spIdx = data.routes.filter(r => r.path?.length).findIndex(r => Number(r.id) === Number(routeId));
    if (spIdx >= 0) savedRoutePoints.splice(spIdx, 1);
    data.routes = data.routes.filter(r => Number(r.id) !== Number(routeId));
}
async function recreateRoute(snapshot) {
    const csrf = document.querySelector('meta[name="csrf-token"]')?.content || '';
    const response = await fetch(appConfig.routesBase, {
        method: 'POST',
        headers: { 'X-CSRF-TOKEN': csrf, 'Accept': 'application/json', 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
        body: JSON.stringify({
            project_id: snapshot.project_id,
            odf_id: snapshot.odf_id ?? null,
            cabinet_id: snapshot.cabinet_id ?? null,
            from_type: snapshot.from_type ?? null,
            from_id: snapshot.from_id ?? null,
            to_type: snapshot.to_type ?? null,
            to_id: snapshot.to_id ?? null,
            name: snapshot.name,
            route_type: snapshot.type,
            installation_type: snapshot.installation_type ?? 'underground',
            duct_length_m: snapshot.duct_length_m,
            fiber_length_m: snapshot.fiber_length_m ?? snapshot.duct_length_m,
            fiber_count: snapshot.fiber_count ?? 4,
            microduct_count: snapshot.microduct_count ?? 0,
            microduct_type: snapshot.microduct_type ?? null,
            status: snapshot.status ?? 'planned',
            path: JSON.stringify(snapshot.path),
            note: snapshot.note ?? null,
        }),
    });
    const result = await readJsonResponse(response, 'Obnova trase nije uspjela.');
    return { ...snapshot, id: result.route.id };
}
async function deleteRouteOnServer(routeId) {
    const csrf = document.querySelector('meta[name="csrf-token"]')?.content || '';
    const response = await fetch(deleteUrls.route(routeId), {
        method: 'DELETE',
        headers: { 'X-CSRF-TOKEN': csrf, 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
    });
    if (!response.ok) throw new Error('Brisanje trase nije uspjelo.');
}
async function deleteRouteWithHistory(route, layers) {
    if (!confirm('Sigurno obrisati?')) return;
    const routeSnapshot = { ...route };
    const csrf = document.querySelector('meta[name="csrf-token"]')?.content || '';
    const response = await fetch(deleteUrls.route(route.id), {
        method: 'DELETE',
        headers: { 'X-CSRF-TOKEN': csrf, 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
    });
    if (!response.ok) {
        alert(await response.text() || 'Brisanje nije uspjelo.');
        return;
    }
    removeRouteFromMap(route.id);
    document.getElementById('cad-command').textContent = `Trasa ${routeSnapshot.name} obrisana. CTRL+Z za poništavanje.`;
    const deleteRef = { recreatedId: null };
    mapHistory.push({
        label: `Obriši trasu ${routeSnapshot.name}`,
        undo: async () => {
            const restored = await recreateRoute(routeSnapshot);
            addSavedRouteToMap(restored);
            deleteRef.recreatedId = restored.id;
        },
        redo: async () => {
            if (!deleteRef.recreatedId) return;
            await deleteRouteOnServer(deleteRef.recreatedId);
            removeRouteFromMap(deleteRef.recreatedId);
            deleteRef.recreatedId = null;
        },
    });
}
async function patchRouteGeometryOnMap(routeId, points) {
    const path = points.map(p => [Number(p.lat.toFixed(7)), Number(p.lng.toFixed(7))]);
    const len = distance(points);
    const csrf = document.querySelector('meta[name="csrf-token"]')?.content || '';
    const response = await fetch(`${appConfig.routesBase}/${routeId}/geometrija`, {
        method: 'PATCH',
        headers: { 'X-CSRF-TOKEN': csrf, 'Accept': 'application/json', 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
        body: JSON.stringify({ path, duct_length_m: len, fiber_length_m: len }),
    });
    const result = await readJsonResponse(response, 'Izmjena geometrije nije uspjela.');
    const line = routeLayerById[routeId];
    if (line) {
        line.setLatLngs(points);
        routeHitLayerById[routeId]?.setLatLngs(points);
    }
    const route = data.routes.find(r => Number(r.id) === Number(routeId));
    if (route && line) {
        route.path = result.route.path;
        route.duct_length_m = result.route.length;
        line.setPopupContent(`<b>${route.name}</b><br>${routeTypeLabel(route.type)}<br>${result.route.length} m`);
        (routeLabelsById[routeId] || []).forEach(l => { map.removeLayer(l); untrackLayer(l); });
        const newLabels = route.type === 'trench' ? [] : addRouteLabel(points, route.name, false, routeLabelSpecs(route));
        routeLabelsById[routeId] = newLabels || [];
        newLabels?.forEach(l => trackLayer(l, routeLayerType(route.type)));
    }
    return result;
}
function cancelActiveDrawing() {
    quickBranchWorkflow = false;
    activeBranchMarkers.forEach(marker => map.removeLayer(marker));
    if (activeBranchLine) map.removeLayer(activeBranchLine);
    if (previewBranchLine) map.removeLayer(previewBranchLine);
    hideSnapIndicator();
    activeBranch = [];
    activeBranchSnapTargets = [];
    activeBranchMarkers = [];
    activeBranchLine = null;
    previewBranchLine = null;
    refreshStats();
    updateCommandBar();
}
function applyOrthoPoint(latlng) {
    if (!orthoEnabled || !activeBranch.length) return latlng;
    const last = activeBranch[activeBranch.length - 1];
    const latDiff = Math.abs(latlng.lat - last.lat);
    const lngDiff = Math.abs(latlng.lng - last.lng);
    return latDiff >= lngDiff ? L.latLng(latlng.lat, last.lng) : L.latLng(last.lat, latlng.lng);
}
function clearFiberTrace() {
    layerRegistry.trace.forEach(layer => map.removeLayer(layer));
    layerRegistry.trace = [];
    activeTraceHouseId = null;
    document.getElementById('map-trace-panel')?.classList.add('hidden');
    Object.entries(houseMarkerByKey).forEach(([key, marker]) => marker.setIcon(icon('house', '', savedHouseColorByKey[key] || null)));
}
function traceRouteFor(type, house, cabinet, odf) {
    if (type === 'drop') {
        return data.routes.find(route => route.type === 'drop' && route.cabinet_id === cabinet.id && Number(route.to_id) === Number(house.id) && route.path?.length)
            || data.routes.find(route => route.type === 'drop' && route.cabinet_id === cabinet.id && route.path?.length && route.path.some(point => map.distance(L.latLng(point[0], point[1]), L.latLng(house.lat, house.lng)) < 8));
    }
    return null;
}
function cabinetSupplyChain(cabinet) {
    const chain = [], visited = new Set();
    let current = cabinet;
    while (current && !visited.has(Number(current.id))) {
        chain.push(current);
        visited.add(Number(current.id));
        current = current.parent_cabinet_id
            ? data.cabinets.find(item => Number(item.id) === Number(current.parent_cabinet_id))
            : null;
    }
    return chain;
}
function supplyRouteToCabinet(cabinet, parentCabinet, odf) {
    const exact = data.routes.find(route =>
        route.type !== 'drop'
        && Number(route.cabinet_id) === Number(cabinet.id)
        && (parentCabinet
            ? route.from_type === 'cabinet' && Number(route.from_id) === Number(parentCabinet.id)
            : route.from_type === 'odf' && Number(route.from_id) === Number(odf.id))
        && route.path?.length
    );
    if (exact || !parentCabinet) return exact || null;

    const cabinetPoint = L.latLng(cabinet.lat, cabinet.lng);
    return data.routes
        .filter(route => route.type !== 'drop' && route.from_type === 'cabinet' && Number(route.from_id) === Number(parentCabinet.id) && route.path?.length)
        .map(route => {
            const start = L.latLng(route.path[0][0], route.path[0][1]);
            const end = L.latLng(route.path[route.path.length - 1][0], route.path[route.path.length - 1][1]);
            return { route, distance: Math.min(map.distance(cabinetPoint, start), map.distance(cabinetPoint, end)) };
        })
        .filter(item => item.distance <= 75)
        .sort((a, b) => a.distance - b.distance)[0]?.route || null;
}
function addTraceMarker(latlng, label, type) {
    return trackLayer(L.circleMarker(latlng, {
        radius: type === 'house' ? 10 : 12,
        color: '#f59e0b',
        weight: 3,
        fillColor: '#fef3c7',
        fillOpacity: .85,
    }).bindTooltip(label, { permanent: false }).addTo(map), 'trace');
}
function addTraceLine(points, physical = true) {
    return trackLayer(L.polyline(points, {
        color: physical ? '#f59e0b' : '#ef4444',
        weight: physical ? 6 : 3,
        opacity: .95,
        dashArray: physical ? null : '7 8',
    }).addTo(map), 'trace');
}
function routePointProjection(point, route) {
    let best = null;
    const path = (route.path || []).map(routePoint => L.latLng(routePoint[0], routePoint[1]));
    for (let i = 1; i < path.length; i++) {
        const projected = projectOnSegment(point, path[i - 1], path[i]);
        const p = map.latLngToLayerPoint(point), a = map.latLngToLayerPoint(path[i - 1]), b = map.latLngToLayerPoint(path[i]);
        const ab = b.subtract(a), ap = p.subtract(a), den = ab.x * ab.x + ab.y * ab.y;
        const t = den ? Math.max(0, Math.min(1, (ap.x * ab.x + ap.y * ab.y) / den)) : 0;
        const distance = map.distance(point, projected);
        if (!best || distance < best.distance) best = { point: projected, distance, segmentIndex: i, t };
    }
    return best;
}
function routePathBetweenPoints(fromPoint, toPoint, route) {
    const path = (route.path || []).map(routePoint => L.latLng(routePoint[0], routePoint[1]));
    if (path.length < 2) return [fromPoint, toPoint];
    const from = routePointProjection(fromPoint, route);
    const to = routePointProjection(toPoint, route);
    if (!from || !to) return [fromPoint, toPoint];
    const middle = [];
    if (from.segmentIndex <= to.segmentIndex) {
        for (let i = from.segmentIndex; i < to.segmentIndex; i++) middle.push(path[i]);
    } else {
        for (let i = from.segmentIndex - 1; i >= to.segmentIndex; i--) middle.push(path[i]);
    }
    return [fromPoint, from.point, ...middle, to.point, toPoint];
}
function traceLogicalNetworkPath(fromPoint, toPoint, spineRoute = null) {
    if (spineRoute?.path?.length) return routePathBetweenPoints(fromPoint, toPoint, spineRoute);
    return [fromPoint, toPoint];
}
function routeProjectionForNetwork(point) {
    return data.routes
        .filter(route => route.type !== 'drop' && route.path?.length)
        .map(route => ({ route, projection: routePointProjection(point, route) }))
        .filter(item => item.projection)
        .sort((a, b) => a.projection.distance - b.projection.distance)[0] || null;
}
function networkNodeKey(point) {
    return `${Number(point.lat).toFixed(7)},${Number(point.lng).toFixed(7)}`;
}
function addNetworkEdge(graph, a, b) {
    const ak = networkNodeKey(a), bk = networkNodeKey(b), weight = map.distance(a, b);
    graph.nodes[ak] = a;
    graph.nodes[bk] = b;
    graph.edges[ak] ??= [];
    graph.edges[bk] ??= [];
    graph.edges[ak].push({ key: bk, weight });
    graph.edges[bk].push({ key: ak, weight });
}
function buildTraceNetworkGraph(fromPoint, toPoint) {
    const fromProjection = routeProjectionForNetwork(fromPoint);
    const toProjection = routeProjectionForNetwork(toPoint);
    const graph = { nodes: {}, edges: {}, startKey: networkNodeKey(fromPoint), endKey: networkNodeKey(toPoint) };
    const insertsByRoute = {};
    if (fromProjection) {
        insertsByRoute[fromProjection.route.id] ??= [];
        insertsByRoute[fromProjection.route.id].push({ ...fromProjection.projection, sourcePoint: fromPoint, sourceKey: graph.startKey });
    }
    if (toProjection) {
        insertsByRoute[toProjection.route.id] ??= [];
        insertsByRoute[toProjection.route.id].push({ ...toProjection.projection, sourcePoint: toPoint, sourceKey: graph.endKey });
    }
    data.routes.filter(route => route.type !== 'drop' && route.path?.length).forEach(route => {
        const path = route.path.map(point => L.latLng(point[0], point[1]));
        for (let i = 1; i < path.length; i++) {
            const segmentPoints = [
                { point: path[i - 1], t: 0 },
                { point: path[i], t: 1 },
                ...(insertsByRoute[route.id] || [])
                    .filter(insert => insert.segmentIndex === i)
                    .map(insert => ({ point: insert.point, t: insert.t ?? 0.5, sourcePoint: insert.sourcePoint, sourceKey: insert.sourceKey })),
            ].sort((a, b) => a.t - b.t);
            for (let j = 1; j < segmentPoints.length; j++) addNetworkEdge(graph, segmentPoints[j - 1].point, segmentPoints[j].point);
            segmentPoints.filter(item => item.sourcePoint).forEach(item => addNetworkEdge(graph, item.sourcePoint, item.point));
        }
    });
    return graph;
}
function shortestTraceNetworkPath(fromPoint, toPoint) {
    const graph = buildTraceNetworkGraph(fromPoint, toPoint);
    const distances = { [graph.startKey]: 0 };
    const previous = {};
    const queue = new Set(Object.keys(graph.nodes));
    queue.add(graph.startKey);
    queue.add(graph.endKey);
    while (queue.size) {
        const current = [...queue].sort((a, b) => (distances[a] ?? Infinity) - (distances[b] ?? Infinity))[0];
        if (!current || (distances[current] ?? Infinity) === Infinity) break;
        queue.delete(current);
        if (current === graph.endKey) break;
        (graph.edges[current] || []).forEach(edge => {
            const nextDistance = distances[current] + edge.weight;
            if (nextDistance < (distances[edge.key] ?? Infinity)) {
                distances[edge.key] = nextDistance;
                previous[edge.key] = current;
                queue.add(edge.key);
            }
        });
    }
    if (!previous[graph.endKey]) return null;
    const keys = [];
    for (let key = graph.endKey; key; key = previous[key]) {
        keys.unshift(key);
        if (key === graph.startKey) break;
    }
    return keys.map(key => graph.nodes[key] || L.latLng(...key.split(',').map(Number)));
}
function showFiberTrace(houseId) {
    clearFiberTrace();
    const house = data.houses.find(item => Number(item.id) === Number(houseId));
    if (!house) return;
    activeTraceHouseId = house.id;
    const cabinet = data.cabinets.find(item => Number(item.id) === Number(house.cabinet_id));
    const odf = cabinet ? data.odfs.find(item => Number(item.id) === Number(cabinet.odf_id)) : null;
    const panel = document.getElementById('map-trace-panel');
    const output = document.getElementById('map-trace-output');
    panel?.classList.remove('hidden');

    if (!cabinet || !odf) {
        output.innerHTML = `<div class="rounded-md bg-red-50 p-3 text-red-700">Kuca ${house.label} nema kompletnu vezu do FTTH ormarica i ODF-a.</div>`;
        return;
    }

    const housePoint = L.latLng(house.lat, house.lng);
    const cabinetPoint = L.latLng(cabinet.lat, cabinet.lng);
    const odfPoint = L.latLng(odf.lat, odf.lng);
    const houseMarker = houseMarkerByKey[pointKey(house.lat, house.lng)];
    houseMarker?.setIcon(icon('house', '', '#f59e0b'));
    addTraceMarker(housePoint, house.label, 'house');
    addTraceMarker(odfPoint, odf.name, 'odf');

    const physical = [];
    const missing = [];
    const supplyChain = cabinetSupplyChain(cabinet);
    supplyChain.forEach(item => addTraceMarker(L.latLng(item.lat, item.lng), item.name, 'cabinet'));
    for (let index = 0; index < supplyChain.length; index++) {
        const child = supplyChain[index];
        const parent = supplyChain[index + 1] || null;
        const fromPoint = parent ? L.latLng(parent.lat, parent.lng) : odfPoint;
        const toPoint = L.latLng(child.lat, child.lng);
        const supplyRoute = supplyRouteToCabinet(child, parent, odf);
        if (supplyRoute?.path?.length > 1) {
            physical.push(addTraceLine(supplyRoute.path.map(point => L.latLng(point[0], point[1])), true));
        } else {
            const networkPath = shortestTraceNetworkPath(fromPoint, toPoint);
            if (networkPath?.length > 1) {
                physical.push(addTraceLine(networkPath, true));
            } else {
                missing.push(parent ? `${parent.name} -> ${child.name}` : `${odf.name} -> ${child.name}`);
                physical.push(addTraceLine([fromPoint, toPoint], false));
            }
        }
    }
    const cabinetToHousePath = shortestTraceNetworkPath(cabinetPoint, housePoint);
    const dropRoute = traceRouteFor('drop', house, cabinet, odf);
    if (dropRoute?.path?.length && dropRoute.path.length > 2) {
        physical.push(addTraceLine(dropRoute.path.map(point => L.latLng(point[0], point[1])), true));
    } else if (cabinetToHousePath?.length > 1) {
        missing.push('FTTH -> kuca');
        physical.push(addTraceLine(cabinetToHousePath, false));
    } else {
        missing.push('FTTH -> kuca');
        physical.push(addTraceLine([cabinetPoint, housePoint], false));
    }

    const warning = missing.length ? `<div class="rounded-md bg-amber-50 p-2 text-xs font-semibold text-amber-800">Nema nacrtane fizicke trase za ovu vezu. Prikazana je logicka veza koja prati postojecu trasu/rov gdje god je moguce. (${missing.join(', ')})</div>` : '';
    output.innerHTML = `
        <div class="rounded-md bg-white p-2"><b>${house.label}</b><br>Kuca</div>
        <div class="text-center font-black text-slate-500">↓</div>
        ${supplyChain.map(item => `<div class="rounded-md bg-white p-2"><b>${item.name}</b><br>FTTH ormaric</div>`).join('<div class="text-center font-black text-slate-500">↓</div>')}
        <div class="text-center font-black text-slate-500">↓</div>
        <div class="rounded-md bg-white p-2"><b>${odf.name}</b><br>ODF</div>
        ${warning}
    `;
    const bounds = L.latLngBounds([housePoint, odfPoint, ...supplyChain.map(item => L.latLng(item.lat, item.lng))]);
    physical.forEach(line => line.getLatLngs().forEach(point => bounds.extend(point)));
    map.fitBounds(bounds, { padding: [70, 70], maxZoom: 19 });
}
function renderBranchList() {
    document.getElementById('route-branch-count').textContent = `${branchMeta.length} krakova`;
    document.getElementById('route-branch-list').innerHTML = branchMeta.length
        ? branchMeta.map(meta => {
            const odfLabel = meta.odf_index === null || meta.odf_index === undefined ? 'bez ODF' : `ODF-${String(meta.odf_index + 1).padStart(2, '0')}`;
            return `<div class="flex items-center justify-between rounded bg-white/80 px-2 py-1"><span>${meta.name} · ${routeTypeLabel(meta.route_type)} · ${odfLabel}</span><strong>${meta.duct_length_m} m</strong></div>`;
        }).join('')
        : '<div class="rounded bg-white/70 px-2 py-2 text-amber-800">Nema nacrtanih krakova.</div>';
}
function refreshRouteOdfStatus() {
    const status = document.getElementById('route-odf-status');
    status.textContent = activeDraftOdfIndex === null
        ? 'Krak nije vezan na ODF. Odaberi/postavi aktivni ODF prije crtanja.'
        : `Novi krakovi se vežu na ODF-${String(activeDraftOdfIndex + 1).padStart(2, '0')}.`;
}
function refreshTrenchGroupStatus() {
    const input = document.getElementById('route-draw-type');
    const status = document.getElementById('route-trench-status');
    if (!input || !status) return;
    const isTrench = input.value === 'trench';
    status.textContent = isTrench ? 'crtanje rova' : 'crtanje mikrocijevi';
    status.className = isTrench
        ? 'rounded bg-emerald-600 px-2 py-1 text-[10px] font-bold text-white'
        : 'rounded bg-white px-2 py-1 text-[10px] font-bold text-amber-800';
    ['route-draw-microducts', 'route-draw-microduct-type', 'route-draw-fiber-count'].forEach(id => {
        const field = document.getElementById(id);
        if (field) field.disabled = isTrench;
    });
}
function routeLabelPlacement(points, position = .5) {
    if (!points.length) return null;
    if (points.length === 1) return { latlng: points[0], angle: 0 };
    const total = distance(points);
    const target = total * position;
    let walked = 0;
    for (let i = 1; i < points.length; i++) {
        const segment = map.distance(points[i - 1], points[i]);
        if (walked + segment >= target) {
            const ratio = segment ? (target - walked) / segment : 0;
            const latlng = L.latLng(
                points[i - 1].lat + (points[i].lat - points[i - 1].lat) * ratio,
                points[i - 1].lng + (points[i].lng - points[i - 1].lng) * ratio
            );
            const a = map.latLngToLayerPoint(points[i - 1]);
            const b = map.latLngToLayerPoint(points[i]);
            let angle = Math.atan2(b.y - a.y, b.x - a.x) * 180 / Math.PI;
            if (angle > 90 || angle < -90) angle += 180;

            return { latlng, angle };
        }
        walked += segment;
    }
    return { latlng: points[Math.floor(points.length / 2)], angle: 0 };
}
function addRouteLabel(points, name, track = true, specs = null) {
    const labelName = normalizeRouteDisplayName(name);
    const specsText = showCableSpecs && specs ? ` <span style="opacity:.65;font-size:.85em">${specs}</span>` : '';
    const labelHtml = `${labelName}${specsText}`;
    const markers = [];
    const total = distance(points);
    const positions = total > 500 ? [.2, .5, .8] : (total > 180 ? [.3, .7] : [.5]);
    positions.forEach(position => {
        const placement = routeLabelPlacement(points, position);
        if (!placement) return;
        const marker = L.marker(placement.latlng, {
            interactive: false,
            keyboard: false,
            icon: L.divIcon({
                className: 'route-label',
                html: `<span style="transform: rotate(${placement.angle.toFixed(1)}deg)">${labelHtml}</span>`,
                iconAnchor: [12, 18],
        }),
    }).addTo(map);
        markers.push(marker);
    });
    if (track) {
        branchLabelGroups.push(markers);
        branchLabels.push(...markers);
    }
    return markers;
}
function normalizeRouteDisplayName(name) {
    return String(name || '').replace(/\b(Sekundarni krak|Primarni krak|Glavni rov)\s+(\d+(?:[.-]\d+)*)/gi, (full, prefix, code) => {
        return `${prefix} ${normalizeRouteCodeDisplay(code)}`;
    });
}
function normalizeRouteCodeDisplay(code) {
    const raw = String(code || '').trim();
    const match = raw.match(/^(.+)-(\d+)$/);
    if (match && match[1].includes('.')) {
        return `${normalizeBranchCode(match[1])}-${match[2]}`;
    }

    return normalizeBranchCode(raw);
}
function showCadContext(latlng, title, actions) {
    cadContext = actions;
    const buttons = actions.map((action, index) => `<button type="button" data-cad-action="${index}" class="block w-full rounded px-2 py-1 text-left text-[10px] font-semibold leading-tight hover:bg-zinc-100">${action.label}</button>`).join('');
    L.popup({ closeButton: true, minWidth: 82, maxWidth: 110, className: 'cad-popup' })
        .setLatLng(latlng)
        .setContent(`<div class="w-[92px]"><div class="border-b border-zinc-200 px-2 py-1 text-[10px] font-bold leading-tight">${title}</div><div class="p-0.5">${buttons}</div></div>`)
        .openOn(map);
}
map.on('popupopen', event => {
    event.popup.getElement()?.querySelectorAll('[data-cad-action]').forEach(button => {
        button.addEventListener('click', () => {
            const action = cadContext?.[Number(button.dataset.cadAction)];
            if (action) action.run();
            map.closePopup();
        });
    });
});
function removeDraftElement(marker) {
    const item = draftElements.find(entry => entry.marker === marker);
    if (!item) return;
    if (selectedDraftElement?.item.marker === marker) closeDraftElementEditor();
    const appendixItem = draftAppendixItems.find(entry => entry.marker === marker);
    if (appendixItem) removeAppendixDraftItem(appendixItem);
    else map.removeLayer(marker);
    draftElements = draftElements.filter(entry => entry.marker !== marker);
    if (item.type === 'odf') {
        const removedIndex = draftOdfs.findIndex(entry => entry.marker === marker);
        draftOdfs = draftOdfs.filter(entry => entry.marker !== marker);
        draftCabinets.forEach(cabinet => {
            if (cabinet.odf_index === removedIndex) cabinet.odf_index = null;
            if (cabinet.odf_index > removedIndex) cabinet.odf_index--;
        });
        activeDraftOdfIndex = draftOdfs.length ? Math.min(activeDraftOdfIndex ?? 0, draftOdfs.length - 1) : null;
    }
    if (item.type === 'cabinet') draftCabinets = draftCabinets.filter(entry => entry.marker !== marker);
    if (item.type === 'manhole' || item.type === 'boring_fi_130') {
        draftAppendixItems = draftAppendixItems.filter(entry => entry.marker !== marker);
    }
    refreshDraftTooltips();
    refreshPlanSummary();
}
function registerDraftContext(marker, title) {
    const openMenu = event => {
        L.DomEvent.stop(event);
        showCadContext(event.latlng, title, [
            { label: 'Obrisi', run: () => removeDraftElement(marker) },
            { label: 'Pomjeri', run: () => marker.dragging?.enable() },
        ]);
    };
    marker.on('contextmenu', openMenu);
    marker.on('click', event => {
        if (mode === 'pan') openMenu(event);
    });
}
function removeDraftHouse(marker) {
    const index = houseMarkers.indexOf(marker);
    if (index < 0) return;
    map.removeLayer(marker);
    houseMarkers.splice(index, 1);
    housePoints.splice(savedHouseCount + index, 1);
    refreshStats();
}
function registerHouseContext(marker) {
    const openMenu = event => {
        L.DomEvent.stop(event);
        showCadContext(event.latlng, 'Kuca', [
            { label: 'Obrisi', run: () => removeDraftHouse(marker) },
            { label: 'Pomjeri', run: () => marker.dragging?.enable() },
        ]);
    };
    marker.on('contextmenu', openMenu);
    marker.on('click', event => {
        if (mode === 'pan') openMenu(event);
    });
}
function removeBranchAt(index) {
    const line = branchLines[index];
    if (line) map.removeLayer(line);
    branchLines.splice(index, 1);
    branches.splice(index, 1);
    branchMeta.splice(index, 1);
    const labels = branchLabelGroups.splice(index, 1)[0] || [];
    labels.forEach(label => {
        if (label) map.removeLayer(label);
        branchLabels = branchLabels.filter(item => item !== label);
    });
    renderBranchList();
    refreshStats();
}
function registerBranchContext(line) {
    const openMenu = event => {
        L.DomEvent.stop(event);
        const index = branchLines.indexOf(line);
        if (index < 0) return;
        showCadContext(event.latlng, branchMeta[index]?.name || 'Krak trase', [
            { label: 'Obrisi krak', run: () => removeBranchAt(index) },
        ]);
    };
    line.on('contextmenu', openMenu);
    line.on('click', event => {
        if (mode === 'pan') openMenu(event);
    });
}
async function deleteSavedElement(url, layer) {
    if (!confirm('Sigurno obrisati?')) return;
    const response = await fetch(url, {
        method: 'DELETE',
        headers: {
            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '',
            'Accept': 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
        },
    });

    if (!response.ok) {
        alert(await response.text() || 'Brisanje nije uspjelo.');
        return;
    }

    (Array.isArray(layer) ? layer : [layer]).filter(Boolean).forEach(item => map.removeLayer(item));
    document.getElementById('cad-command').textContent = 'Element je obrisan.';
}
async function saveSavedPosition(marker, url) {
    const position = marker.getLatLng();
    const response = await fetch(url, {
        method: 'PATCH',
        headers: {
            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '',
            'Accept': 'application/json',
            'Content-Type': 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
        },
        body: JSON.stringify({ latitude: position.lat, longitude: position.lng }),
    });

    if (!response.ok) throw new Error(await response.text() || 'Pomjeranje nije sačuvano.');
    marker.dragging?.disable();
    document.getElementById('cad-command').textContent = 'Nova pozicija je sačuvana.';
}
function registerSavedContext(layer, title, url, positionUrl = null, clickAction = null, customActions = [], onDelete = null) {
    const triggerLayer = Array.isArray(layer) ? layer[0] : layer;
    const allLayers = Array.isArray(layer) ? layer : [layer];
    selectionRegistry.push({ triggerLayer, allLayers, url, title });
    let savedPosition = triggerLayer.getLatLng?.();
    if (positionUrl) {
        triggerLayer.on('dragend', async () => {
            const oldPos = savedPosition;
            try {
                await saveSavedPosition(triggerLayer, positionUrl);
                const newPos = triggerLayer.getLatLng();
                savedPosition = newPos;
                mapHistory.push({
                    label: `Pomjeri ${title}`,
                    undo: async () => {
                        triggerLayer.setLatLng(oldPos);
                        await saveSavedPosition(triggerLayer, positionUrl);
                        savedPosition = oldPos;
                    },
                    redo: async () => {
                        triggerLayer.setLatLng(newPos);
                        await saveSavedPosition(triggerLayer, positionUrl);
                        savedPosition = newPos;
                    },
                });
            } catch (error) {
                if (savedPosition) triggerLayer.setLatLng(savedPosition);
                triggerLayer.dragging?.disable();
                alert(error.message);
            }
        });
    }
    const openMenu = event => {
        L.DomEvent.stop(event);
        if (layerLocked(triggerLayer._ftthLayerType)) {
            document.getElementById('cad-command').textContent = `Layer ${triggerLayer._ftthLayerType} je zaključan.`;
            return;
        }
        const actions = [
            ...(typeof customActions === 'function' ? customActions(event.latlng) : (customActions || [])),
            { label: 'Obrisi', run: () => onDelete ? onDelete() : deleteSavedElement(url, layer) },
        ];
        if (positionUrl) actions.push({ label: 'Pomjeri', run: () => triggerLayer.dragging?.enable() });
        showCadContext(event.latlng, title, actions);
    };
    triggerLayer.on('contextmenu', openMenu);
    triggerLayer.on('click', event => {
        if (!['pan', 'join'].includes(mode)) return;
        if (layerLocked(triggerLayer._ftthLayerType)) {
            document.getElementById('cad-command').textContent = `Layer ${triggerLayer._ftthLayerType} je zaključan.`;
            return;
        }
        if (clickAction) {
            L.DomEvent.stop(event);
            triggerLayer.closePopup?.();
            clickAction(event);
        } else {
            openMenu(event);
        }
    });
}
// ─── BOX SELECT ──────────────────────────────────────────────────────────────

(function initBoxSelect() {
    const rb      = document.getElementById('select-rubber-band');
    const actPanel = document.getElementById('select-actions');
    const countEl  = document.getElementById('select-count');
    const delBtn   = document.getElementById('select-delete-btn');
    const cancelBtn = document.getElementById('select-cancel-btn');
    const mapCont  = document.getElementById('map-container');

    let dragStart = null; // { x, y } in container coords
    let dragging  = false;

    function containerOffset(e) {
        const rect = mapCont.getBoundingClientRect();
        return { x: e.clientX - rect.left, y: e.clientY - rect.top };
    }

    function showRubberBand(a, b) {
        const x = Math.min(a.x, b.x), y = Math.min(a.y, b.y);
        const w = Math.abs(a.x - b.x),  h = Math.abs(a.y - b.y);
        rb.style.left   = x + 'px';
        rb.style.top    = y + 'px';
        rb.style.width  = w + 'px';
        rb.style.height = h + 'px';
        rb.style.display = 'block';
    }

    function hideRubberBand() {
        rb.style.display = 'none';
    }

    function showActionsPanel() {
        actPanel.style.display = 'flex';
    }
    function hideActionsPanel() {
        actPanel.style.display = 'none';
    }

    function applyHighlight(entry, on) {
        if (on && !entry._origStyles) entry._origStyles = {};
        entry.allLayers.forEach((l, i) => {
            if (!l || !map.hasLayer(l)) return;
            if (typeof l.setStyle === 'function') {
                if (on) {
                    if (!entry._origStyles[i]) entry._origStyles[i] = { color: l.options.color, weight: l.options.weight, opacity: l.options.opacity };
                    l.setStyle({ color: '#3b82f6', weight: (l.options.weight || 3) + 2, opacity: 1 });
                } else if (entry._origStyles?.[i]) {
                    l.setStyle(entry._origStyles[i]);
                }
            } else if (l.getElement) {
                // marker
                const el = l.getElement();
                if (el) el.style.filter = on ? 'drop-shadow(0 0 6px #3b82f6) brightness(1.3)' : '';
            }
        });
        if (!on) entry._origStyles = null;
    }

    function clearSelection() {
        currentSelection.forEach(e => {
            if (e.isDxf) {
                e._geomLayer?.setHighlight(false);
            } else {
                applyHighlight(e, false);
            }
        });
        currentSelection = [];
        hideActionsPanel();
        countEl.textContent = '0 selektovano';
    }

    function doBoxSelect(a, b) {
        const p1 = map.containerPointToLatLng(L.point(a.x, a.y));
        const p2 = map.containerPointToLatLng(L.point(b.x, b.y));
        const bounds = L.latLngBounds(p1, p2);

        clearSelection();

        // 1. Standardni Leaflet elementi (rute, ODF, ormarić, kuća...)
        selectionRegistry.forEach(entry => {
            if (!map.hasLayer(entry.triggerLayer)) return;
            if (layerLocked(entry.triggerLayer._ftthLayerType)) return;
            let hit = false;
            const lll = entry.triggerLayer.getLatLng?.();
            if (lll) {
                hit = bounds.contains(lll);
            } else {
                const lls = entry.triggerLayer.getLatLngs?.();
                if (lls) {
                    const flat = lls.flat ? lls.flat(2) : lls;
                    hit = flat.some(ll => bounds.contains(ll));
                }
            }
            if (hit) {
                currentSelection.push(entry);
                applyHighlight(entry, true);
            }
        });

        // 2. DXF background layeri — intersect (jer pokrivaju veliku površinu)
        const dxfItems = window.ftthDxfLayer?.getSelectableItems() ?? [];
        dxfItems.forEach(item => {
            if (!item.bounds || !bounds.intersects(item.bounds)) return;
            const entry = {
                isDxf: true,
                dxfId: item.dxfId,
                title: item.name,
                allLayers: [item.geomLayer, item.textLayer].filter(Boolean),
                _geomLayer: item.geomLayer,
            };
            currentSelection.push(entry);
            item.geomLayer.setHighlight(true);
        });

        if (currentSelection.length > 0) {
            countEl.textContent = currentSelection.length + ' selektovano';
            showActionsPanel();
            document.getElementById('cad-command').textContent =
                `SELEKT: ${currentSelection.length} element(a) selektovano. Klikni "Obriši selektovano" ili ESC.`;
        } else {
            document.getElementById('cad-command').textContent = 'SELEKT: drag da označiš elemente.';
        }
    }

    // Mouse events on map container
    mapCont.addEventListener('mousedown', e => {
        if (mode !== 'select') return;
        if (e.button !== 0) return;
        dragStart = containerOffset(e);
        dragging = false;
        e.preventDefault();
    });

    window.addEventListener('mousemove', e => {
        if (mode !== 'select' || !dragStart) return;
        const cur = containerOffset(e);
        const dx = Math.abs(cur.x - dragStart.x), dy = Math.abs(cur.y - dragStart.y);
        if (dx > 4 || dy > 4) {
            dragging = true;
            showRubberBand(dragStart, cur);
        }
    });

    window.addEventListener('mouseup', e => {
        if (mode !== 'select' || !dragStart) return;
        const cur = containerOffset(e);
        hideRubberBand();
        if (dragging) {
            doBoxSelect(dragStart, cur);
        }
        dragStart = null;
        dragging  = false;
    });

    // Delete selected
    delBtn.addEventListener('click', async () => {
        if (!currentSelection.length) return;
        if (!confirm(`Obrisati ${currentSelection.length} element(a)?`)) return;

        const toDelete = [...currentSelection];
        clearSelection();
        setMode('pan');

        const csrf = document.querySelector('meta[name="csrf-token"]')?.content || '';
        let ok = 0, fail = 0;
        for (const entry of toDelete) {
            try {
                if (entry.isDxf) {
                    // DXF layer — lokalno brisanje iz IndexedDB, nema server zahtjeva
                    window.ftthDxfLayer?.removeLayerById(entry.dxfId);
                    ok++;
                } else {
                    const res = await fetch(entry.url, {
                        method: 'DELETE',
                        headers: { 'X-CSRF-TOKEN': csrf, 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                    });
                    if (res.ok) {
                        entry.allLayers.forEach(l => { if (l && map.hasLayer(l)) map.removeLayer(l); });
                        ok++;
                    } else {
                        fail++;
                    }
                }
            } catch {
                fail++;
            }
        }
        document.getElementById('cad-command').textContent =
            `Obrisano: ${ok}${fail ? ', greška: ' + fail : ''}.`;
    });

    // Cancel
    cancelBtn.addEventListener('click', () => {
        clearSelection();
        document.getElementById('cad-command').textContent = 'SELEKT: drag da označiš elemente.';
    });

    // ESC cancels selection
    document.addEventListener('keydown', e => {
        if (e.key === 'Escape' && mode === 'select' && currentSelection.length) {
            clearSelection();
            document.getElementById('cad-command').textContent = 'SELEKT: drag da označiš elemente.';
        }
    });
})();

function routeLayerType(type) {
    return ['trench', 'backbone', 'drop'].includes(type) ? type : 'distribution';
}
function layerLocked(type) {
    return Boolean(layerLocks[type]);
}
function updateLayerCount(type) {
    const count = document.querySelector(`[data-layer-count="${type}"]`);
    if (!count) return;
    const objectCounts = {
        odf: () => data.odfs.length + draftOdfs.length,
        odo: () => data.cabinets.length + draftCabinets.length,
        houses: () => data.houses.length + Math.max(houseMarkers.length - data.houses.length, 0),
        trench: () => data.routes.filter(route => route.type === 'trench').length + branchMeta.filter(route => route.route_type === 'trench').length,
        backbone: () => data.routes.filter(route => route.type === 'backbone').length + branchMeta.filter(route => route.route_type === 'backbone').length,
        distribution: () => data.routes.filter(route => !['trench', 'backbone', 'drop'].includes(route.type)).length + branchMeta.filter(route => !['trench', 'backbone', 'drop'].includes(route.route_type)).length,
        drop: () => data.routes.filter(route => route.type === 'drop').length,
        dxf: () => 0,
    };
    count.textContent = objectCounts[type] ? objectCounts[type]() : (layerRegistry[type]?.length || 0);
}
function routeEditVertexIcon() {
    return L.divIcon({
        className: 'ftth-label',
        html: '<div style="width:9px;height:9px;background:#2563eb;border:2px solid #fff;box-shadow:0 0 0 1px #0f172a"></div>',
        iconAnchor: [5, 5],
    });
}
function routeEditMidpointIcon() {
    return L.divIcon({
        className: 'ftth-label',
        html: '<div style="width:7px;height:7px;background:rgba(37,99,235,0.35);border:1.5px solid #2563eb;border-radius:1px;transform:rotate(45deg);box-shadow:0 0 0 1px rgba(255,255,255,0.6)"></div>',
        iconAnchor: [4, 4],
    });
}
function startRouteEdit(route, line) {
    if (routeEdit?.route.id === route.id) return;
    cancelRouteEdit();
    routeEditUndoStack.length = 0;
    routeEditRedoStack.length = 0;
    syncRouteEditUndoButtons();
    const points = line.getLatLngs().map(point => L.latLng(point.lat, point.lng));
    routeEdit = { route, line, originalPoints: points.map(point => L.latLng(point.lat, point.lng)), points, markers: [], midpointMarkers: [] };
    line.setStyle({ color: '#2563eb', weight: 4, opacity: 1, dashArray: '2 4' });
    document.getElementById('route-edit-actions').classList.remove('hidden');
    document.getElementById('route-edit-actions').classList.add('flex');
    renderRouteEditVertices();
}

function openRouteAttributePanel(route) {
    selectedAttributeRoute = route;
    document.getElementById('route-attribute-panel').classList.remove('hidden');
    document.getElementById('route-attribute-status').textContent = `${route.name} | ${route.length ?? route.duct_length_m ?? 0} m`;
    document.getElementById('route-attr-name').value = route.name || '';
    document.getElementById('route-attr-type').value = route.type || 'distribution';
    document.getElementById('route-attr-microduct').value = route.microduct_type || route.microduct || '';
    document.getElementById('route-attr-fibers').value = route.fiber_count || route.fibers || 12;
    document.getElementById('route-attr-note').value = route.note || '';
}

function closeRouteAttributePanel() {
    selectedAttributeRoute = null;
    document.getElementById('route-attribute-panel').classList.add('hidden');
}

async function saveRouteAttributes() {
    if (!selectedAttributeRoute) return;
    const route = selectedAttributeRoute;
    const oldLayerType = routeLayerType(route.type);
    const response = await fetch(`${appConfig.routesBase}/${route.id}`, {
        method: 'PATCH',
        headers: {
            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '',
            'Accept': 'application/json',
            'Content-Type': 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
        },
        body: JSON.stringify({
            name: document.getElementById('route-attr-name').value.trim(),
            route_type: document.getElementById('route-attr-type').value,
            microduct_type: document.getElementById('route-attr-microduct').value || null,
            fiber_count: Number(document.getElementById('route-attr-fibers').value || 12),
            note: document.getElementById('route-attr-note').value,
            odf_id: route.odf_id || null,
            from_type: route.from_type || null,
            from_id: route.from_id || null,
            to_type: route.to_type || null,
            to_id: route.to_id || null,
            cabinet_id: route.cabinet_id || null,
        }),
    });
    const result = await readJsonResponse(response, 'Podaci trase nisu sacuvani.');
    Object.assign(route, result.route, {
        type: result.route.type,
        microduct_type: result.route.microduct,
        fiber_count: result.route.fibers,
    });
    const line = routeLayerById[route.id];
    if (line) {
        untrackLayer(line, oldLayerType);
        trackLayer(line, routeLayerType(route.type));
        line.setStyle(routeLineStyle(route.type, routeLineColor(route)));
        line.setPopupContent(`<b>${route.name}</b><br>${routeTypeLabel(route.type)}<br>${route.length ?? route.duct_length_m ?? 0} m`);
    }
    refreshRouteLabels(route, oldLayerType);
    document.getElementById('route-attribute-status').textContent = 'Podaci trase su sacuvani.';
    document.getElementById('cad-command').textContent = `TRASA: ${route.name} sacuvana.`;
}

function refreshRouteLabels(route, oldLayerType = null) {
    (routeLabelsById[route.id] || []).forEach(label => {
        untrackLayer(label, oldLayerType);
        map.removeLayer(label);
    });

    if (route.type === 'trench' || !route.path?.length) {
        routeLabelsById[route.id] = [];
        return;
    }

    const points = route.path.map(point => L.latLng(point[0], point[1]));
    routeLabelsById[route.id] = addRouteLabel(points, route.name, false, routeLabelSpecs(route));
    routeLabelsById[route.id].forEach(label => trackLayer(label, routeLayerType(route.type)));
}
function renderRouteEditVertices() {
    if (!routeEdit) return;
    routeEdit.markers.forEach(marker => map.removeLayer(marker));
    routeEdit.markers = routeEdit.points.map((point, index) => {
        const marker = L.marker(point, { draggable: true, icon: routeEditVertexIcon(), zIndexOffset: 1000 }).addTo(map);
        marker.on('dragstart', () => {
            marker._preDragPos = marker.getLatLng();
            marker._currentSnapTarget = null;
        });
        marker.on('drag', event => {
            const rawPos = event.target.getLatLng();
            const snapTarget = getSnapTarget(rawPos);
            marker._currentSnapTarget = snapTarget || null;
            routeEdit.points[index] = snapTarget ? snapTarget.latlng : rawPos;
            updateRouteEditLine();
            if (snapTarget) {
                showSnapIndicator(snapTarget);
                document.getElementById('cad-command').textContent = `EDIT: snap → ${snapTarget.label}`;
            } else {
                hideSnapIndicator();
                updateRouteEditStatus();
            }
        });
        marker.on('dragend', () => {
            if (marker._currentSnapTarget) marker.setLatLng(marker._currentSnapTarget.latlng);
            hideSnapIndicator();
            const before = marker._preDragPos;
            const after = marker.getLatLng();
            if (!before || map.distance(before, after) < 0.1) return;
            const i = index;
            pushRouteEditUndo({
                undo: () => { if (!routeEdit) return; routeEdit.points[i] = before; routeEdit.markers[i]?.setLatLng(before); updateRouteEditLine(); renderRouteEditMidpoints(); },
                redo: () => { if (!routeEdit) return; routeEdit.points[i] = after; routeEdit.markers[i]?.setLatLng(after); updateRouteEditLine(); renderRouteEditMidpoints(); },
            });
            renderRouteEditMidpoints();
            updateRouteEditStatus();
        });
        marker.on('contextmenu', event => {
            L.DomEvent.stop(event);
            if (routeEdit.points.length <= 2) {
                document.getElementById('cad-command').textContent = 'EDIT ROUTE: trasa mora imati najmanje 2 tačke.';
                return;
            }
            const i = index;
            const deleted = L.latLng(routeEdit.points[i].lat, routeEdit.points[i].lng);
            routeEdit.points.splice(i, 1);
            updateRouteEditLine();
            renderRouteEditVertices();
            pushRouteEditUndo({
                undo: () => { if (!routeEdit) return; routeEdit.points.splice(i, 0, deleted); updateRouteEditLine(); renderRouteEditVertices(); },
                redo: () => { if (!routeEdit) return; routeEdit.points.splice(i, 1); updateRouteEditLine(); renderRouteEditVertices(); },
            });
        });
        return marker;
    });
    renderRouteEditMidpoints();
    updateRouteEditStatus();
}
function renderRouteEditMidpoints() {
    if (!routeEdit) return;
    (routeEdit.midpointMarkers || []).forEach(m => map.removeLayer(m));
    routeEdit.midpointMarkers = [];
    if (routeEdit.points.length < 2) return;
    for (let i = 1; i < routeEdit.points.length; i++) {
        const a = routeEdit.points[i - 1];
        const b = routeEdit.points[i];
        const mid = L.latLng((a.lat + b.lat) / 2, (a.lng + b.lng) / 2);
        const insertAt = i;
        const marker = L.marker(mid, { draggable: true, icon: routeEditMidpointIcon(), zIndexOffset: 900 }).addTo(map);
        marker.on('dragstart', () => {
            marker._currentSnapTarget = null;
            marker.setIcon(routeEditVertexIcon());
        });
        marker.on('drag', event => {
            const rawPos = event.target.getLatLng();
            const snapTarget = getSnapTarget(rawPos);
            marker._currentSnapTarget = snapTarget || null;
            const previewPos = snapTarget ? snapTarget.latlng : rawPos;
            const previewPoints = [...routeEdit.points];
            previewPoints.splice(insertAt, 0, previewPos);
            routeEdit.line.setLatLngs(previewPoints);
            if (snapTarget) {
                showSnapIndicator(snapTarget);
                document.getElementById('cad-command').textContent = `EDIT: snap → ${snapTarget.label}`;
            } else {
                hideSnapIndicator();
            }
        });
        marker.on('dragend', () => {
            const snapTarget = marker._currentSnapTarget;
            const finalPos = snapTarget ? snapTarget.latlng : marker.getLatLng();
            if (snapTarget) marker.setLatLng(finalPos);
            hideSnapIndicator();
            const newPoint = L.latLng(finalPos.lat, finalPos.lng);
            routeEdit.points.splice(insertAt, 0, newPoint);
            updateRouteEditLine();
            renderRouteEditVertices();
            pushRouteEditUndo({
                undo: () => { if (!routeEdit) return; routeEdit.points.splice(insertAt, 1); updateRouteEditLine(); renderRouteEditVertices(); },
                redo: () => { if (!routeEdit) return; routeEdit.points.splice(insertAt, 0, newPoint); updateRouteEditLine(); renderRouteEditVertices(); },
            });
        });
        routeEdit.midpointMarkers.push(marker);
    }
}
function updateRouteEditLine() {
    if (!routeEdit) return;
    routeEdit.line.setLatLngs(routeEdit.points);
    updateRouteEditStatus();
}
function updateRouteEditStatus() {
    if (!routeEdit) return;
    const length = distance(routeEdit.points);
    document.getElementById('cad-command').textContent = `EDIT ROUTE: ${routeEdit.route.name} | Points: ${routeEdit.points.length} | Length: ${length} m`;
    document.getElementById('cad-metrics').textContent = `EDIT ROUTE: ${routeEdit.route.name} | Points: ${routeEdit.points.length} | Length: ${length} m`;
}
function nearestRouteEditSegment(latlng) {
    if (!routeEdit || routeEdit.points.length < 2) return null;
    let best = null;
    for (let index = 1; index < routeEdit.points.length; index++) {
        const point = projectOnSegment(latlng, routeEdit.points[index - 1], routeEdit.points[index]);
        const distance = layerPixelDistance(latlng, point);
        if (!best || distance < best.distance) best = { point, distance, insertAt: index };
    }
    return best;
}
function addRouteEditVertex(latlng = null) {
    if (!routeEdit) return;
    let target = latlng ? nearestRouteEditSegment(latlng) : null;
    if (!target) {
        let longest = null;
        for (let index = 1; index < routeEdit.points.length; index++) {
            const segmentLength = map.distance(routeEdit.points[index - 1], routeEdit.points[index]);
            if (!longest || segmentLength > longest.segmentLength) longest = { index, segmentLength };
        }
        if (!longest) return;
        const a = routeEdit.points[longest.index - 1], b = routeEdit.points[longest.index];
        target = { insertAt: longest.index, point: L.latLng((a.lat + b.lat) / 2, (a.lng + b.lng) / 2) };
    }
    const insertAt = target.insertAt;
    const newPoint = L.latLng(target.point.lat, target.point.lng);
    routeEdit.points.splice(insertAt, 0, newPoint);
    updateRouteEditLine();
    renderRouteEditVertices();
    pushRouteEditUndo({
        undo: () => { if (!routeEdit) return; routeEdit.points.splice(insertAt, 1); updateRouteEditLine(); renderRouteEditVertices(); },
        redo: () => { if (!routeEdit) return; routeEdit.points.splice(insertAt, 0, newPoint); updateRouteEditLine(); renderRouteEditVertices(); },
    });
}
function cancelRouteEdit() {
    if (!routeEdit) return;
    routeEdit.line.setLatLngs(routeEdit.originalPoints);
    routeEdit.line.setStyle(routeLineStyle(routeEdit.route.type, routeLineColor(routeEdit.route)));
    routeEdit.markers.forEach(marker => map.removeLayer(marker));
    (routeEdit.midpointMarkers || []).forEach(m => map.removeLayer(m));
    routeEdit = null;
    routeEditUndoStack.length = 0;
    routeEditRedoStack.length = 0;
    syncRouteEditUndoButtons();
    document.getElementById('route-edit-actions').classList.add('hidden');
    document.getElementById('route-edit-actions').classList.remove('flex');
    updateCommandBar();
}
async function saveRouteEdit() {
    if (!routeEdit) return;
    if (routeEdit.points.length < 2) {
        document.getElementById('cad-command').textContent = 'EDIT ROUTE: nije moguće snimiti trasu sa manje od 2 tačke.';
        return;
    }
    const oldPoints = routeEdit.originalPoints.map(p => L.latLng(p.lat, p.lng));
    const routeId = routeEdit.route.id;
    const routeName = routeEdit.route.name;
    const path = routeEdit.points.map(point => [Number(point.lat.toFixed(7)), Number(point.lng.toFixed(7))]);
    const length = distance(routeEdit.points);
    const response = await fetch(`${appConfig.routesBase}/${routeEdit.route.id}/geometrija`, {
        method: 'PATCH',
        headers: {
            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '',
            'Accept': 'application/json',
            'Content-Type': 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
        },
        body: JSON.stringify({ path, duct_length_m: length, fiber_length_m: length }),
    });
    const result = await readJsonResponse(response, 'Izmjene trase nisu sačuvane.');
    const edited = routeEdit;
    edited.route.path = result.route.path;
    edited.route.duct_length_m = result.route.length;
    const newPoints = edited.points.map(point => L.latLng(point.lat, point.lng));
    const savedRouteIndex = data.routes.filter(route => route.path?.length).findIndex(route => route.id === edited.route.id);
    if (savedRouteIndex >= 0) savedRoutePoints[savedRouteIndex] = newPoints;
    edited.originalPoints = newPoints;
    edited.line.setPopupContent(`<b>${edited.route.name}</b><br>${routeTypeLabel(edited.route.type)}<br>${result.route.length} m`);
    edited.line.setStyle(routeLineStyle(edited.route.type, routeLineColor(edited.route)));
    routeHitLayerById[edited.route.id]?.setLatLngs(edited.points);
    edited.markers.forEach(marker => map.removeLayer(marker));
    (edited.midpointMarkers || []).forEach(m => map.removeLayer(m));
    routeEdit = null;
    document.getElementById('route-edit-actions').classList.add('hidden');
    document.getElementById('route-edit-actions').classList.remove('flex');
    document.getElementById('cad-command').textContent = `Trasa ${routeName} je sačuvana (${result.route.length} m).`;
    updateCommandBar();
    mapHistory.push({
        label: `Uredi geometriju ${routeName}`,
        undo: async () => { await patchRouteGeometryOnMap(routeId, oldPoints); },
        redo: async () => { await patchRouteGeometryOnMap(routeId, newPoints); },
    });
}
function refreshStats() {
    const d = allDistance();
    document.getElementById('draw-length').textContent = `${d} m`;
    document.getElementById('route-duct').value = d;
    document.getElementById('route-fiber').value = d;
    document.getElementById('route-path').value = JSON.stringify(draftNetworkPoints()[0]?.map(p => [Number(p.lat.toFixed(7)), Number(p.lng.toFixed(7))]) || []);
    document.getElementById('house-count').textContent = Math.max(housePoints.length - savedHouseCount, 0);
    refreshPlanSummary();
}
function syncRoutePathInput() {
    const merged = draftNetworkPoints().flatMap((branch, index) => {
        const points = branch.map(p => [Number(p.lat.toFixed(7)), Number(p.lng.toFixed(7))]);
        return index === 0 ? points : [[null, null], ...points];
    }).filter(point => point[0] !== null);
    document.getElementById('route-path').value = JSON.stringify(merged);
}
function redrawActiveBranch() {
    if (activeBranchLine) {
        untrackLayer(activeBranchLine);
        map.removeLayer(activeBranchLine);
    }
    const type = document.getElementById('route-draw-type').value;
    if (activeBranch.length > 1) {
        const style = type === 'trench' ? routeLineStyle('trench') : { color: '#f59e0b', weight: 3, opacity: .95 };
        activeBranchLine = trackLayer(L.polyline(activeBranch, style).addTo(map), routeLayerType(type));
    }
    refreshStats();
    syncRoutePathInput();
    updateCommandBar();
}
function redrawPreviewBranch(latlng = null) {
    if (mode !== 'draw' || !latlng) {
        if (previewBranchLine) map.removeLayer(previewBranchLine);
        previewBranchLine = null;
        hideSnapIndicator();
        return;
    }
    const orthoPoint = applyOrthoPoint(latlng);
    const snapTarget = getSnapTarget(orthoPoint);
    showSnapIndicator(snapTarget);
    const point = snapTarget?.latlng || orthoPoint;
    updateCommandBar(snapTarget?.label || '-', point);
    if (!activeBranch.length) {
        if (previewBranchLine) map.removeLayer(previewBranchLine);
        previewBranchLine = null;
        if (snapTarget) document.getElementById('cad-command').textContent = `SNAP: ${snapTarget.label}. Klik postavlja prvu tačku trase.`;
        return;
    }
    const points = [activeBranch[activeBranch.length - 1], point];
    const type = document.getElementById('route-draw-type').value;
    const previewStyle = type === 'trench'
        ? { ...routeLineStyle('trench'), weight: 4, opacity: .65 }
        : { color: '#f59e0b', weight: 2, opacity: .75, dashArray: '4 7' };
    if (previewBranchLine) {
        previewBranchLine.setLatLngs(points);
        previewBranchLine.setStyle(previewStyle);
    } else {
        previewBranchLine = L.polyline(points, previewStyle).addTo(map);
    }
    if (snapTarget) document.getElementById('cad-command').textContent = `SNAP: ${snapTarget.label}. Klik potvrduje tacku, ENTER/desni klik zavrsava krak.`;
}
async function fetchOsmRoute(from, to) {
    const url = `https://router.project-osrm.org/route/v1/foot/${from.lng.toFixed(6)},${from.lat.toFixed(6)};${to.lng.toFixed(6)},${to.lat.toFixed(6)}?geometries=geojson&overview=full`;
    const response = await fetch(url, { signal: AbortSignal.timeout(8000) });
    if (!response.ok) throw new Error('OSRM error');
    const data = await response.json();
    if (data.code !== 'Ok' || !data.routes?.[0]?.geometry?.coordinates?.length) throw new Error('No route');
    return data.routes[0].geometry.coordinates; // [[lng, lat], ...]
}

async function addDrawPoint(latlng) {
    if (osmRoutingLoading) return;
    if (previewBranchLine) map.removeLayer(previewBranchLine);
    previewBranchLine = null;
    const orthoPoint = applyOrthoPoint(latlng);
    const snapTarget = getSnapTarget(orthoPoint);
    const point = snapTarget?.latlng || orthoPoint;
    hideSnapIndicator();

    let intermediates = [];
    if (osmRoutingEnabled && activeBranch.length > 0) {
        osmRoutingLoading = true;
        updateCommandBar();
        document.getElementById('cad-command').textContent = 'Tražim rutu po ulicama...';
        const from = activeBranch[activeBranch.length - 1];
        try {
            const coords = await fetchOsmRoute(from, point);
            intermediates = coords.slice(1, -1).map(c => L.latLng(c[1], c[0]));
        } catch (e) {
            // fallback to straight line
        }
        osmRoutingLoading = false;
        updateCommandBar();
    }

    for (const ip of intermediates) {
        activeBranch.push(ip);
        activeBranchSnapTargets.push(null);
    }
    const totalAdded = intermediates.length + 1;

    activeBranch.push(point);
    activeBranchSnapTargets.push(snapTarget || null);
    const index = activeBranch.length - 1;
    const marker = L.marker(point, { draggable: true, icon: L.divIcon({ className: 'ftth-label', html: '<div style="width:8px;height:8px;background:#f59e0b;border:2px solid #fff;box-shadow:0 0 0 1px #0f172a"></div>', iconAnchor: [4, 4] }) }).addTo(map);
    marker.on('drag', event => {
        activeBranch[index] = event.target.getLatLng();
        redrawActiveBranch();
    });
    activeBranchMarkers.push(marker);
    pushUndo({
        undo: () => {
            const removedMarker = activeBranchMarkers.pop();
            if (removedMarker) map.removeLayer(removedMarker);
            activeBranch.splice(activeBranch.length - totalAdded, totalAdded);
            activeBranchSnapTargets.splice(activeBranchSnapTargets.length - totalAdded, totalAdded);
            redrawActiveBranch();
        },
        redo: () => {
            for (const ip of intermediates) {
                activeBranch.push(ip);
                activeBranchSnapTargets.push(null);
            }
            activeBranch.push(point);
            activeBranchSnapTargets.push(snapTarget || null);
            activeBranchMarkers.push(marker.addTo(map));
            redrawActiveBranch();
        },
    });
    redrawActiveBranch();
    document.getElementById('cad-command').textContent = `TRASA: tacka ${activeBranch.length}${snapTarget ? ` spojena na ${snapTarget.label}` : ''}. Sljedeci klik nastavlja, ENTER/desni klik zavrsava krak.`;
}
function finishBranch() {
    const finishQuickBranch = quickBranchWorkflow;
    if (activeBranch.length > 1) {
        const meta = currentRouteDraftMeta();
        const target = activeBranchSnapTargets[activeBranchSnapTargets.length - 1];
        if (target?.type === 'cabinet' && Number(target.id) !== Number(meta.from_id)) {
            meta.to_type = 'cabinet';
            meta.to_id = Number(target.id);
            meta.cabinet_id = Number(target.id);
        }
        const meters = distance(activeBranch);
        branches.push([...activeBranch]);
        branchMeta.push({
            ...meta,
            duct_length_m: meters,
            fiber_length_m: (meta.route_type || 'distribution') === 'trench' ? 0 : meters,
            path: activeBranch.map(p => [Number(p.lat.toFixed(7)), Number(p.lng.toFixed(7))]),
        });
        const odfLabel = meta.odf_index === null || meta.odf_index === undefined ? 'bez ODF' : `ODF-${String(meta.odf_index + 1).padStart(2, '0')}`;
        const line = trackLayer(L.polyline(activeBranch, routeLineStyle(meta.route_type)).bindPopup(`<b>${meta.name}</b><br>${routeTypeLabel(meta.route_type)}<br>${odfLabel}<br>${meters} m`).addTo(map), routeLayerType(meta.route_type));
        if (meta.route_type === 'trench') line.bringToBack();
        branchLines.push(line);
        registerBranchContext(line);
        if (meta.route_type !== 'trench') addRouteLabel(activeBranch, meta.name);
        document.getElementById('route-draw-name').value = '';
        renderBranchList();
        document.getElementById('cad-command').textContent = `TRASA: ${meta.name} zavrsena (${meters} m). Nastavi novi krak ili promijeni alat.`;
    }
    activeBranchMarkers.forEach(m => map.removeLayer(m));
    if (activeBranchLine) map.removeLayer(activeBranchLine);
    if (previewBranchLine) map.removeLayer(previewBranchLine);
    hideSnapIndicator();
    activeBranch = []; activeBranchSnapTargets = []; activeBranchMarkers = []; activeBranchLine = null; previewBranchLine = null; refreshStats();
    if (finishQuickBranch) {
        quickBranchWorkflow = false;
        document.getElementById('route-start-source').value = '';
        setMode('pan');
    }
}
function clearDraw() { [...branchLines, ...branchLabels, ...activeBranchMarkers].forEach(l => map.removeLayer(l)); if (activeBranchLine) map.removeLayer(activeBranchLine); if (previewBranchLine) map.removeLayer(previewBranchLine); hideSnapIndicator(); branches=[]; branchLines=[]; branchLabels=[]; branchLabelGroups=[]; branchMeta=[]; activeBranch=[]; activeBranchSnapTargets=[]; activeBranchMarkers=[]; activeBranchLine=null; previewBranchLine=null; renderBranchList(); refreshStats(); }
function undoDraw() { const m = activeBranchMarkers.pop(); if (m) map.removeLayer(m); activeBranch.pop(); activeBranchSnapTargets.pop(); redrawActiveBranch(); }
function undoBranch() {
    const line = branchLines.pop();
    if (line) map.removeLayer(line);
    const labels = branchLabelGroups.pop() || [];
    labels.forEach(label => {
        if (label) map.removeLayer(label);
        branchLabels = branchLabels.filter(item => item !== label);
    });
    branches.pop();
    branchMeta.pop();
    renderBranchList();
    refreshStats();
    syncRoutePathInput();
}

function layerPixelDistance(a, b) {
    return map.latLngToLayerPoint(a).distanceTo(map.latLngToLayerPoint(b));
}
function getSnapTarget(latlng) {
    // 1. ODF / ODO / kuća — apsolutni prioritet
    const primaryCandidates = [
        ...data.odfs.map(item => ({ latlng: odfMarkerById[item.id]?.getLatLng() || L.latLng(item.lat, item.lng), label: item.name, type: 'odf', id: item.id })),
        ...data.cabinets.map(item => ({ latlng: cabinetMarkerById[item.id]?.getLatLng() || L.latLng(item.lat, item.lng), label: item.name, type: 'cabinet', id: item.id })),
        ...data.houses.map(item => ({ latlng: houseMarkerByKey[pointKey(item.lat, item.lng)]?.getLatLng() || L.latLng(item.lat, item.lng), label: `Kuća ${item.label}`, type: 'house' })),
        ...draftOdfs.map((item, index) => ({ latlng: item.marker.getLatLng(), label: item.name || `ODF-${String(index + 1).padStart(2, '0')}`, type: 'odf' })),
        ...draftCabinets.map((item, index) => ({ latlng: item.marker.getLatLng(), label: item.name || draftCabinetName(index), type: 'cabinet' })),
        ...houseMarkers.map((marker, index) => ({ latlng: marker.getLatLng(), label: `Kuća ${index + 1}`, type: 'house' })),
    ];
    let best = null;
    primaryCandidates.forEach(c => {
        const d = layerPixelDistance(latlng, c.latlng);
        if (!best || d < best.distance) best = { ...c, distance: d };
    });
    if (best && best.distance <= snapPixelTolerance) return best;

    // 2. Vertexe i segmente sačuvanih trase
    let bestSec = null;
    [...savedRoutePoints, ...branches].forEach(route => {
        // vertexe
        route.forEach((vertex, index) => {
            const d = layerPixelDistance(latlng, vertex);
            const label = index === 0 ? 'Početak trase' : (index === route.length - 1 ? 'Kraj trase' : 'Čvor trase');
            if (!bestSec || d < bestSec.distance) bestSec = { latlng: vertex, label, type: 'vertex', distance: d };
        });
        // projekcija na segmente
        for (let i = 0; i < route.length - 1; i++) {
            const proj = projectOnSegment(latlng, route[i], route[i + 1]);
            const d = layerPixelDistance(latlng, proj);
            if (!bestSec || d < bestSec.distance) bestSec = { latlng: proj, label: 'Na trasi', type: 'segment', distance: d };
        }
    });
    return bestSec && bestSec.distance <= snapPixelTolerance ? bestSec : null;
}

function showSnapIndicator(target) {
    if (!target) { hideSnapIndicator(); return; }
    const color = (target.type === 'odf' || target.type === 'cabinet') ? '#22c55e'
                : target.type === 'house' ? '#8b5cf6'
                : '#f59e0b';
    const html = `<div class="snap-wrap" style="--sc:${color}">` +
        `<div class="snap-ring"></div>` +
        `<div class="snap-dot"></div>` +
        `<div class="snap-lbl">${target.label}</div>` +
        `</div>`;
    const icon = L.divIcon({ className: '', html, iconSize: [0, 0], iconAnchor: [0, 0] });
    if (!snapIndicator) {
        snapIndicator = L.marker(target.latlng, { icon, interactive: false, zIndexOffset: 2000 }).addTo(map);
    } else {
        snapIndicator.setLatLng(target.latlng);
        snapIndicator.setIcon(icon);
        if (!map.hasLayer(snapIndicator)) snapIndicator.addTo(map);
    }
    map.getContainer().classList.add('leaflet-snap-active');
}
function hideSnapIndicator() {
    if (snapIndicator && map.hasLayer(snapIndicator)) map.removeLayer(snapIndicator);
    map.getContainer().classList.remove('leaflet-snap-active');
}
function projectOnSegment(point, a, b) {
    const p = map.latLngToLayerPoint(point), pa = map.latLngToLayerPoint(a), pb = map.latLngToLayerPoint(b);
    const ab = pb.subtract(pa), ap = p.subtract(pa), den = ab.x*ab.x + ab.y*ab.y;
    const t = den ? Math.max(0, Math.min(1, (ap.x*ab.x + ap.y*ab.y) / den)) : 0;
    return map.layerPointToLatLng(L.point(pa.x + ab.x*t, pa.y + ab.y*t));
}
function nearestOnNetwork(point) {
    let best = null, bestDist = Infinity, chain = 0, passed = 0, branchIndex = -1, segmentIndex = -1;
    for (const [currentBranchIndex, branch] of allNetworkPoints().entries()) {
        for (let i = 1; i < branch.length; i++) {
            const projected = projectOnSegment(point, branch[i-1], branch[i]);
            const dist = map.distance(point, projected);
            if (dist < bestDist) {
                best = projected;
                bestDist = dist;
                chain = passed + map.distance(branch[i-1], projected);
                branchIndex = currentBranchIndex;
                segmentIndex = i;
            }
            passed += map.distance(branch[i-1], branch[i]);
        }
    }
    return { point: best, chain, branchIndex, segmentIndex };
}

function networkPathBetween(aPoint, bPoint) {
    const a = nearestOnNetwork(aPoint);
    const b = nearestOnNetwork(bPoint);
    if (!a.point || !b.point) return [aPoint, bPoint];
    const branch = allNetworkPoints()[a.branchIndex];
    if (!branch || a.branchIndex !== b.branchIndex) return [aPoint, a.point, b.point, bPoint];
    const middle = [];
    if (a.segmentIndex <= b.segmentIndex) {
        for (let i = a.segmentIndex; i < b.segmentIndex; i++) middle.push(branch[i]);
        return [aPoint, a.point, ...middle, b.point, bPoint];
    }
    for (let i = a.segmentIndex - 1; i >= b.segmentIndex; i--) middle.push(branch[i]);
    return [aPoint, a.point, ...middle, b.point, bPoint];
}

function networkDropDistance(cabinetPoint, housePoint) {
    const path = networkPathBetween(cabinetPoint, housePoint);
    return path.reduce((sum, point, index) => index ? sum + map.distance(path[index - 1], point) : 0, 0);
}
function snapCabinetToRoute(point) {
    const snapped = nearestOnNetwork(point);
    return snapped.point || point;
}
function nearestOdf(point) {
    return data.odfs.map(o => ({...o, distance: Math.round(map.distance(point, L.latLng(o.lat, o.lng)))})).sort((a,b) => a.distance-b.distance)[0] || null;
}
function suggestionOdfLabel(draftOdf, odf) {
    if (draftOdf) return `ODF-${String(draftOdf.index + 1).padStart(2, '0')} (draft)`;
    if (odf) return `${odf.name} (${odf.distance} m)`;
    return 'nema';
}
function optimize(points) {
    const min = Math.max(1, Math.min(12, Number(document.getElementById('planner-min').value || 8)));
    const max = Math.max(min, Math.min(12, Number(document.getElementById('planner-max').value || 12)));
    const drop = 1;
    const houses = points.map(p => ({ point: p, chain: nearestOnNetwork(p).chain })).sort((a,b) => a.chain-b.chain);
    const dp = Array(houses.length + 1).fill(Infinity), prev = Array(houses.length + 1).fill(null); dp[0]=0;
    for (let i=0;i<houses.length;i++) for (let s=1;s<=max && i+s<=houses.length;s++) {
        const group = houses.slice(i,i+s), center = L.latLng(group.reduce((a,h)=>a+h.point.lat,0)/s, group.reduce((a,h)=>a+h.point.lng,0)/s);
        const pos = nearestOnNetwork(center).point, splitters = Math.ceil(s/4), waste = splitters*4-s;
        const totalDrop = group.reduce((a,h)=>a+map.distance(h.point,pos),0);
        const cost = 900 + splitters*160 + waste*90 + (s<min ? (min-s)*300 : 0) + totalDrop*drop;
        if (dp[i]+cost < dp[i+s]) { dp[i+s]=dp[i]+cost; prev[i+s]={i,s,pos,splitters,waste,totalDrop,group}; }
    }
    const groups=[]; for(let c=houses.length;c>0 && prev[c];c=prev[c].i) groups.unshift(prev[c]); return groups;
}

function assignHousesToNearestCabinets(groups, points) {
    const max = Math.max(1, Math.min(12, Number(document.getElementById('planner-max').value || 12)));
    const maxDrop = Math.max(20, Number(document.getElementById('planner-max-drop').value || 90));
    const cabinets = groups.map(group => ({
        ...group,
        group: [],
        totalDrop: 0,
        generated: false,
    }));

    const houses = points.map((point, index) => ({ point, index }));
    const pairs = [];
    houses.forEach(house => {
        cabinets.forEach((cabinet, cabinetIndex) => {
            pairs.push({
                house,
                cabinetIndex,
                distance: networkDropDistance(cabinet.pos, house.point),
            });
        });
    });
    pairs.sort((a, b) => a.distance - b.distance);

    const assignedHouses = new Set();
    pairs.forEach(pair => {
        if (assignedHouses.has(pair.house.index)) return;
        if (pair.distance > maxDrop) return;
        const cabinet = cabinets[pair.cabinetIndex];
        if (cabinet.group.length >= max) return;
        cabinet.group.push(pair.house);
        cabinet.totalDrop += pair.distance;
        assignedHouses.add(pair.house.index);
    });

    let unassigned = houses.filter(house => !assignedHouses.has(house.index));
    while (unassigned.length) {
        const seed = unassigned[0];
        const seedNetworkPoint = nearestOnNetwork(seed.point).point || seed.point;
        const localCabinet = {
            i: 0,
            pos: seedNetworkPoint,
            group: [],
            totalDrop: 0,
            generated: true,
        };

        unassigned
            .map(house => ({ house, distance: networkDropDistance(seedNetworkPoint, house.point) }))
            .filter(item => item.distance <= maxDrop)
            .sort((a, b) => a.distance - b.distance)
            .slice(0, max)
            .forEach(item => {
                localCabinet.group.push(item.house);
                localCabinet.totalDrop += item.distance;
                assignedHouses.add(item.house.index);
            });

        cabinets.push(localCabinet);
        unassigned = houses.filter(house => !assignedHouses.has(house.index));
    }

    return cabinets
        .filter(cabinet => cabinet.group.length)
        .map(cabinet => {
            const count = cabinet.group.length;
            const splitters = Math.ceil(count / 4);
            return {
                ...cabinet,
                s: count,
                splitters,
                waste: splitters * 4 - count,
            };
        });
}
function clearSuggestions() {
    suggestionLayers.forEach(l => map.removeLayer(l));
    suggestionLayers.forEach(l => {
        untrackLayer(l, 'preview');
        untrackLayer(l, 'drop');
    });
        Object.entries(houseMarkerByKey).forEach(([key, marker]) => marker.setIcon(icon('house', '', savedHouseColorByKey[key] || null)));
    suggestionLayers=[];
    suggestedCabinets=[];
    currentAutoPlan = null;
    document.getElementById('cabinet-count').textContent='0';
    document.getElementById('suggestion-output').innerHTML='Nacrtaj trasu i oznaci kuće.';
    document.getElementById('save-suggestions').classList.add('hidden');
    refreshPlanSummary();
}

async function suggest() {
    clearSuggestions();
    suggestedCabinets = [];
    currentAutoPlan = null;
    const projectId = document.getElementById('active-project-id').value;
    const output = document.getElementById('suggestion-output');
    if (!projectId) { output.innerHTML = '<b class="text-red-700">Odaberi projekat prije prijedloga rasporeda.</b>'; return; }
    const url = window.ftthMapConfig.endpoints.autoOdoPreviewBaseUrl.replace('__ID__', projectId);
    output.innerHTML = 'Racunam Auto ODO po krakovima...';
    try {
        if (housePoints.length > savedHouseCount || branches.length || draftOdfs.length) {
            output.innerHTML = 'Prvo snimam nacrt sa mape, zatim racunam Auto ODO...';
            await persistDraftPlanForAutoOdo();
        }
        const response = await fetch(url, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '',
                'Accept': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
            },
            body: JSON.stringify({
                max_houses_per_odo: Number(document.getElementById('planner-max').value || 12),
                preferred_fill_min: Number(document.getElementById('planner-min').value || 8),
                max_house_to_odo_m: Number(document.getElementById('planner-max-drop').value || 90),
            }),
        });
        const plan = await readJsonResponse(response, 'Auto ODO preview nije uspio.');
        if (!response.ok) throw new Error(plan.message || 'Auto ODO preview nije uspio.');
        currentAutoPlan = plan;
        renderAutoOdoPlan(plan);
    } catch (error) {
        output.innerHTML = `<b class="text-red-700">${error.message}</b>`;
    }
}
function renderAutoOdoPlan(plan) {
    const branchColor = index => cabinetPalette[(Math.max(Number(index) || 1, 1) - 1) % cabinetPalette.length];
    (plan.cabinets || []).forEach(cabinet => {
        const color = branchColor(cabinet.branch_index);
        const position = L.latLng(cabinet.proposed_latitude, cabinet.proposed_longitude);
        const marker = trackLayer(L.marker(position, { icon: icon('suggest', cabinet.confirmed_name || cabinet.name, color) })
            .bindTooltip(`${cabinet.confirmed_name || cabinet.name} · ${cabinet.house_count}/12`, { direction: 'top', offset: [0, -10] })
            .bindPopup(`<b>${cabinet.confirmed_name || cabinet.name}</b><br>Krak ${cabinet.branch_index}<br>${cabinet.house_count}/12 kuca<br>ODF: ${cabinet.nearest_odf_name || 'nema'}`)
            .addTo(map), 'preview');
        const dropLines = (cabinet.drop_preview || []).map(drop => {
            const path = drop.path?.length ? drop.path : [
                [drop.from.lat, drop.from.lng],
                [drop.to.lat, drop.to.lng],
            ];
            return trackLayer(L.polyline(path, { color, weight: 1.7, opacity: .75, dashArray: '5 7' }).addTo(map), 'drop');
        });
        (cabinet.houses || []).forEach(house => {
            const houseMarker = houseMarkerByKey[pointKey(house.latitude, house.longitude)];
            if (houseMarker) houseMarker.setIcon(icon('house', '', color));
        });
        suggestedCabinets.push({
            name: cabinet.confirmed_name || cabinet.name,
            lat: Number(cabinet.proposed_latitude),
            lng: Number(cabinet.proposed_longitude),
            splitter_count: cabinet.splitter_count,
            odf_id: cabinet.nearest_odf_id,
            branch_id: cabinet.branch_id,
            marker,
            dropLines,
            plan: cabinet,
            houseKeys: (cabinet.houses || []).map(house => pointKey(house.latitude, house.longitude)),
        });
        suggestionLayers.push(marker, ...dropLines);
    });

    (plan.unassigned_houses || []).forEach(house => {
        const marker = houseMarkerByKey[pointKey(house.latitude, house.longitude)];
        if (marker) marker.setIcon(icon('house', '', '#ef4444'));
    });

    const branchHtml = (plan.branches || []).map(branch =>
        `<div class="rounded bg-white p-2"><b>Krak ${branch.branch_index}</b> -> ${branch.house_count} kuca<br>${branch.odo_count} FTTH</div>`
    ).join('');
    const cabinetHtml = (plan.cabinets || []).map(cabinet =>
        `<div class="border-b border-zinc-200 py-2"><b>${cabinet.confirmed_name || cabinet.name}</b><br>Krak ${cabinet.branch_index}, ${cabinet.house_count}/12 kuca<br>${(cabinet.houses || []).map(house => `${house.label} -> ${house.chainage_m}m`).join('<br>')}</div>`
    ).join('');
    const unassigned = plan.summary?.unassigned_house_count || 0;
    document.getElementById('cabinet-count').textContent = suggestedCabinets.length;
    document.getElementById('suggestion-output').innerHTML = `
        <div class="mb-2 rounded-md bg-emerald-50 p-2 text-xs font-semibold text-emerald-800">Preview plana je spreman. Potvrdi raspored ili odbaci raspored.</div>
        <div class="mb-2 grid gap-2">${branchHtml}<div class="rounded bg-white p-2"><b>Unassigned</b> -> ${unassigned} kuca</div></div>
        ${cabinetHtml}
    `;
    document.getElementById('save-suggestions').classList.remove('hidden');

    refreshPlanSummary();
}
function nearestDraftOdf(point) {
    if (!draftOdfs.length) return null;
    return draftOdfs
        .map((odf, index) => ({ index, distance: Math.round(map.distance(point, odf.marker.getLatLng())) }))
        .sort((a,b) => a.distance - b.distance)[0];
}

function draftOdfCabinetCount(index) {
    return [...draftCabinets, ...suggestedCabinets].filter(cabinet => cabinet.odf_index === index).length;
}

function savedOdfsForActiveProject() {
    const projectId = document.getElementById('active-project-id').value;
    return data.odfs.filter(odf => !projectId || String(odf.project_id ?? '') === String(projectId) || data.projects?.find?.(project => String(project.id) === String(projectId) && project.name === odf.project));
}

function activeOdfPayload(point = null) {
    if (activeOdfSelection?.type === 'draft' && draftOdfs[activeOdfSelection.index]) {
        return { odf_index: activeOdfSelection.index, odf_id: null };
    }
    if (activeOdfSelection?.type === 'saved') {
        return { odf_index: null, odf_id: activeOdfSelection.id };
    }
    const nearest = point ? nearestDraftOdf(point) : null;
    return { odf_index: nearest?.index ?? null, odf_id: null };
}

function activeOdfLabel() {
    if (activeOdfSelection?.type === 'draft' && draftOdfs[activeOdfSelection.index]) {
        return draftOdfs[activeOdfSelection.index].name || defaultDraftName('odf', activeOdfSelection.index);
    }
    if (activeOdfSelection?.type === 'saved') {
        return data.odfs.find(odf => Number(odf.id) === Number(activeOdfSelection.id))?.name || 'ODF';
    }
    return null;
}

function defaultDraftName(type, index) {
    return type === 'odf' ? `ODF-${String(index + 1).padStart(2, '0')}` : draftCabinetName(index);
}
function selectDraftElement(type, item) {
    selectedDraftElement = { type, item };
    document.getElementById('element-editor').classList.remove('hidden');
    document.getElementById('element-editor-type').textContent = type === 'odf' ? 'ODF lokacija' : 'Draft FTTH ormarić';
    document.getElementById('element-editor-name').value = item.pending ? '' : item.name;
    document.getElementById('element-editor-status').textContent = item.pending
        ? 'Unos naziva je obavezan da bi ODF bio dodat.'
        : 'Upiši naziv i klikni Sačuvaj naziv.';
    document.getElementById('element-editor-name').focus();
}
function closeDraftElementEditor() {
    if (selectedDraftElement?.item.pending) map.removeLayer(selectedDraftElement.item.marker);
    selectedDraftElement = null;
    document.getElementById('element-editor').classList.add('hidden');
}
function saveSelectedDraftElementName() {
    if (!selectedDraftElement) return;
    const name = document.getElementById('element-editor-name').value.trim();
    if (!name) {
        document.getElementById('element-editor-status').textContent = 'Naziv je obavezan.';
        return;
    }
    selectedDraftElement.item.name = name;
    const wasPendingOdf = selectedDraftElement.item.pending && selectedDraftElement.type === 'odf';
    if (wasPendingOdf) {
        const item = selectedDraftElement.item;
        item.pending = false;
        item.marker.closePopup().bindPopup(`<b>ODF: ${name}</b>`);
        draftOdfCount++;
        draftElements.push({ type: 'odf', marker: item.marker });
        draftOdfs.push(item);
        item.marker.on('drag', () => { refreshDraftTooltips(); refreshPlanSummary(); });
        item.marker.on('click', () => { setActiveDraftOdf(draftOdfs.indexOf(item)); selectDraftElement('odf', item); });
        registerDraftContext(item.marker, item.name);
        setActiveDraftOdf(draftOdfs.length - 1);
    }
    if (selectedDraftElement.type === 'cabinet') selectedDraftElement.item.marker.setIcon(icon('cabinet', name));
    refreshDraftTooltips();
    refreshPlanSummary();
    document.getElementById('element-editor-status').textContent = selectedDraftElement.type === 'odf'
        ? `ODF "${name}" je sačuvan.`
        : `Naziv "${name}" je sačuvan.`;
    if (wasPendingOdf) {
        const savedItem = selectedDraftElement.item;
        setTimeout(() => {
            if (selectedDraftElement?.item === savedItem) closeDraftElementEditor();
        }, 450);
    }
}

function setActiveDraftOdf(index) {
    if (typeof index === 'string' && index.includes(':')) {
        const [type, value] = index.split(':');
        activeOdfSelection = type === 'saved' ? { type, id: Number(value) } : { type: 'draft', index: Number(value) };
    } else if (index === '' || index === null || index === undefined) {
        activeOdfSelection = null;
    } else {
        activeOdfSelection = { type: 'draft', index: Number(index) };
    }
    activeDraftOdfIndex = activeOdfSelection?.type === 'draft' ? activeOdfSelection.index : null;
    const value = activeOdfSelection ? `${activeOdfSelection.type}:${activeOdfSelection.type === 'saved' ? activeOdfSelection.id : activeOdfSelection.index}` : '';
    document.getElementById('active-odf-index').value = value;
    const label = activeOdfLabel();
    document.getElementById('odf-link-status').textContent = label ? `Novi FTTH ormarici se vezu na ${label}.` : 'Odaberi ODF prije redanja FTTH ormarica.';
    refreshDraftTooltips();
}
function renderDraftOdfPicker() {
    const select = document.getElementById('active-odf-index');
    const savedOdfs = savedOdfsForActiveProject();
    const savedOptions = savedOdfs.map(odf => `<option value="saved:${odf.id}">${odf.name} (postojeci ODF)</option>`);
    const draftOptions = draftOdfs.map((item, index) => `<option value="draft:${index}">${item.name || defaultDraftName('odf', index)} (${draftOdfCabinetCount(index)} FTTH)</option>`);
    select.innerHTML = [...savedOptions, ...draftOptions].length ? [...savedOptions, ...draftOptions].join('') : '<option value="">Prvo postavi ODF</option>';
    if (!activeOdfSelection && savedOdfs.length) activeOdfSelection = { type: 'saved', id: savedOdfs[0].id };
    if (!activeOdfSelection && draftOdfs.length) activeOdfSelection = { type: 'draft', index: draftOdfs.length - 1 };
    if (activeOdfSelection?.type === 'draft' && !draftOdfs[activeOdfSelection.index]) activeOdfSelection = null;
    activeDraftOdfIndex = activeOdfSelection?.type === 'draft' ? activeOdfSelection.index : null;
    const value = activeOdfSelection ? `${activeOdfSelection.type}:${activeOdfSelection.type === 'saved' ? activeOdfSelection.id : activeOdfSelection.index}` : '';
    select.value = value;
    const label = activeOdfLabel();
    document.getElementById('odf-link-status').textContent = label ? `Novi FTTH ormarici se vezu na ${label}.` : 'Postavi ODF, zatim postavljaj FTTH ormarice.';
    refreshRouteOdfStatus();
}
function refreshDraftTooltips() {
    draftOdfs.forEach((item, index) => {
        item.marker.bindTooltip(`${item.name} · ${draftOdfCabinetCount(index)} FTTH`, { direction: 'top', offset: [0, -10] });
    });
    draftCabinets.forEach(item => {
        const savedOdf = item.odf_id ? data.odfs.find(odf => Number(odf.id) === Number(item.odf_id)) : null;
        const label = savedOdf ? savedOdf.name : (item.odf_index === null || item.odf_index === undefined ? 'bez ODF' : `ODF-${String(item.odf_index + 1).padStart(2,'0')}`);
        item.marker.bindTooltip(`0/12 - ${label}`, { direction: 'top', offset: [0, -10] });
    });
    renderDraftOdfPicker();
}

function planPayload() {
    const odfs = draftOdfs.map((item, index) => {
        const p = item.marker.getLatLng();
        return { name: item.name || defaultDraftName('odf', index), lat: Number(p.lat.toFixed(7)), lng: Number(p.lng.toFixed(7)), fiber_capacity: 144 };
    });
    const manualCabinets = draftCabinets.map((item, index) => {
        const p = item.marker.getLatLng();
        return { name: item.name || defaultDraftName('cabinet', index), lat: Number(p.lat.toFixed(7)), lng: Number(p.lng.toFixed(7)), splitter_count: 3, odf_index: item.odf_index ?? null, odf_id: item.odf_id ?? null };
    });
    const suggestedCabinetPayload = suggestedCabinets.map(cabinet => ({
        name: cabinet.name,
        lat: cabinet.lat,
        lng: cabinet.lng,
        splitter_count: cabinet.splitter_count,
        odf_index: cabinet.odf_index ?? null,
        odf_id: cabinet.odf_id ?? null,
        houseKeys: cabinet.houseKeys || [],
    }));
    const cabinets = [...manualCabinets, ...suggestedCabinetPayload];
    const houses = housePoints.slice(savedHouseCount).map((p, index) => {
        const key = pointKey(p.lat, p.lng);
        const cabinetIndex = cabinets.findIndex(cabinet => (cabinet.houseKeys || []).includes(key));
        return {
            label: `K-${String(index+1).padStart(3,'0')}`,
            lat: Number(p.lat.toFixed(7)),
            lng: Number(p.lng.toFixed(7)),
            cabinet_index: cabinetIndex >= 0 ? cabinetIndex : null,
        };
    });
    const drawnRoutes = branches.map((branch, index) => {
        const meters = distance(branch);
        const meta = branchMeta[index] || {};
        return {
            name: meta.name || `Trasa ${index+1}`,
            route_type: meta.route_type || 'distribution',
            installation_type: meta.installation_type || 'underground',
            counts_as_trench: (meta.route_type || 'distribution') === 'trench',
            trench_length_m: null,
            microduct_type: (meta.route_type || 'distribution') === 'trench' ? null : (meta.microduct_type || '14/10'),
            fiber_count: (meta.route_type || 'distribution') === 'trench' ? 4 : (meta.fiber_count || 12),
            odf_index: meta.odf_index ?? null,
            duct_length_m: meters,
            fiber_length_m: (meta.route_type || 'distribution') === 'trench' ? 0 : meters,
            microduct_count: (meta.route_type || 'distribution') === 'trench' ? 0 : (meta.microduct_count || 1),
            path: branch.map(p => [Number(p.lat.toFixed(7)), Number(p.lng.toFixed(7))]),
            odf_id: meta.odf_id ?? null,
            from_type: meta.from_type ?? null,
            from_id: meta.from_id ?? null,
            to_type: meta.to_type ?? null,
            to_id: meta.to_id ?? null,
            cabinet_id: meta.cabinet_id ?? null,
        };
    });
    const routes = drawnRoutes;
    const appendix_items = draftAppendixItems.map(item => {
        const p = item.marker.getLatLng();
        return {
            type: item.type,
            lat: Number(p.lat.toFixed(7)),
            lng: Number(p.lng.toFixed(7)),
            quantity: item.type === 'boring_fi_130' ? Number((item.length_m || item.quantity || 1).toFixed(2)) : (item.quantity || 1),
            length_m: item.type === 'boring_fi_130' ? Number((item.length_m || item.quantity || 1).toFixed(2)) : null,
            angle_deg: item.type === 'boring_fi_130' ? Number(normalizeAngle(item.angle_deg || 0).toFixed(2)) : null,
            width_m: item.type === 'boring_fi_130' ? Number((item.width_m || 1.8).toFixed(2)) : null,
            note: item.note || '',
        };
    });
    return { odfs, cabinets, houses, routes, appendix_items };
}

function refreshPlanSummary() {
    const payload = planPayload();
    document.getElementById('bulk-plan-json').value = JSON.stringify(payload);
    const trenchCount = payload.routes.filter(route => route.route_type === 'trench').length;
    const boringMeters = payload.appendix_items
        .filter(item => item.type === 'boring_fi_130')
        .reduce((sum, item) => sum + Number(item.length_m || item.quantity || 0), 0);
    document.getElementById('bulk-plan-summary').textContent = `Draft: ${payload.odfs.length} ODF, ${payload.cabinets.length} FTTH, ${payload.houses.length} kuca, ${payload.routes.length} trasa (${trenchCount} glavni rov), ${payload.appendix_items.length} stavki, FI130 ${Math.round(boringMeters)} m.`;
    scheduleDraftAutosave();
}

async function persistDraftPlanForAutoOdo() {
    if (activeBranch.length > 1) finishBranch();
    refreshPlanSummary();
    const payload = planPayload();
    if (!payload.houses.length && !payload.routes.length && !payload.odfs.length && !payload.appendix_items.length) return false;

    const projectId = document.getElementById('active-project-id').value;
    const form = document.getElementById('bulk-plan-form');
    const body = new FormData();
    body.append('_token', form.querySelector('input[name="_token"]').value);
    body.append('project_id', projectId);
    body.append('plan', JSON.stringify(payload));

    const response = await fetch(form.action, {
        method: 'POST',
        headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
        body,
    });
    const result = await readJsonResponse(response, 'Nacrt nije snimljen prije Auto ODO planiranja.');
    if (!response.ok) throw new Error(result.message || 'Nacrt nije snimljen prije Auto ODO planiranja.');

    savedHouseCount += payload.houses.length;
    draftOdfs = [];
    draftCabinets = [];
    draftAppendixItems = [];
    draftElements = [];
    branches = [];
    branchLines = [];
    branchLabels = [];
    branchLabelGroups = [];
    branchMeta = [];
    draftsByProject[projectId] = null;
    document.getElementById('bulk-plan-status').textContent = `${result.message} Sada racunam Auto ODO.`;
    refreshPlanSummary();
    return true;
}

function draftPayload() {
    return {
        odfs: draftOdfs.map((item, index) => {
            const p = item.marker.getLatLng();
            return { name: item.name || defaultDraftName('odf', index), lat: Number(p.lat.toFixed(7)), lng: Number(p.lng.toFixed(7)) };
        }),
        cabinets: draftCabinets.map((item, index) => {
            const p = item.marker.getLatLng();
            return { name: item.name || defaultDraftName('cabinet', index), lat: Number(p.lat.toFixed(7)), lng: Number(p.lng.toFixed(7)), odf_index: item.odf_index ?? null, odf_id: item.odf_id ?? null };
        }),
        houses: housePoints.slice(savedHouseCount).map(p => [Number(p.lat.toFixed(7)), Number(p.lng.toFixed(7))]),
        branches: branches.map((branch, index) => ({
            path: branch.map(p => [Number(p.lat.toFixed(7)), Number(p.lng.toFixed(7))]),
            meta: branchMeta[index] || {},
        })),
        suggestedCabinets: suggestedCabinets.map(cabinet => ({
            name: cabinet.name,
            lat: cabinet.lat,
            lng: cabinet.lng,
            splitter_count: cabinet.splitter_count,
            odf_index: cabinet.odf_index ?? null,
            odf_id: cabinet.odf_id ?? null,
            houseKeys: cabinet.houseKeys || [],
        })),
        appendix_items: draftAppendixItems.map(item => {
            const p = item.marker.getLatLng();
            return {
                type: item.type,
                lat: Number(p.lat.toFixed(7)),
                lng: Number(p.lng.toFixed(7)),
                quantity: item.type === 'boring_fi_130' ? Number((item.length_m || item.quantity || 1).toFixed(2)) : (item.quantity || 1),
                length_m: item.type === 'boring_fi_130' ? Number((item.length_m || item.quantity || 1).toFixed(2)) : null,
                angle_deg: item.type === 'boring_fi_130' ? Number(normalizeAngle(item.angle_deg || 0).toFixed(2)) : null,
                width_m: item.type === 'boring_fi_130' ? Number((item.width_m || 1.8).toFixed(2)) : null,
                note: item.note || '',
            };
        }),
    };
}

function restoreDraft(payload) {
    if (!payload) return;
    restoringDraft = true;
    clearDraw();
    houseMarkers.forEach(marker => map.removeLayer(marker));
    houseMarkers = [];
    housePoints = data.houses.map(h => L.latLng(h.lat, h.lng));
    draftAppendixItems.forEach(removeAppendixDraftItem);
    draftElements.forEach(item => {
        if (!draftAppendixItems.some(appendixItem => appendixItem.marker === item.marker)) map.removeLayer(item.marker);
    });
    draftElements = [];
    draftOdfs = [];
    draftCabinets = [];
    draftAppendixItems = [];
    activeDraftOdfIndex = null;
    activeOdfSelection = null;
    clearSuggestions();

    (payload.branches || []).forEach((branch, index) => {
        const path = Array.isArray(branch) ? branch : (branch.path || []);
        const meta = Array.isArray(branch) ? { name: `Trasa ${index + 1}`, route_type: 'distribution', microduct_count: 1 } : (branch.meta || {});
        const points = path.map(point => L.latLng(point[0], point[1]));
        const meters = distance(points);
        const normalizedMeta = {
            name: meta.name || `Trasa ${index + 1}`,
            route_type: meta.route_type || 'distribution',
            microduct_count: (meta.route_type || 'distribution') === 'trench' ? 0 : (meta.microduct_count || 1),
            counts_as_trench: (meta.route_type || 'distribution') === 'trench',
            trench_length_m: null,
            odf_index: meta.odf_index ?? null,
            odf_id: meta.odf_id ?? null,
            from_type: meta.from_type ?? null,
            from_id: meta.from_id ?? null,
            duct_length_m: meters,
            fiber_length_m: normalizedMeta.route_type === 'trench' ? 0 : meters,
            path,
        };
        branches.push(points);
        branchMeta.push(normalizedMeta);
        const odfLabel = normalizedMeta.odf_index === null || normalizedMeta.odf_index === undefined ? 'bez ODF' : `ODF-${String(normalizedMeta.odf_index + 1).padStart(2, '0')}`;
        const line = trackLayer(L.polyline(points, routeLineStyle(normalizedMeta.route_type)).bindPopup(`<b>${normalizedMeta.name}</b><br>${routeTypeLabel(normalizedMeta.route_type)}<br>${odfLabel}<br>${meters} m`).addTo(map), routeLayerType(normalizedMeta.route_type));
        branchLines.push(line);
        registerBranchContext(line);
        if (normalizedMeta.route_type !== 'trench') addRouteLabel(points, normalizedMeta.name);
    });
    renderBranchList();

    let restoredHouseIndex = 0;
    (payload.houses || []).forEach((point) => {
        if (savedHouseKeys.has(pointKey(point[0], point[1]))) return;
        const latLng = L.latLng(Array.isArray(point) ? point[0] : point.lat, Array.isArray(point) ? point[1] : point.lng);
        const item = { marker: null, name: Array.isArray(point) ? defaultDraftName('odf', index) : (point.name || defaultDraftName('odf', index)) };
        const houseIndex = restoredHouseIndex++;
        housePoints.push(latLng);
        const marker = L.marker(latLng, { icon: icon('house'), draggable: true }).bindPopup(`Kuca ${houseIndex + 1}`).addTo(map);
        houseMarkerByKey[pointKey(latLng.lat, latLng.lng)] = marker;
        marker.on('drag', event => {
            const next = event.target.getLatLng();
            housePoints[savedHouseCount + houseIndex] = next;
            houseMarkerByKey[pointKey(next.lat, next.lng)] = marker;
            refreshStats();
        });
        registerHouseContext(marker);
        houseMarkers.push(marker);
    });

    (payload.odfs || []).forEach((point, index) => {
        const latLng = L.latLng(point[0], point[1]);
        const marker = L.marker(latLng, { icon: icon('odf', 'ODF'), draggable: true }).bindTooltip('ODF · 0 FTTH', { direction: 'top', offset: [0, -10] }).addTo(map);
        item.marker = marker;
        marker.on('drag', () => { refreshDraftTooltips(); refreshPlanSummary(); });
        marker.on('click', () => { setActiveDraftOdf(index); selectDraftElement('odf', item); });
        registerDraftContext(marker, `ODF-${String(index + 1).padStart(2, '0')}`);
        draftOdfs.push(item);
        draftElements.push({ type: 'odf', marker });
        draftOdfCount = Math.max(draftOdfCount, index + 1);
    });

    (payload.cabinets || []).forEach((point, index) => {
        const lat = Array.isArray(point) ? point[0] : point.lat;
        const lng = Array.isArray(point) ? point[1] : point.lng;
        const latLng = L.latLng(lat, lng);
        const item = { marker: null, name: Array.isArray(point) ? defaultDraftName('cabinet', index) : (point.name || defaultDraftName('cabinet', index)), odf_index: Array.isArray(point) ? (nearestDraftOdf(latLng)?.index ?? null) : (point.odf_index ?? null), odf_id: Array.isArray(point) ? null : (point.odf_id ?? null) };
        const marker = L.marker(latLng, { icon: icon('cabinet', item.name), draggable: true }).bindTooltip('0/12', { direction: 'top', offset: [0, -10] }).addTo(map);
        item.marker = marker;
        marker.on('drag', () => {
            refreshDraftTooltips();
            refreshPlanSummary();
        });
        marker.on('click', () => selectDraftElement('cabinet', item));
        registerDraftContext(marker, item.name);
        draftCabinets.push(item);
        draftElements.push({ type: 'cabinet', marker });
        draftCabinetCount = Math.max(draftCabinetCount, index + 1);
    });

    (payload.appendix_items || []).forEach(item => {
        const latLng = L.latLng(item.lat, item.lng);
        const isManhole = item.type === 'manhole';
        const draftItem = isManhole
            ? { type: item.type, quantity: item.quantity || 1, note: item.note || '', marker: null }
            : createBoringDraft(latLng, item);
        if (isManhole) {
            const marker = L.marker(latLng, { icon: icon('manhole', 'S'), draggable: true })
                .bindTooltip('Prolazni saht', { direction: 'top', offset: [0, -10] })
                .addTo(map);
            draftItem.marker = marker;
            marker.on('drag', refreshPlanSummary);
        }
        registerDraftContext(draftItem.marker, isManhole ? 'Prolazni saht' : 'Podbusivanje FI 130');
        draftAppendixItems.push(draftItem);
        draftElements.push({ type: item.type, marker: draftItem.marker });
    });

    refreshDraftTooltips();
    refreshStats();
    restoringDraft = false;
}

function scheduleDraftAutosave() {
    if (!autosaveReady || restoringDraft || !document.getElementById('active-project-id').value) return;
    clearTimeout(autosaveTimer);
    const status = document.getElementById('bulk-plan-status');
    status.textContent = 'Izmjena spremna za automatsko čuvanje...';
    autosaveTimer = setTimeout(() => saveDraft({ quiet: true }).catch(error => {
        status.textContent = error.message;
    }), 700);
}

async function saveDraft({ quiet = false } = {}) {
    const projectId = document.getElementById('active-project-id').value;
    const status = document.getElementById('bulk-plan-status');
    if (!projectId) {
        status.textContent = 'Odaberi projekat prije čuvanja nacrta.';
        return;
    }

    const body = new FormData();
    body.append('_token', document.querySelector('#bulk-plan-form input[name="_token"]').value);
    body.append('project_id', projectId);
    body.append('draft', JSON.stringify(draftPayload()));
    status.textContent = quiet ? 'Automatski čuvam nacrt...' : 'Cuvam nacrt...';

    const response = await fetch(appConfig.mapDraftStore, {
        method: 'POST',
        headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
        body,
    });

    if (!response.ok) {
        status.textContent = await response.text();
        return;
    }

    const result = await readJsonResponse(response, 'Nacrt nije sačuvan.');
    draftsByProject[projectId] = draftPayload();
    status.textContent = quiet ? `Nacrt automatski sačuvan (${result.updated_at})` : `${result.message} (${result.updated_at})`;
}

function commitTrenchLines() {
    allNetworkPoints().forEach(branch => {
        const trench = L.polyline(branch, routeLineStyle('trench')).bindPopup(`<b>Rov / spremljena trasa</b><br>${distance(branch)} m`).addTo(map);
        trenchLines.push(trench);
    });
}

document.getElementById('finish-branch').addEventListener('click', finishBranch);
document.getElementById('cancel-draw').addEventListener('click', cancelActiveDrawing);
document.getElementById('undo-draw').addEventListener('click', undoDraw);
document.getElementById('undo-branch').addEventListener('click', undoBranch);
document.getElementById('clear-draw').addEventListener('click', clearDraw);
document.getElementById('route-draw-type').addEventListener('change', event => {
    document.getElementById('route-draw-name').placeholder = `npr. ${nextRouteName(event.target.value)}`;
    document.getElementById('cad-command').textContent = `TRASA: aktivan tip ${routeTypeLabel(event.target.value)}. Klikni tačke na mapi.`;
    refreshTrenchGroupStatus();
});
document.getElementById('undo-house').addEventListener('click', () => { const m = houseMarkers.pop(); if(m) map.removeLayer(m); housePoints.pop(); refreshStats(); });
document.getElementById('undo-element').addEventListener('click', () => {
    const item = draftElements.pop();
    if (!item) return;
    if (selectedDraftElement?.item.marker === item.marker) closeDraftElementEditor();
    const appendixItem = draftAppendixItems.find(entry => entry.marker === item.marker);
    if (appendixItem) removeAppendixDraftItem(appendixItem);
    else map.removeLayer(item.marker);
    if (item.type === 'odf') {
        const removedIndex = draftOdfs.findIndex(entry => entry.marker === item.marker);
        draftOdfs = draftOdfs.filter(entry => entry.marker !== item.marker);
        draftCabinets.forEach(cabinet => {
            if (cabinet.odf_index === removedIndex) cabinet.odf_index = null;
            if (cabinet.odf_index > removedIndex) cabinet.odf_index--;
        });
        activeDraftOdfIndex = draftOdfs.length ? Math.min(activeDraftOdfIndex ?? 0, draftOdfs.length - 1) : null;
    }
    if (item.type === 'cabinet') draftCabinets = draftCabinets.filter(entry => entry.marker !== item.marker);
    if (item.type === 'manhole' || item.type === 'boring_fi_130') {
        draftAppendixItems = draftAppendixItems.filter(entry => entry.marker !== item.marker);
    }
    refreshDraftTooltips();
    refreshPlanSummary();
});
document.getElementById('suggest-cabinets').addEventListener('click', suggest);
document.getElementById('clear-suggestions').addEventListener('click', clearSuggestions);
document.getElementById('active-odf-index').addEventListener('change', event => setActiveDraftOdf(event.target.value));
document.querySelectorAll('[data-guide-mode]').forEach(button => {
    button.addEventListener('click', () => setMode(button.dataset.guideMode));
});
document.getElementById('guide-suggest').addEventListener('click', suggest);

async function saveSuggestions() {
    const projectId = document.getElementById('active-project-id').value;
    const output = document.getElementById('suggestion-output');
    if (!projectId) { output.innerHTML = '<b class="text-red-700">Odaberi projekat prije potvrde rasporeda.</b>'; return; }
    if (!currentAutoPlan || !suggestedCabinets.length) { output.innerHTML = '<b class="text-red-700">Nema backend plana za potvrdu.</b>'; return; }
    const btn = document.getElementById('save-suggestions');
    const originalText = btn.textContent;
    btn.disabled = true;
    btn.textContent = 'Snimam...';
    try {
        const url = window.ftthMapConfig.endpoints.autoOdoConfirmBaseUrl.replace('__ID__', projectId);
        const response = await fetch(url, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '',
                'Accept': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
            },
            body: JSON.stringify({ plan: currentAutoPlan, create_drop_routes: false }),
        });
        const result = await readJsonResponse(response, 'FTTH raspored nije snimljen.');
        if (!response.ok) throw new Error(result.message || 'FTTH raspored nije snimljen.');
        output.innerHTML = `<b class="text-emerald-700">${result.message} Povezano kuca: ${result.linked_houses}.</b>`;
        keepSavedSuggestionsOnMap();
    } catch (error) {
        output.innerHTML = `<b class="text-red-700">Greska: ${error.message}</b>`;
    } finally {
        btn.disabled = false;
        btn.textContent = originalText;
    }
}
function keepSavedSuggestionsOnMap() {
    suggestionLayers.forEach(layer => map.removeLayer(layer));
    suggestionLayers = [];
    suggestedCabinets = [];
    currentAutoPlan = null;
    document.getElementById('save-suggestions').classList.add('hidden');
    document.getElementById('suggestion-output').innerHTML = '<b class="text-emerald-700">FTTH ormarici su snimljeni. Osvjezavam mapu...</b>';
    refreshPlanSummary();
    window.setTimeout(() => window.location.reload(), 500);
}

document.getElementById('save-suggestions').addEventListener('click', saveSuggestions);
document.getElementById('clear-map-trace')?.addEventListener('click', clearFiberTrace);
document.querySelectorAll('[data-layer-toggle]').forEach(input => {
    input.addEventListener('change', () => applyLayerVisibility(input.dataset.layerToggle));
});
document.querySelectorAll('[data-layer-lock]').forEach(button => {
    button.addEventListener('click', () => {
        const type = button.dataset.layerLock;
        layerLocks[type] = !layerLocks[type];
        button.textContent = layerLocks[type] ? 'Zaključan' : 'Otključan';
        button.classList.toggle('border-red-200', layerLocks[type]);
        button.classList.toggle('bg-red-50', layerLocks[type]);
        button.classList.toggle('text-red-700', layerLocks[type]);
        document.getElementById('cad-command').textContent = `Layer ${type}: ${layerLocks[type] ? 'zaključan' : 'otključan'}.`;
    });
});
Object.keys(layerRegistry).forEach(updateLayerCount);


document.getElementById('quick-project-form').addEventListener('submit', async event => {
    event.preventDefault();
    const form = event.currentTarget;
    const status = document.getElementById('quick-project-status');
    status.textContent = 'Kreiram projekat...';
    try {
        const response = await fetch(appConfig.projectsStore, {
            method: 'POST',
            headers: {
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '',
                'Accept': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
            },
            body: new FormData(form),
        });
        const result = await readJsonResponse(response, 'Projekat nije kreiran. Provjeri podatke.');
        status.textContent = `${result.project.name} je kreiran. Učitavam čistu mapu...`;
        const url = new URL(window.location.href);
        url.searchParams.set('project', result.project.id);
        window.location.href = url.toString();
    } catch (error) {
        status.textContent = error.message;
    }
});
document.getElementById('save-draft').addEventListener('click', () => saveDraft().catch(error => {
    document.getElementById('bulk-plan-status').textContent = error.message;
}));
document.getElementById('quick-save-draft').addEventListener('click', () => saveDraft().catch(error => {
    document.getElementById('bulk-plan-status').textContent = error.message;
}));
document.getElementById('save-element-name').addEventListener('click', saveSelectedDraftElementName);
document.getElementById('close-element-editor').addEventListener('click', closeDraftElementEditor);
document.getElementById('element-editor-name').addEventListener('keydown', event => {
    if (event.key === 'Enter') {
        event.preventDefault();
        saveSelectedDraftElementName();
    }
});
document.getElementById('element-editor-name').addEventListener('blur', saveSelectedDraftElementName);
renderBranchList();
document.getElementById('route-draw-name').placeholder = `npr. ${nextRouteName(document.getElementById('route-draw-type').value)}`;
document.getElementById('bulk-plan-form').addEventListener('submit', async event => {
    event.preventDefault();
    const form = event.currentTarget;
    const status = document.getElementById('bulk-plan-status');
    if (activeBranch.length > 1) finishBranch();
    refreshPlanSummary();
    try {
        await saveDraft();
        status.textContent = 'Snimam plan...';
        const response = await fetch(form.action, {
            method: 'POST',
            headers: {
                'Accept': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
            },
            body: new FormData(form),
        });

        if (!response.ok) {
            const errorText = await response.text();
            throw new Error(errorText || 'Plan nije snimljen. Provjeri podatke.');
        }

        const result = await readJsonResponse(response, 'Plan nije snimljen. Provjeri podatke.');
        delete draftsByProject[form.elements.project_id.value];
        status.textContent = `${result.message} Osvjezavam trajno spremljenu mapu...`;
        window.location.reload();
    } catch (error) {
        status.textContent = error.message;
    }
});
function applyMapViewMode() {
    const workspace = document.getElementById('map-workspace');
    const btn = document.getElementById('toggle-map-view');
    workspace.classList.toggle('gis-view', mapViewMode === 'gis');
    workspace.classList.toggle('cad-dark', mapViewMode === 'dark');
    const viewLabels = { cad: 'GIS prikaz', gis: 'Tamni CAD', dark: 'Satelit' };
    if (btn) btn.textContent = viewLabels[mapViewMode] || 'GIS prikaz';
    [imagery, osm, cartodbDark].forEach(layer => { if (map.hasLayer(layer)) map.removeLayer(layer); });
    if (mapViewMode === 'dark') cartodbDark.addTo(map);
    else if (mapViewMode === 'gis') osm.addTo(map);
    else imagery.addTo(map);
    data.routes.forEach(route => {
        const line = routeLayerById[route.id];
        if (line?.setStyle) line.setStyle(routeLineStyle(route.type, routeLineColor(route)));
    });
    branchLines.forEach((line, index) => {
        const meta = branchMeta[index] || {};
        if (line?.setStyle) line.setStyle(routeLineStyle(meta.route_type || 'distribution', colorByFibers && meta.fiber_count ? fiberCountColor(meta.fiber_count) : undefined));
    });
    if (activeBranchLine) redrawActiveBranch();
}
function applyMapZoomClass() {
    const workspace = document.getElementById('map-workspace');
    const zoom = map.getZoom();
    workspace.classList.toggle('zoom-high', zoom >= 19);
    workspace.classList.toggle('zoom-low', zoom <= 16);
    workspace.classList.toggle('zoom-far', zoom <= 15);
    ['z20','z21','z22'].forEach(c => workspace.classList.remove(c));
    if (zoom >= 20) workspace.classList.add('z' + Math.min(Math.round(zoom), 22));
}
document.getElementById('toggle-map-view').addEventListener('click', () => {
    const cycle = { cad: 'gis', gis: 'dark', dark: 'cad' };
    mapViewMode = cycle[mapViewMode] || 'gis';
    applyMapViewMode();
    document.getElementById('cad-command').textContent = `VIEW: ${mapViewMode.toUpperCase()} prikaz aktivan.`;
});
document.getElementById('toggle-color-by-fibers').addEventListener('click', () => {
    colorByFibers = !colorByFibers;
    const btn = document.getElementById('toggle-color-by-fibers');
    btn.classList.toggle('bg-amber-100', colorByFibers);
    btn.classList.toggle('border-amber-400', colorByFibers);
    btn.classList.toggle('text-amber-900', colorByFibers);
    refreshAllRouteStyles();
    document.getElementById('cad-command').textContent = colorByFibers
        ? 'BOJA F: trase obojene po broju vlakana (4F=žuta, 12F=zelena, 24F=plava, 48F=narandžasta, 96F+=crvena).'
        : 'BOJA F: isključeno, boja po tipu/grani.';
});
document.getElementById('toggle-cable-specs').addEventListener('click', () => {
    showCableSpecs = !showCableSpecs;
    const btn = document.getElementById('toggle-cable-specs');
    btn.classList.toggle('bg-sky-100', showCableSpecs);
    btn.classList.toggle('border-sky-400', showCableSpecs);
    btn.classList.toggle('text-sky-900', showCableSpecs);
    refreshAllRouteLabels();
    document.getElementById('cad-command').textContent = showCableSpecs ? 'SPECS: prikaz vlakana i mikrocijevi na trasama uključen.' : 'SPECS: isključen.';
});
document.getElementById('btn-coord-jump').addEventListener('click', () => {
    const raw = prompt('Unesi koordinate (lat, lng):');
    if (!raw) return;
    const parts = raw.split(/[\s,;]+/).map(Number).filter(v => !isNaN(v));
    if (parts.length < 2) { document.getElementById('cad-command').textContent = 'GOTO: neispravan format. Primjer: 44.449, 18.650'; return; }
    map.setView([parts[0], parts[1]], Math.max(map.getZoom(), 18));
    document.getElementById('cad-command').textContent = `GOTO: LAT ${parts[0].toFixed(5)}, LNG ${parts[1].toFixed(5)}`;
});
applyMapViewMode();
applyMapZoomClass();
map.on('zoomend', applyMapZoomClass);
document.getElementById('expand-map').addEventListener('click', () => {
    expandedMap = !expandedMap;
    const workspace = document.getElementById('map-workspace');
    const sidebar = workspace.querySelector('aside');
    const btn = document.getElementById('expand-map');
    if (expandedMap) {
        workspace.className = 'grid flex-1 gap-2';
        sidebar.classList.add('hidden');
        btn.textContent = 'Prikazi panele';
    } else {
        workspace.className = 'grid flex-1 gap-2 xl:grid-cols-[minmax(0,1fr)_330px]';
        sidebar.classList.remove('hidden');
        btn.textContent = 'Velika mapa';
    }
    applyMapViewMode();
    setTimeout(() => map.invalidateSize(), 100);
});
document.getElementById('add-route-vertex').addEventListener('click', () => addRouteEditVertex());
document.getElementById('cancel-route-edit').addEventListener('click', cancelRouteEdit);
document.getElementById('undo-route-edit')?.addEventListener('click', undoRouteEdit);
document.getElementById('redo-route-edit')?.addEventListener('click', redoRouteEdit);
document.getElementById('btn-map-undo')?.addEventListener('click', () => mapHistory.undo());
document.getElementById('btn-map-redo')?.addEventListener('click', () => mapHistory.redo());
document.getElementById('save-route-edit').addEventListener('click', async () => {
    try {
        await saveRouteEdit();
    } catch (error) {
        document.getElementById('cad-command').textContent = error.message;
    }
});
document.getElementById('close-route-attribute-panel').addEventListener('click', closeRouteAttributePanel);
document.getElementById('save-route-attributes').addEventListener('click', async () => {
    try {
        await saveRouteAttributes();
    } catch (error) {
        document.getElementById('route-attribute-status').textContent = error.message;
        document.getElementById('cad-command').textContent = error.message;
    }
});
document.getElementById('finish-house-connect').addEventListener('click', async () => {
    try { await finishHouseConnect(); } catch (error) { document.getElementById('cad-command').textContent = error.message; }
});
document.getElementById('cancel-house-connect').addEventListener('click', () => {
    resetHouseConnect();
    document.getElementById('cad-command').textContent = 'CONNECT HOUSES: povezivanje otkazano.';
});

async function runProjectCheck() {
    const projectId = document.getElementById('active-project-id').value;
    const panel = document.getElementById('project-check-panel');
    const summary = document.getElementById('project-check-summary');
    if (!projectId) {
        summary.textContent = 'Prvo odaberi projekat.';
        panel.innerHTML = '';
        return;
    }

    summary.textContent = 'Provjeravam projekat...';
    panel.innerHTML = '';
    const response = await fetch(appConfig.projectValidationBaseUrl.replace('__ID__', projectId), {
        headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
    });
    const result = await readJsonResponse(response, 'Provjera projekta nije uspjela.');
    const items = result.items || [];
    const problems = items.filter(item => item.level !== 'ok');
    const counts = {
        error: problems.filter(item => item.level === 'error').length,
        warning: problems.filter(item => item.level === 'warning').length,
        info: problems.filter(item => item.level === 'info').length,
    };
    summary.textContent = problems.length
        ? `${counts.error} gresaka, ${counts.warning} upozorenja, ${counts.info} info stavki`
        : 'Projekat nema otvorenih problema.';
    panel.innerHTML = items.map((item, index) => projectCheckItemHtml(item, index)).join('');
    highlightValidationItems(items);
    panel.querySelectorAll('[data-check-index]').forEach(button => {
        button.addEventListener('click', () => focusValidationItem(items[Number(button.dataset.checkIndex)]));
    });
}

async function fillMissingDropRoutes() {
    const projectId = document.getElementById('active-project-id').value;
    const summary = document.getElementById('project-check-summary');
    if (!projectId) {
        summary.textContent = 'Prvo odaberi projekat.';
        return;
    }

    summary.textContent = 'Popunjavam nedostajuce drop trase...';
    const response = await fetch(appConfig.projectDropFillBaseUrl.replace('__ID__', projectId), {
        method: 'POST',
        headers: {
            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '',
            'Accept': 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
        },
    });
    const result = await readJsonResponse(response, 'Drop trase nisu kreirane.');
    (result.routes || []).forEach(route => addSavedRouteToMap({ ...route, type: 'drop', length: route.duct_length_m ?? route.length }));
    summary.textContent = result.created
        ? `Kreirano ${result.created} nedostajucih drop trasa.`
        : 'Nema nedostajucih drop trasa za kuce koje imaju ODO.';
    await runProjectCheck();
}

function projectCheckItemHtml(item, index) {
    const color = item.level === 'error'
        ? 'border-red-300 bg-red-50 text-red-900'
        : item.level === 'warning'
            ? 'border-amber-300 bg-amber-50 text-amber-950'
            : item.level === 'ok'
                ? 'border-emerald-300 bg-emerald-50 text-emerald-900'
                : 'border-slate-200 bg-slate-50 text-slate-800';

    return `<button type="button" data-check-index="${index}" class="grid gap-1 rounded-md border px-2 py-2 text-left ${color}">
        <span class="font-bold uppercase">${item.level} · ${item.element_type || 'project'}</span>
        <span>${item.message || ''}</span>
        <small>${item.recommendation || ''}</small>
    </button>`;
}

function focusValidationItem(item) {
    if (!item) return;
    const id = Number(item.element_id);
    let layer = null;
    if (item.element_type === 'odf') layer = odfMarkerById[id];
    if (item.element_type === 'cabinet') layer = cabinetMarkerById[id];
    if (item.element_type === 'house') layer = houseMarkerById[id];
    if (item.element_type === 'route') layer = routeLayerById[id];

    if (layer?.getLatLng) {
        map.setView(layer.getLatLng(), Math.max(map.getZoom(), 20));
        layer.openPopup?.();
        document.getElementById('cad-command').textContent = `CHECK: ${item.message}`;
        return;
    }
    if (layer?.getBounds) {
        map.fitBounds(layer.getBounds(), { padding: [60, 60], maxZoom: 20 });
        layer.openPopup?.();
        document.getElementById('cad-command').textContent = `CHECK: ${item.message}`;
        return;
    }

    document.getElementById('cad-command').textContent = `CHECK: ${item.message}`;
}

function clearValidationHighlights() {
    validationHighlightLayers.forEach(layer => {
        if (layer && map.hasLayer(layer)) map.removeLayer(layer);
    });
    validationHighlightLayers = [];
    data.routes.forEach(route => {
        const line = routeLayerById[route.id];
        if (line?.setStyle) line.setStyle(routeLineStyle(route.type, routeLineColor(route)));
    });
}

function highlightValidationItems(items) {
    clearValidationHighlights();
    items.filter(item => ['error', 'warning'].includes(item.level)).forEach(item => {
        const color = item.level === 'error' ? '#dc2626' : '#f59e0b';
        const id = Number(item.element_id);
        let layer = null;
        if (item.element_type === 'odf') layer = odfMarkerById[id];
        if (item.element_type === 'cabinet') layer = cabinetMarkerById[id];
        if (item.element_type === 'house') layer = houseMarkerById[id];
        if (item.element_type === 'route') layer = routeLayerById[id];

        if (layer?.getLatLng) {
            validationHighlightLayers.push(L.circleMarker(layer.getLatLng(), {
                radius: 13,
                color,
                weight: 3,
                fill: false,
                interactive: false,
                pane: 'markerPane',
            }).addTo(map));
        } else if (layer?.setStyle) {
            layer.setStyle({ color, weight: 6, opacity: 1 });
        }
    });
}

document.getElementById('run-project-check').addEventListener('click', async () => {
    try {
        await runProjectCheck();
    } catch (error) {
        document.getElementById('project-check-summary').textContent = error.message;
    }
});
document.getElementById('fill-missing-drops').addEventListener('click', async () => {
    try {
        await fillMissingDropRoutes();
    } catch (error) {
        document.getElementById('project-check-summary').textContent = error.message;
    }
});

map.on('mousemove', e => {
    document.getElementById('cad-coordinates').textContent = `LAT ${e.latlng.lat.toFixed(7)}, LNG ${e.latlng.lng.toFixed(7)}`;
    if (mode === 'split') {
        const proj = nearestRouteProjection(e.latlng);
        if (proj) {
            showSplitPreview(proj);
            document.getElementById('cad-command').textContent = `SPLIT: klikni da podijeliš trasu "${proj.route.name}"`;
        } else {
            hideSplitPreview();
            document.getElementById('cad-command').textContent = 'SPLIT: pomjeri miša na trasu i klikni gdje hoćeš da je podijeliš.';
        }
        return;
    }
    redrawPreviewBranch(e.latlng);
    if (mode !== 'draw') updateCommandBar();
});

map.on('contextmenu', e => {
    if (mode !== 'draw') return;
    e.originalEvent.preventDefault();
    finishBranch();
});

map.on('dblclick', e => {
    if (mode !== 'draw') return;
    e.originalEvent.preventDefault();
    finishBranch();
});

document.addEventListener('keydown', event => {
    const target = event.target;
    const tag = target?.tagName?.toLowerCase();
    if (['input', 'select', 'textarea'].includes(tag) || target?.isContentEditable) return;

    if (event.key === 'Escape') {
        if (routeEdit) {
            cancelRouteEdit();
            return;
        }
        if (mode === 'draw') {
            cancelActiveDrawing();
        }
        if (mode === 'ruler') {
            clearRuler();
        }
        setMode('pan');
        return;
    }

    if (event.key === 'Enter' && mode === 'draw') {
        event.preventDefault();
        finishBranch();
        return;
    }
    if (event.key === 'Enter' && mode === 'join') {
        event.preventDefault();
        joinSelectedRoutes();
        return;
    }

    if (event.key === 'Backspace' && mode === 'draw') {
        event.preventDefault();
        undoDraw();
        return;
    }

    if ((event.ctrlKey || event.metaKey) && event.key.toLowerCase() === 'z') {
        event.preventDefault();
        if (routeEdit) undoRouteEdit();
        else if (mode === 'draw' && undoStack.length > 0) undoLast();
        else mapHistory.undo();
        return;
    }

    if ((event.ctrlKey || event.metaKey) && (event.key.toLowerCase() === 'y' || (event.shiftKey && event.key.toLowerCase() === 'z'))) {
        event.preventDefault();
        if (routeEdit) redoRouteEdit();
        else if (mode === 'draw' && redoStack.length > 0) redoLast();
        else mapHistory.redo();
        return;
    }

    if (!event.ctrlKey && !event.metaKey && event.key.toLowerCase() === 'o') {
        event.preventDefault();
        orthoEnabled = !orthoEnabled;
        updateCommandBar();
    }

    if (!event.ctrlKey && !event.metaKey && event.key.toLowerCase() === 'r') {
        event.preventDefault();
        osmRoutingEnabled = !osmRoutingEnabled;
        const cb = document.getElementById('osm-routing-toggle');
        if (cb) cb.checked = osmRoutingEnabled;
        updateCommandBar();
    }
});

map.on('click', e => {
    const lat = e.latlng.lat.toFixed(7), lng = e.latlng.lng.toFixed(7);
    if (mode === 'draw') { addDrawPoint(e.latlng); return; }
    if (mode === 'split') {
        if (splitPreview) {
            const { route, latlng, line, labels } = splitPreview;
            hideSplitPreview();
            splitSavedRoute(route, latlng, line, labels);
        }
        return;
    }
    if (mode === 'house') {
        housePoints.push(e.latlng);
        const index = housePoints.length - 1;
        const marker = L.marker(e.latlng, { icon: icon('house'), draggable: true }).bindPopup(`Kuca ${housePoints.length}`).addTo(map);
        houseMarkerByKey[pointKey(e.latlng.lat, e.latlng.lng)] = marker;
        marker.on('drag', event => {
            const next = event.target.getLatLng();
            housePoints[index] = next;
            houseMarkerByKey[pointKey(next.lat, next.lng)] = marker;
            refreshStats();
        });
        registerHouseContext(marker);
        houseMarkers.push(marker);
        document.getElementById('house-lat').value=lat; document.getElementById('house-lng').value=lng; refreshStats(); return;
    }
    if (mode === 'ruler') { rulerClick(e.latlng); return; }
    if (mode === 'manhole' || mode === 'boring-fi-130') {
        const isManhole = mode === 'manhole';
        const item = isManhole
            ? { type: 'manhole', marker: null, quantity: 1, note: '' }
            : createBoringDraft(e.latlng, { length_m: 12, angle_deg: 0, width_m: 1.8 });
        if (isManhole) {
            const marker = L.marker(e.latlng, { icon: icon('manhole', 'S'), draggable: true })
                .addTo(map)
                .bindTooltip('Prolazni saht', { direction: 'top', offset: [0, -10] })
                .bindPopup('Prolazni saht')
                .openPopup();
            item.marker = marker;
            marker.on('drag', refreshPlanSummary);
            marker.on('click', () => marker.openPopup());
        } else {
            item.marker.openPopup();
        }
        draftAppendixItems.push(item);
        draftElements.push({ type: item.type, marker: item.marker });
        registerDraftContext(item.marker, isManhole ? 'Prolazni saht' : 'Podbusivanje FI 130');
        refreshPlanSummary();
        return;
    }
    if (mode === 'odf') {
        if (selectedDraftElement?.item.pending) closeDraftElementEditor();
        const item = { marker: null, name: '', pending: true };
        const marker = L.marker(e.latlng, { icon: icon('odf','ODF'), draggable: true })
            .addTo(map)
            .bindTooltip('ODF · 0 FTTH', { direction: 'top', offset: [0, -10] })
            .bindPopup('Unesi naziv ODF-a')
            .openPopup();
        item.marker = marker;
        marker.on('dragend', event => { const p = event.target.getLatLng(); document.getElementById('odf-lat').value=p.lat.toFixed(7); document.getElementById('odf-lng').value=p.lng.toFixed(7); });
        selectDraftElement('odf', item);
        document.getElementById('odf-lat').value=lat; document.getElementById('odf-lng').value=lng; return;
    }
    if (mode === 'cabinet') {
        draftCabinetCount++;
        const odf = activeOdfPayload(e.latlng);
        const item = { marker: null, name: defaultDraftName('cabinet', draftCabinetCount - 1), odf_index: odf.odf_index, odf_id: odf.odf_id };
        const marker = L.marker(e.latlng, { icon: icon('cabinet', item.name), draggable: true })
            .addTo(map)
            .bindTooltip('0/12', { direction: 'top', offset: [0, -10] })
            .bindPopup(`FTTH draft ${draftCabinetCount}`)
            .openPopup();
        item.marker = marker;
        marker.on('dragend', event => { const p = event.target.getLatLng(); document.getElementById('cabinet-lat').value=p.lat.toFixed(7); document.getElementById('cabinet-lng').value=p.lng.toFixed(7); });
        marker.on('drag', () => {
            refreshPlanSummary();
        });
        draftElements.push({ type: 'cabinet', marker });
        draftCabinets.push(item);
        marker.on('click', () => selectDraftElement('cabinet', item));
        registerDraftContext(marker, item.name);
        refreshDraftTooltips();
        selectDraftElement('cabinet', item);
        document.getElementById('cabinet-lat').value=lat; document.getElementById('cabinet-lng').value=lng;
    }
});
setMode('pan'); refreshStats();

// Auto-load draft when project is selected
data.drafts.forEach(draft => {
    draftsByProject[draft.project_id] = draft.payload;
});
autosaveReady = true;

function updateProjectExportLink(projectId = document.getElementById('active-project-id').value) {
    const links = [
        ['export-geojson', appConfig.projectGeoJsonBaseUrl],
        ['export-dxf', appConfig.projectDxfBaseUrl],
        ['print-project', appConfig.projectPrintBaseUrl],
    ];
    const exportActions = document.getElementById('export-actions');
    if (!projectId) {
        links.forEach(([id]) => {
            const link = document.getElementById(id);
            if (link) { link.href = '#'; link.removeAttribute('data-dxf-url'); }
        });
        if (exportActions) exportActions.style.display = 'none';
        return;
    }
    links.forEach(([id, baseUrl]) => {
        const link = document.getElementById(id);
        if (!link) return;
        const url = baseUrl.replace('__ID__', projectId);
        link.href = url;
        if (id === 'export-dxf') link.setAttribute('data-dxf-url', url);
    });
    if (exportActions) exportActions.style.display = 'grid';
}

// DXF export — POST s background layerima iz IndexedDB
document.getElementById('export-dxf')?.addEventListener('click', async function (e) {
    const dxfUrl = this.getAttribute('data-dxf-url');
    if (!dxfUrl) return; // nema projekta, pusti default navigaciju
    e.preventDefault();

    const orig = this.textContent;
    this.textContent = 'Pripremam…';
    this.style.pointerEvents = 'none';

    try {
        const bgLayers = window.ftthDxfLayer
            ? await window.ftthDxfLayer.getLayersForExport()
            : [];

        // Prikaži koliko bg layera ide u export (debug vidljivo korisniku)
        const cmd = document.getElementById('cad-command');
        if (cmd && bgLayers.length > 0) {
            cmd.textContent = `Export: ${bgLayers.length} DXF podlog(a) uključeno...`;
        }

        const csrf = document.querySelector('meta[name="csrf-token"]')?.content ?? '';
        const res  = await fetch(dxfUrl, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': csrf,
                'Accept': 'application/octet-stream,application/dxf,*/*',
            },
            body: JSON.stringify({ background_layers: bgLayers }),
        });

        if (!res.ok) {
            let msg = 'HTTP ' + res.status;
            try {
                const errJson = await res.json();
                if (errJson.error) msg = errJson.error;
            } catch {}
            throw new Error(msg);
        }

        const blob = await res.blob();
        const url  = URL.createObjectURL(blob);
        const a    = document.createElement('a');
        const cd   = res.headers.get('Content-Disposition') ?? '';
        a.download = cd.match(/filename[^;=\n]*=["']?([^"'\n]+)/i)?.[1] ?? 'export.dxf';
        a.href = url;
        document.body.appendChild(a);
        a.click();
        document.body.removeChild(a);
        URL.revokeObjectURL(url);
    } catch (err) {
        alert('Greška pri DXF exportu: ' + err.message);
    } finally {
        this.textContent = orig;
        this.style.pointerEvents = '';
    }
});

document.getElementById('active-project-id').addEventListener('change', (e) => {
    const projectId = e.target.value;
    updateProjectExportLink(projectId);

    // Navigate to filtered URL so map data reloads for the selected project
    if (!keepCurrentDraftOnProjectChange) {
        const url = new URL(window.location.href);
        if (projectId) {
            url.searchParams.set('project', projectId);
        } else {
            url.searchParams.delete('project');
        }
        window.location.href = url.toString();
        return;
    }

    keepCurrentDraftOnProjectChange = false;
    activeOdfSelection = null;
    renderDraftOdfPicker();
    refreshPlanSummary();
});

// On page load, restore draft for the active project (pre-selected via URL)
(function () {
    const projectId = document.getElementById('active-project-id').value;
    if (!projectId) return;
    updateProjectExportLink(projectId);
    renderDraftOdfPicker();
    setTimeout(() => {
        const draft = draftsByProject[projectId];
        if (draft) restoreDraft(draft);
        else renderDraftOdfPicker();
    }, 500);
})();
const pendingTraceHouseId = localStorage.getItem('ftthTraceHouseId');
if (pendingTraceHouseId) {
    localStorage.removeItem('ftthTraceHouseId');
    setTimeout(() => showFiberTrace(pendingTraceHouseId), 350);
}
refreshTrenchGroupStatus();

// ── Project picker modal ──────────────────────────────────────────
function pickProject(id) {
    const url = new URL(window.location.href);
    url.searchParams.set('project', id);
    window.location.href = url.toString();
}

async function ppCreateProject() {
    const nameInput = document.getElementById('pp-new-name');
    const status = document.getElementById('pp-new-status');
    const name = nameInput.value.trim();
    if (!name) { status.textContent = 'Upiši naziv projekta.'; return; }
    status.textContent = 'Kreiram...';
    try {
        const body = new FormData();
        body.append('_token', document.querySelector('meta[name="csrf-token"]')?.content || '');
        body.append('name', name);
        body.append('quick_create', '1');
        const response = await fetch(appConfig.projectsStore, {
            method: 'POST',
            headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            body,
        });
        const result = await readJsonResponse(response, 'Projekat nije kreiran.');
        status.textContent = `${result.project.name} je kreiran. Učitavam...`;
        pickProject(result.project.id);
    } catch (err) {
        status.textContent = err.message;
    }
}

document.getElementById('pp-new-name')?.addEventListener('keydown', e => {
    if (e.key === 'Enter') ppCreateProject();
});

(function tryInit() {
    if (window.ftthDxfLayer && window.ftthNetworkMap) {
        window.ftthDxfLayer.init(window.ftthNetworkMap);
    } else {
        setTimeout(tryInit, 200);
    }
})();

// ── PRETRAGA MARKERA ─────────────────────────────────────────────────────────
(function () {
    let idx = null, hits = [];
    const inp = document.getElementById('map-search-input');
    const res = document.getElementById('map-search-results');
    if (!inp) return;

    function buildIdx() {
        const items = [];
        data.odfs.forEach(o => items.push({ type: 'ODF', label: o.name, sub: o.address || '', lat: o.lat, lng: o.lng, id: o.id, color: '#0891b2' }));
        data.cabinets.forEach(c => items.push({ type: 'FTTH', label: c.name, sub: (c.address || '') + (c.capacity ? ` · ${c.used_ports}/${c.capacity}p` : ''), lat: c.lat, lng: c.lng, id: c.id, color: '#059669' }));
        data.houses.forEach(h => items.push({ type: 'Kuća', label: h.label, sub: h.address || h.cabinet || '', lat: h.lat, lng: h.lng, id: h.id, color: '#7c3aed' }));
        return items;
    }

    inp.addEventListener('input', () => {
        if (!idx) idx = buildIdx();
        const q = inp.value.trim().toLowerCase();
        if (!q) { res.style.display = 'none'; return; }
        hits = idx.filter(it => it.label.toLowerCase().includes(q) || it.sub.toLowerCase().includes(q)).slice(0, 12);
        if (!hits.length) {
            res.innerHTML = '<div style="padding:10px 12px;font-size:11px;color:#94a3b8">Nema rezultata</div>';
        } else {
            res.innerHTML = hits.map((h, i) =>
                `<div data-i="${i}" style="padding:8px 12px;cursor:pointer;border-bottom:1px solid #f8fafc;display:flex;align-items:center;gap:8px">
                    <span style="flex-shrink:0;width:34px;text-align:center;padding:2px 0;border-radius:5px;font-size:10px;font-weight:700;color:#fff;background:${h.color}">${h.type}</span>
                    <div style="min-width:0">
                        <div style="font-size:12px;font-weight:600;color:#1e293b;white-space:nowrap;overflow:hidden;text-overflow:ellipsis">${h.label}</div>
                        <div style="font-size:10px;color:#94a3b8;white-space:nowrap;overflow:hidden;text-overflow:ellipsis">${h.sub}</div>
                    </div>
                </div>`
            ).join('');
        }
        res.style.display = 'block';
    });
    res.addEventListener('click', e => {
        const row = e.target.closest('[data-i]');
        if (!row) return;
        const h = hits[Number(row.dataset.i)];
        if (!h) return;
        map.flyTo([h.lat, h.lng], 18, { duration: 0.8 });
        inp.value = h.label;
        res.style.display = 'none';
        setTimeout(() => {
            const m = h.type === 'ODF' ? odfMarkerById[h.id]
                    : h.type === 'FTTH' ? cabinetMarkerById[h.id]
                    : houseMarkerById[h.id];
            m?.openPopup();
        }, 900);
    });
    res.addEventListener('mouseover', e => { const r = e.target.closest('[data-i]'); if (r) r.style.background = '#f8fafc'; });
    res.addEventListener('mouseout',  e => { const r = e.target.closest('[data-i]'); if (r) r.style.background = ''; });
    document.addEventListener('click', e => { if (!e.target.closest('#map-search-overlay')) res.style.display = 'none'; });
    inp.addEventListener('keydown', e => { if (e.key === 'Escape') { res.style.display = 'none'; inp.blur(); } });
    inp.addEventListener('focus', () => { if (!idx) idx = buildIdx(); });
})();

// ── STATISTIKE NA MAPI ────────────────────────────────────────────────────────
(function () {
    const bar = document.getElementById('map-stats-bar');
    if (!bar) return;
    const chips = [
        { label: 'ODF',   val: data.odfs.length,      bg: 'rgba(8,145,178,.12)',   bc: 'rgba(8,145,178,.4)',   tc: '#0891b2' },
        { label: 'ODO',   val: data.cabinets.length,   bg: 'rgba(5,150,105,.12)',   bc: 'rgba(5,150,105,.4)',   tc: '#059669' },
        { label: 'Kuće',  val: data.houses.length,     bg: 'rgba(124,58,237,.12)',  bc: 'rgba(124,58,237,.4)',  tc: '#7c3aed' },
        { label: 'Trase', val: data.routes.filter(r => r.type !== 'trench' && r.type !== 'drop').length, bg: 'rgba(100,116,139,.12)', bc: 'rgba(100,116,139,.4)', tc: '#475569' },
    ];
    bar.innerHTML = chips.map(c =>
        `<div style="background:#fff;border-left:3px solid ${c.tc};color:#1e293b;padding:4px 10px;border-radius:7px;font-size:11px;font-weight:600;line-height:1.4;box-shadow:0 2px 8px rgba(0,0,0,.22);display:flex;align-items:center;gap:5px">
            <span style="color:${c.tc};font-size:10px;font-weight:700">${c.label}</span>
            <b style="font-size:13px;color:${c.tc}">${c.val}</b>
        </div>`
    ).join('');
})();

// ── LEGENDA ───────────────────────────────────────────────────────────────────
document.getElementById('map-legend-btn')?.addEventListener('click', e => {
    e.stopPropagation();
    const p = document.getElementById('map-legend-panel');
    if (p) p.style.display = p.style.display === 'none' ? 'block' : 'none';
});
document.addEventListener('click', e => {
    if (!e.target.closest('#map-legend-panel') && !e.target.closest('#map-legend-btn')) {
        const p = document.getElementById('map-legend-panel');
        if (p) p.style.display = 'none';
    }
});

// ── FILTER PO PROJEKTU ────────────────────────────────────────────────────────
document.getElementById('map-project-filter')?.addEventListener('change', function () {
    const pid = this.value ? Number(this.value) : null;
    const odfPid = {}, cabPid = {};
    data.odfs.forEach(o => odfPid[o.id] = o.project_id);
    data.cabinets.forEach(c => cabPid[c.id] = c.project_id);

    data.odfs.forEach(o => {
        const m = odfMarkerById[o.id]; if (!m) return;
        if (pid && o.project_id !== pid) map.removeLayer(m);
        else if (!map.hasLayer(m)) map.addLayer(m);
    });
    data.cabinets.forEach(c => {
        const m = cabinetMarkerById[c.id]; if (!m) return;
        if (pid && c.project_id !== pid) map.removeLayer(m);
        else if (!map.hasLayer(m)) map.addLayer(m);
    });
    data.houses.forEach(h => {
        const m = houseMarkerById[h.id]; if (!m) return;
        if (pid && h.project_id !== pid) map.removeLayer(m);
        else if (!map.hasLayer(m)) map.addLayer(m);
    });
    data.routes.forEach(route => {
        const routePid = cabPid[route.cabinet_id] || odfPid[route.odf_id];
        const l = routeLayerById[route.id]; if (!l) return;
        if (pid && routePid && routePid !== pid) map.removeLayer(l);
        else if (!map.hasLayer(l)) map.addLayer(l);
        (routeLabelsById[route.id] || []).forEach(lbl => {
            if (pid && routePid && routePid !== pid) map.removeLayer(lbl);
            else if (!map.hasLayer(lbl)) map.addLayer(lbl);
        });
    });
});

// ── PRINT / EXPORT ────────────────────────────────────────────────────────────
document.getElementById('btn-map-print')?.addEventListener('click', () => window.print());
