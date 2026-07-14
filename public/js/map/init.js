// ── INITIALIZATION ────────────────────────────────────────────────────────────

// Tile layers (state.js declared them as let)
imagery = L.tileLayer('https://server.arcgisonline.com/ArcGIS/rest/services/World_Imagery/MapServer/tile/{z}/{y}/{x}', {
    maxNativeZoom: 18,
    maxZoom: 22,
    attribution: 'Tiles &copy; Esri'
}).addTo(map);

osm = L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', { maxZoom: 22, maxNativeZoom: 19, attribution: '&copy; OpenStreetMap' });
cartodbDark = L.tileLayer('https://{s}.basemaps.cartocdn.com/dark_all/{z}/{x}/{y}{r}.png', { maxNativeZoom: 19, maxZoom: 22, attribution: '&copy; CARTO' });
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

// ── DATA LOADING ──────────────────────────────────────────────────────────────
const bounds = [];
applyRouteStacking(data.routes);
applyRouteLabelLanes(data.routes);
data.routes.forEach(route => {
    if (!route.path?.length) return;
    const points = route.path.map(point => L.latLng(point[0], point[1]));
    savedRoutePoints.push(points);
    const occupancy = route.occupancy || {};
    const baseStyle = routeLineStyle(route.type, routeLineColor(route));
    if (route._stack) baseStyle.weight = routeStackedWeight(route, baseStyle.weight);
    const line = L.polyline(points, { ...baseStyle, interactive: false })
        .bindPopup(`<b>${route.name}</b><br>${routeTypeLabel(route.type)}<br>${route.duct_length_m} m<br>Fiber: ${occupancy.fiber_capacity ?? route.fibers ?? 0}F<br>Zauzeto: ${occupancy.used_fibers ?? '-'}<br>Slobodno: ${occupancy.free_fibers ?? '-'}<br>Iskorištenost: ${occupancy.utilization_percent ?? '-'}%`)
        .addTo(map);
    const hitLine = L.polyline(points, { weight: 14, opacity: 0, interactive: true }).addTo(map);
    if (route.type === 'trench') { line.bringToBack(); hitLine.bringToBack(); }
    const labels = route.type === 'trench' ? [] : addRouteLabel(points, route.name, false, routeLabelSpecs(route), route._labelLane);
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
(data.gis_segments || []).forEach(segment => {
    if (!segment.path?.length) return;
    const points = segment.path.map(point => L.latLng(point[0], point[1]));
    const line = L.polyline(points, gisSegmentStyle(segment.segment_type))
        .bindPopup(`<b>${segment.name || 'GIS segment'}</b><br>${segment.segment_type}<br>${segment.length_m || distance(points)} m<br>${segment.project || ''}`)
        .addTo(map);
    trackLayer(line, 'gis');
    points.forEach(p => bounds.push([p.lat, p.lng]));
});
(data.gis_restricted_areas || []).forEach(area => {
    if (!area.polygon?.length) return;
    const points = area.polygon.map(point => L.latLng(point[0], point[1]));
    const polygon = L.polygon(points, {
        color: '#dc2626',
        weight: 2,
        opacity: .85,
        fillColor: '#ef4444',
        fillOpacity: .18,
        dashArray: '6 5',
    })
        .bindPopup(`<b>${area.name || 'Zabranjena zona'}</b><br>${area.area_type || 'restricted'}<br>${area.project || ''}`)
        .addTo(map);
    trackLayer(polygon, 'gis');
    points.forEach(p => bounds.push([p.lat, p.lng]));
});
data.odfs.forEach(odf => {
    const p = L.latLng(odf.lat, odf.lng);
    const connectedCabinets = data.cabinets.filter(c => c.odf_id === odf.id).length;
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
    } else if (item.type === 'splice') {
        const marker = L.marker(p, { icon: icon('splice', 'S'), draggable: false })
            .bindTooltip('Spojnica', { direction: 'top', offset: [0, -10] })
            .bindPopup(`<b>Spojnica</b>${item.note ? `<br>${item.note}` : ''}`)
            .addTo(map);
        trackLayer(marker, 'preview');
    } else if (item.type === 'loop') {
        const marker = L.marker(p, { icon: icon('loop', 'R'), draggable: false })
            .bindTooltip('Rezerva/slinga', { direction: 'top', offset: [0, -10] })
            .bindPopup(`<b>Rezerva/slinga</b>${item.note ? `<br>${item.note}` : ''}`)
            .addTo(map);
        trackLayer(marker, 'preview');
    } else {
        const marker = L.marker(p, { icon: icon('manhole', 'S'), draggable: false })
            .bindTooltip(`Prolazni saht: ${item.quantity} ${item.unit}`, { direction: 'top', offset: [0, -10] })
            .bindPopup(`<b>Prolazni saht</b><br>${item.quantity} ${item.unit}${item.note ? `<br>${item.note}` : ''}`)
            .addTo(map);
        trackLayer(marker, 'preview');
    }
    bounds.push([item.lat, item.lng]);
});
savedHouseCount = housePoints.length;
if (bounds.length) map.fitBounds(bounds, { padding: [40, 40], maxZoom: 19 }); else map.setView(defaultCenter, 17);

// ── MODE BUTTON LISTENERS ──────────────────────────────────────────────────────
['pan','select','odf','cabinet','house','draw','manhole','boring-fi-130','ruler','branch-source','trace-branch','connect','connect-houses','trace','join','split'].forEach(m => document.getElementById(`mode-${m}`).addEventListener('click', () => {
    setMode(m);
    if (m === 'draw' && document.getElementById('route-draw-type').value === 'trench') {
        document.getElementById('cad-command').textContent = 'GLAVNI ROV: klikni tacke fizickog iskopa. ENTER/desni klik zavrsava rov.';
    }
}));
document.getElementById('osm-routing-toggle').addEventListener('change', function () {
    osmRoutingEnabled = this.checked;
    updateCommandBar();
});
document.getElementById('gis-routing-toggle')?.addEventListener('change', function () {
    gisRoutingEnabled = this.checked;
    updateCommandBar();
});

document.getElementById('mode-trench-draw').addEventListener('click', () => {
    document.getElementById('route-draw-type').value = 'trench';
    document.getElementById('route-draw-name').placeholder = `npr. ${nextRouteName('trench')}`;
    refreshTrenchGroupStatus();
    setMode('draw');
    document.getElementById('cad-command').textContent = 'GLAVNI ROV: klikni tacke fizickog iskopa. ENTER/desni klik zavrsava rov.';
});

// ── DRAFT EVENT LISTENERS ──────────────────────────────────────────────────────
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
document.getElementById('preview-gis-plan')?.addEventListener('click', previewGisPlan);
document.getElementById('save-gis-plan')?.addEventListener('click', saveGisPlan);
document.getElementById('active-odf-index').addEventListener('change', event => setActiveDraftOdf(event.target.value));
document.querySelectorAll('[data-guide-mode]').forEach(button => {
    button.addEventListener('click', () => setMode(button.dataset.guideMode));
});
document.getElementById('guide-suggest').addEventListener('click', suggest);

// ── LAYER TOGGLE / LOCK ────────────────────────────────────────────────────────
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
document.querySelectorAll('[data-layer-opacity]').forEach(input => {
    input.addEventListener('input', () => applyLayerOpacity(input.dataset.layerOpacity, input.value));
});
Object.keys(layerRegistry).forEach(updateLayerCount);

// ── PROJECT FORM / SAVE DRAFT / ELEMENT EDITOR / BULK PLAN ───────────────────
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

        const result = await readJsonResponse(response, 'Plan nije snimljen. Provjeri podatke.');
        delete draftsByProject[form.elements.project_id.value];
        status.textContent = `${result.message} Osvjezavam trajno spremljenu mapu...`;
        window.location.reload();
    } catch (error) {
        status.textContent = error.message;
    }
});

// ── TOOLBAR EVENT LISTENERS ────────────────────────────────────────────────────
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

// ── PROJECT CHECK ──────────────────────────────────────────────────────────────
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

// ── MAP EVENT HANDLERS ─────────────────────────────────────────────────────────
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
    if (mode === 'trace-branch' && traceBranchStart) {
        const snap = traceSnapTarget(e.latlng);
        showSnapIndicator(snap);
        const point = snap?.latlng || e.latlng;
        const { point: endPoint, hint: endHint } = resolveTraceEndPoint(e.latlng, point, snap, traceBranchStartSnap);
        const path = shortestTracePath(traceBranchStart, endPoint, traceBranchStartSnap, endHint) || networkPathBetween(traceBranchStart, endPoint);
        if (traceBranchPreviewLine) traceBranchPreviewLine.setLatLngs(path);
        else traceBranchPreviewLine = L.polyline(path, { color: '#f59e0b', weight: 3, opacity: .8, dashArray: '4 7' }).addTo(map);
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
        if (mode === 'trace-branch') {
            traceBranchStart = null;
            traceBranchStartSnap = null;
            if (traceBranchPreviewLine) { map.removeLayer(traceBranchPreviewLine); traceBranchPreviewLine = null; }
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

    if (!event.ctrlKey && !event.metaKey && event.key.toLowerCase() === 'g') {
        event.preventDefault();
        gisRoutingEnabled = !gisRoutingEnabled;
        const cb = document.getElementById('gis-routing-toggle');
        if (cb) cb.checked = gisRoutingEnabled;
        updateCommandBar();
    }
});

map.on('click', e => {
    const lat = e.latlng.lat.toFixed(7), lng = e.latlng.lng.toFixed(7);
    if (mode === 'draw') { addDrawPoint(e.latlng); return; }
    if (mode === 'trace-branch') { handleTraceBranchClick(e.latlng); return; }
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
        const defaultName = defaultDraftName('odf', draftOdfs.length);
        const item = { marker: null, name: defaultName, pending: false };
        const marker = L.marker(e.latlng, { icon: icon('odf','ODF'), draggable: true })
            .addTo(map)
            .bindTooltip(`${defaultName} · 0 FTTH`, { direction: 'top', offset: [0, -10] })
            .bindPopup(`<b>ODF: ${defaultName}</b>`)
            .openPopup();
        item.marker = marker;
        marker.on('dragend', event => { const p = event.target.getLatLng(); document.getElementById('odf-lat').value=p.lat.toFixed(7); document.getElementById('odf-lng').value=p.lng.toFixed(7); });
        marker.on('drag', () => { refreshDraftTooltips(); refreshPlanSummary(); });
        marker.on('click', () => { setActiveDraftOdf(draftOdfs.indexOf(item)); selectDraftElement('odf', item); });
        draftElements.push({ type: 'odf', marker });
        draftOdfs.push(item);
        registerDraftContext(item.marker, item.name);
        setActiveDraftOdf(draftOdfs.length - 1);
        refreshDraftTooltips();
        refreshPlanSummary();
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

// ── DXF EXPORT ────────────────────────────────────────────────────────────────
document.getElementById('export-dxf')?.addEventListener('click', async function (e) {
    const dxfUrl = this.getAttribute('data-dxf-url');
    if (!dxfUrl) return;
    e.preventDefault();

    const orig = this.textContent;
    this.textContent = 'Pripremam…';
    this.style.pointerEvents = 'none';

    try {
        const bgLayers = window.ftthDxfLayer
            ? await window.ftthDxfLayer.getLayersForExport()
            : [];

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

// ── PROJECT PICKER MODAL ───────────────────────────────────────────────────────
document.getElementById('pp-new-name')?.addEventListener('keydown', e => {
    if (e.key === 'Enter') ppCreateProject();
});

// ── DXF LAYER INIT ────────────────────────────────────────────────────────────
(function tryInit() {
    if (window.ftthDxfLayer && window.ftthNetworkMap) {
        window.ftthDxfLayer.init(window.ftthNetworkMap);
    } else {
        setTimeout(tryInit, 200);
    }
})();

// ── MARKER SEARCH ─────────────────────────────────────────────────────────────
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

// ── STATS BAR ─────────────────────────────────────────────────────────────────
(function () {
    const bar = document.getElementById('map-stats-bar');
    if (!bar) return;
    const chips = [
        { label: 'ODF',   val: data.odfs.length,      tc: '#0891b2' },
        { label: 'ODO',   val: data.cabinets.length,   tc: '#059669' },
        { label: 'Kuće',  val: data.houses.length,     tc: '#7c3aed' },
        { label: 'Trase', val: data.routes.filter(r => r.type !== 'trench' && r.type !== 'drop').length, tc: '#475569' },
    ];
    bar.innerHTML = chips.map(c =>
        `<div style="background:#fff;border-left:3px solid ${c.tc};color:#1e293b;padding:4px 10px;border-radius:7px;font-size:11px;font-weight:600;line-height:1.4;box-shadow:0 2px 8px rgba(0,0,0,.22);display:flex;align-items:center;gap:5px">
            <span style="color:${c.tc};font-size:10px;font-weight:700">${c.label}</span>
            <b style="font-size:13px;color:${c.tc}">${c.val}</b>
        </div>`
    ).join('');
})();

// ── LEGENDA ────────────────────────────────────────────────────────────────────
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

// ── FILTER PO PROJEKTU ─────────────────────────────────────────────────────────
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

// ── PRINT ──────────────────────────────────────────────────────────────────────
document.getElementById('btn-map-print')?.addEventListener('click', () => window.print());
