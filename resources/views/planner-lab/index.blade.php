@extends('ftth.layout')
@section('title', 'Planner Lab')
@section('subtitle', '')
@section('wide', '1')

@section('content')
<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css">
<style>
#pl-shell{display:flex;flex-direction:column;height:100%;overflow:hidden;font-family:ui-sans-serif,system-ui,sans-serif;color:#0f172a}
#pl-topbar{display:flex;align-items:center;gap:6px;height:44px;flex-shrink:0;padding:0 14px;background:#1e293b;border-bottom:1px solid #0f172a}
.pl-brand{display:flex;align-items:center;gap:7px;font:700 13px/1 ui-sans-serif,system-ui,sans-serif;color:#f1f5f9}
.pl-badge{padding:2px 7px;border:1px solid rgba(245,158,11,.35);border-radius:99px;background:rgba(245,158,11,.2);color:#fcd34d;font:700 9px/1 ui-sans-serif,system-ui,sans-serif;letter-spacing:.08em}
.pl-sep{width:1px;height:18px;background:#334155;margin:0 3px;flex-shrink:0}.pl-sp{flex:1}
.pl-btn{display:inline-flex;align-items:center;justify-content:center;gap:5px;height:28px;padding:0 11px;border-radius:6px;border:1px solid transparent;cursor:pointer;font:600 11.5px/1 ui-sans-serif,system-ui,sans-serif;text-decoration:none;white-space:nowrap;transition:all .12s}
.pl-btn-primary{background:#f59e0b;color:#0f172a;border-color:#d97706}.pl-btn-primary:hover:not(:disabled){background:#fbbf24}.pl-btn-primary:disabled{opacity:.5;cursor:not-allowed}
.pl-btn-ghost{background:rgba(255,255,255,.07);color:#94a3b8;border-color:#334155}.pl-btn-ghost:hover{background:rgba(255,255,255,.13);color:#e2e8f0}
#pl-spinner{display:none;width:12px;height:12px;border:2px solid rgba(15,23,42,.3);border-top-color:#0f172a;border-radius:50%;animation:plspin .65s linear infinite}@keyframes plspin{to{transform:rotate(360deg)}}
#pl-body{display:flex;flex:1;min-height:0}#pl-map{flex:1;min-width:0;background:#e2e8f0}
#pl-left{width:224px;flex-shrink:0;background:#f8fafc;border-right:1px solid #e2e8f0;display:flex;flex-direction:column;overflow-y:auto;scrollbar-width:thin;scrollbar-color:#e2e8f0 transparent}
#pl-right{width:248px;flex-shrink:0;background:#fff;border-left:1px solid #e2e8f0;display:flex;flex-direction:column;overflow:hidden}
#pl-scroll{flex:1;overflow-y:auto;scrollbar-width:thin;scrollbar-color:#e2e8f0 transparent}
#pl-left::-webkit-scrollbar,#pl-scroll::-webkit-scrollbar{width:3px}#pl-left::-webkit-scrollbar-thumb,#pl-scroll::-webkit-scrollbar-thumb{background:#cbd5e1;border-radius:3px}
.pl-sec{padding:13px 13px 10px;border-bottom:1px solid #e2e8f0}.pl-sec:last-child{border-bottom:none}
.pl-label{display:block;margin-bottom:9px;color:#94a3b8;font:700 9.5px/1 ui-sans-serif,system-ui,sans-serif;letter-spacing:.1em;text-transform:uppercase}
.pl-select{width:100%;padding:7px 28px 7px 9px;border:1px solid #e2e8f0;border-radius:7px;background:#fff;color:#0f172a;font:13px/1.3 ui-sans-serif,system-ui,sans-serif;appearance:none;cursor:pointer;box-shadow:0 1px 2px rgba(0,0,0,.04)}
.pl-select:focus,.pl-inp:focus{outline:none;border-color:#f59e0b;box-shadow:0 0 0 3px rgba(245,158,11,.12)}
#pl-stats{display:none;grid-template-columns:1fr 1fr;gap:5px;margin-top:9px}.pl-chip{background:#fff;border:1px solid #e2e8f0;border-radius:7px;padding:7px 8px;text-align:center;box-shadow:0 1px 2px rgba(0,0,0,.03)}
.pl-chip .cv{display:block;color:#0f172a;font:700 17px/1 ui-sans-serif,system-ui,sans-serif}.pl-chip .ck{display:block;margin-top:3px;color:#94a3b8;font:500 10px/1 ui-sans-serif,system-ui,sans-serif}
.pl-tog,.pl-row{display:flex;align-items:center;justify-content:space-between;gap:8px;padding:5px 0;color:#334155;font:12.5px/1 ui-sans-serif,system-ui,sans-serif}
.pl-sw{position:relative;width:34px;height:19px;flex-shrink:0}.pl-sw input{position:absolute;opacity:0;width:0;height:0}.pl-sw-t{position:absolute;inset:0;background:#cbd5e1;border-radius:99px;cursor:pointer;transition:background .18s}
.pl-sw-t:after{content:'';position:absolute;top:2px;left:2px;width:15px;height:15px;background:#fff;border-radius:50%;box-shadow:0 1px 3px rgba(0,0,0,.25);transition:left .18s}.pl-sw input:checked+.pl-sw-t{background:#f59e0b}.pl-sw input:checked+.pl-sw-t:after{left:17px}
.pl-inp{width:68px;padding:5px 7px;border:1px solid #e2e8f0;border-radius:6px;background:#fff;color:#0f172a;text-align:right;font:12px/1 ui-sans-serif,system-ui,sans-serif;box-shadow:0 1px 2px rgba(0,0,0,.03)}
.pl-hr{border:none;border-top:1px solid #e2e8f0;margin:5px 0}.pl-lg-row{display:flex;align-items:center;gap:8px;padding:2.5px 0;color:#64748b;font:11.5px/1 ui-sans-serif,system-ui,sans-serif}
.pl-dot{width:9px;height:9px;border-radius:50%;flex-shrink:0}.pl-line{width:18px;height:3px;border-radius:2px;flex-shrink:0}.pl-dash{width:18px;height:3px;flex-shrink:0;background:repeating-linear-gradient(90deg,#94a3b8 0,#94a3b8 4px,transparent 4px,transparent 8px)}
#pl-empty{display:flex;flex-direction:column;align-items:center;justify-content:center;height:100%;padding:28px 20px;text-align:center}#pl-empty svg{opacity:.25;margin-bottom:14px}#pl-empty strong{display:block;margin-bottom:6px;color:#334155;font:600 13px/1 ui-sans-serif,system-ui,sans-serif}#pl-empty p{color:#94a3b8;font:13px/1.55 ui-sans-serif,system-ui,sans-serif}
.r-score{margin:13px 13px 0;border-radius:8px;padding:14px 15px;display:flex;align-items:center;justify-content:space-between}.r-score-num{font:900 48px/1 ui-sans-serif,system-ui,sans-serif}.r-score-r{text-align:right}.r-grade{display:inline-block;margin-bottom:5px;padding:4px 10px;border-radius:99px;font:700 11px/1 ui-sans-serif,system-ui,sans-serif}.r-score-sub{font:11px/1 ui-sans-serif,system-ui,sans-serif;opacity:.65}
.r-grid{display:grid;grid-template-columns:1fr 1fr;gap:5px;padding:10px 13px 0}.r-box{background:#f8fafc;border:1px solid #f1f5f9;border-radius:8px;padding:9px 10px}.r-box .rv{color:#0f172a;font:700 20px/1 ui-sans-serif,system-ui,sans-serif}.r-box .rk{margin-top:3px;color:#94a3b8;font:500 10px/1 ui-sans-serif,system-ui,sans-serif}.r-box.r2{grid-column:span 2;display:flex;align-items:center;justify-content:space-between}.r-box.r2 .rv{font-size:15px}.r-box.rwarn{background:#fffbeb;border-color:#fde68a}.r-box.rwarn .rv{color:#92400e}
.r-branches{padding:10px 13px 0}.r-bl{display:flex;align-items:center;gap:7px;padding:5.5px 0;border-bottom:1px solid #f8fafc;font-size:11.5px}.r-bl:last-child{border-bottom:none}.r-bd{width:8px;height:8px;border-radius:50%;flex-shrink:0}.r-bn{min-width:34px;color:#0f172a;font-weight:600}.r-bi{flex:1;color:#64748b;font-size:11px}.r-bw{width:34px;height:4px;background:#f1f5f9;border-radius:4px;flex-shrink:0}.r-bb{height:4px;border-radius:4px}
.r-reasons{padding:8px 13px 0}.r-reason{color:#94a3b8;font:11px/1.5 ui-sans-serif,system-ui,sans-serif}.r-warns{padding:0 13px 13px}.r-wh{display:block;margin-bottom:6px;padding-top:8px;color:#ef4444;font:700 9.5px/1 ui-sans-serif,system-ui,sans-serif;letter-spacing:.09em;text-transform:uppercase}.r-wi{display:flex;align-items:flex-start;gap:6px;padding:5px 0;border-bottom:1px solid #fef2f2;color:#374151;cursor:pointer;font:11px/1.45 ui-sans-serif,system-ui,sans-serif}.r-wi:hover{color:#ef4444}
#pl-acts{display:none;padding:11px 13px 13px;border-top:1px solid #f1f5f9;flex-direction:column;gap:5px}.r-abtn{display:flex;align-items:center;justify-content:center;gap:5px;width:100%;padding:9px 0;border-radius:7px;border:none;cursor:pointer;font:600 12.5px/1 ui-sans-serif,system-ui,sans-serif;transition:all .12s}.r-abtn:disabled{opacity:.45;cursor:not-allowed}.r-save{background:#10b981;color:#fff}.r-save:hover:not(:disabled){background:#059669}.r-export{background:#f8fafc;color:#475569;border:1px solid #e2e8f0}.r-export:hover{background:#f1f5f9}.r-clear{background:#fff;color:#ef4444;border:1px solid #fecaca}.r-clear:hover{background:#fef2f2}
#pl-toast{position:fixed;bottom:22px;left:50%;transform:translateX(-50%) translateY(50px);max-width:340px;padding:9px 18px;border-radius:9px;border-width:1px;border-style:solid;box-shadow:0 8px 24px rgba(0,0,0,.15);z-index:99999;pointer-events:none;text-align:center;font:600 12.5px/1.4 ui-sans-serif,system-ui,sans-serif;transition:transform .22s cubic-bezier(.34,1.56,.64,1)}#pl-toast.show{transform:translateX(-50%) translateY(0)}#pl-toast.ok{background:#f0fdf4;color:#166534;border-color:#bbf7d0}#pl-toast.err{background:#fef2f2;color:#991b1b;border-color:#fecaca}
</style>

<div id="pl-toast"></div>

<div id="pl-shell">
    <div id="pl-topbar">
        <div class="pl-brand">
            <svg width="15" height="15" viewBox="0 0 20 20" fill="#f59e0b"><path fill-rule="evenodd" d="M7 2a1 1 0 00-.707 1.707L8 5.414V9l-3.446 5.168A1 1 0 005.382 16h9.236a1 1 0 00.828-1.563L12 9V5.414l1.707-1.707A1 1 0 0013 2H7zm0 2h6l-1.707 1.707A1 1 0 0011 6.586V9.5a1 1 0 01-.168.555L8.382 14h3.236l-2.45-3.668A1 1 0 019 9.5V6.586a1 1 0 00-.293-.707L7 4z" clip-rule="evenodd"/></svg>
            Planner Lab
            <span class="pl-badge">Sandbox</span>
        </div>
        <div class="pl-sep"></div>
        <button id="btn-preview" class="pl-btn pl-btn-primary" type="button">
            Generisi plan <div id="pl-spinner"></div>
        </button>
        <div class="pl-sp"></div>
        <button class="pl-btn pl-btn-ghost" type="button" onclick="PlannerLab.toggleLayer()">
            <svg width="12" height="12" viewBox="0 0 20 20" fill="currentColor"><path d="M3 4a1 1 0 011-1h12a1 1 0 011 1v2a1 1 0 01-1 1H4a1 1 0 01-1-1V4zM3 10a1 1 0 011-1h6a1 1 0 011 1v6a1 1 0 01-1 1H4a1 1 0 01-1-1v-6zM14 9a1 1 0 00-1 1v6a1 1 0 001 1h2a1 1 0 001-1v-6a1 1 0 00-1-1h-2z"/></svg>
            <span id="btn-layer-label">OSM</span>
        </button>
        <div class="pl-sep"></div>
        <a href="{{ route('map.dashboard') }}" class="pl-btn pl-btn-ghost">&larr; Mapa</a>
    </div>

    <div id="pl-body">
        <div id="pl-left">
            <div class="pl-sec">
                <span class="pl-label">Projekt</span>
                <select id="project-select" class="pl-select">
                    <option value="">-- odaberi projekt --</option>
                    @foreach($projects as $p)
                        <option value="{{ $p->id }}">{{ $p->name }}{{ $p->code ? ' - '.$p->code : '' }}</option>
                    @endforeach
                </select>
                <div id="pl-stats"></div>
            </div>

            <div class="pl-sec">
                <span class="pl-label">Opcije</span>
                <div class="pl-tog">
                    <span>OSM routing</span>
                    <label class="pl-sw"><input type="checkbox" id="opt-osm" checked><div class="pl-sw-t"></div></label>
                </div>
                <hr class="pl-hr">
                <div class="pl-row"><label for="opt-max-houses">Max kuca / ODO</label><input type="number" id="opt-max-houses" class="pl-inp" value="12" min="1" max="12"></div>
                <div class="pl-row"><label for="opt-odo-spacing">Razmak ODO (m)</label><input type="number" id="opt-odo-spacing" class="pl-inp" value="150" min="80" max="400" step="10"></div>
                <div class="pl-row"><label for="opt-max-drop">Max drop (m)</label><input type="number" id="opt-max-drop" class="pl-inp" value="150" min="30" max="400" step="10"></div>
                <hr class="pl-hr">
                <div class="pl-row">
                    <label for="opt-installation">Instalacija</label>
                    <select id="opt-installation" class="pl-inp" style="width:90px;text-align:left">
                        <option value="underground">Podzemno</option>
                        <option value="aerial">Nadzemno</option>
                    </select>
                </div>
            </div>

            <div class="pl-sec">
                <span class="pl-label">Legenda</span>
                <div class="pl-lg-row"><div class="pl-dot" style="background:#1d4ed8"></div>ODF</div>
                <div class="pl-lg-row"><div class="pl-dot" style="background:#7c3aed"></div>ODO (postojeci)</div>
                <div class="pl-lg-row"><div class="pl-dot" style="background:#10b981"></div>Kuca (dodijeljena)</div>
                <div class="pl-lg-row"><div class="pl-dot" style="background:#cbd5e1"></div>Kuca (slobodna)</div>
                <hr class="pl-hr">
                <div class="pl-lg-row"><div class="pl-dot" style="background:#f59e0b;outline:2px solid #fde68a;outline-offset:1px"></div>ODO (planirani)</div>
                <div class="pl-lg-row"><div class="pl-line" style="background:#f97316"></div>Krak (OSM)</div>
                <div class="pl-lg-row"><div class="pl-dash"></div>Krak (ravna linija)</div>
            </div>
        </div>

        <div id="pl-map"></div>

        <div id="pl-right">
            <div id="pl-scroll">
                <div id="pl-empty">
                    <svg width="44" height="44" viewBox="0 0 20 20" fill="#94a3b8"><path fill-rule="evenodd" d="M7 2a1 1 0 00-.707 1.707L8 5.414V9l-3.446 5.168A1 1 0 005.382 16h9.236a1 1 0 00.828-1.563L12 9V5.414l1.707-1.707A1 1 0 0013 2H7zm0 2h6l-1.707 1.707A1 1 0 0011 6.586V9.5a1 1 0 01-.168.555L8.382 14h3.236l-2.45-3.668A1 1 0 019 9.5V6.586a1 1 0 00-.293-.707L7 4z" clip-rule="evenodd"/></svg>
                    <strong>Nema plana</strong>
                    <p>Odaberi projekt i klikni <b>Generisi plan</b>.</p>
                </div>
                <div id="pl-result" style="display:none"></div>
            </div>
            <div id="pl-acts">
                <button id="btn-save" class="r-abtn r-save" type="button" onclick="PlannerLab.ui.savePlan()">
                    <svg width="13" height="13" viewBox="0 0 20 20" fill="currentColor"><path d="M7.707 10.293a1 1 0 10-1.414 1.414l3 3a1 1 0 001.414 0l3-3a1 1 0 00-1.414-1.414L11 11.586V6h5a2 2 0 012 2v7a2 2 0 01-2 2H4a2 2 0 01-2-2V8a2 2 0 012-2h5v5.586l-1.293-1.293z"/></svg>
                    Sacuvaj plan u bazu
                </button>
                <div style="display:flex;gap:5px">
                    <button class="r-abtn r-export" type="button" style="flex:1" onclick="PlannerLab.ui.exportJson()">Export JSON</button>
                    <button class="r-abtn r-clear" type="button" style="flex:1" onclick="PlannerLab.ui.clearPreview()">Obrisi</button>
                </div>
            </div>
        </div>
    </div>
</div>

<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
<script src="/js/planner-lab/state.js"></script>
<script src="/js/planner-lab/map.js"></script>
<script src="/js/planner-lab/api.js"></script>
<script src="/js/planner-lab/preview.js"></script>
<script src="/js/planner-lab/ui.js"></script>
<script src="/js/planner-lab/routing.js"></script>
<script src="/js/planner-lab/init.js"></script>
@endsection
