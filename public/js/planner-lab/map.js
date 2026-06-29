(function () {
    'use strict';

    const COLORS = {
        odf:          '#1d4ed8',
        house:        '#6b7280',
        houseAssigned:'#10b981',
        cabinet:      '#7c3aed',
        routeBackbone:'#1e40af',
        routeFeeder:  '#0891b2',
        routeDist:    '#7c3aed',
        routeDrop:    '#6b7280',
        routeTrench:  '#92400e',
        previewCab:   '#f59e0b',
        previewRoute: '#f97316',
        previewDrop:  '#fb923c',
        warning:      '#ef4444',
    };

    var _osmLayer, _satLayer, _satLabels;

    PlannerLab.initMap = function (lat, lng, zoom) {
        const mapEl = document.getElementById('pl-map');
        if (!mapEl) {
            console.error('Planner Lab map container #pl-map is missing.');
            return;
        }

        PlannerLab.map = L.map(mapEl, { zoomControl: true }).setView([lat, lng], zoom || 14);

        _osmLayer = L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
            attribution: '© OpenStreetMap contributors',
            maxZoom: 20,
        });

        _satLayer = L.tileLayer('https://server.arcgisonline.com/ArcGIS/rest/services/World_Imagery/MapServer/tile/{z}/{y}/{x}', {
            attribution: 'Tiles © Esri',
            maxZoom: 20,
            maxNativeZoom: 18,
        });

        // Prozirni sloj s imenima ulica na satelitu
        _satLabels = L.tileLayer('https://{s}.basemaps.cartocdn.com/light_only_labels/{z}/{x}/{y}{r}.png', {
            attribution: '© CartoDB',
            maxZoom: 20,
            maxNativeZoom: 18,
            opacity: 0.9,
            pane: 'overlayPane',
        });

        // Satelit kao default
        _satLayer.addTo(PlannerLab.map);
        _satLabels.addTo(PlannerLab.map);
        PlannerLab._isSat = true;
    };

    PlannerLab.toggleLayer = function () {
        if (PlannerLab._isSat) {
            PlannerLab.map.removeLayer(_satLayer);
            PlannerLab.map.removeLayer(_satLabels);
            _osmLayer.addTo(PlannerLab.map);
            PlannerLab._isSat = false;
        } else {
            PlannerLab.map.removeLayer(_osmLayer);
            _satLayer.addTo(PlannerLab.map);
            _satLabels.addTo(PlannerLab.map);
            PlannerLab._isSat = true;
        }
        var lbl = document.getElementById('btn-layer-label');
        if (lbl) lbl.textContent = PlannerLab._isSat ? 'OSM' : 'Satelit';
    };

    PlannerLab.renderExisting = function () {
        PlannerLab.clearExisting();

        const { odfs, houses, cabinets, routes } = PlannerLab.data;

        routes.forEach(function (r) {
            if (!r.path || r.path.length < 2) return;
            const color = COLORS['route' + capitalize(r.route_type)] || '#94a3b8';
            const line = L.polyline(r.path, {
                color, weight: r.route_type === 'trench' ? 3 : 2,
                opacity: 0.6, dashArray: r.route_type === 'trench' ? '4 4' : null,
            }).addTo(PlannerLab.map);
            line.bindTooltip(r.name || r.route_type, { sticky: true });
            PlannerLab._existingLayers.routes.push(line);
        });

        cabinets.forEach(function (c) {
            if (!c.latitude || !c.longitude) return;
            const m = L.circleMarker([c.latitude, c.longitude], {
                radius: 7, color: COLORS.cabinet, fillColor: COLORS.cabinet,
                fillOpacity: 0.8, weight: 2,
            }).addTo(PlannerLab.map);
            m.bindTooltip('<b>' + (c.name || 'ODO') + '</b><br>Read-only', { sticky: true });
            PlannerLab._existingLayers.cabinets.push(m);
        });

        houses.forEach(function (h) {
            if (!h.latitude || !h.longitude) return;
            const color = h.cabinet_id ? COLORS.houseAssigned : COLORS.house;
            const m = L.circleMarker([h.latitude, h.longitude], {
                radius: 4, color, fillColor: color,
                fillOpacity: 0.75, weight: 1,
            }).addTo(PlannerLab.map);
            m.bindTooltip((h.label || h.address || 'Kuća') + (h.cabinet_id ? ' ✓' : ''), { sticky: true });
            PlannerLab._existingLayers.houses.push(m);
        });

        odfs.forEach(function (o) {
            if (!o.latitude || !o.longitude) return;
            const icon = L.divIcon({
                html: '<div style="background:' + COLORS.odf + ';color:#fff;border-radius:4px;padding:2px 5px;font-size:10px;font-weight:700;white-space:nowrap">' + (o.name || 'ODF') + '</div>',
                className: '',
                iconAnchor: [0, 10],
            });
            const m = L.marker([o.latitude, o.longitude], { icon }).addTo(PlannerLab.map);
            PlannerLab._existingLayers.odfs.push(m);
        });

        PlannerLab.fitExisting();
    };

    PlannerLab.clearExisting = function () {
        Object.values(PlannerLab._existingLayers).forEach(function (arr) {
            arr.forEach(function (l) { PlannerLab.map.removeLayer(l); });
        });
        PlannerLab._existingLayers = { odfs: [], houses: [], cabinets: [], routes: [] };
    };

    PlannerLab.fitExisting = function () {
        const all = [
            ...PlannerLab.data.odfs.filter(o => o.latitude && o.longitude).map(o => [o.latitude, o.longitude]),
            ...PlannerLab.data.houses.filter(h => h.latitude && h.longitude).map(h => [h.latitude, h.longitude]),
        ];
        if (all.length > 0) {
            PlannerLab.map.fitBounds(L.latLngBounds(all).pad(0.1));
        }
    };

    function capitalize(s) {
        return s ? s.charAt(0).toUpperCase() + s.slice(1) : '';
    }
})();
