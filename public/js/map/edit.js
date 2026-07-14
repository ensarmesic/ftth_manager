// ── ROUTE EDIT MODE ───────────────────────────────────────────────────────────
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
    routeLabelsById[route.id] = addRouteLabel(points, route.name, false, routeLabelSpecs(route), route._labelLane);
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
