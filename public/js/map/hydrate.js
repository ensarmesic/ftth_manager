// ── DATA LOADING ──────────────────────────────────────────────────────────────
const bounds = [];
applyRouteStacking(data.routes);
applyRouteLabelLanes(data.routes);
applyRouteVisualLanes(data.routes);
data.routes.forEach(route => {
    if (!route.path?.length) return;
    const points = route.path.map(point => L.latLng(point[0], point[1]));
    savedRoutePoints.push(points);
    // Keep exact surveyed geometry for calculations/editing, while overlapping drops
    // receive a very small parallel display lane inside the trench corridor.
    const displayPoints = route.type !== 'trench'
        ? offsetRouteDisplayPoints(points, route._visualOffsetM || 0)
        : points;
    const occupancy = route.occupancy || {};
    const baseStyle = routeLineStyle(route.type, routeLineColor(route));
    if (route._stack) baseStyle.weight = routeStackedWeight(route, baseStyle.weight);
    const line = L.polyline(displayPoints, { ...baseStyle, interactive: false })
        .bindPopup(`<b>${route.name}</b><br>${routeTypeLabel(route.type)}<br>${route.duct_length_m} m<br>Fiber: ${occupancy.fiber_capacity ?? route.fibers ?? 0}F<br>Zauzeto: ${occupancy.used_fibers ?? '-'}<br>Slobodno: ${occupancy.free_fibers ?? '-'}<br>Iskorištenost: ${occupancy.utilization_percent ?? '-'}%`)
        .addTo(map);
    const hitLine = L.polyline(displayPoints, { weight: 10, opacity: 0, interactive: true }).addTo(map);
    bindRouteHover(route, line, hitLine);
    if (route.type === 'trench') {
        line.bringToBack();
        hitLine.bringToBack();
    }
    const labels = shouldShowPersistentRouteLabel(route) ? addRouteLabel(displayPoints, route.name, false, routeLabelSpecs(route), route._labelLane) : [];
    routeLayerById[route.id] = line;
    routeHitLayerById[route.id] = hitLine;
    routeLabelsById[route.id] = labels || [];
    const renderedRouteLayers = [hitLine, line, ...labels];
    trackLayer(line, routeLayerType(route.type));
    trackLayer(hitLine, routeLayerType(route.type));
    labels?.forEach(label => trackLayer(label, routeLayerType(route.type)));
    registerSavedContext(renderedRouteLayers, route.name, deleteUrls.route(route.id), null, event => {
        if (mode === 'join') selectJoinRoute(route, line);
        else if (routeEdit?.route.id === route.id) addRouteEditVertex(event.latlng);
        else {
            selectRouteFromVisibleStack(event, route);
        }
    }, [], () => deleteRouteWithHistory(route, renderedRouteLayers));
    points.forEach(p => bounds.push([p.lat, p.lng]));
});
(data.gis_segments || []).forEach(segment => {
    if (!segment.path?.length) return;
    const points = segment.path.map(point => L.latLng(point[0], point[1]));
    const line = L.polyline(points, gisSegmentStyle(segment.segment_type))
        .bindPopup(`<b>${segment.name || 'GIS segment'}</b><br>${segment.segment_type}<br>${segment.length_m || distance(points)} m<br>${segment.project || ''}`)
        .addTo(map);
    trackLayer(line, 'gis');
    points.forEach(p => bounds.push([p.lat, p.lng]));
});
(data.gis_restricted_areas || []).forEach(area => {
    if (!area.polygon?.length) return;
    const points = area.polygon.map(point => L.latLng(point[0], point[1]));
    const polygon = L.polygon(points, {
        color: '#dc2626',
        weight: 2,
        opacity: .85,
        fillColor: '#ef4444',
        fillOpacity: .18,
        dashArray: '6 5',
    })
        .bindPopup(`<b>${area.name || 'Zabranjena zona'}</b><br>${area.area_type || 'restricted'}<br>${area.project || ''}`)
        .addTo(map);
    trackLayer(polygon, 'gis');
    points.forEach(p => bounds.push([p.lat, p.lng]));
});
data.odfs.forEach(odf => {
    const p = L.latLng(odf.lat, odf.lng);
    const connectedCabinets = data.cabinets.filter(c => c.odf_id === odf.id).length;
    const marker = L.marker(p, { icon: icon('odf', 'ODF'), draggable: false })
        .bindTooltip(`${odf.name} · ${connectedCabinets} FTTH`, { direction: 'top', offset: [0, -10] })
        .bindPopup(`<b>ODF: ${odf.name}</b><br>${odf.address}<br>FTTH ormarića: ${connectedCabinets}`)
        .addTo(map);
    marker.on('click', event => {
        if (layerLocked('odf')) return document.getElementById('cad-command').textContent = 'Layer ODF je zaključan.';
        if (mode === 'connect') {
            L.DomEvent.stop(event);
            selectConnectOdf(odf);
            return;
        }
        if (mode === 'draw') {
            map.closePopup();
            addDrawPoint(event.latlng);
        }
    });
    registerSavedContext(marker, `ODF: ${odf.name}`, deleteUrls.odf(odf.id), positionUrls.odf(odf.id), null, [
        { label: 'Uredi podatke', run: () => selectDraftElement('odf', { ...odf, marker, saved: true }) },
    ]);
    odfMarkerById[odf.id] = marker;
    trackLayer(marker, 'odf');
    bounds.push([odf.lat, odf.lng]);
});
data.cabinets.forEach(c => {
    const p = L.latLng(c.lat, c.lng);
    const color = cabinetColor(c.id);
    const pct = Math.round((Number(c.used_ports) || 0) / Math.max(Number(c.capacity) || 1, 1) * 100);
    const marker = L.marker(p, { icon: icon('cabinet', c.name || `FTTH ${c.id}`, color), draggable: false })
        .bindTooltip(`${c.used_ports}/${c.capacity} (${pct}%)`, { direction: 'top', offset: [0, -10] })
        .bindPopup(`<b>${c.name}</b><br>${c.used_ports}/${c.capacity} portova (${pct}%)<br>ODF: ${c.odf}<br><a href="/fiber-sema?project=${c.project_id}&cabinet=${c.id}">Otvori fiber šemu</a>`)
        .addTo(map);
    marker.on('click', event => {
        if (layerLocked('odo')) return document.getElementById('cad-command').textContent = 'Layer ODO je zaključan.';
        if (mode === 'connect-houses') {
            L.DomEvent.stop(event);
            selectHouseConnectCabinet(c);
            return;
        }
        if (mode === 'connect') {
            L.DomEvent.stop(event);
            connectSelectedOdfToCabinet(c);
            return;
        }
        if (mode === 'branch-source') {
            L.DomEvent.stop(event);
            startBranchFromCabinet(c);
            return;
        }
        if (mode === 'draw') {
            map.closePopup();
            addDrawPoint(event.latlng);
            const source = document.getElementById('route-start-source')?.value || '';
            if (source.startsWith('cabinet:') && Number(source.split(':')[1]) !== Number(c.id)) finishBranch();
        }
    });
    registerSavedContext(marker, c.name, deleteUrls.cabinet(c.id), positionUrls.cabinet(c.id), null, [
        { label: 'Uredi podatke', run: () => selectDraftElement('cabinet', { ...c, marker, saved: true }) },
        { label: 'Novi krak odavde', run: () => startBranchFromCabinet(c) },
    ]);
    cabinetMarkerById[c.id] = marker;
    trackLayer(marker, 'odo');
    bounds.push([c.lat, c.lng]);
});
data.houses.forEach(h => {
    const p = L.latLng(h.lat, h.lng);
    const key = pointKey(h.lat, h.lng);
    const color = h.cabinet_id ? cabinetColor(h.cabinet_id) : null;
    savedHouseKeys.add(key);
    savedHouseColorByKey[key] = color;
    houseDataByKey[key] = h;
    const marker = L.marker(p, { icon: icon('house', houseIconText(h), color), draggable: false }).bindPopup(`<b>${h.label}</b><br>ODO: ${h.cabinet}`).addTo(map);
    marker.on('click', event => {
        if (layerLocked('houses')) return document.getElementById('cad-command').textContent = 'Layer kuće je zaključan.';
        if (mode === 'connect-houses') {
            L.DomEvent.stop(event);
            toggleHouseConnect(h);
            return;
        }
        if (mode === 'draw') {
            map.closePopup();
            addDrawPoint(event.latlng);
            return;
        }
        if (mode === 'trace') showFiberTrace(h.id);
    });
    registerSavedContext(marker, h.label, deleteUrls.house(h.id), positionUrls.house(h.id), null, [
        { label: 'Uredi podatke', run: () => selectDraftElement('house', { ...h, marker, saved: true }) },
    ]);
    trackLayer(marker, 'houses');
    houseMarkerByKey[key] = marker;
    houseMarkerById[h.id] = marker;
    housePoints.push(p);
    bounds.push([h.lat, h.lng]);
});
data.appendix_items?.forEach(item => {
    const p = L.latLng(item.lat, item.lng);
    if (item.type === 'boring_fi_130') {
        drawSavedBoring(item);
    } else if (item.type === 'splice') {
        const marker = L.marker(p, { icon: icon('splice', 'S'), draggable: false })
            .bindTooltip('Spojnica', { direction: 'top', offset: [0, -10] })
            .bindPopup(`<b>Spojnica</b>${item.note ? `<br>${item.note}` : ''}`)
            .addTo(map);
        trackLayer(marker, 'preview');
    } else if (item.type === 'loop') {
        const marker = L.marker(p, { icon: icon('loop', 'R'), draggable: false })
            .bindTooltip('Rezerva/slinga', { direction: 'top', offset: [0, -10] })
            .bindPopup(`<b>Rezerva/slinga</b>${item.note ? `<br>${item.note}` : ''}`)
            .addTo(map);
        trackLayer(marker, 'preview');
    } else {
        const marker = L.marker(p, { icon: icon('manhole', 'S'), draggable: false })
            .bindTooltip(`Prolazni saht: ${item.quantity} ${item.unit}`, { direction: 'top', offset: [0, -10] })
            .bindPopup(`<b>Prolazni saht</b><br>${item.quantity} ${item.unit}${item.note ? `<br>${item.note}` : ''}`)
            .addTo(map);
        trackLayer(marker, 'preview');
    }
    bounds.push([item.lat, item.lng]);
});
savedHouseCount = housePoints.length;
if (bounds.length) map.fitBounds(bounds, { padding: [40, 40], maxZoom: 19 }); else map.setView(defaultCenter, 17);
