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
    Object.entries(houseMarkerByKey).forEach(([key, marker]) => marker.setIcon(icon('house', '', savedHouseColorByKey[key] || null)));
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
        output.innerHTML = `<b class="text-red-700">${error.message}</b>`;
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
            if (houseMarker) houseMarker.setIcon(icon('house', '', color));
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
        if (marker) marker.setIcon(icon('house', '', '#ef4444'));
    });

    const branchHtml = (plan.branches || []).map(branch =>
        `<div class="rounded bg-white p-2"><b>Krak ${branch.branch_index}</b> -> ${branch.house_count} kuca<br>${branch.odo_count} FTTH</div>`
    ).join('');
    const cabinetHtml = (plan.cabinets || []).map(cabinet =>
        `<div class="border-b border-zinc-200 py-2"><b>${cabinet.confirmed_name || cabinet.name}</b><br>Krak ${cabinet.branch_index}, ${cabinet.house_count}/12 kuca<br>${(cabinet.houses || []).map(house => `${house.label} -> ${house.chainage_m}m`).join('<br>')}</div>`
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
    document.getElementById('element-editor').classList.remove('hidden');
    document.getElementById('element-editor-type').textContent = type === 'odf' ? 'ODF lokacija' : 'Draft FTTH ormarić';
    document.getElementById('element-editor-name').value = item.pending ? '' : item.name;
    document.getElementById('element-editor-status').textContent = item.pending
        ? 'Unos naziva je obavezan da bi ODF bio dodat.'
        : 'Upiši naziv i klikni Sačuvaj naziv.';
    document.getElementById('element-editor-name').focus();
}
function closeDraftElementEditor() {
    if (selectedDraftElement?.item.pending) map.removeLayer(selectedDraftElement.item.marker);
    selectedDraftElement = null;
    document.getElementById('element-editor').classList.add('hidden');
}
function saveSelectedDraftElementName() {
    if (!selectedDraftElement) return;
    const name = document.getElementById('element-editor-name').value.trim();
    if (!name) {
        document.getElementById('element-editor-status').textContent = 'Naziv je obavezan.';
        return;
    }
    selectedDraftElement.item.name = name;
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
    if (selectedDraftElement.type === 'cabinet') selectedDraftElement.item.marker.setIcon(icon('cabinet', name));
    refreshDraftTooltips();
    refreshPlanSummary();
    document.getElementById('element-editor-status').textContent = selectedDraftElement.type === 'odf'
        ? `ODF "${name}" je sačuvan.`
        : `Naziv "${name}" je sačuvan.`;
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
    document.getElementById('odf-link-status').textContent = label ? `Novi FTTH ormarici se vezu na ${label}.` : 'Odaberi ODF prije redanja FTTH ormarica.';
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
    document.getElementById('odf-link-status').textContent = label ? `Novi FTTH ormarici se vezu na ${label}.` : 'Postavi ODF, zatim postavljaj FTTH ormarice.';
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
