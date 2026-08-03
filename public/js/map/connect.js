// ── CONNECT + FIBER TRACE ─────────────────────────────────────────────────────
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
// Same graph/Dijkstra approach as above, but also includes not-yet-saved draft
// branches, so a krak can be auto-traced along a route that was just drawn
// (e.g. the "Krak po liniji" tool) and not only along already-saved routes.
function traceGraphSources() {
    const saved = data.routes
        .filter(route => route.type !== 'drop' && route.path?.length > 1)
        .map(route => ({ key: `saved:${route.id}`, path: route.path }));
    const drafts = branches
        .map((points, index) => ({ index, points }))
        .filter(({ index, points }) => points.length > 1 && branchMeta[index]?.route_type !== 'drop')
        .map(({ index, points }) => ({ key: `draft:${index}`, path: points.map(p => [p.lat, p.lng]) }));
    return [...saved, ...drafts];
}
// Two routes that meet at a T-junction rarely share pixel-identical coordinates
// (the branch was drawn/snapped independently of the trunk's exact vertex), so
// matching graph nodes by exact lat/lng breaks the connection right at the
// junction. Merge vertices that are within a couple of metres of each other
// into the same graph node instead. Only used for route-to-route topology
// edges — the click-point-to-projection edges keep exact keys untouched.
function traceGraphNodeKey(graph, point, toleranceMeters = 3) {
    const exactKey = networkNodeKey(point);
    if (graph.nodes[exactKey]) return exactKey;
    let bestKey = null, bestDistance = toleranceMeters;
    for (const key in graph.nodes) {
        const distance = map.distance(graph.nodes[key], point);
        if (distance <= bestDistance) { bestDistance = distance; bestKey = key; }
    }
    return bestKey || exactKey;
}
function addTraceGraphVertexEdge(graph, a, b) {
    const ak = traceGraphNodeKey(graph, a), bk = traceGraphNodeKey(graph, b), weight = map.distance(a, b);
    graph.nodes[ak] ??= a;
    graph.nodes[bk] ??= b;
    graph.edges[ak] ??= [];
    graph.edges[bk] ??= [];
    graph.edges[ak].push({ key: bk, weight });
    graph.edges[bk].push({ key: ak, weight });
}
function traceGraphProjection(point, sources) {
    let best = null;
    sources.forEach(source => {
        const path = source.path.map(p => L.latLng(p[0], p[1]));
        for (let i = 1; i < path.length; i++) {
            const projected = projectOnSegment(point, path[i - 1], path[i]);
            const distance = map.distance(point, projected);
            if (!best || distance < best.distance) best = { source, point: projected, distance, segmentIndex: i };
        }
    });
    return best;
}
function buildTraceGraph(fromPoint, toPoint, fromHint = null, toHint = null) {
    const sources = traceGraphSources();
    // If the caller already knows exactly which route the point snapped to
    // (e.g. from traceSnapTarget), trust that instead of re-searching for the
    // globally nearest route — two routes running a metre apart would
    // otherwise make this pick whichever one is a hair closer, regardless of
    // which line the user actually clicked on.
    const fromProjection = fromHint?.source ? { source: fromHint.source, point: fromPoint, segmentIndex: fromHint.segmentIndex } : traceGraphProjection(fromPoint, sources);
    const toProjection = toHint?.source ? { source: toHint.source, point: toPoint, segmentIndex: toHint.segmentIndex } : traceGraphProjection(toPoint, sources);
    const graph = { nodes: {}, edges: {}, startKey: networkNodeKey(fromPoint), endKey: networkNodeKey(toPoint) };
    const insertsBySource = {};
    if (fromProjection) {
        insertsBySource[fromProjection.source.key] ??= [];
        insertsBySource[fromProjection.source.key].push({ ...fromProjection, sourcePoint: fromPoint, sourceKey: graph.startKey });
    }
    if (toProjection) {
        insertsBySource[toProjection.source.key] ??= [];
        insertsBySource[toProjection.source.key].push({ ...toProjection, sourcePoint: toPoint, sourceKey: graph.endKey });
    }
    sources.forEach(source => {
        const path = source.path.map(p => L.latLng(p[0], p[1]));
        for (let i = 1; i < path.length; i++) {
            const segmentPoints = [
                { point: path[i - 1], t: 0 },
                { point: path[i], t: 1 },
                ...(insertsBySource[source.key] || [])
                    .filter(insert => insert.segmentIndex === i)
                    .map(insert => ({ point: insert.point, t: 0.5, sourcePoint: insert.sourcePoint, sourceKey: insert.sourceKey })),
            ].sort((a, b) => a.t - b.t);
            for (let j = 1; j < segmentPoints.length; j++) addTraceGraphVertexEdge(graph, segmentPoints[j - 1].point, segmentPoints[j].point);
            segmentPoints.filter(item => item.sourcePoint).forEach(item => addNetworkEdge(graph, item.sourcePoint, item.point));
        }
    });
    return graph;
}
function shortestTracePath(fromPoint, toPoint, fromHint = null, toHint = null) {
    const graph = buildTraceGraph(fromPoint, toPoint, fromHint, toHint);
    const distances = { [graph.startKey]: 0 };
    const previous = {};
    const queue = new Set([graph.startKey, graph.endKey, ...Object.keys(graph.nodes)]);
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
    if (!previous[graph.endKey] && graph.startKey !== graph.endKey) return null;
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
        output.innerHTML = `<div class="rounded-md bg-red-50 p-3 text-red-700">Kuća ${house.label} nema kompletnu vezu do FTTH ormarića i ODF-a.</div>`;
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
        <div class="rounded-md bg-white p-2"><b>${house.label}</b><br>Kuća</div>
        <div class="text-center font-black text-slate-500">↓</div>
        ${supplyChain.map(item => `<div class="rounded-md bg-white p-2"><b>${item.name}</b><br>FTTH ormarić</div>`).join('<div class="text-center font-black text-slate-500">↓</div>')}
        <div class="text-center font-black text-slate-500">↓</div>
        <div class="rounded-md bg-white p-2"><b>${odf.name}</b><br>ODF</div>
        ${warning}
    `;
    const bounds = L.latLngBounds([housePoint, odfPoint, ...supplyChain.map(item => L.latLng(item.lat, item.lng))]);
    physical.forEach(line => line.getLatLngs().forEach(point => bounds.extend(point)));
    map.fitBounds(bounds, { padding: [70, 70], maxZoom: 19 });
}
