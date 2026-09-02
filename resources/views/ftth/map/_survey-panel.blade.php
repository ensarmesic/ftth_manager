{{-- Survey points import floating panel --}}
<div id="survey-panel" class="floating-tool-panel floating-tool-panel-wide">
    <div class="floating-tool-header floating-tool-header-green">
        <span>Terenski rad</span>
        <button type="button" id="field-mode-toggle" class="floating-tool-close" style="font-size:11px;width:auto;padding:0 8px" aria-pressed="false" title="Pojednostavljeni prikaz za telefon i terenski rad">Terenski režim</button>
        <button type="button" id="survey-panel-close" class="floating-tool-close" aria-label="Zatvori panel terenskog rada">&times;</button>
    </div>
    <div style="padding:10px 12px">
        @can('project.edit')
        <div style="font-size:10px;font-weight:850;letter-spacing:.08em;text-transform:uppercase;color:#64748b;margin-bottom:7px">Uvoz geodetskog TXT fajla</div>
        <p style="margin:0 0 8px;font-size:10.5px;color:#64748b;line-height:1.4">Format: <code>broj&nbsp;X&nbsp;Y&nbsp;Z&nbsp;opis</code> (Gauss-Krüger). Rovovi se spajaju u linije, ZO/ODF/šahtovi postaju elementi mreže.</p>
        <button id="survey-choose-btn" type="button" style="width:100%;border-radius:7px;background:#059669;color:#fff;padding:9px;font-size:12px;font-weight:700;border:none;cursor:pointer;font-family:inherit">Odaberi TXT fajl</button>
        <input id="survey-file-input" type="file" accept=".txt" style="display:none">
        <div id="survey-status" style="display:none;margin-top:8px;border-radius:6px;border:1px solid #bbf7d0;background:#f0fdf4;padding:7px 10px;font-size:11px;font-weight:600;color:#166534"></div>
        <div id="survey-summary" style="margin-top:8px;font-size:11px;color:#334155;line-height:1.45"></div>
        <div id="survey-diagnostics-tools" style="display:none;margin-top:8px;padding:8px;border:1px solid #dbeafe;border-radius:8px;background:#f8fafc">
            <div id="survey-preview-meta" style="font-size:9.5px;color:#64748b;line-height:1.35"></div>
            <div style="display:grid;grid-template-columns:minmax(0,1.2fr) repeat(3,minmax(0,1fr));gap:5px;margin-top:7px">
                <select id="survey-route-filter" aria-label="Filtriraj mikrocijevi" style="min-width:0;border:1px solid #cbd5e1;border-radius:6px;padding:6px 4px;background:#fff;color:#334155;font:700 9.5px/1.2 inherit">
                    <option value="all">Sve rute</option>
                    <option value="problems">Samo problemi</option>
                    <option value="complete">Ispravne rute</option>
                </select>
                <button id="survey-compare-btn" type="button" style="border:1px solid #cbd5e1;border-radius:6px;background:#fff;color:#334155;padding:6px 3px;font-size:9.5px;font-weight:750;cursor:pointer">Sa&#269;uvano</button>
                <button id="survey-report-csv" type="button" style="border:1px solid #a7f3d0;border-radius:6px;background:#ecfdf5;color:#047857;padding:6px 3px;font-size:9.5px;font-weight:750;cursor:pointer">CSV</button>
                <button id="survey-report-pdf" type="button" style="border:1px solid #fecaca;border-radius:6px;background:#fef2f2;color:#b91c1c;padding:6px 3px;font-size:9.5px;font-weight:750;cursor:pointer">PDF</button>
            </div>
            <div id="survey-saved-comparison" style="display:none;margin-top:7px;padding-top:7px;border-top:1px solid #e2e8f0;font-size:10px;color:#475569;line-height:1.4"></div>
        </div>
        <div id="survey-coordinate-editor" style="display:none;margin-top:9px;padding:8px;border:1px solid #bfdbfe;border-radius:8px;background:#eff6ff">
            <div style="font-size:10px;font-weight:800;color:#1e40af;margin-bottom:6px">RUČNA KOREKCIJA KOORDINATA</div>
            <button id="survey-edit-toggle" type="button" style="width:100%;border-radius:7px;background:#7c3aed;color:#fff;padding:8px;font-size:11px;font-weight:800;border:none;cursor:pointer">Uredi tačke i cijevi</button>
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:6px;margin-top:6px">
                <button id="survey-edit-reset" type="button" disabled style="border-radius:7px;background:#fff;color:#475569;padding:7px;font-size:10px;font-weight:750;border:1px solid #cbd5e1;cursor:pointer">Vrati početno</button>
                <button id="survey-edit-export" type="button" disabled style="border-radius:7px;background:#0369a1;color:#fff;padding:7px;font-size:10px;font-weight:750;border:none;cursor:pointer">Preuzmi korekcije</button>
            </div>
            <p id="survey-edit-help" style="margin:6px 0 0;font-size:9.5px;color:#475569;line-height:1.35">Povuci numerisani kružić za zajedničku koordinatu ili klikni direktno cijev pa pomjeraj njene hvataljke.</p>
        </div>
        <button id="survey-confirm-btn" type="button" disabled style="width:100%;margin-top:10px;border-radius:7px;background:#2563eb;color:#fff;padding:9px;font-size:12px;font-weight:700;border:none;cursor:pointer;font-family:inherit;opacity:1">Uvezi u projekat</button>
        <div style="margin-top:10px;padding:9px;border:1px solid #e2e8f0;border-radius:8px;background:#f8fafc">
            <div style="font-size:10px;font-weight:800;color:#475569;margin-bottom:6px">UVEZENI TXT FAJLOVI</div>
            <select id="survey-import-select" style="width:100%;border:1px solid #cbd5e1;border-radius:6px;padding:7px;font-size:11px;background:#fff;font-family:inherit">
                <option value="">Učitavam listu...</option>
            </select>
            @can('destructive')<button id="survey-delete-import-btn" type="button" disabled style="width:100%;margin-top:6px;border-radius:7px;background:#fff;color:#b91c1c;padding:8px;font-size:11px;font-weight:700;border:1px solid #fca5a5;cursor:pointer;font-family:inherit">Obriši samo odabrani TXT fajl</button>@endcan
        </div>
        @can('destructive')<button id="survey-clear-btn" type="button" style="width:100%;margin-top:6px;border-radius:7px;background:#fff;color:#991b1b;padding:8px;font-size:10px;font-weight:700;border:1px dashed #fecaca;cursor:pointer;font-family:inherit">Obriši SVE TXT uvoze</button>@endcan
        @endcan
        @can('field.capture')
        <div style="height:1px;background:#e2e8f0;margin:14px 0"></div>
        <div style="font-size:10px;font-weight:850;letter-spacing:.08em;text-transform:uppercase;color:#64748b;margin-bottom:7px">Nova GPS tačka</div>
        <button id="field-gps-read" type="button" style="width:100%;border-radius:8px;background:#075985;color:#fff;padding:10px;font-size:12px;font-weight:800;border:none;cursor:pointer">Očitaj trenutnu GPS lokaciju</button>
        <div id="field-gps-position" style="display:none;margin-top:7px;border:1px solid #bae6fd;background:#f0f9ff;border-radius:7px;padding:8px;font-size:11px;color:#075985"></div>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:7px;margin-top:8px">
            <select id="field-point-kind" class="sb-sel"><option value="trench">Rov / trasa</option><option value="cabinet">ODO ormarić</option><option value="odf">ODF</option><option value="manhole">Šaht</option><option value="splice">Spojnica</option><option value="sling">Kuća / priključak</option><option value="loop">Rezerva kabla</option><option value="boring">Bušenje</option><option value="pole">Stub</option><option value="other">Ostalo</option></select>
            <input id="field-point-code" class="sb-inp" placeholder="Naziv tačke" maxlength="255">
        </div>
        <textarea id="field-point-note" class="sb-inp" rows="2" placeholder="Napomena s terena" style="margin-top:7px;resize:vertical"></textarea>
        <label style="display:flex;align-items:center;justify-content:space-between;gap:8px;margin-top:7px;border:1px dashed #cbd5e1;border-radius:7px;padding:8px;font-size:11px;color:#475569;cursor:pointer"><span>Dodaj fotografiju</span><input id="field-point-photo" type="file" accept="image/jpeg,image/png,image/webp" capture="environment" style="max-width:165px;font-size:10px"></label>
        <img id="field-photo-preview" alt="Pregled fotografije prije slanja" style="display:none;width:100%;max-height:180px;object-fit:cover;margin-top:7px;border-radius:8px;border:1px solid #cbd5e1">
        <button id="field-point-save" type="button" disabled style="width:100%;margin-top:8px;border-radius:8px;background:#0f766e;color:#fff;padding:10px;font-size:12px;font-weight:800;border:none;cursor:pointer;opacity:.55">Sačuvaj GPS tačku</button>
        <div id="field-point-status" style="display:none;margin-top:7px;border-radius:7px;padding:8px;font-size:11px;font-weight:650"></div>
        @endcan
    </div>
</div>
