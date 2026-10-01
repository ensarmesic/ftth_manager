(() => {
    const workspace = document.getElementById('large-planner-workspace');
    if (!workspace || !window.ftthNetworkMap) return;

    const projectId = Number(workspace.dataset.projectId);
    const constraintLayers = L.layerGroup().addTo(map);
    const zoneLayers = L.layerGroup().addTo(map);
    const status = document.getElementById('large-planner-constraint-status');
    const actions = document.getElementById('large-planner-draw-actions');
    const validationResult = document.getElementById('large-planner-validation-result');
    const readinessResult = document.getElementById('large-planner-readiness');
    const readinessBadge = document.getElementById('large-planner-readiness-badge');
    const runButton = document.getElementById('large-planner-run');
    const cancelTaskButton = document.getElementById('large-planner-cancel-task');
    let drawingType = null;
    let points = [];
    let previewLayer = null;
    let drawingZone = false;
    let zonePoints = [];
    let zonePreviewLayer = null;
    const zoneLayerById = new Map();

    document.querySelectorAll('[data-large-planner-step]').forEach(button => {
        button.addEventListener('click', () => {
            const panel = document.getElementById(`large-planner-step-${button.dataset.largePlannerStep}`);
            if (!panel) return;
            document.querySelectorAll('[data-large-planner-step]').forEach(item => item.removeAttribute('aria-current'));
            button.setAttribute('aria-current', 'step');
            panel.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
            panel.classList.add('ring-2', 'ring-violet-400');
            window.setTimeout(() => panel.classList.remove('ring-2', 'ring-violet-400'), 1200);
        });
    });

    const endpoint = id => `${appConfig.largePlannerConstraintsBaseUrl.replace('__ID__', projectId)}${id ? `/${id}` : ''}`;
    const zoneEndpoint = suffix => `${appConfig.largePlannerZonesBaseUrl.replace('__ID__', projectId)}${suffix || ''}`;
    const csrf = document.querySelector('meta[name="csrf-token"]')?.content || '';

    function setStatus(message) {
        if (status) status.textContent = message;
    }

    function clearDrawing() {
        drawingType = null;
        points = [];
        if (previewLayer) map.removeLayer(previewLayer);
        previewLayer = null;
        actions?.classList.add('hidden');
    }

    function clearZoneDrawing() {
        drawingZone = false;
        zonePoints = [];
        if (zonePreviewLayer) map.removeLayer(zonePreviewLayer);
        zonePreviewLayer = null;
        document.getElementById('large-planner-zone-finish')?.classList.add('hidden');
        document.getElementById('large-planner-zone-cancel')?.classList.add('hidden');
    }

    function renderZone(zone) {
        const previous = zoneLayerById.get(zone.id);
        if (previous) zoneLayers.removeLayer(previous);
        const colors = { draft: '#4f46e5', calculated: '#0284c7', accepted: '#059669', locked: '#334155' };
        const layer = L.polygon(zone.geometry, {
            color: colors[zone.status] || colors.draft,
            weight: zone.status === 'locked' ? 3 : 2,
            fillOpacity: 0.1,
            dashArray: zone.status === 'draft' ? '7 5' : null,
        }).bindTooltip(`${zone.name} · ${zone.status}`);
        if (zone.status !== 'locked' && window.ftthMapConfig.permissions.edit) {
            layer.on('contextmenu', async () => {
                const confirmed = await window.ftthConfirm?.(`Obrisati zonu ${zone.name}?`, { title: 'Zone velikog planera', confirmLabel: 'Obriši' });
                if (!confirmed) return;
                const response = await fetch(zoneEndpoint(`/${zone.id}`), {
                    method: 'DELETE',
                    headers: { Accept: 'application/json', 'X-CSRF-TOKEN': csrf, 'X-Requested-With': 'XMLHttpRequest' },
                });
                if (!response.ok) {
                    window.ftthToast?.('Zona nije obrisana.', 'error');
                    return;
                }
                zoneLayers.removeLayer(layer);
                zoneLayerById.delete(zone.id);
                document.querySelector(`[data-large-zone-row="${zone.id}"]`)?.remove();
            });
        }
        layer.addTo(zoneLayers);
        zoneLayerById.set(zone.id, layer);
        renderZoneRow(zone);
    }

    function renderZoneRow(zone) {
        const list = document.getElementById('large-planner-zone-list');
        if (!list) return;
        document.querySelector(`[data-large-zone-row="${zone.id}"]`)?.remove();
        const row = document.createElement('div');
        row.dataset.largeZoneRow = zone.id;
        row.className = 'rounded border border-indigo-200 bg-white px-2 py-1 text-[10px] text-indigo-900';
        const label = document.createElement('b');
        label.textContent = `${zone.name} · ${zone.status} · ${zone.houses_count || 0} kuća`;
        row.append(label);
        if (window.ftthMapConfig.permissions.edit && zone.status !== 'locked') {
            const actionsRow = document.createElement('div');
            actionsRow.className = 'mt-1 flex gap-1';
            if (zone.status === 'calculated' || zone.status === 'accepted') {
                const nextStatus = zone.status === 'calculated' ? 'accepted' : 'locked';
                const statusButton = document.createElement('button');
                statusButton.type = 'button';
                statusButton.className = 'rounded border border-indigo-300 px-1.5 py-0.5 font-bold';
                statusButton.textContent = nextStatus === 'accepted' ? 'Prihvati' : 'Zaključaj';
                statusButton.addEventListener('click', async () => {
                    const response = await fetch(zoneEndpoint(`/${zone.id}/status`), {
                        method: 'PATCH',
                        headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf, 'X-Requested-With': 'XMLHttpRequest' },
                        body: JSON.stringify({ status: nextStatus }),
                    });
                    const result = await response.json();
                    if (response.ok) renderZone(result.zone);
                    else window.ftthToast?.(result.message || 'Status zone nije promijenjen.', 'error');
                });
                actionsRow.append(statusButton);
            }
            const replanButton = document.createElement('button');
            replanButton.type = 'button';
            replanButton.className = 'rounded border border-amber-300 px-1.5 py-0.5 font-bold text-amber-800';
            replanButton.textContent = 'Pripremi replan';
            replanButton.addEventListener('click', async () => {
                const response = await fetch(zoneEndpoint('/pripremi-replan'), {
                    method: 'POST',
                    headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf, 'X-Requested-With': 'XMLHttpRequest' },
                    body: JSON.stringify({ zone_ids: [zone.id] }),
                });
                const result = await response.json();
                if (response.ok) renderZone({ ...zone, status: 'draft' });
                else window.ftthToast?.(result.message || 'Zona nije pripremljena.', 'error');
            });
            actionsRow.append(replanButton);
            row.append(actionsRow);
        }
        list.append(row);
    }

    async function removeConstraint(constraint, layer) {
        if (!window.ftthMapConfig.permissions.edit) return;
        const confirmed = await window.ftthConfirm?.('Obrisati označeno ograničenje?', {
            title: 'Veliki planer',
            confirmLabel: 'Obriši',
        });
        if (!confirmed) return;
        const response = await fetch(endpoint(constraint.id), {
            method: 'DELETE',
            headers: { Accept: 'application/json', 'X-CSRF-TOKEN': csrf, 'X-Requested-With': 'XMLHttpRequest' },
        });
        if (!response.ok) throw new Error('Ograničenje nije obrisano.');
        constraintLayers.removeLayer(layer);
        loadReadiness();
    }

    function renderConstraint(constraint) {
        let layer;
        if (constraint.type === 'required_waypoint') {
            layer = L.circleMarker(constraint.geometry, {
                radius: 7, color: '#7c3aed', weight: 3, fillColor: '#ede9fe', fillOpacity: 1,
            });
        } else {
            layer = L.polygon(constraint.geometry, {
                color: '#dc2626', weight: 2, fillColor: '#ef4444', fillOpacity: 0.2,
            });
        }
        layer.bindTooltip(constraint.name || (constraint.type === 'required_waypoint' ? 'Obavezna tačka' : 'Zabranjeno područje'));
        layer.on('contextmenu', () => removeConstraint(constraint, layer).catch(error => window.ftthToast?.(error.message, 'error')));
        layer.addTo(constraintLayers);
    }

    async function saveConstraint(type, geometry) {
        const response = await fetch(endpoint(), {
            method: 'POST',
            headers: {
                Accept: 'application/json', 'Content-Type': 'application/json',
                'X-CSRF-TOKEN': csrf, 'X-Requested-With': 'XMLHttpRequest',
            },
            body: JSON.stringify({ type, geometry }),
        });
        const payload = await response.json();
        if (!response.ok) throw new Error(payload.message || 'Ograničenje nije sačuvano.');
        renderConstraint(payload.constraint);
        window.ftthToast?.('Ograničenje velikog planera je sačuvano.', 'success');
        loadReadiness();
    }

    async function loadReadiness() {
        if (!readinessResult) return;
        try {
            const response = await fetch(appConfig.largePlannerReadinessBaseUrl.replace('__ID__', projectId), {
                headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            });
            const result = await response.json();
            if (!response.ok) throw new Error(result.message || 'Spremnost nije moguće provjeriti.');
            readinessResult.replaceChildren();
            result.checks.forEach(check => {
                const row = document.createElement('div');
                row.className = `rounded border px-2 py-1 ${check.status === 'ready' ? 'border-emerald-200 bg-emerald-50 text-emerald-800' : 'border-red-200 bg-red-50 text-red-800'}`;
                const title = document.createElement('b');
                title.textContent = `${check.status === 'ready' ? '✓' : '×'} ${check.label}`;
                const message = document.createElement('span');
                message.className = 'block';
                message.textContent = check.message;
                row.append(title, message);
                readinessResult.append(row);
            });
            readinessBadge.textContent = result.ready ? 'SPREMAN' : `${result.summary.blocking} BLOKIRA`;
            readinessBadge.className = `rounded px-2 py-0.5 text-[9px] font-black ${result.ready ? 'bg-emerald-200 text-emerald-800' : 'bg-red-200 text-red-800'}`;
            if (runButton) {
                runButton.disabled = !result.ready;
                runButton.title = result.ready ? 'Pokreni novi proračun.' : 'Prvo riješi blokirajuće stavke spremnosti.';
            }
            const estimate = document.getElementById('large-planner-time-estimate');
            if (estimate) {
                const source = result.estimate.source === 'project_history' ? `na osnovu ${result.estimate.sample_count} ranijih proračuna` : 'po konzervativnoj početnoj procjeni';
                estimate.textContent = `Očekivano trajanje: ${result.estimate.minimum_seconds}–${result.estimate.maximum_seconds} s (${source}).`;
            }
        } catch (error) {
            readinessResult.textContent = error.message;
            readinessBadge.textContent = 'GREŠKA';
            if (runButton) runButton.disabled = true;
        }
    }

    function activate(type) {
        clearDrawing();
        setMode('pan');
        drawingType = type;
        actions?.classList.toggle('hidden', type !== 'restricted_area');
        setStatus(type === 'required_waypoint'
            ? 'Klikni jednu obaveznu tačku prolaza na mapi.'
            : 'Klikni najmanje tri tačke područja, zatim Završi područje.');
    }

    map.on('click', async event => {
        if (drawingZone) {
            zonePoints.push([event.latlng.lat, event.latlng.lng]);
            if (zonePreviewLayer) map.removeLayer(zonePreviewLayer);
            zonePreviewLayer = L.polygon(zonePoints, { color: '#4f46e5', weight: 2, dashArray: '7 5', fillOpacity: 0.1 }).addTo(map);
            document.getElementById('large-planner-zone-status').textContent = `Nova zona: ${zonePoints.length} tačaka.`;
            return;
        }
        if (!drawingType) return;
        const point = [event.latlng.lat, event.latlng.lng];
        if (drawingType === 'required_waypoint') {
            clearDrawing();
            try {
                await saveConstraint('required_waypoint', point);
                setStatus('Obavezna tačka je sačuvana.');
            } catch (error) {
                setStatus(error.message);
            }
            return;
        }
        points.push(point);
        if (previewLayer) map.removeLayer(previewLayer);
        previewLayer = L.polygon(points, { color: '#dc2626', weight: 2, dashArray: '6 5', fillOpacity: 0.12 }).addTo(map);
        setStatus(`Zabranjeno područje: ${points.length} tačaka.`);
    });

    document.getElementById('large-planner-waypoint')?.addEventListener('click', () => activate('required_waypoint'));
    document.getElementById('large-planner-restricted-area')?.addEventListener('click', () => activate('restricted_area'));
    document.getElementById('large-planner-cancel-draw')?.addEventListener('click', () => {
        clearDrawing();
        setStatus('Označavanje je otkazano.');
    });
    document.getElementById('large-planner-finish-area')?.addEventListener('click', async () => {
        if (points.length < 3) {
            setStatus('Zabranjeno područje mora imati najmanje tri tačke.');
            return;
        }
        const geometry = [...points];
        clearDrawing();
        try {
            await saveConstraint('restricted_area', geometry);
            setStatus('Zabranjeno područje je sačuvano.');
        } catch (error) {
            setStatus(error.message);
        }
    });

    document.getElementById('large-planner-zone-draw')?.addEventListener('click', () => {
        clearDrawing();
        clearZoneDrawing();
        setMode('pan');
        drawingZone = true;
        document.getElementById('large-planner-zone-finish')?.classList.remove('hidden');
        document.getElementById('large-planner-zone-cancel')?.classList.remove('hidden');
        document.getElementById('large-planner-zone-status').textContent = 'Klikni najmanje tri tačke granice zone.';
    });
    document.getElementById('large-planner-zone-cancel')?.addEventListener('click', () => {
        clearZoneDrawing();
        document.getElementById('large-planner-zone-status').textContent = 'Crtanje zone je otkazano.';
    });
    document.getElementById('large-planner-zone-finish')?.addEventListener('click', async () => {
        const zoneStatus = document.getElementById('large-planner-zone-status');
        const name = document.getElementById('large-planner-zone-name')?.value.trim();
        if (!name) {
            zoneStatus.textContent = 'Unesi naziv zone.';
            return;
        }
        if (zonePoints.length < 3) {
            zoneStatus.textContent = 'Zona mora imati najmanje tri tačke.';
            return;
        }
        const geometry = [...zonePoints];
        clearZoneDrawing();
        const response = await fetch(zoneEndpoint(), {
            method: 'POST',
            headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf, 'X-Requested-With': 'XMLHttpRequest' },
            body: JSON.stringify({ name, geometry }),
        });
        const result = await response.json();
        if (!response.ok) {
            zoneStatus.textContent = result.message || 'Zona nije sačuvana.';
            return;
        }
        renderZone(result.zone);
        document.getElementById('large-planner-zone-name').value = '';
        zoneStatus.textContent = `${result.zone.name} je sačuvana.`;
    });
    document.getElementById('large-planner-zone-auto')?.addEventListener('click', async () => {
        const zoneStatus = document.getElementById('large-planner-zone-status');
        const targetSize = Number(document.getElementById('large-planner-zone-target')?.value || 200);
        zoneStatus.textContent = 'Dijelim kuće na početne zone…';
        const response = await fetch(zoneEndpoint('/automatska-podjela'), {
            method: 'POST',
            headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf, 'X-Requested-With': 'XMLHttpRequest' },
            body: JSON.stringify({ target_size: targetSize }),
        });
        const result = await response.json();
        if (!response.ok) {
            zoneStatus.textContent = result.message || 'Automatska podjela nije uspjela.';
            return;
        }
        result.zones.forEach(renderZone);
        zoneStatus.textContent = `Kreirano zona: ${result.created}.`;
    });

    document.getElementById('large-planner-validate-input')?.addEventListener('click', async () => {
        validationResult.textContent = 'Provjeravam ulazne podatke…';
        try {
            const response = await fetch(appConfig.largePlannerInputValidationBaseUrl.replace('__ID__', projectId), {
                headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            });
            const result = await response.json();
            if (!response.ok) throw new Error(result.message || 'Provjera nije uspjela.');
            validationResult.replaceChildren();
            const summary = document.createElement('b');
            summary.className = result.ready ? 'text-emerald-700' : 'text-red-700';
            summary.textContent = result.ready
                ? `Ulaz je ispravan · ${result.summary.houses} kuća · ${result.summary.corridors} koridora`
                : `${result.summary.errors} grešaka · ${result.summary.warnings} upozorenja`;
            validationResult.append(summary);
            result.issues.forEach(issue => {
                const item = document.createElement('div');
                item.className = `mt-1 ${issue.severity === 'error' ? 'text-red-700' : 'text-amber-800'}`;
                item.textContent = `• ${issue.message}`;
                validationResult.append(item);
            });
            loadReadiness();
        } catch (error) {
            validationResult.textContent = error.message;
        }
    });
    document.getElementById('large-planner-sync-trenches')?.addEventListener('click', async () => {
        const button = document.getElementById('large-planner-sync-trenches');
        button.disabled = true;
        try {
            const result = await jsonRequest(appConfig.largePlannerTrenchesBaseUrl.replace('__ID__', projectId), { method: 'POST' });
            window.ftthToast?.(result.message, 'success');
            window.location.reload();
        } catch (error) {
            button.disabled = false;
            window.ftthToast?.(error.message, 'error');
        }
    });

    const planLayers = L.layerGroup().addTo(map);
    const routeLayersByType = { primary: [], secondary: [] };
    const elementMarkerByKey = new Map();
    const zoneFilter = document.getElementById('large-planner-zone-filter');
    const variantSelect = document.getElementById('large-planner-variant');
    const taskMessage = document.getElementById('large-planner-task-message');
    const taskBadge = document.getElementById('large-planner-task-progress');
    const progressBar = document.getElementById('large-planner-progress-bar');
    let activeTaskId = null;
    let activePreview = null;
    let pollTimer = null;
    let activeTask = null;
    const taskBase = appConfig.largePlannerTasksBaseUrl.replace('__ID__', projectId);
    const previewBase = appConfig.largePlannerPreviewBaseUrl.replace('__ID__', projectId);

    async function jsonRequest(url, options = {}) {
        const response = await fetch(url, {
            ...options,
            headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-TOKEN': csrf, ...(options.headers || {}) },
        });
        const result = await response.json();
        if (!response.ok) throw new Error(result.message || 'Zahtjev nije uspio.');
        return result;
    }

    function updateTask(task) {
        activeTask = task;
        const progress = Number(task.progress || 0);
        taskBadge.textContent = `${task.status.toUpperCase()} · ${progress}%`;
        progressBar.style.width = `${progress}%`;
        taskMessage.textContent = task.error || task.status_message || 'Čekanje na obradu.';
        taskBadge.className = `rounded px-2 py-0.5 text-[9px] font-black ${task.status === 'completed' ? 'bg-emerald-200 text-emerald-800' : task.status === 'failed' ? 'bg-red-200 text-red-800' : task.status === 'cancelled' ? 'bg-slate-200 text-slate-700' : 'bg-cyan-200 text-cyan-900'}`;
        if (cancelTaskButton) cancelTaskButton.disabled = !['queued', 'running'].includes(task.status);
        if (runButton) runButton.disabled = ['queued', 'running', 'cancelling'].includes(task.status);
        const confirmButton = document.getElementById('large-planner-confirm');
        if (confirmButton) { confirmButton.disabled = Boolean(task.confirmed_at); confirmButton.textContent = task.confirmed_at ? 'Plan je potvrđen' : 'Potvrdi plan'; }
        const reopenButton = document.getElementById('large-planner-reopen');
        reopenButton?.classList.toggle('hidden', !task.confirmed_at);
    }

    async function savePreviewChange(path, payload) {
        const result = await jsonRequest(`${previewBase}/${activeTaskId}/${path}`, {
            method: 'PATCH',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload),
        });
        activePreview = result.preview;
        renderPlan(activePreview);
        window.ftthToast?.(result.message, 'success');
    }

    function elementMarker(item, type, color) {
        const locked = Boolean(item.locked);
        const size = type === 'odf' ? 18 : 14;
        const icon = L.divIcon({
            className: '',
            html: `<span style="display:block;width:${size}px;height:${size}px;border-radius:50%;background:${color};border:${locked ? 4 : 2}px solid white;box-shadow:0 1px 5px #0f172a88"></span>`,
            iconSize: [size, size],
            iconAnchor: [size / 2, size / 2],
        });
        const marker = L.marker(item.point, { icon, draggable: !locked && window.ftthMapConfig.permissions.edit });
        marker.bindTooltip(`${item.provisional_name} · ${item.occupancy}/${item.capacity}${locked ? ' · zaključan' : ''}`);
        const popup = document.createElement('div');
        const details = document.createElement('b');
        details.textContent = `${item.provisional_name} · ${item.occupancy}/${item.capacity}`;
        popup.append(details);
        if (window.ftthMapConfig.permissions.edit) {
            const lockButton = document.createElement('button');
            lockButton.type = 'button';
            lockButton.className = 'mt-2 block rounded bg-slate-800 px-2 py-1 text-xs font-bold text-white';
            lockButton.textContent = locked ? 'Otključaj' : 'Zaključaj';
            lockButton.addEventListener('click', () => savePreviewChange('zakljucaj', { type, key: item.key, locked: !locked }).catch(error => window.ftthToast?.(error.message, 'error')));
            popup.append(lockButton);
        }
        marker.bindPopup(popup);
        if (!locked && window.ftthMapConfig.permissions.edit) {
            marker.on('dragend', event => {
                const point = event.target.getLatLng();
                savePreviewChange('pomjeri', { type, key: item.key, point: [point.lat, point.lng] }).catch(error => {
                    window.ftthToast?.(error.message, 'error');
                    renderPlan(activePreview);
                });
            });
        }
        marker.addTo(planLayers);
        elementMarkerByKey.set(item.key, marker);
    }

    function focusProblem(problem) {
        const odoKey = problem.odo_key || problem.details?.odo_key;
        const marker = odoKey ? elementMarkerByKey.get(odoKey) : null;
        if (marker) {
            map.setView(marker.getLatLng(), Math.max(map.getZoom(), 18));
            marker.openPopup();
            return;
        }
        const houseId = Number(problem.house_id || problem.details?.house_id || problem.id || 0);
        const house = houseId ? (data.houses || []).find(item => Number(item.id) === houseId) : null;
        if (house?.latitude != null && house?.longitude != null) {
            map.setView([Number(house.latitude), Number(house.longitude)], Math.max(map.getZoom(), 19));
            return;
        }
        const routeKey = problem.route_key || problem.segment_key || problem.details?.route_key;
        const routeLayer = Object.values(routeLayersByType).flat().find(layer => layer.options.routeKey === routeKey);
        if (routeLayer) map.fitBounds(routeLayer.getBounds().pad(0.4));
    }

    function renderPlan(preview) {
        planLayers.clearLayers();
        elementMarkerByKey.clear();
        Object.values(routeLayersByType).forEach(layers => layers.splice(0));
        const selectedZone = zoneFilter?.value || '';
        const odoByKey = new Map((preview.odo_placement?.odos || []).map(odo => [odo.key, odo]));
        const belongsToSelectedZone = item => !selectedZone || String(item?.zone_id ?? 'unassigned') === selectedZone;
        const secondaryColors = ['#2563eb', '#7c3aed', '#0891b2', '#ea580c', '#db2777', '#16a34a', '#4f46e5', '#b45309'];
        const drawRoutes = (type, routes, color, weight) => {
            const visibleRoutes = (routes || []).filter(route =>
                type === 'primary' || belongsToSelectedZone(odoByKey.get(route.terminal_odo_key || route.odo_key))
            );
            visibleRoutes.forEach((route, routeIndex) => {
                const enabled = document.querySelector(`[data-large-route-filter="${type}"]`)?.checked !== false;
                const routeColor = type === 'secondary' ? secondaryColors[routeIndex % secondaryColors.length] : color;
                const sourcePoints = (route.path || []).map(point => L.latLng(Number(point[0]), Number(point[1])));
                const routeName = route.name || (type === 'secondary' ? `Sekundarni krak ${routeIndex + 1}` : route.key);
                const lateral = type === 'secondary' && Boolean(route.is_lateral);
                // The engineering geometry must remain visually on the surveyed
                // trench axis. With dozens of branches, cumulative display lanes
                // looked like off-road routes even though stored paths were valid.
                const layer = L.polyline(sourcePoints, {
                    color: routeColor,
                    weight,
                    opacity: enabled ? 0.9 : 0,
                    interactive: enabled,
                    routeKey: route.key,
                    dashArray: lateral ? '8 6' : null,
                }).bindTooltip(`${routeName} · ${Math.round(route.length_m || 0)} m · polazi iz ${lateral ? 'ZO-a' : 'ODF-a'}`).addTo(planLayers);
                routeLayersByType[type].push(layer);
            });
        };
        drawRoutes('primary', preview.odf_placement?.primary_routes, '#dc2626', 5);
        drawRoutes('secondary', preview.routes?.secondary_routes, '#2563eb', 4);
        (preview.odf_placement?.odfs || []).forEach(item => elementMarker(item, 'odf', '#dc2626'));
        (preview.odo_placement?.odos || []).filter(belongsToSelectedZone).forEach(item => elementMarker(item, 'odo', '#059669'));

        if (zoneFilter) {
            const previousValue = zoneFilter.value;
            const zoneIds = [...new Set((preview.odo_placement?.odos || []).map(odo => odo.zone_id ?? 'unassigned'))];
            zoneFilter.replaceChildren(new Option('Sve zone', ''));
            zoneIds.forEach(zoneId => {
                const zone = (data.large_planner_zones || []).find(item => String(item.id) === String(zoneId));
                zoneFilter.add(new Option(zone?.name || (zoneId === 'unassigned' ? 'Bez zone' : `Zona #${zoneId}`), String(zoneId)));
            });
            zoneFilter.value = [...zoneFilter.options].some(option => option.value === previousValue) ? previousValue : '';
        }

        const summary = document.getElementById('large-planner-preview-summary');
        summary.replaceChildren();
        const primaryLength = (preview.odf_placement?.primary_routes || []).reduce((total, route) => total + Number(route.length_m || 0), 0);
        const secondaryLength = Number(preview.routes?.summary?.secondary_length_m || 0);
        const dropLength = Number(preview.routes?.summary?.drop_length_m || 0);
        const houseCount = (preview.odo_placement?.odos || []).reduce((total, odo) => total + (odo.house_ids?.length || 0), 0);
        const metrics = [
            ['ODF', preview.odf_placement?.odfs?.length || 0], ['ODO', preview.odo_placement?.odos?.length || 0],
            ['Kuće', houseCount], ['Problemi', preview.warnings?.summary?.total || preview.warnings?.items?.length || 0],
            ['Primarno', `${Math.round(primaryLength)} m`], ['Sekundarno', `${Math.round(secondaryLength)} m`],
            ['Drop', `${Math.round(dropLength)} m`], ['Ukupno kabla', `${Math.round(primaryLength + secondaryLength + dropLength)} m`],
        ];
        metrics.forEach(([label, value]) => { const item = document.createElement('span'); item.className = 'rounded bg-white px-2 py-1'; item.textContent = `${label}: ${value}`; summary.append(item); });
        const networkDetails = document.getElementById('large-planner-network-details');
        networkDetails?.replaceChildren();
        const addNetworkDetail = (title, text, tone = 'slate') => {
            if (!networkDetails) return;
            const item = document.createElement('div');
            item.className = `rounded border px-2 py-1 ${tone === 'red' ? 'bg-red-50 border-red-200 text-red-900' : tone === 'blue' ? 'bg-blue-50 border-blue-200 text-blue-900' : 'bg-slate-50 border-slate-200 text-slate-900'}`;
            const strong = document.createElement('strong');
            strong.textContent = title;
            item.append(strong, document.createTextNode(` · ${text}`));
            networkDetails.append(item);
        };
        (preview.odf_placement?.odfs || []).forEach(odf => addNetworkDetail(
            odf.provisional_name,
            `${odf.occupancy || 0}/${odf.max_odos || odf.occupancy || 0} ODO · ${odf.fiber_capacity || odf.capacity || 144}F · optimizovano ${Math.round(odf.secondary_cost_m || 0)} m`,
            'red',
        ));
        (preview.odf_placement?.primary_routes || []).forEach((route, index) => addNetworkDetail(
            `Primarni krak ${index + 1}`,
            `${route.from_odf_key || `ODF #${route.from_odf_id}`} ↔ ${route.to_odf_key || `ODF #${route.to_odf_id}`} · ${Math.round(route.length_m || 0)} m`,
            'red',
        ));
        const secondarySizes = new Map((preview.cable_capacity?.routes?.secondary_routes || []).map(route => [route.key, route]));
        (preview.routes?.secondary_routes || []).forEach((route, index) => {
            const sizing = secondarySizes.get(route.key) || {};
            addNetworkDetail(route.name || `Sekundarni krak ${index + 1}`, `${(route.odo_keys || []).length} ODO · ${Math.round(route.length_m || 0)} m · ${sizing.fiber_count || '?'}F (${sizing.required_fibers || '?'} potrebno)`, 'blue');
        });
        if (networkDetails && !networkDetails.children.length) networkDetails.textContent = 'Nema mrežnih elemenata za prikaz.';
        const warnings = document.getElementById('large-planner-preview-warnings');
        warnings.replaceChildren();
        (preview.warnings?.items || []).forEach(problem => {
            const item = document.createElement('button');
            item.type = 'button';
            item.className = `block w-full rounded px-2 py-1 text-left ${problem.severity === 'error' ? 'bg-red-100 text-red-800' : 'bg-amber-100 text-amber-900'}`;
            item.textContent = problem.message;
            item.title = 'Fokusiraj problem na mapi';
            item.addEventListener('click', () => focusProblem(problem));
            warnings.append(item);
        });
        if (!(preview.warnings?.items || []).length) warnings.textContent = 'Nema upozorenja.';
        const odoSelect = document.getElementById('large-planner-house-odo');
        odoSelect.replaceChildren(new Option('Odaberi ODO', ''));
        const groupOdoSelect = document.getElementById('large-planner-houses-odo');
        groupOdoSelect?.replaceChildren(new Option('Odaberi ODO', ''));
        (preview.odo_placement?.odos || []).forEach(odo => {
            const label = `${odo.provisional_name} · ${odo.occupancy}/${odo.capacity}`;
            odoSelect.add(new Option(label, odo.key));
            groupOdoSelect?.add(new Option(label, odo.key));
        });
        const bounds = planLayers.getBounds?.();
        if (bounds?.isValid()) map.fitBounds(bounds.pad(0.08));
    }

    document.querySelectorAll('[data-large-route-filter]').forEach(filter => {
        filter.addEventListener('change', () => {
            const enabled = filter.checked;
            (routeLayersByType[filter.dataset.largeRouteFilter] || []).forEach(layer => {
                layer.setStyle({ opacity: enabled ? 0.85 : 0 });
                if (enabled) layer.addTo(planLayers);
                else planLayers.removeLayer(layer);
            });
        });
    });
    zoneFilter?.addEventListener('change', () => { if (activePreview) renderPlan(activePreview); });

    async function loadPreview(taskId) {
        const result = await jsonRequest(`${previewBase}/${taskId}`);
        activeTaskId = Number(taskId);
        activePreview = result.preview;
        updateTask(result.task);
        renderPlan(result.preview);
        const exportLink = document.getElementById('large-planner-export');
        if (exportLink) {
            exportLink.href = `${previewBase}/${taskId}/izvoz`;
            exportLink.classList.remove('hidden');
        }
    }

    async function loadVariants(selectTaskId = null) {
        const result = await jsonRequest(previewBase);
        const selects = [variantSelect, document.getElementById('large-planner-compare-first'), document.getElementById('large-planner-compare-second')];
        selects.forEach((select, index) => {
            const placeholder = index === 0 ? 'Odaberi završenu varijantu' : index === 1 ? 'Prva varijanta' : 'Druga varijanta';
            select.replaceChildren(new Option(placeholder, ''));
            result.tasks.filter(task => task.status === 'completed').forEach(task => select.add(new Option(`Varijanta #${task.id} · ${new Date(task.created_at).toLocaleString('bs-BA')}`, task.id)));
        });
        if (selectTaskId) { variantSelect.value = String(selectTaskId); await loadPreview(selectTaskId); }
    }

    async function pollTask(taskId) {
        clearTimeout(pollTimer);
        const result = await jsonRequest(`${taskBase}/${taskId}`);
        updateTask(result.task);
        if (result.task.status === 'completed') { await loadVariants(taskId); return; }
        if (['failed', 'cancelled'].includes(result.task.status)) { await loadReadiness(); return; }
        pollTimer = setTimeout(() => pollTask(taskId).catch(error => { taskMessage.textContent = error.message; }), 1500);
    }

    runButton?.addEventListener('click', async () => {
        try {
            const result = await jsonRequest(taskBase, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ type: 'large_plan', base_task_id: activeTaskId || null }) });
            activeTaskId = result.task.id;
            updateTask(result.task);
            pollTask(activeTaskId);
        } catch (error) { window.ftthToast?.(error.message, 'error'); }
    });
    cancelTaskButton?.addEventListener('click', async () => {
        if (!activeTaskId || cancelTaskButton.disabled) return;
        try {
            const result = await jsonRequest(`${taskBase}/${activeTaskId}/otkazi`, { method: 'POST' });
            updateTask(result.task);
            clearTimeout(pollTimer);
            if (result.task.status === 'cancelling') pollTask(activeTaskId);
            else loadReadiness();
            window.ftthToast?.(result.message, 'success');
        } catch (error) { window.ftthToast?.(error.message, 'error'); }
    });
    variantSelect?.addEventListener('change', () => { if (variantSelect.value) loadPreview(variantSelect.value).catch(error => window.ftthToast?.(error.message, 'error')); });
    document.getElementById('large-planner-assign-house')?.addEventListener('click', () => {
        const houseId = Number(document.getElementById('large-planner-house-id').value);
        const odoKey = document.getElementById('large-planner-house-odo').value;
        if (!houseId || !odoKey || !activeTaskId) return window.ftthToast?.('Odaberi preview, kuću i ODO.', 'error');
        savePreviewChange('kuca', { house_id: houseId, odo_key: odoKey }).catch(error => window.ftthToast?.(error.message, 'error'));
    });
    document.getElementById('large-planner-reset-preview')?.addEventListener('click', async () => {
        if (!activeTaskId || activeTask?.confirmed_at) return window.ftthToast?.('Odaberi nepotvrđenu varijantu.', 'error');
        const accepted = await window.ftthConfirm?.('Poništiti sva ručna pomjeranja, dodjele i zaključavanja ove varijante?', { title: 'Poništi korekcije', confirmLabel: 'Poništi' });
        if (!accepted) return;
        try {
            const result = await jsonRequest(`${previewBase}/${activeTaskId}/ponisti-korekcije`, { method: 'POST' });
            activePreview = result.preview;
            renderPlan(activePreview);
            window.ftthToast?.(result.message, 'success');
        } catch (error) { window.ftthToast?.(error.message, 'error'); }
    });
    document.getElementById('large-planner-assign-houses')?.addEventListener('click', () => {
        const houseIds = [...new Set((document.getElementById('large-planner-house-ids')?.value || '').split(/[\s,;]+/).filter(Boolean).map(Number))];
        const odoKey = document.getElementById('large-planner-houses-odo')?.value;
        if (!houseIds.length || houseIds.some(id => !Number.isInteger(id) || id < 1) || !odoKey || !activeTaskId) return window.ftthToast?.('Unesi ispravne ID brojeve kuća i odaberi ODO.', 'error');
        savePreviewChange('kuce', { house_ids: houseIds, odo_key: odoKey }).catch(error => window.ftthToast?.(error.message, 'error'));
    });
    document.getElementById('large-planner-compare')?.addEventListener('click', async () => {
        const first = document.getElementById('large-planner-compare-first').value;
        const second = document.getElementById('large-planner-compare-second').value;
        if (!first || !second || first === second) return window.ftthToast?.('Odaberi dvije različite varijante.', 'error');
        try {
            const result = await jsonRequest(`${appConfig.largePlannerCompareBaseUrl.replace('__ID__', projectId)}?first_task_id=${first}&second_task_id=${second}`);
            document.getElementById('large-planner-comparison').textContent = `ODF ${result.first.odfs} / ${result.second.odfs} · ODO ${result.first.odos} / ${result.second.odos} · Kabl ${Math.round(result.first.primary_length_m + result.first.secondary_length_m + result.first.drop_length_m)} / ${Math.round(result.second.primary_length_m + result.second.secondary_length_m + result.second.drop_length_m)} m · Problemi ${result.first.problems} / ${result.second.problems}`;
        } catch (error) { window.ftthToast?.(error.message, 'error'); }
    });
    document.getElementById('large-planner-final-validate')?.addEventListener('click', async () => {
        const output = document.getElementById('large-planner-final-status');
        if (!activeTaskId) return window.ftthToast?.('Prvo odaberi završenu varijantu.', 'error');
        output.textContent = 'Pokrećem završnu provjeru…';
        try {
            const result = await jsonRequest(`${previewBase}/${activeTaskId}/zavrsna-validacija`, { method: 'POST' });
            output.className = 'text-[10px] leading-4 text-emerald-700';
            output.textContent = `Plan je spreman · ${result.summary.houses} kuća · ${result.summary.odos} ODO · ${result.summary.routes} trasa.`;
        } catch (error) { output.className = 'text-[10px] leading-4 text-red-700'; output.textContent = error.message; }
    });
    document.getElementById('large-planner-confirm')?.addEventListener('click', async () => {
        if (!activeTaskId || activeTask?.confirmed_at) return;
        const accepted = await window.ftthConfirm?.('Potvrdom će se ODF, ODO, trase i veze kuća trajno upisati u postojeću mrežu. Nastaviti?', { title: 'Potvrda velikog plana', confirmLabel: 'Potvrdi i upiši' });
        if (!accepted) return;
        const output = document.getElementById('large-planner-final-status');
        output.textContent = 'Potvrđujem plan u jednoj transakciji…';
        try {
            const result = await jsonRequest(`${previewBase}/${activeTaskId}/potvrdi`, {
                method: 'POST', headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ acknowledge_warnings: document.getElementById('large-planner-acknowledge-warnings')?.checked || false }),
            });
            updateTask(result.task);
            output.className = 'text-[10px] leading-4 text-emerald-700';
            output.textContent = `Upisano: ${result.summary.odfs} ODF, ${result.summary.odos} ODO, ${result.summary.routes} trasa i ${result.summary.houses} kuća. Materijal: ${Math.round(result.summary.materials?.route_length_m || 0)} m trase, ${Math.round(result.summary.materials?.microduct_14_10_m || 0)} m cijevi 14/10 i ${Math.round(result.summary.materials?.microduct_10_8_m || 0)} m cijevi 10/8.`;
            window.ftthToast?.(result.message, 'success');
        } catch (error) { output.className = 'text-[10px] leading-4 text-red-700'; output.textContent = error.message; }
    });
    document.getElementById('large-planner-reopen')?.addEventListener('click', async () => {
        if (!activeTaskId || !activeTask?.confirmed_at) return;
        const accepted = await window.ftthConfirm?.('Vratiti projekat na stanje prije potvrde ovog plana? Trenutno potvrđeni automatski elementi bit će uklonjeni, a sigurnosna kopija sadašnjeg stanja bit će sačuvana.', { title: 'Novi proračun', confirmLabel: 'Vrati i nastavi' });
        if (!accepted) return;
        try {
            const result = await jsonRequest(`${previewBase}/${activeTaskId}/ponovo-otvori`, { method: 'POST' });
            window.ftthToast?.(result.message, 'success');
            window.location.reload();
        } catch (error) { window.ftthToast?.(error.message, 'error'); }
    });

    (data.large_planner_constraints || []).forEach(renderConstraint);
    (data.large_planner_zones || []).forEach(renderZone);
    loadReadiness();
    loadVariants().catch(error => { taskMessage.textContent = error.message; });
})();
