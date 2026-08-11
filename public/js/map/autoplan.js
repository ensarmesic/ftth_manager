// ── AUTO ODO PLANNING ────────────────────────────────────────────────────────
function suggestionOdfLabel(draftOdf, odf) {
    if (draftOdf) return `ODF-${String(draftOdf.index + 1).padStart(2, '0')} (draft)`;
    if (odf) return `${odf.name} (${odf.distance} m)`;
    return 'nema';
}
function optimize(points) {
    const min = Math.max(1, Math.min(12, Number(document.getElementById('planner-min').value || 8)));
    const max = Math.max(min, Math.min(12, Number(document.getElementById('planner-max').value || 12)));
    const drop = 1;
    const houses = points.map(p => ({ point: p, chain: nearestOnNetwork(p).chain })).sort((a,b) => a.chain-b.chain);
    const dp = Array(houses.length + 1).fill(Infinity), prev = Array(houses.length + 1).fill(null); dp[0]=0;
    for (let i=0;i<houses.length;i++) for (let s=1;s<=max && i+s<=houses.length;s++) {
        const group = houses.slice(i,i+s), center = L.latLng(group.reduce((a,h)=>a+h.point.lat,0)/s, group.reduce((a,h)=>a+h.point.lng,0)/s);
        const pos = nearestOnNetwork(center).point, splitters = Math.ceil(s/4), waste = splitters*4-s;
        const totalDrop = group.reduce((a,h)=>a+map.distance(h.point,pos),0);
        const cost = 900 + splitters*160 + waste*90 + (s<min ? (min-s)*300 : 0) + totalDrop*drop;
        if (dp[i]+cost < dp[i+s]) { dp[i+s]=dp[i]+cost; prev[i+s]={i,s,pos,splitters,waste,totalDrop,group}; }
    }
    const groups=[]; for(let c=houses.length;c>0 && prev[c];c=prev[c].i) groups.unshift(prev[c]); return groups;
}

function assignHousesToNearestCabinets(groups, points) {
    const max = Math.max(1, Math.min(12, Number(document.getElementById('planner-max').value || 12)));
    const maxDrop = Math.max(20, Number(document.getElementById('planner-max-drop').value || 90));
    const cabinets = groups.map(group => ({
        ...group,
        group: [],
        totalDrop: 0,
        generated: false,
    }));

    const houses = points.map((point, index) => ({ point, index }));
    const pairs = [];
    houses.forEach(house => {
        cabinets.forEach((cabinet, cabinetIndex) => {
            pairs.push({
                house,
                cabinetIndex,
                distance: networkDropDistance(cabinet.pos, house.point),
            });
        });
    });
    pairs.sort((a, b) => a.distance - b.distance);

    const assignedHouses = new Set();
    pairs.forEach(pair => {
        if (assignedHouses.has(pair.house.index)) return;
        if (pair.distance > maxDrop) return;
        const cabinet = cabinets[pair.cabinetIndex];
        if (cabinet.group.length >= max) return;
        cabinet.group.push(pair.house);
        cabinet.totalDrop += pair.distance;
        assignedHouses.add(pair.house.index);
    });

    let unassigned = houses.filter(house => !assignedHouses.has(house.index));
    while (unassigned.length) {
        const seed = unassigned[0];
        const seedNetworkPoint = nearestOnNetwork(seed.point).point || seed.point;
        const localCabinet = {
            i: 0,
            pos: seedNetworkPoint,
            group: [],
            totalDrop: 0,
            generated: true,
        };

        unassigned
            .map(house => ({ house, distance: networkDropDistance(seedNetworkPoint, house.point) }))
            .filter(item => item.distance <= maxDrop)
            .sort((a, b) => a.distance - b.distance)
            .slice(0, max)
            .forEach(item => {
                localCabinet.group.push(item.house);
                localCabinet.totalDrop += item.distance;
                assignedHouses.add(item.house.index);
            });

        cabinets.push(localCabinet);
        unassigned = houses.filter(house => !assignedHouses.has(house.index));
    }

    return cabinets
        .filter(cabinet => cabinet.group.length)
        .map(cabinet => {
            const count = cabinet.group.length;
            const splitters = Math.ceil(count / 4);
            return {
                ...cabinet,
                s: count,
                splitters,
                waste: splitters * 4 - count,
            };
        });
}
function clearSuggestions() {
    suggestionLayers.forEach(l => map.removeLayer(l));
    suggestionLayers.forEach(l => {
        untrackLayer(l, 'preview');
        untrackLayer(l, 'drop');
    });
    Object.entries(houseMarkerByKey).forEach(([key, marker]) => marker.setIcon(icon('house', houseIconTextByKey(key), savedHouseColorByKey[key] || null)));
    suggestionLayers=[];
    suggestedCabinets=[];
    currentAutoPlan = null;
    document.getElementById('cabinet-count').textContent='0';
    document.getElementById('suggestion-output').innerHTML='Nacrtaj trasu i oznaci kuće.';
    document.getElementById('save-suggestions').classList.add('hidden');
    refreshPlanSummary();
}

async function suggest() {
    clearSuggestions();
    suggestedCabinets = [];
    currentAutoPlan = null;
    const projectId = document.getElementById('active-project-id').value;
    const output = document.getElementById('suggestion-output');
    if (!projectId) { output.innerHTML = '<b class="text-red-700">Odaberi projekat prije prijedloga rasporeda.</b>'; return; }
    const url = window.ftthMapConfig.endpoints.autoOdoPreviewBaseUrl.replace('__ID__', projectId);
    output.innerHTML = 'Racunam Auto ODO po krakovima...';
    try {
        if (housePoints.length > savedHouseCount || branches.length || draftOdfs.length) {
            output.innerHTML = 'Prvo snimam nacrt sa mape, zatim racunam Auto ODO...';
            await persistDraftPlanForAutoOdo();
        }
        const response = await fetch(url, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '',
                'Accept': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
            },
            body: JSON.stringify({
                max_houses_per_odo: Number(document.getElementById('planner-max').value || 12),
                preferred_fill_min: Number(document.getElementById('planner-min').value || 8),
                max_house_to_odo_m: Number(document.getElementById('planner-max-drop').value || 90),
            }),
        });
        const plan = await readJsonResponse(response, 'Auto ODO preview nije uspio.');
        if (!response.ok) throw new Error(plan.message || 'Auto ODO preview nije uspio.');
        currentAutoPlan = plan;
        renderAutoOdoPlan(plan);
    } catch (error) {
        output.innerHTML = `<b class="text-red-700">${escapeHtml(error.message)}</b>`;
    }
}
function renderAutoOdoPlan(plan) {
    const branchColor = index => cabinetPalette[(Math.max(Number(index) || 1, 1) - 1) % cabinetPalette.length];
    (plan.cabinets || []).forEach(cabinet => {
        const color = branchColor(cabinet.branch_index);
        const position = L.latLng(cabinet.proposed_latitude, cabinet.proposed_longitude);
        const marker = trackLayer(L.marker(position, { icon: icon('suggest', cabinet.confirmed_name || cabinet.name, color) })
            .bindTooltip(`${cabinet.confirmed_name || cabinet.name} · ${cabinet.house_count}/12`, { direction: 'top', offset: [0, -10] })
            .bindPopup(`<b>${cabinet.confirmed_name || cabinet.name}</b><br>Krak ${cabinet.branch_index}<br>${cabinet.house_count}/12 kuca<br>ODF: ${cabinet.nearest_odf_name || 'nema'}`)
            .addTo(map), 'preview');
        const dropLines = (cabinet.drop_preview || []).map(drop => {
            const path = drop.path?.length ? drop.path : [
                [drop.from.lat, drop.from.lng],
                [drop.to.lat, drop.to.lng],
            ];
            return trackLayer(L.polyline(path, { color, weight: 1.7, opacity: .75, dashArray: '5 7' }).addTo(map), 'drop');
        });
        (cabinet.houses || []).forEach(house => {
            const houseMarker = houseMarkerByKey[pointKey(house.latitude, house.longitude)];
            if (houseMarker) houseMarker.setIcon(icon('house', houseIconTextByKey(pointKey(house.latitude, house.longitude)), color));
        });
        suggestedCabinets.push({
            name: cabinet.confirmed_name || cabinet.name,
            lat: Number(cabinet.proposed_latitude),
            lng: Number(cabinet.proposed_longitude),
            splitter_count: cabinet.splitter_count,
            odf_id: cabinet.nearest_odf_id,
            branch_id: cabinet.branch_id,
            marker,
            dropLines,
            plan: cabinet,
            houseKeys: (cabinet.houses || []).map(house => pointKey(house.latitude, house.longitude)),
        });
        suggestionLayers.push(marker, ...dropLines);
    });

    (plan.unassigned_houses || []).forEach(house => {
        const marker = houseMarkerByKey[pointKey(house.latitude, house.longitude)];
        if (marker) marker.setIcon(icon('house', houseIconTextByKey(pointKey(house.latitude, house.longitude)), '#ef4444'));
    });

    const branchHtml = (plan.branches || []).map(branch =>
        `<div class="rounded bg-white p-2"><b>Krak ${branch.branch_index}</b> -> ${branch.house_count} kuca<br>${branch.odo_count} FTTH</div>`
    ).join('');
    const cabinetHtml = (plan.cabinets || []).map(cabinet =>
        `<div class="border-b border-zinc-200 py-2"><b>${escapeHtml(cabinet.confirmed_name || cabinet.name)}</b><br>Krak ${cabinet.branch_index}, ${cabinet.house_count}/12 kuca<br>${(cabinet.houses || []).map(house => `${escapeHtml(house.label)} -> ${house.chainage_m}m`).join('<br>')}</div>`
    ).join('');
    const unassigned = plan.summary?.unassigned_house_count || 0;
    document.getElementById('cabinet-count').textContent = suggestedCabinets.length;
    document.getElementById('suggestion-output').innerHTML = `
        <div class="mb-2 rounded-md bg-emerald-50 p-2 text-xs font-semibold text-emerald-800">Preview plana je spreman. Potvrdi raspored ili odbaci raspored.</div>
        <div class="mb-2 grid gap-2">${branchHtml}<div class="rounded bg-white p-2"><b>Unassigned</b> -> ${unassigned} kuca</div></div>
        ${cabinetHtml}
    `;
    document.getElementById('save-suggestions').classList.remove('hidden');

    refreshPlanSummary();
}
function addSavedCabinetToMap(cabinet) {
    const enriched = {
        ...cabinet,
        project_id: cabinet.project_id || document.getElementById('active-project-id')?.value,
        project: cabinet.project || '',
        capacity: cabinet.capacity || ((cabinet.splitter_count || 1) * 4),
        used_ports: cabinet.used_ports || 0,
        free_ports: Math.max((cabinet.capacity || ((cabinet.splitter_count || 1) * 4)) - (cabinet.used_ports || 0), 0),
        utilization: Math.round(((cabinet.used_ports || 0) / Math.max(cabinet.capacity || ((cabinet.splitter_count || 1) * 4), 1)) * 100),
    };
    const marker = L.marker([enriched.lat, enriched.lng], { icon: icon('cabinet', enriched.name, cabinetColor(enriched.id)), draggable: false })
        .bindTooltip(`${enriched.name} · ${enriched.used_ports}/${enriched.capacity}`, { direction: 'top', offset: [0, -10] })
        .bindPopup(`<b>FTTH: ${enriched.name}</b><br>Kapacitet: ${enriched.capacity}<br>Zauzeto: ${enriched.used_ports}`)
        .addTo(map);
    data.cabinets.push(enriched);
    cabinetMarkerById[enriched.id] = marker;
    trackLayer(marker, 'odo');
    return marker;
}

async function previewGisPlan() {
    clearSuggestions();
    const projectId = document.getElementById('active-project-id').value;
    const output = document.getElementById('suggestion-output');
    if (!projectId) { output.innerHTML = '<b class="text-red-700">Odaberi projekat prije GIS plana.</b>'; return; }
    output.innerHTML = 'Racunam GIS plan od ODF-a do kuca...';
    try {
        const url = window.ftthMapConfig.endpoints.gisPlanPreviewBaseUrl.replace('__ID__', projectId);
        const response = await fetch(`${url}?limit=120`, { headers: { Accept: 'application/json' } });
        const plan = await readJsonResponse(response, 'GIS plan nije izracunat.');
        currentGisPlan = plan;
        renderGisPlanPreview(plan);
    } catch (error) {
        output.innerHTML = `<b class="text-red-700">${escapeHtml(error.message)}</b>`;
    }
}

function renderGisPlanPreview(plan) {
    const color = '#0284c7';
    const maxLoad = Math.max(1, ...(plan.network_segments || []).map(segment => Number(segment.house_count) || 1));
    (plan.network_segments || []).forEach(segment => {
        const points = (segment.path || []).map(point => L.latLng(point[0], point[1]));
        if (points.length < 2) return;
        const load = Number(segment.house_count) || 1;
        const line = trackLayer(L.polyline(points, {
            color: load > 1 ? '#0369a1' : color,
            weight: 2 + Math.min(6, load / maxLoad * 5),
            opacity: .88,
        })
            .bindPopup(`<b>GIS mrežni segment</b><br>${segment.length_m} m<br>Korisnika: ${load}<br>${(segment.houses || []).slice(0, 12).join(', ')}`)
            .addTo(map), 'preview');
        suggestionLayers.push(line);
    });
    (plan.cabinets || []).forEach(cabinet => {
        const marker = trackLayer(L.marker([cabinet.lat, cabinet.lng], { icon: icon('suggest', cabinet.name, '#0ea5e9') })
            .bindTooltip(`${cabinet.name} · ${cabinet.house_count}/12`, { direction: 'top', offset: [0, -10] })
            .bindPopup(`<b>${cabinet.name}</b><br>ODF: ${cabinet.odf?.name || '-'}<br>${cabinet.house_count} kuca<br>Splitteri: ${cabinet.splitter_count}`)
            .addTo(map), 'preview');
        suggestionLayers.push(marker);
    });

    const summary = plan.summary || {};
    const warningsHtml = (plan.warnings || []).slice(0, 6).map(warning =>
        `<div class="rounded bg-amber-50 px-2 py-1 text-amber-800">${escapeHtml(warning)}</div>`
    ).join('');
    const odfsHtml = (plan.odfs || []).map(odf =>
        `<div class="rounded bg-white px-2 py-1"><b>${odf.name}</b>: ${odf.house_count} kuca · ${odf.splitter_count}/${odf.fiber_capacity || '-'} vlakana · ${odf.utilization_percent || 0}%</div>`
    ).join('');
    const routesHtml = (plan.network_segments || []).slice(0, 20).map(segment =>
        `<div class="border-b border-slate-200 py-1"><b>${segment.house_count} korisnika</b> -> ${segment.length_m} m<br><span class="text-slate-400">${escapeHtml((segment.houses || []).slice(0, 8).join(', '))}</span></div>`
    ).join('');
    document.getElementById('suggestion-output').innerHTML = `
        <div class="mb-2 rounded-md bg-sky-50 p-2 text-xs font-semibold text-sky-800">
            GIS score ${summary.score ?? '-'} / 100 · ${summary.routed_houses || 0}/${summary.houses_total || 0} kuca · ODF ${summary.used_odf_count || 0}/${summary.odf_count || 0} · ODO ${summary.cabinet_count || 0}<br>
            Rov ${summary.unique_network_m || 0} m · drop avg ${summary.average_drop_m || 0} m · max ${summary.max_drop_m || 0} m · ODO popunjenost ${summary.average_utilization_percent || 0}%
        </div>
        ${odfsHtml ? `<div class="mb-2 grid gap-1">${odfsHtml}</div>` : ''}
        ${warningsHtml ? `<div class="mb-2 grid gap-1">${warningsHtml}</div>` : ''}
        ${routesHtml || '<b class="text-red-700">Nema mrežnih segmenata kroz GIS graf.</b>'}
        ${(summary.unrouted_houses || 0) ? `<div class="mt-2 rounded bg-amber-50 p-2 text-amber-800">Bez rute: ${summary.unrouted_houses} kuca.</div>` : ''}
    `;
    document.getElementById('save-suggestions').classList.add('hidden');
    document.getElementById('save-gis-plan')?.classList.remove('hidden');
    refreshPlanSummary();
}

async function saveGisPlan() {
    const projectId = document.getElementById('active-project-id').value;
    const output = document.getElementById('suggestion-output');
    if (!projectId || !currentGisPlan) { output.innerHTML = '<b class="text-red-700">Prvo izracunaj GIS plan.</b>'; return; }
    const button = document.getElementById('save-gis-plan');
    button.disabled = true;
    button.textContent = 'Snimam GIS mrezu...';
    try {
        const url = window.ftthMapConfig.endpoints.gisPlanConfirmBaseUrl.replace('__ID__', projectId);
        const response = await fetch(url, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '',
                'Accept': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
            },
            body: JSON.stringify({ limit: 120 }),
        });
        const result = await readJsonResponse(response, 'GIS mreza nije snimljena.');
        (result.routes || []).forEach(route => addSavedRouteToMap({ ...route, type: route.type || 'distribution' }));
        (result.cabinets || []).forEach(cabinet => addSavedCabinetToMap(cabinet));
        suggestionLayers.forEach(layer => map.removeLayer(layer));
        suggestionLayers = [];
        currentGisPlan = null;
        output.innerHTML = `<b class="text-emerald-700">GIS mreza je snimljena: ${result.created || 0} trasa, ${result.created_cabinets || 0} ODO, ${result.created_drop_routes || 0} drop trasa.</b>`;
        button.classList.add('hidden');
        refreshStats();
    } catch (error) {
        output.innerHTML = `<b class="text-red-700">${error.message}</b>`;
    } finally {
        button.disabled = false;
        button.textContent = 'Snimi GIS mrezu';
    }
}

function nearestDraftOdf(point) {
    if (!draftOdfs.length) return null;
    return draftOdfs
        .map((odf, index) => ({ index, distance: Math.round(map.distance(point, odf.marker.getLatLng())) }))
        .sort((a,b) => a.distance - b.distance)[0];
}

function draftOdfCabinetCount(index) {
    return [...draftCabinets, ...suggestedCabinets].filter(cabinet => cabinet.odf_index === index).length;
}

function savedOdfsForActiveProject() {
    const projectId = document.getElementById('active-project-id').value;
    return data.odfs.filter(odf => !projectId || String(odf.project_id ?? '') === String(projectId) || data.projects?.find?.(project => String(project.id) === String(projectId) && project.name === odf.project));
}

function activeOdfPayload(point = null) {
    if (activeOdfSelection?.type === 'draft' && draftOdfs[activeOdfSelection.index]) {
        return { odf_index: activeOdfSelection.index, odf_id: null };
    }
    if (activeOdfSelection?.type === 'saved') {
        return { odf_index: null, odf_id: activeOdfSelection.id };
    }
    const nearest = point ? nearestDraftOdf(point) : null;
    return { odf_index: nearest?.index ?? null, odf_id: null };
}

function activeOdfLabel() {
    if (activeOdfSelection?.type === 'draft' && draftOdfs[activeOdfSelection.index]) {
        return draftOdfs[activeOdfSelection.index].name || defaultDraftName('odf', activeOdfSelection.index);
    }
    if (activeOdfSelection?.type === 'saved') {
        return data.odfs.find(odf => Number(odf.id) === Number(activeOdfSelection.id))?.name || 'ODF';
    }
    return null;
}

function defaultDraftName(type, index) {
    return type === 'odf' ? `ODF-${String(index + 1).padStart(2, '0')}` : draftCabinetName(index);
}
function selectDraftElement(type, item) {
    selectedDraftElement = { type, item };
    const model = type === 'house' ? (item.meta || item) : item;
    document.getElementById('element-editor').classList.remove('hidden');
    document.getElementById('element-editor-type').textContent = type === 'odf' ? 'ODF lokacija' : type === 'cabinet' ? 'FTTH ormarić' : 'Kućni priključak';
    document.getElementById('element-editor-name').value = item.pending ? '' : (model.name || model.label || '');
    document.getElementById('element-editor-address').value = model.address || '';
    document.getElementById('element-editor-odf-fields').classList.toggle('hidden', type !== 'odf');
    document.getElementById('element-editor-cabinet-fields').classList.toggle('hidden', type !== 'cabinet');
    document.getElementById('element-editor-capacity').classList.toggle('hidden', type === 'house');
    if (type === 'odf') {
        document.getElementById('element-editor-fiber-capacity').value = String(item.fiber_capacity || 144);
        document.getElementById('element-editor-port-count').value = item.port_count || 48;
        document.getElementById('element-editor-capacity').textContent = `${item.fiber_capacity || 144} vlakana · ${item.port_count || 48} priključnih portova`;
    }
    if (type === 'cabinet') {
        document.getElementById('element-editor-splitter-count').value = item.splitter_count || 3;
        document.getElementById('element-editor-ports-per-splitter').value = item.ports_per_splitter || 4;
        const odfSelect = document.getElementById('element-editor-odf');
        odfSelect.innerHTML = '<option value="">Nije povezan</option>'
            + data.odfs.map(odf => `<option value="saved:${odf.id}">${escapeHtml(odf.name)}</option>`).join('')
            + draftOdfs.map((odf, index) => `<option value="draft:${index}">${escapeHtml(odf.name || defaultDraftName('odf', index))}</option>`).join('');
        odfSelect.value = item.odf_id ? `saved:${item.odf_id}` : (item.odf_index !== null && item.odf_index !== undefined ? `draft:${item.odf_index}` : '');
        document.getElementById('element-editor-capacity').textContent = `${(item.splitter_count || 3) * (item.ports_per_splitter || 4)} priključaka ukupnog kapaciteta`;
    }
    document.getElementById('element-editor-status').textContent = item.pending
        ? 'Unos naziva je obavezan da bi ODF bio dodat.'
        : 'Izmijeni podatke i potvrdi spremanje.';
    document.getElementById('element-editor-name').focus();
}
function closeDraftElementEditor() {
    if (selectedDraftElement?.item.pending) map.removeLayer(selectedDraftElement.item.marker);
    selectedDraftElement = null;
    document.getElementById('element-editor').classList.add('hidden');
}
async function saveSelectedDraftElementName() {
    if (!selectedDraftElement) return;
    const name = document.getElementById('element-editor-name').value.trim();
    if (!name) {
        document.getElementById('element-editor-status').textContent = 'Naziv je obavezan.';
        return;
    }
    const { type, item } = selectedDraftElement;
    const address = document.getElementById('element-editor-address').value.trim();
    if (type === 'house') {
        const houseModel = item.meta || item;
        houseModel.label = name;
        houseModel.address = address;
        item.marker.setPopupContent(`<b>${escapeHtml(name)}</b>${address ? `<br>${escapeHtml(address)}` : ''}`);
    } else {
        item.name = name;
        item.address = address;
    }
    if (type === 'odf') {
        const fiberCapacity = Number(document.getElementById('element-editor-fiber-capacity').value);
        const portCount = Number(document.getElementById('element-editor-port-count').value);
        if (!Number.isInteger(fiberCapacity) || fiberCapacity < 1 || !Number.isInteger(portCount) || portCount < 1) {
            document.getElementById('element-editor-status').textContent = 'Kapacitet vlakana i broj portova moraju biti pozitivni cijeli brojevi.';
            return;
        }
        item.fiber_capacity = fiberCapacity;
        item.port_count = portCount;
        document.getElementById('element-editor-capacity').textContent = `${fiberCapacity} vlakana · ${portCount} priključnih portova`;
    }
    if (type === 'cabinet') {
        const splitterCount = Number(document.getElementById('element-editor-splitter-count').value);
        const portsPerSplitter = Number(document.getElementById('element-editor-ports-per-splitter').value);
        if (!Number.isInteger(splitterCount) || splitterCount < 1 || splitterCount > 3 || !Number.isInteger(portsPerSplitter) || portsPerSplitter < 1 || portsPerSplitter > 4) {
            document.getElementById('element-editor-status').textContent = 'Ormarić podržava najviše 3 splittera sa po 4 porta.';
            return;
        }
        item.splitter_count = splitterCount;
        item.ports_per_splitter = portsPerSplitter;
        const odfValue = document.getElementById('element-editor-odf').value;
        item.odf_index = odfValue.startsWith('draft:') ? Number(odfValue.split(':')[1]) : null;
        item.odf_id = odfValue.startsWith('saved:') ? Number(odfValue.split(':')[1]) : null;
        document.getElementById('element-editor-capacity').textContent = `${splitterCount * portsPerSplitter} priključaka ukupnog kapaciteta`;
    }
    if (item.saved) {
        const position = item.marker.getLatLng();
        const payload = { project_id: item.project_id, name, address, latitude: position.lat, longitude: position.lng };
        if (type === 'odf') Object.assign(payload, { fiber_capacity: item.fiber_capacity, port_count: item.port_count });
        if (type === 'cabinet') Object.assign(payload, {
            odf_id: item.odf_id, parent_cabinet_id: item.parent_cabinet_id || null, branch_id: item.branch_id || null,
            branch_order: item.branch_order || 0, splitter_count: item.splitter_count, ports_per_splitter: item.ports_per_splitter,
        });
        if (type === 'house') Object.assign(payload, { label: name, cabinet_id: item.cabinet_id || null, status: item.status || 'planned' });
        const base = type === 'odf' ? '/odf/' : type === 'cabinet' ? '/ormarici/' : '/kuce/';
        const status = document.getElementById('element-editor-status');
        status.textContent = 'Čuvam trajne izmjene…';
        const response = await fetch(`${base}${item.id}`, {
            method: 'PATCH',
            headers: {
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '',
                'Accept': 'application/json', 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest',
            },
            body: JSON.stringify(payload),
        });
        if (!response.ok) {
            const result = await response.json().catch(() => ({}));
            status.textContent = Object.values(result.errors || {})[0]?.[0] || result.message || 'Izmjena nije sačuvana.';
            return;
        }
        if (type === 'cabinet') item.marker.setIcon(icon('cabinet', name));
        item.marker.setPopupContent(`<b>${escapeHtml(name)}</b>${address ? `<br>${escapeHtml(address)}` : ''}`);
        status.textContent = `Trajne izmjene za "${name}" su sačuvane.`;
        document.getElementById('cad-command').textContent = `UPDATE: ${name}`;
        return;
    }
    const wasPendingOdf = selectedDraftElement.item.pending && selectedDraftElement.type === 'odf';
    if (wasPendingOdf) {
        const item = selectedDraftElement.item;
        item.pending = false;
        item.marker.closePopup().bindPopup(`<b>ODF: ${name}</b>`);
        draftOdfCount++;
        draftElements.push({ type: 'odf', marker: item.marker });
        draftOdfs.push(item);
        item.marker.on('drag', () => { refreshDraftTooltips(); refreshPlanSummary(); });
        item.marker.on('click', () => { setActiveDraftOdf(draftOdfs.indexOf(item)); selectDraftElement('odf', item); });
        registerDraftContext(item.marker, item.name);
        setActiveDraftOdf(draftOdfs.length - 1);
    }
    if (type === 'cabinet') item.marker.setIcon(icon('cabinet', name));
    refreshDraftTooltips();
    refreshPlanSummary();
    document.getElementById('element-editor-status').textContent = type === 'odf'
        ? `ODF "${name}" je sačuvan.`
        : `Podaci za "${name}" su sačuvani.`;
    if (wasPendingOdf) {
        const savedItem = selectedDraftElement.item;
        setTimeout(() => {
            if (selectedDraftElement?.item === savedItem) closeDraftElementEditor();
        }, 450);
    }
}

function setActiveDraftOdf(index) {
    if (typeof index === 'string' && index.includes(':')) {
        const [type, value] = index.split(':');
        activeOdfSelection = type === 'saved' ? { type, id: Number(value) } : { type: 'draft', index: Number(value) };
    } else if (index === '' || index === null || index === undefined) {
        activeOdfSelection = null;
    } else {
        activeOdfSelection = { type: 'draft', index: Number(index) };
    }
    activeDraftOdfIndex = activeOdfSelection?.type === 'draft' ? activeOdfSelection.index : null;
    const value = activeOdfSelection ? `${activeOdfSelection.type}:${activeOdfSelection.type === 'saved' ? activeOdfSelection.id : activeOdfSelection.index}` : '';
    document.getElementById('active-odf-index').value = value;
    const label = activeOdfLabel();
    document.getElementById('odf-link-status').textContent = label ? `Novi FTTH ormarići se vežu na ${label}.` : 'Odaberi ODF prije redanja FTTH ormarića.';
    refreshDraftTooltips();
}
function renderDraftOdfPicker() {
    const select = document.getElementById('active-odf-index');
    const savedOdfs = savedOdfsForActiveProject();
    const savedOptions = savedOdfs.map(odf => `<option value="saved:${odf.id}">${odf.name} (postojeci ODF)</option>`);
    const draftOptions = draftOdfs.map((item, index) => `<option value="draft:${index}">${item.name || defaultDraftName('odf', index)} (${draftOdfCabinetCount(index)} FTTH)</option>`);
    select.innerHTML = [...savedOptions, ...draftOptions].length ? [...savedOptions, ...draftOptions].join('') : '<option value="">Prvo postavi ODF</option>';
    if (!activeOdfSelection && savedOdfs.length) activeOdfSelection = { type: 'saved', id: savedOdfs[0].id };
    if (!activeOdfSelection && draftOdfs.length) activeOdfSelection = { type: 'draft', index: draftOdfs.length - 1 };
    if (activeOdfSelection?.type === 'draft' && !draftOdfs[activeOdfSelection.index]) activeOdfSelection = null;
    activeDraftOdfIndex = activeOdfSelection?.type === 'draft' ? activeOdfSelection.index : null;
    const value = activeOdfSelection ? `${activeOdfSelection.type}:${activeOdfSelection.type === 'saved' ? activeOdfSelection.id : activeOdfSelection.index}` : '';
    select.value = value;
    const label = activeOdfLabel();
    document.getElementById('odf-link-status').textContent = label ? `Novi FTTH ormarići se vežu na ${label}.` : 'Postavi ODF, zatim postavljaj FTTH ormariće.';
    refreshRouteOdfStatus();
}
function refreshDraftTooltips() {
    draftOdfs.forEach((item, index) => {
        item.marker.bindTooltip(`${item.name} · ${draftOdfCabinetCount(index)} FTTH`, { direction: 'top', offset: [0, -10] });
    });
    draftCabinets.forEach(item => {
        const savedOdf = item.odf_id ? data.odfs.find(odf => Number(odf.id) === Number(item.odf_id)) : null;
        const label = savedOdf ? savedOdf.name : (item.odf_index === null || item.odf_index === undefined ? 'bez ODF' : `ODF-${String(item.odf_index + 1).padStart(2,'0')}`);
        item.marker.bindTooltip(`0/12 - ${label}`, { direction: 'top', offset: [0, -10] });
    });
    renderDraftOdfPicker();
}
