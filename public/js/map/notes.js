// Napomene na mapi: tačka, linija ili površina s tekstom šta treba izmijeniti.
// Piše i rješava ih svako ko može otvoriti projekat.
(function () {
    const panel = document.getElementById('notes-panel');
    if (!panel) return;

    const storeUrl = panel.dataset.storeUrl;
    const panelButton = document.getElementById('notes-panel-btn');
    const openCount = document.getElementById('notes-open-count');
    const hint = document.getElementById('notes-hint');
    const form = document.getElementById('notes-form');
    const formTitle = document.getElementById('notes-form-title');
    const kindInput = document.getElementById('notes-kind');
    const bodyInput = document.getElementById('notes-body');
    const listBox = document.getElementById('notes-list');
    const coordsInput = document.getElementById('notes-coords-input');
    const csrf = () => document.querySelector('meta[name="csrf-token"]')?.content || '';
    const toast = (text, tone = 'info') => window.ftthToast?.(text, tone);
    const defaultHint = hint.textContent;

    const COLOR_OPEN = '#e11d48';
    const COLOR_RESOLVED = '#16a34a';
    const SHAPE_NAMES = { point: 'Tačka', line: 'Linija', area: 'Površina' };

    let notes = [];
    let filter = 'open';
    const rendered = new Map(); // id -> { layers: [], focusLayer }

    // Crtanje nove napomene.
    let tool = null; // point | line | area
    let draftPoints = [];
    let draftGeometry = null; // { type, points } kad je oblik završen
    let editingId = null;
    const draftLayer = L.layerGroup().addTo(map);
    let rubberBand = null;

    // ── Panel ────────────────────────────────────────────────────────────────
    const setPanelOpen = (open) => {
        panel.style.display = open ? 'block' : 'none';
        panelButton?.setAttribute('aria-expanded', String(open));
        if (!open) stopTool();
    };
    panelButton?.addEventListener('click', () => setPanelOpen(getComputedStyle(panel).display === 'none'));
    document.getElementById('notes-panel-close')?.addEventListener('click', () => {
        resetForm();
        setPanelOpen(false);
    });

    // ── API ──────────────────────────────────────────────────────────────────
    async function request(url, method, body) {
        const response = await fetch(url, {
            method,
            headers: {
                Accept: 'application/json',
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': csrf(),
                'X-Requested-With': 'XMLHttpRequest',
            },
            body: body ? JSON.stringify(body) : undefined,
        });
        const payload = await response.json().catch(() => ({}));
        if (!response.ok) {
            const first = payload.errors ? Object.values(payload.errors)[0]?.[0] : null;
            throw new Error(first || payload.message || 'Napomena nije sačuvana.');
        }
        return payload;
    }

    async function load() {
        try {
            const payload = await request(storeUrl, 'GET');
            notes = payload.notes || [];
            renderAll();
        } catch (error) {
            listBox.innerHTML = `<div class="notes-empty">${escapeHtml(error.message)}</div>`;
        }
    }

    // ── Prikaz na mapi ───────────────────────────────────────────────────────
    const isResolved = (note) => note.status === 'resolved';
    const visibleByFilter = (note) => filter === 'all' || (filter === 'resolved' ? isResolved(note) : !isResolved(note));
    const formatDate = (iso) => (iso ? new Date(iso).toLocaleString('bs', { dateStyle: 'short', timeStyle: 'short' }) : '');
    const formatCoord = ([lat, lng]) => `${Number(lat).toFixed(6)}, ${Number(lng).toFixed(6)}`;

    function lengthMeters(points) {
        let total = 0;
        for (let i = 1; i < points.length; i++) total += map.distance(points[i - 1], points[i]);
        return total;
    }

    function shapeSummary(type, points) {
        if (type === 'point') return formatCoord(points[0]);
        if (type === 'line') return `${points.length} tačaka, ${Math.round(lengthMeters(points)).toLocaleString('bs')} m`;
        return `${points.length} uglova`;
    }

    function popupHtml(note) {
        const resolved = isResolved(note);
        const status = resolved
            ? `Riješeno${note.resolved_by ? ` (${escapeHtml(note.resolved_by)})` : ''} ${formatDate(note.resolved_at)}`
            : 'Otvoreno';
        return (
            `<div class="map-note-popup" data-note-id="${note.id}">` +
            `<b>Napomena #${note.id}</b> <span class="map-note-kind${resolved ? ' is-resolved' : ''}">${escapeHtml(note.kind_label)}</span>` +
            `<div class="map-note-body">${escapeHtml(note.body)}</div>` +
            `<div class="map-note-meta">${SHAPE_NAMES[note.geometry_type]}: ${escapeHtml(shapeSummary(note.geometry_type, note.geometry))}</div>` +
            `<div class="map-note-meta">${escapeHtml(note.author || 'Nepoznat korisnik')} · ${formatDate(note.created_at)}</div>` +
            `<div class="map-note-meta"><b>Status:</b> ${status}</div>` +
            '<div class="map-note-actions">' +
            (resolved
                ? '<button type="button" data-note-act="reopen">Ponovo otvori</button>'
                : '<button type="button" data-note-act="resolve">✓ Riješeno</button>') +
            '<button type="button" data-note-act="edit">Uredi</button>' +
            (note.geometry_type === 'point' ? '<button type="button" data-note-act="copy">Kopiraj koordinate</button>' : '') +
            '<button type="button" data-note-act="delete">Obriši</button>' +
            '</div></div>'
        );
    }

    function buildLayers(note) {
        const resolved = isResolved(note);
        const color = resolved ? COLOR_RESOLVED : COLOR_OPEN;
        const points = note.geometry;
        const layers = [];
        let focusLayer;
        if (note.geometry_type === 'point') {
            focusLayer = L.marker(points[0], {
                icon: L.divIcon({
                    className: `map-note-pin${resolved ? ' is-resolved' : ''}`,
                    iconSize: [24, 24],
                    iconAnchor: [3, 27],
                    popupAnchor: [9, -26],
                    html: `<span><b>${note.id}</b></span>`,
                }),
                zIndexOffset: 1000,
                keyboard: false,
            });
        } else {
            const style = {
                color,
                weight: 3,
                opacity: resolved ? 0.6 : 0.95,
                dashArray: '8 6',
                fillColor: color,
                fillOpacity: resolved ? 0.06 : 0.14,
            };
            focusLayer = note.geometry_type === 'area' ? L.polygon(points, style) : L.polyline(points, style);
            focusLayer.bindTooltip(`#${note.id}`, {
                permanent: true,
                direction: 'center',
                className: `map-note-tag${resolved ? ' is-resolved' : ''}`,
            });
        }
        focusLayer.bindPopup(popupHtml(note), { maxWidth: 300 });
        focusLayer.on('popupopen', (event) => bindPopupActions(event.popup, note.id));
        layers.push(focusLayer);
        return { layers, focusLayer };
    }

    function clearRendered() {
        rendered.forEach(({ layers }) =>
            layers.forEach((layer) => {
                untrackLayer(layer, 'notes');
                if (map.hasLayer(layer)) map.removeLayer(layer);
            }),
        );
        rendered.clear();
    }

    function renderAll() {
        clearRendered();
        notes.filter(visibleByFilter).forEach((note) => {
            const record = buildLayers(note);
            record.layers.forEach((layer) => trackLayer(layer, 'notes'));
            rendered.set(note.id, record);
        });
        updateLayerCount('notes');
        renderList();
        const open = notes.filter((note) => !isResolved(note)).length;
        if (openCount) {
            openCount.textContent = String(open);
            openCount.hidden = open === 0;
        }
    }

    function renderList() {
        const shown = notes.filter(visibleByFilter);
        if (!shown.length) {
            const empty = {
                open: 'Nema otvorenih napomena.',
                resolved: 'Nema riješenih napomena.',
                all: 'Još nema napomena u ovom projektu.',
            };
            listBox.innerHTML = `<div class="notes-empty">${empty[filter]}</div>`;
            return;
        }
        listBox.innerHTML = shown
            .map(
                (note) =>
                    `<button type="button" class="notes-row${isResolved(note) ? ' is-resolved' : ''}" data-note-id="${note.id}">` +
                    `<span class="notes-row-no">${note.id}</span>` +
                    `<span class="notes-row-body"><b>${escapeHtml(note.kind_label)}:</b> ${escapeHtml(note.body)}</span>` +
                    `<span class="notes-row-meta">${SHAPE_NAMES[note.geometry_type]} · ${escapeHtml(note.author || '')} · ${formatDate(note.created_at)}</span>` +
                    '</button>',
            )
            .join('');
    }

    function focusNote(id) {
        const record = rendered.get(id);
        if (!record) return;
        const layer = record.focusLayer;
        if (!map.hasLayer(layer)) {
            const toggle = document.querySelector('[data-layer-toggle="notes"]');
            if (toggle && !toggle.checked) toggle.click();
        }
        if (layer.getBounds) map.fitBounds(layer.getBounds().pad(0.3), { maxZoom: 19 });
        else map.setView(layer.getLatLng(), Math.max(map.getZoom(), 18));
        layer.openPopup();
    }

    listBox.addEventListener('click', (event) => {
        const row = event.target.closest('[data-note-id]');
        if (row) focusNote(Number(row.dataset.noteId));
    });

    panel.querySelectorAll('[data-note-filter]').forEach((button) =>
        button.addEventListener('click', () => {
            filter = button.dataset.noteFilter;
            panel
                .querySelectorAll('[data-note-filter]')
                .forEach((other) => other.classList.toggle('is-active', other === button));
            renderAll();
        }),
    );

    // ── Akcije iz popupa ─────────────────────────────────────────────────────
    function replaceNote(updated) {
        notes = notes.map((note) => (note.id === updated.id ? updated : note));
    }

    function bindPopupActions(popup, id) {
        const root = popup.getElement()?.querySelector('.map-note-popup');
        if (!root) return;
        root.onclick = async (event) => {
            const button = event.target.closest('button[data-note-act]');
            if (!button) return;
            const note = notes.find((item) => item.id === id);
            if (!note) return;
            const url = `${storeUrl}/${id}`;
            const act = button.dataset.noteAct;
            try {
                if (act === 'resolve' || act === 'reopen') {
                    const payload = await request(url, 'PATCH', { status: act === 'resolve' ? 'resolved' : 'open' });
                    replaceNote(payload.note);
                    map.closePopup();
                    renderAll();
                    toast(payload.message, 'success');
                } else if (act === 'edit') {
                    map.closePopup();
                    startEdit(note);
                } else if (act === 'copy') {
                    await navigator.clipboard?.writeText(formatCoord(note.geometry[0]));
                    toast('Koordinate kopirane.', 'success');
                } else if (act === 'delete') {
                    if (!window.confirm(`Obrisati napomenu #${id}?`)) return;
                    const payload = await request(url, 'DELETE');
                    notes = notes.filter((item) => item.id !== id);
                    map.closePopup();
                    renderAll();
                    toast(payload.message, 'success');
                }
            } catch (error) {
                toast(error.message, 'error');
            }
        };
    }

    // ── Crtanje ──────────────────────────────────────────────────────────────
    function setHint(text) {
        hint.textContent = text || defaultHint;
    }

    function drawDraft(cursor = null) {
        draftLayer.clearLayers();
        rubberBand = null;
        const points = draftGeometry ? draftGeometry.points : draftPoints;
        const type = draftGeometry ? draftGeometry.type : tool;
        if (!points.length) return;
        const style = { color: COLOR_OPEN, weight: 3, dashArray: '6 5', fillColor: COLOR_OPEN, fillOpacity: 0.12, interactive: false };
        if (type === 'point') {
            L.circleMarker(points[0], { radius: 8, color: '#fff', weight: 3, fillColor: COLOR_OPEN, fillOpacity: 1, interactive: false }).addTo(draftLayer);
            return;
        }
        const shape = cursor ? [...points, cursor] : points;
        if (type === 'area' && shape.length >= 3) L.polygon(shape, style).addTo(draftLayer);
        else if (shape.length >= 2) L.polyline(shape, style).addTo(draftLayer);
        points.forEach((point) =>
            L.circleMarker(point, { radius: 4, color: COLOR_OPEN, weight: 2, fillColor: '#fff', fillOpacity: 1, interactive: false }).addTo(draftLayer),
        );
    }

    function startTool(next) {
        if (editingId) resetForm();
        tool = next;
        draftPoints = [];
        draftGeometry = null;
        form.classList.remove('is-open');
        drawDraft();
        if (mode !== 'note') setMode('note');
        map.doubleClickZoom.disable();
        panel.classList.add('is-drawing');
        panel.querySelectorAll('[data-note-tool]').forEach((button) => button.classList.toggle('is-active', button.dataset.noteTool === next));
        const text = {
            point: 'Klikni mjesto na mapi.',
            line: 'Klikći tačke linije. Dupli klik, ENTER ili desni klik završava. ESC prekida.',
            area: 'Klikći uglove površine. Dupli klik, ENTER ili desni klik završava. ESC prekida.',
        }[next];
        setHint(text);
        const command = document.getElementById('cad-command');
        if (command) command.textContent = `NAPOMENA: ${text}`;
    }

    function stopTool() {
        tool = null;
        draftPoints = [];
        panel.classList.remove('is-drawing');
        if (!draftGeometry) draftLayer.clearLayers();
        map.doubleClickZoom.enable();
        panel.querySelectorAll('[data-note-tool]').forEach((button) => button.classList.remove('is-active'));
        if (!draftGeometry) setHint();
        if (mode === 'note') setMode('pan');
    }

    function finishShape() {
        if (!tool) return;
        const minimum = { point: 1, line: 2, area: 3 }[tool];
        if (draftPoints.length < minimum) {
            toast(tool === 'line' ? 'Linija treba najmanje dvije tačke.' : 'Površina treba najmanje tri tačke.', 'error');
            return;
        }
        draftGeometry = { type: tool, points: draftPoints.slice() };
        stopTool();
        drawDraft();
        openForm();
    }

    function openForm() {
        formTitle.textContent = editingId
            ? `Uredi napomenu #${editingId}`
            : `Nova napomena · ${SHAPE_NAMES[draftGeometry.type]}: ${shapeSummary(draftGeometry.type, draftGeometry.points)}`;
        document.getElementById('notes-redraw').hidden = Boolean(editingId);
        form.classList.add('is-open');
        setHint(editingId ? 'Izmijeni tekst ili vrstu pa sačuvaj.' : 'Upiši šta treba izmijeniti pa sačuvaj.');
        panel.style.display = 'block';
        bodyInput.focus();
    }

    function startEdit(note) {
        stopTool();
        draftGeometry = null;
        draftLayer.clearLayers();
        editingId = note.id;
        kindInput.value = note.kind;
        bodyInput.value = note.body;
        openForm();
    }

    function resetForm() {
        editingId = null;
        draftGeometry = null;
        form.reset();
        form.classList.remove('is-open');
        draftLayer.clearLayers();
        setHint();
    }

    panel.querySelectorAll('[data-note-tool]').forEach((button) =>
        button.addEventListener('click', () => (tool === button.dataset.noteTool ? stopTool() : startTool(button.dataset.noteTool))),
    );
    document.getElementById('notes-cancel').addEventListener('click', resetForm);
    document.getElementById('notes-redraw').addEventListener('click', () => {
        const type = draftGeometry?.type || 'point';
        draftGeometry = null;
        startTool(type);
    });

    function addFromCoordinates() {
        const parts = coordsInput.value.split(/[\s,;]+/).filter(Boolean).map(Number);
        const [lat, lng] = parts;
        if (parts.length < 2 || !Number.isFinite(lat) || !Number.isFinite(lng) || Math.abs(lat) > 90 || Math.abs(lng) > 180) {
            toast('Upiši koordinate kao: 44.4493, 18.6498', 'error');
            return;
        }
        if (editingId) resetForm();
        if (tool) stopTool();
        draftGeometry = { type: 'point', points: [[lat, lng]] };
        map.setView([lat, lng], Math.max(map.getZoom(), 18));
        drawDraft();
        openForm();
    }
    document.getElementById('notes-coords-add').addEventListener('click', addFromCoordinates);
    coordsInput.addEventListener('keydown', (event) => {
        if (event.key === 'Enter') {
            event.preventDefault();
            addFromCoordinates();
        }
    });

    form.addEventListener('submit', async (event) => {
        event.preventDefault();
        const body = bodyInput.value.trim();
        if (!body) return toast('Upiši tekst napomene.', 'error');
        try {
            let payload;
            if (editingId) {
                payload = await request(`${storeUrl}/${editingId}`, 'PATCH', { kind: kindInput.value, body });
                replaceNote(payload.note);
            } else {
                if (!draftGeometry) return toast('Prvo označi mjesto na mapi.', 'error');
                payload = await request(storeUrl, 'POST', {
                    geometry_type: draftGeometry.type,
                    geometry: draftGeometry.points.map(([lat, lng]) => [lat, lng]),
                    kind: kindInput.value,
                    body,
                });
                notes = [payload.note, ...notes];
                if (filter === 'resolved') {
                    filter = 'open';
                    panel.querySelectorAll('[data-note-filter]').forEach((b) => b.classList.toggle('is-active', b.dataset.noteFilter === 'open'));
                }
            }
            resetForm();
            coordsInput.value = '';
            renderAll();
            toast(payload.message, 'success');
        } catch (error) {
            toast(error.message, 'error');
        }
    });

    // Klikovi na mapu dok je alat aktivan hvataju se prije ostalih slojeva,
    // pa se napomena može staviti i preko ormara, stuba ili trase.
    const container = map.getContainer();
    let pressAt = null;
    const onMapSurface = (event) => !event.target.closest('.leaflet-control, .leaflet-popup');
    container.addEventListener(
        'mousedown',
        (event) => {
            pressAt = tool && onMapSurface(event) ? { x: event.clientX, y: event.clientY } : null;
        },
        true,
    );
    container.addEventListener(
        'click',
        (event) => {
            if (!tool || mode !== 'note' || !onMapSurface(event) || temporaryPanActive) return;
            event.stopPropagation();
            event.preventDefault();
            // Povlačenje mape nije klik.
            if (pressAt && Math.hypot(event.clientX - pressAt.x, event.clientY - pressAt.y) > 5) return;
            const latlng = map.mouseEventToLatLng(event);
            const point = [latlng.lat, latlng.lng];
            const last = draftPoints[draftPoints.length - 1];
            if (last && map.latLngToContainerPoint(last).distanceTo(map.latLngToContainerPoint(latlng)) < 4) return;
            draftPoints.push(point);
            if (tool === 'point') finishShape();
            else drawDraft();
        },
        true,
    );
    container.addEventListener(
        'dblclick',
        (event) => {
            if (!tool || mode !== 'note' || !onMapSurface(event)) return;
            event.stopPropagation();
            event.preventDefault();
            finishShape();
        },
        true,
    );
    container.addEventListener(
        'contextmenu',
        (event) => {
            if (!tool || mode !== 'note' || !onMapSurface(event)) return;
            event.stopPropagation();
            event.preventDefault();
            finishShape();
        },
        true,
    );
    map.on('mousemove', (event) => {
        if (!tool || tool === 'point' || !draftPoints.length) return;
        drawDraft([event.latlng.lat, event.latlng.lng]);
    });
    document.addEventListener('keydown', (event) => {
        if (!tool || event.key !== 'Enter') return;
        const tag = event.target?.tagName?.toLowerCase();
        if (['input', 'select', 'textarea'].includes(tag)) return;
        event.preventDefault();
        finishShape();
    });

    // ESC i drugi alati vraćaju mapu iz moda napomene; tada se prekida crtanje.
    const baseSetMode = setMode;
    setMode = function (next) {
        baseSetMode(next);
        if (next === 'note') {
            const contextTool = document.getElementById('map-context-tool');
            if (contextTool) contextTool.textContent = 'Napomena';
        } else if (tool) {
            tool = null;
            draftPoints = [];
            panel.classList.remove('is-drawing');
            if (!draftGeometry) draftLayer.clearLayers();
            map.doubleClickZoom.enable();
            panel.querySelectorAll('[data-note-tool]').forEach((button) => button.classList.remove('is-active'));
            if (!draftGeometry) setHint();
        }
    };

    window.ftthMapNotes = { count: () => notes.filter(visibleByFilter).length, focus: focusNote, reload: load };

    load();
})();
