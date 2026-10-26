// Geodetski objekti (stubovi, ODF, Z.P.) na karti: filter, pretraga, grupisanje i oznake.
// Učitava se za sve uloge; kontrole iz survey panela su opcionalne (samo za urednike).
(function () {
    if (!surveyObjectMarkers.length) return;

    const objectSearch = document.getElementById('survey-object-search');
    const objectFilter = document.getElementById('survey-object-filter');
    const labelMode = document.getElementById('survey-label-mode');
    const clusteringToggle = document.getElementById('survey-clustering-toggle');
    const objectSearchResult = document.getElementById('survey-object-search-result');
    const preferenceKey = `ftth-survey-display-${document.getElementById('active-project-id')?.value || 'all'}`;
    let clusterLayers = [];
    let labelFrame = null;

    try {
        const preferences = JSON.parse(localStorage.getItem(preferenceKey) || '{}');
        if (labelMode && preferences.labels) labelMode.value = preferences.labels;
        if (clusteringToggle && typeof preferences.clustering === 'boolean') clusteringToggle.checked = preferences.clustering;
    } catch (_) { /* Neispravne stare postavke — koriste se zadane. */ }

    function savePreferences() {
        try {
            localStorage.setItem(preferenceKey, JSON.stringify({ labels: labelMode?.value || 'auto', clustering: clusteringToggle?.checked !== false }));
        } catch (_) { /* Storage nije dostupan (privatni prozor). */ }
    }

    function matches(item, query, filter) {
        const textMatch = !query || `${item.point_no} ${item.original_label || ''}`.toLocaleLowerCase('bs').includes(query);
        const filterMatch = filter === 'all'
            || (filter === 'poles' && item.is_pole)
            || (filter === 'other' && !item.is_pole)
            || item.category === filter;

        return textMatch && filterMatch;
    }

    function labelText(item, mode) {
        if (mode === 'number') return String(item.point_no);
        if (mode === 'original') return String(item.original_label || '');
        if (mode === 'full' || !item.is_pole) return `${item.point_no} / ${item.original_label}`;
        return item.cad_label || String(item.point_no);
    }

    // Skriva oznake koje bi se preklopile; ODF oznake imaju prednost.
    function placeLabels(markers, mode) {
        const occupied = new Set();
        const ordered = [...markers].sort((a, b) => Number(b._surveyObject?.category === 'odf') - Number(a._surveyObject?.category === 'odf'));
        ordered.forEach(marker => {
            const label = marker.getElement()?.querySelector('[data-survey-label]');
            if (!label) return;
            label.textContent = labelText(marker._surveyObject || {}, mode);
            label.style.display = mode === 'none' ? 'none' : '';
            if (mode === 'none') return;
            const rect = label.getBoundingClientRect();
            const cells = [];
            for (let x = Math.floor(rect.left / 80); x <= Math.floor(rect.right / 80); x++) {
                for (let y = Math.floor(rect.top / 24); y <= Math.floor(rect.bottom / 24); y++) cells.push(`${x}:${y}`);
            }
            if (cells.some(cell => occupied.has(cell))) { label.style.display = 'none'; return; }
            cells.forEach(cell => occupied.add(cell));
        });
    }

    function clusterMarkerFor(group) {
        const lat = group.reduce((sum, marker) => sum + marker.getLatLng().lat, 0) / group.length;
        const lng = group.reduce((sum, marker) => sum + marker.getLatLng().lng, 0) / group.length;
        const cluster = L.marker([lat, lng], { icon: L.divIcon({ className: 'survey-cluster-icon', iconSize: [24, 24], iconAnchor: [12, 12], html: `<span>${group.length}</span>` }) });
        cluster.on('click', () => {
            const bounds = L.latLngBounds(group.map(marker => marker.getLatLng()));
            if (map.getZoom() < 18 && !bounds.getNorthEast().equals(bounds.getSouthWest())) {
                map.fitBounds(bounds.pad(0.5), { maxZoom: Math.min(18, map.getZoom() + 2) });
            } else {
                cluster.bindPopup(`<b>${group.length} objekata</b><br>${group.map(marker => `${escapeHtml(marker._surveyObject.point_no)} / ${escapeHtml(marker._surveyObject.original_label)}`).join('<br>')}`).openPopup();
            }
        });

        return cluster;
    }

    function render() {
        clusterLayers.forEach(cluster => map.removeLayer(cluster));
        clusterLayers = [];
        const query = String(objectSearch?.value || '').trim().toLocaleLowerCase('bs');
        const filter = objectFilter?.value || 'all';
        const layerOn = layerVisible('survey');
        const view = map.getBounds().pad(0.25);
        const lowZoom = map.getZoom() <= 15;
        let matchCount = 0;
        const groups = new Map();
        surveyObjectMarkers.forEach(marker => {
            if (!layerOn || !matches(marker._surveyObject || {}, query, filter)) return;
            matchCount++;
            const latLng = marker.getLatLng();
            if (!view.contains(latLng)) return;
            // Na manjem zoomu grupišemo po ekranskoj ćeliji, inače samo tačke na istoj poziciji.
            const pixel = lowZoom ? map.latLngToContainerPoint(latLng) : null;
            const key = lowZoom ? `${Math.floor(pixel.x / 38)}:${Math.floor(pixel.y / 38)}` : `${latLng.lat.toFixed(7)}:${latLng.lng.toFixed(7)}`;
            if (!groups.has(key)) groups.set(key, []);
            groups.get(key).push(marker);
        });

        const shown = new Set();
        groups.forEach(group => {
            if (clusteringToggle?.checked === false || group.length === 1) {
                group.forEach(marker => shown.add(marker));
                return;
            }
            const cluster = clusterMarkerFor(group).addTo(map);
            clusterLayers.push(cluster);
        });
        // Dodaj/ukloni samo markere kojima se stanje promijenilo.
        surveyObjectMarkers.forEach(marker => {
            const visible = shown.has(marker);
            if (visible !== map.hasLayer(marker)) visible ? marker.addTo(map) : map.removeLayer(marker);
        });

        cancelAnimationFrame(labelFrame);
        labelFrame = requestAnimationFrame(() => placeLabels([...shown], labelMode?.value === 'auto' ? 'stable' : (labelMode?.value || 'stable')));
        if (objectSearchResult) {
            objectSearchResult.style.display = query || filter !== 'all' ? 'block' : 'none';
            objectSearchResult.textContent = `${matchCount} prikazanih geodetskih objekata.`;
        }
    }

    function focusFirstMatch() {
        const query = String(objectSearch?.value || '').trim().toLocaleLowerCase('bs');
        const filter = objectFilter?.value || 'all';
        const first = query && surveyObjectMarkers.find(marker => matches(marker._surveyObject || {}, query, filter));
        if (first) map.setView(first.getLatLng(), Math.max(map.getZoom(), 19));
        render();
    }

    objectSearch?.addEventListener('input', focusFirstMatch);
    objectFilter?.addEventListener('change', render);
    labelMode?.addEventListener('change', () => { savePreferences(); render(); });
    clusteringToggle?.addEventListener('change', () => { savePreferences(); render(); });
    customLayerRenderers.survey = render;
    map.on('zoomend moveend', render);
    render();
})();
