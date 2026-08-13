// Geodetski TXT: pregled fajla, potvrda uvoza i crtanje elemenata mreze.
(function () {
    const panel = document.getElementById('survey-panel');
    if (!panel) return;

    const panelButton = document.getElementById('survey-panel-btn');
    const setPanelOpen = open => {
        panel.style.display = open ? 'block' : 'none';
        panelButton?.setAttribute('aria-expanded', String(open));
    };
    panelButton?.addEventListener('click', () => setPanelOpen(getComputedStyle(panel).display === 'none'));
    document.getElementById('survey-panel-close')?.addEventListener('click', () => { setPanelOpen(false); clearMapPreview(); });

    const fileInput = document.getElementById('survey-file-input');
    const chooseBtn = document.getElementById('survey-choose-btn');
    const confirmBtn = document.getElementById('survey-confirm-btn');
    const clearBtn = document.getElementById('survey-clear-btn');
    const importSelect = document.getElementById('survey-import-select');
    const deleteImportBtn = document.getElementById('survey-delete-import-btn');
    const statusBox = document.getElementById('survey-status');
    const summaryBox = document.getElementById('survey-summary');
    const gpsReadBtn = document.getElementById('field-gps-read');
    const gpsPositionBox = document.getElementById('field-gps-position');
    const fieldSaveBtn = document.getElementById('field-point-save');
    const fieldKind = document.getElementById('field-point-kind');
    const fieldCode = document.getElementById('field-point-code');
    const fieldNote = document.getElementById('field-point-note');
    const fieldPhoto = document.getElementById('field-point-photo');
    const fieldPhotoPreview = document.getElementById('field-photo-preview');
    const fieldStatus = document.getElementById('field-point-status');
    let selectedFile = null;
    let fieldPosition = null;
    let previewLayers = [];

    document.getElementById('field-mode-toggle')?.addEventListener('click', event => {
        const active = document.body.classList.toggle('field-mode');
        event.currentTarget.setAttribute('aria-pressed', String(active));
        event.currentTarget.textContent = active ? 'Puni prikaz' : 'Terenski režim';
    });
    fieldPhoto?.addEventListener('change', () => {
        const file = fieldPhoto.files?.[0];
        if (!file) { fieldPhotoPreview.style.display = 'none'; fieldPhotoPreview.removeAttribute('src'); return; }
        fieldPhotoPreview.src = URL.createObjectURL(file);
        fieldPhotoPreview.style.display = 'block';
    });

    function clearMapPreview() {
        previewLayers.forEach(layer => { untrackLayer(layer, 'preview'); if (map.hasLayer(layer)) map.removeLayer(layer); });
        previewLayers = [];
    }

    function drawMapPreview(data) {
        clearMapPreview();
        const add = layer => { previewLayers.push(layer); trackLayer(layer, 'preview'); return layer.addTo(map); };
        (data.trench_runs || []).forEach(run => {
            if (run.path?.length > 1) add(L.polyline(run.path, { color: '#0f172a', weight: 4, opacity: .65, dashArray: '8 7', interactive: false }));
        });
        (data.ducts || []).forEach(duct => {
            if (!duct.path?.length || duct.path.length < 2) return;
            const error = duct.routing_status === 'unreachable';
            add(L.polyline(duct.path, { color: error ? '#dc2626' : (duct.route_type === 'drop' ? '#16a34a' : '#2563eb'), weight: error ? 4 : 2.5, opacity: .9, dashArray: error ? '3 5' : null, interactive: false }));
        });
        (data.quality?.issue_points || []).forEach(point => add(L.circleMarker([point.lat, point.lng], { radius: 7, color: '#fff', weight: 2, fillColor: '#dc2626', fillOpacity: 1 }).bindTooltip(`Tačka ${point.point_no}: ${escapeHtml(point.code)}`)));
        if (previewLayers.length && data.bounds?.lat && data.bounds?.lng) map.fitBounds([[data.bounds.lat[0], data.bounds.lng[0]], [data.bounds.lat[1], data.bounds.lng[1]]], { padding: [35, 35], maxZoom: 20 });
    }

    function projectId() {
        return document.getElementById('active-project-id')?.value || '';
    }

    function setStatus(message, isError = false) {
        statusBox.textContent = message;
        statusBox.style.display = message ? 'block' : 'none';
        statusBox.style.background = isError ? '#fef2f2' : '#f0fdf4';
        statusBox.style.borderColor = isError ? '#fecaca' : '#bbf7d0';
        statusBox.style.color = isError ? '#b91c1c' : '#166534';
    }

    function setFieldStatus(message, isError = false) {
        fieldStatus.textContent = message;
        fieldStatus.style.display = message ? 'block' : 'none';
        fieldStatus.style.background = isError ? '#fef2f2' : '#ecfdf5';
        fieldStatus.style.color = isError ? '#b91c1c' : '#065f46';
        fieldStatus.style.border = `1px solid ${isError ? '#fecaca' : '#a7f3d0'}`;
    }

    async function loadImportedFiles() {
        if (!importSelect || !projectId()) return;
        importSelect.innerHTML = '<option value="">Učitavam listu...</option>';
        deleteImportBtn.disabled = true;
        try {
            const response = await fetch(`/projekti/${projectId()}/tacke/importi`, {
                headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            });
            const payload = await response.json().catch(() => ({}));
            if (!response.ok) throw new Error(payload.message || 'Lista uvoza nije dostupna.');
            const imports = payload.imports || [];
            importSelect.innerHTML = imports.length
                ? '<option value="">Odaberi TXT fajl...</option>' + imports.map(item =>
                    `<option value="${escapeHtml(item.batch)}">${escapeHtml(item.filename)} — ${item.points_count} tačaka</option>`
                ).join('')
                : '<option value="">Nema uvezenih TXT fajlova</option>';
        } catch (error) {
            importSelect.innerHTML = '<option value="">Greška pri učitavanju</option>';
            setStatus(error.message, true);
        }
    }

    function fieldSessionUuid() {
        const key = `ftth-field-session-${projectId()}`;
        let uuid = localStorage.getItem(key);
        if (!uuid) {
            uuid = crypto.randomUUID?.() || 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, char => {
                const value = Math.random() * 16 | 0;
                return (char === 'x' ? value : (value & 0x3 | 0x8)).toString(16);
            });
            localStorage.setItem(key, uuid);
        }
        return uuid;
    }

    async function requestJson(url, file, overrides) {
        const body = new FormData();
        body.append('points_file', file);
        if (overrides && Object.keys(overrides).length) {
            body.append('overrides', JSON.stringify(overrides));
        }
        const response = await fetch(url, {
            method: 'POST',
            headers: {
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '',
                Accept: 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
            },
            body,
        });
        const payload = await response.json().catch(() => ({}));
        if (!response.ok) throw new Error(payload.message || 'Zahtjev nije uspio.');
        return payload;
    }

    function kindLabel(kind) {
        return ({
            trench: 'rov', cabinet: 'ZO ormar', odf: 'ODF', manhole: 'saht',
            splice: 'spojnica', sling: 'kuca', loop: 'rezerva/slinga', boring: 'busenje',
            pole: 'stub', other: 'neprepoznato',
        })[kind] || kind;
    }

    function escapeHtml(value) {
        const element = document.createElement('div');
        element.textContent = String(value ?? '');
        return element.innerHTML;
    }

    function ductRowHtml(duct) {
        const type = duct.route_type ? ` <span style="color:#94a3b8">(${duct.route_type})</span>` : '';
        const routing = duct.routing_status === 'unreachable'
            ? ' <b style="color:#b91c1c">— NEMA PUTA DO ZO</b>'
            : (duct.routing_status === 'complete' ? ' <span style="color:#047857">✓ do ZO</span>' : '');
        if (duct.match_confidence !== 'ambiguous') {
            const cabinet = duct.matched_cabinet_name ? ` &rarr; ${escapeHtml(duct.matched_cabinet_name)}` : '';

            return `<li>${escapeHtml(duct.label)} / ${duct.length_m} m${type}${cabinet}${routing}</li>`;
        }

        const options = ['<option value="">(bez ormara)</option>']
            .concat((duct.candidates || []).map(c => `<option value="${c.id}"${c.id === duct.matched_cabinet_id ? ' selected' : ''}>${escapeHtml(c.name)} (${c.distance_m} m)</option>`))
            .join('');

        return `<li>${escapeHtml(duct.label)} / ${duct.length_m} m${type}${routing} - nejasan ormar:
            <select class="survey-duct-override" data-duct-key="${escapeHtml(duct.key)}">${options}</select></li>`;
    }

    function previewStat(label, value, tone = 'slate') {
        const colors = {
            slate: ['#f8fafc', '#475569'], blue: ['#eff6ff', '#1d4ed8'],
            emerald: ['#ecfdf5', '#047857'], violet: ['#f5f3ff', '#6d28d9'],
            amber: ['#fffbeb', '#b45309'], red: ['#fef2f2', '#b91c1c'],
        };
        const [background, color] = colors[tone] || colors.slate;
        return `<div style="border:1px solid ${color}22;border-radius:7px;background:${background};padding:7px 8px"><b style="display:block;font-size:15px;color:${color}">${value}</b><span style="font-size:9px;font-weight:800;text-transform:uppercase;color:${color}">${label}</span></div>`;
    }

    async function previewFile(file) {
        if (!projectId()) {
            setStatus('Prvo odaberi projekat (filter gore desno).', true);
            return;
        }

        selectedFile = null;
        confirmBtn.disabled = true;
        summaryBox.innerHTML = '';
        setStatus('Analiziram fajl...');
        try {
            const data = await requestJson(`/projekti/${projectId()}/tacke/preview`, file);
            selectedFile = file;
            drawMapPreview(data);
            const kinds = Object.entries(data.by_kind || {})
                .map(([kind, count]) => `${count} ${kindLabel(kind)}`)
                .join(' / ');
            const runs = (data.trench_runs || []).map(run => {
                const duct = run.microduct_type
                    ? `/ ${run.microduct_count > 1 ? `${run.microduct_count}x` : ''}${run.microduct_type} mc`
                    : '';
                return `<li>${run.points} tac. / ${run.length_m} m ${duct} <span style="color:#94a3b8">${escapeHtml(run.code)}</span></li>`;
            }).join('');
            const ducts = (data.ducts || []).map(ductRowHtml).join('');
            const mainDucts = (data.ducts || []).filter(duct => duct.route_type !== 'drop').length;
            const dropDucts = (data.ducts || []).filter(duct => duct.route_type === 'drop').length;
            const houseAndSlingCount = `${Number(data.by_kind?.sling || 0)} / ${Number(data.prepared_slings || 0)}`;
            const ambiguousCount = (data.ducts || []).filter(d => d.match_confidence === 'ambiguous').length;
            const unrecognized = (data.unrecognized_codes || []).length
                ? `<p style="margin:6px 0 0;color:#b45309"><b>Neprepoznato:</b> ${data.unrecognized_codes.slice(0, 6).map(escapeHtml).join(' | ')}</p>`
                : '';
            const warning = data.already_imported
                ? '<p style="margin:6px 0 0;color:#b91c1c;font-weight:700">Ovaj fajl je vec uvezen u ovaj projekat!</p>'
                : '';
            const ambiguousNote = ambiguousCount
                ? `<p style="margin:4px 0 0;color:#b45309">${ambiguousCount} mikrocijevi ima nejasnu pripadnost ormaru - provjeri odabir ispod prije potvrde.</p>`
                : '';
            const qualityErrors = data.quality?.errors || [];
            const qualityPanel = data.quality
                ? `<div style="margin:8px 0;padding:9px;border:1px solid ${qualityErrors.length ? '#fecaca' : '#a7f3d0'};border-radius:7px;background:${qualityErrors.length ? '#fef2f2' : '#ecfdf5'};color:${qualityErrors.length ? '#991b1b' : '#065f46'}"><b>${qualityErrors.length ? 'UVOZ BLOKIRAN' : 'KONTROLA PROŠLA'}</b><br>${qualityErrors.length ? qualityErrors.map(escapeHtml).join('<br>') : `${data.quality.complete_drop_routes} korisničkih ruta ima dokazanu vezu kroz rov do ODO-a.`}</div>`
                : '';

            summaryBox.innerHTML = `
                <p style="margin:0"><b>${escapeHtml(data.filename)}</b> - ${data.total_points} tacaka</p>
                <p style="margin:4px 0 0">${kinds}</p>
                <div style="display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:5px;margin:8px 0">
                    ${previewStat('Rovovi', (data.trench_runs || []).length, 'slate')}
                    ${previewStat('Glavne MC', mainDucts, 'blue')}
                    ${previewStat('Drop trase', dropDucts, dropDucts ? 'emerald' : 'slate')}
                    ${previewStat('Kuće / šlinge', houseAndSlingCount, 'violet')}
                    ${previewStat('ZO ormari', (data.cabinets || []).length, 'amber')}
                    ${previewStat('Greške', qualityErrors.length, qualityErrors.length ? 'red' : 'emerald')}
                </div>
                <p style="margin:6px 0 2px"><b>Rovovi (${(data.trench_runs || []).length})</b> - ukupno ${data.trench_total_m} m:</p>
                <ul style="margin:0;padding-left:16px;max-height:120px;overflow-y:auto">${runs || '<li>nema</li>'}</ul>
                <p style="margin:6px 0 2px"><b>Mikrocijevi (${(data.ducts || []).length})</b>:</p>
                <ul style="margin:0;padding-left:16px;max-height:140px;overflow-y:auto">${ducts || '<li>nema</li>'}</ul>
                ${ambiguousNote}
                <p style="margin:6px 0 0">ZO ormara: <b>${(data.cabinets || []).length}</b> / ODF: <b>${(data.odfs || []).length}</b> / sahtova: <b>${data.manholes}</b> / ŠLINGA: <b>${data.prepared_slings || 0}</b></p>
                ${qualityPanel}${unrecognized}${warning}`;
            confirmBtn.disabled = !!data.already_imported || data.quality?.status === 'blocked';
            setStatus(
                data.already_imported ? 'Fajl je vec uvezen - uvoz blokiran.' : data.quality?.status === 'blocked' ? 'Ispravi označene probleme u TXT fajlu prije uvoza.' : 'Pregled spreman. Klikni "Uvezi u projekat" za potvrdu.',
                data.already_imported || data.quality?.status === 'blocked',
            );
        } catch (error) {
            clearMapPreview();
            setStatus(error.message, true);
        }
    }

    chooseBtn.addEventListener('click', () => fileInput.click());
    fileInput.addEventListener('change', () => {
        if (fileInput.files?.[0]) previewFile(fileInput.files[0]);
        fileInput.value = '';
    });

    confirmBtn.addEventListener('click', async () => {
        if (!selectedFile) return;
        confirmBtn.disabled = true;
        setStatus('Uvozim tacke i crtam elemente...');
        const overrides = {};
        summaryBox.querySelectorAll('.survey-duct-override').forEach(select => {
            if (select.value) overrides[select.dataset.ductKey] = parseInt(select.value, 10);
        });
        try {
            const data = await requestJson(`/projekti/${projectId()}/tacke/import`, selectedFile, overrides);
            setStatus(`${data.message} Osvjezavam mapu...`);
            setTimeout(() => window.location.reload(), 900);
        } catch (error) {
            confirmBtn.disabled = false;
            setStatus(error.message, true);
        }
    });

    importSelect?.addEventListener('change', () => {
        deleteImportBtn.disabled = !importSelect.value;
    });

    deleteImportBtn?.addEventListener('click', async () => {
        const batch = importSelect.value;
        if (!batch || !projectId()) return;
        const filename = importSelect.options[importSelect.selectedIndex]?.textContent || 'odabrani TXT';
        if (!await window.ftthConfirm(`Obrisati samo "${filename}"?`, { title: 'Brisanje TXT importa', detail: 'Ostali TXT uvozi i ručno nacrtani elementi ostaju.', confirmLabel: 'Obriši import', danger: true })) return;
        deleteImportBtn.disabled = true;
        setStatus('Brišem samo odabrani TXT uvoz...');
        try {
            const response = await fetch(`/projekti/${projectId()}/tacke/importi/${encodeURIComponent(batch)}`, {
                method: 'DELETE',
                headers: {
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '',
                    Accept: 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                },
            });
            const payload = await response.json().catch(() => ({}));
            if (!response.ok) throw new Error(payload.message || 'Brisanje nije uspjelo.');
            setStatus(`${payload.message} Osvježavam mapu...`);
            setTimeout(() => window.location.reload(), 900);
        } catch (error) {
            deleteImportBtn.disabled = false;
            setStatus(error.message, true);
        }
    });

    gpsReadBtn?.addEventListener('click', async () => {
        if (!projectId()) return setFieldStatus('Prvo odaberi projekat.', true);
        if (!window.isSecureContext) return setFieldStatus('GPS u browseru zahtijeva HTTPS vezu. Otvori aplikaciju preko sigurnog HTTPS servera.', true);
        if (!navigator.geolocation) return setFieldStatus('Ovaj uređaj ne podržava GPS lokaciju.', true);
        if (navigator.permissions?.query) {
            const permission = await navigator.permissions.query({ name: 'geolocation' }).catch(() => null);
            if (permission?.state === 'denied') return setFieldStatus('Pristup lokaciji je blokiran. U postavkama browsera za ovu stranicu uključi Lokaciju, zatim pokušaj ponovo.', true);
        }
        if (document.visibilityState !== 'visible') return setFieldStatus('Vrati aplikaciju u prvi plan prije GPS očitanja.', true);
        gpsReadBtn.disabled = true;
        gpsReadBtn.textContent = 'Čekam preciznu GPS lokaciju…';
        navigator.geolocation.getCurrentPosition(position => {
            fieldPosition = {
                latitude: position.coords.latitude,
                longitude: position.coords.longitude,
                accuracy: position.coords.accuracy,
                capturedAt: new Date(position.timestamp).toISOString(),
            };
            const accuracyColor = fieldPosition.accuracy <= 5 ? '#047857' : fieldPosition.accuracy <= 15 ? '#b45309' : '#b91c1c';
            gpsPositionBox.style.display = 'block';
            gpsPositionBox.innerHTML = `<b>${fieldPosition.latitude.toFixed(7)}, ${fieldPosition.longitude.toFixed(7)}</b><br>Procijenjena preciznost: <b style="color:${accuracyColor}">±${fieldPosition.accuracy.toFixed(1)} m</b>`;
            if (!fieldCode.value.trim()) fieldCode.value = fieldKind.options[fieldKind.selectedIndex].text;
            fieldSaveBtn.disabled = false;
            fieldSaveBtn.style.opacity = '1';
            gpsReadBtn.disabled = false;
            gpsReadBtn.textContent = 'Ponovo očitaj GPS lokaciju';
            setFieldStatus(fieldPosition.accuracy > 15 ? 'GPS signal je slab. Sačekaj bolju preciznost prije spremanja ako je moguće.' : 'Lokacija je spremna za evidentiranje.', fieldPosition.accuracy > 30);
        }, error => {
            gpsReadBtn.disabled = false;
            gpsReadBtn.textContent = 'Očitaj trenutnu GPS lokaciju';
            const messages = {
                1: 'Pristup lokaciji nije dozvoljen. Uključi Lokaciju za ovu stranicu u postavkama browsera.',
                2: 'GPS signal nije dostupan. Izađi na otvorenije mjesto i provjeri da je lokacija uključena na telefonu.',
                3: 'GPS očitanje je isteklo nakon 30 sekundi. Pokušaj ponovo na otvorenom prostoru.',
            };
            setFieldStatus(messages[error.code] || 'GPS lokacija trenutno nije dostupna.', true);
        }, { enableHighAccuracy: true, timeout: 30000, maximumAge: 0 });
    });

    fieldSaveBtn?.addEventListener('click', async () => {
        if (!fieldPosition || !projectId()) return;
        if (!fieldCode.value.trim()) return setFieldStatus('Upiši naziv ili opis tačke.', true);
        if (fieldPosition.accuracy > 30 && !await window.ftthConfirm(`GPS preciznost je samo ±${fieldPosition.accuracy.toFixed(1)} m.`, { title: 'Niska GPS preciznost', detail: 'Ovakva tačka nije pogodna za izvedeno geodetsko stanje. Sačuvaj je samo kao informativni terenski zapis.', confirmLabel: 'Ipak sačuvaj' })) return;
        if (fieldPhoto.files?.[0]?.size > 10 * 1024 * 1024) return setFieldStatus('Fotografija je veća od 10 MB. Smanji fotografiju prije slanja.', true);
        const body = new FormData();
        body.append('session_uuid', fieldSessionUuid());
        body.append('latitude', fieldPosition.latitude);
        body.append('longitude', fieldPosition.longitude);
        body.append('accuracy_m', fieldPosition.accuracy);
        body.append('captured_at', fieldPosition.capturedAt);
        body.append('kind', fieldKind.value);
        body.append('code', fieldCode.value.trim());
        body.append('note', fieldNote.value.trim());
        if (fieldPhoto.files?.[0]) body.append('photo', fieldPhoto.files[0]);
        fieldSaveBtn.disabled = true;
        fieldSaveBtn.textContent = 'Spremam terensku tačku…';
        try {
            const response = await fetch(`/projekti/${projectId()}/teren/tacke`, {
                method: 'POST',
                headers: { 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '', Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                body,
            });
            const payload = await response.json().catch(() => ({}));
            if (!response.ok) throw new Error(Object.values(payload.errors || {})[0]?.[0] || payload.message || 'Tačka nije sačuvana.');
            const point = payload.point;
            L.circleMarker([point.lat, point.lng], { radius: 7, color: '#0f766e', weight: 3, fillColor: '#5eead4', fillOpacity: .8 })
                .bindPopup(`<b>#${point.sequence} ${escapeHtml(point.code)}</b><br>GPS ±${Number(point.accuracy_m || 0).toFixed(1)} m${point.has_photo ? '<br>Fotografija spremljena' : ''}`)
                .addTo(map).openPopup();
            map.setView([point.lat, point.lng], Math.max(map.getZoom(), 20));
            setFieldStatus(payload.message);
            fieldCode.value = '';
            fieldNote.value = '';
            fieldPhoto.value = '';
            fieldPhotoPreview.style.display = 'none'; fieldPhotoPreview.removeAttribute('src');
            fieldPosition = null;
            gpsPositionBox.style.display = 'none';
            fieldSaveBtn.textContent = 'Sačuvaj GPS tačku';
            fieldSaveBtn.style.opacity = '.55';
        } catch (error) {
            fieldSaveBtn.disabled = false;
            fieldSaveBtn.textContent = 'Sačuvaj GPS tačku';
            setFieldStatus(error.message, true);
        }
    });

    clearBtn?.addEventListener('click', async () => {
        if (!projectId()) {
            setStatus('Prvo odaberi projekat (filter gore desno).', true);
            return;
        }
        if (!await window.ftthConfirm('Obrisati SVE TXT uvoze u ovom projektu?', { title: 'Brisanje svih TXT importa', detail: 'Za jedan fajl koristi listu iznad. Ručno nacrtani elementi ostaju, ali svi geodetski uvezeni podaci bit će uklonjeni.', confirmLabel: 'Obriši sve importe', danger: true })) {
            return;
        }
        clearBtn.disabled = true;
        setStatus('Brisem podatke iz uvoza...');
        try {
            const response = await fetch(`/projekti/${projectId()}/tacke`, {
                method: 'DELETE',
                headers: {
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '',
                    Accept: 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                },
            });
            const payload = await response.json().catch(() => ({}));
            if (!response.ok) throw new Error(payload.message || 'Zahtjev nije uspio.');
            setStatus(`${payload.message} Osvjezavam mapu...`);
            setTimeout(() => window.location.reload(), 900);
        } catch (error) {
            clearBtn.disabled = false;
            setStatus(error.message, true);
        }
    });

    loadImportedFiles();
}());
