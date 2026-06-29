(function () {
    'use strict';

    const BRANCH_COLORS = [
        '#f97316', '#3b82f6', '#10b981', '#8b5cf6',
        '#ef4444', '#06b6d4', '#eab308', '#84cc16',
        '#f43f5e', '#a855f7',
    ];

    function branchColor(index) {
        return BRANCH_COLORS[index % BRANCH_COLORS.length];
    }

    PlannerLab.renderPreview = function (plan) {
        PlannerLab.clearPreview();
        PlannerLab.preview.plan = plan;

        // Mapa: branch.temp_id → boja
        const branchColorMap = {};
        (plan.planned_branches || []).forEach(function (branch, i) {
            branchColorMap[branch.temp_id] = branchColor(i);
        });

        // Mapa: cabinet_temp_id → boja kraka
        const cabinetColorMap = {};
        (plan.planned_branches || []).forEach(function (branch, i) {
            const color = branchColor(i);
            (branch.cabinets || []).forEach(function (c) {
                cabinetColorMap[c.temp_id] = color;
            });
        });

        // Trase (po krakovima)
        (plan.planned_routes || []).forEach(function (r) {
            if (!r.path || r.path.length < 2) return;
            const color = r.branch_temp_id
                ? (branchColorMap[r.branch_temp_id] || '#f97316')
                : '#f97316';

            const line = L.polyline(r.path, {
                color,
                weight: 3,
                opacity: 0.9,
                dashArray: r.follows_road ? null : '8 5',
            }).addTo(PlannerLab.map);

            line.bindTooltip(
                '<b>' + r.name + '</b>' +
                (r.branch_name ? '<br>Krak: ' + r.branch_name : '') +
                '<br>' + Math.round(r.length_m) + ' m' +
                '<br>' + (r.follows_road ? '✓ prati cestu' : '⚠ ravna linija'),
                { sticky: true }
            );
            PlannerLab.preview.layers.push(line);
        });

        // Labele krakova na sredini prvog segmenta
        (plan.planned_branches || []).forEach(function (branch, i) {
            if (!branch.cabinets || branch.cabinets.length === 0) return;
            const color = branchColor(i);
            const branchRoutes = (plan.planned_routes || []).filter(r => r.branch_temp_id === branch.temp_id);
            if (branchRoutes.length === 0) return;
            const firstRoute = branchRoutes[0];
            if (!firstRoute.path || firstRoute.path.length < 2) return;

            const midPt = firstRoute.path[Math.floor(firstRoute.path.length / 2)];
            if (!midPt) return;
            const icon = L.divIcon({
                html: '<div style="background:' + color + ';color:#fff;border-radius:3px;padding:1px 5px;font-size:9px;font-weight:700;white-space:nowrap;opacity:.9">' + branch.name + '</div>',
                className: '',
                iconAnchor: [0, 6],
            });
            PlannerLab.preview.layers.push(L.marker(midPt, { icon, interactive: false }).addTo(PlannerLab.map));
        });

        // Drop trase (tanke linije ODO → kuća, ista boja kao krak)
        (plan.planned_drops || []).forEach(function (d) {
            if (!d.path || d.path.length < 2) return;
            const color = cabinetColorMap[d.cabinet_temp_id] || '#94a3b8';
            const line = L.polyline(d.path, {
                color,
                weight: 1.5,
                opacity: 0.55,
                dashArray: d.source === 'straight' ? '4 4' : null,
            }).addTo(PlannerLab.map);
            line.bindTooltip(
                '<b>' + (d.house_label || 'Kuća') + '</b>' +
                '<br>Drop: ' + Math.round(d.length_m) + ' m' +
                (d.source === 'straight' ? '<br>⚠ ravna linija' : ''),
                { sticky: true }
            );
            PlannerLab.preview.layers.push(line);
        });

        // Kuće obojene po svom ormaricu
        const assignedIds = new Set();
        (plan.house_assignments || []).forEach(function (a) {
            assignedIds.add(a.house_id);
            const color = cabinetColorMap[a.cabinet_temp_id] || '#f59e0b';
            const m = L.circleMarker([a.house_lat, a.house_lng], {
                radius: 5, color: '#fff', fillColor: color, fillOpacity: 0.95, weight: 1.5,
            }).addTo(PlannerLab.map);
            m.bindTooltip('<b>' + (a.house_label || 'Kuća') + '</b><br>ODO: ' + a.cabinet_name, { sticky: true });
            PlannerLab.preview.layers.push(m);
        });

        // Neraspoređene kuće — sive, vidljivo gdje plan nije pokriven
        (PlannerLab.data.houses || []).forEach(function (h) {
            if (assignedIds.has(h.id)) return;
            if (!h.latitude || !h.longitude) return;
            const m = L.circleMarker([h.latitude, h.longitude], {
                radius: 4, color: '#94a3b8', fillColor: '#94a3b8', fillOpacity: 0.45, weight: 1,
                dashArray: '3 2',
            }).addTo(PlannerLab.map);
            m.bindTooltip('<b>' + (h.label || h.address || 'Kuća') + '</b><br><i>Nije dodijeljena</i>', { sticky: true });
            PlannerLab.preview.layers.push(m);
        });

        // Planirani ODO-i
        (plan.planned_cabinets || []).forEach(function (c) {
            const color = cabinetColorMap[c.temp_id] || '#f59e0b';
            const icon = L.divIcon({
                html: '<div style="background:' + color + ';color:#fff;border-radius:4px;padding:2px 5px;font-size:10px;font-weight:700;border:2px solid rgba(0,0,0,.25);white-space:nowrap">' + c.name + '</div>',
                className: '',
                iconAnchor: [0, 10],
            });
            const m = L.marker([c.lat, c.lng], { icon }).addTo(PlannerLab.map);
            m.bindPopup('<b>' + c.name + '</b><br>Kuća: ' + c.house_count + '<br>Splitteri: ' + c.splitters);
            PlannerLab.preview.layers.push(m);
        });

        // Warning markeri (samo level=warning)
        (plan.warnings || []).filter(w => w.level === 'warning').forEach(function (w) {
            if (!w.lat || !w.lng) return;
            const icon = L.divIcon({
                html: '<div style="background:#ef4444;color:#fff;border-radius:50%;width:18px;height:18px;display:flex;align-items:center;justify-content:center;font-size:11px;font-weight:900;line-height:18px;text-align:center">!</div>',
                className: '',
                iconAnchor: [9, 9],
            });
            const m = L.marker([w.lat, w.lng], { icon }).addTo(PlannerLab.map);
            m.bindTooltip(w.message, { sticky: true });
            PlannerLab.preview.layers.push(m);
            PlannerLab.preview.warnings.push(w);
        });

        PlannerLab.fitPreviewBounds();
    };

    PlannerLab.clearPreview = function () {
        PlannerLab.preview.layers.forEach(function (l) { PlannerLab.map.removeLayer(l); });
        PlannerLab.preview.layers = [];
        PlannerLab.preview.warnings = [];
        PlannerLab.preview.plan = null;
    };

    PlannerLab.fitPreviewBounds = function () {
        const plan = PlannerLab.preview.plan;
        if (!plan) return;
        const coords = [];
        (plan.planned_cabinets || []).forEach(c => coords.push([c.lat, c.lng]));
        (plan.planned_routes || []).forEach(r => (r.path || []).forEach(p => coords.push(p)));
        (plan.house_assignments || []).forEach(a => coords.push([a.house_lat, a.house_lng]));
        if (coords.length > 0) {
            PlannerLab.map.fitBounds(L.latLngBounds(coords).pad(0.1));
        }
    };
})();
