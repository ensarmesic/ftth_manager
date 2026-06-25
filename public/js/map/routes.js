// ── ROUTES (CRUD, JOIN, SPLIT) ────────────────────────────────────────────────
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

        removeRouteFromMap(route.id);
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
