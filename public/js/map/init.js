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
initDraftPersistenceControls();

// ── TOOLBAR EVENT LISTENERS ────────────────────────────────────────────────────
initMapViewToolbar();
initProjectVersionHistory();
initRouteEditingControls();
initHouseConnectionControls();

// ── PROJECT CHECK ──────────────────────────────────────────────────────────────
initProjectCheckControls();

// ── MAP EVENT HANDLERS ─────────────────────────────────────────────────────────
const cadCrosshair = document.getElementById('cad-crosshair');
map.on('mousemove', event => {
    if (!cadCrosshair) return;
    const point = map.latLngToContainerPoint(event.latlng);
    cadCrosshair.style.setProperty('--cad-x', `${point.x}px`);
    cadCrosshair.style.setProperty('--cad-y', `${point.y}px`);
});
map.on('mouseout', () => {
    cadCrosshair?.style.setProperty('--cad-x', '-100px');
    cadCrosshair?.style.setProperty('--cad-y', '-100px');
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
    if (mode === 'trace-branch' && traceBranchStart) {
        const snap = traceSnapTarget(e.latlng);
        showSnapIndicator(snap);
        const point = snap?.latlng || e.latlng;
        const { point: endPoint, hint: endHint } = resolveTraceEndPoint(e.latlng, point, snap, traceBranchStartSnap);
        const path = shortestTracePath(traceBranchStart, endPoint, traceBranchStartSnap, endHint);
        if (!path) {
            if (traceBranchPreviewLine) { map.removeLayer(traceBranchPreviewLine); traceBranchPreviewLine = null; }
            document.getElementById('cad-command').textContent = 'KRAK PO LINIJI: između tačaka nema povezane trase.';
            return;
        }
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

initMapKeyboardInteractions();

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
        const draftIndex = index - savedHouseCount;
        const houseMeta = { label: `K-${String(draftIndex + 1).padStart(3, '0')}`, address: '' };
        draftHouseMeta.push(houseMeta);
        const marker = L.marker(e.latlng, { icon: icon('house', 'K'), draggable: true }).bindPopup(`Kuća ${housePoints.length}`).addTo(map);
        houseMarkerByKey[pointKey(e.latlng.lat, e.latlng.lng)] = marker;
        marker.on('drag', event => {
            const next = event.target.getLatLng();
            housePoints[index] = next;
            houseMarkerByKey[pointKey(next.lat, next.lng)] = marker;
            refreshStats();
            refreshPlanSummary();
        });
        registerHouseContext(marker);
        marker.on('click', () => selectDraftElement('house', { marker, houseIndex: draftIndex, meta: houseMeta }));
        houseMarkers.push(marker);
        document.getElementById('house-lat').value=lat; document.getElementById('house-lng').value=lng; refreshStats(); refreshPlanSummary(); selectDraftElement('house', { marker, houseIndex: draftIndex, meta: houseMeta }); return;
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
        const item = { marker: null, name: defaultName, address: '', fiber_capacity: 144, port_count: 48, pending: false };
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
        const item = { marker: null, name: defaultDraftName('cabinet', draftCabinetCount - 1), address: '', splitter_count: 3, ports_per_splitter: 4, odf_index: odf.odf_index, odf_id: odf.odf_id };
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
focusRequestedMapElement();

// ── DXF EXPORT ────────────────────────────────────────────────────────────────
initDxfExport();

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
document.getElementById('mobile-map-tools-toggle')?.addEventListener('click', event => {
    const toolbar = document.getElementById('map-cad-toolbar');
    const opening = !toolbar?.classList.contains('mobile-open');
    toolbar?.classList.toggle('mobile-open', opening);
    event.currentTarget.setAttribute('aria-expanded', String(opening));
    const label = event.currentTarget.querySelector('span:last-child');
    if (label) label.textContent = opening ? 'Sakrij' : 'Prikaži';
    setTimeout(() => map.invalidateSize(), 80);
});
document.querySelectorAll('#map-workspace > aside > details.sidebar-card').forEach(section => {
    section.addEventListener('toggle', () => {
        if (!section.open) return;
        document.querySelectorAll('#map-workspace > aside > details.sidebar-card[open]').forEach(other => {
            if (other !== section) other.open = false;
        });
        requestAnimationFrame(() => section.scrollIntoView({
            behavior: window.matchMedia('(prefers-reduced-motion: reduce)').matches ? 'auto' : 'smooth',
            block: 'start',
            inline: 'nearest',
        }));
    });
});
document.getElementById('pp-new-name')?.addEventListener('keydown', e => {
    if (e.key === 'Enter') ppCreateProject();
});
document.getElementById('pp-new-submit')?.addEventListener('click', ppCreateProject);
document.querySelectorAll('#project-picker-card [data-project-id]').forEach(button => {
    button.addEventListener('click', () => pickProject(Number(button.dataset.projectId)));
});
const projectPickerOverlay = document.getElementById('project-picker-overlay');
const projectPickerSearch = document.getElementById('pp-project-search');
if (projectPickerOverlay && !projectPickerOverlay.classList.contains('hidden')) {
    requestAnimationFrame(() => (projectPickerSearch || document.getElementById('pp-new-name'))?.focus());
}
projectPickerSearch?.addEventListener('input', () => {
    const query = projectPickerSearch.value.trim().toLocaleLowerCase('bs');
    document.querySelectorAll('#project-picker-card .pp-row').forEach(row => {
        row.hidden = query !== '' && !row.dataset.projectSearch.toLocaleLowerCase('bs').includes(query);
    });
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
                    <div style="font-size:12px;font-weight:600;color:#1e293b;white-space:nowrap;overflow:hidden;text-overflow:ellipsis">${escapeHtml(h.label)}</div>
                    <div style="font-size:10px;color:#94a3b8;white-space:nowrap;overflow:hidden;text-overflow:ellipsis">${escapeHtml(h.sub)}</div>
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
    const contextProject = document.getElementById('map-context-project');
    if (contextProject) contextProject.textContent = this.options[this.selectedIndex]?.textContent || 'Svi projekti — samo pregled';
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

const focusedCabinetId = Number(new URLSearchParams(location.search).get('cabinet') || 0);
if (focusedCabinetId && cabinetMarkerById[focusedCabinetId]) {
    const marker = cabinetMarkerById[focusedCabinetId];
    requestAnimationFrame(() => { map.setView(marker.getLatLng(), Math.max(map.getZoom(), 19)); marker.openPopup(); });
}
