{{-- Napomene na mapi: svako ko vidi projekat može označiti tačku, liniju ili površinu i upisati šta treba izmijeniti. --}}
<style>
    #map-workspace .map-toolbar .tc-rose { color: #fda4af; border-color: rgba(251,113,133,.4); background: rgba(244,63,94,.12); }
    #map-workspace .map-toolbar .tc-rose:hover { background: rgba(244,63,94,.24); color: #fecdd3; }
    .notes-count { display: inline-block; min-width: 15px; margin-left: 3px; padding: 0 4px; border-radius: 8px; background: #e11d48; color: #fff; font-size: 9.5px; font-weight: 800; line-height: 15px; text-align: center; }
    .notes-count[hidden] { display: none; }
    .floating-tool-header-rose { background: linear-gradient(135deg,#fff1f2,#fef2f2); color: #9f1239; }
    #notes-panel { width: 340px; }
    /* Bez bočnog panela (samo pregled) karta zauzima cijelu širinu, pa panel ide uz desni rub. */
    #map-workspace.map-readonly ~ #notes-panel, body:has(#map-workspace.map-readonly) #notes-panel { right: 16px; }
    #notes-panel.is-drawing .notes-list-section, #notes-panel.is-drawing .notes-coords { display: none; }
    #notes-panel .notes-section { font-size: 10px; font-weight: 850; letter-spacing: .08em; text-transform: uppercase; color: #64748b; margin: 0 0 7px; }
    #notes-panel .notes-tools { display: grid; grid-template-columns: repeat(3, 1fr); gap: 6px; }
    #notes-panel .notes-tool { border: 1px solid #fecdd3; background: #fff; color: #9f1239; border-radius: 7px; padding: 7px 4px; font-family: inherit; font-size: 11.5px; line-height: 1.2; font-weight: 700; cursor: pointer; }
    #notes-panel .notes-tool:hover { background: #fff1f2; }
    #notes-panel .notes-tool.is-active { background: #e11d48; border-color: #e11d48; color: #fff; }
    #notes-panel .notes-hint { margin: 6px 0 0; font-size: 10.5px; color: #64748b; line-height: 1.4; }
    #notes-panel .notes-coords { display: grid; grid-template-columns: 1fr auto; gap: 6px; margin-top: 8px; }
    #notes-panel .notes-form { display: none; margin-top: 10px; padding: 9px; border: 1px solid #fecdd3; border-radius: 9px; background: #fff7f8; }
    #notes-panel .notes-form.is-open { display: grid; gap: 6px; }
    #notes-panel .notes-form textarea { width: 100%; box-sizing: border-box; min-height: 70px; resize: vertical; }
    #notes-panel .notes-form-actions { display: grid; grid-template-columns: 1fr auto auto; gap: 6px; }
    #notes-panel .notes-btn { border-radius: 7px; padding: 7px 10px; font-family: inherit; font-size: 11px; line-height: 1.2; font-weight: 700; cursor: pointer; border: 1px solid #e2e8f0; background: #fff; color: #334155; }
    #notes-panel .notes-btn-primary { background: #e11d48; border-color: #e11d48; color: #fff; }
    #notes-panel .notes-filter { display: flex; gap: 4px; margin-bottom: 6px; }
    #notes-panel .notes-filter button { flex: 1; border: 1px solid #e2e8f0; background: #fff; border-radius: 6px; padding: 4px; font-family: inherit; font-size: 10.5px; line-height: 1.2; font-weight: 600; color: #475569; cursor: pointer; }
    #notes-panel .notes-filter button.is-active { background: #0f172a; border-color: #0f172a; color: #fff; }
    #notes-list { display: grid; gap: 4px; max-height: 320px; overflow-y: auto; }
    .notes-row { display: grid; grid-template-columns: auto 1fr; gap: 2px 8px; width: 100%; text-align: left; border: 1px solid #e2e8f0; border-radius: 8px; background: #fff; padding: 6px 8px; cursor: pointer; font-family: inherit; font-size: 11px; line-height: 1.35; font-weight: normal; color: #0f172a; }
    .notes-row:hover { border-color: #fda4af; background: #fff7f8; }
    .notes-row.is-resolved { opacity: .6; }
    .notes-row .notes-row-no { grid-row: span 2; align-self: start; min-width: 22px; padding: 1px 4px; border-radius: 5px; background: #e11d48; color: #fff; font-weight: 800; font-size: 10px; text-align: center; }
    .notes-row.is-resolved .notes-row-no { background: #16a34a; }
    .notes-row .notes-row-body { overflow: hidden; display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; white-space: pre-line; }
    .notes-row .notes-row-meta { font-size: 9.5px; color: #64748b; }
    .notes-empty { padding: 10px; font-size: 11px; color: #64748b; text-align: center; }
    .map-note-pin span { display: flex; align-items: center; justify-content: center; width: 24px; height: 24px; border-radius: 50% 50% 50% 0; transform: rotate(-45deg); background: #e11d48; border: 2px solid #fff; box-shadow: 0 2px 6px rgba(15,23,42,.35); }
    .map-note-pin span b { transform: rotate(45deg); color: #fff; font: 800 10px/1 system-ui, sans-serif; }
    .map-note-pin.is-resolved span { background: #16a34a; opacity: .75; }
    .map-note-tag { background: #e11d48; color: #fff; border: 0; border-radius: 5px; padding: 1px 5px; font: 800 10px/1.3 system-ui, sans-serif; box-shadow: 0 1px 3px rgba(15,23,42,.3); }
    .map-note-tag::before { display: none; }
    .map-note-tag.is-resolved { background: #16a34a; }
    .map-note-popup { min-width: 210px; max-width: 280px; font-size: 12px; line-height: 1.4; }
    .map-note-popup .map-note-kind { display: inline-block; padding: 1px 6px; border-radius: 5px; background: #ffe4e6; color: #9f1239; font-size: 10px; font-weight: 800; }
    .map-note-popup .map-note-kind.is-resolved { background: #dcfce7; color: #166534; }
    .map-note-popup .map-note-body { margin: 6px 0; white-space: pre-line; }
    .map-note-popup .map-note-meta { font-size: 10.5px; color: #64748b; }
    .map-note-popup .map-note-actions { display: flex; flex-wrap: wrap; gap: 4px; margin-top: 8px; }
    .map-note-popup .map-note-actions button { border: 1px solid #e2e8f0; background: #fff; border-radius: 6px; padding: 3px 8px; font: 600 11px/1.3 system-ui, sans-serif; cursor: pointer; }
    .map-note-popup .map-note-actions button[data-note-act="resolve"] { background: #16a34a; border-color: #16a34a; color: #fff; }
    .map-note-popup .map-note-actions button[data-note-act="delete"] { color: #b91c1c; border-color: #fecaca; }
</style>
<div id="notes-panel" class="floating-tool-panel" data-project-id="{{ $activeProjectId }}" data-store-url="{{ route('projects.map-notes.store', $activeProjectId) }}">
    <div class="floating-tool-header floating-tool-header-rose">
        <span>Napomene na mapi</span>
        <button type="button" id="notes-panel-close" class="floating-tool-close" aria-label="Zatvori napomene">&times;</button>
    </div>
    <div style="padding:10px 12px">
        <div class="notes-section">Nova napomena</div>
        <div class="notes-tools">
            <button type="button" class="notes-tool" data-note-tool="point" title="Klikni jedno mjesto na mapi">Tačka</button>
            <button type="button" class="notes-tool" data-note-tool="line" title="Klikći tačke linije; dupli klik, ENTER ili desni klik završava">Linija</button>
            <button type="button" class="notes-tool" data-note-tool="area" title="Klikći uglove površine; dupli klik, ENTER ili desni klik završava">Površina</button>
        </div>
        <p id="notes-hint" class="notes-hint">Odaberi oblik pa ga označi na mapi. Za tačku možeš upisati i koordinate.</p>
        <div class="notes-coords">
            <input id="notes-coords-input" class="sb-inp" placeholder="Koordinate: 44.4493, 18.6498" aria-label="Koordinate napomene">
            <button type="button" id="notes-coords-add" class="notes-btn">Tačka ovdje</button>
        </div>

        <form id="notes-form" class="notes-form" autocomplete="off">
            <div id="notes-form-title" style="font-size:11px;font-weight:800;color:#9f1239">Nova napomena</div>
            <select id="notes-kind" class="sb-sel" aria-label="Vrsta izmjene">
                @foreach(\App\Models\MapNote::KINDS as $value => $label)
                <option value="{{ $value }}">{{ $label }}</option>
                @endforeach
            </select>
            <textarea id="notes-body" class="sb-inp" maxlength="5000" placeholder="Šta treba izmijeniti? npr. Pomjeriti ODO 7 bliže kućama na sjeveru." required></textarea>
            <div class="notes-form-actions">
                <button type="submit" class="notes-btn notes-btn-primary">Sačuvaj</button>
                <button type="button" id="notes-redraw" class="notes-btn" title="Označi oblik ponovo">Ponovo označi</button>
                <button type="button" id="notes-cancel" class="notes-btn">Odustani</button>
            </div>
        </form>

        <div class="notes-list-section">
        <div style="height:1px;background:#e2e8f0;margin:12px 0"></div>
        <div class="notes-section">Sve napomene</div>
        <div class="notes-filter" role="group" aria-label="Filter napomena">
            <button type="button" data-note-filter="open" class="is-active">Otvorene</button>
            <button type="button" data-note-filter="resolved">Riješene</button>
            <button type="button" data-note-filter="all">Sve</button>
        </div>
        <div id="notes-list"><div class="notes-empty">Učitavam napomene…</div></div>
        </div>
    </div>
</div>
