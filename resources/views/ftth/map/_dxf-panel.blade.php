{{-- DXF floating panel --}}
<div id="dxf-layer-panel" style="display:none;position:fixed;top:120px;right:350px;z-index:9999;width:280px;background:#fff;border:1px solid rgba(15,23,42,.15);border-radius:10px;box-shadow:0 10px 32px rgba(15,23,42,.18);overflow:hidden;font-family:inherit">
    <div style="display:flex;align-items:center;justify-content:space-between;padding:10px 14px;border-bottom:1px solid #f1f5f9;background:linear-gradient(135deg,#eef2ff,#f0f9ff)">
        <span style="font-size:13px;font-weight:700;color:#3730a3">DXF Layeri</span>
        <button type="button" id="dxf-layer-close" aria-label="Zatvori DXF panel" style="background:none;border:none;cursor:pointer;font-size:18px;line-height:1;color:#94a3b8;padding:0 2px">&times;</button>
    </div>
    <div id="dxf-dropzone" style="margin:10px;border:2px dashed #a5b4fc;border-radius:8px;background:#eef2ff;padding:14px 10px;text-align:center;cursor:pointer;transition:background .15s,border-color .15s">
        <svg style="display:block;margin:0 auto 6px;color:#818cf8" width="22" height="22" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M3 17a1 1 0 011-1h12a1 1 0 110 2H4a1 1 0 01-1-1zM6.293 6.707a1 1 0 010-1.414l3-3a1 1 0 011.414 0l3 3a1 1 0 01-1.414 1.414L11 5.414V13a1 1 0 11-2 0V5.414L7.707 6.707a1 1 0 01-1.414 0z" clip-rule="evenodd"/></svg>
        <p style="font-size:11px;font-weight:700;color:#4338ca;margin:0">Prevuci DXF fajl ovdje</p>
        <p style="font-size:10px;color:#818cf8;margin:3px 0 0">ili klikni dugme ispod</p>
    </div>
    <div id="dxf-error" style="display:none;margin:0 10px 6px;border-radius:6px;background:#fef2f2;border:1px solid #fecaca;padding:7px 10px;font-size:11px;font-weight:600;color:#b91c1c"></div>
    <div id="dxf-layer-list" style="padding:0 4px 4px;max-height:180px;overflow-y:auto">
        <p style="padding:12px 8px;text-align:center;font-size:10px;color:#94a3b8;margin:0">Nema učitanih layera.</p>
    </div>
    <div style="padding:8px 10px;border-top:1px solid #f1f5f9">
        <button id="dxf-upload-btn" type="button" style="width:100%;border-radius:7px;background:#4f46e5;color:#fff;padding:9px;font-size:12px;font-weight:700;border:none;cursor:pointer;display:flex;align-items:center;justify-content:center;gap:6px;font-family:inherit">
            <svg width="13" height="13" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M3 17a1 1 0 011-1h12a1 1 0 110 2H4a1 1 0 01-1-1zM6.293 6.707a1 1 0 010-1.414l3-3a1 1 0 011.414 0l3 3a1 1 0 01-1.414 1.414L11 5.414V13a1 1 0 11-2 0V5.414L7.707 6.707a1 1 0 01-1.414 0z" clip-rule="evenodd"/></svg>
            Odaberi DXF fajl
            <span id="dxf-spinner" style="display:none">⏳</span>
        </button>
        <input id="dxf-file-input" type="file" accept=".dxf" style="display:none">
    </div>
</div>
