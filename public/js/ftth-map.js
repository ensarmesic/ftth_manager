(function () {
    const MapEditor = {
        map: null,
        activeTool: 'select',
        selectedRoute: null,
        selectedElement: null,
        pendingRoute: null,
        moving: null,
        dirty: false,
        routeLayers: new Map(),
        snapTargets: [],
        layers: {},
        drawing: { points: [], polyline: null, tempLine: null, nodes: [] },
        measure: { points: [], polyline: null, tempLine: null, nodes: [] },
        editing: { route: null, originalPoints: [], points: [], line: null, outline: null, nodes: [] },
    };
    const colors = { feeder: '#2563eb', main: '#2563eb', distribution: '#16a34a', secondary: '#f97316', drop: '#9333ea', temporary: '#64748b' };
    const data = () => window.ftthData || { odfs: [], cabinets: [], houses: [], routes: [] };
    const config = () => window.ftthConfig || {};
    const status = () => document.getElementById('map-status');
    const details = () => document.getElementById('detail-body');
    const heading = () => document.getElementById('detail-heading');

    function initMap() {
        MapEditor.map = L.map('dashboard-map', { zoomControl: true, doubleClickZoom: false }).setView([44.437, 18.882], 15);
        const satellite = L.tileLayer('https://server.arcgisonline.com/ArcGIS/rest/services/World_Imagery/MapServer/tile/{z}/{y}/{x}', { maxZoom: 19, attribution: 'Tiles &copy; Esri' }).addTo(MapEditor.map);
        const streets = L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', { maxZoom: 19, attribution: '&copy; OpenStreetMap' });
        initLayers();
        L.control.layers({ Satelit: satellite, Ulice: streets }, {
            ODF: MapEditor.layers.odfs, 'ODO ormarici': MapEditor.layers.cabinets, Kuce: MapEditor.layers.houses,
            Trase: MapEditor.layers.routes, Cvorovi: MapEditor.layers.routeNodes, Mjerenje: MapEditor.layers.measure,
        }).addTo(MapEditor.map);
        L.control.scale({ imperial: false, position: 'bottomright' }).addTo(MapEditor.map);
        MapEditor.map.on('click', onMapClick).on('mousemove', onMouseMove).on('dblclick', onDoubleClick);
        document.addEventListener('keydown', onKeyDown);
        window.addEventListener('beforeunload', (event) => { if (!MapEditor.dirty) return; event.preventDefault(); event.returnValue = ''; });
    }
    function initLayers() {
        ['odfs', 'cabinets', 'houses', 'routeOutlines', 'routes', 'routeNodes', 'drawing', 'measure', 'snap'].forEach((name) => {
            MapEditor.layers[name] = L.layerGroup().addTo(MapEditor.map);
        });
    }
    function initToolbar() {
        document.querySelectorAll('.tool[data-tool]').forEach((button) => button.addEventListener('click', (event) => {
            event.preventDefault();
            setActiveTool(button.dataset.tool);
        }));
        document.querySelector('[data-action="save-edit"]')?.addEventListener('click', saveEditedRoute);
        document.querySelector('[data-action="cancel-edit"]')?.addEventListener('click', cancelEditedRoute);
        document.querySelector('[data-action="save-position"]')?.addEventListener('click', saveMarkerPosition);
        document.querySelector('[data-action="cancel-position"]')?.addEventListener('click', cancelMarkerPosition);
        document.querySelectorAll('[data-action="close-route-modal"]').forEach((button) => button.addEventListener('click', closeRouteModal));
        document.getElementById('route-form')?.addEventListener('submit', saveRouteForm);
    }
    function setActiveTool(tool) {
        if (MapEditor.dirty && tool !== MapEditor.activeTool && !confirm('Imas nespremljene izmjene. Odbaciti ih?')) return;
        if (tool !== 'draw_route') cancelDrawingRoute();
        if (tool !== 'measure') cancelMeasure();
        if (tool !== 'edit_route') cancelEditedRoute();
        MapEditor.activeTool = tool;
        document.querySelectorAll('.tool').forEach((button) => button.classList.toggle('active', button.dataset.tool === tool));
        const container = document.getElementById('dashboard-map');
        [...container.classList].filter((name) => name.startsWith('tool-')).forEach((name) => container.classList.remove(name));
        container.classList.add(`tool-${tool}`);
        setStatus(toolInstruction(tool));
    }
    function toolInstruction(tool) {
        return {
            select: 'Odaberi element na mapi.',
            add_odf: 'Klikni na mapu za novu ODF lokaciju.',
            add_odo: 'Klikni na mapu za novi ODO ormaric.',
            add_customer: 'Klikni na mapu za novu kucu ili prikljucak.',
            draw_route: 'Klikni prvu tacku trase. Double click zavrsava. ESC prekida. Backspace uklanja zadnju tacku.',
            edit_route: 'Klikni trasu za uredjivanje geometrije.',
            delete_node: 'Uredi trasu, zatim klikni lomnu tacku koju zelis obrisati.',
            measure: 'Klikni tacke za mjerenje. Double click zavrsava. ESC brise mjerenje.',
            delete_element: 'Klikni trasu koju zelis obrisati.',
        }[tool] || 'Odaberi alat.';
    }
    function setStatus(message, points = null, length = null, snap = null) {
        if (!status()) return;
        const extras = [];
        if (points !== null) extras.push(`Tacke: ${points}`);
        if (length !== null) extras.push(`Duzina: ${length} m`);
        if (snap !== null) extras.push(`Snap: ${snap || 'nema'}`);
        status().textContent = `Alat: ${MapEditor.activeTool} | ${message}${extras.length ? ` | ${extras.join(' | ')}` : ''}`;
    }
    function renderAll() { renderOdfs(); renderCabinets(); renderHouses(); renderRoutes(); fitMapToData(); }
    function markerIcon(type, label) { return L.divIcon({ className: 'ftth-marker', html: `<div class="marker-box ${type}-box">${label}</div>`, iconSize: [36, 36], iconAnchor: [18, 18] }); }
    function renderOdfs() { data().odfs.filter(validPoint).forEach((item) => addAssetMarker(item, 'odf', MapEditor.layers.odfs)); }
    function renderCabinets() { data().cabinets.filter(validPoint).forEach((item) => addAssetMarker(item, 'odo', MapEditor.layers.cabinets)); }
    function renderHouses() { data().houses.filter(validPoint).forEach((item) => addAssetMarker(item, 'house', MapEditor.layers.houses)); }
    function addAssetMarker(item, type, layer) {
        const labels = { odf: 'ODF', odo: 'ODO', house: 'K' };
        const marker = L.marker([item.lat, item.lng], { icon: markerIcon(type === 'house' ? 'house' : type, labels[type]) }).addTo(layer);
        item.marker = marker;
        marker.bindTooltip(item.name || item.label || labels[type]).on('click', () => showDetails({ ...item, elementType: type, marker }));
        MapEditor.snapTargets.push({ latlng: marker.getLatLng(), label: item.name || item.label || labels[type] });
    }
    function renderRoutes() { data().routes.filter((route) => normalizePoints(route.path).length > 1).forEach(drawStyledRoute); }
    function drawStyledRoute(route) {
        const coords = normalizePoints(route.path), color = routeColor(route.type), weight = route.type === 'feeder' ? 5 : 4;
        const outline = L.polyline(coords, { color: '#fff', weight: weight + 4, opacity: .94, lineCap: 'round', lineJoin: 'round', interactive: false }).addTo(MapEditor.layers.routeOutlines);
        const line = L.polyline(coords, { color, weight, lineCap: 'round', lineJoin: 'round' }).addTo(MapEditor.layers.routes);
        const nodes = coords.map((point, index) => addViewNode(route, point, index, color));
        line.bindTooltip(`${route.name} - ${route.length || calculateLength(coords)} m`);
        line.on('mouseover', () => line.setStyle({ weight: weight + 2 })).on('mouseout', () => line.setStyle({ weight }));
        line.on('click', (event) => { L.DomEvent.stopPropagation(event); handleRouteClick(route, event.latlng); });
        MapEditor.routeLayers.set(route.id, { outline, line, nodes });
        coords.forEach((point, index) => MapEditor.snapTargets.push({ latlng: L.latLng(point), label: `${route.name} T${index + 1}` }));
        return line;
    }
    function addViewNode(route, point, index, color) {
        return L.circleMarker(point, { radius: 4, color, weight: 2, fillColor: '#fff', fillOpacity: 1 }).addTo(MapEditor.layers.routeNodes)
            .on('click', (event) => { if (MapEditor.activeTool === 'delete_node' && MapEditor.editing.route?.id === route.id) { L.DomEvent.stopPropagation(event); deleteRouteNode(index); } });
    }
    function handleRouteClick(route, latlng) {
        if (MapEditor.activeTool === 'delete_element') return confirmDeleteRoute(route);
        if (MapEditor.activeTool === 'edit_route') {
            if (MapEditor.editing.route?.id === route.id) return insertNodeOnSegment(latlng);
            return startEditingRoute(route);
        }
        showDetails({ ...route, elementType: 'route' });
    }
    function onMapClick(event) {
        const snapped = applySnap(event.latlng);
        if (MapEditor.activeTool === 'draw_route') return addDrawingPoint(snapped.latlng);
        if (MapEditor.activeTool === 'measure') return addMeasurePoint(snapped.latlng);
        if (MapEditor.activeTool === 'add_odf') return addOdf(snapped.latlng);
        if (MapEditor.activeTool === 'add_odo') return addOdo(snapped.latlng);
        if (MapEditor.activeTool === 'add_customer') return addHouse(snapped.latlng);
    }
    function onMouseMove(event) {
        const snapped = applySnap(event.latlng);
        showSnapIndicator(snapped);
        if (MapEditor.activeTool === 'draw_route' && MapEditor.drawing.points.length) { updateTempLine(MapEditor.drawing, snapped.latlng, MapEditor.layers.drawing); setStatus('Double click za zavrsetak.', MapEditor.drawing.points.length, calculateLength([...MapEditor.drawing.points, [snapped.latlng.lat, snapped.latlng.lng]]), snapped.label); }
        if (MapEditor.activeTool === 'measure' && MapEditor.measure.points.length) { updateTempLine(MapEditor.measure, snapped.latlng, MapEditor.layers.measure); setStatus('Mjerenje aktivno. ESC brise.', MapEditor.measure.points.length, calculateLength([...MapEditor.measure.points, [snapped.latlng.lat, snapped.latlng.lng]]), snapped.label); }
    }
    function onDoubleClick(event) {
        event.originalEvent?.preventDefault();
        if (MapEditor.activeTool === 'draw_route') finishDrawingRoute();
        if (MapEditor.activeTool === 'measure') finishMeasure();
    }
    function addDrawingPoint(latlng) {
        MapEditor.drawing.points.push([latlng.lat, latlng.lng]); MapEditor.dirty = true; redrawTemporary(MapEditor.drawing, MapEditor.layers.drawing, colors.temporary);
        setStatus('Double click za zavrsetak.', MapEditor.drawing.points.length, calculateLength(MapEditor.drawing.points));
    }
    function updateTempLine(state, latlng, layer) {
        if (state.tempLine) layer.removeLayer(state.tempLine);
        state.tempLine = L.polyline([state.points.at(-1), [latlng.lat, latlng.lng]], { color: colors.temporary, weight: 2, dashArray: '6,6' }).addTo(layer);
    }
    function redrawTemporary(state, layer, color) {
        if (state.polyline) layer.removeLayer(state.polyline);
        state.nodes.forEach((node) => layer.removeLayer(node));
        state.nodes = state.points.map((point) => L.circleMarker(point, { radius: 5, color, weight: 2, fillColor: '#fff', fillOpacity: 1 }).addTo(layer));
        state.polyline = L.polyline(state.points, { color, weight: 3, dashArray: '6,6' }).addTo(layer);
    }
    async function finishDrawingRoute() {
        if (MapEditor.drawing.points.length < 2) return showToast('Trasa mora imati najmanje dvije tacke.', 'error');
        openRouteModal({ path: [...MapEditor.drawing.points] });
    }
    function cancelDrawingRoute() { clearState(MapEditor.drawing, MapEditor.layers.drawing); MapEditor.dirty = Boolean(MapEditor.editing.route); }
    function removeLastRoutePoint() { MapEditor.drawing.points.pop(); redrawTemporary(MapEditor.drawing, MapEditor.layers.drawing, colors.temporary); setStatus('Double click za zavrsetak.', MapEditor.drawing.points.length, calculateLength(MapEditor.drawing.points)); }
    function addMeasurePoint(latlng) { MapEditor.measure.points.push([latlng.lat, latlng.lng]); redrawTemporary(MapEditor.measure, MapEditor.layers.measure, '#facc15'); setStatus('Mjerenje aktivno. ESC brise.', MapEditor.measure.points.length, calculateLength(MapEditor.measure.points)); }
    function finishMeasure() { setStatus('Mjerenje zavrseno. ESC brise.', MapEditor.measure.points.length, calculateLength(MapEditor.measure.points)); }
    function cancelMeasure() { clearState(MapEditor.measure, MapEditor.layers.measure); }
    function clearState(state, layer) { layer.clearLayers(); state.points = []; state.nodes = []; state.polyline = null; state.tempLine = null; }
    function startEditingRoute(route) {
        cancelEditedRoute(); const current = normalizePoints(route.path); if (current.length < 2) return;
        MapEditor.editing.route = route; MapEditor.editing.originalPoints = current.map((x) => [...x]); MapEditor.editing.points = current.map((x) => [...x]);
        MapEditor.routeLayers.get(route.id)?.line.setStyle({ weight: 7 }); renderEditGeometry(); document.getElementById('map-edit-actions')?.classList.remove('hidden');
        setStatus('Povuci tacku ili klikni segment za novu tacku. Sacuvaj kada zavrsis.', current.length, calculateLength(current));
    }
    function renderEditGeometry() {
        const edit = MapEditor.editing; MapEditor.layers.drawing.clearLayers();
        edit.outline = L.polyline(edit.points, { color: '#fff', weight: 9 }).addTo(MapEditor.layers.drawing);
        edit.line = L.polyline(edit.points, { color: routeColor(edit.route.type), weight: 5 }).addTo(MapEditor.layers.drawing);
        edit.nodes = edit.points.map((point, index) => L.marker(point, { draggable: true, icon: L.divIcon({ className: 'route-node', iconSize: [14, 14], iconAnchor: [7, 7] }) }).addTo(MapEditor.layers.drawing)
            .on('drag', (event) => { edit.points[index] = [event.latlng.lat, event.latlng.lng]; edit.outline.setLatLngs(edit.points); edit.line.setLatLngs(edit.points); MapEditor.dirty = true; setStatus('Nespremljene izmjene.', edit.points.length, calculateLength(edit.points)); })
            .on('click', () => { if (MapEditor.activeTool === 'delete_node') deleteRouteNode(index); }));
    }
    function insertNodeOnSegment(latlng) {
        const points = MapEditor.editing.points; let bestIndex = 1, bestDistance = Infinity;
        for (let index = 1; index < points.length; index++) { const distance = segmentDistance(latlng, points[index - 1], points[index]); if (distance < bestDistance) { bestDistance = distance; bestIndex = index; } }
        points.splice(bestIndex, 0, [latlng.lat, latlng.lng]); MapEditor.dirty = true; renderEditGeometry();
    }
    function deleteRouteNode(index) { if (MapEditor.editing.points.length <= 2) return showToast('Trasa mora zadrzati najmanje dvije tacke.', 'error'); MapEditor.editing.points.splice(index, 1); MapEditor.dirty = true; renderEditGeometry(); }
    async function saveEditedRoute() {
        const edit = MapEditor.editing; if (!edit.route) return;
        const response = await request(config().routeGeometryUrl.replace('__ID__', edit.route.id), 'PATCH', { path: edit.points });
        if (!response?.route) return; edit.route.path = response.route.path; edit.route.length = response.route.length; rerenderRoutes(); cancelEditedRoute(); showToast('Izmjene trase su sacuvane.', 'success');
    }
    function cancelEditedRoute() { MapEditor.layers.drawing?.clearLayers(); MapEditor.routeLayers.get(MapEditor.editing.route?.id)?.line.setStyle({ weight: 4 }); MapEditor.editing = { route: null, originalPoints: [], points: [], line: null, outline: null, nodes: [] }; MapEditor.dirty = false; document.getElementById('map-edit-actions')?.classList.add('hidden'); }
    function rerenderRoutes() { MapEditor.layers.routeOutlines.clearLayers(); MapEditor.layers.routes.clearLayers(); MapEditor.layers.routeNodes.clearLayers(); MapEditor.routeLayers.clear(); renderRoutes(); }
    async function confirmDeleteRoute(route) { if (!confirm(`Obrisati trasu "${route.name}"?`)) return; const response = await request(config().routeDeleteUrl.replace('__ID__', route.id), 'DELETE'); if (!response) return; data().routes = data().routes.filter((item) => item.id !== route.id); window.ftthData.routes = data().routes; rerenderRoutes(); showToast('Trasa je obrisana.', 'success'); }
    function applySnap(latlng) { const target = findSnapTarget(latlng); return target ? { latlng: target.latlng, label: target.label } : { latlng, label: '' }; }
    function findSnapTarget(latlng) { const point = MapEditor.map.latLngToContainerPoint(latlng); return MapEditor.snapTargets.find((target) => point.distanceTo(MapEditor.map.latLngToContainerPoint(target.latlng)) <= 12); }
    function showSnapIndicator(result) { MapEditor.layers.snap.clearLayers(); if (result.label) L.circleMarker(result.latlng, { radius: 8, color: '#facc15', weight: 2, fillOpacity: 0 }).addTo(MapEditor.layers.snap); }
    function calculateLength(points) { let total = 0; for (let i = 1; i < points.length; i++) total += MapEditor.map.distance(points[i - 1], points[i]); return Math.round(total); }
    function segmentDistance(point, a, b) { const p = MapEditor.map.latLngToLayerPoint(point), x = MapEditor.map.latLngToLayerPoint(a), y = MapEditor.map.latLngToLayerPoint(b), dx = y.x - x.x, dy = y.y - x.y, t = Math.max(0, Math.min(1, ((p.x - x.x) * dx + (p.y - x.y) * dy) / ((dx * dx + dy * dy) || 1))); return p.distanceTo(L.point(x.x + t * dx, x.y + t * dy)); }
    function normalizePoints(points) { return (points || []).map((point) => Array.isArray(point) ? [Number(point[0]), Number(point[1])] : [Number(point.lat), Number(point.lng)]); }
    function routeColor(type) { return colors[type] || colors.distribution; }
    function validPoint(item) { return Number.isFinite(Number(item.lat)) && Number.isFinite(Number(item.lng)); }
    function fitMapToData() { const points = [...data().odfs, ...data().cabinets, ...data().houses].filter(validPoint).map((item) => [item.lat, item.lng]); if (points.length) MapEditor.map.fitBounds(points, { padding: [35, 35], maxZoom: 16 }); }
    function showDetails(item) {
        MapEditor.selectedElement = item;
        heading().innerHTML = `<div class="flex items-center gap-3"><b>${item.elementType.toUpperCase()}</b><b>${item.name || item.label}</b></div>`;
        const routeActions = `<button data-panel-action="edit-data">Uredi podatke</button><button data-panel-action="edit-geometry">Uredi geometriju</button><button data-panel-action="delete-route">Obrisi</button>`;
        const markerActions = `<button data-panel-action="move-marker">Pomjeri</button>`;
        details().innerHTML = `<dl class="grid grid-cols-[90px_1fr] gap-y-2 px-3 tiny"><dt>Tip:</dt><dd>${item.elementType}</dd><dt>Naziv:</dt><dd>${item.name || item.label}</dd>${item.elementType === 'route' ? `<dt>Duzina:</dt><dd>${item.length} m</dd><dt>Tacke:</dt><dd>${normalizePoints(item.path).length}</dd><dt>Mikrocijev:</dt><dd>${item.microduct}</dd><dt>Kabal:</dt><dd>${item.fibers} niti</dd>` : ''}</dl><div class="property-actions">${item.elementType === 'route' ? routeActions : markerActions}</div>`;
        details().querySelector('[data-panel-action="edit-data"]')?.addEventListener('click', () => openRouteModal(item));
        details().querySelector('[data-panel-action="edit-geometry"]')?.addEventListener('click', () => { setActiveTool('edit_route'); startEditingRoute(item); });
        details().querySelector('[data-panel-action="delete-route"]')?.addEventListener('click', () => confirmDeleteRoute(item));
        details().querySelector('[data-panel-action="move-marker"]')?.addEventListener('click', () => startMoveMarker(item));
    }
    function openRouteModal(route) {
        MapEditor.pendingRoute = route;
        const form = document.getElementById('route-form'), points = normalizePoints(route.path);
        form.elements.route_id.value = route.id || ''; form.elements.name.value = route.name || `Trasa ${data().routes.length + 1}`;
        form.elements.route_type.value = route.type || 'distribution'; form.elements.odf_id.value = route.odf_id || ''; form.elements.cabinet_id.value = route.cabinet_id || '';
        form.elements.microduct_type.value = route.microduct || '14/10'; form.elements.fiber_count.value = route.fibers || 12;
        document.getElementById('route-modal-points').textContent = `Tacke: ${points.length}`; document.getElementById('route-modal-length').textContent = `Duzina: ${calculateLength(points)} m`;
        document.getElementById('route-modal').classList.remove('hidden');
    }
    function closeRouteModal() { document.getElementById('route-modal').classList.add('hidden'); MapEditor.pendingRoute = null; }
    async function saveRouteForm(event) {
        event.preventDefault(); const form = event.currentTarget, values = Object.fromEntries(new FormData(form)), route = MapEditor.pendingRoute, points = normalizePoints(route.path);
        if (points.length < 2 || !values.name || !values.route_type || !values.microduct_type || !values.fiber_count) return showToast('Popuni obavezna polja trase.', 'error');
        const edit = Boolean(values.route_id), url = edit ? config().routeUpdateUrl.replace('__ID__', values.route_id) : config().routeUrl;
        const payload = { project_id: config().projectId, name: values.name, route_type: values.route_type, odf_id: values.odf_id || null, cabinet_id: values.cabinet_id || null, microduct_type: values.microduct_type, fiber_count: Number(values.fiber_count) };
        if (!edit) Object.assign(payload, { installation_type: 'underground', duct_length_m: calculateLength(points), fiber_length_m: calculateLength(points), microduct_count: 1, status: 'planned', path: JSON.stringify(points) });
        const response = await request(url, edit ? 'PATCH' : 'POST', payload); if (!response?.route) return;
        if (edit) Object.assign(route, response.route); else { data().routes.push(response.route); appendRouteRow(response.route); updateRouteStats(response.route); }
        closeRouteModal(); cancelDrawingRoute(); rerenderRoutes(); showToast('Trasa je sacuvana.', 'success');
    }
    function startMoveMarker(item) { if (!item.marker) return; MapEditor.moving = { item, marker: item.marker, original: item.marker.getLatLng() }; item.marker.dragging.enable(); MapEditor.dirty = true; document.getElementById('map-move-actions').classList.remove('hidden'); setStatus('Pomjeri marker, zatim sacuvaj poziciju ili ponisti.'); }
    async function saveMarkerPosition() { const move = MapEditor.moving; if (!move) return; const latlng = move.marker.getLatLng(), url = config().positionUrls[move.item.elementType]?.replace('__ID__', move.item.id); const response = await request(url, 'PATCH', { latitude: latlng.lat, longitude: latlng.lng }); if (!response) return; move.item.lat = response.latitude; move.item.lng = response.longitude; finishMarkerMove(); showToast('Pozicija je sacuvana.', 'success'); }
    function cancelMarkerPosition() { const move = MapEditor.moving; if (!move) return; move.marker.setLatLng(move.original); finishMarkerMove(); }
    function finishMarkerMove() { MapEditor.moving?.marker.dragging.disable(); MapEditor.moving = null; MapEditor.dirty = false; document.getElementById('map-move-actions').classList.add('hidden'); }
    async function request(url, method, payload = null) { try { if (!config().projectId && method === 'POST') throw new Error('Prvo kreiraj projekat.'); const response = await fetch(url, { method, headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': config().csrf }, body: payload ? JSON.stringify(payload) : null }); const json = await response.json(); if (!response.ok) throw new Error(Object.values(json.errors || {})[0]?.[0] || json.message || 'Zahtjev nije uspio.'); return json; } catch (error) { showToast(error.message, 'error'); return null; } }
    async function addOdf(latlng) { const name = prompt('Naziv ODF-a', `ODF-${String(data().odfs.length + 1).padStart(2, '0')}`); if (!name) return; const response = await request(config().odfUrl, 'POST', { project_id: config().projectId, name, address: 'Sa mape', fiber_capacity: 144, port_count: 48, latitude: latlng.lat, longitude: latlng.lng }); if (response?.odf) { data().odfs.push(response.odf); addAssetMarker(response.odf, 'odf', MapEditor.layers.odfs); showToast('ODF je dodat.', 'success'); } }
    async function addOdo(latlng) { const name = prompt('Naziv ODO ormarica', `ODO-${String(data().cabinets.length + 1).padStart(2, '0')}`); if (!name) return; const response = await request(config().cabinetUrl, 'POST', { project_id: config().projectId, odf_id: data().odfs[0]?.id || null, name, address: 'Sa mape', splitter_count: 3, ports_per_splitter: 4, latitude: latlng.lat, longitude: latlng.lng }); if (response?.cabinet) { data().cabinets.push(response.cabinet); addAssetMarker(response.cabinet, 'odo', MapEditor.layers.cabinets); showToast('ODO je dodat.', 'success'); } }
    async function addHouse(latlng) { const name = prompt('Naziv kuce', `K-${String(data().houses.length + 1).padStart(3, '0')}`); if (!name) return; const response = await request(config().houseUrl, 'POST', { project_id: config().projectId, cabinet_id: data().cabinets[0]?.id || null, label: name, address: 'Sa mape', latitude: latlng.lat, longitude: latlng.lng, status: 'planned' }); if (response?.house) { data().houses.push(response.house); addAssetMarker(response.house, 'house', MapEditor.layers.houses); showToast('Kuca je dodata.', 'success'); } }
    function appendRouteRow(route) { const body = document.getElementById('dashboard-routes-body'); if (!body) return; body.querySelector('[data-empty-row]')?.remove(); const row = document.createElement('tr'); row.className = 'border-t'; row.innerHTML = `<td class="px-4 py-2">${body.children.length + 1}</td><td>${route.from}</td><td>${route.to}</td><td>${route.type}</td><td>${route.length} m</td><td>${route.microduct}</td><td>${route.fibers} niti</td><td>...</td>`; body.prepend(row); while (body.children.length > 5) body.lastElementChild.remove(); }
    function updateRouteStats(route) { document.querySelectorAll('[data-stat]').forEach((element) => { let next = Number(element.dataset.value || 0), key = element.dataset.stat; if (key === 'routes_m' || (key === 'microduct_14_10' && route.microduct === '14/10') || (key === 'microduct_10_8' && route.microduct === '10/8') || (key === 'fiber_4' && route.fibers === 4) || (key === 'fiber_12' && route.fibers === 12)) next += route.length; element.dataset.value = next; element.textContent = `${(next / 1000).toFixed(2)} km`; }); }
    function onKeyDown(event) { if (event.key === 'Escape') { cancelDrawingRoute(); cancelMeasure(); cancelEditedRoute(); cancelMarkerPosition(); closeRouteModal(); setActiveTool('select'); } if (event.key === 'Backspace' && MapEditor.activeTool === 'draw_route') { event.preventDefault(); removeLastRoutePoint(); } }
    function showToast(message, type = '') { const toast = document.getElementById('map-toast'); if (!toast) return; toast.textContent = message; toast.className = `map-toast ${type}`; clearTimeout(showToast.timer); showToast.timer = setTimeout(() => toast.classList.add('hidden'), 3200); }
    document.addEventListener('DOMContentLoaded', () => { if (!document.getElementById('dashboard-map')) return; initMap(); initToolbar(); renderAll(); setActiveTool('select'); });
})();
