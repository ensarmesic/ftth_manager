// ── MARKERS, LABELS, BRANCH LIST ─────────────────────────────────────────────
function renderBranchList() {
    document.getElementById('route-branch-count').textContent = `${branchMeta.length} krakova`;
    document.getElementById('route-branch-list').innerHTML = branchMeta.length
        ? branchMeta.map(meta => {
            const odfLabel = meta.odf_index === null || meta.odf_index === undefined ? 'bez ODF' : `ODF-${String(meta.odf_index + 1).padStart(2, '0')}`;
            return `<div class="flex items-center justify-between rounded bg-white/80 px-2 py-1"><span>${meta.name} · ${routeTypeLabel(meta.route_type)} · ${odfLabel}</span><strong>${meta.duct_length_m} m</strong></div>`;
        }).join('')
        : '<div class="rounded bg-white/70 px-2 py-2 text-amber-800">Nema nacrtanih krakova.</div>';
}
function refreshRouteOdfStatus() {
    const status = document.getElementById('route-odf-status');
    status.textContent = activeDraftOdfIndex === null
        ? 'Krak nije vezan na ODF. Odaberi/postavi aktivni ODF prije crtanja.'
        : `Novi krakovi se vežu na ODF-${String(activeDraftOdfIndex + 1).padStart(2, '0')}.`;
}
function refreshTrenchGroupStatus() {
    const input = document.getElementById('route-draw-type');
    const status = document.getElementById('route-trench-status');
    if (!input || !status) return;
    const isTrench = input.value === 'trench';
    status.textContent = isTrench ? 'crtanje rova' : 'crtanje mikrocijevi';
    status.className = isTrench
        ? 'rounded bg-emerald-600 px-2 py-1 text-[10px] font-bold text-white'
        : 'rounded bg-white px-2 py-1 text-[10px] font-bold text-amber-800';
    ['route-draw-microducts', 'route-draw-microduct-type', 'route-draw-fiber-count'].forEach(id => {
        const field = document.getElementById(id);
        if (field) field.disabled = isTrench;
    });
}
function routeLabelPlacement(points, position = .5) {
    if (!points.length) return null;
    if (points.length === 1) return { latlng: points[0], angle: 0, perpEast: 0, perpNorth: 0 };
    const total = distance(points);
    const target = total * position;
    let walked = 0;
    for (let i = 1; i < points.length; i++) {
        const segment = map.distance(points[i - 1], points[i]);
        if (walked + segment >= target) {
            const ratio = segment ? (target - walked) / segment : 0;
            const latlng = L.latLng(
                points[i - 1].lat + (points[i].lat - points[i - 1].lat) * ratio,
                points[i - 1].lng + (points[i].lng - points[i - 1].lng) * ratio
            );
            const a = map.latLngToLayerPoint(points[i - 1]);
            const b = map.latLngToLayerPoint(points[i]);
            let angle = Math.atan2(b.y - a.y, b.x - a.x) * 180 / Math.PI;
            if (angle > 90 || angle < -90) angle += 180;

            // Direction of this segment in local east/north terms, rotated 90° to get
            // the perpendicular a stacked-route label can be pushed along.
            const dirEast = (points[i].lng - points[i - 1].lng) * Math.cos(latlng.lat * Math.PI / 180);
            const dirNorth = points[i].lat - points[i - 1].lat;
            const dirLen = Math.hypot(dirEast, dirNorth) || 1e-9;

            return { latlng, angle, perpEast: -dirNorth / dirLen, perpNorth: dirEast / dirLen };
        }
        walked += segment;
    }
    return { latlng: points[Math.floor(points.length / 2)], angle: 0, perpEast: 0, perpNorth: 0 };
}
// Same physical trench often carries several ducts along (near-)identical geometry —
// `lane` (see applyRouteStacking's route._stack) pushes each one's label a few metres
// off the line so overlapping duct labels don't render on top of each other.
const ROUTE_LABEL_LANE_METERS = 0.2;
function addRouteLabel(points, name, track = true, specs = null, lane = 0) {
    const labelName = normalizeRouteDisplayName(name);
    const specsText = showCableSpecs && specs ? ` <span style="opacity:.65;font-size:.85em">${specs}</span>` : '';
    const labelHtml = `${labelName}${specsText}`;
    const markers = [];
    const total = distance(points);
    const hash = [...String(name || '')].reduce((sum, char) => ((sum * 31) + char.charCodeAt(0)) >>> 0, 0);
    const stagger = ((hash % 5) - 2) * .07;
    const positions = total > 500
        ? [.2 + stagger / 2, .5 + stagger / 2, .8 + stagger / 2]
        : (total > 180 ? [.32 + stagger / 2, .68 + stagger / 2] : [.5 + stagger]);
    const laneOffsetM = Math.max(-0.6, Math.min(0.6, (lane || 0) * ROUTE_LABEL_LANE_METERS));
    positions.forEach(position => {
        const placement = routeLabelPlacement(points, position);
        if (!placement) return;
        const latlng = laneOffsetM
            ? metersOffset(placement.latlng, placement.perpEast * laneOffsetM, placement.perpNorth * laneOffsetM)
            : placement.latlng;
        const marker = L.marker(latlng, {
            interactive: false,
            keyboard: false,
            icon: L.divIcon({
                className: 'route-label',
                html: `<span style="transform: rotate(${placement.angle.toFixed(1)}deg)">${labelHtml}</span>`,
                iconAnchor: [12, 18],
        }),
    }).addTo(map);
        markers.push(marker);
    });
    if (track) {
        branchLabelGroups.push(markers);
        branchLabels.push(...markers);
    }
    return markers;
}
function normalizeRouteDisplayName(name) {
    return String(name || '').replace(/\b(Sekundarni krak|Primarni krak|Glavni rov)\s+(\d+(?:[.-]\d+)*)/gi, (full, prefix, code) => {
        return `${prefix} ${normalizeRouteCodeDisplay(code)}`;
    });
}
function normalizeRouteCodeDisplay(code) {
    const raw = String(code || '').trim();
    const match = raw.match(/^(.+)-(\d+)$/);
    if (match && match[1].includes('.')) {
        return `${normalizeBranchCode(match[1])}-${match[2]}`;
    }

    return normalizeBranchCode(raw);
}
