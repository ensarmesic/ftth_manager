(function () {
    'use strict';

    const BRANCH_COLORS = [
        '#f97316', '#3b82f6', '#10b981', '#8b5cf6',
        '#ef4444', '#06b6d4', '#eab308', '#84cc16',
        '#f43f5e', '#a855f7',
    ];

    const GRADE = {
        excellent: { color: '#059669', bg: '#d1fae5', label: 'Odlican' },
        good: { color: '#2563eb', bg: '#dbeafe', label: 'Dobar' },
        fair: { color: '#d97706', bg: '#fef3c7', label: 'Solidan' },
        poor: { color: '#dc2626', bg: '#fee2e2', label: 'Los' },
    };

    PlannerLab.ui = {
        setLoading: function (loading) {
            const btn = document.getElementById('btn-preview');
            const sp = document.getElementById('pl-spinner');
            if (!btn) return;

            btn.disabled = loading;
            for (const node of btn.childNodes) {
                if (node.nodeType === 3) {
                    node.textContent = loading ? ' Racunam... ' : ' Generisi plan ';
                    break;
                }
            }
            if (sp) sp.style.display = loading ? 'inline-block' : 'none';
        },

        showProjectStats: function (data) {
            const el = document.getElementById('pl-stats');
            if (!el) return;

            const odfs = (data.odfs || []).length;
            const houses = (data.houses || []).length;
            const cabs = (data.cabinets || []).length;
            const assigned = (data.houses || []).filter(h => h.cabinet_id).length;

            el.innerHTML =
                chip(odfs, 'ODF') +
                chip(houses, 'Kuca') +
                chip(cabs, 'ODO') +
                chip(assigned + '/' + houses, 'Dodijeljeno');
            el.style.display = 'grid';

            function chip(val, key) {
                return '<div class="pl-chip"><span class="cv">' + escapeHtml(val) + '</span><span class="ck">' + escapeHtml(key) + '</span></div>';
            }
        },

        showSummary: function (plan) {
            const s = plan.summary || {};
            const g = GRADE[s.grade] || GRADE.fair;
            const result = document.getElementById('pl-result');
            if (!result) return;

            let html = '<div class="r-score" style="background:' + g.bg + '">';
            html += '<div class="r-score-num" style="color:' + g.color + '">' + escapeHtml(s.score ?? 0) + '</div>';
            html += '<div class="r-score-r">';
            html += '<div class="r-grade" style="background:' + g.color + ';color:#fff">' + g.label + '</div>';
            html += '<div class="r-score-sub" style="color:' + g.color + '">od 100 bodova</div>';
            html += '</div></div>';

            html += '<div class="r-grid">';
            html += statBox(s.assigned_houses ?? 0, 'Dodijeljeno');
            html += (s.unassigned_houses || 0) > 0
                ? statBox(s.unassigned_houses, 'Slobodne', 'rwarn')
                : statBox(s.total_houses ?? 0, 'Ukupno kuca');
            html += statBox(s.planned_cabinets ?? 0, 'ODO-i');
            html += statBox(s.planned_branches ?? 0, 'Krakovi');
            html += '<div class="r-box r2">';
            html += '<div><div class="rv">' + fmtM(s.total_route_length_m) + '</div><div class="rk">Ukupno trasa</div></div>';
            html += (s.fallback_routes || 0) > 0
                ? '<div style="font-size:11px;color:#d97706;font-weight:600">' + escapeHtml(s.fallback_routes) + ' ravnih linija</div>'
                : '<div style="font-size:11px;color:#059669;font-weight:600">sve prate ceste</div>';
            html += '</div></div>';

            const branches = plan.planned_branches || [];
            if (branches.length > 0) {
                html += '<div class="r-branches">';
                html += '<span class="pl-label" style="margin-bottom:6px">Krakovi</span>';
                branches.forEach(function (b, i) {
                    const clr = BRANCH_COLORS[i % BRANCH_COLORS.length];
                    const totalH = (b.cabinets || []).reduce((a, c) => a + (c.house_count || 0), 0);
                    const maxH = (b.cabinets || []).length * 12;
                    const fill = maxH > 0 ? Math.round(totalH / maxH * 100) : 0;
                    html += '<div class="r-bl">';
                    html += '<div class="r-bd" style="background:' + clr + '"></div>';
                    html += '<span class="r-bn">' + escapeHtml(b.name || ('Krak ' + (i + 1))) + '</span>';
                    html += '<span class="r-bi">' + escapeHtml(b.cabinet_count || 0) + ' ODO, ' + fmtM(b.length_m) + '</span>';
                    html += '<div class="r-bw"><div class="r-bb" style="background:' + clr + ';width:' + fill + '%"></div></div>';
                    html += '</div>';
                });
                html += '</div>';
            }

            if (s.score_reasons && s.score_reasons.length > 0) {
                html += '<div class="r-reasons">';
                s.score_reasons.forEach(function (reason) {
                    html += '<div class="r-reason">- ' + escapeHtml(reason) + '</div>';
                });
                html += '</div>';
            }

            result.innerHTML = html;
            result.style.display = 'block';

            const emp = document.getElementById('pl-empty');
            if (emp) emp.style.display = 'none';

            const acts = document.getElementById('pl-acts');
            if (acts) acts.style.display = 'flex';

            PlannerLab.ui.showWarnings((plan.warnings || []).filter(w => w.level === 'warning'));

            function statBox(val, key, cls) {
                return '<div class="r-box ' + (cls || '') + '"><div class="rv">' + escapeHtml(val) + '</div><div class="rk">' + escapeHtml(key) + '</div></div>';
            }
        },

        showWarnings: function (warnings) {
            const el = document.getElementById('pl-result');
            if (!el || !warnings || warnings.length === 0) return;

            let html = '<div class="r-warns">';
            html += '<span class="r-wh">Upozorenja (' + warnings.length + ')</span>';
            warnings.forEach(function (w) {
                const click = (w.lat && w.lng) ? 'onclick="PlannerLab.map.flyTo([' + Number(w.lat) + ',' + Number(w.lng) + '],19)"' : '';
                html += '<div class="r-wi" ' + click + '>! ' + escapeHtml(w.message || '') + '</div>';
            });
            html += '</div>';
            el.insertAdjacentHTML('beforeend', html);
        },

        clearPreview: function () {
            PlannerLab.clearPreview();
            const result = document.getElementById('pl-result');
            const acts = document.getElementById('pl-acts');
            const emp = document.getElementById('pl-empty');
            const save = document.getElementById('btn-save');

            if (result) {
                result.innerHTML = '';
                result.style.display = 'none';
            }
            if (acts) acts.style.display = 'none';
            if (emp) emp.style.display = 'flex';
            if (save) {
                save.disabled = false;
                save.innerHTML = '<svg width="13" height="13" viewBox="0 0 20 20" fill="currentColor"><path d="M7.707 10.293a1 1 0 10-1.414 1.414l3 3a1 1 0 001.414 0l3-3a1 1 0 00-1.414-1.414L11 11.586V6h5a2 2 0 012 2v7a2 2 0 01-2 2H4a2 2 0 01-2-2V8a2 2 0 012-2h5v5.586l-1.293-1.293z"/></svg> Sacuvaj plan u bazu';
            }
        },

        savePlan: async function () {
            const plan = PlannerLab.preview.plan;
            if (!plan || !PlannerLab.projectId) return;

            const s = plan.summary || {};
            if (!confirm('Snimiti plan u bazu?\n\n' +
                (s.planned_cabinets || 0) + ' ODO-a - ' +
                (s.planned_routes || 0) + ' trasa - ' +
                (s.assigned_houses || 0) + ' kuca\n\nPostojeci podaci ostaju netaknuti.')) return;

            const btn = document.getElementById('btn-save');
            if (btn) {
                btn.disabled = true;
                btn.textContent = 'Snimam...';
            }

            try {
                const res = await PlannerLab.api.savePlan(PlannerLab.projectId, plan);
                PlannerLab.ui.toast(res.message || 'Plan sacuvan!', 'ok');
                if (btn) btn.textContent = 'Sacuvano';
            } catch (e) {
                PlannerLab.ui.toast('Greska: ' + e.message, 'err');
                if (btn) {
                    btn.disabled = false;
                    btn.innerHTML = '<svg width="13" height="13" viewBox="0 0 20 20" fill="currentColor"><path d="M7.707 10.293a1 1 0 10-1.414 1.414l3 3a1 1 0 001.414 0l3-3a1 1 0 00-1.414-1.414L11 11.586V6h5a2 2 0 012 2v7a2 2 0 01-2 2H4a2 2 0 01-2-2V8a2 2 0 012-2h5v5.586l-1.293-1.293z"/></svg> Sacuvaj plan u bazu';
                }
            }
        },

        exportJson: async function () {
            const plan = PlannerLab.preview.plan;
            if (!plan || !PlannerLab.projectId) return;
            try {
                const res = await PlannerLab.api.exportJson(PlannerLab.projectId, plan);
                const blob = new Blob([JSON.stringify(res.data, null, 2)], { type: 'application/json' });
                const a = document.createElement('a');
                a.href = URL.createObjectURL(blob);
                a.download = res.filename;
                a.click();
                URL.revokeObjectURL(a.href);
            } catch (e) {
                PlannerLab.ui.toast('Export nije uspio.', 'err');
            }
        },

        toast: function (msg, type) {
            const el = document.getElementById('pl-toast');
            if (!el) return;
            el.textContent = msg;
            el.className = type || '';
            void el.offsetWidth;
            el.classList.add('show');
            clearTimeout(PlannerLab._toastTimer);
            PlannerLab._toastTimer = setTimeout(() => { el.classList.remove('show'); }, 4000);
        },

        readOptions: function () {
            return {
                useOsm: document.getElementById('opt-osm')?.checked ?? true,
                followRoads: true,
                maxHousesPerCabinet: parseInt(document.getElementById('opt-max-houses')?.value || '12', 10),
                odoSpacing: parseInt(document.getElementById('opt-odo-spacing')?.value || '150', 10),
                maxDropDistance: parseInt(document.getElementById('opt-max-drop')?.value || '150', 10),
                installation: document.getElementById('opt-installation')?.value || 'underground',
            };
        },
    };

    function fmtM(v) {
        if (!v) return '0 m';
        return v >= 1000 ? (v / 1000).toFixed(2) + ' km' : Math.round(v) + ' m';
    }

    function escapeHtml(value) {
        return String(value ?? '').replace(/[&<>"']/g, function (ch) {
            return ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' })[ch];
        });
    }
})();
