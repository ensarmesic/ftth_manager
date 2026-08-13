// ── TOOLBAR / VIEW / PROJECT CHECK ───────────────────────────────────────────
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

function updateParallelRouteButton() {
    const btn = document.getElementById('toggle-parallel-routes');
    btn.setAttribute('aria-pressed', String(parallelRouteDisplay));
    btn.classList.toggle('bg-emerald-100', parallelRouteDisplay);
    btn.classList.toggle('border-emerald-400', parallelRouteDisplay);
    btn.classList.toggle('text-emerald-900', parallelRouteDisplay);
    btn.textContent = parallelRouteDisplay ? 'Paralelno ✓' : 'Paralelno';
}

function initMapViewToolbar() {
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
    document.getElementById('toggle-parallel-routes').addEventListener('click', () => {
        parallelRouteDisplay = !parallelRouteDisplay;
        localStorage.setItem('ftth.parallelRouteDisplay', parallelRouteDisplay ? 'on' : 'off');
        refreshRouteVisualGeometry();
        updateParallelRouteButton();
        document.getElementById('cad-command').textContent = parallelRouteDisplay
            ? 'PARALELNO: mikrocijevi su razdvojene u rovu.'
            : 'PARALELNO: isključeno, prikazana je tačna zajednička osa.';
    });
    document.getElementById('btn-coord-jump').addEventListener('click', () => {
        const raw = prompt('Unesi koordinate (lat, lng):');
        if (!raw) return;
        const parts = raw.split(/[\s,;]+/).map(Number).filter(value => !isNaN(value));
        if (parts.length < 2) {
            document.getElementById('cad-command').textContent = 'GOTO: neispravan format. Primjer: 44.449, 18.650';
            return;
        }
        map.setView([parts[0], parts[1]], Math.max(map.getZoom(), 18));
        document.getElementById('cad-command').textContent = `GOTO: LAT ${parts[0].toFixed(5)}, LNG ${parts[1].toFixed(5)}`;
    });

    applyMapViewMode();
    applyMapZoomClass();
    updateParallelRouteButton();
    map.on('zoomend', () => {
        applyMapZoomClass();
        refreshRouteVisualGeometry();
    });
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
}

async function manageProjectSnapshots() {
    const projectId = document.getElementById('active-project-id').value;
    if (!projectId) return void (document.getElementById('cad-command').textContent = 'BACKUP: prvo odaberi projekat.');
    const overlay = document.getElementById('snapshot-overlay');
    const list = document.getElementById('snapshot-list');
    const status = document.getElementById('snapshot-status');
    overlay.classList.remove('hidden');
    list.innerHTML = '<div class="snapshot-empty">Učitavam sigurnosne kopije...</div>';
    status.style.display = 'none';
    const baseUrl = appConfig.projectSnapshotsBaseUrl.replace('__ID__', projectId);
    const response = await fetch(baseUrl, { headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' } });
    const payload = await readJsonResponse(response, 'Sigurnosne kopije nisu dostupne.');
    const snapshots = payload.versions || payload.snapshots || [];
    list.innerHTML = snapshots.length ? snapshots.map(snapshot => {
        const source = snapshot.source === 'automatic' ? 'Automatska' : 'Ručna';
        return `<div class="snapshot-row"><div><strong>${escapeHtml(snapshot.label)}</strong><small>${source} verzija · ${new Date(snapshot.created_at).toLocaleString('bs-BA')} · ${Number(snapshot.item_count || 0)} stavki</small></div><div class="snapshot-actions"><a class="snapshot-download" href="${baseUrl}/${snapshot.id}/download">JSON</a><button type="button" class="snapshot-restore" data-snapshot-id="${snapshot.id}">Vrati</button></div></div>`;
    }).join('') : '<div class="snapshot-empty">Još nema sačuvanih verzija projekta.</div>';
}

function initProjectVersionHistory() {
    document.getElementById('project-snapshot-btn').addEventListener('click', () => {
        manageProjectSnapshots().catch(error => { document.getElementById('cad-command').textContent = `VERZIJE: ${error.message}`; });
    });
    document.getElementById('snapshot-close').addEventListener('click', () => document.getElementById('snapshot-overlay').classList.add('hidden'));
    document.getElementById('snapshot-overlay').addEventListener('click', event => {
        if (event.target.id === 'snapshot-overlay') event.currentTarget.classList.add('hidden');
    });
    document.getElementById('snapshot-create').addEventListener('click', async () => {
        const projectId = document.getElementById('active-project-id').value;
        const baseUrl = appConfig.projectSnapshotsBaseUrl.replace('__ID__', projectId);
        const label = document.getElementById('snapshot-label').value.trim() || 'Ručna verzija';
        const response = await fetch(baseUrl, { method: 'POST', headers: { 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '', Accept: 'application/json', 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }, body: JSON.stringify({ label }) });
        await readJsonResponse(response, 'Verzija nije sačuvana.');
        await manageProjectSnapshots();
    });
    document.getElementById('snapshot-list').addEventListener('click', async event => {
        const button = event.target.closest('.snapshot-restore');
        const label = button?.closest('.snapshot-row')?.querySelector('strong')?.textContent || 'odabranu verziju';
        if (!button || !confirm(`Vratiti projekat na "${label}"? Trenutno stanje bit će zamijenjeno.`)) return;
        const projectId = document.getElementById('active-project-id').value;
        const baseUrl = appConfig.projectSnapshotsBaseUrl.replace('__ID__', projectId);
        const response = await fetch(`${baseUrl}/${button.dataset.snapshotId}/vrati`, { method: 'POST', headers: { 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '', Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' } });
        const result = await readJsonResponse(response, 'Projekat nije vraćen.');
        document.getElementById('cad-command').textContent = `${result.message} Osvježavam mapu...`;
        setTimeout(() => window.location.reload(), 700);
    });
}

function initProjectCheckControls() {
    const showError = error => { document.getElementById('project-check-summary').textContent = error.message; };
    document.getElementById('run-project-check').addEventListener('click', () => runProjectCheck().catch(showError));
}

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

    summary.textContent = 'Popunjavam nedostajuće drop trase...';
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

async function auditAndRepairDropRoutes() {
    const projectId = document.getElementById('active-project-id').value;
    const summary = document.getElementById('project-check-summary');
    if (!projectId) {
        summary.textContent = 'Prvo odaberi projekat.';
        return;
    }

    summary.textContent = 'Provjeravam da li drop trase prate fizički rov...';
    const auditResponse = await fetch(appConfig.projectDropAuditBaseUrl.replace('__ID__', projectId), {
        headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
    });
    const audit = await readJsonResponse(auditResponse, 'Audit drop-trasa nije uspio.');
    if (!audit.total) {
        summary.textContent = 'Nema spremljenih drop-trasa za provjeru.';
        return;
    }
    if (!audit.repairable) {
        summary.textContent = `${audit.unreachable} drop-trasa nema povezani fizički koridor. Dopuni rov prije popravke.`;
        return;
    }
    if (!window.confirm(`Pronađeno je ${audit.repairable} drop-trasa koje se mogu ponovo provući kroz fizički rov. Nastaviti?`)) {
        summary.textContent = 'Popravka nije pokrenuta.';
        return;
    }

    summary.textContent = 'Ponovo rutiram drop trase kroz fizički rov...';
    const response = await fetch(appConfig.projectDropRepairBaseUrl.replace('__ID__', projectId), {
        method: 'POST',
        headers: {
            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '',
            Accept: 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
        },
    });
    const result = await readJsonResponse(response, 'Popravka drop-trasa nije uspjela.');
    summary.textContent = `${result.message}${result.unreachable ? ` ${result.unreachable} nije moguće popraviti bez dodatnog rova.` : ''} Osvježavam mapu...`;
    setTimeout(() => window.location.reload(), 900);
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
        <span>${escapeHtml(item.message || '')}</span>
        <small>${escapeHtml(item.recommendation || '')}</small>
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

function focusRequestedMapElement() {
    const params = new URLSearchParams(window.location.search);
    const type = params.get('focus_type');
    const id = Number(params.get('focus_id'));
    if (!type || !id) return;
    requestAnimationFrame(() => {
        focusValidationItem({ element_type: type, element_id: id, message: 'Element otvoren iz kontrole projekta.' });
        const cleanUrl = new URL(window.location.href);
        cleanUrl.searchParams.delete('focus_type');
        cleanUrl.searchParams.delete('focus_id');
        window.history.replaceState({}, '', cleanUrl);
    });
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

function pickProject(id) {
    const url = new URL(window.location.href);
    url.searchParams.set('project', id);
    window.location.href = url.toString();
}

async function ppCreateProject() {
    const nameInput = document.getElementById('pp-new-name');
    const status = document.getElementById('pp-new-status');
    const submit = document.querySelector('.pp-new-submit');
    const name = nameInput.value.trim();
    if (!name) { status.textContent = 'Upiši naziv projekta.'; return; }
    status.textContent = 'Kreiram...';
    if (submit) { submit.disabled = true; submit.setAttribute('aria-busy', 'true'); }
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
        if (!response.ok) throw new Error(result.message || 'Projekat nije kreiran.');
        status.textContent = `${result.project.name} je kreiran. Učitavam...`;
        pickProject(result.project.id);
    } catch (err) {
        status.textContent = err.message;
        if (submit) { submit.disabled = false; submit.removeAttribute('aria-busy'); }
    }
}
