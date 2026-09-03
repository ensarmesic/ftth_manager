@extends('ftth.layout')

@section('title', 'Mapa mreže')
@section('subtitle', 'Satelitski projektantski prikaz za ODF, ODO ormariće, kuće i trase.')
@section('wide', '1')

@section('content')
<link rel="stylesheet" href="{{ asset('vendor/leaflet/leaflet.css') }}">

<style>
@include('ftth.map._workspace-styles')
@include('ftth.map._element-styles')
@include('ftth.map._dialog-styles')
@include('ftth.map._theme-styles')
</style>

@include('ftth.map._project-picker')
@include('ftth.map._snapshot-modal')

<section id="map-workspace" class="grid flex-1 min-h-0 gap-2 xl:grid-cols-[minmax(0,1fr)_316px] @cannot('project.edit') map-readonly @endcannot @cannot('field.capture') map-no-field @endcannot">

    <!-- MAP COLUMN -->
    <div class="map-shell flex min-h-0 flex-col overflow-hidden rounded-xl border border-slate-200/80 shadow-xl">

        <!-- Top bar -->
        <div class="map-topbar flex shrink-0 flex-wrap items-center justify-between gap-3 px-4 py-2.5">
            <div class="flex items-center gap-3">
                <div class="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg text-white" style="background:linear-gradient(135deg,#308dcc,#004f7d)">
                    <svg viewBox="0 0 20 20" fill="currentColor" class="w-4 h-4"><path fill-rule="evenodd" d="M12 1.586l-4 4v12.828l4-4V1.586zM3.707 3.293A1 1 0 002 4v10a1 1 0 00.293.707L6 18.414V5.586L3.707 3.293zM17.707 5.293L14 1.586v12.828l2.293 2.293A1 1 0 0018 16V6a1 1 0 00-.293-.707z" clip-rule="evenodd"/></svg>
                </div>
                <div>
                    <div class="text-sm font-bold text-slate-900 leading-tight">Radna karta</div>
                    <div class="text-xs text-slate-500 leading-tight">Satelit · ODF · Ormarići · Trase</div>
                </div>
                <div class="map-work-context hidden md:flex" aria-live="polite">
                    <span><small>PROJEKAT</small><b id="map-context-project">{{ $projects->firstWhere('id', $activeProjectId)?->name ?? 'Svi projekti — samo pregled' }}</b></span>
                    <i></i>
                    <span><small>AKTIVNI ALAT</small><b id="map-context-tool">Pan / pregled</b></span>
                    <i></i>
                    <span><small>AKTIVNI ODF</small><b id="map-context-odf">Nije odabran</b></span>
                </div>
            </div>
            <div class="flex items-center gap-2">
                <div class="metric-pill amber"><b id="draw-length">0 m</b><span>Trasa</span></div>
                <div class="metric-pill violet"><b id="house-count">0</b><span>Kuće</span></div>
                <div class="metric-pill emerald"><b id="cabinet-count">0</b><span>FTTH</span></div>
            </div>
        </div>

        <!-- CAD Toolbar -->
        <button type="button" id="mobile-map-tools-toggle" class="mobile-map-tools-toggle" aria-controls="map-cad-toolbar" aria-expanded="false">
            <span>Alati za crtanje i uređivanje</span>
            <span>Prikaži</span>
        </button>
        <div id="map-cad-toolbar" class="map-toolbar">
            <span class="toolbar-group-label">Navigacija</span>
            <button type="button" id="mode-pan" class="tc tc-white">⊕ Pan</button>
            <button type="button" id="mode-select" class="tc tc-white tool-btn" title="Selektuj i briši više elemenata (drag pravougaonik)">⬚ Selekt</button>
            <div class="tc-sep"></div>
            <span class="toolbar-group-label">Elementi</span>
            <button type="button" id="mode-odf" class="tc tc-cyan">ODF</button>
            <button type="button" id="mode-cabinet" class="tc tc-emerald">FTTH</button>
            <button type="button" id="mode-house" class="tc tc-violet">Kuće</button>
            <button type="button" id="mode-manhole" class="tc tc-slate">Šaht</button>
            <button type="button" id="mode-boring-fi-130" class="tc tc-red">Raketa FI130</button>
            <div class="tc-sep"></div>
            <span class="toolbar-group-label">Trase</span>
            <button type="button" id="mode-draw" class="tc tc-amber">Trasa</button>
            <button type="button" id="mode-trench-draw" class="tc tc-slate">Rov</button>
            <button type="button" id="mode-trace-branch" class="tc tc-amber">Krak po liniji</button>
            <button type="button" id="mode-ruler" class="tc tc-rose">Mjerač</button>
            <div class="tc-sep"></div>
            <span class="toolbar-group-label">Veze</span>
            <button type="button" id="mode-connect" class="tc tc-blue">ODF↔ODO</button>
            <button type="button" id="mode-connect-houses" class="tc tc-violet">ODO↔Kuće</button>
            <button type="button" id="mode-branch-source" class="tc tc-orange">Krak iz ODO</button>
            <button type="button" id="advanced-map-tools-toggle" class="tc tc-ghost" aria-expanded="false">Napredni alati</button>
            <button type="button" id="mode-trace" class="tc tc-sky advanced-map-tool hidden">Trace</button>
            <button type="button" id="mode-join" class="tc tc-rose advanced-map-tool hidden">Join trase</button>
            <button type="button" id="mode-split" class="tc tc-orange advanced-map-tool hidden">✂ Split</button>
            <div class="tc-sep"></div>
            <span class="toolbar-group-label">Historija</span>
            <button type="button" id="btn-map-undo" class="tc tc-ghost" title="Undo (Ctrl+Z)" disabled>↩ Undo</button>
            <button type="button" id="btn-map-redo" class="tc tc-ghost" title="Redo (Ctrl+Y)" disabled>↪ Redo</button>
            <div class="tc-sep"></div>
            <div id="house-connect-actions" class="hidden items-center gap-1">
                <button type="button" id="finish-house-connect" class="tc tc-confirm">✓ Završi</button>
                <button type="button" id="cancel-house-connect" class="tc tc-ghost">✕ Otkaži</button>
            </div>
            <button type="button" id="finish-branch" class="tc tc-confirm">✓ Završi krak</button>
            <button type="button" id="undo-draw" class="tc tc-ghost">↩ Tačka</button>
            <button type="button" id="undo-branch" class="tc tc-ghost">↩ Krak</button>
            <button type="button" id="undo-element" class="tc tc-ghost">↩ Elem.</button>
            <button type="button" id="undo-house" class="tc tc-ghost">↩ Kuća</button>
            <button type="button" id="cancel-draw" class="tc tc-danger">✕ Crtanje</button>
            <button type="button" id="clear-draw" class="tc tc-danger">✕ Trase</button>
            <div id="route-edit-actions" class="hidden items-center gap-1">
                <button type="button" id="add-route-vertex" class="tc tc-blue">+ Tačka</button>
                <button type="button" id="undo-route-edit" class="tc tc-ghost" title="Ctrl+Z" disabled>↩ Undo</button>
                <button type="button" id="redo-route-edit" class="tc tc-ghost" title="Ctrl+Y" disabled>↷ Redo</button>
                <button type="button" id="save-route-edit" class="tc tc-save">✓ Sačuvaj trasu</button>
                <button type="button" id="cancel-route-edit" class="tc tc-ghost">✕ Otkaži</button>
            </div>
            <button type="button" id="quick-save-draft" class="tc tc-save">💾 Nacrt</button>
            <span id="map-save-indicator" class="map-save-indicator" data-state="idle"><i></i><span>Spremno</span></span>
            <div class="tc-sep"></div>
            <div class="flex items-center gap-1 ml-auto flex-wrap">
                <button type="button" id="toggle-color-by-fibers" class="tc tc-ghost" title="Boja trase po broju vlakana">Boja F</button>
                <button type="button" id="toggle-parallel-routes" class="tc tc-ghost" title="Uključi ili isključi paralelni prikaz mikrocijevi" aria-pressed="true">Paralelno</button>
                <button type="button" id="toggle-cable-specs" class="tc tc-ghost" title="Specifikacije kabela">Specs</button>
                <button type="button" id="project-snapshot-btn" class="tc tc-ghost" title="Pregledaj, sačuvaj ili vrati verziju projekta">Verzije</button>
                <button type="button" id="btn-coord-jump" class="tc tc-ghost" title="Skok na koordinate">Goto</button>
                <button type="button" id="dxf-layer-btn" class="tc tc-indigo" title="Učitaj DXF" aria-controls="dxf-layer-panel" aria-expanded="false">DXF</button>
                @canany(['project.edit', 'field.capture'])<button type="button" id="survey-panel-btn" class="tc tc-indigo" title="Terenske i geodetske tačke" aria-controls="survey-panel" aria-expanded="false">Tačke</button>@endcanany
                <button type="button" id="toggle-map-view" class="tc tc-ghost">GIS</button>
                <button type="button" id="expand-map" class="tc tc-ghost" title="Proširena mapa">⛶</button>
                <div class="tc-sep"></div>
                <select id="map-project-filter" title="Filtriraj po projektu" style="height:26px;border:1px solid #334155;background:#1e293b;color:#e2e8f0;border-radius:5px;font-size:11px;padding:0 6px;font-family:inherit;cursor:pointer;max-width:150px">
                    <option value="">Svi projekti</option>
                    @foreach($projects as $project)
                    <option value="{{ $project->id }}" {{ $activeProjectId == $project->id ? 'selected' : '' }}>{{ $project->name }}</option>
                    @endforeach
                </select>
                <button type="button" id="btn-map-print" class="tc tc-ghost" title="Štampaj/izvoz mape">
                    <svg width="12" height="12" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M5 4v3H4a2 2 0 00-2 2v3a2 2 0 002 2h1v2a2 2 0 002 2h6a2 2 0 002-2v-2h1a2 2 0 002-2V9a2 2 0 00-2-2h-1V4a2 2 0 00-2-2H7a2 2 0 00-2 2zm8 0H7v3h6V4zm0 8H7v4h6v-4z" clip-rule="evenodd"/></svg>
                    Print
                </button>
            </div>
        </div>
        <p class="shrink-0 border-b border-slate-800 bg-slate-900 px-4 py-1 text-[10px] text-slate-500">Desni klik: obriši / premjesti · ESC prekid · ENTER završi · CTRL+Z undo · O ortho · G GIS graf · Selekcija: K kopiraj, P pomjeri, V rotiraj, Z zrcali, S skaliraj, N niz</p>

        <div id="map-container" class="min-h-0 flex-1 w-full relative">
            <div id="network-map" class="w-full h-full"></div>
            <div id="cad-crosshair" aria-hidden="true"><i></i><b></b><span></span><em id="cad-dynamic-input"></em></div>
            <nav class="map-vertical-tools" aria-label="Brzi alati mape">
                <button type="button" data-map-tool="select" title="Selektuj elemente" aria-label="Selektuj elemente">
                    <svg viewBox="0 0 20 20" fill="currentColor"><path d="M4 2.7v13.8c0 .7.84 1.04 1.33.55l3.1-3.1 2.15 3.55a1 1 0 001.72-1.03l-2.14-3.57h4.38c.7 0 1.05-.85.55-1.34L5.34 2.16A.78.78 0 004 2.7z"/></svg>
                </button>
                <button type="button" data-map-tool="draw" title="Crtaj trasu" aria-label="Crtaj trasu">
                    <svg viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.7"><circle cx="4" cy="15" r="1.5" fill="currentColor"/><circle cx="15.5" cy="4.5" r="1.5" fill="currentColor"/><path d="M5.2 13.8L14.3 5.7"/></svg>
                </button>
                <button type="button" data-map-tool="house" title="Dodaj kuću / priključak" aria-label="Dodaj kuću">
                    <svg viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.6"><path d="M10 18s5-5.2 5-10A5 5 0 105 8c0 4.8 5 10 5 10z"/><circle cx="10" cy="8" r="1.7"/></svg>
                </button>
                <button type="button" data-map-tool="cabinet" title="Dodaj FTTH / ODO ormarić" aria-label="Dodaj FTTH ormarić">
                    <svg viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.6"><rect x="4" y="4" width="12" height="12" rx="1"/><path d="M7 7h6v6H7z"/></svg>
                </button>
                <button type="button" data-map-tool="odf" title="Dodaj ODF" aria-label="Dodaj ODF">
                    <svg viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.6"><circle cx="10" cy="10" r="6"/><circle cx="10" cy="10" r="2"/></svg>
                </button>
                <button type="button" data-map-tool="manhole" title="Dodaj šaht" aria-label="Dodaj šaht">
                    <svg viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.6"><path d="M5 4h10M10 4v12M6.5 16h7"/></svg>
                </button>
                <button type="button" data-map-delete title="Obriši selektovane elemente" aria-label="Obriši selektovane elemente">
                    <svg viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.6"><path d="M4 6h12M8 3h4l1 3H7l1-3zM6 6l1 11h6l1-11M9 9v5M11 9v5"/></svg>
                </button>
            </nav>
            <div id="select-rubber-band" style="display:none;position:absolute;border:2px solid #3b82f6;background:rgba(59,130,246,0.08);pointer-events:none;z-index:2000;box-sizing:border-box;"></div>
            <div id="cabinet-assign-panel" style="display:none;position:absolute;bottom:54px;left:50%;transform:translateX(-50%);z-index:2002;background:#1e293b;border:1px solid #7c3aed;border-radius:10px;padding:10px 12px;min-width:260px;max-width:340px;box-shadow:0 8px 28px rgba(0,0,0,.4);">
                <div style="font:700 11px/1 system-ui,sans-serif;color:#ddd6fe;margin-bottom:8px;letter-spacing:.04em;text-transform:uppercase;">Dodijeli ODO</div>
                <div id="cabinet-assign-list" style="max-height:210px;overflow-y:auto;display:grid;gap:3px;"></div>
                <div id="cabinet-assign-status" style="display:none;font:600 11px/1.4 system-ui;color:#a78bfa;margin-top:8px;"></div>
                <button id="cabinet-assign-cancel" type="button" style="margin-top:8px;width:100%;padding:5px;border:1px solid #475569;border-radius:5px;background:transparent;color:#94a3b8;cursor:pointer;font:600 11px/1 system-ui,sans-serif;">Otkaži</button>
            </div>
            <div id="select-actions" style="display:none;position:absolute;bottom:10px;left:50%;transform:translateX(-50%);z-index:2001;background:#1e293b;border:1px solid #3b82f6;border-radius:6px;padding:6px 12px;align-items:center;gap:8px;font-size:12px;color:#e2e8f0;white-space:nowrap;">
                <span id="select-count">0 selektovano</span>
                <button id="select-assign-btn" type="button" class="tc tc-violet" style="display:none;padding:2px 10px;font-size:11px;">⤵ Dodijeli ODO</button>
                <span style="width:1px;height:16px;background:#334155;"></span>
                <button id="xf-copy-btn" type="button" class="tc tc-ghost" title="Kopiraj [K]" style="padding:2px 8px;font-size:11px;">Kopiraj</button>
                <button id="xf-move-btn" type="button" class="tc tc-ghost" title="Pomjeri [P]" style="padding:2px 8px;font-size:11px;">Pomjeri</button>
                <button id="xf-rotate-btn" type="button" class="tc tc-ghost" title="Rotiraj [V]" style="padding:2px 8px;font-size:11px;">Rotiraj</button>
                <button id="xf-mirror-btn" type="button" class="tc tc-ghost" title="Zrcali [Z]" style="padding:2px 8px;font-size:11px;">Zrcali</button>
                <button id="xf-scale-btn" type="button" class="tc tc-ghost" title="Skaliraj [S]" style="padding:2px 8px;font-size:11px;">Skaliraj</button>
                <button id="xf-array-btn" type="button" class="tc tc-ghost" title="Niz [N]" style="padding:2px 8px;font-size:11px;">Niz</button>
                <button id="xf-align-btn" type="button" class="tc tc-ghost" title="Poravnaj" style="padding:2px 8px;font-size:11px;">Poravnaj</button>
                <label style="display:flex;align-items:center;gap:4px;font-size:10px;color:#94a3b8;">
                    <input type="checkbox" id="xf-keep-original" checked style="accent-color:#3b82f6;">Original
                </label>
                <span style="width:1px;height:16px;background:#334155;"></span>
                <button id="select-delete-btn" class="tc tc-danger" style="padding:2px 10px;font-size:11px;">Obriši selektovano</button>
                <button id="select-cancel-btn" class="tc tc-ghost" style="padding:2px 8px;font-size:11px;">✕</button>
            </div>
            <div id="xf-value-panel" style="display:none;position:absolute;bottom:54px;left:50%;transform:translateX(-50%);z-index:2002;background:#1e293b;border:1px solid #3b82f6;border-radius:10px;padding:10px 12px;min-width:220px;box-shadow:0 8px 28px rgba(0,0,0,.4);">
                <div id="xf-value-label" style="font:700 11px/1 system-ui,sans-serif;color:#bfdbfe;margin-bottom:8px;letter-spacing:.04em;text-transform:uppercase;">Ugao</div>
                <input id="xf-value-input" type="number" step="any" style="width:100%;padding:6px 8px;border-radius:6px;border:1px solid #475569;background:#0f172a;color:#e2e8f0;font:600 12px system-ui;">
                <input id="xf-value-input-2" type="number" step="any" style="display:none;width:100%;margin-top:6px;padding:6px 8px;border-radius:6px;border:1px solid #475569;background:#0f172a;color:#e2e8f0;font:600 12px system-ui;">
                <div style="display:flex;gap:6px;margin-top:8px;">
                    <button id="xf-value-confirm" type="button" style="flex:1;padding:5px;border:none;border-radius:5px;background:#2563eb;color:#fff;cursor:pointer;font:600 11px/1 system-ui,sans-serif;">Primijeni</button>
                    <button id="xf-value-cancel" type="button" style="padding:5px 10px;border:1px solid #475569;border-radius:5px;background:transparent;color:#94a3b8;cursor:pointer;font:600 11px/1 system-ui,sans-serif;">Otkaži</button>
                </div>
            </div>

            {{-- Pretraga markera --}}
            <div id="map-search-overlay" style="position:absolute;top:10px;left:10px;z-index:1500;width:250px">
                <div style="position:relative">
                    <svg style="position:absolute;left:9px;top:50%;transform:translateY(-50%);color:#94a3b8;pointer-events:none" width="13" height="13" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M8 4a4 4 0 100 8 4 4 0 000-8zM2 8a6 6 0 1110.89 3.476l4.817 4.817a1 1 0 01-1.414 1.414l-4.816-4.816A6 6 0 012 8z" clip-rule="evenodd"/></svg>
                    <input id="map-search-input" placeholder="Traži ODF, FTTH, kuću..." autocomplete="off"
                        style="width:100%;box-sizing:border-box;padding:7px 10px 7px 28px;border-radius:9px;border:1.5px solid #e2e8f0;background:rgba(255,255,255,.95);font-size:12px;box-shadow:0 2px 8px rgba(0,0,0,.14);outline:none;font-family:inherit;color:#1e293b">
                </div>
                <div id="map-search-results" style="display:none;margin-top:3px;border-radius:9px;border:1px solid #e2e8f0;background:#fff;box-shadow:0 8px 24px rgba(0,0,0,.14);overflow:hidden;max-height:220px;overflow-y:auto"></div>
            </div>

            {{-- Statistike na mapi --}}
            <div id="map-stats-bar" style="position:absolute;top:10px;right:10px;z-index:1500;display:flex;gap:4px;flex-wrap:wrap;justify-content:flex-end;pointer-events:none"></div>

            {{-- Legenda --}}
            <div style="position:absolute;bottom:46px;right:10px;z-index:1500">
                <button id="map-legend-btn" type="button" style="padding:4px 10px;border-radius:7px;background:rgba(255,255,255,.93);border:1px solid #e2e8f0;font-size:11px;font-weight:600;color:#475569;cursor:pointer;box-shadow:0 2px 6px rgba(0,0,0,.1);display:flex;align-items:center;gap:5px;font-family:inherit;white-space:nowrap">
                    <svg width="12" height="12" viewBox="0 0 20 20" fill="currentColor"><path d="M9 4.804A7.968 7.968 0 005.5 4c-1.255 0-2.443.29-3.5.804v10A7.969 7.969 0 015.5 14c1.669 0 3.218.51 4.5 1.385A7.962 7.962 0 0114.5 14c1.255 0 2.443.29 3.5.804v-10A7.968 7.968 0 0014.5 4c-1.255 0-2.443.29-3.5.804V12a1 1 0 11-2 0V4.804z"/></svg>
                    Legenda
                </button>
                <div id="map-legend-panel" style="display:none;position:absolute;bottom:calc(100% + 6px);right:0;width:200px;background:#fff;border:1px solid #e2e8f0;border-radius:10px;box-shadow:0 8px 24px rgba(0,0,0,.14);padding:10px 12px;font-size:11px">
                    <div style="font-weight:700;color:#1e293b;margin-bottom:8px;font-size:12px">Legenda mape</div>
                    <div style="display:grid;gap:5px">
                        <div style="display:flex;align-items:center;gap:7px"><span style="flex-shrink:0;width:22px;height:14px;border-radius:3px;background:#0891b2"></span><span style="color:#334155">ODF čvorište</span></div>
                        <div style="display:flex;align-items:center;gap:7px"><span style="flex-shrink:0;width:22px;height:14px;border-radius:3px;background:#059669"></span><span style="color:#334155">FTTH ormarić (ODO)</span></div>
                        <div style="display:flex;align-items:center;gap:7px"><span style="flex-shrink:0;width:22px;height:14px;border-radius:3px;background:#7c3aed"></span><span style="color:#334155">Kuća / pretplatnik</span></div>
                        <div style="border-top:1px solid #f1f5f9;margin:3px 0"></div>
                        <div style="display:flex;align-items:center;gap:7px"><span style="flex-shrink:0;width:22px;height:3px;background:#0f172a;border-radius:2px"></span><span style="color:#334155">Backbone kabel</span></div>
                        <div style="display:flex;align-items:center;gap:7px"><span style="flex-shrink:0;width:22px;height:3px;background:#d97706;border-radius:2px"></span><span style="color:#334155">Distribution kabel</span></div>
                        <div style="display:flex;align-items:center;gap:7px"><span style="flex-shrink:0;width:22px;height:3px;background:#6d28d9;border-radius:2px"></span><span style="color:#334155">Drop kabel</span></div>
                        <div style="display:flex;align-items:center;gap:7px"><span style="flex-shrink:0;width:22px;height:3px;background:#64748b;border-radius:2px"></span><span style="color:#334155">Trench / rov</span></div>
                        <div style="border-top:1px solid #f1f5f9;margin:3px 0"></div>
                        <div style="display:flex;align-items:center;gap:7px"><span style="flex-shrink:0;width:10px;height:10px;border-radius:50%;background:#64748b;margin-left:6px"></span><span style="color:#334155">Šaht</span></div>
                        <div style="display:flex;align-items:center;gap:7px"><span style="flex-shrink:0;width:10px;height:10px;background:#ef4444;transform:rotate(45deg);margin-left:6px"></span><span style="color:#334155">Raketa FI 130</span></div>
                    </div>
                </div>
            </div>
        </div>
        <div class="cad-status grid gap-2 px-3 py-2 md:grid-cols-[1fr_auto_auto_auto]">
            <div class="cad-command-line">
                <div id="cad-command">Command: PAN</div>
                <label><span>&gt;</span><input id="cad-command-input" type="text" autocomplete="off" spellcheck="false" placeholder="Komanda: LINE, PAN, DIST…" aria-label="CAD komandna linija" list="cad-command-list"></label>
                <datalist id="cad-command-list"><option value="LINE"><option value="PAN"><option value="SELECT"><option value="ERASE"><option value="DIST"><option value="ORTHO"><option value="UNDO"><option value="REDO"><option value="ZOOM EXTENTS"><option value="ODF"><option value="FTTH"><option value="HOUSE"><option value="BRANCH"></datalist>
            </div>
            <div id="cad-metrics" class="cad-chip rounded px-2 py-1">Points: 0 | Distance: 0m | Snap: - | ORTHO: OFF</div>
            <div id="cad-coordinates" class="cad-chip rounded px-2 py-1">LAT -, LNG -</div>
            <div class="cad-toggle-strip">
                <button type="button" data-cad-toggle="snap" title="F3"><span>OSNAP</span><b>ON</b></button>
                <button type="button" data-cad-toggle="grid" title="F7"><span>GRID</span><b>OFF</b></button>
                <button type="button" data-cad-toggle="ortho" title="F8"><span>ORTHO</span><b>OFF</b></button>
            </div>
        </div>
    </div>

    <!-- RIGHT SIDEBAR -->
    <aside class="grid min-h-0 content-start gap-2 xl:max-h-full xl:overflow-y-auto xl:pb-2">

        <!-- Novi projekat -->
        <details class="sidebar-card" @if(!$activeProjectId) open @endif>
            <summary class="sidebar-hd">
                <span class="sdot sdot-sky"></span>
                Novi projekat
                <svg class="chev w-3.5 h-3.5" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M7.293 14.707a1 1 0 010-1.414L10.586 10 7.293 6.707a1 1 0 011.414-1.414l4 4a1 1 0 010 1.414l-4 4a1 1 0 01-1.414 0z" clip-rule="evenodd"/></svg>
            </summary>
            <form id="quick-project-form" class="sidebar-bd grid gap-2">
                <input name="name" class="sb-inp" placeholder="Naziv projekta" required>
                <input type="hidden" name="quick_create" value="1">
                <button class="sb-btn sb-btn-primary">Kreiraj i odaberi</button>
                <div id="quick-project-status" class="text-xs font-semibold text-emerald-700"></div>
            </form>
        </details>

        <!-- Element editor (contextual) -->
        <div id="element-editor" class="ctx-panel hidden" style="border-color:#6ee7b7;border-width:2px">
            <div class="ctx-panel-hd">
                <div>
                    <div class="text-sm font-semibold text-slate-900">Odabrani element</div>
                    <div id="element-editor-type" class="text-xs text-slate-500"></div>
                </div>
                <button type="button" id="close-element-editor" class="sb-btn sb-btn-outline" style="width:auto;padding:3px 9px;font-size:11px">Zatvori</button>
            </div>
            <div class="sidebar-bd grid gap-2">
                <label class="grid gap-1"><span class="sb-kicker">Naziv</span><input id="element-editor-name" class="sb-inp" placeholder="Naziv elementa"></label>
                <label class="grid gap-1"><span class="sb-kicker">Adresa / napomena</span><input id="element-editor-address" class="sb-inp" placeholder="Lokacija ili kratka napomena"></label>
                <div id="element-editor-odf-fields" class="hidden grid grid-cols-2 gap-2">
                    <label class="grid gap-1"><span class="sb-kicker">Vlakana</span><select id="element-editor-fiber-capacity" class="sb-sel"><option>72</option><option selected>144</option><option>288</option><option>576</option></select></label>
                    <label class="grid gap-1"><span class="sb-kicker">Portova</span><input id="element-editor-port-count" type="number" min="1" max="1152" class="sb-inp" value="48"></label>
                </div>
                <div id="element-editor-cabinet-fields" class="hidden grid gap-2">
                    <div class="grid grid-cols-2 gap-2">
                        <label class="grid gap-1"><span class="sb-kicker">Splittera</span><input id="element-editor-splitter-count" type="number" min="1" max="3" class="sb-inp" value="3"></label>
                        <label class="grid gap-1"><span class="sb-kicker">Portova / splitter</span><input id="element-editor-ports-per-splitter" type="number" min="1" max="4" class="sb-inp" value="4"></label>
                    </div>
                    <label class="grid gap-1"><span class="sb-kicker">Povezani ODF</span><select id="element-editor-odf" class="sb-sel"></select></label>
                </div>
                <div id="element-editor-capacity" class="hidden rounded-lg border border-slate-200 bg-slate-50 px-3 py-2 text-xs text-slate-600"></div>
                <button type="button" id="save-element-name" class="sb-btn sb-btn-emerald">Sačuvaj podatke</button>
                <div id="element-editor-status" class="text-xs font-semibold text-emerald-700"></div>
            </div>
        </div>

        <!-- Plan projekta -->
        <details class="sidebar-card" @if($activeProjectId) open @endif>
        <summary class="sidebar-hd">
            <span class="sdot sdot-indigo"></span>
            Plan projekta
            <span id="bulk-plan-summary" class="text-[10px] font-normal text-slate-400 truncate" style="max-width:110px">0 ODF</span>
            <svg class="chev w-3.5 h-3.5" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M7.293 14.707a1 1 0 010-1.414L10.586 10 7.293 6.707a1 1 0 011.414-1.414l4 4a1 1 0 010 1.414l-4 4a1 1 0 01-1.414 0z" clip-rule="evenodd"/></svg>
        </summary>
        <form method="POST" action="{{ route('map.plan.store') }}" id="bulk-plan-form">
            @csrf
            <div class="sidebar-bd grid gap-3">
                <select id="active-project-id" name="project_id" class="sb-sel" required>
                    <option value="">Svi projekti</option>
                    @foreach($projects as $project)
                        <option value="{{ $project->id }}" @selected($activeProjectId === $project->id)>{{ $project->name }}</option>
                    @endforeach
                </select>
                <div>
                    <div class="sb-kicker">Tok rada</div>
                    <div class="grid grid-cols-2 gap-1.5">
                        <button type="button" data-guide-mode="odf" class="step-btn step-cyan"><b>1</b> ODF</button>
                        <button type="button" data-guide-mode="draw" class="step-btn step-amber"><b>2</b> Trasa</button>
                        <button type="button" data-guide-mode="house" class="step-btn step-violet"><b>3</b> Kuće</button>
                        <button type="button" id="guide-suggest" class="step-btn step-emerald"><b>4</b> FTTH</button>
                    </div>
                </div>
                <div>
                    <div class="sb-kicker">Aktivni ODF</div>
                    <select id="active-odf-index" class="sb-sel mb-2"><option value="">Prvo postavi ODF</option></select>
                    <div id="odf-link-status" class="sb-info bg-cyan-50 text-cyan-800">Postavi ODF, zatim postavljaj ODO ormariće.</div>
                </div>
                <div class="rounded-lg border border-amber-100 bg-amber-50 px-3 py-2.5">
                    <div class="flex items-start justify-between gap-2">
                        <div class="text-xs font-semibold text-amber-900">Obracun rova</div>
                        <span id="route-trench-status" class="rounded bg-amber-200 px-2 py-1 text-[10px] font-bold text-amber-900 whitespace-nowrap">tip trase odlucuje</span>
                    </div>
                    <div class="mt-1 text-xs text-amber-700 leading-5">Najpre Glavni rov, zatim mikrocijevi/krakovi.</div>
                </div>
                <details class="rounded-lg border border-amber-100 overflow-hidden">
                    <summary class="list-none flex items-center justify-between cursor-pointer px-3 py-2 text-xs font-semibold text-amber-900 bg-amber-50">
                        Postavke trase
                        <svg class="w-3.5 h-3.5 text-amber-400" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M5.293 7.293a1 1 0 011.414 0L10 10.586l3.293-3.293a1 1 0 111.414 1.414l-4 4a1 1 0 01-1.414 0l-4-4a1 1 0 010-1.414z" clip-rule="evenodd"/></svg>
                    </summary>
                    <div class="p-3 grid gap-2 bg-white border-t border-amber-100">
                        <div class="flex items-center justify-between gap-2">
                            <span class="text-xs font-semibold text-amber-900">Aktivni krak</span>
                            <span id="route-branch-count" class="text-xs font-semibold text-amber-600">0 krakova</span>
                        </div>
                        <div class="sb-kicker">Pocetak trase
                            <select id="route-start-source" class="sb-sel mt-1 font-normal">
                                <option value="">Automatski / bez veze</option>
                                @foreach($odfsForSelect as $odf)
                                    <option value="odf:{{ $odf->id }}">ODF: {{ $odf->name }} - {{ $odf->project->name }}</option>
                                @endforeach
                                @foreach($cabinetsForSelect as $cabinet)
                                    <option value="cabinet:{{ $cabinet->id }}">ODO: {{ $cabinet->name }} - {{ $cabinet->project->name }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="grid grid-cols-2 gap-2">
                            <div class="sb-kicker">Tip
                                <select id="route-draw-type" class="sb-sel mt-1 font-normal">
                                    <option value="trench">Glavni rov</option>
                                    <option value="feeder">Primarni</option>
                                    <option value="distribution">Sekundarni</option>
                                </select>
                            </div>
                            <div class="sb-kicker">Mikrocijevi
                                <input id="route-draw-microducts" type="number" min="1" value="1" class="sb-inp mt-1">
                            </div>
                        </div>
                        <div class="grid grid-cols-3 gap-2">
                            <div class="sb-kicker">Polaganje
                                <select id="route-draw-installation" class="sb-sel mt-1 font-normal"><option value="underground">Podzemna</option><option value="aerial">Zracna</option></select>
                            </div>
                            <div class="sb-kicker">Mikrocijev
                                <select id="route-draw-microduct-type" class="sb-sel mt-1 font-normal"><option value="14/10">14/10</option><option value="10/8">10/8</option></select>
                            </div>
                            <div class="sb-kicker">Niti
                                <select id="route-draw-fiber-count" class="sb-sel mt-1 font-normal"><option value="4">4F</option><option value="12" selected>12F</option><option value="24">24F</option><option value="48">48F</option></select>
                            </div>
                        </div>
                        <div class="sb-kicker">Oznaka
                            <input id="route-draw-name" class="sb-inp mt-1" placeholder="npr. P-01 ili S-01">
                        </div>
                        <div id="route-odf-status" class="sb-info bg-amber-50 text-amber-900">Krak nije vezan na ODF.</div>
                        <div id="route-branch-list" class="grid max-h-28 gap-1 overflow-y-auto text-xs text-amber-900"></div>
                    </div>
                </details>
                <label class="flex items-center gap-2.5 cursor-pointer select-none rounded-lg border border-amber-200 bg-amber-50 px-3 py-2.5 transition-colors hover:bg-amber-100">
                    <input type="checkbox" id="osm-routing-toggle" class="cb-custom">
                    <span class="text-xs font-semibold text-amber-900">Prati ulice (OSM)</span>
                    <span class="ml-auto text-[10px] font-mono font-semibold text-amber-400 bg-amber-100 border border-amber-200 rounded px-1">[R]</span>
                </label>
                <label class="flex items-center gap-2.5 cursor-pointer select-none rounded-lg border border-sky-200 bg-sky-50 px-3 py-2.5 transition-colors hover:bg-sky-100">
                    <input type="checkbox" id="gis-routing-toggle" class="cb-custom">
                    <span class="text-xs font-semibold text-sky-900">Interni GIS graf</span>
                    <span class="ml-auto text-[10px] font-mono font-semibold text-sky-500 bg-sky-100 border border-sky-200 rounded px-1">BETA</span>
                </label>
                <input id="bulk-plan-json" type="hidden" name="plan">
                <div class="grid grid-cols-2 gap-2">
                    <button type="button" id="save-draft" class="sb-btn sb-btn-outline">Radna verzija</button>
                    <button class="sb-btn sb-btn-emerald">Sačuvaj na mapi</button>
                </div>
                <div id="preflight-panel" class="hidden overflow-hidden rounded-lg border border-amber-200 bg-amber-50">
                    <div class="flex items-center justify-between border-b border-amber-200 px-3 py-2">
                        <b class="text-xs text-amber-950">Plan treba doradu</b>
                        <span id="preflight-count" class="rounded-full bg-amber-200 px-2 py-0.5 text-[10px] font-black text-amber-900"></span>
                    </div>
                    <div id="preflight-list" class="grid max-h-40 divide-y divide-amber-100 overflow-y-auto"></div>
                </div>
                <div id="export-actions" style="display:none" class="grid grid-cols-3 gap-1.5">
                    <a id="export-geojson" href="#" class="sb-btn sb-btn-outline" style="font-size:10px;padding:5px 8px;text-align:center">GeoJSON</a>
                    <a id="export-dxf" href="#" class="sb-btn sb-btn-outline" style="font-size:10px;padding:5px 8px;text-align:center">DXF</a>
                    <a id="print-project" href="#" class="sb-btn sb-btn-outline" style="font-size:10px;padding:5px 8px;text-align:center">Print</a>
                </div>
                <div id="bulk-plan-status" class="text-xs font-semibold text-emerald-700"></div>
            </div>
        </form>
        </details>

        <!-- Provjera projekta -->
        <details class="sidebar-card">
            <summary class="sidebar-hd">
                <span class="sdot sdot-amber"></span>
                Provjera projekta
                <svg class="chev w-3.5 h-3.5" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M7.293 14.707a1 1 0 010-1.414L10.586 10 7.293 6.707a1 1 0 011.414-1.414l4 4a1 1 0 010 1.414l-4 4a1 1 0 01-1.414 0z" clip-rule="evenodd"/></svg>
            </summary>
            <div class="sidebar-bd grid gap-2">
                <button type="button" id="run-project-check" class="sb-btn sb-btn-outline">Provjeri</button>
                <div id="project-check-summary" class="text-xs text-slate-500">Odaberi projekat i pokreni provjeru.</div>
                <div id="project-check-panel" class="grid max-h-60 gap-1 overflow-y-auto text-xs"></div>
            </div>
        </details>

        <!-- Trasa atributi (contextual) -->
        <div id="route-attribute-panel" class="ctx-panel hidden" style="border-color:#93c5fd;border-width:2px">
            <div class="ctx-panel-hd">
                <div>
                    <div class="text-sm font-semibold text-slate-900">Trasa</div>
                    <div id="route-attribute-status" class="text-xs text-slate-500">Odabrana trasa</div>
                </div>
                <button type="button" id="close-route-attribute-panel" class="sb-btn sb-btn-outline" style="width:auto;padding:3px 9px;font-size:11px">Zatvori</button>
            </div>
            <div class="sidebar-bd grid gap-2">
                <input id="route-attr-name" class="sb-inp" placeholder="Naziv trase">
                <div class="grid grid-cols-2 gap-2">
                    <select id="route-attr-type" class="sb-sel">
                        <option value="trench">Glavni rov</option>
                        <option value="backbone">Backbone</option>
                        <option value="feeder">Primarni</option>
                        <option value="distribution">Sekundarni</option>
                        <option value="drop">Drop</option>
                    </select>
                    <select id="route-attr-microduct" class="sb-sel">
                        <option value="">Bez mikrocijevi</option>
                        <option value="14/10">14/10</option>
                        <option value="10/8">10/8</option>
                    </select>
                </div>
                <div class="grid grid-cols-2 gap-2">
                    <select id="route-attr-fibers" class="sb-sel">
                        <option value="4">4F</option>
                        <option value="12">12F</option>
                        <option value="24">24F</option>
                        <option value="48">48F</option>
                    </select>
                    <select id="route-attr-installation" class="sb-sel">
                        <option value="underground">Podzemno</option>
                        <option value="aerial">Nadzemno</option>
                    </select>
                </div>
                <div class="grid grid-cols-2 gap-2">
                    <label class="grid gap-1"><span class="sb-kicker">Broj mikrocijevi</span><input id="route-attr-microduct-count" type="number" min="0" max="48" class="sb-inp" value="1"></label>
                    <label class="grid gap-1"><span class="sb-kicker">Napomena</span><input id="route-attr-note" class="sb-inp" placeholder="Napomena"></label>
                </div>
                <div class="grid grid-cols-2 gap-2">
                    <button type="button" id="edit-route-geometry" class="sb-btn sb-btn-outline">Uredi geometriju</button>
                    <button type="button" id="save-route-attributes" class="sb-btn sb-btn-blue">Sačuvaj podatke trase</button>
                </div>
            </div>
        </div>

        <!-- Auto FTTH -->
        <details class="sidebar-card">
            <summary class="sidebar-hd">
                <span class="sdot sdot-violet"></span>
                Auto raspored FTTH
                <svg class="chev w-3.5 h-3.5" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M7.293 14.707a1 1 0 010-1.414L10.586 10 7.293 6.707a1 1 0 011.414-1.414l4 4a1 1 0 010 1.414l-4 4a1 1 0 01-1.414 0z" clip-rule="evenodd"/></svg>
            </summary>
            <div class="sidebar-bd grid gap-3">
                <div id="planner-context" class="rounded-lg border border-violet-200 bg-violet-50 px-3 py-2.5 text-[11px] leading-5 text-violet-900">
                    <b class="block">Siguran tok: parametri → preview → pregled → potvrda</b>
                    <span id="planner-context-project">{{ $activeProjectId ? 'Aktivan je odabrani projekat.' : 'Prvo odaberi jedan projekat.' }}</span>
                </div>
                <details class="planner-help rounded-lg border border-slate-200 bg-white">
                    <summary class="cursor-pointer px-3 py-2 text-[11px] font-bold text-slate-700">Kako rade Auto ODO i GIS plan?</summary>
                    <div class="grid gap-2 border-t border-slate-100 px-3 py-2.5 text-[10px] leading-4 text-slate-600">
                        <p><b>Predloži FTTH</b> koristi postojeće sekundarne krakove, raspoređuje nepovezane kuće i može dopuniti postojeće ODO-e.</p>
                        <p><b>GIS plan</b> koristi dozvoljene GIS koridore ili postojeće nedrop trase i predlaže distribucijsku mrežu.</p>
                        <p class="rounded bg-amber-50 p-2 font-semibold text-amber-800">Ni jedna potvrda ne kreira konačne pojedinačne drop trase. Preview uvijek pregledaj na mapi.</p>
                        <a href="{{ route('documentation') }}" target="_blank" class="font-bold text-sky-700 underline">Otvori detaljno uputstvo ↗</a>
                    </div>
                </details>
                <div class="grid grid-cols-4 gap-2">
                    <div class="sb-kicker">Min<input id="planner-min" type="number" min="1" max="12" value="8" class="sb-inp mt-1"></div>
                    <div class="sb-kicker">Max<input id="planner-max" type="number" min="1" max="12" value="12" class="sb-inp mt-1"></div>
                    <div class="sb-kicker">Max m<input id="planner-max-drop" type="number" min="20" value="90" class="sb-inp mt-1"></div>
                    <div class="flex items-end"><button type="button" id="clear-suggestions" class="sb-btn sb-btn-outline">Ocisti</button></div>
                </div>
                <div class="grid grid-cols-2 gap-2">
                    <button type="button" id="suggest-cabinets" class="sb-btn sb-btn-violet">Predlozi FTTH</button>
                    <button type="button" id="preview-gis-plan" class="sb-btn sb-btn-blue">GIS plan</button>
                </div>
                <div id="suggestion-output" class="max-h-52 overflow-auto rounded-lg border border-slate-100 bg-slate-50 p-3 text-xs text-slate-600 leading-5">Nacrtaj trasu i oznaci kuce.</div>
                <label id="planner-review-wrap" class="hidden items-start gap-2 rounded-lg border border-emerald-200 bg-emerald-50 p-2.5 text-[10px] font-semibold leading-4 text-emerald-900">
                    <input type="checkbox" id="planner-review-confirm" class="mt-0.5">
                    <span>Pregledao/la sam ODO pozicije, kuće bez rute, kapacitete, upozorenja i razumijem šta će biti trajno snimljeno.</span>
                </label>
                <button type="button" id="save-gis-plan" class="hidden sb-btn sb-btn-blue" disabled>Snimi GIS mrezu</button>
                <button type="button" id="save-suggestions" class="hidden sb-btn sb-btn-violet" disabled>Potvrdi raspored</button>
            </div>
        </details>


        <!-- Layer Manager -->
        <details class="sidebar-card">
            <summary class="sidebar-hd">
                <span class="sdot sdot-slate"></span>
                Slojevi mape
                <svg class="chev w-3.5 h-3.5" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M7.293 14.707a1 1 0 010-1.414L10.586 10 7.293 6.707a1 1 0 011.414-1.414l4 4a1 1 0 010 1.414l-4 4a1 1 0 01-1.414 0z" clip-rule="evenodd"/></svg>
            </summary>
            <div class="sidebar-bd grid gap-0.5 py-2">
                @foreach(['odf' => ['ODF','#0891b2'], 'odo' => ['ODO','#059669'], 'houses' => ['Kuce','#7c3aed'], 'trench' => ['Glavni rov','#64748b'], 'backbone' => ['Backbone','#0f172a'], 'distribution' => ['Distribution','#d97706'], 'drop' => ['Drop','#6d28d9'], 'gis' => ['GIS ceste','#0284c7'], 'dxf' => ['DXF','#9333ea'], 'preview' => ['Preview','#0369a1'], 'measure' => ['Mjerenje','#dc2626'], 'trace' => ['Fiber tracing','#0d9488']] as $layer => [$label, $color])
                <div class="layer-row">
                    <label class="flex flex-1 items-center gap-2 cursor-pointer select-none min-w-0">
                        <input type="checkbox" data-layer-toggle="{{ $layer }}" checked>
                        <span class="inline-block w-2 h-2 rounded-full shrink-0" style="background:{{ $color }}"></span>
                        <span class="text-slate-700 truncate">{{ $label }}</span>
                        <span data-layer-count="{{ $layer }}" class="text-[10px] text-slate-400 ml-auto shrink-0">0</span>
                    </label>
                    <input type="range" data-layer-opacity="{{ $layer }}" min="10" max="100" value="100" title="Providnost sloja" class="layer-opacity-slider">
                    <button type="button" data-layer-lock="{{ $layer }}" class="layer-lock-btn">&#x1F513;</button>
                </div>
                @endforeach
            </div>
        </details>

        <!-- Fiber tracing (contextual) -->
        <section id="map-trace-panel" class="ctx-panel hidden">
            <div class="ctx-panel-hd">
                <div class="text-sm font-semibold text-slate-900">Fiber tracing</div>
                <button type="button" id="clear-map-trace" class="sb-btn sb-btn-outline" style="width:auto;padding:3px 9px;font-size:11px">Ocisti</button>
            </div>
            <div class="sidebar-bd">
                <div id="map-trace-output" class="grid gap-2 text-xs text-slate-700"></div>
            </div>
        </section>

        <!-- Napredne forme -->
        <details class="sidebar-card">
            <summary class="sidebar-hd">
                <span class="sdot sdot-slate"></span>
                Napredno uređivanje
                <svg class="chev w-3.5 h-3.5" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M7.293 14.707a1 1 0 010-1.414L10.586 10 7.293 6.707a1 1 0 011.414-1.414l4 4a1 1 0 010 1.414l-4 4a1 1 0 01-1.414 0z" clip-rule="evenodd"/></svg>
            </summary>
            <div class="sidebar-bd grid gap-3">
                <form method="POST" action="{{ route('odfs.store') }}" id="odf-form" class="grid gap-2 rounded-lg border border-cyan-100 bg-cyan-50/50 p-3">
                    @csrf
                    <div class="text-xs font-bold text-cyan-900 mb-1">Sačuvaj ODF</div>
                    <select name="project_id" class="sb-sel" required><option value="">Projekat</option>@foreach($projects as $project)<option value="{{ $project->id }}">{{ $project->name }}</option>@endforeach</select>
                    <input name="name" class="sb-inp" placeholder="Naziv ODF-a" required>
                    <input name="address" class="sb-inp" placeholder="Adresa" required>
                    <div class="grid grid-cols-2 gap-2">
                        <input type="number" name="fiber_capacity" value="144" min="1" class="sb-inp" placeholder="Vlakna">
                        <input type="number" name="port_count" value="48" min="1" class="sb-inp" placeholder="Portovi">
                    </div>
                    <div class="grid grid-cols-2 gap-2">
                        <input id="odf-lat" name="latitude" class="sb-inp" placeholder="Lat" required>
                        <input id="odf-lng" name="longitude" class="sb-inp" placeholder="Lng" required>
                    </div>
                    <button class="sb-btn sb-btn-cyan">Sačuvaj ODF</button>
                </form>
                <form method="POST" action="{{ route('cabinets.store') }}" id="cabinet-form" class="grid gap-2 rounded-lg border border-emerald-100 bg-emerald-50/50 p-3">
                    @csrf
                    <div class="text-xs font-bold text-emerald-900 mb-1">Sačuvaj FTTH ormarić</div>
                    <select name="project_id" class="sb-sel" required><option value="">Projekat</option>@foreach($projects as $project)<option value="{{ $project->id }}">{{ $project->name }}</option>@endforeach</select>
                    <select name="odf_id" class="sb-sel"><option value="">Povezani ODF</option>@foreach($odfsForSelect as $odf)<option value="{{ $odf->id }}">{{ $odf->name }} - {{ $odf->project->name }}</option>@endforeach</select>
                    <select name="parent_cabinet_id" class="sb-sel"><option value="">Napaja se iz ODF-a direktno</option>@foreach($cabinetsForSelect as $parentCabinet)<option value="{{ $parentCabinet->id }}">Iz ODO: {{ $parentCabinet->name }} - {{ $parentCabinet->project->name }}</option>@endforeach</select>
                    <input name="name" class="sb-inp" placeholder="Naziv, npr. FTTH 1-2-3" required>
                    <input name="address" class="sb-inp" placeholder="Adresa" required>
                    <div class="grid grid-cols-2 gap-2">
                        <input type="number" name="splitter_count" value="3" min="1" max="3" class="sb-inp" placeholder="Splitteri">
                        <input type="number" name="ports_per_splitter" value="4" min="1" max="4" class="sb-inp" placeholder="Portovi">
                    </div>
                    <div class="grid grid-cols-2 gap-2">
                        <input id="cabinet-lat" name="latitude" class="sb-inp" placeholder="Lat" required>
                        <input id="cabinet-lng" name="longitude" class="sb-inp" placeholder="Lng" required>
                    </div>
                    <button class="sb-btn sb-btn-emerald">Sačuvaj FTTH</button>
                </form>
                <form method="POST" action="{{ route('routes.store') }}" id="route-form" class="hidden">
                    @csrf
                    <input id="route-duct" name="duct_length_m" value="0">
                    <input id="route-fiber" name="fiber_length_m" value="0">
                    <input id="route-path" name="path" value="[]">
                </form>
                <form method="POST" action="{{ route('houses.store') }}" id="house-form" class="hidden">
                    @csrf
                    <input id="house-lat" name="latitude">
                    <input id="house-lng" name="longitude">
                </form>
            </div>
        </details>
    </aside>
</section>

<script src="{{ asset('vendor/leaflet/leaflet.js') }}"></script>
@include('ftth.map._configuration')
<script src="{{ asset('js/map/state.js') }}?v={{ filemtime(public_path('js/map/state.js')) }}"></script>
<script src="{{ asset('js/map/utils.js') }}?v={{ filemtime(public_path('js/map/utils.js')) }}"></script>
<script src="{{ asset('js/map/layers.js') }}?v={{ filemtime(public_path('js/map/layers.js')) }}"></script>
<script src="{{ asset('js/map/markers.js') }}?v={{ filemtime(public_path('js/map/markers.js')) }}"></script>
<script src="{{ asset('js/map/context.js') }}?v={{ filemtime(public_path('js/map/context.js')) }}"></script>
<script src="{{ asset('js/map/transform.js') }}?v={{ filemtime(public_path('js/map/transform.js')) }}"></script>
<script src="{{ asset('js/map/routes.js') }}?v={{ filemtime(public_path('js/map/routes.js')) }}"></script>
<script src="{{ asset('js/map/connect.js') }}?v={{ filemtime(public_path('js/map/connect.js')) }}"></script>
<script src="{{ asset('js/map/edit.js') }}?v={{ filemtime(public_path('js/map/edit.js')) }}"></script>
<script src="{{ asset('js/map/draw.js') }}?v={{ filemtime(public_path('js/map/draw.js')) }}"></script>
<script src="{{ asset('js/map/autoplan.js') }}?v={{ filemtime(public_path('js/map/autoplan.js')) }}"></script>
<script src="{{ asset('js/map/draft.js') }}?v={{ filemtime(public_path('js/map/draft.js')) }}"></script>
<script src="{{ asset('js/map/toolbar.js') }}?v={{ filemtime(public_path('js/map/toolbar.js')) }}"></script>
<script src="{{ asset('js/map/interactions.js') }}?v={{ filemtime(public_path('js/map/interactions.js')) }}"></script>
<script src="{{ asset('js/map/exports.js') }}?v={{ filemtime(public_path('js/map/exports.js')) }}"></script>
<script src="{{ asset('js/map/controls.js') }}?v={{ filemtime(public_path('js/map/controls.js')) }}"></script>
<script src="{{ asset('js/map/hydrate.js') }}?v={{ filemtime(public_path('js/map/hydrate.js')) }}"></script>
<script src="{{ asset('js/map/init.js') }}?v={{ filemtime(public_path('js/map/init.js')) }}"></script>
<script>
    (() => {
        const dock = document.querySelector('.map-vertical-tools');
        if (!dock) return;
        const sync = () => dock.querySelectorAll('[data-map-tool]').forEach(button => {
            button.classList.toggle('is-active', document.getElementById(`mode-${button.dataset.mapTool}`)?.classList.contains('ring-2'));
        });
        dock.addEventListener('click', event => {
            const button = event.target.closest('button');
            if (!button) return;
            if (button.hasAttribute('data-map-delete')) {
                const deleteButton = document.getElementById('select-delete-btn');
                const selectionVisible = document.getElementById('select-actions')?.style.display !== 'none';
                (selectionVisible ? deleteButton : document.getElementById('mode-select'))?.click();
            } else {
                document.getElementById(`mode-${button.dataset.mapTool}`)?.click();
            }
            requestAnimationFrame(sync);
        });
        new MutationObserver(sync).observe(document.getElementById('map-cad-toolbar'), { attributes: true, subtree: true, attributeFilter: ['class'] });
        sync();
    })();
</script>

@include('ftth.map._dxf-panel')
@canany(['project.edit', 'field.capture'])@include('ftth.map._survey-panel')@endcanany

<script src="{{ asset('vendor/proj4/proj4.js') }}"></script>
<script src="{{ asset('js/ftth-dxf-layer.js') }}?v={{ filemtime(public_path('js/ftth-dxf-layer.js')) }}"></script>
{{-- survey.js mora doci NAKON #survey-panel HTML-a da bi dugmad postojala pri bindanju --}}
<script src="{{ asset('js/map/survey.js') }}?v={{ filemtime(public_path('js/map/survey.js')) }}"></script>
@endsection
