// ── CONTEXT MENUS + BOX SELECT ────────────────────────────────────────────────
function showCadContext(latlng, title, actions) {
    cadContext = actions;
    const buttons = actions.map((action, index) => `<button type="button" data-cad-action="${index}" class="block w-full rounded px-2 py-1 text-left text-[10px] font-semibold leading-tight hover:bg-zinc-100">${action.label}</button>`).join('');
    L.popup({ closeButton: true, minWidth: 82, maxWidth: 110, className: 'cad-popup' })
        .setLatLng(latlng)
        .setContent(`<div class="w-[92px]"><div class="border-b border-zinc-200 px-2 py-1 text-[10px] font-bold leading-tight">${title}</div><div class="p-0.5">${buttons}</div></div>`)
        .openOn(map);
}

map.on('popupopen', event => {
    event.popup.getElement()?.querySelectorAll('[data-cad-action]').forEach(button => {
        button.addEventListener('click', () => {
            const action = cadContext?.[Number(button.dataset.cadAction)];
            if (action) action.run();
            map.closePopup();
        });
    });
});

function removeDraftElement(marker) {
    const item = draftElements.find(entry => entry.marker === marker);
    if (!item) return;
    if (selectedDraftElement?.item.marker === marker) closeDraftElementEditor();
    const appendixItem = draftAppendixItems.find(entry => entry.marker === marker);
    if (appendixItem) removeAppendixDraftItem(appendixItem);
    else map.removeLayer(marker);
    draftElements = draftElements.filter(entry => entry.marker !== marker);
    if (item.type === 'odf') {
        const removedIndex = draftOdfs.findIndex(entry => entry.marker === marker);
        draftOdfs = draftOdfs.filter(entry => entry.marker !== marker);
        draftCabinets.forEach(cabinet => {
            if (cabinet.odf_index === removedIndex) cabinet.odf_index = null;
            if (cabinet.odf_index > removedIndex) cabinet.odf_index--;
        });
        activeDraftOdfIndex = draftOdfs.length ? Math.min(activeDraftOdfIndex ?? 0, draftOdfs.length - 1) : null;
    }
    if (item.type === 'cabinet') draftCabinets = draftCabinets.filter(entry => entry.marker !== marker);
    if (item.type === 'manhole' || item.type === 'boring_fi_130') {
        draftAppendixItems = draftAppendixItems.filter(entry => entry.marker !== marker);
    }
    refreshDraftTooltips();
    refreshPlanSummary();
}
function registerDraftContext(marker, title) {
    const openMenu = event => {
        L.DomEvent.stop(event);
        showCadContext(event.latlng, title, [
            { label: 'Obrisi', run: () => removeDraftElement(marker) },
            { label: 'Pomjeri', run: () => marker.dragging?.enable() },
        ]);
    };
    marker.on('contextmenu', openMenu);
    marker.on('click', event => {
        if (mode === 'pan') openMenu(event);
    });
}
function removeDraftHouse(marker) {
    const index = houseMarkers.indexOf(marker);
    if (index < 0) return;
    map.removeLayer(marker);
    houseMarkers.splice(index, 1);
    housePoints.splice(savedHouseCount + index, 1);
    refreshStats();
}
function registerHouseContext(marker) {
    const openMenu = event => {
        L.DomEvent.stop(event);
        showCadContext(event.latlng, 'Kuca', [
            { label: 'Obrisi', run: () => removeDraftHouse(marker) },
            { label: 'Pomjeri', run: () => marker.dragging?.enable() },
        ]);
    };
    marker.on('contextmenu', openMenu);
    marker.on('click', event => {
        if (mode === 'pan') openMenu(event);
    });
}
function removeBranchAt(index) {
    const line = branchLines[index];
    if (line) map.removeLayer(line);
    branchLines.splice(index, 1);
    branches.splice(index, 1);
    branchMeta.splice(index, 1);
    const labels = branchLabelGroups.splice(index, 1)[0] || [];
    labels.forEach(label => {
        if (label) map.removeLayer(label);
        branchLabels = branchLabels.filter(item => item !== label);
    });
    renderBranchList();
    refreshStats();
}
function registerBranchContext(line) {
    const openMenu = event => {
        L.DomEvent.stop(event);
        const index = branchLines.indexOf(line);
        if (index < 0) return;
        showCadContext(event.latlng, branchMeta[index]?.name || 'Krak trase', [
            { label: 'Obrisi krak', run: () => removeBranchAt(index) },
        ]);
    };
    line.on('contextmenu', openMenu);
    line.on('click', event => {
        if (mode === 'trace-branch') { L.DomEvent.stop(event); handleTraceBranchClick(event.latlng); return; }
        if (mode === 'draw') { L.DomEvent.stop(event); map.closePopup(); addDrawPoint(event.latlng); return; }
        if (mode === 'pan') openMenu(event);
    });
}
async function deleteSavedElement(url, layer) {
    if (!confirm('Sigurno obrisati?')) return;
    const response = await fetch(url, {
        method: 'DELETE',
        headers: {
            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '',
            'Accept': 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
        },
    });

    if (!response.ok) {
        alert(await response.text() || 'Brisanje nije uspjelo.');
        return;
    }

    (Array.isArray(layer) ? layer : [layer]).filter(Boolean).forEach(item => map.removeLayer(item));
    document.getElementById('cad-command').textContent = 'Element je obrisan.';
}
async function saveSavedPosition(marker, url) {
    const position = marker.getLatLng();
    const response = await fetch(url, {
        method: 'PATCH',
        headers: {
            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '',
            'Accept': 'application/json',
            'Content-Type': 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
        },
        body: JSON.stringify({ latitude: position.lat, longitude: position.lng }),
    });

    if (!response.ok) throw new Error(await response.text() || 'Pomjeranje nije sačuvano.');
    marker.dragging?.disable();
    document.getElementById('cad-command').textContent = 'Nova pozicija je sačuvana.';
}
function registerSavedContext(layer, title, url, positionUrl = null, clickAction = null, customActions = [], onDelete = null) {
    const triggerLayer = Array.isArray(layer) ? layer[0] : layer;
    const allLayers = Array.isArray(layer) ? layer : [layer];
    selectionRegistry.push({ triggerLayer, allLayers, url, title });
    let savedPosition = triggerLayer.getLatLng?.();
    if (positionUrl) {
        triggerLayer.on('dragend', async () => {
            const oldPos = savedPosition;
            try {
                await saveSavedPosition(triggerLayer, positionUrl);
                const newPos = triggerLayer.getLatLng();
                savedPosition = newPos;
                mapHistory.push({
                    label: `Pomjeri ${title}`,
                    undo: async () => {
                        triggerLayer.setLatLng(oldPos);
                        await saveSavedPosition(triggerLayer, positionUrl);
                        savedPosition = oldPos;
                    },
                    redo: async () => {
                        triggerLayer.setLatLng(newPos);
                        await saveSavedPosition(triggerLayer, positionUrl);
                        savedPosition = newPos;
                    },
                });
            } catch (error) {
                if (savedPosition) triggerLayer.setLatLng(savedPosition);
                triggerLayer.dragging?.disable();
                alert(error.message);
            }
        });
    }
    const openMenu = event => {
        L.DomEvent.stop(event);
        if (layerLocked(triggerLayer._ftthLayerType)) {
            document.getElementById('cad-command').textContent = `Layer ${triggerLayer._ftthLayerType} je zaključan.`;
            return;
        }
        const actions = [
            ...(typeof customActions === 'function' ? customActions(event.latlng) : (customActions || [])),
            { label: 'Obrisi', run: () => onDelete ? onDelete() : deleteSavedElement(url, layer) },
        ];
        if (positionUrl) actions.push({ label: 'Pomjeri', run: () => triggerLayer.dragging?.enable() });
        showCadContext(event.latlng, title, actions);
    };
    triggerLayer.on('contextmenu', openMenu);
    triggerLayer.on('click', event => {
        if (mode === 'trace-branch') { L.DomEvent.stop(event); handleTraceBranchClick(event.latlng); return; }
        // A saved route line (markers already handle 'draw' mode via their own
        // dedicated click listener, registered before this one — don't
        // duplicate the point insertion for them here).
        if (mode === 'draw' && typeof triggerLayer.getLatLng !== 'function') {
            L.DomEvent.stop(event);
            triggerLayer.closePopup?.();
            addDrawPoint(event.latlng);
            return;
        }
        if (!['pan', 'join'].includes(mode)) return;
        if (layerLocked(triggerLayer._ftthLayerType)) {
            document.getElementById('cad-command').textContent = `Layer ${triggerLayer._ftthLayerType} je zaključan.`;
            return;
        }
        if (clickAction) {
            L.DomEvent.stop(event);
            triggerLayer.closePopup?.();
            clickAction(event);
        } else {
            openMenu(event);
        }
    });
}

// ─── BOX SELECT ──────────────────────────────────────────────────────────────
(function initBoxSelect() {
    const rb         = document.getElementById('select-rubber-band');
    const actPanel   = document.getElementById('select-actions');
    const countEl    = document.getElementById('select-count');
    const delBtn     = document.getElementById('select-delete-btn');
    const cancelBtn  = document.getElementById('select-cancel-btn');
    const assignBtn  = document.getElementById('select-assign-btn');
    const assignPanel = document.getElementById('cabinet-assign-panel');
    const assignList  = document.getElementById('cabinet-assign-list');
    const assignStatus = document.getElementById('cabinet-assign-status');
    const assignCancel = document.getElementById('cabinet-assign-cancel');
    const mapCont    = document.getElementById('map-container');

    let dragStart = null;
    let dragging  = false;

    function containerOffset(e) {
        const rect = mapCont.getBoundingClientRect();
        return { x: e.clientX - rect.left, y: e.clientY - rect.top };
    }

    function showRubberBand(a, b) {
        const x = Math.min(a.x, b.x), y = Math.min(a.y, b.y);
        const w = Math.abs(a.x - b.x),  h = Math.abs(a.y - b.y);
        rb.style.left   = x + 'px';
        rb.style.top    = y + 'px';
        rb.style.width  = w + 'px';
        rb.style.height = h + 'px';
        rb.style.display = 'block';
    }

    function hideRubberBand() {
        rb.style.display = 'none';
    }

    function showActionsPanel() {
        actPanel.style.display = 'flex';
    }
    function hideActionsPanel() {
        actPanel.style.display = 'none';
        hideAssignPanel();
    }

    // ── Cabinet assign panel ──────────────────────────────────────────────────
    function selectedHouseIds() {
        const base = appConfig.housesBase;
        return currentSelection
            .filter(e => !e.isDxf && e.url && e.url.startsWith(base))
            .map(e => parseInt(e.url.split('/').pop(), 10))
            .filter(id => !isNaN(id));
    }

    function updateAssignBtn() {
        const count = selectedHouseIds().length;
        assignBtn.style.display = count > 0 ? 'inline-flex' : 'none';
        if (count > 0) assignBtn.textContent = `⤵ Dodijeli ODO (${count} kuća)`;
    }

    function hideAssignPanel() {
        assignPanel.style.display = 'none';
        assignStatus.style.display = 'none';
        assignStatus.textContent = '';
    }

    function showAssignPanel() {
        const projectId = parseInt(document.getElementById('active-project-id')?.value, 10);
        const cabinets = (data.cabinets || []).filter(c => !projectId || c.project_id === projectId);

        if (!cabinets.length) {
            assignStatus.textContent = 'Nema ODO ormarića za ovaj projekat.';
            assignStatus.style.display = 'block';
            assignList.innerHTML = '';
            assignPanel.style.display = 'block';
            return;
        }

        assignList.innerHTML = cabinets.map(c => {
            const free = c.free_ports ?? (c.capacity - (c.used_ports ?? 0));
            const pct  = c.capacity > 0 ? Math.round((c.used_ports ?? 0) / c.capacity * 100) : 0;
            const barColor = pct >= 90 ? '#ef4444' : pct >= 70 ? '#f59e0b' : '#22c55e';
            const disabled = free <= 0;
            return `<button type="button" data-cabinet-id="${c.id}"
                style="width:100%;text-align:left;padding:7px 9px;border-radius:6px;
                       border:1px solid ${disabled ? '#374151' : '#4c1d95'};
                       background:${disabled ? '#111827' : '#1e0a3c'};
                       color:${disabled ? '#6b7280' : '#e2e8f0'};
                       cursor:${disabled ? 'not-allowed' : 'pointer'};
                       font:600 11px/1.3 system-ui,sans-serif;display:grid;gap:3px;"
                ${disabled ? 'disabled' : ''}>
                <span style="display:flex;justify-content:space-between;">
                    <span>${c.name}</span>
                    <span style="color:${disabled ? '#6b7280' : '#a78bfa'};font-size:10px;">${free}/${c.capacity} slobodnih</span>
                </span>
                <span style="height:3px;border-radius:2px;background:#374151;overflow:hidden;">
                    <span style="display:block;height:100%;width:${pct}%;background:${barColor};border-radius:2px;"></span>
                </span>
            </button>`;
        }).join('');

        assignStatus.style.display = 'none';
        assignPanel.style.display = 'block';

        assignList.querySelectorAll('[data-cabinet-id]').forEach(btn => {
            btn.addEventListener('click', () => assignHousesToCabinet(parseInt(btn.dataset.cabinetId, 10)));
        });
    }

    async function assignHousesToCabinet(cabinetId) {
        const houseIds = selectedHouseIds();
        if (!houseIds.length) return;

        assignList.querySelectorAll('button').forEach(b => b.disabled = true);
        assignStatus.textContent = 'Dodjeljivanje...';
        assignStatus.style.display = 'block';

        const csrf = document.querySelector('meta[name="csrf-token"]')?.content || '';
        try {
            const res = await fetch(`${appConfig.cabinetsBase}/${cabinetId}/povezi-kuce`, {
                method: 'POST',
                headers: {
                    'X-CSRF-TOKEN': csrf,
                    'Accept': 'application/json',
                    'Content-Type': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                },
                body: JSON.stringify({ house_ids: houseIds }),
            });

            const json = await res.json().catch(() => ({}));
            if (!res.ok) {
                assignStatus.textContent = json.message || 'Greška pri dodjeli.';
                assignList.querySelectorAll('button').forEach(b => b.disabled = false);
                return;
            }

            // Ažuriraj boje markera dodijeljenih kuća
            const color = cabinetColor(cabinetId);
            houseIds.forEach(hid => {
                const marker = houseMarkerById[hid];
                if (marker) marker.setIcon(icon('house', '', color));
            });

            // Ažuriraj slobodne portove u data.cabinets za ovaj session
            const cab = (data.cabinets || []).find(c => c.id === cabinetId);
            if (cab) {
                cab.used_ports = (cab.used_ports ?? 0) + houseIds.length;
                cab.free_ports = Math.max(0, cab.capacity - cab.used_ports);
            }

            hideAssignPanel();
            clearSelection();
            setMode('pan');
            document.getElementById('cad-command').textContent =
                `Dodijeljeno ${houseIds.length} kuca ODO-u ${cab?.name ?? cabinetId}. Drop trase su kreirane automatski.`;

        } catch (err) {
            assignStatus.textContent = 'Greška: ' + err.message;
            assignList.querySelectorAll('button').forEach(b => b.disabled = false);
        }
    }

    assignBtn.addEventListener('click', () => {
        if (assignPanel.style.display === 'block') hideAssignPanel();
        else showAssignPanel();
    });
    assignCancel.addEventListener('click', hideAssignPanel);

    function applyHighlight(entry, on) {
        if (on && !entry._origStyles) entry._origStyles = {};
        entry.allLayers.forEach((l, i) => {
            if (!l || !map.hasLayer(l)) return;
            if (typeof l.setStyle === 'function') {
                if (on) {
                    if (!entry._origStyles[i]) entry._origStyles[i] = { color: l.options.color, weight: l.options.weight, opacity: l.options.opacity };
                    l.setStyle({ color: '#3b82f6', weight: (l.options.weight || 3) + 2, opacity: 1 });
                } else if (entry._origStyles?.[i]) {
                    l.setStyle(entry._origStyles[i]);
                }
            } else if (l.getElement) {
                const el = l.getElement();
                if (el) el.style.filter = on ? 'drop-shadow(0 0 6px #3b82f6) brightness(1.3)' : '';
            }
        });
        if (!on) entry._origStyles = null;
    }

    function clearSelection() {
        currentSelection.forEach(e => {
            if (e.isDxf) {
                e._geomLayer?.setHighlight(false);
            } else {
                applyHighlight(e, false);
            }
        });
        currentSelection = [];
        assignBtn.style.display = 'none';
        hideActionsPanel();
        countEl.textContent = '0 selektovano';
    }

    function doBoxSelect(a, b) {
        const p1 = map.containerPointToLatLng(L.point(a.x, a.y));
        const p2 = map.containerPointToLatLng(L.point(b.x, b.y));
        const bounds = L.latLngBounds(p1, p2);

        clearSelection();

        selectionRegistry.forEach(entry => {
            if (!map.hasLayer(entry.triggerLayer)) return;
            if (layerLocked(entry.triggerLayer._ftthLayerType)) return;
            let hit = false;
            const lll = entry.triggerLayer.getLatLng?.();
            if (lll) {
                hit = bounds.contains(lll);
            } else {
                const lls = entry.triggerLayer.getLatLngs?.();
                if (lls) {
                    const flat = lls.flat ? lls.flat(2) : lls;
                    hit = flat.some(ll => bounds.contains(ll));
                }
            }
            if (hit) {
                entry.kind = entryKind(entry.url);
                entry.id = entryId(entry.url);
                currentSelection.push(entry);
                applyHighlight(entry, true);
            }
        });

        // DXF background layeri — intersect
        const dxfItems = window.ftthDxfLayer?.getSelectableItems() ?? [];
        dxfItems.forEach(item => {
            if (!item.bounds || !bounds.intersects(item.bounds)) return;
            const entry = {
                isDxf: true,
                dxfId: item.dxfId,
                title: item.name,
                allLayers: [item.geomLayer, item.textLayer].filter(Boolean),
                _geomLayer: item.geomLayer,
            };
            currentSelection.push(entry);
            item.geomLayer.setHighlight(true);
        });

        if (currentSelection.length > 0) {
            countEl.textContent = currentSelection.length + ' selektovano';
            updateAssignBtn();
            showActionsPanel();
            const hCount = selectedHouseIds().length;
            const hint = hCount > 0 ? ` (${hCount} kuća → "Dodijeli ODO")` : '';
            document.getElementById('cad-command').textContent =
                `SELEKT: ${currentSelection.length} element(a) selektovano${hint}. Klikni akciju ili ESC.`;
        } else {
            document.getElementById('cad-command').textContent = 'SELEKT: drag da označiš elemente.';
        }
    }

    mapCont.addEventListener('mousedown', e => {
        if (mode !== 'select') return;
        if (e.button !== 0) return;
        dragStart = containerOffset(e);
        dragging = false;
        e.preventDefault();
    });

    window.addEventListener('mousemove', e => {
        if (mode !== 'select' || !dragStart) return;
        const cur = containerOffset(e);
        const dx = Math.abs(cur.x - dragStart.x), dy = Math.abs(cur.y - dragStart.y);
        if (dx > 4 || dy > 4) {
            dragging = true;
            showRubberBand(dragStart, cur);
        }
    });

    window.addEventListener('mouseup', e => {
        if (mode !== 'select' || !dragStart) return;
        const cur = containerOffset(e);
        hideRubberBand();
        if (dragging) {
            doBoxSelect(dragStart, cur);
        }
        dragStart = null;
        dragging  = false;
    });

    delBtn.addEventListener('click', async () => {
        if (!currentSelection.length) return;
        if (!confirm(`Obrisati ${currentSelection.length} element(a)?`)) return;

        const toDelete = [...currentSelection];
        clearSelection();
        setMode('pan');

        const csrf = document.querySelector('meta[name="csrf-token"]')?.content || '';
        let ok = 0, fail = 0;
        for (const entry of toDelete) {
            try {
                if (entry.isDxf) {
                    window.ftthDxfLayer?.removeLayerById(entry.dxfId);
                    ok++;
                } else {
                    const res = await fetch(entry.url, {
                        method: 'DELETE',
                        headers: { 'X-CSRF-TOKEN': csrf, 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                    });
                    if (res.ok) {
                        entry.allLayers.forEach(l => { if (l && map.hasLayer(l)) map.removeLayer(l); });
                        ok++;
                    } else {
                        fail++;
                    }
                }
            } catch {
                fail++;
            }
        }
        document.getElementById('cad-command').textContent =
            `Obrisano: ${ok}${fail ? ', greška: ' + fail : ''}.`;
    });

    cancelBtn.addEventListener('click', () => {
        clearSelection();
        document.getElementById('cad-command').textContent = 'SELEKT: drag da označiš elemente.';
    });

    document.addEventListener('keydown', e => {
        if (e.key === 'Escape' && mode === 'select' && currentSelection.length) {
            clearSelection();
            document.getElementById('cad-command').textContent = 'SELEKT: drag da označiš elemente.';
        }
    });
})();
