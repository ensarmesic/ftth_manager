// ── TRANSFORM TOOLS (Copy/Move/Rotate/Mirror/Scale/Array/Align) ──────────────
// Operate on the existing box-select `currentSelection`. Saved elements (route/odf/cabinet/house)
// persist via the same endpoints already used for drag-to-move and route vertex editing.
let xfPick = null; // { tool, pointsNeeded, points: [], onReady }

const xfPrompts = {
    move: ['POMJERI: klikni referentnu tačku.', 'POMJERI: klikni ciljnu tačku.'],
    copy: ['KOPIRAJ: klikni referentnu tačku.', 'KOPIRAJ: klikni ciljnu tačku.'],
    rotate: ['ROTIRAJ: klikni pivot tačku.'],
    mirror: ['ZRCALI: klikni prvu tačku ose zrcaljenja.', 'ZRCALI: klikni drugu tačku ose zrcaljenja.'],
    scale: ['SKALIRAJ: klikni pivot tačku.'],
    array: ['NIZ: klikni referentnu tačku.', 'NIZ: klikni tačku koja definiše razmak/pravac.'],
    align: ['PORAVNAJ: klikni referentnu tačku.', 'PORAVNAJ: klikni ciljnu tačku (snap na mrežu).'],
};

function xfSelectionTargets() {
    return currentSelection.filter(e => e.kind && e.id);
}

function xfStartPick(tool, pointsNeeded, onReady) {
    if (!xfSelectionTargets().length) {
        document.getElementById('cad-command').textContent = 'Selektuj bar jedan element prije transformacije.';
        return;
    }
    xfPick = { tool, pointsNeeded, points: [], onReady };
    document.getElementById('cad-command').textContent = xfPrompts[tool][0];
}

function xfCancelPick() {
    xfPick = null;
    hideXfValuePanel();
}

function xfHandleMapClick(latlng) {
    if (!xfPick) return;
    xfPick.points.push(latlng);
    if (xfPick.points.length < xfPick.pointsNeeded) {
        document.getElementById('cad-command').textContent = xfPrompts[xfPick.tool][xfPick.points.length];
        return;
    }
    const finished = xfPick;
    xfPick = null;
    finished.onReady(finished.points);
}

function showXfValuePanel(label, { twoInputs = false, label2 = '', defaultValue = '', default2 = '' } = {}) {
    document.getElementById('xf-value-label').textContent = label;
    const input = document.getElementById('xf-value-input');
    const input2 = document.getElementById('xf-value-input-2');
    input.value = defaultValue;
    input2.value = default2;
    input2.style.display = twoInputs ? 'block' : 'none';
    input2.placeholder = label2;
    document.getElementById('xf-value-panel').style.display = 'block';
    input.focus();
}
function hideXfValuePanel() {
    document.getElementById('xf-value-panel').style.display = 'none';
}

// ── Geometry accessors for selectable entries ────────────────────────────────
function xfCurrentPoints(entry) {
    if (entry.kind === 'route') {
        return (routeLayerById[entry.id]?.getLatLngs() || []).map(p => L.latLng(p.lat, p.lng));
    }
    return [entry.triggerLayer.getLatLng()];
}

async function xfApplyPointsToEntry(entry, points) {
    if (entry.kind === 'route') {
        const route = data.routes.find(r => r.id === entry.id);
        const csrf = document.querySelector('meta[name="csrf-token"]')?.content || '';
        const path = points.map(p => [Number(p.lat.toFixed(7)), Number(p.lng.toFixed(7))]);
        const length = distance(points);
        const response = await fetch(`${appConfig.routesBase}/${entry.id}/geometrija`, {
            method: 'PATCH',
            headers: {
                'X-CSRF-TOKEN': csrf,
                'Accept': 'application/json',
                'Content-Type': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
            },
            body: JSON.stringify({ path, duct_length_m: length, fiber_length_m: length }),
        });
        await readJsonResponse(response, 'Izmjena trase nije sačuvana.');
        routeLayerById[entry.id]?.setLatLngs(points);
        routeHitLayerById[entry.id]?.setLatLngs(points);
        if (route) {
            route.path = path;
            route.duct_length_m = length;
            refreshRouteLabels(route, routeLayerType(route.type));
        }
        return;
    }
    entry.triggerLayer.setLatLng(points[0]);
    await saveSavedPosition(entry.triggerLayer, positionUrls[entry.kind](entry.id));
}

async function xfTransformSelection(label, transformFn) {
    const targets = xfSelectionTargets();
    if (!targets.length) return;
    const before = targets.map(xfCurrentPoints);
    const after = before.map(points => points.map(transformFn));
    try {
        for (let i = 0; i < targets.length; i++) await xfApplyPointsToEntry(targets[i], after[i]);
    } catch (error) {
        document.getElementById('cad-command').textContent = `${label}: greška — ${error.message}`;
        for (let i = 0; i < targets.length; i++) {
            try { await xfApplyPointsToEntry(targets[i], before[i]); } catch { /* best effort */ }
        }
        return;
    }
    mapHistory.push({
        label,
        undo: async () => { for (let i = 0; i < targets.length; i++) await xfApplyPointsToEntry(targets[i], before[i]); },
        redo: async () => { for (let i = 0; i < targets.length; i++) await xfApplyPointsToEntry(targets[i], after[i]); },
    });
    document.getElementById('cad-command').textContent = `${label}: gotovo (${targets.length} element(a)).`;
}

// ── Draft copy creation (Copy / Array / Mirror-keep-original) ────────────────
function xfCreateDraftCopy(entry, points) {
    if (entry.kind === 'route') {
        const source = data.routes.find(r => r.id === entry.id) || {};
        const type = source.type || 'distribution';
        const name = nextRouteName(type);
        const meters = distance(points);
        branches.push(points.map(p => L.latLng(p.lat, p.lng)));
        branchMeta.push({
            name,
            route_type: type,
            from_type: null, from_id: null, to_type: null, to_id: null, cabinet_id: null,
            odf_index: null, odf_id: null,
            duct_length_m: meters,
            fiber_length_m: type === 'trench' ? 0 : meters,
            path: points.map(p => [Number(p.lat.toFixed(7)), Number(p.lng.toFixed(7))]),
        });
        const line = trackLayer(L.polyline(points, routeLineStyle(type))
            .bindPopup(`<b>${name}</b><br>${routeTypeLabel(type)}<br>${meters} m (kopija)`)
            .addTo(map), routeLayerType(type));
        if (type === 'trench') line.bringToBack();
        const index = branchLines.push(line) - 1;
        registerBranchContext(line);
        if (type !== 'trench') addRouteLabel(points, name);
        renderBranchList();
        refreshStats();
        return () => removeBranchAt(index);
    }
    if (entry.kind === 'odf') {
        const name = nextNumberedName('ODF', [...data.odfs.map(o => o.name), ...draftOdfs.map(o => o.name)]);
        const marker = L.marker(points[0], { icon: icon('odf', name), draggable: true })
            .addTo(map)
            .bindTooltip(`${name} · 0 FTTH`, { direction: 'top', offset: [0, -10] });
        const item = { marker, name, pending: false };
        marker.on('drag', () => { refreshDraftTooltips(); refreshPlanSummary(); });
        marker.on('click', () => { setActiveDraftOdf(draftOdfs.indexOf(item)); selectDraftElement('odf', item); });
        registerDraftContext(marker, name);
        draftElements.push({ type: 'odf', marker });
        draftOdfs.push(item);
        setActiveDraftOdf(draftOdfs.length - 1);
        refreshDraftTooltips();
        refreshPlanSummary();
        return () => removeDraftElement(marker);
    }
    if (entry.kind === 'cabinet') {
        draftCabinetCount++;
        const name = draftCabinetName(draftCabinetCount - 1);
        const odf = activeOdfPayload(points[0]);
        const marker = L.marker(points[0], { icon: icon('cabinet', name), draggable: true })
            .addTo(map)
            .bindTooltip('0/12', { direction: 'top', offset: [0, -10] });
        const item = { marker, name, odf_index: odf.odf_index, odf_id: odf.odf_id };
        marker.on('drag', () => refreshPlanSummary());
        marker.on('click', () => selectDraftElement('cabinet', item));
        registerDraftContext(marker, name);
        draftElements.push({ type: 'cabinet', marker });
        draftCabinets.push(item);
        refreshDraftTooltips();
        refreshPlanSummary();
        return () => removeDraftElement(marker);
    }
    if (entry.kind === 'house') {
        housePoints.push(points[0]);
        const marker = L.marker(points[0], { icon: icon('house'), draggable: true }).bindPopup(`Kuca ${housePoints.length}`).addTo(map);
        houseMarkerByKey[pointKey(points[0].lat, points[0].lng)] = marker;
        marker.on('drag', event => {
            const next = event.target.getLatLng();
            const index = houseMarkers.indexOf(marker);
            if (index >= 0) housePoints[index] = next;
            houseMarkerByKey[pointKey(next.lat, next.lng)] = marker;
            refreshStats();
        });
        registerHouseContext(marker);
        houseMarkers.push(marker);
        refreshStats();
        return () => removeDraftHouse(marker);
    }
    return () => {};
}

function xfCopySelection(dLat, dLng, label = 'Kopiraj') {
    const targets = xfSelectionTargets();
    if (!targets.length) return;
    const undoFns = targets.map(entry => {
        const points = xfCurrentPoints(entry).map(p => translateLatLng(p, dLat, dLng));
        return xfCreateDraftCopy(entry, points);
    });
    mapHistory.push({
        label,
        undo: () => undoFns.forEach(fn => fn()),
        redo: () => document.getElementById('cad-command').textContent = `${label}: redo nije podržan za kopije, ponovi akciju rucno.`,
    });
    document.getElementById('cad-command').textContent = `${label}: napravljeno ${targets.length} kopija (draft, potvrdi kroz Plan).`;
}

// ── Wiring ────────────────────────────────────────────────────────────────────
document.getElementById('xf-copy-btn')?.addEventListener('click', () => {
    xfStartPick('copy', 2, ([a, b]) => xfCopySelection(b.lat - a.lat, b.lng - a.lng, 'Kopiraj'));
});
document.getElementById('xf-move-btn')?.addEventListener('click', () => {
    xfStartPick('move', 2, ([a, b]) => xfTransformSelection('Pomjeri', p => translateLatLng(p, b.lat - a.lat, b.lng - a.lng)));
});
document.getElementById('xf-rotate-btn')?.addEventListener('click', () => {
    xfStartPick('rotate', 1, ([pivot]) => {
        xfPendingRotatePivot = pivot;
        showXfValuePanel('Ugao rotacije (°)', { defaultValue: '90' });
    });
});
document.getElementById('xf-mirror-btn')?.addEventListener('click', () => {
    xfStartPick('mirror', 2, ([a, b]) => {
        const keepOriginal = document.getElementById('xf-keep-original').checked;
        const targets = xfSelectionTargets();
        if (keepOriginal) {
            const undoFns = targets.map(entry => xfCreateDraftCopy(entry, xfCurrentPoints(entry).map(p => mirrorLatLng(a, b, p))));
            mapHistory.push({ label: 'Zrcali (kopija)', undo: () => undoFns.forEach(fn => fn()), redo: () => {} });
            document.getElementById('cad-command').textContent = `Zrcali: napravljeno ${targets.length} kopija (draft).`;
        } else {
            xfTransformSelection('Zrcali', p => mirrorLatLng(a, b, p));
        }
    });
});
document.getElementById('xf-scale-btn')?.addEventListener('click', () => {
    xfStartPick('scale', 1, ([pivot]) => {
        xfPendingScalePivot = pivot;
        showXfValuePanel('Faktor skaliranja', { defaultValue: '1.5' });
    });
});
document.getElementById('xf-array-btn')?.addEventListener('click', () => {
    xfStartPick('array', 2, ([a, b]) => {
        xfPendingArrayVector = { dLat: b.lat - a.lat, dLng: b.lng - a.lng };
        showXfValuePanel('Broj kopija', { defaultValue: '3' });
    });
});
document.getElementById('xf-align-btn')?.addEventListener('click', () => {
    xfStartPick('align', 2, ([a, b]) => {
        const snapped = getSnapTarget(b)?.latlng || b;
        xfTransformSelection('Poravnaj', p => translateLatLng(p, snapped.lat - a.lat, snapped.lng - a.lng));
    });
});

let xfPendingRotatePivot = null;
let xfPendingScalePivot = null;
let xfPendingArrayVector = null;

document.getElementById('xf-value-cancel')?.addEventListener('click', () => {
    hideXfValuePanel();
    xfPendingRotatePivot = null;
    xfPendingScalePivot = null;
    xfPendingArrayVector = null;
});
document.getElementById('xf-value-confirm')?.addEventListener('click', () => {
    const value = Number(document.getElementById('xf-value-input').value);
    if (xfPendingRotatePivot) {
        const pivot = xfPendingRotatePivot;
        xfPendingRotatePivot = null;
        hideXfValuePanel();
        if (Number.isFinite(value)) xfTransformSelection('Rotiraj', p => rotateLatLng(pivot, p, value));
        return;
    }
    if (xfPendingScalePivot) {
        const pivot = xfPendingScalePivot;
        xfPendingScalePivot = null;
        hideXfValuePanel();
        if (Number.isFinite(value) && value > 0) xfTransformSelection('Skaliraj', p => scaleLatLng(pivot, p, value));
        return;
    }
    if (xfPendingArrayVector) {
        const { dLat, dLng } = xfPendingArrayVector;
        xfPendingArrayVector = null;
        hideXfValuePanel();
        const count = Math.max(1, Math.round(value));
        for (let n = 1; n <= count; n++) xfCopySelection(dLat * n, dLng * n, 'Niz');
        return;
    }
});

map.on('click', e => xfHandleMapClick(e.latlng));

document.addEventListener('keydown', event => {
    const target = event.target;
    const tag = target?.tagName?.toLowerCase();
    if (['input', 'select', 'textarea'].includes(tag) || target?.isContentEditable) return;

    if (event.key === 'Escape' && (xfPick || document.getElementById('xf-value-panel').style.display === 'block')) {
        xfCancelPick();
        xfPendingRotatePivot = null;
        xfPendingScalePivot = null;
        xfPendingArrayVector = null;
        document.getElementById('cad-command').textContent = 'Transformacija otkazana.';
        return;
    }

    if (event.ctrlKey || event.metaKey || !xfSelectionTargets().length) return;

    const key = event.key.toLowerCase();
    const shortcuts = {
        k: 'xf-copy-btn',
        p: 'xf-move-btn',
        v: 'xf-rotate-btn',
        z: 'xf-mirror-btn',
        s: 'xf-scale-btn',
        n: 'xf-array-btn',
    };
    if (shortcuts[key]) {
        event.preventDefault();
        document.getElementById(shortcuts[key])?.click();
    }
});
