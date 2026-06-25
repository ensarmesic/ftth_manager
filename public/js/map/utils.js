// ── UTILS ─────────────────────────────────────────────────────────────────────
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
function cabinetColor(id) { return cabinetPalette[(Math.max(Number(id) || 1, 1) - 1) % cabinetPalette.length]; }
function cabinetOccupancyColor(c) {
    const pct = (Number(c.used_ports) || 0) / Math.max(Number(c.capacity) || 1, 1);
    if (pct >= 1.0) return '#dc2626';
    if (pct >= 0.8) return '#ea580c';
    if (pct >= 0.6) return '#f59e0b';
    return '#16a34a';
}
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
function pointKey(lat, lng) { return `${Number(lat).toFixed(7)},${Number(lng).toFixed(7)}`; }
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
