// ── DRAW MODE + SNAP ─────────────────────────────────────────────────────────
function setMode(next) {
    if (routeEdit && next !== 'pan') cancelRouteEdit();
    if (mode === 'ruler' && next !== 'ruler') clearRuler();
    mode = next;
    if (next !== 'connect') connectOdf = null;
    if (next !== 'connect-houses') resetHouseConnect();
    if (next !== 'join') resetJoinRoutes();
    if (next !== 'draw') hideSnapIndicator();
    if (next !== 'split') hideSplitPreview();
    if (next !== 'trace-branch') {
        traceBranchStart = null;
        traceBranchStartSnap = null;
        if (traceBranchPreviewLine) { map.removeLayer(traceBranchPreviewLine); traceBranchPreviewLine = null; }
    }
    document.querySelectorAll('[id^="mode-"]').forEach(btn => btn.classList.remove('ring-2', 'ring-zinc-900'));
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
        'trace-branch': 'KRAK PO LINIJI: klikni početnu tačku na nacrtanoj liniji (rov ili trasa).',
        connect: 'CONNECT: odaberi ODF',
        'connect-houses': 'CONNECT HOUSES: odaberi ODO',
        trace: 'TRACE: klikni kuću za prikaz optičkog puta',
        join: 'JOIN: označi trase klikom, zatim pritisni ENTER',
        split: 'SPLIT: pomjeri miša na trasu i klikni gdje hoćeš da je podijeliš. ESC prekida.',
    };
    document.getElementById('cad-command').textContent = labels[next] ?? next.toUpperCase();
    updateCommandBar();
    if (next === 'select') {
        map.dragging.disable();
        document.getElementById('network-map').style.cursor = 'crosshair';
    } else {
        map.dragging.enable();
        document.getElementById('network-map').style.cursor = '';
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
    return data.routes[0].geometry.coordinates;
}

async function fetchGisRoute(from, to) {
    const projectId = document.getElementById('active-project-id')?.value || window.ftthMapConfig.projectId;
    if (!projectId) throw new Error('Nema aktivnog projekta.');
    const params = new URLSearchParams({
        project_id: projectId,
        from_lat: from.lat.toFixed(7),
        from_lng: from.lng.toFixed(7),
        to_lat: to.lat.toFixed(7),
        to_lng: to.lng.toFixed(7),
    });
    const response = await fetch(`${appConfig.mapAutoRoute}?${params.toString()}`, {
        headers: { Accept: 'application/json' },
        signal: AbortSignal.timeout(8000),
    });
    const result = await readJsonResponse(response, 'Interni GIS graf nije pronasao rutu.');
    if (!response.ok || !result.path?.length) throw new Error(result.message || 'Interni GIS graf nije pronasao rutu.');
    return result.path.map(p => [Number(p[1]), Number(p[0])]); // [[lng, lat], ...]
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
    if ((gisRoutingEnabled || osmRoutingEnabled) && activeBranch.length > 0) {
        osmRoutingLoading = true;
        updateCommandBar();
        document.getElementById('cad-command').textContent = 'Tražim rutu po ulicama...';
        const from = activeBranch[activeBranch.length - 1];
        try {
            const coords = gisRoutingEnabled ? await fetchGisRoute(from, point) : await fetchOsmRoute(from, point);
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
        const odfLabel = meta.odf_index === null || meta.odf_index === undefined ? 'bez ODF' : `ODF-${String(meta.odf_index + 1).padStart(2, '00')}`;
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

    let bestSec = null;
    [...savedRoutePoints, ...branches].forEach(route => {
        route.forEach((vertex, index) => {
            const d = layerPixelDistance(latlng, vertex);
            const label = index === 0 ? 'Početak trase' : (index === route.length - 1 ? 'Kraj trase' : 'Čvor trase');
            if (!bestSec || d < bestSec.distance) bestSec = { latlng: vertex, label, type: 'vertex', distance: d };
        });
        for (let i = 0; i < route.length - 1; i++) {
            const proj = projectOnSegment(latlng, route[i], route[i + 1]);
            const d = layerPixelDistance(latlng, proj);
            if (!bestSec || d < bestSec.distance) bestSec = { latlng: proj, label: 'Na trasi', type: 'segment', distance: d };
        }
    });
    return bestSec && bestSec.distance <= snapPixelTolerance ? bestSec : null;
}
// getSnapTarget() only reports a bare point/label — it never says WHICH route a
// 'segment'/'vertex' match came from. When two routes run close and parallel
// (a trench and a cable following the same street a metre apart), the trace
// graph would otherwise search "nearest route" all over again on its own and
// can silently pick the OTHER line. This re-derives the exact same winning
// route from getSnapTarget's own result so the trace always follows what the
// snap indicator actually shows the user.
function traceSnapTarget(latlng) {
    const snap = getSnapTarget(latlng);
    if (!snap || ['odf', 'cabinet', 'house'].includes(snap.type)) return snap ? { ...snap, source: null } : null;
    const sources = traceGraphSources();
    let best = null;
    sources.forEach(source => {
        const path = source.path.map(p => L.latLng(p[0], p[1]));
        for (let i = 1; i < path.length; i++) {
            const projected = projectOnSegment(snap.latlng, path[i - 1], path[i]);
            const distance = map.distance(snap.latlng, projected);
            if (!best || distance < best.distance) best = { source, segmentIndex: i, distance };
        }
    });
    return { ...snap, source: best?.source || null, segmentIndex: best?.segmentIndex };
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
// When picking the SECOND (end) point, prefer staying on the exact route the
// trace started from — only cross to a different one (e.g. a real T-junction
// where the branch visibly diverges) if the end click isn't reasonably close
// to the starting route at all. Without this, two routes running a metre or
// two apart (a trench and a cable following the same street) would make the
// end point silently "lane switch" onto whichever is a hair closer, even
// though the user never left the line they started tracing.
function resolveTraceEndPoint(clickLatlng, snappedPoint, snap, startSnap) {
    // A marker (ODF/ormarić/kuća) always wins — only prefer "stay on the same
    // route" when the click would otherwise resolve to an ambiguous route point.
    if (['odf', 'cabinet', 'house'].includes(snap?.type)) return { point: snappedPoint, hint: snap };
    if (startSnap?.source) {
        const sameSourceProjection = traceGraphProjection(clickLatlng, [startSnap.source]);
        if (sameSourceProjection && layerPixelDistance(clickLatlng, sameSourceProjection.point) <= snapPixelTolerance) {
            return { point: sameSourceProjection.point, hint: { source: startSnap.source, segmentIndex: sameSourceProjection.segmentIndex } };
        }
    }
    return { point: snappedPoint, hint: snap };
}
// When the map is showing "Svi projekti", data.odfs/data.cabinets includes
// markers from every project — snapping the trace's from/to link onto one of
// those would try to save a route with an ODF/ormarić id that doesn't belong
// to the project actually being saved, which the server correctly rejects.
function snapBelongsToActiveProject(snapItem) {
    if (!snapItem || !['odf', 'cabinet'].includes(snapItem.type)) return true;
    const activeProjectId = Number(document.getElementById('active-project-id')?.value || 0);
    if (!activeProjectId) return false;
    const list = snapItem.type === 'odf' ? data.odfs : data.cabinets;
    const match = list.find(entry => Number(entry.id) === Number(snapItem.id));
    return !match || Number(match.project_id) === activeProjectId;
}
function handleTraceBranchClick(latlng) {
    const snap = traceSnapTarget(latlng);
    const point = snap?.latlng || latlng;
    if (!traceBranchStart) {
        traceBranchStart = point;
        traceBranchStartSnap = snap;
        document.getElementById('cad-command').textContent = 'KRAK PO LINIJI: klikni krajnju tačku.';
        return;
    }
    const startPoint = traceBranchStart;
    const startSnap = traceBranchStartSnap;
    traceBranchStart = null;
    traceBranchStartSnap = null;
    if (traceBranchPreviewLine) { map.removeLayer(traceBranchPreviewLine); traceBranchPreviewLine = null; }

    const { point: endPoint, hint: endHint } = resolveTraceEndPoint(latlng, point, snap, startSnap);
    const path = shortestTracePath(startPoint, endPoint, startSnap, endHint) || networkPathBetween(startPoint, endPoint);
    if (!path || path.length < 2) {
        document.getElementById('cad-command').textContent = 'KRAK PO LINIJI: nije pronađena linija u blizini obe tačke.';
        return;
    }

    // Anchor from_type/from_id/odf_id on whichever end actually touched a real
    // ODF/ormarić (start takes priority), so the traced krak is really linked
    // to it — not just visually touching it. When the map shows "Svi
    // projekti", data.odfs/data.cabinets includes markers from OTHER projects
    // too — snapping to one of those would save an ODF/ormarić id that
    // doesn't belong to the project being saved and the plan save rejects it
    // ("ODF #X nije validan za ovaj projekat"). Only anchor on a marker that
    // actually belongs to the active project.
    const anchorSnap = snapBelongsToActiveProject(startSnap) && ['odf', 'cabinet'].includes(startSnap?.type)
        ? startSnap
        : (snapBelongsToActiveProject(snap) && ['odf', 'cabinet'].includes(snap?.type) ? snap : null);
    const startSource = document.getElementById('route-start-source');
    const originalStartSource = startSource.value;
    startSource.value = anchorSnap ? `${anchorSnap.type}:${anchorSnap.id}` : '';

    const endTarget = snapBelongsToActiveProject(snap) ? snap : null;
    activeBranch = path;
    activeBranchSnapTargets = path.map(() => null);
    activeBranchSnapTargets[activeBranchSnapTargets.length - 1] = endTarget || null;
    finishBranch();
    startSource.value = originalStartSource;
    document.getElementById('cad-command').textContent += ' Klikni novu početnu tačku za sljedeći krak ili promijeni alat.';
}
function nearestOdf(point) {
    return data.odfs.map(o => ({...o, distance: Math.round(map.distance(point, L.latLng(o.lat, o.lng)))})).sort((a,b) => a.distance-b.distance)[0] || null;
}
