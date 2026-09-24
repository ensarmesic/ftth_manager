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
    let drawingType = null;
    let points = [];
    let previewLayer = null;
    let drawingZone = false;
    let zonePoints = [];
    let zonePreviewLayer = null;
    const zoneLayerById = new Map();

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
        } catch (error) {
            readinessResult.textContent = error.message;
            readinessBadge.textContent = 'GREŠKA';
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

    (data.large_planner_constraints || []).forEach(renderConstraint);
    (data.large_planner_zones || []).forEach(renderZone);
    loadReadiness();
})();
