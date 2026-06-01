@extends('ftth.layout')

@section('title', 'Mapa mreze')
@section('subtitle', 'Satelitski projektantski prikaz za ODF, FTTH ormarice, kuce i trase.')
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
    .ftth-tag.odf { min-width: 30px; height: 18px; border-radius: 999px; background: #0891b2; }
    .ftth-tag.cabinet { min-width: 38px; height: 18px; border-radius: 5px; background: #059669; }
    .ftth-tag.house { width: 14px; height: 14px; border: 2px solid #fff; border-radius: 999px; background: #7c3aed; font-size: 0; }
    .ftth-tag.suggest { min-width: 46px; height: 20px; border-radius: 5px; background: #f59e0b; color: #111827; }
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
                    <div class="metric-card rounded-md px-3 py-2"><span class="block text-zinc-500">Kuce</span><strong id="house-count" class="text-sm text-violet-700">0</strong></div>
                    <div class="metric-card rounded-md px-3 py-2"><span class="block text-zinc-500">FTTH</span><strong id="cabinet-count" class="text-sm text-emerald-700">0</strong></div>
                </div>
            </div>
            <div class="mt-3 flex flex-wrap items-center gap-2">
                <button type="button" id="mode-pan" class="tool-btn rounded-md bg-zinc-950 px-3 py-2 text-sm font-semibold text-white">Pomjeraj</button>
                <button type="button" id="mode-odf" class="tool-btn rounded-md border border-cyan-200 bg-cyan-50 px-3 py-2 text-sm font-semibold text-cyan-800">ODF</button>
                <button type="button" id="mode-cabinet" class="tool-btn rounded-md border border-emerald-200 bg-emerald-50 px-3 py-2 text-sm font-semibold text-emerald-800">FTTH</button>
                <button type="button" id="mode-house" class="tool-btn rounded-md border border-violet-200 bg-violet-50 px-3 py-2 text-sm font-semibold text-violet-800">Kuce</button>
                <button type="button" id="mode-draw" class="tool-btn rounded-md border border-amber-200 bg-amber-50 px-3 py-2 text-sm font-semibold text-amber-800">Trasa</button>
                <span class="mx-1 hidden h-8 w-px bg-zinc-200 sm:block"></span>
                <button type="button" id="finish-branch" class="action-btn rounded-md bg-amber-500 px-3 py-2 text-sm font-semibold text-zinc-950">Zavrsi krak</button>
                <button type="button" id="undo-draw" class="action-btn rounded-md border border-zinc-300 bg-white px-3 py-2 text-sm font-semibold">Undo tacka</button>
                <button type="button" id="undo-branch" class="action-btn rounded-md border border-zinc-300 bg-white px-3 py-2 text-sm font-semibold">Undo krak</button>
                <button type="button" id="undo-element" class="action-btn rounded-md border border-zinc-300 bg-white px-3 py-2 text-sm font-semibold">Undo element</button>
                <button type="button" id="undo-house" class="action-btn rounded-md border border-zinc-300 bg-white px-3 py-2 text-sm font-semibold">Undo kuca</button>
                <button type="button" id="clear-draw" class="action-btn rounded-md border border-red-200 bg-red-50 px-3 py-2 text-sm font-semibold text-red-700">Ocisti trase</button>
                <button type="button" id="quick-save-draft" class="action-btn rounded-md bg-emerald-600 px-3 py-2 text-sm font-semibold text-white hover:bg-emerald-700">Sacuvaj nacrt</button>
                <button type="button" id="expand-map" class="action-btn ml-auto rounded-md border border-zinc-300 bg-white px-3 py-2 text-sm font-semibold text-zinc-700">Velika mapa</button>
            </div>
            <p class="mt-2 text-xs text-zinc-500">Nacrt se cuva automatski. Desni klik na element: obrisi ili premjesti.</p>
        </div>
        <div id="network-map" class="min-h-0 flex-1 w-full"></div>
        <div class="cad-status grid gap-2 px-3 py-2 md:grid-cols-[1fr_auto_auto]">
            <div id="cad-command">PAN: pomjeraj mapu. Izaberi ODF, FTTH, Kuce ili Trasa.</div>
            <div id="cad-coordinates" class="cad-chip rounded px-2 py-1">LAT -, LNG -</div>
            <div class="cad-chip rounded px-2 py-1">ESC prekid · ENTER zavrsi · CTRL+Z undo</div>
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
            <button type="button" id="save-element-name" class="mt-2 w-full rounded-md bg-emerald-600 px-4 py-2 text-sm font-semibold text-white">Sacuvaj naziv</button>
            <div id="element-editor-status" class="mt-2 text-xs font-semibold text-emerald-700"></div>
        </div>
        <form method="POST" action="{{ route('map.plan.store') }}" id="bulk-plan-form" class="control-panel rounded-md p-3">
            @csrf
            <div class="grid gap-1">
                <h2 class="font-semibold text-zinc-950">Plan projekta</h2>
                <span id="bulk-plan-summary" class="text-sm text-emerald-700">Draft: 0 ODF, 0 FTTH, 0 kuca, 0 trasa.</span>
            </div>
            <div class="mt-3 grid gap-2">
                <select id="active-project-id" name="project_id" class="rounded-md border border-zinc-300 bg-white px-3 py-2 text-sm" required><option value="">Odaberi projekat</option>@foreach($projects as $project)<option value="{{ $project->id }}">{{ $project->name }}</option>@endforeach</select>
                <div class="grid gap-2 rounded-md border border-emerald-200 bg-emerald-50 p-2">
                    <div class="grid grid-cols-2 gap-2 text-xs font-semibold">
                        <button type="button" data-guide-mode="odf" class="guide-step rounded bg-cyan-100 px-2 py-2 text-cyan-800">1 ODF</button>
                        <button type="button" data-guide-mode="draw" class="guide-step rounded bg-amber-100 px-2 py-2 text-amber-800">2 Trasa</button>
                        <button type="button" data-guide-mode="house" class="guide-step rounded bg-violet-100 px-2 py-2 text-violet-800">3 Kuce</button>
                        <button type="button" id="guide-suggest" class="guide-step rounded bg-emerald-100 px-2 py-2 text-emerald-800">4 FTTH</button>
                    </div>
                    <button class="rounded-md bg-emerald-600 px-4 py-2 text-sm font-semibold text-white hover:bg-emerald-700">5 Sacuvaj na mapi</button>
                </div>
                <div class="rounded-md border border-cyan-100 bg-cyan-50 p-3">
                    <label class="grid gap-1 text-xs font-semibold text-cyan-900">
                        Aktivni ODF za nove ormarice
                        <select id="active-odf-index" class="rounded-md border border-cyan-200 bg-white px-3 py-2 text-sm font-normal text-zinc-800">
                            <option value="">Prvo postavi ODF</option>
                        </select>
                    </label>
                    <div id="odf-link-status" class="mt-2 text-xs text-cyan-800">Postavi ODF, zatim postavljaj FTTH ormariće.</div>
                </div>
                <details class="rounded-md border border-amber-100 bg-amber-50">
                    <summary class="cursor-pointer list-none px-3 py-2 text-sm font-semibold text-amber-950">Postavke trase</summary>
                <div class="border-t border-amber-100 p-3">
                    <div class="mb-2 flex items-center justify-between gap-2">
                        <h3 class="text-sm font-semibold text-amber-950">Aktivni krak trase</h3>
                        <span id="route-branch-count" class="text-xs font-semibold text-amber-700">0 krakova</span>
                    </div>
                    <div class="grid grid-cols-2 gap-2">
                        <label class="grid gap-1 text-xs text-amber-900">Tip
                            <select id="route-draw-type" class="rounded-md border border-amber-200 bg-white px-2 py-2 text-sm text-zinc-800">
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
                            <select id="route-draw-installation" class="rounded-md border border-amber-200 bg-white px-2 py-2 text-sm text-zinc-800"><option value="underground">Podzemna</option><option value="aerial">Zracna</option></select>
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
                    <button type="button" id="save-draft" class="action-btn rounded-md border border-emerald-200 bg-emerald-50 px-4 py-2 text-sm font-semibold text-emerald-800">Sacuvaj radnu verziju</button>
                    <button class="action-btn rounded-md bg-emerald-600 px-4 py-2 text-sm font-semibold text-white hover:bg-emerald-700">Sacuvaj na mapi</button>
                </div>
            </div>
            <div id="bulk-plan-status" class="mt-2 text-sm font-semibold text-emerald-800"></div>
        </form>

        <details class="control-panel rounded-md">
            <summary class="cursor-pointer list-none px-3 py-2 text-sm font-semibold">Automatski raspored FTTH</summary>
        <div class="border-t border-zinc-100 p-3">
            <div class="flex items-center justify-between gap-3">
                <h2 class="font-semibold">Automatski raspored</h2>
                <button type="button" id="suggest-cabinets" class="action-btn rounded-md bg-violet-600 px-4 py-2 text-sm font-semibold text-white hover:bg-violet-700">Predlozi FTTH</button>
            </div>
            <div class="mt-2 grid grid-cols-4 gap-2">
                <label class="grid gap-1 text-xs text-zinc-600">Min<input id="planner-min" type="number" min="1" max="12" value="8" class="rounded-md border border-zinc-300 px-2 py-2 text-sm"></label>
                <label class="grid gap-1 text-xs text-zinc-600">Max<input id="planner-max" type="number" min="1" max="12" value="12" class="rounded-md border border-zinc-300 px-2 py-2 text-sm"></label>
                <label class="grid gap-1 text-xs text-zinc-600">Max m<input id="planner-max-drop" type="number" min="20" value="90" class="rounded-md border border-zinc-300 px-2 py-2 text-sm"></label>
                <button type="button" id="clear-suggestions" class="action-btn mt-5 rounded-md border border-zinc-300 bg-white px-3 py-2 text-sm font-semibold">Ocisti</button>
            </div>
            <div id="suggestion-output" class="mt-3 max-h-56 overflow-auto rounded-md border border-zinc-100 bg-zinc-50 p-3 text-sm text-zinc-700">Nacrtaj trasu i oznaci kuce.</div>
            <button type="button" id="save-suggestions" class="action-btn mt-3 hidden w-full rounded-md bg-violet-600 px-4 py-2 text-sm font-semibold text-white hover:bg-violet-700">Potvrdi raspored</button>
        </div>
        </details>

        <details class="control-panel rounded-md">
            <summary class="cursor-pointer list-none px-3 py-2 text-sm font-semibold">Napredne forme za pojedinacno snimanje</summary>
            <div class="grid gap-3 border-t border-zinc-100 p-3">
                <form method="POST" action="{{ route('odfs.store') }}" id="odf-form" class="grid gap-3 rounded-md border border-cyan-100 bg-cyan-50 p-3">
                    @csrf
                    <h3 class="font-semibold text-cyan-900">Sacuvaj ODF</h3>
                    <select name="project_id" class="rounded-md border border-zinc-300 px-3 py-2 text-sm" required><option value="">Projekat</option>@foreach($projects as $project)<option value="{{ $project->id }}">{{ $project->name }}</option>@endforeach</select>
                    <input name="name" class="rounded-md border border-zinc-300 px-3 py-2 text-sm" placeholder="Naziv ODF-a" required>
                    <input name="address" class="rounded-md border border-zinc-300 px-3 py-2 text-sm" placeholder="Adresa" required>
                    <input type="number" name="fiber_capacity" value="144" min="1" class="rounded-md border border-zinc-300 px-3 py-2 text-sm" required>
                    <input type="number" name="port_count" value="48" min="1" class="rounded-md border border-zinc-300 px-3 py-2 text-sm" required>
                    <div class="grid grid-cols-2 gap-2"><input id="odf-lat" name="latitude" class="rounded-md border border-zinc-300 px-3 py-2 text-sm" placeholder="Lat" required><input id="odf-lng" name="longitude" class="rounded-md border border-zinc-300 px-3 py-2 text-sm" placeholder="Lng" required></div>
                    <button class="rounded-md bg-cyan-600 px-4 py-2 text-sm font-semibold text-white">Sacuvaj ODF</button>
                </form>
                <form method="POST" action="{{ route('cabinets.store') }}" id="cabinet-form" class="grid gap-3 rounded-md border border-emerald-100 bg-emerald-50 p-3">
                    @csrf
                    <h3 class="font-semibold text-emerald-900">Sacuvaj FTTH ormaric</h3>
                    <select name="project_id" class="rounded-md border border-zinc-300 px-3 py-2 text-sm" required><option value="">Projekat</option>@foreach($projects as $project)<option value="{{ $project->id }}">{{ $project->name }}</option>@endforeach</select>
                    <select name="odf_id" class="rounded-md border border-zinc-300 px-3 py-2 text-sm"><option value="">Povezani ODF</option>@foreach($odfsForSelect as $odf)<option value="{{ $odf->id }}">{{ $odf->name }} - {{ $odf->project->name }}</option>@endforeach</select>
                    <input name="name" class="rounded-md border border-zinc-300 px-3 py-2 text-sm" placeholder="Naziv, npr. FTTH-001" required>
                    <input name="address" class="rounded-md border border-zinc-300 px-3 py-2 text-sm" placeholder="Adresa" required>
                    <div class="grid grid-cols-2 gap-2"><input type="number" name="splitter_count" value="3" min="1" max="3" class="rounded-md border border-zinc-300 px-3 py-2 text-sm"><input type="number" name="ports_per_splitter" value="4" min="1" max="4" class="rounded-md border border-zinc-300 px-3 py-2 text-sm"></div>
                    <div class="grid grid-cols-2 gap-2"><input id="cabinet-lat" name="latitude" class="rounded-md border border-zinc-300 px-3 py-2 text-sm" placeholder="Lat" required><input id="cabinet-lng" name="longitude" class="rounded-md border border-zinc-300 px-3 py-2 text-sm" placeholder="Lng" required></div>
                    <button class="rounded-md bg-emerald-600 px-4 py-2 text-sm font-semibold text-white">Sacuvaj FTTH</button>
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
const data = @json($mapData);
const defaultCenter = [44.4493, 18.6498];
const map = L.map('network-map', { zoomSnap: 0.25 }).setView(defaultCenter, 17);
let mode = 'pan';
let activeBranch = [];
let activeBranchMarkers = [];
let activeBranchLine = null;
let previewBranchLine = null;
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
let suggestedCabinets = [];
let expandedMap = false;
let activeDraftOdfIndex = null;
let cadContext = null;
let autosaveTimer = null;
let autosaveReady = false;
let restoringDraft = false;
let selectedDraftElement = null;
let keepCurrentDraftOnProjectChange = false;
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

const cabinetPalette = ['#2563eb', '#dc2626', '#16a34a', '#9333ea', '#ea580c', '#0891b2'];
function cabinetColor(id) { return cabinetPalette[(Math.max(Number(id) || 1, 1) - 1) % cabinetPalette.length]; }
const cabinetLegend = L.control({ position: 'bottomright' });
cabinetLegend.onAdd = () => {
    const box = L.DomUtil.create('div', 'rounded-md bg-white/95 p-2 text-xs shadow');
    box.innerHTML = `<b>ODO boje</b>${data.cabinets.map(c => `<div class="mt-1 flex items-center gap-1"><span style="width:10px;height:10px;border-radius:3px;background:${cabinetColor(c.id)}"></span>${c.name}</div>`).join('') || '<div class="mt-1 text-zinc-500">Nema spremljenih ODO</div>'}`;
    return box;
};
cabinetLegend.addTo(map);

function icon(type, text = '', color = null) {
    const cls = type === 'odf' ? 'odf' : type === 'cabinet' ? 'cabinet' : type === 'suggest' ? 'suggest' : 'house';
    const style = color ? ` style="background:${color}"` : '';
    return L.divIcon({ className: 'ftth-label', html: `<div class="ftth-tag ${cls}"${style}>${text}</div>`, iconSize: [2, 2], iconAnchor: [1, 1] });
}

const bounds = [];
data.routes.forEach(route => {
    if (!route.path?.length) return;
    const points = route.path.map(point => L.latLng(point[0], point[1]));
    const line = L.polyline(points, { color: route.cabinet_id ? cabinetColor(route.cabinet_id) : routeColor(route.type), weight: 4, opacity: .9 })
        .bindPopup(`<b>${route.name}</b><br>${routeTypeLabel(route.type)}<br>${route.duct_length_m} m`)
        .addTo(map);
    const labels = addRouteLabel(points, route.name, false);
    registerSavedContext([line, ...labels], route.name, deleteUrls.route(route.id));
    points.forEach(p => bounds.push([p.lat, p.lng]));
});
data.odfs.forEach(odf => {
    const p = L.latLng(odf.lat, odf.lng);
    const connectedCabinets = data.cabinets.filter(c => c.odf === odf.name).length;
    const marker = L.marker(p, { icon: icon('odf', 'ODF'), draggable: false })
        .bindTooltip(`${odf.name} · ${connectedCabinets} FTTH`, { direction: 'top', offset: [0, -10] })
        .bindPopup(`<b>ODF: ${odf.name}</b><br>${odf.address}<br>FTTH ormarica: ${connectedCabinets}`)
        .addTo(map);
    marker.on('click', event => {
        if (mode === 'draw') {
            map.closePopup();
            addDrawPoint(event.latlng);
        }
    });
    registerSavedContext(marker, `ODF: ${odf.name}`, deleteUrls.odf(odf.id), positionUrls.odf(odf.id));
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
        if (mode === 'draw') {
            map.closePopup();
            addDrawPoint(event.latlng);
        }
    });
    registerSavedContext(marker, c.name, deleteUrls.cabinet(c.id), positionUrls.cabinet(c.id));
    bounds.push([c.lat, c.lng]);
});
    const savedHouseKeys = new Set();
    const savedHouseColorByKey = {};
function pointKey(lat, lng) { return `${Number(lat).toFixed(7)},${Number(lng).toFixed(7)}`; }
    data.houses.forEach(h => {
        const p = L.latLng(h.lat, h.lng);
        const key = pointKey(h.lat, h.lng);
        const color = h.cabinet_id ? cabinetColor(h.cabinet_id) : null;
        savedHouseKeys.add(key);
        savedHouseColorByKey[key] = color;
        const marker = L.marker(p, { icon: icon('house', '', color), draggable: false }).bindPopup(`<b>${h.label}</b><br>ODO: ${h.cabinet}`).addTo(map);
        registerSavedContext(marker, h.label, deleteUrls.house(h.id), positionUrls.house(h.id));
        houseMarkerByKey[key] = marker;
    housePoints.push(p);
    bounds.push([h.lat, h.lng]);
});
const savedHouseCount = housePoints.length;
if (bounds.length) map.fitBounds(bounds, { padding: [40, 40], maxZoom: 19 }); else map.setView(defaultCenter, 17);

function setMode(next) {
    mode = next;
    document.querySelectorAll('.tool-btn').forEach(btn => btn.classList.remove('ring-2', 'ring-zinc-900'));
    document.getElementById(`mode-${next}`).classList.add('ring-2', 'ring-zinc-900');
    const labels = {
        pan: 'PAN: pomjeraj mapu. Izaberi alat za crtanje.',
        odf: 'ODF: klikni lokaciju centrale/cvora. Novi ODF postaje aktivan.',
        cabinet: 'FTTH: klikni lokacije zelenih ormarica. Vezuju se na aktivni ODF.',
        house: 'KUCE: klikni svaku kucu/prikljucak. CTRL+Z vraca zadnju.',
        draw: 'TRASA: klik po klik crtaj trasu. ENTER ili desni klik zavrsava krak, ESC prekida.',
    };
    document.getElementById('cad-command').textContent = labels[next];
}
['pan','odf','cabinet','house','draw'].forEach(m => document.getElementById(`mode-${m}`).addEventListener('click', () => setMode(m)));

function distance(points) { return Math.round(points.slice(1).reduce((sum, p, i) => sum + map.distance(points[i], p), 0)); }
function allNetworkPoints() { return [...branches, activeBranch].filter(b => b.length > 1); }
function allDistance() { return allNetworkPoints().reduce((sum, b) => sum + distance(b), 0); }
function routeTypeLabel(type) {
    return type === 'feeder' ? 'Primarni' : type === 'drop' ? 'Drop' : 'Sekundarni';
}
function routeColor(type) {
    return type === 'feeder' ? '#0ea5e9' : type === 'drop' ? '#7c3aed' : '#f59e0b';
}
function nextRouteName(type) {
    const prefix = type === 'feeder' ? 'P' : type === 'drop' ? 'D' : 'S';
    const count = branchMeta.filter(meta => meta.route_type === type).length + 1;
    return `${prefix}-${String(count).padStart(2, '0')}`;
}
function currentRouteDraftMeta() {
    const type = document.getElementById('route-draw-type').value;
    const manualName = document.getElementById('route-draw-name').value.trim();
    return {
        name: manualName || nextRouteName(type),
        route_type: type,
        installation_type: document.getElementById('route-draw-installation').value,
        microduct_type: document.getElementById('route-draw-microduct-type').value,
        fiber_count: Number(document.getElementById('route-draw-fiber-count').value || 12),
        microduct_count: Math.max(1, Number(document.getElementById('route-draw-microducts').value || 1)),
        odf_index: activeDraftOdfIndex,
    };
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
    const buttons = actions.map((action, index) => `<button type="button" data-cad-action="${index}" class="block w-full rounded px-3 py-2 text-left text-sm font-semibold hover:bg-zinc-100">${action.label}</button>`).join('');
    L.popup()
        .setLatLng(latlng)
        .setContent(`<div class="min-w-[150px]"><div class="border-b border-zinc-200 px-3 py-2 text-sm font-semibold">${title}</div><div class="p-1">${buttons}</div></div>`)
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
    map.removeLayer(marker);
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
    refreshDraftTooltips();
    refreshPlanSummary();
}
function registerDraftContext(marker, title) {
    marker.on('contextmenu', event => {
        L.DomEvent.stop(event);
        showCadContext(event.latlng, title, [
            { label: 'Obrisi', run: () => removeDraftElement(marker) },
            { label: 'Premjesti: povuci marker misem', run: () => marker.dragging?.enable() },
        ]);
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
    marker.on('contextmenu', event => {
        L.DomEvent.stop(event);
        showCadContext(event.latlng, 'Kuca', [
            { label: 'Obrisi', run: () => removeDraftHouse(marker) },
            { label: 'Premjesti: povuci marker misem', run: () => marker.dragging?.enable() },
        ]);
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
    line.on('contextmenu', event => {
        L.DomEvent.stop(event);
        const index = branchLines.indexOf(line);
        if (index < 0) return;
        showCadContext(event.latlng, branchMeta[index]?.name || 'Krak trase', [
            { label: 'Obrisi krak', run: () => removeBranchAt(index) },
        ]);
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

    if (!response.ok) throw new Error(await response.text() || 'Pomjeranje nije sacuvano.');
    marker.dragging?.disable();
    document.getElementById('cad-command').textContent = 'Nova pozicija je sacuvana.';
}
function registerSavedContext(layer, title, url, positionUrl = null) {
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
    triggerLayer.on('contextmenu', event => {
        L.DomEvent.stop(event);
        const actions = [
            { label: 'Obrisi', run: () => deleteSavedElement(url, layer) },
        ];
        if (positionUrl) actions.push({ label: 'Premjesti: povuci marker misem', run: () => triggerLayer.dragging?.enable() });
        showCadContext(event.latlng, title, [
            ...actions,
        ]);
    });
}
function refreshStats() {
    const d = allDistance();
    document.getElementById('draw-length').textContent = `${d} m`;
    document.getElementById('route-duct').value = d;
    document.getElementById('route-fiber').value = d;
    document.getElementById('route-path').value = JSON.stringify(allNetworkPoints()[0]?.map(p => [Number(p.lat.toFixed(7)), Number(p.lng.toFixed(7))]) || []);
    document.getElementById('house-count').textContent = Math.max(housePoints.length - savedHouseCount, 0);
    refreshPlanSummary();
}
function syncRoutePathInput() {
    const merged = allNetworkPoints().flatMap((branch, index) => {
        const points = branch.map(p => [Number(p.lat.toFixed(7)), Number(p.lng.toFixed(7))]);
        return index === 0 ? points : [[null, null], ...points];
    }).filter(point => point[0] !== null);
    document.getElementById('route-path').value = JSON.stringify(merged);
}
function redrawActiveBranch() {
    if (activeBranchLine) map.removeLayer(activeBranchLine);
    if (activeBranch.length > 1) activeBranchLine = L.polyline(activeBranch, { color: '#f59e0b', weight: 5, opacity: .9 }).addTo(map);
    refreshStats();
    syncRoutePathInput();
}
function redrawPreviewBranch(latlng = null) {
    if (mode !== 'draw' || !latlng || !activeBranch.length) {
        if (previewBranchLine) map.removeLayer(previewBranchLine);
        previewBranchLine = null;
        return;
    }
    const points = [activeBranch[activeBranch.length - 1], latlng];
    if (previewBranchLine) {
        previewBranchLine.setLatLngs(points);
        return;
    }
    previewBranchLine = L.polyline(points, { color: '#f59e0b', weight: 3, opacity: .65, dashArray: '4 8' }).addTo(map);
}
function addDrawPoint(latlng) {
    if (previewBranchLine) map.removeLayer(previewBranchLine);
    previewBranchLine = null;
    activeBranch.push(latlng);
    const index = activeBranch.length - 1;
    const marker = L.marker(latlng, { draggable: true, icon: L.divIcon({ className: 'ftth-label', html: '<div style="width:12px;height:12px;border-radius:999px;background:#f59e0b;border:2px solid #fff;box-shadow:0 1px 5px rgba(0,0,0,.35)"></div>', iconAnchor: [6, 6] }) }).addTo(map);
    marker.on('drag', event => {
        activeBranch[index] = event.target.getLatLng();
        redrawActiveBranch();
    });
    activeBranchMarkers.push(marker);
    redrawActiveBranch();
    document.getElementById('cad-command').textContent = `TRASA: tacka ${activeBranch.length}. Sljedeci klik nastavlja, ENTER/desni klik zavrsava krak.`;
}
function finishBranch() {
    if (activeBranch.length > 1) {
        const meta = currentRouteDraftMeta();
        const meters = distance(activeBranch);
        branches.push([...activeBranch]);
        branchMeta.push({
            ...meta,
            duct_length_m: meters,
            fiber_length_m: meters,
            path: activeBranch.map(p => [Number(p.lat.toFixed(7)), Number(p.lng.toFixed(7))]),
        });
        const odfLabel = meta.odf_index === null || meta.odf_index === undefined ? 'bez ODF' : `ODF-${String(meta.odf_index + 1).padStart(2, '0')}`;
        const line = L.polyline(activeBranch, { color: routeColor(meta.route_type), weight: 4 }).bindPopup(`<b>${meta.name}</b><br>${routeTypeLabel(meta.route_type)}<br>${odfLabel}<br>${meters} m`).addTo(map);
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
    activeBranch = []; activeBranchMarkers = []; activeBranchLine = null; previewBranchLine = null; refreshStats();
}
function clearDraw() { [...branchLines, ...branchLabels, ...activeBranchMarkers].forEach(l => map.removeLayer(l)); if (activeBranchLine) map.removeLayer(activeBranchLine); if (previewBranchLine) map.removeLayer(previewBranchLine); branches=[]; branchLines=[]; branchLabels=[]; branchMeta=[]; activeBranch=[]; activeBranchMarkers=[]; activeBranchLine=null; previewBranchLine=null; renderBranchList(); refreshStats(); }
function undoDraw() { const m = activeBranchMarkers.pop(); if (m) map.removeLayer(m); activeBranch.pop(); redrawActiveBranch(); }
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

function projectOnSegment(point, a, b) {
    const p = map.latLngToLayerPoint(point), pa = map.latLngToLayerPoint(a), pb = map.latLngToLayerPoint(b);
    const ab = pb.subtract(pa), ap = p.subtract(pa), den = ab.x*ab.x + ab.y*ab.y;
    const t = den ? Math.max(0, Math.min(1, (ap.x*ab.x + ap.y*ab.y) / den)) : 0;
    return map.layerPointToLatLng(L.point(pa.x + ab.x*t, pa.y + ab.y*t));
}
function nearestOnNetwork(point) {
    let best = null, bestDist = Infinity, chain = 0, passed = 0;
    for (const branch of allNetworkPoints()) {
        for (let i = 1; i < branch.length; i++) {
            const projected = projectOnSegment(point, branch[i-1], branch[i]);
            const dist = map.distance(point, projected);
            if (dist < bestDist) { best = projected; bestDist = dist; chain = passed + map.distance(branch[i-1], projected); }
            passed += map.distance(branch[i-1], branch[i]);
        }
    }
    return { point: best, chain };
}

function networkPathBetween(aPoint, bPoint) {
    const a = nearestOnNetwork(aPoint);
    const b = nearestOnNetwork(bPoint);
    if (!a.point || !b.point) return [aPoint, bPoint];
    return a.chain <= b.chain
        ? [aPoint, a.point, b.point, bPoint]
        : [aPoint, a.point, b.point, bPoint];
}

function networkDropDistance(cabinetPoint, housePoint) {
    const a = nearestOnNetwork(cabinetPoint);
    const b = nearestOnNetwork(housePoint);
    if (!a.point || !b.point) return map.distance(cabinetPoint, housePoint);
    return map.distance(cabinetPoint, a.point)
        + Math.abs(a.chain - b.chain)
        + map.distance(b.point, housePoint);
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
        Object.entries(houseMarkerByKey).forEach(([key, marker]) => marker.setIcon(icon('house', '', savedHouseColorByKey[key] || null)));
    suggestionLayers=[];
    suggestedCabinets=[];
    document.getElementById('cabinet-count').textContent='0';
    document.getElementById('suggestion-output').innerHTML='Nacrtaj trasu i oznaci kuce.';
    document.getElementById('save-suggestions').classList.add('hidden');
    document.getElementById('material-specs-output').classList.add('hidden');
    refreshPlanSummary();
}

function calculateMaterialSpecs() {
    const specs = {};

    // Spliters i ormarici
    const splitterTotal = suggestedCabinets.reduce((sum, c) => sum + c.splitter_count, 0);
    const cabinetCount = suggestedCabinets.length;

    specs['Spliteri 1:4'] = { quantity: splitterTotal, unit: 'kom', price: 160 };
    specs['Zeleni ormarici (FTTH)'] = { quantity: cabinetCount, unit: 'kom', price: 900 };

    // Mikrocijevi i kabl
    const totalDuct = allDistance();
    const microductCount = allNetworkPoints().length;
    const totalMicroduct = totalDuct * microductCount;
    const reserveMicroduct = Math.ceil(totalMicroduct * 1.1);

    specs['Mikrocijevi 14/10 (m)'] = { quantity: Math.ceil(reserveMicroduct / 1000), unit: '1km', price: 15000 };
    specs['Opticki kabl SM (m)'] = { quantity: Math.ceil(totalDuct * 1.1), unit: 'm', price: 12 };

    // Konektori i spajanja
    const spliceCount = splitterTotal + (housePoints.length - savedHouseCount);
    specs['Splice kasetne (kom)'] = { quantity: Math.ceil(spliceCount / 12), unit: 'kom', price: 450 };
    specs['Spojnice SC/APC'] = { quantity: housePoints.length - savedHouseCount, unit: 'kom', price: 8 };

    // Korisnički priključci
    specs['Opticki prikljucak ONT'] = { quantity: housePoints.length - savedHouseCount, unit: 'kom', price: 45 };

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
function suggest() {
    clearSuggestions();
    suggestedCabinets = [];
    if (!allNetworkPoints().length) { document.getElementById('suggestion-output').innerHTML='<b class="text-red-700">Prvo nacrtaj bar jedan krak trase.</b>'; return; }
    if (!housePoints.length) { document.getElementById('suggestion-output').innerHTML='<b class="text-red-700">Prvo oznaci kuce.</b>'; return; }
    const groups = assignHousesToNearestCabinets(optimize(housePoints), housePoints), summary = {};
    const html = groups.map((g,i) => {
        const groupColor = cabinetPalette[i % cabinetPalette.length];
        const cabinetPos = snapCabinetToRoute(g.pos);
        const odf = nearestOdf(cabinetPos); if (odf) { summary[odf.name] ??= {c:0,h:0,s:0}; summary[odf.name].c++; summary[odf.name].h += g.s; summary[odf.name].s += g.splitters; }
        const draftOdf = activeDraftOdfIndex !== null && draftOdfs[activeDraftOdfIndex]
            ? { index: activeDraftOdfIndex, distance: Math.round(map.distance(cabinetPos, draftOdfs[activeDraftOdfIndex].marker.getLatLng())) }
            : nearestDraftOdf(cabinetPos);
        const odfLabel = suggestionOdfLabel(draftOdf, odf);
        const assignedDraftOdfIndex = draftOdf ? draftOdf.index : null;
        const assignedExistingOdfId = !draftOdf && odf ? odf.id : null;
        const suggestedCabinet = {
            name: `FTTH-${String(i+1).padStart(3,'0')}`,
            lat: Number(cabinetPos.lat.toFixed(7)),
            lng: Number(cabinetPos.lng.toFixed(7)),
            splitter_count: g.splitters,
            odf_index: assignedDraftOdfIndex,
            odf_id: assignedExistingOdfId,
            houseKeys: g.group.map(h => pointKey(h.point.lat, h.point.lng)),
        };
        const marker = L.marker(cabinetPos, { icon: icon('suggest', `FTTH-${String(i+1).padStart(2,'0')}`, groupColor) })
            .bindTooltip(`${g.s}/12`, { direction: 'top', offset: [0, -10] })
            .bindPopup(`<b>FTTH-${i+1}</b><br>${g.s}/12 kuca<br>${g.splitters} splittera<br>${g.waste} praznih portova<br>ODF: ${odfLabel}`)
            .addTo(map);
        const drops = g.group.map(h => {
            const houseMarker = houseMarkerByKey[pointKey(h.point.lat, h.point.lng)];
            if (houseMarker) houseMarker.setIcon(icon('house', '', groupColor));
            return L.polyline(networkPathBetween(cabinetPos, h.point), { color: groupColor, weight: 1.5, opacity: .55 }).addTo(map);
        });
        suggestedCabinet.marker = marker;
        suggestedCabinet.dropLines = drops;
        suggestedCabinets.push(suggestedCabinet);
        suggestionLayers.push(marker, ...drops);
        return `<div class="border-b border-zinc-200 py-2"><b>FTTH-${String(i+1).padStart(2,'0')}</b><br>${g.s}/12 kuca, ${g.splitters} splittera, ${g.waste} praznih portova<br>ODF: ${odfLabel}<br>${cabinetPos.lat.toFixed(7)}, ${cabinetPos.lng.toFixed(7)}</div>`;
    }).join('');
    const sum = Object.entries(summary).map(([n,v]) => `<div class="rounded-md bg-white p-2"><b>${n}</b><br>${v.c} FTTH · ${v.h} kuca · ${v.s} splittera</div>`).join('');
    const draftOdfNote = '<div class="mb-2 rounded-md bg-emerald-50 p-2 text-xs font-semibold text-emerald-800">Pregled je spreman. Klikni "5 Sacuvaj na mapi" da elementi ostanu trajno prikazani.</div>';
    document.getElementById('cabinet-count').textContent = groups.length;
    document.getElementById('suggestion-output').innerHTML = `${draftOdfNote}${sum ? `<div class="mb-3 grid gap-2">${sum}</div>` : ''}${html}`;
    document.getElementById('save-suggestions').classList.remove('hidden');
    document.getElementById('material-specs-output').classList.remove('hidden');
    displayMaterialSpecs();
    refreshRouteOdfStatus();
    refreshDraftTooltips();
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

function defaultDraftName(type, index) {
    return type === 'odf' ? `ODF-${String(index + 1).padStart(2, '0')}` : `FTTH-M-${String(index + 1).padStart(3, '0')}`;
}
function selectDraftElement(type, item) {
    selectedDraftElement = { type, item };
    document.getElementById('element-editor').classList.remove('hidden');
    document.getElementById('element-editor-type').textContent = type === 'odf' ? 'ODF lokacija' : 'Draft FTTH ormaric';
    document.getElementById('element-editor-name').value = item.pending ? '' : item.name;
    document.getElementById('element-editor-status').textContent = item.pending
        ? 'Unos naziva je obavezan da bi ODF bio dodat.'
        : 'Upisi naziv i klikni Sacuvaj naziv.';
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
        ? `ODF "${name}" je sacuvan.`
        : `Naziv "${name}" je sacuvan.`;
    if (wasPendingOdf) {
        const savedItem = selectedDraftElement.item;
        setTimeout(() => {
            if (selectedDraftElement?.item === savedItem) closeDraftElementEditor();
        }, 450);
    }
}

function setActiveDraftOdf(index) {
    activeDraftOdfIndex = index === '' || index === null ? null : Number(index);
    document.getElementById('active-odf-index').value = activeDraftOdfIndex ?? '';
    document.getElementById('odf-link-status').textContent = activeDraftOdfIndex === null
        ? 'Postavi ODF ili odaberi postojeći draft ODF prije redanja FTTH ormarića.'
        : `Novi FTTH ormarići se vežu na ODF-${String(activeDraftOdfIndex + 1).padStart(2,'0')}.`;
    refreshDraftTooltips();
}

function renderDraftOdfPicker() {
    const select = document.getElementById('active-odf-index');
    select.innerHTML = draftOdfs.length
        ? draftOdfs.map((item, index) => `<option value="${index}">${item.name || defaultDraftName('odf', index)} (${draftOdfCabinetCount(index)} FTTH)</option>`).join('')
        : '<option value="">Prvo postavi ODF</option>';
    if (draftOdfs.length && (activeDraftOdfIndex === null || !draftOdfs[activeDraftOdfIndex])) activeDraftOdfIndex = draftOdfs.length - 1;
    select.value = activeDraftOdfIndex ?? '';
    document.getElementById('odf-link-status').textContent = activeDraftOdfIndex === null
        ? 'Postavi ODF, zatim postavljaj FTTH ormariće.'
        : `Novi FTTH ormarići se vežu na ODF-${String(activeDraftOdfIndex + 1).padStart(2,'0')}.`;
    refreshRouteOdfStatus();
}

function refreshDraftTooltips() {
    draftOdfs.forEach((item, index) => {
        item.marker.bindTooltip(`${item.name} · ${draftOdfCabinetCount(index)} FTTH`, { direction: 'top', offset: [0, -10] });
    });
    draftCabinets.forEach(item => {
        const label = item.odf_index === null || item.odf_index === undefined ? 'bez ODF' : `ODF-${String(item.odf_index + 1).padStart(2,'0')}`;
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
        return { name: item.name || defaultDraftName('cabinet', index), lat: Number(p.lat.toFixed(7)), lng: Number(p.lng.toFixed(7)), splitter_count: 3, odf_index: item.odf_index ?? null };
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
            microduct_type: meta.microduct_type || '14/10',
            fiber_count: meta.fiber_count || 12,
            odf_index: meta.odf_index ?? null,
            duct_length_m: meters,
            fiber_length_m: meters,
            microduct_count: meta.microduct_count || 1,
            path: branch.map(p => [Number(p.lat.toFixed(7)), Number(p.lng.toFixed(7))]),
        };
    });
    const routes = drawnRoutes;
    return { odfs, cabinets, houses, routes };
}

function refreshPlanSummary() {
    const payload = planPayload();
    document.getElementById('bulk-plan-json').value = JSON.stringify(payload);
    document.getElementById('bulk-plan-summary').textContent = `Draft: ${payload.odfs.length} ODF, ${payload.cabinets.length} FTTH, ${payload.houses.length} kuca, ${payload.routes.length} trasa.`;
    scheduleDraftAutosave();
}

function draftPayload() {
    return {
        odfs: draftOdfs.map((item, index) => {
            const p = item.marker.getLatLng();
            return { name: item.name || defaultDraftName('odf', index), lat: Number(p.lat.toFixed(7)), lng: Number(p.lng.toFixed(7)) };
        }),
        cabinets: draftCabinets.map((item, index) => {
            const p = item.marker.getLatLng();
            return { name: item.name || defaultDraftName('cabinet', index), lat: Number(p.lat.toFixed(7)), lng: Number(p.lng.toFixed(7)), odf_index: item.odf_index ?? null };
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
    };
}

function restoreDraft(payload) {
    if (!payload) return;
    restoringDraft = true;
    clearDraw();
    houseMarkers.forEach(marker => map.removeLayer(marker));
    houseMarkers = [];
    housePoints = data.houses.map(h => L.latLng(h.lat, h.lng));
    draftElements.forEach(item => map.removeLayer(item.marker));
    draftElements = [];
    draftOdfs = [];
    draftCabinets = [];
    activeDraftOdfIndex = null;
    clearSuggestions();

    (payload.branches || []).forEach((branch, index) => {
        const path = Array.isArray(branch) ? branch : (branch.path || []);
        const meta = Array.isArray(branch) ? { name: `Trasa ${index + 1}`, route_type: 'distribution', microduct_count: 1 } : (branch.meta || {});
        const points = path.map(point => L.latLng(point[0], point[1]));
        const meters = distance(points);
        const normalizedMeta = {
            name: meta.name || `Trasa ${index + 1}`,
            route_type: meta.route_type || 'distribution',
            microduct_count: meta.microduct_count || 1,
            odf_index: meta.odf_index ?? null,
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
        const item = { marker: null, name: Array.isArray(point) ? defaultDraftName('cabinet', index) : (point.name || defaultDraftName('cabinet', index)), odf_index: Array.isArray(point) ? (nearestDraftOdf(latLng)?.index ?? null) : (point.odf_index ?? null) };
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

    refreshDraftTooltips();
    refreshStats();
    restoringDraft = false;
}

function scheduleDraftAutosave() {
    if (!autosaveReady || restoringDraft || !document.getElementById('active-project-id').value) return;
    clearTimeout(autosaveTimer);
    const status = document.getElementById('bulk-plan-status');
    status.textContent = 'Izmjena spremna za automatsko cuvanje...';
    autosaveTimer = setTimeout(() => saveDraft({ quiet: true }).catch(error => {
        status.textContent = error.message;
    }), 700);
}

async function saveDraft({ quiet = false } = {}) {
    const projectId = document.getElementById('active-project-id').value;
    const status = document.getElementById('bulk-plan-status');
    if (!projectId) {
        status.textContent = 'Odaberi projekat prije cuvanja nacrta.';
        return;
    }

    const body = new FormData();
    body.append('_token', document.querySelector('#bulk-plan-form input[name="_token"]').value);
    body.append('project_id', projectId);
    body.append('draft', JSON.stringify(draftPayload()));
    status.textContent = quiet ? 'Automatski cuvam nacrt...' : 'Cuvam nacrt...';

    const response = await fetch('{{ route('map.draft.store') }}', {
        method: 'POST',
        headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
        body,
    });

    if (!response.ok) {
        status.textContent = await response.text();
        return;
    }

    const result = await readJsonResponse(response, 'Nacrt nije sacuvan.');
    draftsByProject[projectId] = draftPayload();
    status.textContent = quiet ? `Nacrt automatski sacuvan (${result.updated_at})` : `${result.message} (${result.updated_at})`;
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
document.getElementById('undo-draw').addEventListener('click', undoDraw);
document.getElementById('undo-branch').addEventListener('click', undoBranch);
document.getElementById('clear-draw').addEventListener('click', clearDraw);
document.getElementById('route-draw-type').addEventListener('change', event => {
    document.getElementById('route-draw-name').placeholder = `npr. ${nextRouteName(event.target.value)}`;
    document.getElementById('cad-command').textContent = `TRASA: aktivan tip ${routeTypeLabel(event.target.value)}. Klikni tacke na mapi.`;
});
document.getElementById('undo-house').addEventListener('click', () => { const m = houseMarkers.pop(); if(m) map.removeLayer(m); housePoints.pop(); refreshStats(); });
document.getElementById('undo-element').addEventListener('click', () => {
    const item = draftElements.pop();
    if (!item) return;
    if (selectedDraftElement?.item.marker === item.marker) closeDraftElementEditor();
    map.removeLayer(item.marker);
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
    if (!projectId) {
        output.innerHTML = '<b class="text-red-700">Odaberi projekat prije potvrde rasporeda.</b>';
        return;
    }
    if (!suggestedCabinets.length) {
        output.innerHTML = '<b class="text-red-700">Nema sugestija za potvrdu.</b>';
        return;
    }

    const btn = document.getElementById('save-suggestions');
    const originalText = btn.textContent;
    btn.disabled = true;
    btn.textContent = 'Snimam...';

    try {
        const response = await fetch('{{ route('map.suggestions.store') }}', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '',
                'Accept': 'application/json',
            },
            body: JSON.stringify({
                project_id: parseInt(projectId),
                cabinets: suggestedCabinets.map(c => ({
                    name: c.name,
                    latitude: c.lat,
                    longitude: c.lng,
                    splitter_count: c.splitter_count,
                    odf_id: c.odf_id,
                    houses: (c.houseKeys || []).map(key => {
                        const [latitude, longitude] = key.split(',').map(Number);
                        return { latitude, longitude };
                    }),
                })),
            }),
        });

        if (!response.ok) {
            const error = await response.text();
            output.innerHTML = `<b class="text-red-700">Greška pri snimanju: ${error}</b>`;
            return;
        }

        const result = await readJsonResponse(response, 'FTTH ormarici nisu snimljeni.');
        output.innerHTML = `<b class="text-emerald-700">${result.message}</b>`;
        keepSavedSuggestionsOnMap();
    } catch (error) {
        output.innerHTML = `<b class="text-red-700">Greška: ${error.message}</b>`;
    } finally {
        btn.disabled = false;
        btn.textContent = originalText;
    }
}

function keepSavedSuggestionsOnMap() {
    suggestedCabinets.forEach((cabinet, index) => {
        cabinet.dropLines?.forEach(line => map.removeLayer(line));
        cabinet.marker
            ?.setIcon(icon('cabinet', cabinet.name))
            .bindTooltip(`0/${cabinet.splitter_count * 4}`, { direction: 'top', offset: [0, -10] })
            .bindPopup(`<b>${cabinet.name}</b><br>Snimljen FTTH ormaric<br>${cabinet.splitter_count} splittera`);
    });

    suggestionLayers = suggestionLayers.filter(layer => !suggestedCabinets.some(cabinet => cabinet.dropLines?.includes(layer)));
    suggestedCabinets = [];
    document.getElementById('save-suggestions').classList.add('hidden');
    document.getElementById('suggestion-output').innerHTML = '<b class="text-emerald-700">FTTH ormarici su snimljeni i ostaju prikazani na shemi.</b>';
    refreshPlanSummary();
}

document.getElementById('save-suggestions').addEventListener('click', saveSuggestions);

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
        status.textContent = `${result.project.name} je kreiran, odabran i nacrt je sacuvan.`;
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

map.on('mousemove', e => {
    document.getElementById('cad-coordinates').textContent = `LAT ${e.latlng.lat.toFixed(7)}, LNG ${e.latlng.lng.toFixed(7)}`;
    redrawPreviewBranch(e.latlng);
});

map.on('contextmenu', e => {
    if (mode !== 'draw') return;
    e.originalEvent.preventDefault();
    finishBranch();
});

document.addEventListener('keydown', event => {
    if (event.key === 'Escape') {
        if (mode === 'draw') {
            activeBranchMarkers.forEach(marker => map.removeLayer(marker));
            if (activeBranchLine) map.removeLayer(activeBranchLine);
            if (previewBranchLine) map.removeLayer(previewBranchLine);
            activeBranch = [];
            activeBranchMarkers = [];
            activeBranchLine = null;
            previewBranchLine = null;
            refreshStats();
        }
        setMode('pan');
        return;
    }

    if (event.key === 'Enter' && mode === 'draw') {
        event.preventDefault();
        finishBranch();
        return;
    }

    if ((event.ctrlKey || event.metaKey) && event.key.toLowerCase() === 'z') {
        event.preventDefault();
        if (mode === 'draw') undoDraw();
        else if (mode === 'house') document.getElementById('undo-house').click();
        else document.getElementById('undo-element').click();
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
        const item = { marker: null, name: defaultDraftName('cabinet', draftCabinetCount - 1), odf_index: activeDraftOdfIndex ?? nearestDraftOdf(e.latlng)?.index ?? null };
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
        draftElements.forEach(item => map.removeLayer(item.marker));
        draftElements = [];
        draftOdfs = [];
        draftCabinets = [];
        activeDraftOdfIndex = null;
        clearSuggestions();
        refreshStats();
    }
});

// Load first project's draft on page load
if (document.querySelectorAll('#active-project-id option:not([value=""])').length > 0) {
    const firstProject = document.querySelector('#active-project-id option:not([value=""])');
    if (firstProject) {
        const projectId = firstProject.value;
        document.getElementById('active-project-id').value = projectId;
        setTimeout(() => {
            const draft = draftsByProject[projectId];
            if (draft) {
                restoreDraft(draft);
            }
        }, 500);
    }
}
</script>
@endsection
