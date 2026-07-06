// ── DRAFT / PLAN PERSISTENCE ──────────────────────────────────────────────────
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
            fiber_length_m: (meta.route_type || 'distribution') === 'trench' ? 0 : meters,
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
        const item = { marker: null, name: Array.isArray(point) ? defaultDraftName('odf', restoredHouseIndex) : (point.name || defaultDraftName('odf', restoredHouseIndex)) };
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
        const lat = Array.isArray(point) ? point[0] : point.lat;
        const lng = Array.isArray(point) ? point[1] : point.lng;
        const latLng = L.latLng(lat, lng);
        const item = { marker: null, name: Array.isArray(point) ? `ODF-${String(index + 1).padStart(2, '00')}` : (point.name || `ODF-${String(index + 1).padStart(2, '00')}`) };
        const marker = L.marker(latLng, { icon: icon('odf', 'ODF'), draggable: true }).bindTooltip('ODF · 0 FTTH', { direction: 'top', offset: [0, -10] }).addTo(map);
        item.marker = marker;
        marker.on('drag', () => { refreshDraftTooltips(); refreshPlanSummary(); });
        marker.on('click', () => { setActiveDraftOdf(index); selectDraftElement('odf', item); });
        registerDraftContext(marker, `ODF-${String(index + 1).padStart(2, '00')}`);
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
