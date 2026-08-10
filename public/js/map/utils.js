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
function escapeHtml(value) {
    return String(value ?? '').replace(/[&<>'"]/g, character => ({
        '&': '&amp;',
        '<': '&lt;',
        '>': '&gt;',
        "'": '&#039;',
        '"': '&quot;',
    })[character]);
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
    const name = String(route.name || '').toLowerCase();
    const f = route.fiber_count || route.fibers;
    if (f && !name.includes(`${f}f`)) parts.push(`${f}F`);
    const md = route.microduct_type || route.microduct;
    if (md && !name.includes(String(md).toLowerCase())) parts.push(md);
    return parts.length ? parts.join('·') : null;
}
function shouldShowPersistentRouteLabel(route) {
    if (route.type === 'trench') return false;
    return !String(route.note || '').toLowerCase().includes('geodetski snimak');
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
    const cls = type === 'odf' ? 'odf' : type === 'cabinet' ? 'cabinet' : type === 'suggest' ? 'suggest' : type === 'manhole' ? 'manhole' : type === 'boring' ? 'boring' : type === 'splice' ? 'splice' : type === 'loop' ? 'loop' : 'house';
    const style = color ? ` style="background:${color}"` : '';
    if (type === 'cabinet') {
        const match = String(text || '').trim().match(/^FTTH\s+(.+)$/i);
        const title = escapeHtml(match ? 'FTTH' : String(text || '').trim());
        const code = escapeHtml(match ? normalizeFtthDisplayCode(match[1]) : '');
        const html = code
            ? `<div class="ftth-tag ${cls} ftth-cabinet-tag"><span class="ftth-cabinet-symbol"${style}>ODO</span><span class="ftth-cabinet-text"><span class="ftth-cabinet-title">${title}</span><span class="ftth-cabinet-code">${code}</span></span></div>`
            : `<div class="ftth-tag ${cls} ftth-cabinet-tag"><span class="ftth-cabinet-symbol"${style}>ODO</span><span class="ftth-cabinet-text"><span class="ftth-cabinet-title">${title}</span></span></div>`;
        return L.divIcon({ className: 'ftth-label', html, iconSize: [2, 2], iconAnchor: [1, 1] });
    }
    if (cls === 'house' || cls === 'suggest') {
        return L.divIcon({ className: 'ftth-label ftth-house-icon', html: `<div class="ftth-tag ${cls}"${style}>${escapeHtml(text)}</div>`, iconSize: [20, 20], iconAnchor: [10, 10] });
    }
    return L.divIcon({ className: 'ftth-label', html: `<div class="ftth-tag ${cls}"${style}>${escapeHtml(text)}</div>`, iconSize: [2, 2], iconAnchor: [1, 1] });
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
// ── TRANSFORM GEOMETRY (local-cartesian, meters, same approach as metersOffset/toCartesian) ──
function toLocalMeters(origin, point) {
    const latRad = origin.lat * Math.PI / 180;
    return {
        x: (point.lng - origin.lng) * 111320 * Math.max(Math.cos(latRad), 0.00001),
        y: (point.lat - origin.lat) * 111320,
    };
}
function translateLatLng(point, dLat, dLng) {
    return L.latLng(point.lat + dLat, point.lng + dLng);
}
function rotateLatLng(pivot, point, angleDeg) {
    const { x, y } = toLocalMeters(pivot, point);
    const rad = angleDeg * Math.PI / 180;
    const cos = Math.cos(rad), sin = Math.sin(rad);
    const rx = x * cos - y * sin;
    const ry = x * sin + y * cos;
    return metersOffset(pivot, rx, ry);
}
function scaleLatLng(pivot, point, factor) {
    const { x, y } = toLocalMeters(pivot, point);
    return metersOffset(pivot, x * factor, y * factor);
}
function mirrorLatLng(a, b, point) {
    // Reflect `point` across the infinite line through a-b, all in local meters relative to a.
    const p = toLocalMeters(a, point);
    const d = toLocalMeters(a, b);
    const len2 = Math.max(0.000001, d.x * d.x + d.y * d.y);
    const t = (p.x * d.x + p.y * d.y) / len2;
    const projX = d.x * t, projY = d.y * t;
    const mx = 2 * projX - p.x;
    const my = 2 * projY - p.y;
    return metersOffset(a, mx, my);
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
function entryKind(url) {
    if (!url) return null;
    if (url.startsWith(appConfig.routesBase)) return 'route';
    if (url.startsWith(appConfig.odfsBase)) return 'odf';
    if (url.startsWith(appConfig.cabinetsBase)) return 'cabinet';
    if (url.startsWith(appConfig.housesBase)) return 'house';
    return null;
}
function entryId(url) {
    const id = parseInt(String(url || '').split('/').pop(), 10);
    return Number.isNaN(id) ? null : id;
}
function gisSegmentStyle(type) {
    const styles = {
        road: { color: '#0284c7', weight: 2.5, opacity: .75 },
        corridor: { color: '#16a34a', weight: 3, opacity: .7, dashArray: '8 6' },
        sidewalk: { color: '#0d9488', weight: 2, opacity: .65, dashArray: '4 5' },
    };
    return styles[type] || styles.road;
}
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
// Stvarne boje mikrocijevi iz geodetskog snimka (naziv rute "MC 14/10 Zelena"...)
const microductColorWords = {
    zelena: '#16a34a', crvena: '#dc2626', plava: '#2563eb', zuta: '#ca8a04',
    bjela: '#94a3b8', bijela: '#94a3b8', narandzasta: '#ea580c', ljubicasta: '#7c3aed', siva: '#64748b',
};
function microductRouteColor(route) {
    if (!route.name || !route.name.startsWith('MC ')) return null;
    const lower = route.name.toLowerCase();
    for (const word in microductColorWords) {
        if (lower.includes(word)) return microductColorWords[word];
    }
    return null;
}
function routeLineColor(route) {
    if (route.type === 'trench') return routeColor('trench');
    const surveyColor = microductRouteColor(route);
    if (surveyColor && !colorByFibers) return surveyColor;
    const fibers = route.fiber_count || route.fibers;
    if (colorByFibers && fibers) return fiberCountColor(fibers);
    return surveyColor || (route.cabinet_id ? cabinetColor(route.cabinet_id) : routeColor(route.type));
}
// Vise mikrocijevi dijeli istu putanju rova — svaku crtamo tanju od prethodne
// da se boje vide jedna unutar druge (poput presjeka cijevi).
function routeStackedWeight(route, baseWeight) {
    return Math.max(2, baseWeight + 1.5 - (route._stack || 0) * 2);
}
function applyRouteStacking(routes) {
    const seen = {};
    routes.forEach(route => {
        if (!route.path || route.path.length < 2 || route.type === 'trench') return;
        const key = JSON.stringify([route.path[0], route.path[route.path.length - 1], route.path.length]);
        route._stack = seen[key] || 0;
        seen[key] = route._stack + 1;
    });
}
// Ducts imported from a survey commonly leave the SAME physical point (the start of a
// shared trench) but peel off at different lengths, so applyRouteStacking's exact
// start+end+length match won't group them. Group by shared origin alone (~1m snap) so
// their map labels can be fanned into separate lanes — see ROUTE_LABEL_LANE_METERS in
// markers.js. Kept separate from _stack, which drives line THICKNESS and must stay
// limited to true full-path duplicates.
function applyRouteLabelLanes(routes) {
    const seen = {};
    routes.forEach(route => {
        if (!route.path || route.path.length < 2 || route.type === 'trench') return;
        const [lat, lng] = route.path[0];
        const key = `${lat.toFixed(5)},${lng.toFixed(5)}`;
        route._labelLane = seen[key] || 0;
        seen[key] = route._labelLane + 1;
    });
}

// Visually fan overlapping saved ducts into parallel lanes. Database geometry remains on
// the surveyed trench axis; only Leaflet display/hit layers use these offset points.
const ROUTE_VISUAL_MAX_SPREAD_METERS = 1.2;
const ROUTE_VISUAL_MAX_GAP_METERS = 0.25;
const ROUTE_VISUAL_ENDPOINT_TAPER_METERS = 4;
function routePathsOverlapForDisplay(first, second) {
    if (!first.path?.length || !second.path?.length) return false;
    if ((first.microduct_type || null) !== (second.microduct_type || null)) return false;
    if (first.cabinet_id && second.cabinet_id && Number(first.cabinet_id) !== Number(second.cabinet_id)) return false;
    const a = first.path.length <= second.path.length ? first.path : second.path;
    const b = first.path.length <= second.path.length ? second.path : first.path;
    let near = 0;
    for (const raw of a) {
        const point = L.latLng(raw[0], raw[1]);
        let best = Infinity;
        for (let i = 1; i < b.length; i++) {
            best = Math.min(best, map.distance(point, projectOnSegment(point, L.latLng(b[i - 1][0], b[i - 1][1]), L.latLng(b[i][0], b[i][1]))));
        }
        if (best <= 1.5 && ++near >= 2) return true;
    }
    return false;
}

function offsetRouteDisplayPoints(points, offsetM) {
    if (!offsetM || points.length < 2) return points.map(point => L.latLng(point.lat, point.lng));
    const travelled = [0];
    for (let index = 1; index < points.length; index++) {
        travelled[index] = travelled[index - 1] + map.distance(points[index - 1], points[index]);
    }
    const total = travelled[travelled.length - 1];
    return points.map((point, index) => {
        const before = points[Math.max(0, index - 1)];
        const after = points[Math.min(points.length - 1, index + 1)];
        const east = (after.lng - before.lng) * Math.cos(point.lat * Math.PI / 180);
        const north = after.lat - before.lat;
        const length = Math.hypot(east, north) || 1e-12;
        // House and cabinet coordinates stay exact. Parallel lanes open gradually on the
        // shared trench and close back into one physical point at the ZO.
        const taper = Math.min(
            1,
            travelled[index] / ROUTE_VISUAL_ENDPOINT_TAPER_METERS,
            (total - travelled[index]) / ROUTE_VISUAL_ENDPOINT_TAPER_METERS,
        );
        const taperedOffset = offsetM * Math.max(0, taper);
        return metersOffset(point, (-north / length) * taperedOffset, (east / length) * taperedOffset);
    });
}
function applyRouteVisualLanes(routes) {
    routes.forEach(route => { route._visualOffsetM = 0; });
    const candidates = routes.filter(route => route.type === 'drop' && route.path?.length > 1);
    const parent = candidates.map((_, index) => index);
    const find = index => parent[index] === index ? index : (parent[index] = find(parent[index]));
    const unite = (a, b) => { const ra = find(a); const rb = find(b); if (ra !== rb) parent[rb] = ra; };
    for (let i = 0; i < candidates.length; i++) {
        for (let j = i + 1; j < candidates.length; j++) {
            if (routePathsOverlapForDisplay(candidates[i], candidates[j])) unite(i, j);
        }
    }
    const groups = {};
    candidates.forEach((route, index) => (groups[find(index)] ||= []).push(route));
    Object.values(groups).forEach(group => {
        group.sort((first, second) => {
            const a = first.path[0];
            const b = second.path[0];
            return a[0] - b[0] || a[1] - b[1] || Number(first.id) - Number(second.id);
        });
        const middle = (group.length - 1) / 2;
        const gap = group.length > 1
            ? Math.min(ROUTE_VISUAL_MAX_GAP_METERS, ROUTE_VISUAL_MAX_SPREAD_METERS / (group.length - 1))
            : 0;
        group.forEach((route, index) => { route._visualOffsetM = (index - middle) * gap; });
    });
}
function refreshAllRouteStyles() {
    data.routes.forEach(route => {
        const line = routeLayerById[route.id];
        if (line?.setStyle) {
            const style = routeLineStyle(route.type, routeLineColor(route));
            if (route._stack) style.weight = routeStackedWeight(route, style.weight);
            line.setStyle(style);
        }
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
