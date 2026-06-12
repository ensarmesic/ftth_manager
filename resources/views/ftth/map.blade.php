@extends('ftth.layout')

@section('title', 'Mapa mreže')
@section('subtitle', 'Satelitski projektantski prikaz za ODF, FTTH ormariće, kuće i trase.')
@section('wide', '1')

@section('content')
<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css">

<style>
    #map-workspace {
        --panel-bg: rgba(255, 255, 255, .96);
        min-height: 0;
    }
    .map-shell {
        background:
            linear-gradient(135deg, rgba(8,145,178,.10), transparent 34%),
            linear-gradient(315deg, rgba(16,185,129,.12), transparent 36%),
            #f8fafc;
    }
    .control-panel {
        background: var(--panel-bg);
        border: 1px solid rgba(15, 23, 42, .10);
        box-shadow: 0 18px 45px rgba(15, 23, 42, .08);
        backdrop-filter: blur(14px);
    }
    .metric-card {
        border: 1px solid rgba(15, 23, 42, .08);
        background: linear-gradient(180deg, #ffffff, #f8fafc);
    }
    .tool-btn, .action-btn {
        transition: transform .15s ease, box-shadow .15s ease, border-color .15s ease, background .15s ease;
    }
    .tool-btn:hover, .action-btn:hover {
        transform: translateY(-1px);
        box-shadow: 0 10px 24px rgba(15, 23, 42, .10);
    }
    .ftth-label { border: 0; background: transparent; }
    .ftth-tag { position: absolute; left: 1px; top: 1px; transform: translate(-50%, -50%); box-shadow: 0 7px 20px rgba(0,0,0,.35); border: 1.5px solid #fff; color: #fff; font: 700 9px/1 system-ui, sans-serif; display: grid; place-items: center; }
    .ftth-tag.odf { min-width: 30px; height: 18px; border-radius: 999px; background: #308DCC; }
    .ftth-tag.cabinet { min-width: 38px; height: 18px; border-radius: 5px; background: #65A845; }
    .ftth-tag.house { width: 14px; height: 14px; border: 2px solid #fff; border-radius: 999px; background: #81C342; font-size: 0; }
    .ftth-tag.suggest { min-width: 46px; height: 20px; border-radius: 5px; background: #f59e0b; color: #111827; }
    .ftth-tag.manhole { width: 22px; height: 22px; border-radius: 6px; background: #334155; font-size: 9px; }
    .ftth-tag.boring { width: 10px; height: 10px; border-radius: 2px; background: #dc2626; font-size: 0; transform: translate(-50%, -50%) rotate(45deg); }
    .boring-grip { border: 0; background: transparent; }
    .boring-grip span {
        display: grid;
        width: 8px;
        height: 8px;
        place-items: center;
        border: 1.5px solid #fff;
        border-radius: 2px;
        background: #f59e0b;
        color: #111827;
        box-shadow: 0 1px 4px rgba(15, 23, 42, .28);
        font-size: 0;
        transform: rotate(45deg);
    }
    .boring-length-label { border: 0; background: transparent; pointer-events: none; }
    .boring-length-label span {
        display: block;
        transform: translate(-50%, -50%);
        white-space: nowrap;
        border: 1px solid #fecaca;
        border-radius: 5px;
        background: rgba(255, 255, 255, .95);
        color: #991b1b;
        box-shadow: 0 6px 16px rgba(15, 23, 42, .16);
        padding: 2px 6px;
        font: 800 11px/1.2 system-ui, sans-serif;
    }
    .cad-popup .leaflet-popup-content-wrapper { border-radius: 6px; padding: 0; }
    .cad-popup .leaflet-popup-content { width: 92px !important; margin: 0; }
    .cad-popup .leaflet-popup-close-button { width: 16px; height: 16px; padding: 0; font-size: 14px; line-height: 14px; }
    .cad-popup .leaflet-popup-tip { width: 10px; height: 10px; }
    .route-label {
        border: 0;
        background: transparent;
        pointer-events: none;
    }
    .route-label span {
        display: block;
        border: 1px solid rgba(255, 255, 255, .75);
        border-radius: 4px;
        background: rgba(15, 23, 42, .76);
        box-shadow: 0 4px 12px rgba(15, 23, 42, .2);
        color: #fff;
        font: 700 10px/1 ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace;
        letter-spacing: 0;
        padding: 3px 5px;
        white-space: nowrap;
    }
    .cad-status {
        border-top: 1px solid rgba(15, 23, 42, .12);
        background: #0f172a;
        color: #e2e8f0;
        font: 600 12px/1.2 ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace;
    }
    .cad-chip {
        border: 1px solid rgba(148, 163, 184, .35);
        background: rgba(15, 23, 42, .8);
    }
    #network-map { min-height: 620px; }
    @media (min-width: 1280px) {
        #network-map { min-height: 0; }
    }
</style>

<section id="map-workspace" class="grid flex-1 gap-2 xl:grid-cols-[minmax(0,1fr)_330px]">
    <div class="map-shell flex min-h-0 flex-col overflow-hidden rounded-md border border-zinc-200 shadow-sm">
        <div class="shrink-0 border-b border-zinc-200 bg-white/95 px-4 py-3 backdrop-blur">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h2 class="text-base font-semibold">Radna karta</h2>
                    <p class="text-xs text-zinc-500">Satelit, trase, ODF, FTTH ormarići i kuće na jednom platnu.</p>
                </div>
                <div class="grid grid-cols-3 gap-2 text-xs">
                    <div class="metric-card rounded-md px-3 py-2"><span class="block text-zinc-500">Trasa</span><strong id="draw-length" class="text-sm text-amber-700">0 m</strong></div>
                    <div class="metric-card rounded-md px-3 py-2"><span class="block text-zinc-500">Kuće</span><strong id="house-count" class="text-sm text-violet-700">0</strong></div>
                    <div class="metric-card rounded-md px-3 py-2"><span class="block text-zinc-500">FTTH</span><strong id="cabinet-count" class="text-sm text-emerald-700">0</strong></div>
                </div>
            </div>
            <div class="mt-3 flex flex-wrap items-center gap-2">
                <button type="button" id="mode-pan" class="tool-btn rounded-md bg-zinc-950 px-3 py-2 text-sm font-semibold text-white">Pomjeraj</button>
                <button type="button" id="mode-odf" class="tool-btn rounded-md border border-cyan-200 bg-cyan-50 px-3 py-2 text-sm font-semibold text-cyan-800">ODF</button>
                <button type="button" id="mode-cabinet" class="tool-btn rounded-md border border-emerald-200 bg-emerald-50 px-3 py-2 text-sm font-semibold text-emerald-800">FTTH</button>
                <button type="button" id="mode-house" class="tool-btn rounded-md border border-violet-200 bg-violet-50 px-3 py-2 text-sm font-semibold text-violet-800">Kuće</button>
                <button type="button" id="mode-draw" class="tool-btn rounded-md border border-amber-200 bg-amber-50 px-3 py-2 text-sm font-semibold text-amber-800">Trasa</button>
                <button type="button" id="mode-trench-draw" class="tool-btn rounded-md border border-zinc-400 bg-zinc-100 px-3 py-2 text-sm font-semibold text-zinc-900">Glavni rov</button>
                <button type="button" id="mode-manhole" class="tool-btn rounded-md border border-slate-300 bg-slate-100 px-3 py-2 text-sm font-semibold text-slate-800">Saht</button>
                <button type="button" id="mode-boring-fi-130" class="tool-btn rounded-md border border-red-200 bg-red-50 px-3 py-2 text-sm font-semibold text-red-800">Raketa FI130</button>
                <button type="button" id="mode-branch-source" class="tool-btn rounded-md border border-orange-300 bg-orange-100 px-3 py-2 text-sm font-semibold text-orange-900">Novi krak iz ODO</button>
                <button type="button" id="mode-connect" class="tool-btn rounded-md border border-blue-200 bg-blue-50 px-3 py-2 text-sm font-semibold text-blue-800">Poveži ODF-ODO</button>
                <button type="button" id="mode-connect-houses" class="tool-btn rounded-md border border-violet-200 bg-violet-50 px-3 py-2 text-sm font-semibold text-violet-800">Poveži ODO-kuće</button>
                <button type="button" id="mode-trace" class="tool-btn rounded-md border border-sky-200 bg-sky-50 px-3 py-2 text-sm font-semibold text-sky-800">Trace</button>
                <button type="button" id="mode-join" class="tool-btn rounded-md border border-rose-200 bg-rose-50 px-3 py-2 text-sm font-semibold text-rose-800">Join trase</button>
                <div id="house-connect-actions" class="hidden items-center gap-2">
                    <button type="button" id="finish-house-connect" class="action-btn rounded-md bg-violet-600 px-3 py-2 text-sm font-semibold text-white">Završi povezivanje</button>
                    <button type="button" id="cancel-house-connect" class="action-btn rounded-md border border-zinc-300 bg-white px-3 py-2 text-sm font-semibold">Otkaži</button>
                </div>
                <span class="mx-1 hidden h-8 w-px bg-zinc-200 sm:block"></span>
                <button type="button" id="finish-branch" class="action-btn rounded-md bg-amber-500 px-3 py-2 text-sm font-semibold text-zinc-950">Završi krak</button>
                <button type="button" id="undo-draw" class="action-btn rounded-md border border-zinc-300 bg-white px-3 py-2 text-sm font-semibold">Undo tačka</button>
                <button type="button" id="undo-branch" class="action-btn rounded-md border border-zinc-300 bg-white px-3 py-2 text-sm font-semibold">Undo krak</button>
                <button type="button" id="undo-element" class="action-btn rounded-md border border-zinc-300 bg-white px-3 py-2 text-sm font-semibold">Undo element</button>
                <button type="button" id="undo-house" class="action-btn rounded-md border border-zinc-300 bg-white px-3 py-2 text-sm font-semibold">Undo kuća</button>
                <button type="button" id="clear-draw" class="action-btn rounded-md border border-red-200 bg-red-50 px-3 py-2 text-sm font-semibold text-red-700">Očisti trase</button>
                <button type="button" id="cancel-draw" class="action-btn rounded-md border border-red-200 bg-white px-3 py-2 text-sm font-semibold text-red-700">Ponisti crtanje</button>
                <button type="button" id="quick-save-draft" class="action-btn rounded-md bg-emerald-600 px-3 py-2 text-sm font-semibold text-white hover:bg-emerald-700">Sačuvaj nacrt</button>
                <div id="route-edit-actions" class="hidden items-center gap-2">
                    <button type="button" id="add-route-vertex" class="action-btn rounded-md border border-blue-200 bg-blue-50 px-3 py-2 text-sm font-semibold text-blue-700">Dodaj tačku</button>
                    <button type="button" id="save-route-edit" class="action-btn rounded-md bg-blue-600 px-3 py-2 text-sm font-semibold text-white">Sačuvaj izmjene trase</button>
                    <button type="button" id="cancel-route-edit" class="action-btn rounded-md border border-zinc-300 bg-white px-3 py-2 text-sm font-semibold">Otkaži edit</button>
                </div>
                <button type="button" id="expand-map" class="action-btn ml-auto rounded-md border border-zinc-300 bg-white px-3 py-2 text-sm font-semibold text-zinc-700">Velika mapa</button>
            </div>
            <p class="mt-2 text-xs text-zinc-500">Nacrt se čuva automatski. Desni klik na element: obriši ili premjesti.</p>
        </div>
        <div id="network-map" class="min-h-0 flex-1 w-full"></div>
        <div class="cad-status grid gap-2 px-3 py-2 md:grid-cols-[1fr_auto_auto_auto]">
            <div id="cad-command">Command: PAN</div>
            <div id="cad-metrics" class="cad-chip rounded px-2 py-1">Points: 0 | Distance: 0m | Snap: - | ORTHO: OFF</div>
            <div id="cad-coordinates" class="cad-chip rounded px-2 py-1">LAT -, LNG -</div>
            <div class="cad-chip rounded px-2 py-1">ESC prekid | ENTER zavrsi | CTRL+Z undo | O ORTHO</div>
        </div>
    </div>

    <aside class="grid min-h-0 gap-2 xl:max-h-full xl:overflow-y-auto">
        <details class="control-panel rounded-md" open>
            <summary class="cursor-pointer list-none px-3 py-2 text-sm font-semibold">Novi projekat</summary>
            <form id="quick-project-form" class="grid gap-2 border-t border-zinc-100 p-3">
                <input name="name" class="rounded-md border border-zinc-300 px-3 py-2 text-sm" placeholder="Naziv projekta" required>
                <input type="hidden" name="quick_create" value="1">
                <button class="rounded-md bg-zinc-950 px-3 py-2 text-sm font-semibold text-white">Kreiraj i odaberi</button>
                <div id="quick-project-status" class="text-xs font-semibold text-emerald-700"></div>
            </form>
        </details>
        <div id="element-editor" class="control-panel hidden rounded-md border-2 border-emerald-300 p-3">
            <div class="flex items-center justify-between gap-2">
                <div><h2 class="font-semibold">Odabrani element</h2><p id="element-editor-type" class="text-xs text-zinc-500"></p></div>
                <button type="button" id="close-element-editor" class="rounded px-2 py-1 text-sm font-semibold text-zinc-500 hover:bg-zinc-100">Zatvori</button>
            </div>
            <input id="element-editor-name" class="mt-3 w-full rounded-md border border-zinc-300 px-3 py-2 text-sm" placeholder="Naziv elementa">
            <button type="button" id="save-element-name" class="mt-2 w-full rounded-md bg-emerald-600 px-4 py-2 text-sm font-semibold text-white">Sačuvaj naziv</button>
            <div id="element-editor-status" class="mt-2 text-xs font-semibold text-emerald-700"></div>
        </div>
        <form method="POST" action="{{ route('map.plan.store') }}" id="bulk-plan-form" class="control-panel rounded-md p-3">
            @csrf
            <div class="grid gap-1">
                <h2 class="font-semibold text-zinc-950">Plan projekta</h2>
                <span id="bulk-plan-summary" class="text-sm text-emerald-700">Draft: 0 ODF, 0 FTTH, 0 kuca, 0 trasa, 0 stavki.</span>
            </div>
            <div class="mt-3 grid gap-2">
                <select id="active-project-id" name="project_id" class="rounded-md border border-zinc-300 bg-white px-3 py-2 text-sm" required><option value="">Odaberi projekat</option>@foreach($projects as $project)<option value="{{ $project->id }}">{{ $project->name }}</option>@endforeach</select>
                <div class="grid gap-2 rounded-md border border-emerald-200 bg-emerald-50 p-2">
                    <div class="grid grid-cols-2 gap-2 text-xs font-semibold">
                        <button type="button" data-guide-mode="odf" class="guide-step rounded bg-cyan-100 px-2 py-2 text-cyan-800">1 ODF</button>
                        <button type="button" data-guide-mode="draw" class="guide-step rounded bg-amber-100 px-2 py-2 text-amber-800">2 Trasa</button>
                        <button type="button" data-guide-mode="house" class="guide-step rounded bg-violet-100 px-2 py-2 text-violet-800">3 Kuće</button>
                        <button type="button" id="guide-suggest" class="guide-step rounded bg-emerald-100 px-2 py-2 text-emerald-800">4 FTTH</button>
                    </div>
                    <button class="rounded-md bg-emerald-600 px-4 py-2 text-sm font-semibold text-white hover:bg-emerald-700">5 Sačuvaj na mapi</button>
                </div>
                <div class="rounded-md border border-cyan-100 bg-cyan-50 p-3">
                    <label class="grid gap-1 text-xs font-semibold text-cyan-900">
                        Aktivni ODF za nove ormariće
                        <select id="active-odf-index" class="rounded-md border border-cyan-200 bg-white px-3 py-2 text-sm font-normal text-zinc-800">
                            <option value="">Prvo postavi ODF</option>
                        </select>
                    </label>
                    <div id="odf-link-status" class="mt-2 text-xs text-cyan-800">Postavi ODF, zatim postavljaj FTTH ormariće.</div>
                </div>
                <div class="rounded-md border border-amber-300 bg-amber-50 p-3 shadow-sm">
                    <div class="flex items-center justify-between gap-2">
                        <div>
                            <div class="text-sm font-semibold text-amber-950">Obracun rova</div>
                            <div class="text-xs text-amber-800">Prvo nacrtaj Glavni rov, zatim posebno mikrocijevi/krakove.</div>
                        </div>
                        <span id="route-trench-status" class="rounded bg-emerald-600 px-2 py-1 text-xs font-bold text-white">tip trase odlucuje</span>
                    </div>
                </div>
                <details class="rounded-md border border-amber-100 bg-amber-50">
                    <summary class="cursor-pointer list-none px-3 py-2 text-sm font-semibold text-amber-950">Postavke trase</summary>
                <div class="border-t border-amber-100 p-3">
	                    <div class="mb-2 flex items-center justify-between gap-2">
	                        <h3 class="text-sm font-semibold text-amber-950">Aktivni krak trase</h3>
	                        <span id="route-branch-count" class="text-xs font-semibold text-amber-700">0 krakova</span>
	                    </div>
	                    <label class="mb-2 grid gap-1 text-xs text-amber-900">Pocetak trase
	                        <select id="route-start-source" class="rounded-md border border-amber-200 bg-white px-2 py-2 text-sm text-zinc-800">
	                            <option value="">Automatski / bez veze</option>
	                            @foreach($odfsForSelect as $odf)
	                                <option value="odf:{{ $odf->id }}">ODF: {{ $odf->name }} - {{ $odf->project->name }}</option>
	                            @endforeach
	                            @foreach($cabinetsForSelect as $cabinet)
	                                <option value="cabinet:{{ $cabinet->id }}">ODO: {{ $cabinet->name }} - {{ $cabinet->project->name }}</option>
	                            @endforeach
	                        </select>
	                    </label>
	                    <div class="grid grid-cols-2 gap-2">
                        <label class="grid gap-1 text-xs text-amber-900">Tip
                            <select id="route-draw-type" class="rounded-md border border-amber-200 bg-white px-2 py-2 text-sm text-zinc-800">
                                <option value="trench">Glavni rov</option>
                                <option value="feeder">Primarni</option>
                                <option value="distribution">Sekundarni</option>
                                <option value="drop">Drop</option>
                            </select>
                        </label>
                        <label class="grid gap-1 text-xs text-amber-900">Mikrocijevi
                            <input id="route-draw-microducts" type="number" min="1" value="1" class="rounded-md border border-amber-200 bg-white px-2 py-2 text-sm text-zinc-800">
                        </label>
                    </div>
                    <div class="mt-2 grid grid-cols-3 gap-2">
                        <label class="grid gap-1 text-xs text-amber-900">Polaganje
                            <select id="route-draw-installation" class="rounded-md border border-amber-200 bg-white px-2 py-2 text-sm text-zinc-800"><option value="underground">Podzemna</option><option value="aerial">Zračna</option></select>
                        </label>
                        <label class="grid gap-1 text-xs text-amber-900">Mikrocijev
                            <select id="route-draw-microduct-type" class="rounded-md border border-amber-200 bg-white px-2 py-2 text-sm text-zinc-800"><option value="14/10">14/10</option><option value="10/8">10/8</option></select>
                        </label>
                        <label class="grid gap-1 text-xs text-amber-900">Niti
                            <select id="route-draw-fiber-count" class="rounded-md border border-amber-200 bg-white px-2 py-2 text-sm text-zinc-800"><option value="4">4</option><option value="12" selected>12</option><option value="24">24</option><option value="48">48</option></select>
                        </label>
                    </div>
                    <label class="mt-2 grid gap-1 text-xs text-amber-900">Oznaka
                        <input id="route-draw-name" class="rounded-md border border-amber-200 bg-white px-2 py-2 text-sm text-zinc-800" placeholder="npr. P-01 ili S-01">
                    </label>
                    <div id="route-odf-status" class="mt-2 rounded bg-white/70 px-2 py-2 text-xs font-semibold text-amber-900">Krak nije vezan na ODF.</div>
                    <div id="route-branch-list" class="mt-3 grid max-h-32 gap-1 overflow-y-auto text-xs text-amber-950"></div>
                </div>
                </details>
                <input id="bulk-plan-json" type="hidden" name="plan">
                <div class="grid grid-cols-2 gap-2">
                    <button type="button" id="save-draft" class="action-btn rounded-md border border-emerald-200 bg-emerald-50 px-4 py-2 text-sm font-semibold text-emerald-800">Sačuvaj radnu verziju</button>
                    <button class="action-btn rounded-md bg-emerald-600 px-4 py-2 text-sm font-semibold text-white hover:bg-emerald-700">Sačuvaj na mapi</button>
                </div>
            </div>
            <div id="bulk-plan-status" class="mt-2 text-sm font-semibold text-emerald-800"></div>
        </form>

        <details class="control-panel rounded-md">
            <summary class="cursor-pointer list-none px-3 py-2 text-sm font-semibold">Automatski raspored FTTH</summary>
        <div class="border-t border-zinc-100 p-3">
            <div class="flex items-center justify-between gap-3">
                <h2 class="font-semibold">Automatski raspored</h2>
                <button type="button" id="suggest-cabinets" class="action-btn rounded-md bg-violet-600 px-4 py-2 text-sm font-semibold text-white hover:bg-violet-700">Predloži FTTH</button>
            </div>
            <div class="mt-2 grid grid-cols-4 gap-2">
                <label class="grid gap-1 text-xs text-zinc-600">Min<input id="planner-min" type="number" min="1" max="12" value="8" class="rounded-md border border-zinc-300 px-2 py-2 text-sm"></label>
                <label class="grid gap-1 text-xs text-zinc-600">Max<input id="planner-max" type="number" min="1" max="12" value="12" class="rounded-md border border-zinc-300 px-2 py-2 text-sm"></label>
                <label class="grid gap-1 text-xs text-zinc-600">Max m<input id="planner-max-drop" type="number" min="20" value="90" class="rounded-md border border-zinc-300 px-2 py-2 text-sm"></label>
                <button type="button" id="clear-suggestions" class="action-btn mt-5 rounded-md border border-zinc-300 bg-white px-3 py-2 text-sm font-semibold">Očisti</button>
            </div>
            <div id="suggestion-output" class="mt-3 max-h-56 overflow-auto rounded-md border border-zinc-100 bg-zinc-50 p-3 text-sm text-zinc-700">Nacrtaj trasu i oznaci kuće.</div>
            <button type="button" id="save-suggestions" class="action-btn mt-3 hidden w-full rounded-md bg-violet-600 px-4 py-2 text-sm font-semibold text-white hover:bg-violet-700">Potvrdi raspored</button>
        </div>
        </details>

        <details class="control-panel rounded-md" open>
            <summary class="cursor-pointer list-none px-3 py-2 text-sm font-semibold">Layer Manager</summary>
            <div class="grid gap-1 border-t border-zinc-100 p-3 text-sm">
                @foreach(['odf' => 'ODF', 'odo' => 'ODO', 'houses' => 'Kuće', 'backbone' => 'Backbone', 'distribution' => 'Distribution', 'drop' => 'Drop', 'dxf' => 'DXF', 'preview' => 'Preview', 'measure' => 'Mjerenje', 'trace' => 'Fiber tracing'] as $layer => $label)
                    <div class="grid grid-cols-[1fr_auto_auto] items-center gap-2 rounded border border-zinc-100 bg-white px-2 py-1">
                        <label><input type="checkbox" data-layer-toggle="{{ $layer }}" checked> {{ $label }} <span data-layer-count="{{ $layer }}" class="text-xs text-zinc-400">0</span></label>
                        <button type="button" data-layer-lock="{{ $layer }}" class="rounded border border-zinc-200 px-2 py-1 text-xs font-semibold">Otključan</button>
                    </div>
                @endforeach
            </div>
        </details>

        <section id="map-trace-panel" class="control-panel hidden rounded-md p-3">
            <div class="flex items-center justify-between gap-2">
                <h2 class="font-semibold">Fiber tracing</h2>
                <button type="button" id="clear-map-trace" class="rounded px-2 py-1 text-sm font-semibold text-zinc-500 hover:bg-zinc-100">Ocisti</button>
            </div>
            <div id="map-trace-output" class="mt-3 grid gap-2 text-sm text-slate-700"></div>
        </section>

        <details class="control-panel rounded-md">
            <summary class="cursor-pointer list-none px-3 py-2 text-sm font-semibold">Napredne forme za pojedinacno snimanje</summary>
            <div class="grid gap-3 border-t border-zinc-100 p-3">
                <form method="POST" action="{{ route('odfs.store') }}" id="odf-form" class="grid gap-3 rounded-md border border-cyan-100 bg-cyan-50 p-3">
                    @csrf
                    <h3 class="font-semibold text-cyan-900">Sačuvaj ODF</h3>
                    <select name="project_id" class="rounded-md border border-zinc-300 px-3 py-2 text-sm" required><option value="">Projekat</option>@foreach($projects as $project)<option value="{{ $project->id }}">{{ $project->name }}</option>@endforeach</select>
                    <input name="name" class="rounded-md border border-zinc-300 px-3 py-2 text-sm" placeholder="Naziv ODF-a" required>
                    <input name="address" class="rounded-md border border-zinc-300 px-3 py-2 text-sm" placeholder="Adresa" required>
                    <input type="number" name="fiber_capacity" value="144" min="1" class="rounded-md border border-zinc-300 px-3 py-2 text-sm" required>
                    <input type="number" name="port_count" value="48" min="1" class="rounded-md border border-zinc-300 px-3 py-2 text-sm" required>
                    <div class="grid grid-cols-2 gap-2"><input id="odf-lat" name="latitude" class="rounded-md border border-zinc-300 px-3 py-2 text-sm" placeholder="Lat" required><input id="odf-lng" name="longitude" class="rounded-md border border-zinc-300 px-3 py-2 text-sm" placeholder="Lng" required></div>
                    <button class="rounded-md bg-cyan-600 px-4 py-2 text-sm font-semibold text-white">Sačuvaj ODF</button>
                </form>
                <form method="POST" action="{{ route('cabinets.store') }}" id="cabinet-form" class="grid gap-3 rounded-md border border-emerald-100 bg-emerald-50 p-3">
                    @csrf
                    <h3 class="font-semibold text-emerald-900">Sačuvaj FTTH ormarić</h3>
                    <select name="project_id" class="rounded-md border border-zinc-300 px-3 py-2 text-sm" required><option value="">Projekat</option>@foreach($projects as $project)<option value="{{ $project->id }}">{{ $project->name }}</option>@endforeach</select>
                    <select name="odf_id" class="rounded-md border border-zinc-300 px-3 py-2 text-sm"><option value="">Povezani ODF</option>@foreach($odfsForSelect as $odf)<option value="{{ $odf->id }}">{{ $odf->name }} - {{ $odf->project->name }}</option>@endforeach</select>
                    <select name="parent_cabinet_id" class="rounded-md border border-zinc-300 px-3 py-2 text-sm"><option value="">Napaja se direktno iz ODF-a</option>@foreach($cabinetsForSelect as $parentCabinet)<option value="{{ $parentCabinet->id }}">Iz ODO: {{ $parentCabinet->name }} - {{ $parentCabinet->project->name }}</option>@endforeach</select>
                    <input name="name" class="rounded-md border border-zinc-300 px-3 py-2 text-sm" placeholder="Naziv, npr. FTTH-001" required>
                    <input name="address" class="rounded-md border border-zinc-300 px-3 py-2 text-sm" placeholder="Adresa" required>
                    <div class="grid grid-cols-2 gap-2"><input type="number" name="splitter_count" value="3" min="1" max="3" class="rounded-md border border-zinc-300 px-3 py-2 text-sm"><input type="number" name="ports_per_splitter" value="4" min="1" max="4" class="rounded-md border border-zinc-300 px-3 py-2 text-sm"></div>
                    <div class="grid grid-cols-2 gap-2"><input id="cabinet-lat" name="latitude" class="rounded-md border border-zinc-300 px-3 py-2 text-sm" placeholder="Lat" required><input id="cabinet-lng" name="longitude" class="rounded-md border border-zinc-300 px-3 py-2 text-sm" placeholder="Lng" required></div>
                    <button class="rounded-md bg-emerald-600 px-4 py-2 text-sm font-semibold text-white">Sačuvaj FTTH</button>
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
                <div id="material-specs-output" class="hidden grid gap-3 rounded-md border border-sky-100 bg-sky-50 p-3">
                    <h3 class="font-semibold text-sky-900">Materijalne specifikacije</h3>
                    <div id="material-items" class="grid gap-2 max-h-48 overflow-y-auto text-sm"><!-- dynamic content --></div>
                    <button type="button" id="save-all-materials" class="rounded-md bg-sky-600 px-4 py-2 text-sm font-semibold text-white hover:bg-sky-700">Snimi sve materijale</button>
                </div>
            </div>
        </details>
    </aside>
</section>

<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
<script>
window.MapEditor = window.MapEditor || {};
if (window.MapEditor.initialized) {
    throw new Error('MapEditor already initialized.');
}
window.MapEditor.initialized = true;
const data = @json($mapData);
window.ftthMapConfig = {
    mode: 'editor',
    projectId: null,
    endpoints: {
        autoOdoPreviewBaseUrl: @json(url('/projekti/__ID__/odo-plan/preview')),
        autoOdoConfirmBaseUrl: @json(url('/projekti/__ID__/odo-plan/confirm')),
    },
    data,
};
const defaultCenter = [44.4493, 18.6498];
const map = L.map('network-map', { zoomSnap: 0.25 }).setView(defaultCenter, 17);
let mode = 'pan';
let activeBranch = [];
let activeBranchSnapTargets = [];
let quickBranchWorkflow = false;
let activeBranchMarkers = [];
let activeBranchLine = null;
let previewBranchLine = null;
let snapIndicator = null;
let routeEdit = null;
let connectOdf = null;
let connectCabinet = null;
let connectHouseIds = new Set();
let joinRoutes = [];
let branches = [];
let branchLines = [];
let branchMeta = [];
let branchLabels = [];
let trenchLines = [];
let housePoints = [];
let houseMarkers = [];
let houseMarkerByKey = {};
let suggestionLayers = [];
let draftOdfCount = 0;
let draftCabinetCount = 0;
let draftElements = [];
let draftOdfs = [];
let draftCabinets = [];
let draftAppendixItems = [];
let suggestedCabinets = [];
let expandedMap = false;
let activeDraftOdfIndex = null;
let activeOdfSelection = null;
let cadContext = null;
let autosaveTimer = null;
let autosaveReady = false;
let restoringDraft = false;
let selectedDraftElement = null;
let keepCurrentDraftOnProjectChange = false;
let savedRoutePoints = [];
const snapPixelTolerance = 18;
let orthoEnabled = false;
let currentAutoPlan = null;
const undoStack = [];
const redoStack = [];
const odfMarkerById = {};
const cabinetMarkerById = {};
const routeLayerById = {};
const routeLabelsById = {};
let activeTraceHouseId = null;
const layerRegistry = {
    odf: [],
    odo: [],
    houses: [],
    backbone: [],
    distribution: [],
    drop: [],
    dxf: [],
    preview: [],
    measure: [],
    trace: [],
};
const layerLocks = {};
const draftsByProject = {};
const deleteUrls = {
    odf: id => `{{ url('/odf') }}/${id}`,
    cabinet: id => `{{ url('/ormarici') }}/${id}`,
    house: id => `{{ url('/kuce') }}/${id}`,
    route: id => `{{ url('/trase') }}/${id}`,
};
const positionUrls = {
    odf: id => `{{ url('/odf') }}/${id}/pozicija`,
    cabinet: id => `{{ url('/ormarici') }}/${id}/pozicija`,
    house: id => `{{ url('/kuce') }}/${id}/pozicija`,
};
async function readJsonResponse(response, fallbackMessage) {
    const text = await response.text();
    let payload = null;
    try {
        payload = text ? JSON.parse(text) : {};
    } catch {
        throw new Error(response.ok ? fallbackMessage : `${fallbackMessage} Server je vratio neispravan odgovor.`);
    }
    if (!response.ok) {
        const validationMessage = payload.errors ? Object.values(payload.errors).flat()[0] : null;
        throw new Error(validationMessage || payload.message || fallbackMessage);
    }
    return payload;
}

const imagery = L.tileLayer('https://server.arcgisonline.com/ArcGIS/rest/services/World_Imagery/MapServer/tile/{z}/{y}/{x}', {
    maxNativeZoom: 18,
    maxZoom: 22,
    attribution: 'Tiles &copy; Esri'
}).addTo(map);

const osm = L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', { maxZoom: 22, maxNativeZoom: 19, attribution: '&copy; OpenStreetMap' });
L.control.layers({ 'Satelit': imagery, 'OpenStreetMap': osm }, {}, { position: 'bottomleft' }).addTo(map);

const cabinetPalette = ['#308DCC', '#65A845', '#7BA9DA', '#81C342', '#00659E', '#A3D06E'];
function cabinetColor(id) { return cabinetPalette[(Math.max(Number(id) || 1, 1) - 1) % cabinetPalette.length]; }
const cabinetLegend = L.control({ position: 'bottomright' });
cabinetLegend.onAdd = () => {
    const box = L.DomUtil.create('div', 'rounded-md bg-white/95 p-2 text-xs shadow');
    box.innerHTML = `<b>ODO boje</b>${data.cabinets.map(c => `<div class="mt-1 flex items-center gap-1"><span style="width:10px;height:10px;border-radius:3px;background:${cabinetColor(c.id)}"></span>${c.name}</div>`).join('') || '<div class="mt-1 text-zinc-500">Nema spremljenih ODO</div>'}`;
    return box;
};
cabinetLegend.addTo(map);

function icon(type, text = '', color = null) {
    const cls = type === 'odf' ? 'odf' : type === 'cabinet' ? 'cabinet' : type === 'suggest' ? 'suggest' : type === 'manhole' ? 'manhole' : type === 'boring' ? 'boring' : 'house';
    const style = color ? ` style="background:${color}"` : '';
    return L.divIcon({ className: 'ftth-label', html: `<div class="ftth-tag ${cls}"${style}>${text}</div>`, iconSize: [2, 2], iconAnchor: [1, 1] });
}
function boringGripIcon(text) {
    return L.divIcon({
        className: 'boring-grip',
        html: `<span>${text}</span>`,
        iconSize: [8, 8],
        iconAnchor: [4, 4],
    });
}

const bounds = [];
data.routes.forEach(route => {
    if (!route.path?.length) return;
    const points = route.path.map(point => L.latLng(point[0], point[1]));
    savedRoutePoints.push(points);
    const occupancy = route.occupancy || {};
    const line = L.polyline(points, { color: route.cabinet_id ? cabinetColor(route.cabinet_id) : routeColor(route.type), weight: 4, opacity: .9 })
        .bindPopup(`<b>${route.name}</b><br>${routeTypeLabel(route.type)}<br>${route.duct_length_m} m<br>Fiber: ${occupancy.fiber_capacity ?? route.fibers ?? 0}F<br>Zauzeto: ${occupancy.used_fibers ?? '-'}<br>Slobodno: ${occupancy.free_fibers ?? '-'}<br>Iskorištenost: ${occupancy.utilization_percent ?? '-'}%`)
        .addTo(map);
    const labels = addRouteLabel(points, route.name, false);
    routeLayerById[route.id] = line;
    routeLabelsById[route.id] = labels || [];
    trackLayer(line, routeLayerType(route.type));
    labels?.forEach(label => trackLayer(label, routeLayerType(route.type)));
    registerSavedContext([line, ...labels], route.name, deleteUrls.route(route.id), null, event => {
        if (mode === 'join') selectJoinRoute(route, line);
        else if (routeEdit?.route.id === route.id) addRouteEditVertex(event.latlng);
        else startRouteEdit(route, line);
    });
    points.forEach(p => bounds.push([p.lat, p.lng]));
});
data.odfs.forEach(odf => {
    const p = L.latLng(odf.lat, odf.lng);
    const connectedCabinets = data.cabinets.filter(c => c.odf === odf.name).length;
    const marker = L.marker(p, { icon: icon('odf', 'ODF'), draggable: false })
        .bindTooltip(`${odf.name} · ${connectedCabinets} FTTH`, { direction: 'top', offset: [0, -10] })
        .bindPopup(`<b>ODF: ${odf.name}</b><br>${odf.address}<br>FTTH ormarića: ${connectedCabinets}`)
        .addTo(map);
    marker.on('click', event => {
        if (layerLocked('odf')) return document.getElementById('cad-command').textContent = 'Layer ODF je zaključan.';
        if (mode === 'connect') {
            L.DomEvent.stop(event);
            selectConnectOdf(odf);
            return;
        }
        if (mode === 'draw') {
            map.closePopup();
            addDrawPoint(event.latlng);
        }
    });
    registerSavedContext(marker, `ODF: ${odf.name}`, deleteUrls.odf(odf.id), positionUrls.odf(odf.id));
    odfMarkerById[odf.id] = marker;
    trackLayer(marker, 'odf');
    bounds.push([odf.lat, odf.lng]);
});
data.cabinets.forEach(c => {
    const p = L.latLng(c.lat, c.lng);
    const color = cabinetColor(c.id);
    const marker = L.marker(p, { icon: icon('cabinet', c.name?.startsWith('FTTH') ? c.name : `FTTH ${c.id}`, color), draggable: false })
        .bindTooltip(`${c.used_ports}/${c.capacity}`, { direction: 'top', offset: [0, -10] })
        .bindPopup(`<b>${c.name}</b><br>${c.used_ports}/${c.capacity} portova<br>ODF: ${c.odf}`)
        .addTo(map);
    marker.on('click', event => {
        if (layerLocked('odo')) return document.getElementById('cad-command').textContent = 'Layer ODO je zaključan.';
        if (mode === 'connect-houses') {
            L.DomEvent.stop(event);
            selectHouseConnectCabinet(c);
            return;
        }
        if (mode === 'connect') {
            L.DomEvent.stop(event);
            connectSelectedOdfToCabinet(c);
            return;
        }
        if (mode === 'branch-source') {
            L.DomEvent.stop(event);
            startBranchFromCabinet(c);
            return;
        }
        if (mode === 'draw') {
            map.closePopup();
            addDrawPoint(event.latlng);
            const source = document.getElementById('route-start-source')?.value || '';
            if (source.startsWith('cabinet:') && Number(source.split(':')[1]) !== Number(c.id)) finishBranch();
        }
    });
    registerSavedContext(marker, c.name, deleteUrls.cabinet(c.id), positionUrls.cabinet(c.id), null, [
        { label: 'Novi krak odavde', run: () => startBranchFromCabinet(c) },
    ]);
    cabinetMarkerById[c.id] = marker;
    trackLayer(marker, 'odo');
    bounds.push([c.lat, c.lng]);
});
    const savedHouseKeys = new Set();
    const savedHouseColorByKey = {};
    const houseDataByKey = {};
function pointKey(lat, lng) { return `${Number(lat).toFixed(7)},${Number(lng).toFixed(7)}`; }
    data.houses.forEach(h => {
        const p = L.latLng(h.lat, h.lng);
        const key = pointKey(h.lat, h.lng);
        const color = h.cabinet_id ? cabinetColor(h.cabinet_id) : null;
        savedHouseKeys.add(key);
        savedHouseColorByKey[key] = color;
        houseDataByKey[key] = h;
        const marker = L.marker(p, { icon: icon('house', '', color), draggable: false }).bindPopup(`<b>${h.label}</b><br>ODO: ${h.cabinet}`).addTo(map);
        marker.on('click', event => {
            if (layerLocked('houses')) return document.getElementById('cad-command').textContent = 'Layer kuće je zaključan.';
            if (mode === 'connect-houses') {
                L.DomEvent.stop(event);
                toggleHouseConnect(h);
                return;
            }
            if (mode === 'draw') {
                map.closePopup();
                addDrawPoint(event.latlng);
                return;
            }
            if (mode === 'trace') showFiberTrace(h.id);
        });
        registerSavedContext(marker, h.label, deleteUrls.house(h.id), positionUrls.house(h.id));
        trackLayer(marker, 'houses');
        houseMarkerByKey[key] = marker;
    housePoints.push(p);
    bounds.push([h.lat, h.lng]);
});
data.appendix_items?.forEach(item => {
    const p = L.latLng(item.lat, item.lng);
    if (item.type === 'boring_fi_130') {
        drawSavedBoring(item);
    } else {
        const marker = L.marker(p, { icon: icon('manhole', 'S'), draggable: false })
            .bindTooltip(`Prolazni saht: ${item.quantity} ${item.unit}`, { direction: 'top', offset: [0, -10] })
            .bindPopup(`<b>Prolazni saht</b><br>${item.quantity} ${item.unit}${item.note ? `<br>${item.note}` : ''}`)
            .addTo(map);
        trackLayer(marker, 'preview');
    }
    bounds.push([item.lat, item.lng]);
});
let savedHouseCount = housePoints.length;
if (bounds.length) map.fitBounds(bounds, { padding: [40, 40], maxZoom: 19 }); else map.setView(defaultCenter, 17);

function setMode(next) {
    if (routeEdit && next !== 'pan') cancelRouteEdit();
    mode = next;
    if (next !== 'connect') connectOdf = null;
    if (next !== 'connect-houses') resetHouseConnect();
    if (next !== 'join') resetJoinRoutes();
    if (next !== 'draw') hideSnapIndicator();
    document.querySelectorAll('.tool-btn').forEach(btn => btn.classList.remove('ring-2', 'ring-zinc-900'));
    const button = document.getElementById(`mode-${next}`);
    if (button) button.classList.add('ring-2', 'ring-zinc-900');
    const labels = {
        pan: 'PAN: pomjeraj mapu. Izaberi alat za crtanje.',
        odf: 'ODF: klikni lokaciju centrale/cvora. Novi ODF postaje aktivan.',
        cabinet: 'FTTH: klikni lokacije zelenih ormarića. Vezuju se na aktivni ODF.',
        house: 'KUCE: klikni svaku kucu/prikljucak. CTRL+Z vraca zadnju.',
        draw: 'TRASA: klik po klik crtaj trasu. Blizu postojece trase/tacke automatski se spoji. ENTER, dupla klik ili desni klik zavrsava krak. ESC prekida.',
        manhole: 'SAHT: klikni lokaciju prolaznog sahta.',
        'boring-fi-130': 'RAKETA FI130: klikni lokaciju podbusivanja ispod ceste.',
        'branch-source': 'NOVI KRAK IZ ODO: klikni ormarić iz kojeg novi krak polazi.',
        connect: 'CONNECT: odaberi ODF',
        'connect-houses': 'CONNECT HOUSES: odaberi ODO',
        trace: 'TRACE: klikni kuću za prikaz optičkog puta',
        join: 'JOIN: označi trase klikom, zatim pritisni ENTER',
    };
    document.getElementById('cad-command').textContent = labels[next];
    updateCommandBar();
}
['pan','odf','cabinet','house','draw','manhole','boring-fi-130','branch-source','connect','connect-houses','trace','join'].forEach(m => document.getElementById(`mode-${m}`).addEventListener('click', () => {
    if (m === 'draw' && document.getElementById('route-draw-type').value === 'trench') {
        document.getElementById('route-draw-type').value = 'distribution';
        refreshTrenchGroupStatus();
    }
    setMode(m);
}));
document.getElementById('mode-trench-draw').addEventListener('click', () => {
    document.getElementById('route-draw-type').value = 'trench';
    document.getElementById('route-draw-name').placeholder = `npr. ${nextRouteName('trench')}`;
    refreshTrenchGroupStatus();
    setMode('draw');
    document.getElementById('cad-command').textContent = 'GLAVNI ROV: klikni tacke fizickog iskopa. ENTER/desni klik zavrsava rov.';
});

function distance(points) { return Math.round(points.slice(1).reduce((sum, p, i) => sum + map.distance(points[i], p), 0)); }
function normalizeAngle(deg) {
    return ((deg % 360) + 360) % 360;
}
function metersOffset(origin, eastMeters, northMeters) {
    const latRad = origin.lat * Math.PI / 180;
    const lat = origin.lat + (northMeters / 111320);
    const lng = origin.lng + (eastMeters / (111320 * Math.max(Math.cos(latRad), 0.00001)));
    return L.latLng(lat, lng);
}
function boringGeometry(center, lengthM = 12, angleDeg = 0, widthM = 1.8) {
    const angle = normalizeAngle(angleDeg) * Math.PI / 180;
    const dx = Math.cos(angle);
    const dy = Math.sin(angle);
    const nx = -dy;
    const ny = dx;
    const halfLength = Math.max(Number(lengthM) || 0, 1) / 2;
    const halfWidth = Math.max(Number(widthM) || 0, 1) / 2;
    const edge = (side, end) => metersOffset(center, dx * halfLength * end + nx * halfWidth * side, dy * halfLength * end + ny * halfWidth * side);
    return {
        top: [edge(1, -1), edge(1, 1)],
        bottom: [edge(-1, -1), edge(-1, 1)],
        labelPoint: metersOffset(center, nx * (halfWidth + 4.5), ny * (halfWidth + 4.5)),
        lengthHandle: metersOffset(center, dx * (halfLength + 2), dy * (halfLength + 2)),
        rotateHandle: metersOffset(center, -nx * (halfWidth + 5), -ny * (halfWidth + 5)),
    };
}
function angleFromCenter(center, point) {
    const latRad = center.lat * Math.PI / 180;
    const east = (point.lng - center.lng) * 111320 * Math.max(Math.cos(latRad), 0.00001);
    const north = (point.lat - center.lat) * 111320;
    return normalizeAngle(Math.atan2(north, east) * 180 / Math.PI);
}
function formatMeters(value) {
    return `${Number(value || 0).toLocaleString('bs-BA', { minimumFractionDigits: 2, maximumFractionDigits: 2 })} m`;
}
function boringTitle(item) {
    return `Podbusivanje FI130: ${formatMeters(item.length_m)} / ${Math.round(normalizeAngle(item.angle_deg || 0))}°`;
}
function boringLengthIcon(lengthM) {
    return L.divIcon({
        className: 'boring-length-label',
        html: `<span>${formatMeters(lengthM)}</span>`,
        iconSize: [1, 1],
        iconAnchor: [0, 0],
    });
}
function drawSavedBoring(item) {
    const center = L.latLng(item.lat, item.lng);
    const lengthM = item.length_m || item.quantity || 12;
    const geometry = boringGeometry(center, lengthM, item.angle_deg || 0, item.width_m || 1.8);
    const popup = `<b>Podbusivanje raketom FI 130</b><br>Duzina: ${formatMeters(item.length_m || item.quantity || 0)}<br>Ugao: ${Math.round(normalizeAngle(item.angle_deg || 0))}°${item.note ? `<br>${item.note}` : ''}`;
    const top = L.polyline(geometry.top, { color: '#dc2626', weight: 4, opacity: .95 }).bindPopup(popup).addTo(map);
    const bottom = L.polyline(geometry.bottom, { color: '#dc2626', weight: 4, opacity: .95 }).bindPopup(popup).addTo(map);
    const label = L.marker(center, { icon: icon('boring', 'FI130'), draggable: false })
        .bindTooltip(boringTitle({ length_m: item.length_m || item.quantity || 0, angle_deg: item.angle_deg || 0 }), { direction: 'top', offset: [0, -10] })
        .bindPopup(popup)
        .addTo(map);
    const lengthLabel = L.marker(geometry.labelPoint, { icon: boringLengthIcon(lengthM), interactive: false }).addTo(map);
    trackLayer(top, 'preview');
    trackLayer(bottom, 'preview');
    trackLayer(label, 'preview');
    trackLayer(lengthLabel, 'preview');
}
function updateBoringDraft(item) {
    const center = item.marker.getLatLng();
    const geometry = boringGeometry(center, item.length_m, item.angle_deg, item.width_m);
    item.lines[0].setLatLngs(geometry.top);
    item.lines[1].setLatLngs(geometry.bottom);
    item.lengthLabel.setLatLng(geometry.labelPoint);
    item.lengthLabel.setIcon(boringLengthIcon(item.length_m));
    item.lengthHandle.setLatLng(geometry.lengthHandle);
    item.rotateHandle.setLatLng(geometry.rotateHandle);
    item.marker.setTooltipContent(boringTitle(item));
    item.marker.setPopupContent(`<b>Podbusivanje raketom FI 130</b><br>Duzina: ${formatMeters(item.length_m)}<br>Ugao: ${Math.round(normalizeAngle(item.angle_deg))}°`);
}
function createBoringDraft(center, options = {}) {
    const item = {
        type: 'boring_fi_130',
        marker: null,
        lines: [],
        lengthLabel: null,
        lengthHandle: null,
        rotateHandle: null,
        quantity: Number(options.length_m ?? options.quantity ?? 12),
        length_m: Number(options.length_m ?? options.quantity ?? 12),
        angle_deg: normalizeAngle(Number(options.angle_deg ?? 0)),
        width_m: Number(options.width_m ?? 1.8),
        note: options.note || '',
    };
    const geometry = boringGeometry(center, item.length_m, item.angle_deg, item.width_m);
    item.lines = [
        L.polyline(geometry.top, { color: '#dc2626', weight: 4, opacity: .95 }).addTo(map),
        L.polyline(geometry.bottom, { color: '#dc2626', weight: 4, opacity: .95 }).addTo(map),
    ];
    item.marker = L.marker(center, { icon: icon('boring', 'FI130'), draggable: true })
        .bindTooltip(boringTitle(item), { direction: 'top', offset: [0, -10] })
        .bindPopup('')
        .addTo(map);
    item.lengthLabel = L.marker(geometry.labelPoint, { icon: boringLengthIcon(item.length_m), interactive: false }).addTo(map);
    item.lengthHandle = L.marker(geometry.lengthHandle, { icon: boringGripIcon('L'), draggable: true })
        .bindTooltip('Povuci za duzinu', { direction: 'top', offset: [0, -10] })
        .addTo(map);
    item.rotateHandle = L.marker(geometry.rotateHandle, { icon: boringGripIcon('R'), draggable: true })
        .bindTooltip('Povuci za rotaciju 360°', { direction: 'top', offset: [0, -10] })
        .addTo(map);
    item.marker.on('drag', () => {
        updateBoringDraft(item);
        refreshPlanSummary();
    });
    item.lengthHandle.on('drag', event => {
        const centerPoint = item.marker.getLatLng();
        const handlePoint = event.target.getLatLng();
        item.angle_deg = angleFromCenter(centerPoint, handlePoint);
        item.length_m = Math.max(1, Math.round(map.distance(centerPoint, handlePoint) * 2 - 4));
        item.quantity = item.length_m;
        updateBoringDraft(item);
        refreshPlanSummary();
    });
    item.rotateHandle.on('drag', event => {
        item.angle_deg = angleFromCenter(item.marker.getLatLng(), event.target.getLatLng()) + 90;
        updateBoringDraft(item);
        refreshPlanSummary();
    });
    updateBoringDraft(item);
    return item;
}
function removeAppendixDraftItem(item) {
    if (!item) return;
    [item.marker, item.lengthLabel, item.lengthHandle, item.rotateHandle, ...(item.lines || [])].forEach(layer => {
        if (layer && map.hasLayer(layer)) map.removeLayer(layer);
    });
}
function draftNetworkPoints() { return [...branches, activeBranch].filter(b => b.length > 1); }
function allNetworkPoints() { return [...savedRoutePoints, ...branches, activeBranch].filter(b => b.length > 1); }
function allDistance() { return draftNetworkPoints().reduce((sum, b) => sum + distance(b), 0); }
function routeTypeLabel(type) {
    return type === 'trench' ? 'Glavni rov' : type === 'backbone' ? 'Backbone' : type === 'feeder' ? 'Primarni' : type === 'drop' ? 'Drop' : 'Sekundarni';
}
function routeColor(type) {
    return type === 'trench' ? '#111827' : type === 'backbone' ? '#2563eb' : type === 'feeder' ? '#308DCC' : type === 'drop' ? '#7BA9DA' : '#81C342';
}
function nextRouteName(type) {
    const prefix = type === 'trench' ? 'R' : type === 'feeder' ? 'P' : type === 'drop' ? 'D' : 'S';
    const count = branchMeta.filter(meta => meta.route_type === type).length + 1;
    return `${prefix}-${String(count).padStart(2, '0')}`;
}
function selectConnectOdf(odf) {
    connectOdf = odf;
    document.getElementById('cad-command').textContent = `CONNECT: odaberi ODO za ${odf.name}`;
}
function selectHouseConnectCabinet(cabinet) {
    resetHouseConnect();
    connectCabinet = cabinet;
    document.getElementById('house-connect-actions').classList.remove('hidden');
    document.getElementById('house-connect-actions').classList.add('flex');
    document.getElementById('cad-command').textContent = `CONNECT HOUSES: ${cabinet.name}, odaberi kuće`;
}
function toggleHouseConnect(house) {
    if (!connectCabinet) return document.getElementById('cad-command').textContent = 'CONNECT HOUSES: prvo odaberi ODO';
    if (Number(house.project_id) !== Number(connectCabinet.project_id)) return document.getElementById('cad-command').textContent = 'CONNECT HOUSES: kuća i ODO moraju biti u istom projektu.';
    if (house.cabinet_id && Number(house.cabinet_id) !== Number(connectCabinet.id)) return document.getElementById('cad-command').textContent = `${house.label} je već povezana na drugi ODO.`;
    const available = Math.max(12 - Number(connectCabinet.used_ports || 0), 0);
    if (!connectHouseIds.has(house.id) && connectHouseIds.size >= available) return document.getElementById('cad-command').textContent = `${connectCabinet.name} nema više slobodnih portova.`;
    const marker = houseMarkerByKey[pointKey(house.lat, house.lng)];
    if (connectHouseIds.has(house.id)) {
        connectHouseIds.delete(house.id);
        marker?.setIcon(icon('house', '', savedHouseColorByKey[pointKey(house.lat, house.lng)] || null));
    } else {
        connectHouseIds.add(house.id);
        marker?.setIcon(icon('house', '', '#a855f7'));
    }
    document.getElementById('cad-command').textContent = `CONNECT HOUSES: ${connectCabinet.name} | odabrano ${connectHouseIds.size}/${available}`;
}
function resetHouseConnect() {
    connectHouseIds.forEach(id => {
        const house = data.houses.find(item => Number(item.id) === Number(id));
        if (house) houseMarkerByKey[pointKey(house.lat, house.lng)]?.setIcon(icon('house', '', savedHouseColorByKey[pointKey(house.lat, house.lng)] || null));
    });
    connectCabinet = null;
    connectHouseIds = new Set();
    document.getElementById('house-connect-actions')?.classList.add('hidden');
    document.getElementById('house-connect-actions')?.classList.remove('flex');
}
async function finishHouseConnect() {
    if (!connectCabinet || !connectHouseIds.size) return document.getElementById('cad-command').textContent = 'CONNECT HOUSES: odaberi ODO i najmanje jednu kuću.';
    const cabinet = connectCabinet, houseIds = [...connectHouseIds];
    const response = await fetch(`{{ url('/ormarici') }}/${cabinet.id}/povezi-kuce`, {
        method: 'POST',
        headers: { 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '', 'Accept': 'application/json', 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
        body: JSON.stringify({ house_ids: houseIds }),
    });
    const result = await readJsonResponse(response, 'ODO-kuće povezivanje nije uspjelo.');
    result.routes.forEach(route => addSavedRouteToMap({ ...route, type: 'drop', length: route.duct_length_m }));
    data.houses.filter(house => houseIds.includes(house.id)).forEach(house => { house.cabinet_id = cabinet.id; house.cabinet = cabinet.name; });
    cabinet.used_ports = Number(cabinet.used_ports || 0) + result.routes.length;
    resetHouseConnect();
    document.getElementById('cad-command').textContent = `CONNECT HOUSES: kreirano ${result.routes.length} drop veza za ${cabinet.name}`;
}
async function connectSelectedOdfToCabinet(cabinet) {
    if (!connectOdf) {
        document.getElementById('cad-command').textContent = 'CONNECT: prvo odaberi ODF';
        return;
    }
    if (Number(connectOdf.project_id) !== Number(cabinet.project_id)) {
        document.getElementById('cad-command').textContent = 'CONNECT: ODF i ODO moraju pripadati istom projektu.';
        return;
    }
    const points = [L.latLng(connectOdf.lat, connectOdf.lng), L.latLng(cabinet.lat, cabinet.lng)];
    const length = distance(points);
    const payload = {
        project_id: connectOdf.project_id,
        odf_id: connectOdf.id,
        cabinet_id: cabinet.id,
        from_type: 'odf',
        from_id: connectOdf.id,
        to_type: 'cabinet',
        to_id: cabinet.id,
        name: `${connectOdf.name} - ${cabinet.name}`,
        route_type: 'backbone',
        installation_type: 'underground',
        duct_length_m: length,
        fiber_length_m: length,
        fiber_count: 24,
        microduct_type: '14/10',
        microduct_count: 1,
        status: 'planned',
        path: JSON.stringify(points.map(point => [Number(point.lat.toFixed(7)), Number(point.lng.toFixed(7))])),
    };
    document.getElementById('cad-command').textContent = `CONNECT: kreiram vezu ${connectOdf.name} → ${cabinet.name}`;
    try {
        const response = await fetch(`{{ route('routes.store') }}`, {
            method: 'POST',
            headers: {
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '',
                'Accept': 'application/json',
                'Content-Type': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
            },
            body: JSON.stringify(payload),
        });
        const result = await readJsonResponse(response, 'ODF-ODO veza nije kreirana.');
        addSavedRouteToMap({
            ...result.route,
            project_id: connectOdf.project_id,
            project: connectOdf.project,
            cabinet_id: cabinet.id,
            installation_type: 'underground',
            fiber_count: 24,
            duct_length_m: result.route.length,
            fiber_length_m: result.route.length,
        });
        document.getElementById('cad-command').textContent = `CONNECT: kreirana veza ${connectOdf.name} → ${cabinet.name}`;
        connectOdf = null;
    } catch (error) {
        document.getElementById('cad-command').textContent = `CONNECT: ${error.message}`;
    }
}
function addSavedRouteToMap(route) {
    const points = route.path.map(point => L.latLng(point[0], point[1]));
    const line = L.polyline(points, { color: routeColor(route.type), weight: 4, opacity: .9 })
        .bindPopup(`<b>${route.name}</b><br>${routeTypeLabel(route.type)}<br>${route.length} m`)
        .addTo(map);
    const labels = addRouteLabel(points, route.name, false);
    data.routes.push(route);
    savedRoutePoints.push(points);
    routeLayerById[route.id] = line;
    routeLabelsById[route.id] = labels || [];
    trackLayer(line, routeLayerType(route.type));
    labels?.forEach(label => trackLayer(label, routeLayerType(route.type)));
    registerSavedContext([line, ...labels], route.name, deleteUrls.route(route.id), null, event => {
        if (mode === 'join') selectJoinRoute(route, line);
        else if (routeEdit?.route.id === route.id) addRouteEditVertex(event.latlng);
        else startRouteEdit(route, line);
    });
}
function resetJoinRoutes() {
    joinRoutes.forEach(item => item.line.setStyle({ color: item.route.cabinet_id ? cabinetColor(item.route.cabinet_id) : routeColor(item.route.type), weight: 4, opacity: .9 }));
    joinRoutes = [];
}
function selectJoinRoute(route, line) {
    const selectedIndex = joinRoutes.findIndex(item => Number(item.route.id) === Number(route.id));
    if (selectedIndex >= 0) {
        joinRoutes.splice(selectedIndex, 1);
        line.setStyle({ color: route.cabinet_id ? cabinetColor(route.cabinet_id) : routeColor(route.type), weight: 4, opacity: .9 });
        document.getElementById('cad-command').textContent = `JOIN: označeno ${joinRoutes.length} trasa. ENTER spaja.`;
        return;
    }
    joinRoutes.push({ route, line });
    line.setStyle({ color: joinRoutes.length === 1 ? '#e11d48' : '#fb7185', weight: 7, opacity: 1 });
    document.getElementById('cad-command').textContent = `JOIN: označeno ${joinRoutes.length} trasa. Glavna: ${joinRoutes[0].route.name}. ENTER spaja.`;
}
async function joinSelectedRoutes() {
    if (joinRoutes.length < 2) {
        document.getElementById('cad-command').textContent = 'JOIN: označi najmanje dvije trase, zatim pritisni ENTER.';
        return;
    }
    const first = joinRoutes[0];
    const others = joinRoutes.slice(1);
    for (const item of others) {
      try {
        const response = await fetch(`{{ url('/trase') }}/${first.route.id}/join/${item.route.id}`, {
            method: 'POST',
            headers: { 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '', 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
        });
        const result = await readJsonResponse(response, 'Trase nisu spojene.');
        const points = result.route.path.map(point => L.latLng(point[0], point[1]));
        first.line.setLatLngs(points);
        first.route.path = result.route.path;
        first.route.length = result.route.length;
        first.route.duct_length_m = result.route.length;
        first.line.setPopupContent(`<b>${first.route.name}</b><br>${routeTypeLabel(first.route.type)}<br>${result.route.length} m`);
        (routeLabelsById[first.route.id] || []).forEach(label => {
            map.removeLayer(label);
            untrackLayer(label);
        });
        routeLabelsById[first.route.id] = addRouteLabel(points, first.route.name, false);
        routeLabelsById[first.route.id].forEach(label => trackLayer(label, routeLayerType(first.route.type)));
        const removedLine = routeLayerById[result.deleted_route_id];
        if (removedLine) map.removeLayer(removedLine);
        (routeLabelsById[result.deleted_route_id] || []).forEach(label => {
            map.removeLayer(label);
            untrackLayer(label);
        });
        delete routeLayerById[result.deleted_route_id];
        delete routeLabelsById[result.deleted_route_id];
        data.routes = data.routes.filter(item => Number(item.id) !== Number(result.deleted_route_id));
        const firstIndex = data.routes.findIndex(item => Number(item.id) === Number(first.route.id));
        if (firstIndex >= 0) data.routes[firstIndex] = first.route;
      } catch (error) {
        document.getElementById('cad-command').textContent = `JOIN: ${error.message}`;
        resetJoinRoutes();
        return;
      }
    }
    removeDraftBranchesOnPath(first.line.getLatLngs());
    document.getElementById('cad-command').textContent = `JOIN: spojeno ${others.length + 1} trasa u ${first.route.name} (${first.route.length} m)`;
    resetJoinRoutes();
}
function removeDraftBranchesOnPath(joinedPath) {
    const matchesJoinedPath = branch => branch.length > 1 && branch.every(point => {
        let nearest = Infinity;
        for (let index = 1; index < joinedPath.length; index++) {
            nearest = Math.min(nearest, map.distance(point, projectOnSegment(point, joinedPath[index - 1], joinedPath[index])));
        }
        return nearest <= 3;
    });
    branches.map((branch, index) => matchesJoinedPath(branch) ? index : -1)
        .filter(index => index >= 0)
        .reverse()
        .forEach(removeBranchAt);
}
function currentRouteDraftMeta() {
    const type = document.getElementById('route-draw-type').value;
    const manualName = document.getElementById('route-draw-name').value.trim();
    const odf = activeOdfPayload();
    const startSource = document.getElementById('route-start-source')?.value || '';
    const [fromType, fromId] = startSource ? startSource.split(':') : [null, null];
    const sourceCabinet = fromType === 'cabinet' ? data.cabinets.find(cabinet => Number(cabinet.id) === Number(fromId)) : null;
    const isTrench = type === 'trench';
    return {
        name: manualName || nextRouteName(type),
        route_type: type,
        installation_type: document.getElementById('route-draw-installation').value,
        counts_as_trench: isTrench,
        trench_length_m: null,
        microduct_type: isTrench ? null : document.getElementById('route-draw-microduct-type').value,
        fiber_count: isTrench ? 4 : Number(document.getElementById('route-draw-fiber-count').value || 12),
        microduct_count: isTrench ? 0 : Math.max(1, Number(document.getElementById('route-draw-microducts').value || 1)),
        odf_index: fromType === 'odf' ? null : odf.odf_index,
        odf_id: fromType === 'odf' ? Number(fromId) : (sourceCabinet?.odf_id || odf.odf_id),
        from_type: fromType || null,
        from_id: fromId ? Number(fromId) : null,
    };
}
function nextCabinetBranchName(cabinet) {
    const numberParts = String(cabinet.name || '').match(/\d+/g) || [cabinet.id];
    const base = numberParts.join('-');
    const prefix = `Sekundarni krak ${base}.`;
    const used = [...data.routes, ...branchMeta]
        .map(route => String(route.name || ''))
        .filter(name => name.startsWith(prefix))
        .map(name => Number(name.slice(prefix.length)))
        .filter(Number.isFinite);
    return `${prefix}${Math.max(0, ...used) + 1}`;
}
function startBranchFromCabinet(cabinet) {
    cancelActiveDrawing();
    quickBranchWorkflow = true;
    document.getElementById('route-start-source').value = `cabinet:${cabinet.id}`;
    document.getElementById('route-draw-type').value = 'distribution';
    document.getElementById('route-draw-name').value = nextCabinetBranchName(cabinet);
    setMode('draw');
    addDrawPoint(cabinetMarkerById[cabinet.id]?.getLatLng() || L.latLng(cabinet.lat, cabinet.lng));
    document.getElementById('cad-command').textContent = `NOVI KRAK: polazi iz ${cabinet.name}. Klikni dalje po trasi, ENTER završava krak.`;
}
function trackLayer(layer, type) {
    if (layerRegistry[type]) layerRegistry[type].push(layer);
    layer._ftthLayerType = type;
    applyLayerVisibility(type);
    updateLayerCount(type);
    return layer;
}
function untrackLayer(layer, type = null) {
    Object.entries(layerRegistry).forEach(([key, layers]) => {
        if (type && key !== type) return;
        const index = layers.indexOf(layer);
        if (index >= 0) {
            layers.splice(index, 1);
            updateLayerCount(key);
        }
    });
}
function layerVisible(type) {
    return document.querySelector(`[data-layer-toggle="${type}"]`)?.checked !== false;
}
function applyLayerVisibility(type) {
    (layerRegistry[type] || []).forEach(layer => {
        if (layerVisible(type)) {
            if (!map.hasLayer(layer)) layer.addTo(map);
        } else if (map.hasLayer(layer)) {
            map.removeLayer(layer);
        }
    });
}
function updateCommandBar(snap = '-') {
    if (routeEdit) {
        updateRouteEditStatus();
        return;
    }
    const metrics = document.getElementById('cad-metrics');
    if (!metrics) return;
    metrics.textContent = `Points: ${activeBranch.length} | Distance: ${distance(activeBranch)}m | Snap: ${snap || '-'} | ORTHO: ${orthoEnabled ? 'ON' : 'OFF'}`;
}
function pushUndo(action) {
    undoStack.push(action);
    redoStack.length = 0;
}
function undoLast() {
    const action = undoStack.pop();
    if (!action) return;
    action.undo?.();
    redoStack.push(action);
    updateCommandBar();
}
function redoLast() {
    const action = redoStack.pop();
    if (!action) return;
    action.redo?.();
    undoStack.push(action);
    updateCommandBar();
}
function cancelActiveDrawing() {
    quickBranchWorkflow = false;
    activeBranchMarkers.forEach(marker => map.removeLayer(marker));
    if (activeBranchLine) map.removeLayer(activeBranchLine);
    if (previewBranchLine) map.removeLayer(previewBranchLine);
    hideSnapIndicator();
    activeBranch = [];
    activeBranchSnapTargets = [];
    activeBranchMarkers = [];
    activeBranchLine = null;
    previewBranchLine = null;
    refreshStats();
    updateCommandBar();
}
function applyOrthoPoint(latlng) {
    if (!orthoEnabled || !activeBranch.length) return latlng;
    const last = activeBranch[activeBranch.length - 1];
    const latDiff = Math.abs(latlng.lat - last.lat);
    const lngDiff = Math.abs(latlng.lng - last.lng);
    return latDiff >= lngDiff ? L.latLng(latlng.lat, last.lng) : L.latLng(last.lat, latlng.lng);
}
function clearFiberTrace() {
    layerRegistry.trace.forEach(layer => map.removeLayer(layer));
    layerRegistry.trace = [];
    activeTraceHouseId = null;
    document.getElementById('map-trace-panel')?.classList.add('hidden');
    Object.entries(houseMarkerByKey).forEach(([key, marker]) => marker.setIcon(icon('house', '', savedHouseColorByKey[key] || null)));
}
function traceRouteFor(type, house, cabinet, odf) {
    if (type === 'drop') {
        return data.routes.find(route => route.type === 'drop' && route.cabinet_id === cabinet.id && Number(route.to_id) === Number(house.id) && route.path?.length)
            || data.routes.find(route => route.type === 'drop' && route.cabinet_id === cabinet.id && route.path?.length && route.path.some(point => map.distance(L.latLng(point[0], point[1]), L.latLng(house.lat, house.lng)) < 8));
    }
    return null;
}
function cabinetSupplyChain(cabinet) {
    const chain = [], visited = new Set();
    let current = cabinet;
    while (current && !visited.has(Number(current.id))) {
        chain.push(current);
        visited.add(Number(current.id));
        current = current.parent_cabinet_id
            ? data.cabinets.find(item => Number(item.id) === Number(current.parent_cabinet_id))
            : null;
    }
    return chain;
}
function supplyRouteToCabinet(cabinet, parentCabinet, odf) {
    const exact = data.routes.find(route =>
        route.type !== 'drop'
        && Number(route.cabinet_id) === Number(cabinet.id)
        && (parentCabinet
            ? route.from_type === 'cabinet' && Number(route.from_id) === Number(parentCabinet.id)
            : route.from_type === 'odf' && Number(route.from_id) === Number(odf.id))
        && route.path?.length
    );
    if (exact || !parentCabinet) return exact || null;

    const cabinetPoint = L.latLng(cabinet.lat, cabinet.lng);
    return data.routes
        .filter(route => route.type !== 'drop' && route.from_type === 'cabinet' && Number(route.from_id) === Number(parentCabinet.id) && route.path?.length)
        .map(route => {
            const start = L.latLng(route.path[0][0], route.path[0][1]);
            const end = L.latLng(route.path[route.path.length - 1][0], route.path[route.path.length - 1][1]);
            return { route, distance: Math.min(map.distance(cabinetPoint, start), map.distance(cabinetPoint, end)) };
        })
        .filter(item => item.distance <= 75)
        .sort((a, b) => a.distance - b.distance)[0]?.route || null;
}
function addTraceMarker(latlng, label, type) {
    return trackLayer(L.circleMarker(latlng, {
        radius: type === 'house' ? 10 : 12,
        color: '#f59e0b',
        weight: 3,
        fillColor: '#fef3c7',
        fillOpacity: .85,
    }).bindTooltip(label, { permanent: false }).addTo(map), 'trace');
}
function addTraceLine(points, physical = true) {
    return trackLayer(L.polyline(points, {
        color: physical ? '#f59e0b' : '#ef4444',
        weight: physical ? 6 : 3,
        opacity: .95,
        dashArray: physical ? null : '7 8',
    }).addTo(map), 'trace');
}
function routePointProjection(point, route) {
    let best = null;
    const path = (route.path || []).map(routePoint => L.latLng(routePoint[0], routePoint[1]));
    for (let i = 1; i < path.length; i++) {
        const projected = projectOnSegment(point, path[i - 1], path[i]);
        const p = map.latLngToLayerPoint(point), a = map.latLngToLayerPoint(path[i - 1]), b = map.latLngToLayerPoint(path[i]);
        const ab = b.subtract(a), ap = p.subtract(a), den = ab.x * ab.x + ab.y * ab.y;
        const t = den ? Math.max(0, Math.min(1, (ap.x * ab.x + ap.y * ab.y) / den)) : 0;
        const distance = map.distance(point, projected);
        if (!best || distance < best.distance) best = { point: projected, distance, segmentIndex: i, t };
    }
    return best;
}
function routePathBetweenPoints(fromPoint, toPoint, route) {
    const path = (route.path || []).map(routePoint => L.latLng(routePoint[0], routePoint[1]));
    if (path.length < 2) return [fromPoint, toPoint];
    const from = routePointProjection(fromPoint, route);
    const to = routePointProjection(toPoint, route);
    if (!from || !to) return [fromPoint, toPoint];
    const middle = [];
    if (from.segmentIndex <= to.segmentIndex) {
        for (let i = from.segmentIndex; i < to.segmentIndex; i++) middle.push(path[i]);
    } else {
        for (let i = from.segmentIndex - 1; i >= to.segmentIndex; i--) middle.push(path[i]);
    }
    return [fromPoint, from.point, ...middle, to.point, toPoint];
}
function traceLogicalNetworkPath(fromPoint, toPoint, spineRoute = null) {
    if (spineRoute?.path?.length) return routePathBetweenPoints(fromPoint, toPoint, spineRoute);
    return [fromPoint, toPoint];
}
function routeProjectionForNetwork(point) {
    return data.routes
        .filter(route => route.type !== 'drop' && route.path?.length)
        .map(route => ({ route, projection: routePointProjection(point, route) }))
        .filter(item => item.projection)
        .sort((a, b) => a.projection.distance - b.projection.distance)[0] || null;
}
function networkNodeKey(point) {
    return `${Number(point.lat).toFixed(7)},${Number(point.lng).toFixed(7)}`;
}
function addNetworkEdge(graph, a, b) {
    const ak = networkNodeKey(a), bk = networkNodeKey(b), weight = map.distance(a, b);
    graph.nodes[ak] = a;
    graph.nodes[bk] = b;
    graph.edges[ak] ??= [];
    graph.edges[bk] ??= [];
    graph.edges[ak].push({ key: bk, weight });
    graph.edges[bk].push({ key: ak, weight });
}
function buildTraceNetworkGraph(fromPoint, toPoint) {
    const fromProjection = routeProjectionForNetwork(fromPoint);
    const toProjection = routeProjectionForNetwork(toPoint);
    const graph = { nodes: {}, edges: {}, startKey: networkNodeKey(fromPoint), endKey: networkNodeKey(toPoint) };
    const insertsByRoute = {};
    if (fromProjection) {
        insertsByRoute[fromProjection.route.id] ??= [];
        insertsByRoute[fromProjection.route.id].push({ ...fromProjection.projection, sourcePoint: fromPoint, sourceKey: graph.startKey });
    }
    if (toProjection) {
        insertsByRoute[toProjection.route.id] ??= [];
        insertsByRoute[toProjection.route.id].push({ ...toProjection.projection, sourcePoint: toPoint, sourceKey: graph.endKey });
    }
    data.routes.filter(route => route.type !== 'drop' && route.path?.length).forEach(route => {
        const path = route.path.map(point => L.latLng(point[0], point[1]));
        for (let i = 1; i < path.length; i++) {
            const segmentPoints = [
                { point: path[i - 1], t: 0 },
                { point: path[i], t: 1 },
                ...(insertsByRoute[route.id] || [])
                    .filter(insert => insert.segmentIndex === i)
                    .map(insert => ({ point: insert.point, t: insert.t ?? 0.5, sourcePoint: insert.sourcePoint, sourceKey: insert.sourceKey })),
            ].sort((a, b) => a.t - b.t);
            for (let j = 1; j < segmentPoints.length; j++) addNetworkEdge(graph, segmentPoints[j - 1].point, segmentPoints[j].point);
            segmentPoints.filter(item => item.sourcePoint).forEach(item => addNetworkEdge(graph, item.sourcePoint, item.point));
        }
    });
    return graph;
}
function shortestTraceNetworkPath(fromPoint, toPoint) {
    const graph = buildTraceNetworkGraph(fromPoint, toPoint);
    const distances = { [graph.startKey]: 0 };
    const previous = {};
    const queue = new Set(Object.keys(graph.nodes));
    queue.add(graph.startKey);
    queue.add(graph.endKey);
    while (queue.size) {
        const current = [...queue].sort((a, b) => (distances[a] ?? Infinity) - (distances[b] ?? Infinity))[0];
        if (!current || (distances[current] ?? Infinity) === Infinity) break;
        queue.delete(current);
        if (current === graph.endKey) break;
        (graph.edges[current] || []).forEach(edge => {
            const nextDistance = distances[current] + edge.weight;
            if (nextDistance < (distances[edge.key] ?? Infinity)) {
                distances[edge.key] = nextDistance;
                previous[edge.key] = current;
                queue.add(edge.key);
            }
        });
    }
    if (!previous[graph.endKey]) return null;
    const keys = [];
    for (let key = graph.endKey; key; key = previous[key]) {
        keys.unshift(key);
        if (key === graph.startKey) break;
    }
    return keys.map(key => graph.nodes[key] || L.latLng(...key.split(',').map(Number)));
}
function showFiberTrace(houseId) {
    clearFiberTrace();
    const house = data.houses.find(item => Number(item.id) === Number(houseId));
    if (!house) return;
    activeTraceHouseId = house.id;
    const cabinet = data.cabinets.find(item => Number(item.id) === Number(house.cabinet_id));
    const odf = cabinet ? data.odfs.find(item => Number(item.id) === Number(cabinet.odf_id)) : null;
    const panel = document.getElementById('map-trace-panel');
    const output = document.getElementById('map-trace-output');
    panel?.classList.remove('hidden');

    if (!cabinet || !odf) {
        output.innerHTML = `<div class="rounded-md bg-red-50 p-3 text-red-700">Kuca ${house.label} nema kompletnu vezu do FTTH ormarica i ODF-a.</div>`;
        return;
    }

    const housePoint = L.latLng(house.lat, house.lng);
    const cabinetPoint = L.latLng(cabinet.lat, cabinet.lng);
    const odfPoint = L.latLng(odf.lat, odf.lng);
    const houseMarker = houseMarkerByKey[pointKey(house.lat, house.lng)];
    houseMarker?.setIcon(icon('house', '', '#f59e0b'));
    addTraceMarker(housePoint, house.label, 'house');
    addTraceMarker(odfPoint, odf.name, 'odf');

    const physical = [];
    const missing = [];
    const supplyChain = cabinetSupplyChain(cabinet);
    supplyChain.forEach(item => addTraceMarker(L.latLng(item.lat, item.lng), item.name, 'cabinet'));
    for (let index = 0; index < supplyChain.length; index++) {
        const child = supplyChain[index];
        const parent = supplyChain[index + 1] || null;
        const fromPoint = parent ? L.latLng(parent.lat, parent.lng) : odfPoint;
        const toPoint = L.latLng(child.lat, child.lng);
        const supplyRoute = supplyRouteToCabinet(child, parent, odf);
        if (supplyRoute?.path?.length > 1) {
            physical.push(addTraceLine(supplyRoute.path.map(point => L.latLng(point[0], point[1])), true));
        } else {
            const networkPath = shortestTraceNetworkPath(fromPoint, toPoint);
            if (networkPath?.length > 1) {
                physical.push(addTraceLine(networkPath, true));
            } else {
                missing.push(parent ? `${parent.name} -> ${child.name}` : `${odf.name} -> ${child.name}`);
                physical.push(addTraceLine([fromPoint, toPoint], false));
            }
        }
    }
    const cabinetToHousePath = shortestTraceNetworkPath(cabinetPoint, housePoint);
    const dropRoute = traceRouteFor('drop', house, cabinet, odf);
    if (dropRoute?.path?.length && dropRoute.path.length > 2) {
        physical.push(addTraceLine(dropRoute.path.map(point => L.latLng(point[0], point[1])), true));
    } else if (cabinetToHousePath?.length > 1) {
        missing.push('FTTH -> kuca');
        physical.push(addTraceLine(cabinetToHousePath, false));
    } else {
        missing.push('FTTH -> kuca');
        physical.push(addTraceLine([cabinetPoint, housePoint], false));
    }

    const warning = missing.length ? `<div class="rounded-md bg-amber-50 p-2 text-xs font-semibold text-amber-800">Nema nacrtane fizicke trase za ovu vezu. Prikazana je logicka veza koja prati postojecu trasu/rov gdje god je moguce. (${missing.join(', ')})</div>` : '';
    output.innerHTML = `
        <div class="rounded-md bg-white p-2"><b>${house.label}</b><br>Kuca</div>
        <div class="text-center font-black text-slate-500">↓</div>
        ${supplyChain.map(item => `<div class="rounded-md bg-white p-2"><b>${item.name}</b><br>FTTH ormaric</div>`).join('<div class="text-center font-black text-slate-500">↓</div>')}
        <div class="text-center font-black text-slate-500">↓</div>
        <div class="rounded-md bg-white p-2"><b>${odf.name}</b><br>ODF</div>
        ${warning}
    `;
    const bounds = L.latLngBounds([housePoint, odfPoint, ...supplyChain.map(item => L.latLng(item.lat, item.lng))]);
    physical.forEach(line => line.getLatLngs().forEach(point => bounds.extend(point)));
    map.fitBounds(bounds, { padding: [70, 70], maxZoom: 19 });
}
function renderBranchList() {
    document.getElementById('route-branch-count').textContent = `${branchMeta.length} krakova`;
    document.getElementById('route-branch-list').innerHTML = branchMeta.length
        ? branchMeta.map(meta => {
            const odfLabel = meta.odf_index === null || meta.odf_index === undefined ? 'bez ODF' : `ODF-${String(meta.odf_index + 1).padStart(2, '0')}`;
            return `<div class="flex items-center justify-between rounded bg-white/80 px-2 py-1"><span>${meta.name} · ${routeTypeLabel(meta.route_type)} · ${odfLabel}</span><strong>${meta.duct_length_m} m</strong></div>`;
        }).join('')
        : '<div class="rounded bg-white/70 px-2 py-2 text-amber-800">Nema nacrtanih krakova.</div>';
}
function refreshRouteOdfStatus() {
    const status = document.getElementById('route-odf-status');
    status.textContent = activeDraftOdfIndex === null
        ? 'Krak nije vezan na ODF. Odaberi/postavi aktivni ODF prije crtanja.'
        : `Novi krakovi se vežu na ODF-${String(activeDraftOdfIndex + 1).padStart(2, '0')}.`;
}
function refreshTrenchGroupStatus() {
    const input = document.getElementById('route-draw-type');
    const status = document.getElementById('route-trench-status');
    if (!input || !status) return;
    const isTrench = input.value === 'trench';
    status.textContent = isTrench ? 'crtanje rova' : 'crtanje mikrocijevi';
    status.className = isTrench
        ? 'rounded bg-emerald-600 px-2 py-1 text-xs font-bold text-white'
        : 'rounded bg-white px-2 py-1 text-xs font-bold text-amber-800';
    ['route-draw-microducts', 'route-draw-microduct-type', 'route-draw-fiber-count'].forEach(id => {
        const field = document.getElementById(id);
        if (field) field.disabled = isTrench;
    });
}
function routeLabelPoint(points, position = .5) {
    if (!points.length) return null;
    if (points.length === 1) return points[0];
    const total = distance(points);
    const target = total * position;
    let walked = 0;
    for (let i = 1; i < points.length; i++) {
        const segment = map.distance(points[i - 1], points[i]);
        if (walked + segment >= target) {
            const ratio = segment ? (target - walked) / segment : 0;
            return L.latLng(
                points[i - 1].lat + (points[i].lat - points[i - 1].lat) * ratio,
                points[i - 1].lng + (points[i].lng - points[i - 1].lng) * ratio
            );
        }
        walked += segment;
    }
    return points[Math.floor(points.length / 2)];
}
function addRouteLabel(points, name, track = true) {
    const markers = [];
    [.08, .5, .92].forEach(position => {
        const labelPoint = routeLabelPoint(points, position);
        if (!labelPoint) return;
        const marker = L.marker(labelPoint, {
            interactive: false,
            keyboard: false,
            icon: L.divIcon({
                className: 'route-label',
                html: `<span>${name}</span>`,
                iconAnchor: [16, 8],
        }),
    }).addTo(map);
        if (track) branchLabels.push(marker);
        markers.push(marker);
    });
    return markers;
}
function showCadContext(latlng, title, actions) {
    cadContext = actions;
    const buttons = actions.map((action, index) => `<button type="button" data-cad-action="${index}" class="block w-full rounded px-2 py-1 text-left text-[10px] font-semibold leading-tight hover:bg-zinc-100">${action.label}</button>`).join('');
    L.popup({ closeButton: true, minWidth: 82, maxWidth: 110, className: 'cad-popup' })
        .setLatLng(latlng)
        .setContent(`<div class="w-[92px]"><div class="border-b border-zinc-200 px-2 py-1 text-[10px] font-bold leading-tight">${title}</div><div class="p-0.5">${buttons}</div></div>`)
        .openOn(map);
}
map.on('popupopen', event => {
    event.popup.getElement()?.querySelectorAll('[data-cad-action]').forEach(button => {
        button.addEventListener('click', () => {
            const action = cadContext?.[Number(button.dataset.cadAction)];
            if (action) action.run();
            map.closePopup();
        });
    });
});
function removeDraftElement(marker) {
    const item = draftElements.find(entry => entry.marker === marker);
    if (!item) return;
    if (selectedDraftElement?.item.marker === marker) closeDraftElementEditor();
    const appendixItem = draftAppendixItems.find(entry => entry.marker === marker);
    if (appendixItem) removeAppendixDraftItem(appendixItem);
    else map.removeLayer(marker);
    draftElements = draftElements.filter(entry => entry.marker !== marker);
    if (item.type === 'odf') {
        const removedIndex = draftOdfs.findIndex(entry => entry.marker === marker);
        draftOdfs = draftOdfs.filter(entry => entry.marker !== marker);
        draftCabinets.forEach(cabinet => {
            if (cabinet.odf_index === removedIndex) cabinet.odf_index = null;
            if (cabinet.odf_index > removedIndex) cabinet.odf_index--;
        });
        activeDraftOdfIndex = draftOdfs.length ? Math.min(activeDraftOdfIndex ?? 0, draftOdfs.length - 1) : null;
    }
    if (item.type === 'cabinet') draftCabinets = draftCabinets.filter(entry => entry.marker !== marker);
    if (item.type === 'manhole' || item.type === 'boring_fi_130') {
        draftAppendixItems = draftAppendixItems.filter(entry => entry.marker !== marker);
    }
    refreshDraftTooltips();
    refreshPlanSummary();
}
function registerDraftContext(marker, title) {
    const openMenu = event => {
        L.DomEvent.stop(event);
        showCadContext(event.latlng, title, [
            { label: 'Obrisi', run: () => removeDraftElement(marker) },
            { label: 'Pomjeri', run: () => marker.dragging?.enable() },
        ]);
    };
    marker.on('contextmenu', openMenu);
    marker.on('click', event => {
        if (mode === 'pan') openMenu(event);
    });
}
function removeDraftHouse(marker) {
    const index = houseMarkers.indexOf(marker);
    if (index < 0) return;
    map.removeLayer(marker);
    houseMarkers.splice(index, 1);
    housePoints.splice(savedHouseCount + index, 1);
    refreshStats();
}
function registerHouseContext(marker) {
    const openMenu = event => {
        L.DomEvent.stop(event);
        showCadContext(event.latlng, 'Kuca', [
            { label: 'Obrisi', run: () => removeDraftHouse(marker) },
            { label: 'Pomjeri', run: () => marker.dragging?.enable() },
        ]);
    };
    marker.on('contextmenu', openMenu);
    marker.on('click', event => {
        if (mode === 'pan') openMenu(event);
    });
}
function removeBranchAt(index) {
    const line = branchLines[index];
    if (line) map.removeLayer(line);
    branchLines.splice(index, 1);
    branches.splice(index, 1);
    branchMeta.splice(index, 1);
    for (let i = 0; i < 3; i++) {
        const label = branchLabels.splice(index * 3, 1)[0];
        if (label) map.removeLayer(label);
    }
    renderBranchList();
    refreshStats();
}
function registerBranchContext(line) {
    const openMenu = event => {
        L.DomEvent.stop(event);
        const index = branchLines.indexOf(line);
        if (index < 0) return;
        showCadContext(event.latlng, branchMeta[index]?.name || 'Krak trase', [
            { label: 'Obrisi krak', run: () => removeBranchAt(index) },
        ]);
    };
    line.on('contextmenu', openMenu);
    line.on('click', event => {
        if (mode === 'pan') openMenu(event);
    });
}
async function deleteSavedElement(url, layer) {
    if (!confirm('Sigurno obrisati?')) return;
    const response = await fetch(url, {
        method: 'DELETE',
        headers: {
            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '',
            'Accept': 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
        },
    });

    if (!response.ok) {
        alert(await response.text() || 'Brisanje nije uspjelo.');
        return;
    }

    (Array.isArray(layer) ? layer : [layer]).filter(Boolean).forEach(item => map.removeLayer(item));
    document.getElementById('cad-command').textContent = 'Element je obrisan.';
}
async function saveSavedPosition(marker, url) {
    const position = marker.getLatLng();
    const response = await fetch(url, {
        method: 'PATCH',
        headers: {
            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '',
            'Accept': 'application/json',
            'Content-Type': 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
        },
        body: JSON.stringify({ latitude: position.lat, longitude: position.lng }),
    });

    if (!response.ok) throw new Error(await response.text() || 'Pomjeranje nije sačuvano.');
    marker.dragging?.disable();
    document.getElementById('cad-command').textContent = 'Nova pozicija je sačuvana.';
}
function registerSavedContext(layer, title, url, positionUrl = null, clickAction = null, customActions = []) {
    const triggerLayer = Array.isArray(layer) ? layer[0] : layer;
    let savedPosition = triggerLayer.getLatLng?.();
    if (positionUrl) {
        triggerLayer.on('dragend', async () => {
            try {
                await saveSavedPosition(triggerLayer, positionUrl);
                savedPosition = triggerLayer.getLatLng();
            } catch (error) {
                if (savedPosition) triggerLayer.setLatLng(savedPosition);
                triggerLayer.dragging?.disable();
                alert(error.message);
            }
        });
    }
    const openMenu = event => {
        L.DomEvent.stop(event);
        if (layerLocked(triggerLayer._ftthLayerType)) {
            document.getElementById('cad-command').textContent = `Layer ${triggerLayer._ftthLayerType} je zaključan.`;
            return;
        }
        const actions = [
            ...customActions,
            { label: 'Obrisi', run: () => deleteSavedElement(url, layer) },
        ];
        if (positionUrl) actions.push({ label: 'Pomjeri', run: () => triggerLayer.dragging?.enable() });
        showCadContext(event.latlng, title, actions);
    };
    triggerLayer.on('contextmenu', openMenu);
    triggerLayer.on('click', event => {
        if (!['pan', 'join'].includes(mode)) return;
        if (layerLocked(triggerLayer._ftthLayerType)) {
            document.getElementById('cad-command').textContent = `Layer ${triggerLayer._ftthLayerType} je zaključan.`;
            return;
        }
        if (clickAction) {
            L.DomEvent.stop(event);
            triggerLayer.closePopup?.();
            clickAction(event);
        } else {
            openMenu(event);
        }
    });
}
function routeLayerType(type) {
    return ['backbone', 'drop'].includes(type) ? type : 'distribution';
}
function layerLocked(type) {
    return Boolean(layerLocks[type]);
}
function updateLayerCount(type) {
    const count = document.querySelector(`[data-layer-count="${type}"]`);
    if (!count) return;
    const objectCounts = {
        odf: () => data.odfs.length + draftOdfs.length,
        odo: () => data.cabinets.length + draftCabinets.length,
        houses: () => data.houses.length + Math.max(houseMarkers.length - data.houses.length, 0),
        backbone: () => data.routes.filter(route => route.type === 'backbone').length + branchMeta.filter(route => route.route_type === 'backbone').length,
        distribution: () => data.routes.filter(route => !['backbone', 'drop'].includes(route.type)).length + branchMeta.filter(route => !['backbone', 'drop'].includes(route.route_type)).length,
        drop: () => data.routes.filter(route => route.type === 'drop').length,
        dxf: () => 0,
    };
    count.textContent = objectCounts[type] ? objectCounts[type]() : (layerRegistry[type]?.length || 0);
}
function routeEditVertexIcon() {
    return L.divIcon({
        className: 'ftth-label',
        html: '<div style="width:14px;height:14px;border-radius:3px;background:#2563eb;border:2px solid #fff;box-shadow:0 1px 5px rgba(0,0,0,.45)"></div>',
        iconAnchor: [7, 7],
    });
}
function startRouteEdit(route, line) {
    if (routeEdit?.route.id === route.id) return;
    cancelRouteEdit();
    const points = line.getLatLngs().map(point => L.latLng(point.lat, point.lng));
    routeEdit = { route, line, originalPoints: points.map(point => L.latLng(point.lat, point.lng)), points, markers: [] };
    line.setStyle({ color: '#2563eb', weight: 6, opacity: 1 });
    document.getElementById('route-edit-actions').classList.remove('hidden');
    document.getElementById('route-edit-actions').classList.add('flex');
    renderRouteEditVertices();
}
function renderRouteEditVertices() {
    if (!routeEdit) return;
    routeEdit.markers.forEach(marker => map.removeLayer(marker));
    routeEdit.markers = routeEdit.points.map((point, index) => {
        const marker = L.marker(point, { draggable: true, icon: routeEditVertexIcon(), zIndexOffset: 1000 }).addTo(map);
        marker.on('drag', event => {
            routeEdit.points[index] = event.target.getLatLng();
            updateRouteEditLine();
        });
        marker.on('contextmenu', event => {
            L.DomEvent.stop(event);
            if (routeEdit.points.length <= 2) {
                document.getElementById('cad-command').textContent = 'EDIT ROUTE: trasa mora imati najmanje 2 tačke.';
                return;
            }
            routeEdit.points.splice(index, 1);
            updateRouteEditLine();
            renderRouteEditVertices();
        });
        return marker;
    });
    updateRouteEditStatus();
}
function updateRouteEditLine() {
    if (!routeEdit) return;
    routeEdit.line.setLatLngs(routeEdit.points);
    updateRouteEditStatus();
}
function updateRouteEditStatus() {
    if (!routeEdit) return;
    const length = distance(routeEdit.points);
    document.getElementById('cad-command').textContent = `EDIT ROUTE: ${routeEdit.route.name} | Points: ${routeEdit.points.length} | Length: ${length} m`;
    document.getElementById('cad-metrics').textContent = `EDIT ROUTE: ${routeEdit.route.name} | Points: ${routeEdit.points.length} | Length: ${length} m`;
}
function nearestRouteEditSegment(latlng) {
    if (!routeEdit || routeEdit.points.length < 2) return null;
    let best = null;
    for (let index = 1; index < routeEdit.points.length; index++) {
        const point = projectOnSegment(latlng, routeEdit.points[index - 1], routeEdit.points[index]);
        const distance = layerPixelDistance(latlng, point);
        if (!best || distance < best.distance) best = { point, distance, insertAt: index };
    }
    return best;
}
function addRouteEditVertex(latlng = null) {
    if (!routeEdit) return;
    let target = latlng ? nearestRouteEditSegment(latlng) : null;
    if (!target) {
        let longest = null;
        for (let index = 1; index < routeEdit.points.length; index++) {
            const segmentLength = map.distance(routeEdit.points[index - 1], routeEdit.points[index]);
            if (!longest || segmentLength > longest.segmentLength) longest = { index, segmentLength };
        }
        if (!longest) return;
        const a = routeEdit.points[longest.index - 1], b = routeEdit.points[longest.index];
        target = { insertAt: longest.index, point: L.latLng((a.lat + b.lat) / 2, (a.lng + b.lng) / 2) };
    }
    routeEdit.points.splice(target.insertAt, 0, target.point);
    updateRouteEditLine();
    renderRouteEditVertices();
}
function cancelRouteEdit() {
    if (!routeEdit) return;
    routeEdit.line.setLatLngs(routeEdit.originalPoints);
    routeEdit.line.setStyle({ color: routeEdit.route.cabinet_id ? cabinetColor(routeEdit.route.cabinet_id) : routeColor(routeEdit.route.type), weight: 4, opacity: .9 });
    routeEdit.markers.forEach(marker => map.removeLayer(marker));
    routeEdit = null;
    document.getElementById('route-edit-actions').classList.add('hidden');
    document.getElementById('route-edit-actions').classList.remove('flex');
    updateCommandBar();
}
async function saveRouteEdit() {
    if (!routeEdit) return;
    if (routeEdit.points.length < 2) {
        document.getElementById('cad-command').textContent = 'EDIT ROUTE: nije moguće snimiti trasu sa manje od 2 tačke.';
        return;
    }
    const path = routeEdit.points.map(point => [Number(point.lat.toFixed(7)), Number(point.lng.toFixed(7))]);
    const length = distance(routeEdit.points);
    const response = await fetch(`{{ url('/trase') }}/${routeEdit.route.id}/geometrija`, {
        method: 'PATCH',
        headers: {
            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '',
            'Accept': 'application/json',
            'Content-Type': 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
        },
        body: JSON.stringify({ path, duct_length_m: length, fiber_length_m: length }),
    });
    const result = await readJsonResponse(response, 'Izmjene trase nisu sačuvane.');
    const edited = routeEdit;
    edited.route.path = result.route.path;
    edited.route.duct_length_m = result.route.length;
    const savedRouteIndex = data.routes.filter(route => route.path?.length).findIndex(route => route.id === edited.route.id);
    if (savedRouteIndex >= 0) savedRoutePoints[savedRouteIndex] = edited.points.map(point => L.latLng(point.lat, point.lng));
    edited.originalPoints = edited.points.map(point => L.latLng(point.lat, point.lng));
    edited.line.setPopupContent(`<b>${edited.route.name}</b><br>${routeTypeLabel(edited.route.type)}<br>${result.route.length} m`);
    edited.line.setStyle({ color: edited.route.cabinet_id ? cabinetColor(edited.route.cabinet_id) : routeColor(edited.route.type), weight: 4, opacity: .9 });
    edited.markers.forEach(marker => map.removeLayer(marker));
    routeEdit = null;
    document.getElementById('route-edit-actions').classList.add('hidden');
    document.getElementById('route-edit-actions').classList.remove('flex');
    document.getElementById('cad-command').textContent = `Trasa ${edited.route.name} je sačuvana (${result.route.length} m).`;
    updateCommandBar();
}
function refreshStats() {
    const d = allDistance();
    document.getElementById('draw-length').textContent = `${d} m`;
    document.getElementById('route-duct').value = d;
    document.getElementById('route-fiber').value = d;
    document.getElementById('route-path').value = JSON.stringify(draftNetworkPoints()[0]?.map(p => [Number(p.lat.toFixed(7)), Number(p.lng.toFixed(7))]) || []);
    document.getElementById('house-count').textContent = Math.max(housePoints.length - savedHouseCount, 0);
    refreshPlanSummary();
}
function syncRoutePathInput() {
    const merged = draftNetworkPoints().flatMap((branch, index) => {
        const points = branch.map(p => [Number(p.lat.toFixed(7)), Number(p.lng.toFixed(7))]);
        return index === 0 ? points : [[null, null], ...points];
    }).filter(point => point[0] !== null);
    document.getElementById('route-path').value = JSON.stringify(merged);
}
function redrawActiveBranch() {
    if (activeBranchLine) map.removeLayer(activeBranchLine);
    if (activeBranch.length > 1) activeBranchLine = trackLayer(L.polyline(activeBranch, { color: '#f59e0b', weight: 5, opacity: .9 }).addTo(map), 'distribution');
    refreshStats();
    syncRoutePathInput();
    updateCommandBar();
}
function redrawPreviewBranch(latlng = null) {
    if (mode !== 'draw' || !latlng) {
        if (previewBranchLine) map.removeLayer(previewBranchLine);
        previewBranchLine = null;
        hideSnapIndicator();
        return;
    }
    const orthoPoint = applyOrthoPoint(latlng);
    const snapTarget = getSnapTarget(orthoPoint);
    showSnapIndicator(snapTarget);
    updateCommandBar(snapTarget?.label || '-');
    if (!activeBranch.length) {
        if (previewBranchLine) map.removeLayer(previewBranchLine);
        previewBranchLine = null;
        if (snapTarget) document.getElementById('cad-command').textContent = `SNAP: ${snapTarget.label}. Klik postavlja prvu tačku trase.`;
        return;
    }
    const point = snapTarget?.latlng || orthoPoint;
    const points = [activeBranch[activeBranch.length - 1], point];
    if (previewBranchLine) {
        previewBranchLine.setLatLngs(points);
    } else {
        previewBranchLine = L.polyline(points, { color: '#f59e0b', weight: 3, opacity: .65, dashArray: '4 8' }).addTo(map);
    }
    if (snapTarget) document.getElementById('cad-command').textContent = `SNAP: ${snapTarget.label}. Klik potvrduje tacku, ENTER/desni klik zavrsava krak.`;
}
function addDrawPoint(latlng) {
    if (previewBranchLine) map.removeLayer(previewBranchLine);
    previewBranchLine = null;
    const orthoPoint = applyOrthoPoint(latlng);
    const snapTarget = getSnapTarget(orthoPoint);
    const point = snapTarget?.latlng || orthoPoint;
    hideSnapIndicator();
    activeBranch.push(point);
    activeBranchSnapTargets.push(snapTarget || null);
    const index = activeBranch.length - 1;
    const marker = L.marker(point, { draggable: true, icon: L.divIcon({ className: 'ftth-label', html: '<div style="width:12px;height:12px;border-radius:999px;background:#f59e0b;border:2px solid #fff;box-shadow:0 1px 5px rgba(0,0,0,.35)"></div>', iconAnchor: [6, 6] }) }).addTo(map);
    marker.on('drag', event => {
        activeBranch[index] = event.target.getLatLng();
        redrawActiveBranch();
    });
    activeBranchMarkers.push(marker);
    pushUndo({
        undo: () => {
            const removedMarker = activeBranchMarkers.pop();
            if (removedMarker) map.removeLayer(removedMarker);
            activeBranch.pop();
            activeBranchSnapTargets.pop();
            redrawActiveBranch();
        },
        redo: () => {
            activeBranch.push(point);
            activeBranchSnapTargets.push(snapTarget || null);
            activeBranchMarkers.push(marker.addTo(map));
            redrawActiveBranch();
        },
    });
    redrawActiveBranch();
    document.getElementById('cad-command').textContent = `TRASA: tacka ${activeBranch.length}${snapTarget ? ` spojena na ${snapTarget.label}` : ''}. Sljedeci klik nastavlja, ENTER/desni klik zavrsava krak.`;
}
function finishBranch() {
    const finishQuickBranch = quickBranchWorkflow;
    if (activeBranch.length > 1) {
        const meta = currentRouteDraftMeta();
        const target = activeBranchSnapTargets[activeBranchSnapTargets.length - 1];
        if (target?.type === 'cabinet' && Number(target.id) !== Number(meta.from_id)) {
            meta.to_type = 'cabinet';
            meta.to_id = Number(target.id);
            meta.cabinet_id = Number(target.id);
        }
        const meters = distance(activeBranch);
        branches.push([...activeBranch]);
        branchMeta.push({
            ...meta,
            duct_length_m: meters,
            fiber_length_m: meters,
            path: activeBranch.map(p => [Number(p.lat.toFixed(7)), Number(p.lng.toFixed(7))]),
        });
        const odfLabel = meta.odf_index === null || meta.odf_index === undefined ? 'bez ODF' : `ODF-${String(meta.odf_index + 1).padStart(2, '0')}`;
        const lineWeight = meta.route_type === 'trench' ? 6 : 4;
        const line = trackLayer(L.polyline(activeBranch, { color: routeColor(meta.route_type), weight: lineWeight }).bindPopup(`<b>${meta.name}</b><br>${routeTypeLabel(meta.route_type)}<br>${odfLabel}<br>${meters} m`).addTo(map), routeLayerType(meta.route_type));
        branchLines.push(line);
        registerBranchContext(line);
        addRouteLabel(activeBranch, meta.name);
        document.getElementById('route-draw-name').value = '';
        renderBranchList();
        document.getElementById('cad-command').textContent = `TRASA: ${meta.name} zavrsena (${meters} m). Nastavi novi krak ili promijeni alat.`;
    }
    activeBranchMarkers.forEach(m => map.removeLayer(m));
    if (activeBranchLine) map.removeLayer(activeBranchLine);
    if (previewBranchLine) map.removeLayer(previewBranchLine);
    hideSnapIndicator();
    activeBranch = []; activeBranchSnapTargets = []; activeBranchMarkers = []; activeBranchLine = null; previewBranchLine = null; refreshStats();
    if (finishQuickBranch) {
        quickBranchWorkflow = false;
        document.getElementById('route-start-source').value = '';
        setMode('pan');
    }
}
function clearDraw() { [...branchLines, ...branchLabels, ...activeBranchMarkers].forEach(l => map.removeLayer(l)); if (activeBranchLine) map.removeLayer(activeBranchLine); if (previewBranchLine) map.removeLayer(previewBranchLine); hideSnapIndicator(); branches=[]; branchLines=[]; branchLabels=[]; branchMeta=[]; activeBranch=[]; activeBranchSnapTargets=[]; activeBranchMarkers=[]; activeBranchLine=null; previewBranchLine=null; renderBranchList(); refreshStats(); }
function undoDraw() { const m = activeBranchMarkers.pop(); if (m) map.removeLayer(m); activeBranch.pop(); activeBranchSnapTargets.pop(); redrawActiveBranch(); }
function undoBranch() {
    const line = branchLines.pop();
    if (line) map.removeLayer(line);
    for (let i = 0; i < 3; i++) {
        const label = branchLabels.pop();
        if (label) map.removeLayer(label);
    }
    branches.pop();
    branchMeta.pop();
    renderBranchList();
    refreshStats();
    syncRoutePathInput();
}

function layerPixelDistance(a, b) {
    return map.latLngToLayerPoint(a).distanceTo(map.latLngToLayerPoint(b));
}
function getSnapTarget(latlng) {
    const candidates = [
        ...data.odfs.map(item => ({ latlng: odfMarkerById[item.id]?.getLatLng() || L.latLng(item.lat, item.lng), label: item.name, type:'odf', id:item.id })),
        ...data.cabinets.map(item => ({ latlng: cabinetMarkerById[item.id]?.getLatLng() || L.latLng(item.lat, item.lng), label: item.name, type:'cabinet', id:item.id })),
        ...data.houses.map(item => ({ latlng: houseMarkerByKey[pointKey(item.lat, item.lng)]?.getLatLng() || L.latLng(item.lat, item.lng), label: `Kuća ${item.label}` })),
        ...draftOdfs.map((item, index) => ({ latlng: item.marker.getLatLng(), label: item.name || `ODF-${String(index + 1).padStart(2, '0')}` })),
        ...draftCabinets.map((item, index) => ({ latlng: item.marker.getLatLng(), label: item.name || `FTTH-${String(index + 1).padStart(2, '0')}` })),
        ...houseMarkers.map((marker, index) => ({ latlng: marker.getLatLng(), label: `Kuća ${index + 1}` })),
    ];
    [...savedRoutePoints, ...branches].forEach(route => {
        route.forEach((vertex, index) => candidates.push({
            latlng: vertex,
            label: index === 0 ? 'Route start' : (index === route.length - 1 ? 'Route end' : 'Route vertex'),
        }));
    });
    let best = null;
    candidates.forEach(candidate => {
        const distance = layerPixelDistance(latlng, candidate.latlng);
        if (!best || distance < best.distance) best = { ...candidate, distance };
    });
    return best && best.distance <= snapPixelTolerance ? best : null;
}
function showSnapIndicator(target) {
    if (!target) {
        hideSnapIndicator();
        return;
    }
    if (!snapIndicator) {
        snapIndicator = L.circleMarker(target.latlng, {
            radius: 7, color: '#22c55e', weight: 2, fillColor: '#ffffff', fillOpacity: .85, interactive: false,
        }).addTo(map);
    } else {
        snapIndicator.setLatLng(target.latlng);
        if (!map.hasLayer(snapIndicator)) snapIndicator.addTo(map);
    }
}
function hideSnapIndicator() {
    if (snapIndicator && map.hasLayer(snapIndicator)) map.removeLayer(snapIndicator);
}
function projectOnSegment(point, a, b) {
    const p = map.latLngToLayerPoint(point), pa = map.latLngToLayerPoint(a), pb = map.latLngToLayerPoint(b);
    const ab = pb.subtract(pa), ap = p.subtract(pa), den = ab.x*ab.x + ab.y*ab.y;
    const t = den ? Math.max(0, Math.min(1, (ap.x*ab.x + ap.y*ab.y) / den)) : 0;
    return map.layerPointToLatLng(L.point(pa.x + ab.x*t, pa.y + ab.y*t));
}
function nearestOnNetwork(point) {
    let best = null, bestDist = Infinity, chain = 0, passed = 0, branchIndex = -1, segmentIndex = -1;
    for (const [currentBranchIndex, branch] of allNetworkPoints().entries()) {
        for (let i = 1; i < branch.length; i++) {
            const projected = projectOnSegment(point, branch[i-1], branch[i]);
            const dist = map.distance(point, projected);
            if (dist < bestDist) {
                best = projected;
                bestDist = dist;
                chain = passed + map.distance(branch[i-1], projected);
                branchIndex = currentBranchIndex;
                segmentIndex = i;
            }
            passed += map.distance(branch[i-1], branch[i]);
        }
    }
    return { point: best, chain, branchIndex, segmentIndex };
}

function networkPathBetween(aPoint, bPoint) {
    const a = nearestOnNetwork(aPoint);
    const b = nearestOnNetwork(bPoint);
    if (!a.point || !b.point) return [aPoint, bPoint];
    const branch = allNetworkPoints()[a.branchIndex];
    if (!branch || a.branchIndex !== b.branchIndex) return [aPoint, a.point, b.point, bPoint];
    const middle = [];
    if (a.segmentIndex <= b.segmentIndex) {
        for (let i = a.segmentIndex; i < b.segmentIndex; i++) middle.push(branch[i]);
        return [aPoint, a.point, ...middle, b.point, bPoint];
    }
    for (let i = a.segmentIndex - 1; i >= b.segmentIndex; i--) middle.push(branch[i]);
    return [aPoint, a.point, ...middle, b.point, bPoint];
}

function networkDropDistance(cabinetPoint, housePoint) {
    const path = networkPathBetween(cabinetPoint, housePoint);
    return path.reduce((sum, point, index) => index ? sum + map.distance(path[index - 1], point) : 0, 0);
}
function snapCabinetToRoute(point) {
    const snapped = nearestOnNetwork(point);
    return snapped.point || point;
}
function nearestOdf(point) {
    return data.odfs.map(o => ({...o, distance: Math.round(map.distance(point, L.latLng(o.lat, o.lng)))})).sort((a,b) => a.distance-b.distance)[0] || null;
}
function suggestionOdfLabel(draftOdf, odf) {
    if (draftOdf) return `ODF-${String(draftOdf.index + 1).padStart(2, '0')} (draft)`;
    if (odf) return `${odf.name} (${odf.distance} m)`;
    return 'nema';
}
function optimize(points) {
    const min = Math.max(1, Math.min(12, Number(document.getElementById('planner-min').value || 8)));
    const max = Math.max(min, Math.min(12, Number(document.getElementById('planner-max').value || 12)));
    const drop = 1;
    const houses = points.map(p => ({ point: p, chain: nearestOnNetwork(p).chain })).sort((a,b) => a.chain-b.chain);
    const dp = Array(houses.length + 1).fill(Infinity), prev = Array(houses.length + 1).fill(null); dp[0]=0;
    for (let i=0;i<houses.length;i++) for (let s=1;s<=max && i+s<=houses.length;s++) {
        const group = houses.slice(i,i+s), center = L.latLng(group.reduce((a,h)=>a+h.point.lat,0)/s, group.reduce((a,h)=>a+h.point.lng,0)/s);
        const pos = nearestOnNetwork(center).point, splitters = Math.ceil(s/4), waste = splitters*4-s;
        const totalDrop = group.reduce((a,h)=>a+map.distance(h.point,pos),0);
        const cost = 900 + splitters*160 + waste*90 + (s<min ? (min-s)*300 : 0) + totalDrop*drop;
        if (dp[i]+cost < dp[i+s]) { dp[i+s]=dp[i]+cost; prev[i+s]={i,s,pos,splitters,waste,totalDrop,group}; }
    }
    const groups=[]; for(let c=houses.length;c>0 && prev[c];c=prev[c].i) groups.unshift(prev[c]); return groups;
}

function assignHousesToNearestCabinets(groups, points) {
    const max = Math.max(1, Math.min(12, Number(document.getElementById('planner-max').value || 12)));
    const maxDrop = Math.max(20, Number(document.getElementById('planner-max-drop').value || 90));
    const cabinets = groups.map(group => ({
        ...group,
        group: [],
        totalDrop: 0,
        generated: false,
    }));

    const houses = points.map((point, index) => ({ point, index }));
    const pairs = [];
    houses.forEach(house => {
        cabinets.forEach((cabinet, cabinetIndex) => {
            pairs.push({
                house,
                cabinetIndex,
                distance: networkDropDistance(cabinet.pos, house.point),
            });
        });
    });
    pairs.sort((a, b) => a.distance - b.distance);

    const assignedHouses = new Set();
    pairs.forEach(pair => {
        if (assignedHouses.has(pair.house.index)) return;
        if (pair.distance > maxDrop) return;
        const cabinet = cabinets[pair.cabinetIndex];
        if (cabinet.group.length >= max) return;
        cabinet.group.push(pair.house);
        cabinet.totalDrop += pair.distance;
        assignedHouses.add(pair.house.index);
    });

    let unassigned = houses.filter(house => !assignedHouses.has(house.index));
    while (unassigned.length) {
        const seed = unassigned[0];
        const seedNetworkPoint = nearestOnNetwork(seed.point).point || seed.point;
        const localCabinet = {
            i: 0,
            pos: seedNetworkPoint,
            group: [],
            totalDrop: 0,
            generated: true,
        };

        unassigned
            .map(house => ({ house, distance: networkDropDistance(seedNetworkPoint, house.point) }))
            .filter(item => item.distance <= maxDrop)
            .sort((a, b) => a.distance - b.distance)
            .slice(0, max)
            .forEach(item => {
                localCabinet.group.push(item.house);
                localCabinet.totalDrop += item.distance;
                assignedHouses.add(item.house.index);
            });

        cabinets.push(localCabinet);
        unassigned = houses.filter(house => !assignedHouses.has(house.index));
    }

    return cabinets
        .filter(cabinet => cabinet.group.length)
        .map(cabinet => {
            const count = cabinet.group.length;
            const splitters = Math.ceil(count / 4);
            return {
                ...cabinet,
                s: count,
                splitters,
                waste: splitters * 4 - count,
            };
        });
}
function clearSuggestions() {
    suggestionLayers.forEach(l => map.removeLayer(l));
    suggestionLayers.forEach(l => {
        untrackLayer(l, 'preview');
        untrackLayer(l, 'drop');
    });
        Object.entries(houseMarkerByKey).forEach(([key, marker]) => marker.setIcon(icon('house', '', savedHouseColorByKey[key] || null)));
    suggestionLayers=[];
    suggestedCabinets=[];
    currentAutoPlan = null;
    document.getElementById('cabinet-count').textContent='0';
    document.getElementById('suggestion-output').innerHTML='Nacrtaj trasu i oznaci kuće.';
    document.getElementById('save-suggestions').classList.add('hidden');
    document.getElementById('material-specs-output').classList.add('hidden');
    refreshPlanSummary();
}

function calculateMaterialSpecs() {
    const specs = {};

    // Spliters i ormarići
    const splitterTotal = suggestedCabinets.reduce((sum, c) => sum + c.splitter_count, 0);
    const cabinetCount = suggestedCabinets.length;

    specs['Spliteri 1:4'] = { quantity: splitterTotal, unit: 'kom', price: 160 };
    specs['Zeleni ormarići (FTTH)'] = { quantity: cabinetCount, unit: 'kom', price: 900 };

    // Mikrocijevi i kabl
    const totalDuct = allDistance();
    const microductCount = allNetworkPoints().length;
    const totalMicroduct = totalDuct * microductCount;
    const reserveMicroduct = Math.ceil(totalMicroduct * 1.1);

    specs['Mikrocijevi 14/10 (m)'] = { quantity: Math.ceil(reserveMicroduct / 1000), unit: '1km', price: 15000 };
    specs['Optički kabl SM (m)'] = { quantity: Math.ceil(totalDuct * 1.1), unit: 'm', price: 12 };

    // Konektori i spajanja
    const spliceCount = splitterTotal + (housePoints.length - savedHouseCount);
    specs['Splice kasetne (kom)'] = { quantity: Math.ceil(spliceCount / 12), unit: 'kom', price: 450 };
    specs['Spojnice SC/APC'] = { quantity: housePoints.length - savedHouseCount, unit: 'kom', price: 8 };

    // Korisnički priključci
    specs['Optički prikljucak ONT'] = { quantity: housePoints.length - savedHouseCount, unit: 'kom', price: 45 };

    return specs;
}

function displayMaterialSpecs() {
    const specs = calculateMaterialSpecs();
    const container = document.getElementById('material-items');

    let html = '';
    let totalPrice = 0;

    Object.entries(specs).forEach(([name, data]) => {
        const price = data.quantity * data.price;
        totalPrice += price;
        html += `<div class="rounded-md bg-white p-2 grid grid-cols-4 gap-2 text-xs"><span class="font-semibold">${name}</span><span>${data.quantity} ${data.unit}</span><span class="text-right">${Number(data.price).toFixed(2)} KM</span><span class="font-semibold text-right">${Number(price).toFixed(2)} KM</span></div>`;
    });

    html += `<div class="rounded-md bg-sky-100 p-2 grid grid-cols-4 gap-2 font-semibold text-xs"><span colspan="3">UKUPNO:</span><span class="text-right">${Number(totalPrice).toFixed(2)} KM</span></div>`;

    container.innerHTML = html;

    // Store specs for saving
    window.currentMaterialSpecs = specs;
}
async function suggest() {
    clearSuggestions();
    suggestedCabinets = [];
    currentAutoPlan = null;
    const projectId = document.getElementById('active-project-id').value;
    const output = document.getElementById('suggestion-output');
    if (!projectId) { output.innerHTML = '<b class="text-red-700">Odaberi projekat prije prijedloga rasporeda.</b>'; return; }
    const url = window.ftthMapConfig.endpoints.autoOdoPreviewBaseUrl.replace('__ID__', projectId);
    output.innerHTML = 'Racunam Auto ODO po krakovima...';
    try {
        if (housePoints.length > savedHouseCount || branches.length || draftOdfs.length) {
            output.innerHTML = 'Prvo snimam nacrt sa mape, zatim racunam Auto ODO...';
            await persistDraftPlanForAutoOdo();
        }
        const response = await fetch(url, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '',
                'Accept': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
            },
            body: JSON.stringify({
                max_houses_per_odo: Number(document.getElementById('planner-max').value || 12),
                preferred_fill_min: Number(document.getElementById('planner-min').value || 8),
                max_house_to_odo_m: Number(document.getElementById('planner-max-drop').value || 90),
            }),
        });
        const plan = await readJsonResponse(response, 'Auto ODO preview nije uspio.');
        if (!response.ok) throw new Error(plan.message || 'Auto ODO preview nije uspio.');
        currentAutoPlan = plan;
        renderAutoOdoPlan(plan);
    } catch (error) {
        output.innerHTML = `<b class="text-red-700">${error.message}</b>`;
    }
}
function renderAutoOdoPlan(plan) {
    const branchColor = index => cabinetPalette[(Math.max(Number(index) || 1, 1) - 1) % cabinetPalette.length];
    (plan.cabinets || []).forEach(cabinet => {
        const color = branchColor(cabinet.branch_index);
        const position = L.latLng(cabinet.proposed_latitude, cabinet.proposed_longitude);
        const marker = trackLayer(L.marker(position, { icon: icon('suggest', cabinet.confirmed_name || cabinet.name, color) })
            .bindTooltip(`${cabinet.confirmed_name || cabinet.name} · ${cabinet.house_count}/12`, { direction: 'top', offset: [0, -10] })
            .bindPopup(`<b>${cabinet.confirmed_name || cabinet.name}</b><br>Krak ${cabinet.branch_index}<br>${cabinet.house_count}/12 kuca<br>ODF: ${cabinet.nearest_odf_name || 'nema'}`)
            .addTo(map), 'preview');
        const dropLines = (cabinet.drop_preview || []).map(drop => {
            const path = drop.path?.length ? drop.path : [
                [drop.from.lat, drop.from.lng],
                [drop.to.lat, drop.to.lng],
            ];
            return trackLayer(L.polyline(path, { color, weight: 1.7, opacity: .75, dashArray: '5 7' }).addTo(map), 'drop');
        });
        (cabinet.houses || []).forEach(house => {
            const houseMarker = houseMarkerByKey[pointKey(house.latitude, house.longitude)];
            if (houseMarker) houseMarker.setIcon(icon('house', '', color));
        });
        suggestedCabinets.push({
            name: cabinet.confirmed_name || cabinet.name,
            lat: Number(cabinet.proposed_latitude),
            lng: Number(cabinet.proposed_longitude),
            splitter_count: cabinet.splitter_count,
            odf_id: cabinet.nearest_odf_id,
            branch_id: cabinet.branch_id,
            marker,
            dropLines,
            plan: cabinet,
            houseKeys: (cabinet.houses || []).map(house => pointKey(house.latitude, house.longitude)),
        });
        suggestionLayers.push(marker, ...dropLines);
    });

    (plan.unassigned_houses || []).forEach(house => {
        const marker = houseMarkerByKey[pointKey(house.latitude, house.longitude)];
        if (marker) marker.setIcon(icon('house', '', '#ef4444'));
    });

    const branchHtml = (plan.branches || []).map(branch =>
        `<div class="rounded bg-white p-2"><b>Krak ${branch.branch_index}</b> -> ${branch.house_count} kuca<br>${branch.odo_count} FTTH</div>`
    ).join('');
    const cabinetHtml = (plan.cabinets || []).map(cabinet =>
        `<div class="border-b border-zinc-200 py-2"><b>${cabinet.confirmed_name || cabinet.name}</b><br>Krak ${cabinet.branch_index}, ${cabinet.house_count}/12 kuca<br>${(cabinet.houses || []).map(house => `${house.label} -> ${house.chainage_m}m`).join('<br>')}</div>`
    ).join('');
    const unassigned = plan.summary?.unassigned_house_count || 0;
    document.getElementById('cabinet-count').textContent = suggestedCabinets.length;
    document.getElementById('suggestion-output').innerHTML = `
        <div class="mb-2 rounded-md bg-emerald-50 p-2 text-xs font-semibold text-emerald-800">Preview plana je spreman. Potvrdi raspored ili odbaci raspored.</div>
        <div class="mb-2 grid gap-2">${branchHtml}<div class="rounded bg-white p-2"><b>Unassigned</b> -> ${unassigned} kuca</div></div>
        ${cabinetHtml}
    `;
    document.getElementById('save-suggestions').classList.remove('hidden');
    document.getElementById('material-specs-output').classList.remove('hidden');
    displayMaterialSpecs();
    refreshPlanSummary();
}
function nearestDraftOdf(point) {
    if (!draftOdfs.length) return null;
    return draftOdfs
        .map((odf, index) => ({ index, distance: Math.round(map.distance(point, odf.marker.getLatLng())) }))
        .sort((a,b) => a.distance - b.distance)[0];
}

function draftOdfCabinetCount(index) {
    return [...draftCabinets, ...suggestedCabinets].filter(cabinet => cabinet.odf_index === index).length;
}

function savedOdfsForActiveProject() {
    const projectId = document.getElementById('active-project-id').value;
    return data.odfs.filter(odf => !projectId || String(odf.project_id ?? '') === String(projectId) || data.projects?.find?.(project => String(project.id) === String(projectId) && project.name === odf.project));
}

function activeOdfPayload(point = null) {
    if (activeOdfSelection?.type === 'draft' && draftOdfs[activeOdfSelection.index]) {
        return { odf_index: activeOdfSelection.index, odf_id: null };
    }
    if (activeOdfSelection?.type === 'saved') {
        return { odf_index: null, odf_id: activeOdfSelection.id };
    }
    const nearest = point ? nearestDraftOdf(point) : null;
    return { odf_index: nearest?.index ?? null, odf_id: null };
}

function activeOdfLabel() {
    if (activeOdfSelection?.type === 'draft' && draftOdfs[activeOdfSelection.index]) {
        return draftOdfs[activeOdfSelection.index].name || defaultDraftName('odf', activeOdfSelection.index);
    }
    if (activeOdfSelection?.type === 'saved') {
        return data.odfs.find(odf => Number(odf.id) === Number(activeOdfSelection.id))?.name || 'ODF';
    }
    return null;
}

function defaultDraftName(type, index) {
    return type === 'odf' ? `ODF-${String(index + 1).padStart(2, '0')}` : `FTTH-M-${String(index + 1).padStart(3, '0')}`;
}
function selectDraftElement(type, item) {
    selectedDraftElement = { type, item };
    document.getElementById('element-editor').classList.remove('hidden');
    document.getElementById('element-editor-type').textContent = type === 'odf' ? 'ODF lokacija' : 'Draft FTTH ormarić';
    document.getElementById('element-editor-name').value = item.pending ? '' : item.name;
    document.getElementById('element-editor-status').textContent = item.pending
        ? 'Unos naziva je obavezan da bi ODF bio dodat.'
        : 'Upiši naziv i klikni Sačuvaj naziv.';
    document.getElementById('element-editor-name').focus();
}
function closeDraftElementEditor() {
    if (selectedDraftElement?.item.pending) map.removeLayer(selectedDraftElement.item.marker);
    selectedDraftElement = null;
    document.getElementById('element-editor').classList.add('hidden');
}
function saveSelectedDraftElementName() {
    if (!selectedDraftElement) return;
    const name = document.getElementById('element-editor-name').value.trim();
    if (!name) {
        document.getElementById('element-editor-status').textContent = 'Naziv je obavezan.';
        return;
    }
    selectedDraftElement.item.name = name;
    const wasPendingOdf = selectedDraftElement.item.pending && selectedDraftElement.type === 'odf';
    if (wasPendingOdf) {
        const item = selectedDraftElement.item;
        item.pending = false;
        item.marker.closePopup().bindPopup(`<b>ODF: ${name}</b>`);
        draftOdfCount++;
        draftElements.push({ type: 'odf', marker: item.marker });
        draftOdfs.push(item);
        item.marker.on('drag', () => { refreshDraftTooltips(); refreshPlanSummary(); });
        item.marker.on('click', () => { setActiveDraftOdf(draftOdfs.indexOf(item)); selectDraftElement('odf', item); });
        registerDraftContext(item.marker, item.name);
        setActiveDraftOdf(draftOdfs.length - 1);
    }
    if (selectedDraftElement.type === 'cabinet') selectedDraftElement.item.marker.setIcon(icon('cabinet', name));
    refreshDraftTooltips();
    refreshPlanSummary();
    document.getElementById('element-editor-status').textContent = selectedDraftElement.type === 'odf'
        ? `ODF "${name}" je sačuvan.`
        : `Naziv "${name}" je sačuvan.`;
    if (wasPendingOdf) {
        const savedItem = selectedDraftElement.item;
        setTimeout(() => {
            if (selectedDraftElement?.item === savedItem) closeDraftElementEditor();
        }, 450);
    }
}

function setActiveDraftOdf(index) {
    if (typeof index === 'string' && index.includes(':')) {
        const [type, value] = index.split(':');
        activeOdfSelection = type === 'saved' ? { type, id: Number(value) } : { type: 'draft', index: Number(value) };
    } else if (index === '' || index === null || index === undefined) {
        activeOdfSelection = null;
    } else {
        activeOdfSelection = { type: 'draft', index: Number(index) };
    }
    activeDraftOdfIndex = activeOdfSelection?.type === 'draft' ? activeOdfSelection.index : null;
    const value = activeOdfSelection ? `${activeOdfSelection.type}:${activeOdfSelection.type === 'saved' ? activeOdfSelection.id : activeOdfSelection.index}` : '';
    document.getElementById('active-odf-index').value = value;
    const label = activeOdfLabel();
    document.getElementById('odf-link-status').textContent = label ? `Novi FTTH ormarici se vezu na ${label}.` : 'Odaberi ODF prije redanja FTTH ormarica.';
    refreshDraftTooltips();
}
function renderDraftOdfPicker() {
    const select = document.getElementById('active-odf-index');
    const savedOdfs = savedOdfsForActiveProject();
    const savedOptions = savedOdfs.map(odf => `<option value="saved:${odf.id}">${odf.name} (postojeci ODF)</option>`);
    const draftOptions = draftOdfs.map((item, index) => `<option value="draft:${index}">${item.name || defaultDraftName('odf', index)} (${draftOdfCabinetCount(index)} FTTH)</option>`);
    select.innerHTML = [...savedOptions, ...draftOptions].length ? [...savedOptions, ...draftOptions].join('') : '<option value="">Prvo postavi ODF</option>';
    if (!activeOdfSelection && savedOdfs.length) activeOdfSelection = { type: 'saved', id: savedOdfs[0].id };
    if (!activeOdfSelection && draftOdfs.length) activeOdfSelection = { type: 'draft', index: draftOdfs.length - 1 };
    if (activeOdfSelection?.type === 'draft' && !draftOdfs[activeOdfSelection.index]) activeOdfSelection = null;
    activeDraftOdfIndex = activeOdfSelection?.type === 'draft' ? activeOdfSelection.index : null;
    const value = activeOdfSelection ? `${activeOdfSelection.type}:${activeOdfSelection.type === 'saved' ? activeOdfSelection.id : activeOdfSelection.index}` : '';
    select.value = value;
    const label = activeOdfLabel();
    document.getElementById('odf-link-status').textContent = label ? `Novi FTTH ormarici se vezu na ${label}.` : 'Postavi ODF, zatim postavljaj FTTH ormarice.';
    refreshRouteOdfStatus();
}
function refreshDraftTooltips() {
    draftOdfs.forEach((item, index) => {
        item.marker.bindTooltip(`${item.name} · ${draftOdfCabinetCount(index)} FTTH`, { direction: 'top', offset: [0, -10] });
    });
    draftCabinets.forEach(item => {
        const savedOdf = item.odf_id ? data.odfs.find(odf => Number(odf.id) === Number(item.odf_id)) : null;
        const label = savedOdf ? savedOdf.name : (item.odf_index === null || item.odf_index === undefined ? 'bez ODF' : `ODF-${String(item.odf_index + 1).padStart(2,'0')}`);
        item.marker.bindTooltip(`0/12 - ${label}`, { direction: 'top', offset: [0, -10] });
    });
    renderDraftOdfPicker();
}

function planPayload() {
    const odfs = draftOdfs.map((item, index) => {
        const p = item.marker.getLatLng();
        return { name: item.name || defaultDraftName('odf', index), lat: Number(p.lat.toFixed(7)), lng: Number(p.lng.toFixed(7)), fiber_capacity: 144 };
    });
    const manualCabinets = draftCabinets.map((item, index) => {
        const p = item.marker.getLatLng();
        return { name: item.name || defaultDraftName('cabinet', index), lat: Number(p.lat.toFixed(7)), lng: Number(p.lng.toFixed(7)), splitter_count: 3, odf_index: item.odf_index ?? null, odf_id: item.odf_id ?? null };
    });
    const suggestedCabinetPayload = suggestedCabinets.map(cabinet => ({
        name: cabinet.name,
        lat: cabinet.lat,
        lng: cabinet.lng,
        splitter_count: cabinet.splitter_count,
        odf_index: cabinet.odf_index ?? null,
        odf_id: cabinet.odf_id ?? null,
        houseKeys: cabinet.houseKeys || [],
    }));
    const cabinets = [...manualCabinets, ...suggestedCabinetPayload];
    const houses = housePoints.slice(savedHouseCount).map((p, index) => {
        const key = pointKey(p.lat, p.lng);
        const cabinetIndex = cabinets.findIndex(cabinet => (cabinet.houseKeys || []).includes(key));
        return {
            label: `K-${String(index+1).padStart(3,'0')}`,
            lat: Number(p.lat.toFixed(7)),
            lng: Number(p.lng.toFixed(7)),
            cabinet_index: cabinetIndex >= 0 ? cabinetIndex : null,
        };
    });
    const drawnRoutes = branches.map((branch, index) => {
        const meters = distance(branch);
        const meta = branchMeta[index] || {};
        return {
            name: meta.name || `Trasa ${index+1}`,
            route_type: meta.route_type || 'distribution',
            installation_type: meta.installation_type || 'underground',
            counts_as_trench: (meta.route_type || 'distribution') === 'trench',
            trench_length_m: null,
            microduct_type: (meta.route_type || 'distribution') === 'trench' ? null : (meta.microduct_type || '14/10'),
            fiber_count: (meta.route_type || 'distribution') === 'trench' ? 4 : (meta.fiber_count || 12),
            odf_index: meta.odf_index ?? null,
            duct_length_m: meters,
            fiber_length_m: meters,
            microduct_count: (meta.route_type || 'distribution') === 'trench' ? 0 : (meta.microduct_count || 1),
            path: branch.map(p => [Number(p.lat.toFixed(7)), Number(p.lng.toFixed(7))]),
            odf_id: meta.odf_id ?? null,
            from_type: meta.from_type ?? null,
            from_id: meta.from_id ?? null,
            to_type: meta.to_type ?? null,
            to_id: meta.to_id ?? null,
            cabinet_id: meta.cabinet_id ?? null,
        };
    });
    const routes = drawnRoutes;
    const appendix_items = draftAppendixItems.map(item => {
        const p = item.marker.getLatLng();
        return {
            type: item.type,
            lat: Number(p.lat.toFixed(7)),
            lng: Number(p.lng.toFixed(7)),
            quantity: item.type === 'boring_fi_130' ? Number((item.length_m || item.quantity || 1).toFixed(2)) : (item.quantity || 1),
            length_m: item.type === 'boring_fi_130' ? Number((item.length_m || item.quantity || 1).toFixed(2)) : null,
            angle_deg: item.type === 'boring_fi_130' ? Number(normalizeAngle(item.angle_deg || 0).toFixed(2)) : null,
            width_m: item.type === 'boring_fi_130' ? Number((item.width_m || 1.8).toFixed(2)) : null,
            note: item.note || '',
        };
    });
    return { odfs, cabinets, houses, routes, appendix_items };
}

function refreshPlanSummary() {
    const payload = planPayload();
    document.getElementById('bulk-plan-json').value = JSON.stringify(payload);
    const boringMeters = payload.appendix_items
        .filter(item => item.type === 'boring_fi_130')
        .reduce((sum, item) => sum + Number(item.length_m || item.quantity || 0), 0);
    document.getElementById('bulk-plan-summary').textContent = `Draft: ${payload.odfs.length} ODF, ${payload.cabinets.length} FTTH, ${payload.houses.length} kuca, ${payload.routes.length} trasa, ${payload.appendix_items.length} stavki, FI130 ${Math.round(boringMeters)} m.`;
    scheduleDraftAutosave();
}

async function persistDraftPlanForAutoOdo() {
    if (activeBranch.length > 1) finishBranch();
    refreshPlanSummary();
    const payload = planPayload();
    if (!payload.houses.length && !payload.routes.length && !payload.odfs.length && !payload.appendix_items.length) return false;

    const projectId = document.getElementById('active-project-id').value;
    const form = document.getElementById('bulk-plan-form');
    const body = new FormData();
    body.append('_token', form.querySelector('input[name="_token"]').value);
    body.append('project_id', projectId);
    body.append('plan', JSON.stringify(payload));

    const response = await fetch(form.action, {
        method: 'POST',
        headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
        body,
    });
    const result = await readJsonResponse(response, 'Nacrt nije snimljen prije Auto ODO planiranja.');
    if (!response.ok) throw new Error(result.message || 'Nacrt nije snimljen prije Auto ODO planiranja.');

    savedHouseCount += payload.houses.length;
    draftOdfs = [];
    draftCabinets = [];
    draftAppendixItems = [];
    draftElements = [];
    branches = [];
    branchMeta = [];
    draftsByProject[projectId] = null;
    document.getElementById('bulk-plan-status').textContent = `${result.message} Sada racunam Auto ODO.`;
    refreshPlanSummary();
    return true;
}

function draftPayload() {
    return {
        odfs: draftOdfs.map((item, index) => {
            const p = item.marker.getLatLng();
            return { name: item.name || defaultDraftName('odf', index), lat: Number(p.lat.toFixed(7)), lng: Number(p.lng.toFixed(7)) };
        }),
        cabinets: draftCabinets.map((item, index) => {
            const p = item.marker.getLatLng();
            return { name: item.name || defaultDraftName('cabinet', index), lat: Number(p.lat.toFixed(7)), lng: Number(p.lng.toFixed(7)), odf_index: item.odf_index ?? null, odf_id: item.odf_id ?? null };
        }),
        houses: housePoints.slice(savedHouseCount).map(p => [Number(p.lat.toFixed(7)), Number(p.lng.toFixed(7))]),
        branches: branches.map((branch, index) => ({
            path: branch.map(p => [Number(p.lat.toFixed(7)), Number(p.lng.toFixed(7))]),
            meta: branchMeta[index] || {},
        })),
        suggestedCabinets: suggestedCabinets.map(cabinet => ({
            name: cabinet.name,
            lat: cabinet.lat,
            lng: cabinet.lng,
            splitter_count: cabinet.splitter_count,
            odf_index: cabinet.odf_index ?? null,
            odf_id: cabinet.odf_id ?? null,
            houseKeys: cabinet.houseKeys || [],
        })),
        appendix_items: draftAppendixItems.map(item => {
            const p = item.marker.getLatLng();
            return {
                type: item.type,
                lat: Number(p.lat.toFixed(7)),
                lng: Number(p.lng.toFixed(7)),
                quantity: item.type === 'boring_fi_130' ? Number((item.length_m || item.quantity || 1).toFixed(2)) : (item.quantity || 1),
                length_m: item.type === 'boring_fi_130' ? Number((item.length_m || item.quantity || 1).toFixed(2)) : null,
                angle_deg: item.type === 'boring_fi_130' ? Number(normalizeAngle(item.angle_deg || 0).toFixed(2)) : null,
                width_m: item.type === 'boring_fi_130' ? Number((item.width_m || 1.8).toFixed(2)) : null,
                note: item.note || '',
            };
        }),
    };
}

function restoreDraft(payload) {
    if (!payload) return;
    restoringDraft = true;
    clearDraw();
    houseMarkers.forEach(marker => map.removeLayer(marker));
    houseMarkers = [];
    housePoints = data.houses.map(h => L.latLng(h.lat, h.lng));
    draftAppendixItems.forEach(removeAppendixDraftItem);
    draftElements.forEach(item => {
        if (!draftAppendixItems.some(appendixItem => appendixItem.marker === item.marker)) map.removeLayer(item.marker);
    });
    draftElements = [];
    draftOdfs = [];
    draftCabinets = [];
    draftAppendixItems = [];
    activeDraftOdfIndex = null;
    activeOdfSelection = null;
    clearSuggestions();

    (payload.branches || []).forEach((branch, index) => {
        const path = Array.isArray(branch) ? branch : (branch.path || []);
        const meta = Array.isArray(branch) ? { name: `Trasa ${index + 1}`, route_type: 'distribution', microduct_count: 1 } : (branch.meta || {});
        const points = path.map(point => L.latLng(point[0], point[1]));
        const meters = distance(points);
        const normalizedMeta = {
            name: meta.name || `Trasa ${index + 1}`,
            route_type: meta.route_type || 'distribution',
            microduct_count: (meta.route_type || 'distribution') === 'trench' ? 0 : (meta.microduct_count || 1),
            counts_as_trench: (meta.route_type || 'distribution') === 'trench',
            trench_length_m: null,
            odf_index: meta.odf_index ?? null,
            odf_id: meta.odf_id ?? null,
            from_type: meta.from_type ?? null,
            from_id: meta.from_id ?? null,
            duct_length_m: meters,
            fiber_length_m: meters,
            path,
        };
        branches.push(points);
        branchMeta.push(normalizedMeta);
        const odfLabel = normalizedMeta.odf_index === null || normalizedMeta.odf_index === undefined ? 'bez ODF' : `ODF-${String(normalizedMeta.odf_index + 1).padStart(2, '0')}`;
        const line = L.polyline(points, { color: routeColor(normalizedMeta.route_type), weight: 4 }).bindPopup(`<b>${normalizedMeta.name}</b><br>${routeTypeLabel(normalizedMeta.route_type)}<br>${odfLabel}<br>${meters} m`).addTo(map);
        branchLines.push(line);
        registerBranchContext(line);
        addRouteLabel(points, normalizedMeta.name);
    });
    renderBranchList();

    let restoredHouseIndex = 0;
    (payload.houses || []).forEach((point) => {
        if (savedHouseKeys.has(pointKey(point[0], point[1]))) return;
        const latLng = L.latLng(Array.isArray(point) ? point[0] : point.lat, Array.isArray(point) ? point[1] : point.lng);
        const item = { marker: null, name: Array.isArray(point) ? defaultDraftName('odf', index) : (point.name || defaultDraftName('odf', index)) };
        const houseIndex = restoredHouseIndex++;
        housePoints.push(latLng);
        const marker = L.marker(latLng, { icon: icon('house'), draggable: true }).bindPopup(`Kuca ${houseIndex + 1}`).addTo(map);
        houseMarkerByKey[pointKey(latLng.lat, latLng.lng)] = marker;
        marker.on('drag', event => {
            const next = event.target.getLatLng();
            housePoints[savedHouseCount + houseIndex] = next;
            houseMarkerByKey[pointKey(next.lat, next.lng)] = marker;
            refreshStats();
        });
        registerHouseContext(marker);
        houseMarkers.push(marker);
    });

    (payload.odfs || []).forEach((point, index) => {
        const latLng = L.latLng(point[0], point[1]);
        const marker = L.marker(latLng, { icon: icon('odf', 'ODF'), draggable: true }).bindTooltip('ODF · 0 FTTH', { direction: 'top', offset: [0, -10] }).addTo(map);
        item.marker = marker;
        marker.on('drag', () => { refreshDraftTooltips(); refreshPlanSummary(); });
        marker.on('click', () => { setActiveDraftOdf(index); selectDraftElement('odf', item); });
        registerDraftContext(marker, `ODF-${String(index + 1).padStart(2, '0')}`);
        draftOdfs.push(item);
        draftElements.push({ type: 'odf', marker });
        draftOdfCount = Math.max(draftOdfCount, index + 1);
    });

    (payload.cabinets || []).forEach((point, index) => {
        const lat = Array.isArray(point) ? point[0] : point.lat;
        const lng = Array.isArray(point) ? point[1] : point.lng;
        const latLng = L.latLng(lat, lng);
        const item = { marker: null, name: Array.isArray(point) ? defaultDraftName('cabinet', index) : (point.name || defaultDraftName('cabinet', index)), odf_index: Array.isArray(point) ? (nearestDraftOdf(latLng)?.index ?? null) : (point.odf_index ?? null), odf_id: Array.isArray(point) ? null : (point.odf_id ?? null) };
        const marker = L.marker(latLng, { icon: icon('cabinet', item.name), draggable: true }).bindTooltip('0/12', { direction: 'top', offset: [0, -10] }).addTo(map);
        item.marker = marker;
        marker.on('drag', () => {
            refreshDraftTooltips();
            refreshPlanSummary();
        });
        marker.on('click', () => selectDraftElement('cabinet', item));
        registerDraftContext(marker, item.name);
        draftCabinets.push(item);
        draftElements.push({ type: 'cabinet', marker });
        draftCabinetCount = Math.max(draftCabinetCount, index + 1);
    });

    (payload.appendix_items || []).forEach(item => {
        const latLng = L.latLng(item.lat, item.lng);
        const isManhole = item.type === 'manhole';
        const draftItem = isManhole
            ? { type: item.type, quantity: item.quantity || 1, note: item.note || '', marker: null }
            : createBoringDraft(latLng, item);
        if (isManhole) {
            const marker = L.marker(latLng, { icon: icon('manhole', 'S'), draggable: true })
                .bindTooltip('Prolazni saht', { direction: 'top', offset: [0, -10] })
                .addTo(map);
            draftItem.marker = marker;
            marker.on('drag', refreshPlanSummary);
        }
        registerDraftContext(draftItem.marker, isManhole ? 'Prolazni saht' : 'Podbusivanje FI 130');
        draftAppendixItems.push(draftItem);
        draftElements.push({ type: item.type, marker: draftItem.marker });
    });

    refreshDraftTooltips();
    refreshStats();
    restoringDraft = false;
}

function scheduleDraftAutosave() {
    if (!autosaveReady || restoringDraft || !document.getElementById('active-project-id').value) return;
    clearTimeout(autosaveTimer);
    const status = document.getElementById('bulk-plan-status');
    status.textContent = 'Izmjena spremna za automatsko čuvanje...';
    autosaveTimer = setTimeout(() => saveDraft({ quiet: true }).catch(error => {
        status.textContent = error.message;
    }), 700);
}

async function saveDraft({ quiet = false } = {}) {
    const projectId = document.getElementById('active-project-id').value;
    const status = document.getElementById('bulk-plan-status');
    if (!projectId) {
        status.textContent = 'Odaberi projekat prije čuvanja nacrta.';
        return;
    }

    const body = new FormData();
    body.append('_token', document.querySelector('#bulk-plan-form input[name="_token"]').value);
    body.append('project_id', projectId);
    body.append('draft', JSON.stringify(draftPayload()));
    status.textContent = quiet ? 'Automatski čuvam nacrt...' : 'Cuvam nacrt...';

    const response = await fetch('{{ route('map.draft.store') }}', {
        method: 'POST',
        headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
        body,
    });

    if (!response.ok) {
        status.textContent = await response.text();
        return;
    }

    const result = await readJsonResponse(response, 'Nacrt nije sačuvan.');
    draftsByProject[projectId] = draftPayload();
    status.textContent = quiet ? `Nacrt automatski sačuvan (${result.updated_at})` : `${result.message} (${result.updated_at})`;
}

function commitTrenchLines() {
    allNetworkPoints().forEach(branch => {
        const trench = L.polyline(branch, {
            color: '#111827',
            weight: 5,
            opacity: .9,
        }).bindPopup(`<b>Rov / spremljena trasa</b><br>${distance(branch)} m`).addTo(map);
        trenchLines.push(trench);
    });
}

document.getElementById('finish-branch').addEventListener('click', finishBranch);
document.getElementById('cancel-draw').addEventListener('click', cancelActiveDrawing);
document.getElementById('undo-draw').addEventListener('click', undoDraw);
document.getElementById('undo-branch').addEventListener('click', undoBranch);
document.getElementById('clear-draw').addEventListener('click', clearDraw);
document.getElementById('route-draw-type').addEventListener('change', event => {
    document.getElementById('route-draw-name').placeholder = `npr. ${nextRouteName(event.target.value)}`;
    document.getElementById('cad-command').textContent = `TRASA: aktivan tip ${routeTypeLabel(event.target.value)}. Klikni tačke na mapi.`;
    refreshTrenchGroupStatus();
});
document.getElementById('undo-house').addEventListener('click', () => { const m = houseMarkers.pop(); if(m) map.removeLayer(m); housePoints.pop(); refreshStats(); });
document.getElementById('undo-element').addEventListener('click', () => {
    const item = draftElements.pop();
    if (!item) return;
    if (selectedDraftElement?.item.marker === item.marker) closeDraftElementEditor();
    const appendixItem = draftAppendixItems.find(entry => entry.marker === item.marker);
    if (appendixItem) removeAppendixDraftItem(appendixItem);
    else map.removeLayer(item.marker);
    if (item.type === 'odf') {
        const removedIndex = draftOdfs.findIndex(entry => entry.marker === item.marker);
        draftOdfs = draftOdfs.filter(entry => entry.marker !== item.marker);
        draftCabinets.forEach(cabinet => {
            if (cabinet.odf_index === removedIndex) cabinet.odf_index = null;
            if (cabinet.odf_index > removedIndex) cabinet.odf_index--;
        });
        activeDraftOdfIndex = draftOdfs.length ? Math.min(activeDraftOdfIndex ?? 0, draftOdfs.length - 1) : null;
    }
    if (item.type === 'cabinet') draftCabinets = draftCabinets.filter(entry => entry.marker !== item.marker);
    if (item.type === 'manhole' || item.type === 'boring_fi_130') {
        draftAppendixItems = draftAppendixItems.filter(entry => entry.marker !== item.marker);
    }
    refreshDraftTooltips();
    refreshPlanSummary();
});
document.getElementById('suggest-cabinets').addEventListener('click', suggest);
document.getElementById('clear-suggestions').addEventListener('click', clearSuggestions);
document.getElementById('active-odf-index').addEventListener('change', event => setActiveDraftOdf(event.target.value));
document.querySelectorAll('[data-guide-mode]').forEach(button => {
    button.addEventListener('click', () => setMode(button.dataset.guideMode));
});
document.getElementById('guide-suggest').addEventListener('click', suggest);

async function saveSuggestions() {
    const projectId = document.getElementById('active-project-id').value;
    const output = document.getElementById('suggestion-output');
    if (!projectId) { output.innerHTML = '<b class="text-red-700">Odaberi projekat prije potvrde rasporeda.</b>'; return; }
    if (!currentAutoPlan || !suggestedCabinets.length) { output.innerHTML = '<b class="text-red-700">Nema backend plana za potvrdu.</b>'; return; }
    const btn = document.getElementById('save-suggestions');
    const originalText = btn.textContent;
    btn.disabled = true;
    btn.textContent = 'Snimam...';
    try {
        const url = window.ftthMapConfig.endpoints.autoOdoConfirmBaseUrl.replace('__ID__', projectId);
        const response = await fetch(url, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '',
                'Accept': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
            },
            body: JSON.stringify({ plan: currentAutoPlan, create_drop_routes: false }),
        });
        const result = await readJsonResponse(response, 'FTTH raspored nije snimljen.');
        if (!response.ok) throw new Error(result.message || 'FTTH raspored nije snimljen.');
        output.innerHTML = `<b class="text-emerald-700">${result.message} Povezano kuca: ${result.linked_houses}.</b>`;
        keepSavedSuggestionsOnMap();
    } catch (error) {
        output.innerHTML = `<b class="text-red-700">Greska: ${error.message}</b>`;
    } finally {
        btn.disabled = false;
        btn.textContent = originalText;
    }
}
function keepSavedSuggestionsOnMap() {
    suggestionLayers.forEach(layer => map.removeLayer(layer));
    suggestionLayers = [];
    suggestedCabinets = [];
    currentAutoPlan = null;
    document.getElementById('save-suggestions').classList.add('hidden');
    document.getElementById('suggestion-output').innerHTML = '<b class="text-emerald-700">FTTH ormarici su snimljeni. Osvjezavam mapu...</b>';
    refreshPlanSummary();
    window.setTimeout(() => window.location.reload(), 500);
}

document.getElementById('save-suggestions').addEventListener('click', saveSuggestions);
document.getElementById('clear-map-trace')?.addEventListener('click', clearFiberTrace);
document.querySelectorAll('[data-layer-toggle]').forEach(input => {
    input.addEventListener('change', () => applyLayerVisibility(input.dataset.layerToggle));
});
document.querySelectorAll('[data-layer-lock]').forEach(button => {
    button.addEventListener('click', () => {
        const type = button.dataset.layerLock;
        layerLocks[type] = !layerLocks[type];
        button.textContent = layerLocks[type] ? 'Zaključan' : 'Otključan';
        button.classList.toggle('border-red-200', layerLocks[type]);
        button.classList.toggle('bg-red-50', layerLocks[type]);
        button.classList.toggle('text-red-700', layerLocks[type]);
        document.getElementById('cad-command').textContent = `Layer ${type}: ${layerLocks[type] ? 'zaključan' : 'otključan'}.`;
    });
});
Object.keys(layerRegistry).forEach(updateLayerCount);

async function saveMaterials() {
    const projectId = document.getElementById('active-project-id').value;
    const output = document.getElementById('material-items');
    if (!projectId || !window.currentMaterialSpecs) {
        output.innerHTML = '<div class="text-xs font-semibold text-red-700">Obračunaj materijale prije snimanja.</div>';
        return;
    }

    const btn = document.getElementById('save-all-materials');
    const originalText = btn.textContent;
    btn.disabled = true;
    btn.textContent = 'Snimam...';

    const materialsToSave = Object.entries(window.currentMaterialSpecs).map(([name, data]) => ({
        project_id: parseInt(projectId),
        name: name,
        unit: data.unit,
        planned_quantity: data.quantity,
        used_quantity: 0,
        unit_price: data.price,
    }));

    try {
        const promises = materialsToSave.map(material =>
            fetch('{{ route('materials.store') }}', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '',
                },
                body: new URLSearchParams(material),
            })
        );

        const results = await Promise.all(promises);
        const allSuccess = results.every(r => r.ok);

        if (allSuccess) {
            output.innerHTML = `<div class="text-xs font-semibold text-emerald-700">Snimljeno ${materialsToSave.length} stavki materijala.</div>`;
            clearSuggestions();
        } else {
            output.innerHTML = '<div class="text-xs font-semibold text-red-700">Greška pri snimanju nekih materijala.</div>';
        }
    } catch (error) {
        output.innerHTML = `<div class="text-xs font-semibold text-red-700">Greška: ${error.message}</div>`;
    } finally {
        btn.disabled = false;
        btn.textContent = originalText;
    }
}

document.getElementById('save-all-materials').addEventListener('click', saveMaterials);
document.getElementById('quick-project-form').addEventListener('submit', async event => {
    event.preventDefault();
    const form = event.currentTarget;
    const status = document.getElementById('quick-project-status');
    status.textContent = 'Kreiram projekat...';
    try {
        const response = await fetch('{{ route('projects.store') }}', {
            method: 'POST',
            headers: {
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '',
                'Accept': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
            },
            body: new FormData(form),
        });
        const result = await readJsonResponse(response, 'Projekat nije kreiran. Provjeri podatke.');
        const select = document.getElementById('active-project-id');
        keepCurrentDraftOnProjectChange = true;
        select.add(new Option(result.project.name, result.project.id, true, true));
        select.dispatchEvent(new Event('change'));
        draftsByProject[result.project.id] = draftPayload();
        await saveDraft();
        form.reset();
        status.textContent = `${result.project.name} je kreiran, odabran i nacrt je sačuvan.`;
    } catch (error) {
        status.textContent = error.message;
    }
});
document.getElementById('save-draft').addEventListener('click', () => saveDraft().catch(error => {
    document.getElementById('bulk-plan-status').textContent = error.message;
}));
document.getElementById('quick-save-draft').addEventListener('click', () => saveDraft().catch(error => {
    document.getElementById('bulk-plan-status').textContent = error.message;
}));
document.getElementById('save-element-name').addEventListener('click', saveSelectedDraftElementName);
document.getElementById('close-element-editor').addEventListener('click', closeDraftElementEditor);
document.getElementById('element-editor-name').addEventListener('keydown', event => {
    if (event.key === 'Enter') {
        event.preventDefault();
        saveSelectedDraftElementName();
    }
});
document.getElementById('element-editor-name').addEventListener('blur', saveSelectedDraftElementName);
renderBranchList();
document.getElementById('route-draw-name').placeholder = `npr. ${nextRouteName(document.getElementById('route-draw-type').value)}`;
document.getElementById('bulk-plan-form').addEventListener('submit', async event => {
    event.preventDefault();
    const form = event.currentTarget;
    const status = document.getElementById('bulk-plan-status');
    if (activeBranch.length > 1) finishBranch();
    refreshPlanSummary();
    try {
        await saveDraft();
        status.textContent = 'Snimam plan...';
        const response = await fetch(form.action, {
            method: 'POST',
            headers: {
                'Accept': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
            },
            body: new FormData(form),
        });

        if (!response.ok) {
            const errorText = await response.text();
            throw new Error(errorText || 'Plan nije snimljen. Provjeri podatke.');
        }

        const result = await readJsonResponse(response, 'Plan nije snimljen. Provjeri podatke.');
        delete draftsByProject[form.elements.project_id.value];
        status.textContent = `${result.message} Osvjezavam trajno spremljenu mapu...`;
        window.location.reload();
    } catch (error) {
        status.textContent = error.message;
    }
});
document.getElementById('expand-map').addEventListener('click', () => {
    expandedMap = !expandedMap;
    const workspace = document.getElementById('map-workspace');
    const sidebar = workspace.querySelector('aside');
    const btn = document.getElementById('expand-map');
    if (expandedMap) {
        workspace.className = 'grid flex-1 gap-2';
        sidebar.classList.add('hidden');
        btn.textContent = 'Prikazi panele';
    } else {
        workspace.className = 'grid flex-1 gap-2 xl:grid-cols-[minmax(0,1fr)_330px]';
        sidebar.classList.remove('hidden');
        btn.textContent = 'Velika mapa';
    }
    setTimeout(() => map.invalidateSize(), 100);
});
document.getElementById('add-route-vertex').addEventListener('click', () => addRouteEditVertex());
document.getElementById('cancel-route-edit').addEventListener('click', cancelRouteEdit);
document.getElementById('save-route-edit').addEventListener('click', async () => {
    try {
        await saveRouteEdit();
    } catch (error) {
        document.getElementById('cad-command').textContent = error.message;
    }
});
document.getElementById('finish-house-connect').addEventListener('click', async () => {
    try { await finishHouseConnect(); } catch (error) { document.getElementById('cad-command').textContent = error.message; }
});
document.getElementById('cancel-house-connect').addEventListener('click', () => {
    resetHouseConnect();
    document.getElementById('cad-command').textContent = 'CONNECT HOUSES: povezivanje otkazano.';
});

map.on('mousemove', e => {
    document.getElementById('cad-coordinates').textContent = `LAT ${e.latlng.lat.toFixed(7)}, LNG ${e.latlng.lng.toFixed(7)}`;
    redrawPreviewBranch(e.latlng);
    if (mode !== 'draw') updateCommandBar();
});

map.on('contextmenu', e => {
    if (mode !== 'draw') return;
    e.originalEvent.preventDefault();
    finishBranch();
});

map.on('dblclick', e => {
    if (mode !== 'draw') return;
    e.originalEvent.preventDefault();
    finishBranch();
});

document.addEventListener('keydown', event => {
    const target = event.target;
    const tag = target?.tagName?.toLowerCase();
    if (['input', 'select', 'textarea'].includes(tag) || target?.isContentEditable) return;

    if (event.key === 'Escape') {
        if (routeEdit) {
            cancelRouteEdit();
            return;
        }
        if (mode === 'draw') {
            cancelActiveDrawing();
        }
        setMode('pan');
        return;
    }

    if (event.key === 'Enter' && mode === 'draw') {
        event.preventDefault();
        finishBranch();
        return;
    }
    if (event.key === 'Enter' && mode === 'join') {
        event.preventDefault();
        joinSelectedRoutes();
        return;
    }

    if (event.key === 'Backspace' && mode === 'draw') {
        event.preventDefault();
        undoDraw();
        return;
    }

    if ((event.ctrlKey || event.metaKey) && event.key.toLowerCase() === 'z') {
        event.preventDefault();
        undoLast();
        return;
    }

    if ((event.ctrlKey || event.metaKey) && event.key.toLowerCase() === 'y') {
        event.preventDefault();
        redoLast();
        return;
    }

    if (!event.ctrlKey && !event.metaKey && event.key.toLowerCase() === 'o') {
        event.preventDefault();
        orthoEnabled = !orthoEnabled;
        updateCommandBar();
    }
});

map.on('click', e => {
    const lat = e.latlng.lat.toFixed(7), lng = e.latlng.lng.toFixed(7);
    if (mode === 'draw') { addDrawPoint(e.latlng); return; }
    if (mode === 'house') {
        housePoints.push(e.latlng);
        const index = housePoints.length - 1;
        const marker = L.marker(e.latlng, { icon: icon('house'), draggable: true }).bindPopup(`Kuca ${housePoints.length}`).addTo(map);
        houseMarkerByKey[pointKey(e.latlng.lat, e.latlng.lng)] = marker;
        marker.on('drag', event => {
            const next = event.target.getLatLng();
            housePoints[index] = next;
            houseMarkerByKey[pointKey(next.lat, next.lng)] = marker;
            refreshStats();
        });
        registerHouseContext(marker);
        houseMarkers.push(marker);
        document.getElementById('house-lat').value=lat; document.getElementById('house-lng').value=lng; refreshStats(); return;
    }
    if (mode === 'manhole' || mode === 'boring-fi-130') {
        const isManhole = mode === 'manhole';
        const item = isManhole
            ? { type: 'manhole', marker: null, quantity: 1, note: '' }
            : createBoringDraft(e.latlng, { length_m: 12, angle_deg: 0, width_m: 1.8 });
        if (isManhole) {
            const marker = L.marker(e.latlng, { icon: icon('manhole', 'S'), draggable: true })
                .addTo(map)
                .bindTooltip('Prolazni saht', { direction: 'top', offset: [0, -10] })
                .bindPopup('Prolazni saht')
                .openPopup();
            item.marker = marker;
            marker.on('drag', refreshPlanSummary);
            marker.on('click', () => marker.openPopup());
        } else {
            item.marker.openPopup();
        }
        draftAppendixItems.push(item);
        draftElements.push({ type: item.type, marker: item.marker });
        registerDraftContext(item.marker, isManhole ? 'Prolazni saht' : 'Podbusivanje FI 130');
        refreshPlanSummary();
        return;
    }
    if (mode === 'odf') {
        if (selectedDraftElement?.item.pending) closeDraftElementEditor();
        const item = { marker: null, name: '', pending: true };
        const marker = L.marker(e.latlng, { icon: icon('odf','ODF'), draggable: true })
            .addTo(map)
            .bindTooltip('ODF · 0 FTTH', { direction: 'top', offset: [0, -10] })
            .bindPopup('Unesi naziv ODF-a')
            .openPopup();
        item.marker = marker;
        marker.on('dragend', event => { const p = event.target.getLatLng(); document.getElementById('odf-lat').value=p.lat.toFixed(7); document.getElementById('odf-lng').value=p.lng.toFixed(7); });
        selectDraftElement('odf', item);
        document.getElementById('odf-lat').value=lat; document.getElementById('odf-lng').value=lng; return;
    }
    if (mode === 'cabinet') {
        draftCabinetCount++;
        const odf = activeOdfPayload(e.latlng);
        const item = { marker: null, name: defaultDraftName('cabinet', draftCabinetCount - 1), odf_index: odf.odf_index, odf_id: odf.odf_id };
        const marker = L.marker(e.latlng, { icon: icon('cabinet', item.name), draggable: true })
            .addTo(map)
            .bindTooltip('0/12', { direction: 'top', offset: [0, -10] })
            .bindPopup(`FTTH draft ${draftCabinetCount}`)
            .openPopup();
        item.marker = marker;
        marker.on('dragend', event => { const p = event.target.getLatLng(); document.getElementById('cabinet-lat').value=p.lat.toFixed(7); document.getElementById('cabinet-lng').value=p.lng.toFixed(7); });
        marker.on('drag', () => {
            refreshPlanSummary();
        });
        draftElements.push({ type: 'cabinet', marker });
        draftCabinets.push(item);
        marker.on('click', () => selectDraftElement('cabinet', item));
        registerDraftContext(marker, `FTTH-${draftCabinetCount}`);
        refreshDraftTooltips();
        selectDraftElement('cabinet', item);
        document.getElementById('cabinet-lat').value=lat; document.getElementById('cabinet-lng').value=lng;
    }
});
setMode('pan'); refreshStats();

// Auto-load draft when project is selected
data.drafts.forEach(draft => {
    draftsByProject[draft.project_id] = draft.payload;
});
autosaveReady = true;

document.getElementById('active-project-id').addEventListener('change', (e) => {
    const projectId = e.target.value;
    if (!projectId) return;
    if (keepCurrentDraftOnProjectChange) {
        keepCurrentDraftOnProjectChange = false;
        activeOdfSelection = null;
        renderDraftOdfPicker();
        refreshPlanSummary();
        return;
    }
    const draft = draftsByProject[projectId];
    if (draft) {
        restoreDraft(draft);
    } else {
        clearDraw();
        houseMarkers.forEach(m => map.removeLayer(m));
        houseMarkers = [];
        housePoints = data.houses.map(h => L.latLng(h.lat, h.lng));
        draftAppendixItems.forEach(removeAppendixDraftItem);
        draftElements.forEach(item => {
            if (!draftAppendixItems.some(appendixItem => appendixItem.marker === item.marker)) map.removeLayer(item.marker);
        });
        draftElements = [];
        draftOdfs = [];
        draftCabinets = [];
        draftAppendixItems = [];
        activeDraftOdfIndex = null;
        activeOdfSelection = null;
        clearSuggestions();
        renderDraftOdfPicker();
        refreshStats();
    }
});

// Load first project's draft on page load
if (document.querySelectorAll('#active-project-id option:not([value=""])').length > 0) {
    const firstProject = document.querySelector('#active-project-id option:not([value=""])');
    if (firstProject) {
        const projectId = firstProject.value;
        document.getElementById('active-project-id').value = projectId;
        renderDraftOdfPicker();
        setTimeout(() => {
            const draft = draftsByProject[projectId];
            if (draft) {
                restoreDraft(draft);
            } else {
                renderDraftOdfPicker();
            }
        }, 500);
    }
}
const pendingTraceHouseId = localStorage.getItem('ftthTraceHouseId');
if (pendingTraceHouseId) {
    localStorage.removeItem('ftthTraceHouseId');
    setTimeout(() => showFiberTrace(pendingTraceHouseId), 350);
}
refreshTrenchGroupStatus();
</script>
@endsection
