// ── LAYER MANAGEMENT + HISTORY UTILS ─────────────────────────────────────────
function trackLayer(layer, type) {
    if (layerRegistry[type]) layerRegistry[type].push(layer);
    layer._ftthLayerType = type;
    if (layerOpacity[type] !== undefined) setLayerOpacity(layer, layerOpacity[type]);
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
function applyLayerOpacity(type, percent) {
    const opacity = Math.max(0, Math.min(100, Number(percent))) / 100;
    layerOpacity[type] = opacity;
    (layerRegistry[type] || []).forEach(layer => setLayerOpacity(layer, opacity));
}
function setLayerOpacity(layer, opacity) {
    if (typeof layer.setStyle === 'function') {
        layer.setStyle({ opacity, fillOpacity: opacity * 0.3 });
    } else if (typeof layer.setOpacity === 'function') {
        layer.setOpacity(opacity);
    }
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
    metrics.textContent = `Points: ${activeBranch.length} | Total: ${total}m | Segment: ${segment}m | Snap: ${snap || '-'} | ORTHO: ${orthoEnabled ? 'ON' : 'OFF'} | OSM: ${osmRoutingEnabled ? (osmRoutingLoading ? '...' : 'ON') : 'OFF'} | GIS: ${gisRoutingEnabled ? 'ON' : 'OFF'}`;
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
    if (u) u.disabled = mapHistory.busy || mapHistory.stack.length === 0;
    if (r) r.disabled = mapHistory.busy || mapHistory.histRedoStack.length === 0;
}
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
        trench: () => new Set([
            ...data.routes.filter(route => route.type === 'trench').map(route => route.trench_group || `route:${route.id}`),
            ...branchMeta.filter(route => route.route_type === 'trench').map((route, index) => route.trench_group || `draft:${index}`),
        ]).size,
        backbone: () => data.routes.filter(route => route.type === 'backbone').length + branchMeta.filter(route => route.route_type === 'backbone').length,
        distribution: () => data.routes.filter(route => !['trench', 'backbone', 'drop'].includes(route.type)).length + branchMeta.filter(route => !['trench', 'backbone', 'drop'].includes(route.route_type)).length,
        drop: () => data.routes.filter(route => route.type === 'drop').length,
        dxf: () => 0,
    };
    count.textContent = objectCounts[type] ? objectCounts[type]() : (layerRegistry[type]?.length || 0);
}
