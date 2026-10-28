// Krakovi stubova: linije stub-do-stuba po kraku, oznake krakova i generisanje iz panela.
(function () {
    const branches = Object.values(data.pole_branches || {});
    // Bez plavih/tirkiznih nijansi — tako se crtaju podzemne trase.
    const palette = [
        '#7c3aed',
        '#ea580c',
        '#db2777',
        '#ca8a04',
        '#65a30d',
        '#c026d3',
        '#b45309',
        '#e11d48',
        '#4d7c0f',
        '#9333ea',
    ];
    const records = new Map();
    const labelMarkers = [];
    const csrf = () => document.querySelector('meta[name="csrf-token"]')?.content || '';
    const formatMeters = (value) => `${Math.round(Number(value) || 0).toLocaleString('bs')} m`;
    // Svaki krak svoju boju, uvijek različitu od kraka iz kojeg se odvaja.
    const colors = new Map();
    branches.forEach((branch, index) => {
        let color = palette[index % palette.length];
        if (color === colors.get(branch.parent_code)) color = palette[(index + 1) % palette.length];
        colors.set(branch.code, color);
    });
    const colorOf = (branch) => colors.get(branch.code) || palette[0];

    function popupHtml(branch) {
        const firstPole = branch.poles[0];
        let start = 'počinje na <b>početnom stubu</b>';
        if (branch.entry && firstPole) {
            start = `izlazi iz podzemne linije na stub <b>${escapeHtml(firstPole.label)}</b> (podzemno ${formatMeters(branch.entry.length_m)})`;
        } else if (branch.from) {
            start = `odvaja se ${branch.parent_code ? `od kraka <b>${escapeHtml(branch.parent_code)}</b> ` : ''}na stubu <b>${escapeHtml(branch.from.label)}</b>`;
        }
        const odf = branch.odf_name ? `<b>ODF:</b> ${escapeHtml(branch.odf_name)}<br>` : '';
        const longSpans = (branch.long_spans || []).map((span) => `${formatMeters(span.length_m)}`).join(', ');
        const order = [branch.from, ...branch.poles]
            .filter(Boolean)
            .map((pole) => escapeHtml(pole.label))
            .join(' → ');

        return (
            `<div class="pole-branch-popup"><b>KRAK ${escapeHtml(branch.code)}</b><br>${start}<br>${odf}` +
            `<b>Stubova u kraku:</b> ${branch.pole_count}<br><b>Dužina:</b> ${formatMeters(branch.length_m)}` +
            (longSpans
                ? `<br><b style="color:#b91c1c">Predug raspon:</b> ${longSpans} (dozvoljeno ${formatMeters(branch.max_span_m)})`
                : '') +
            `<div class="pole-branch-order">${order}</div></div>`
        );
    }

    branches.forEach((branch) => {
        if ((branch.path || []).length < 2 && !branch.poles?.length) return;
        const color = colorOf(branch);
        const weight = branch.depth === 0 ? 4 : branch.depth === 1 ? 3 : 2.5;
        const layers = [];
        if (branch.path.length >= 2) {
            const line = L.polyline(branch.path, {
                color,
                weight,
                opacity: 0.9,
                lineCap: 'round',
                lineJoin: 'round',
            }).bindPopup(popupHtml(branch));
            line.on('mouseover', () => line.setStyle({ weight: weight + 2 }));
            line.on('mouseout', () => line.setStyle({ weight }));
            layers.push(line);
        }
        // Prelaz: kabl izlazi iz podzemne linije i penje se na prvi stub kraka.
        const riserPole = branch.poles[0];
        if (branch.entry && riserPole) {
            const tooltip = `Prelaz podzemno → nadzemno na ${escapeHtml(riserPole.label)} (${escapeHtml(branch.code)})`;
            if (branch.entry.length_m >= 1) {
                layers.push(
                    L.polyline(
                        [
                            [branch.entry.lat, branch.entry.lng],
                            [riserPole.lat, riserPole.lng],
                        ],
                        { color: '#78350f', weight: 3, dashArray: '4 5', opacity: 1 },
                    ).bindTooltip(`Podzemni izlaz do stuba: ${formatMeters(branch.entry.length_m)}`, { sticky: true }),
                );
            }
            layers.push(
                L.circleMarker([riserPole.lat, riserPole.lng], {
                    radius: 8,
                    color: '#78350f',
                    weight: 3,
                    fillColor: '#fde68a',
                    fillOpacity: 0.9,
                })
                    .bindTooltip(tooltip)
                    .bindPopup(popupHtml(branch)),
            );
        }
        // Predugi rasponi (nedostaje stub ili dio nije snimljen) — crvena isprekidana linija preko kraka.
        const points = [branch.from, ...branch.poles].filter(Boolean);
        (branch.long_spans || []).forEach((span) => {
            const index = points.findIndex((pole) => pole.id === span.to_id);
            if (index < 1) return;
            layers.push(
                L.polyline(
                    [
                        [points[index - 1].lat, points[index - 1].lng],
                        [points[index].lat, points[index].lng],
                    ],
                    { color: '#dc2626', weight: weight + 1, dashArray: '8 8', opacity: 1 },
                ).bindTooltip(`Predug raspon ${formatMeters(span.length_m)}`, { sticky: true }),
            );
        });
        // Oznaka kraka na sredini njegovih stubova.
        const middle = branch.poles[Math.floor((branch.poles.length - 1) / 2)] || branch.from;
        if (middle) {
            const label = L.marker([middle.lat, middle.lng], {
                interactive: false,
                keyboard: false,
                icon: L.divIcon({
                    className: 'pole-branch-label',
                    iconSize: null,
                    html: `<span style="--branch-color:${color}">${escapeHtml(branch.code)}</span>`,
                }),
            });
            labelMarkers.push(label);
        }
        layers.forEach((layer) => trackLayer(layer, 'pole_branches'));
        records.set(branch.id, { branch, layers });
    });

    // Oznake krakova samo na bližem zoomu, da ne zatrpaju kartu.
    function syncLabels() {
        const show = map.getZoom() >= 16 && layerVisible('pole_branches');
        labelMarkers.forEach((marker) => {
            if (show !== map.hasLayer(marker)) show ? marker.addTo(map) : map.removeLayer(marker);
        });
    }
    map.on('zoomend', syncLabels);
    document
        .querySelector('[data-layer-toggle="pole_branches"]')
        ?.addEventListener('change', () => requestAnimationFrame(syncLabels));
    syncLabels();

    function focusBranch(id) {
        const record = records.get(id);
        if (!record) return;
        const line = record.layers.find((layer) => layer instanceof L.Polyline);
        if (!line) return;
        map.fitBounds(line.getBounds().pad(0.2), { maxZoom: 19 });
        line.openPopup();
    }

    function activeProjectId() {
        const selected = document.getElementById('active-project-id')?.value;
        if (selected) return selected;
        const projects = new Set(
            (data.survey_objects || []).filter((item) => item.is_pole).map((item) => item.project_id),
        );

        return projects.size === 1 ? [...projects][0] : null;
    }

    const statusBox = document.getElementById('pole-branch-status');
    const showStatus = (text, tone = 'info') => {
        if (!statusBox) return;
        statusBox.style.display = text ? 'block' : 'none';
        statusBox.style.color = tone === 'error' ? '#b91c1c' : '#475569';
        statusBox.textContent = text;
    };

    async function generate(projectId, rootPointId = null) {
        if (!projectId) return window.ftthToast?.('Odaberi projekat da bi se stubovi spojili u krakove.', 'error');
        const maxSpan = Number(document.getElementById('pole-branch-max-span')?.value) || 80;
        showStatus('Spajam stubove u krakove…');
        const response = await fetch(`/projekti/${projectId}/stubovi/krakovi`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': csrf() },
            body: JSON.stringify({ max_span_m: maxSpan, root_point_id: rootPointId }),
        });
        const payload = await response.json().catch(() => ({}));
        if (!response.ok) {
            showStatus(payload.message || 'Krakovi nisu napravljeni.', 'error');
            return window.ftthToast?.(payload.message || 'Krakovi nisu napravljeni.', 'error');
        }
        try {
            sessionStorage.setItem('ftth-pole-branch-message', payload.message);
        } catch (_) {
            /* nije bitno */
        }
        window.location.reload();
    }
    // Ručne korekcije: prisilni spoj dva stuba ili prekid raspona.
    const links = Object.values(data.pole_branch_links || {});
    const neighbours = new Map();
    branches.forEach((branch) => {
        const points = [branch.from, ...branch.poles].filter(Boolean);
        for (let i = 1; i < points.length; i++) {
            [
                [points[i - 1], points[i]],
                [points[i], points[i - 1]],
            ].forEach(([a, b]) => {
                if (!neighbours.has(a.id)) neighbours.set(a.id, new Map());
                neighbours.get(a.id).set(b.id, b.label);
            });
        }
    });

    let pickFrom = null;

    async function sendLink(projectId, method, body) {
        const response = await fetch(`/projekti/${projectId}/stubovi/krakovi/veze`, {
            method,
            headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': csrf() },
            body: JSON.stringify(body),
        });
        const payload = await response.json().catch(() => ({}));
        if (!response.ok) return window.ftthToast?.(payload.message || 'Izmjena nije sačuvana.', 'error');
        try {
            sessionStorage.setItem('ftth-pole-branch-message', payload.message);
        } catch (_) {
            /* nije bitno */
        }
        window.location.reload();
    }

    function renderPoleTools(box, item) {
        if (!box || !item?.is_pole) return;
        const label = item.cad_label;
        const buttons = [];
        if (pickFrom && pickFrom.id !== item.id) {
            buttons.push(
                `<button type="button" data-act="connect-confirm">Spoji ${escapeHtml(pickFrom.label)} ↔ ${escapeHtml(label)}</button>`,
                '<button type="button" data-act="pick-cancel">Odustani od spajanja</button>',
            );
        } else {
            buttons.push('<button type="button" data-act="pick">Spoji sa drugim stubom…</button>');
        }
        (neighbours.get(item.id) || new Map()).forEach((otherLabel, otherId) => {
            buttons.push(
                `<button type="button" data-act="cut" data-other="${otherId}">✂ Prekini vezu sa ${escapeHtml(otherLabel)}</button>`,
            );
        });
        links
            .filter((link) => link.from.id === item.id || link.to.id === item.id)
            .forEach((link) => {
                const what = link.type === 'cut' ? 'prekid' : 'spoj';
                buttons.push(
                    `<button type="button" data-act="unlink" data-link="${link.id}">↺ Poništi ručni ${what} ${escapeHtml(link.from.label)} – ${escapeHtml(link.to.label)}</button>`,
                );
            });
        box.innerHTML = `<div class="pole-link-title">Ručna korekcija krakova</div>${buttons.join('')}`;
        box.onclick = (event) => {
            const button = event.target.closest('button[data-act]');
            if (!button) return;
            const act = button.dataset.act;
            if (act === 'pick') {
                pickFrom = { id: item.id, label };
                map.closePopup();
                window.ftthToast?.(`Klikni stub koji treba spojiti sa ${label}.`, 'info');
            } else if (act === 'pick-cancel') {
                pickFrom = null;
                map.closePopup();
            } else if (act === 'connect-confirm') {
                const from = pickFrom;
                pickFrom = null;
                sendLink(item.project_id, 'POST', { from_point_id: from.id, to_point_id: item.id, type: 'connect' });
            } else if (act === 'cut') {
                sendLink(item.project_id, 'POST', {
                    from_point_id: item.id,
                    to_point_id: Number(button.dataset.other),
                    type: 'cut',
                });
            } else if (act === 'unlink') {
                sendLink(item.project_id, 'DELETE', { link_id: Number(button.dataset.link) });
            }
        };
    }

    window.ftthPoleBranches = { generate, focus: focusBranch, renderPoleTools };

    document.getElementById('pole-branch-generate')?.addEventListener('click', () => generate(activeProjectId()));
    document.getElementById('pole-branch-clear')?.addEventListener('click', async () => {
        const projectId = activeProjectId();
        if (!projectId || !branches.length) return;
        if (!window.confirm('Obrisati sve krakove stubova u ovom projektu? Stubovi ostaju.')) return;
        const response = await fetch(`/projekti/${projectId}/stubovi/krakovi`, {
            method: 'DELETE',
            headers: { Accept: 'application/json', 'X-CSRF-TOKEN': csrf() },
        });
        const payload = await response.json().catch(() => ({}));
        if (!response.ok) return window.ftthToast?.(payload.message || 'Krakovi nisu obrisani.', 'error');
        try {
            sessionStorage.setItem('ftth-pole-branch-message', payload.message);
        } catch (_) {
            /* nije bitno */
        }
        window.location.reload();
    });

    // Poruka nakon ponovnog učitavanja stranice.
    try {
        const message = sessionStorage.getItem('ftth-pole-branch-message');
        if (message) {
            sessionStorage.removeItem('ftth-pole-branch-message');
            window.ftthToast?.(message, 'success');
            showStatus(message);
        }
    } catch (_) {
        /* Storage nije dostupan. */
    }

    // Poništavanje svih ručnih korekcija, samo kad ih ima.
    const listBox = document.getElementById('pole-branch-list');
    if (listBox && links.length) {
        const reset = document.createElement('button');
        reset.type = 'button';
        reset.className = 'pole-branch-row';
        reset.style.color = '#991b1b';
        reset.textContent = `↺ Poništi ručne izmjene krakova (${links.length})`;
        reset.addEventListener('click', () => {
            const projectId = activeProjectId();
            if (projectId && window.confirm('Poništiti sve ručne spojeve i prekide krakova?'))
                sendLink(projectId, 'DELETE', {});
        });
        listBox.before(reset);
    }

    const list = document.getElementById('pole-branch-list');
    if (list && branches.length) {
        let lastOdf = null;
        list.innerHTML = branches
            .map((branch) => {
                const color = colorOf(branch);
                // Naslov ODF-a ispred njegovih krakova.
                const odfHeader =
                    branch.odf_name && branch.odf_name !== lastOdf
                        ? `<div class="pole-branch-odf">${escapeHtml(branch.odf_name)}</div>`
                        : '';
                lastOdf = branch.odf_name || lastOdf;
                const riser = branch.entry
                    ? `<em class="pole-branch-riser" title="Prelaz sa podzemne linije${branch.odf_name ? `, ${escapeHtml(branch.odf_name)}` : ''}">⤒</em>`
                    : '';
                const warn = branch.long_spans?.length
                    ? '<em class="pole-branch-warn" title="Predug raspon">⚠</em>'
                    : '';
                return (
                    odfHeader +
                    `<button type="button" class="pole-branch-row" data-branch-id="${branch.id}" style="padding-left:${6 + branch.depth * 12}px">` +
                    `<i style="background:${color}"></i><b>${escapeHtml(branch.code)}</b>${riser}${warn}<span class="pole-branch-meta">${branch.pole_count} st. · ${formatMeters(branch.length_m)}</span></button>`
                );
            })
            .join('');
        list.addEventListener('click', (event) => {
            const row = event.target.closest('[data-branch-id]');
            if (row) focusBranch(Number(row.dataset.branchId));
        });
    }
})();
