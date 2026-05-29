@extends('ftth.layout')

@section('title', 'Mapa mreze')
@section('subtitle', 'Satelitski projektantski prikaz za ODF, FTTH ormarice, kuce i trase.')
@section('wide', '1')

@section('content')
<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css">

<style>
    .ftth-label { border: 0; background: transparent; }
    .ftth-tag { box-shadow: 0 1px 5px rgba(0,0,0,.35); border: 1.5px solid #fff; color: #fff; font: 700 9px/1 system-ui, sans-serif; display: grid; place-items: center; }
    .ftth-tag.odf { min-width: 30px; height: 18px; border-radius: 999px; background: #0891b2; }
    .ftth-tag.cabinet { min-width: 38px; height: 18px; border-radius: 5px; background: #059669; }
    .ftth-tag.house { width: 10px; height: 10px; border-radius: 999px; background: #7c3aed; font-size: 0; }
    .ftth-tag.suggest { min-width: 46px; height: 20px; border-radius: 5px; background: #f59e0b; color: #111827; }
</style>

<section id="map-workspace" class="grid min-h-0 flex-1 grid-rows-[auto_minmax(0,1fr)] gap-3">
    <aside class="grid shrink-0 gap-3 xl:grid-cols-[1.15fr_1fr_1fr_auto]">
        <div class="rounded-md border border-zinc-200 bg-white p-3 shadow-sm">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <h2 class="font-semibold">Projektantske komande</h2>
                <div class="grid grid-cols-3 gap-2 text-sm">
                    <div class="rounded-md bg-amber-50 px-3 py-2"><span class="text-zinc-500">Trasa</span> <strong id="draw-length" class="text-amber-800">0 m</strong></div>
                    <div class="rounded-md bg-violet-50 px-3 py-2"><span class="text-zinc-500">Kuce</span> <strong id="house-count" class="text-violet-800">0</strong></div>
                    <div class="rounded-md bg-emerald-50 px-3 py-2"><span class="text-zinc-500">Ormarici</span> <strong id="cabinet-count" class="text-emerald-800">0</strong></div>
                </div>
            </div>
            <div class="mt-2 flex flex-wrap gap-2">
                <button type="button" id="finish-branch" class="rounded-md bg-amber-500 px-3 py-2 text-sm font-semibold text-zinc-950">Zavrsi krak</button>
                <button type="button" id="undo-draw" class="rounded-md border border-zinc-300 px-3 py-2 text-sm font-semibold">Undo tacka</button>
                <button type="button" id="undo-branch" class="rounded-md border border-zinc-300 px-3 py-2 text-sm font-semibold">Undo krak</button>
                <button type="button" id="clear-draw" class="rounded-md border border-zinc-300 px-3 py-2 text-sm font-semibold">Ocisti trase</button>
                <button type="button" id="undo-element" class="rounded-md border border-zinc-300 px-3 py-2 text-sm font-semibold">Undo element</button>
                <button type="button" id="undo-house" class="rounded-md border border-zinc-300 px-3 py-2 text-sm font-semibold">Undo kuca</button>
            </div>
        </div>

        <form method="POST" action="{{ route('map.plan.store') }}" id="bulk-plan-form" class="rounded-md border border-emerald-200 bg-emerald-50 p-3 shadow-sm">
            @csrf
            <div class="flex items-center justify-between gap-3">
                <h2 class="font-semibold text-emerald-950">Plan projekta</h2>
                <span id="bulk-plan-summary" class="text-sm text-emerald-900">Draft: 0 ODF, 0 FTTH, 0 kuca, 0 trasa.</span>
            </div>
            <div class="mt-2 grid gap-2 lg:grid-cols-[1fr_auto_auto]">
                <select id="active-project-id" name="project_id" class="rounded-md border border-emerald-300 px-3 py-2 text-sm" required><option value="">Odaberi projekat</option>@foreach($projects as $project)<option value="{{ $project->id }}">{{ $project->name }}</option>@endforeach</select>
                <input id="bulk-plan-json" type="hidden" name="plan">
                <button type="button" id="save-draft" class="rounded-md border border-emerald-400 bg-white px-4 py-2 text-sm font-semibold text-emerald-900">Sacuvaj nacrt</button>
                <button class="rounded-md bg-emerald-700 px-4 py-2 text-sm font-semibold text-white hover:bg-emerald-800">Snimi sve</button>
            </div>
            <div id="bulk-plan-status" class="mt-1 text-sm font-semibold text-emerald-950"></div>
        </form>

        <div class="rounded-md border border-zinc-200 bg-white p-3 shadow-sm">
            <div class="flex items-center justify-between gap-3">
                <h2 class="font-semibold">Automatski raspored</h2>
                <button type="button" id="suggest-cabinets" class="rounded-md bg-violet-600 px-4 py-2 text-sm font-semibold text-white hover:bg-violet-700">Predlozi FTTH</button>
            </div>
            <div class="mt-2 grid grid-cols-4 gap-2">
                <label class="grid gap-1 text-xs text-zinc-600">Min<input id="planner-min" type="number" min="1" max="12" value="8" class="rounded-md border border-zinc-300 px-2 py-2 text-sm"></label>
                <label class="grid gap-1 text-xs text-zinc-600">Max<input id="planner-max" type="number" min="1" max="12" value="12" class="rounded-md border border-zinc-300 px-2 py-2 text-sm"></label>
                <label class="grid gap-1 text-xs text-zinc-600">Drop<input id="planner-drop" type="number" min="0" value="1" class="rounded-md border border-zinc-300 px-2 py-2 text-sm"></label>
                <button type="button" id="clear-suggestions" class="mt-5 rounded-md border border-zinc-300 px-3 py-2 text-sm font-semibold">Ocisti</button>
            </div>
            <div id="suggestion-output" class="mt-2 max-h-16 overflow-auto rounded-md bg-zinc-50 p-2 text-sm text-zinc-700">Nacrtaj trasu i oznaci kuce.</div>
        </div>

        <details class="rounded-md border border-zinc-200 bg-white shadow-sm">
            <summary class="cursor-pointer list-none px-4 py-3 font-semibold">Snimanje elemenata</summary>
            <div class="grid gap-4 border-t border-zinc-100 p-5 xl:absolute xl:right-8 xl:z-[1000] xl:mt-2 xl:w-[390px] xl:bg-white xl:shadow-xl">
                <form method="POST" action="{{ route('odfs.store') }}" id="odf-form" class="grid gap-3 rounded-md bg-cyan-50 p-4">
                    @csrf
                    <h3 class="font-semibold text-cyan-900">Sacuvaj ODF</h3>
                    <select name="project_id" class="rounded-md border border-zinc-300 px-3 py-2 text-sm" required><option value="">Projekat</option>@foreach($projects as $project)<option value="{{ $project->id }}">{{ $project->name }}</option>@endforeach</select>
                    <input name="name" class="rounded-md border border-zinc-300 px-3 py-2 text-sm" placeholder="Naziv ODF-a" required>
                    <input name="address" class="rounded-md border border-zinc-300 px-3 py-2 text-sm" placeholder="Adresa" required>
                    <input type="number" name="fiber_capacity" value="144" min="1" class="rounded-md border border-zinc-300 px-3 py-2 text-sm" required>
                    <div class="grid grid-cols-2 gap-2"><input id="odf-lat" name="latitude" class="rounded-md border border-zinc-300 px-3 py-2 text-sm" placeholder="Lat" required><input id="odf-lng" name="longitude" class="rounded-md border border-zinc-300 px-3 py-2 text-sm" placeholder="Lng" required></div>
                    <button class="rounded-md bg-cyan-600 px-4 py-2 text-sm font-semibold text-white">Sacuvaj ODF</button>
                </form>
                <form method="POST" action="{{ route('cabinets.store') }}" id="cabinet-form" class="grid gap-3 rounded-md bg-emerald-50 p-4">
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
            </div>
        </details>
    </aside>

    <div class="flex min-h-0 flex-col overflow-hidden rounded-md border border-zinc-200 bg-white shadow-sm">
        <div class="shrink-0 flex flex-wrap items-center justify-between gap-3 border-b border-zinc-200 px-4 py-2">
            <div>
                <h2 class="font-semibold">Radna karta</h2>
                <p class="text-sm text-zinc-500">Satelit, vise krakova trase i brzo redanje kuca.</p>
            </div>
            <div class="flex flex-wrap gap-2">
                <button type="button" id="mode-pan" class="tool-btn rounded-md bg-zinc-900 px-3 py-2 text-sm font-semibold text-white">Pomjeraj</button>
                <button type="button" id="mode-odf" class="tool-btn rounded-md border border-cyan-300 bg-cyan-50 px-3 py-2 text-sm font-semibold text-cyan-800">ODF</button>
                <button type="button" id="mode-cabinet" class="tool-btn rounded-md border border-emerald-300 bg-emerald-50 px-3 py-2 text-sm font-semibold text-emerald-800">FTTH</button>
                <button type="button" id="mode-house" class="tool-btn rounded-md border border-violet-300 bg-violet-50 px-3 py-2 text-sm font-semibold text-violet-800">Kuce</button>
                <button type="button" id="mode-draw" class="tool-btn rounded-md border border-amber-300 bg-amber-50 px-3 py-2 text-sm font-semibold text-amber-800">Trasa</button>
                <button type="button" id="expand-map" class="rounded-md border border-zinc-300 bg-white px-3 py-2 text-sm font-semibold text-zinc-700">Velika mapa</button>
            </div>
        </div>
        <div id="network-map" class="min-h-0 flex-1 w-full"></div>
    </div>
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
let branches = [];
let branchLines = [];
let trenchLines = [];
let housePoints = [];
let houseMarkers = [];
let suggestionLayers = [];
let draftOdfCount = 0;
let draftCabinetCount = 0;
let draftElements = [];
let draftOdfs = [];
let draftCabinets = [];
let suggestedCabinets = [];
let expandedMap = false;

const imagery = L.tileLayer('https://server.arcgisonline.com/ArcGIS/rest/services/World_Imagery/MapServer/tile/{z}/{y}/{x}', {
    maxNativeZoom: 18,
    maxZoom: 22,
    attribution: 'Tiles &copy; Esri'
}).addTo(map);

const osm = L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', { maxZoom: 22, maxNativeZoom: 19, attribution: '&copy; OpenStreetMap' });
L.control.layers({ 'Satelit': imagery, 'OpenStreetMap': osm }, {}, { position: 'bottomleft' }).addTo(map);

function icon(type, text = '') {
    const cls = type === 'odf' ? 'odf' : type === 'cabinet' ? 'cabinet' : type === 'suggest' ? 'suggest' : 'house';
    const anchors = { odf: [15, 9], cabinet: [19, 9], suggest: [23, 10], house: [5, 5] };
    return L.divIcon({ className: 'ftth-label', html: `<div class="ftth-tag ${cls}">${text}</div>`, iconAnchor: anchors[cls] });
}

const bounds = [];
data.routes.forEach(route => { L.polyline(route.path, { color: '#10b981', weight: 4 }).bindPopup(`<b>${route.name}</b><br>${route.duct_length_m} m`).addTo(map); route.path.forEach(p => bounds.push(p)); });
data.odfs.forEach(odf => {
    const p = L.latLng(odf.lat, odf.lng);
    const connectedCabinets = data.cabinets.filter(c => c.odf === odf.name).length;
    const marker = L.marker(p, { icon: icon('odf', 'ODF') })
        .bindTooltip(`ODF · ${connectedCabinets} FTTH`, { direction: 'top', offset: [0, -10] })
        .bindPopup(`<b>ODF: ${odf.name}</b><br>${odf.address}<br>FTTH ormarica: ${connectedCabinets}`)
        .addTo(map);
    marker.on('click', event => {
        if (mode === 'draw') {
            map.closePopup();
            addDrawPoint(event.latlng);
        }
    });
    bounds.push([odf.lat, odf.lng]);
});
data.cabinets.forEach(c => {
    const p = L.latLng(c.lat, c.lng);
    const marker = L.marker(p, { icon: icon('cabinet', c.name?.startsWith('FTTH') ? c.name : `FTTH ${c.id}`) })
        .bindTooltip(`${c.used_ports}/${c.capacity}`, { direction: 'top', offset: [0, -10] })
        .bindPopup(`<b>${c.name}</b><br>${c.used_ports}/${c.capacity} portova<br>ODF: ${c.odf}`)
        .addTo(map);
    marker.on('click', event => {
        if (mode === 'draw') {
            map.closePopup();
            addDrawPoint(event.latlng);
        }
    });
    bounds.push([c.lat, c.lng]);
});
data.houses.forEach(h => { const p = L.latLng(h.lat, h.lng); L.marker(p, { icon: icon('house') }).bindPopup(`<b>${h.label}</b>`).addTo(map); housePoints.push(p); bounds.push([h.lat, h.lng]); });
const savedHouseCount = housePoints.length;
if (bounds.length) map.fitBounds(bounds, { padding: [40, 40], maxZoom: 19 }); else map.setView(defaultCenter, 17);

function setMode(next) {
    mode = next;
    document.querySelectorAll('.tool-btn').forEach(btn => btn.classList.remove('ring-2', 'ring-zinc-900'));
    document.getElementById(`mode-${next}`).classList.add('ring-2', 'ring-zinc-900');
}
['pan','odf','cabinet','house','draw'].forEach(m => document.getElementById(`mode-${m}`).addEventListener('click', () => setMode(m)));

function distance(points) { return Math.round(points.slice(1).reduce((sum, p, i) => sum + map.distance(points[i], p), 0)); }
function allNetworkPoints() { return [...branches, activeBranch].filter(b => b.length > 1); }
function allDistance() { return allNetworkPoints().reduce((sum, b) => sum + distance(b), 0); }
function refreshStats() {
    const d = allDistance();
    document.getElementById('draw-length').textContent = `${d} m`;
    document.getElementById('route-duct').value = d;
    document.getElementById('route-fiber').value = d;
    document.getElementById('route-path').value = JSON.stringify(allNetworkPoints()[0]?.map(p => [Number(p.lat.toFixed(7)), Number(p.lng.toFixed(7))]) || []);
    document.getElementById('house-count').textContent = housePoints.length;
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
    if (activeBranch.length > 1) activeBranchLine = L.polyline(activeBranch, { color: '#f59e0b', weight: 5, dashArray: '8 8' }).addTo(map);
    refreshStats();
    syncRoutePathInput();
}
function addDrawPoint(latlng) {
    activeBranch.push(latlng);
    const index = activeBranch.length - 1;
    const marker = L.marker(latlng, { draggable: true, icon: L.divIcon({ className: 'ftth-label', html: '<div style="width:12px;height:12px;border-radius:999px;background:#f59e0b;border:2px solid #fff;box-shadow:0 1px 5px rgba(0,0,0,.35)"></div>', iconAnchor: [6, 6] }) }).addTo(map);
    marker.on('drag', event => {
        activeBranch[index] = event.target.getLatLng();
        redrawActiveBranch();
    });
    activeBranchMarkers.push(marker);
    redrawActiveBranch();
}
function finishBranch() {
    if (activeBranch.length > 1) {
        branches.push([...activeBranch]);
        branchLines.push(L.polyline(activeBranch, { color: '#f59e0b', weight: 4 }).addTo(map));
    }
    activeBranchMarkers.forEach(m => map.removeLayer(m));
    if (activeBranchLine) map.removeLayer(activeBranchLine);
    activeBranch = []; activeBranchMarkers = []; activeBranchLine = null; refreshStats();
}
function clearDraw() { [...branchLines, ...activeBranchMarkers].forEach(l => map.removeLayer(l)); if (activeBranchLine) map.removeLayer(activeBranchLine); branches=[]; branchLines=[]; activeBranch=[]; activeBranchMarkers=[]; activeBranchLine=null; refreshStats(); }
function undoDraw() { const m = activeBranchMarkers.pop(); if (m) map.removeLayer(m); activeBranch.pop(); redrawActiveBranch(); }
function undoBranch() {
    const line = branchLines.pop();
    if (line) map.removeLayer(line);
    branches.pop();
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
function nearestOdf(point) {
    return data.odfs.map(o => ({...o, distance: Math.round(map.distance(point, L.latLng(o.lat, o.lng)))})).sort((a,b) => a.distance-b.distance)[0] || null;
}
function optimize(points) {
    const min = Math.max(1, Math.min(12, Number(document.getElementById('planner-min').value || 8)));
    const max = Math.max(min, Math.min(12, Number(document.getElementById('planner-max').value || 12)));
    const drop = Math.max(0, Number(document.getElementById('planner-drop').value || 1));
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
function clearSuggestions() { suggestionLayers.forEach(l => map.removeLayer(l)); suggestionLayers=[]; suggestedCabinets=[]; document.getElementById('cabinet-count').textContent='0'; document.getElementById('suggestion-output').innerHTML='Nacrtaj trasu i oznaci kuce.'; refreshPlanSummary(); }
function suggest() {
    clearSuggestions();
    suggestedCabinets = [];
    if (!allNetworkPoints().length) { document.getElementById('suggestion-output').innerHTML='<b class="text-red-700">Prvo nacrtaj bar jedan krak trase.</b>'; return; }
    if (!housePoints.length) { document.getElementById('suggestion-output').innerHTML='<b class="text-red-700">Prvo oznaci kuce.</b>'; return; }
    const groups = optimize(housePoints), summary = {};
    const html = groups.map((g,i) => {
        const odf = nearestOdf(g.pos); if (odf) { summary[odf.name] ??= {c:0,h:0,s:0}; summary[odf.name].c++; summary[odf.name].h += g.s; summary[odf.name].s += g.splitters; }
        const draftOdf = nearestDraftOdf(g.pos);
        const assignedDraftOdfIndex = draftOdf ? draftOdf.index : null;
        const assignedExistingOdfId = !draftOdf && odf ? odf.id : null;
        suggestedCabinets.push({
            name: `FTTH-${String(i+1).padStart(3,'0')}`,
            lat: Number(g.pos.lat.toFixed(7)),
            lng: Number(g.pos.lng.toFixed(7)),
            splitter_count: g.splitters,
            odf_index: assignedDraftOdfIndex,
            odf_id: assignedExistingOdfId,
        });
        const marker = L.marker(g.pos, { icon: icon('suggest', `FTTH-${String(i+1).padStart(2,'0')}`) })
            .bindTooltip(`${g.s}/12`, { direction: 'top', offset: [0, -10] })
            .bindPopup(`<b>FTTH-${i+1}</b><br>${g.s}/12 kuca<br>${g.splitters} splittera<br>${g.waste} praznih portova<br>ODF: ${draftOdf ? 'Draft ODF '+(draftOdf.index + 1) : (odf ? odf.name : '-')}`)
            .addTo(map);
        const drops = g.group.map(h => L.polyline([g.pos, h.point], { color:'#7c3aed', weight:1, opacity:.35 }).addTo(map));
        suggestionLayers.push(marker, ...drops);
        return `<div class="border-b border-zinc-200 py-2"><b>FTTH-${String(i+1).padStart(2,'0')}</b><br>${g.s}/12 kuca, ${g.splitters} splittera, ${g.waste} praznih portova<br>ODF: ${odf ? odf.name + ' (' + odf.distance + ' m)' : 'nema'}<br>${g.pos.lat.toFixed(7)}, ${g.pos.lng.toFixed(7)}</div>`;
    }).join('');
    const sum = Object.entries(summary).map(([n,v]) => `<div class="rounded-md bg-white p-2"><b>${n}</b><br>${v.c} FTTH · ${v.h} kuca · ${v.s} splittera</div>`).join('');
    document.getElementById('cabinet-count').textContent = groups.length;
    document.getElementById('suggestion-output').innerHTML = `${sum ? `<div class="mb-3 grid gap-2">${sum}</div>` : ''}${html}`;
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

function refreshDraftTooltips() {
    draftOdfs.forEach((item, index) => {
        item.marker.bindTooltip(`ODF · ${draftOdfCabinetCount(index)} FTTH`, { direction: 'top', offset: [0, -10] });
    });
    draftCabinets.forEach(item => {
        item.marker.bindTooltip('0/12', { direction: 'top', offset: [0, -10] });
    });
}

function planPayload() {
    const odfs = draftOdfs.map((item, index) => {
        const p = item.marker.getLatLng();
        return { name: `ODF-${String(index+1).padStart(2,'0')}`, lat: Number(p.lat.toFixed(7)), lng: Number(p.lng.toFixed(7)), fiber_capacity: 144 };
    });
    const manualCabinets = draftCabinets.map((item, index) => {
        const p = item.marker.getLatLng();
        const nearest = nearestDraftOdf(p);
        return { name: `FTTH-M-${String(index+1).padStart(3,'0')}`, lat: Number(p.lat.toFixed(7)), lng: Number(p.lng.toFixed(7)), splitter_count: 3, odf_index: nearest ? nearest.index : null };
    });
    const cabinets = [...manualCabinets, ...suggestedCabinets];
    const houses = housePoints.slice(savedHouseCount).map((p, index) => ({ label: `K-${String(index+1).padStart(3,'0')}`, lat: Number(p.lat.toFixed(7)), lng: Number(p.lng.toFixed(7)) }));
    const routes = allNetworkPoints().map((branch, index) => {
        const meters = distance(branch);
        return { name: `Trasa ${index+1}`, duct_length_m: meters, fiber_length_m: meters, microduct_count: 1, path: branch.map(p => [Number(p.lat.toFixed(7)), Number(p.lng.toFixed(7))]) };
    });
    return { odfs, cabinets, houses, routes };
}

function refreshPlanSummary() {
    const payload = planPayload();
    document.getElementById('bulk-plan-json').value = JSON.stringify(payload);
    document.getElementById('bulk-plan-summary').textContent = `Draft: ${payload.odfs.length} ODF, ${payload.cabinets.length} FTTH, ${payload.houses.length} kuca, ${payload.routes.length} trasa.`;
}

function draftPayload() {
    return {
        odfs: draftOdfs.map(item => {
            const p = item.marker.getLatLng();
            return [Number(p.lat.toFixed(7)), Number(p.lng.toFixed(7))];
        }),
        cabinets: draftCabinets.map(item => {
            const p = item.marker.getLatLng();
            return [Number(p.lat.toFixed(7)), Number(p.lng.toFixed(7))];
        }),
        houses: housePoints.slice(savedHouseCount).map(p => [Number(p.lat.toFixed(7)), Number(p.lng.toFixed(7))]),
        branches: allNetworkPoints().map(branch => branch.map(p => [Number(p.lat.toFixed(7)), Number(p.lng.toFixed(7))])),
        suggestedCabinets,
    };
}

function restoreDraft(payload) {
    if (!payload) return;
    clearDraw();
    houseMarkers.forEach(marker => map.removeLayer(marker));
    houseMarkers = [];
    housePoints = data.houses.map(h => L.latLng(h.lat, h.lng));
    draftElements.forEach(item => map.removeLayer(item.marker));
    draftElements = [];
    draftOdfs = [];
    draftCabinets = [];
    clearSuggestions();

    (payload.branches || []).forEach(branch => {
        branches.push(branch.map(point => L.latLng(point[0], point[1])));
        branchLines.push(L.polyline(branches[branches.length - 1], { color: '#f59e0b', weight: 4 }).addTo(map));
    });

    (payload.houses || []).forEach((point, index) => {
        const latLng = L.latLng(point[0], point[1]);
        housePoints.push(latLng);
        const marker = L.marker(latLng, { icon: icon('house'), draggable: true }).bindPopup(`Kuca ${index + 1}`).addTo(map);
        marker.on('drag', event => { housePoints[index] = event.target.getLatLng(); refreshStats(); });
        houseMarkers.push(marker);
    });

    (payload.odfs || []).forEach((point, index) => {
        const latLng = L.latLng(point[0], point[1]);
        const marker = L.marker(latLng, { icon: icon('odf', 'ODF'), draggable: true }).bindTooltip('ODF · 0 FTTH', { direction: 'top', offset: [0, -10] }).addTo(map);
        marker.on('drag', () => { refreshDraftTooltips(); refreshPlanSummary(); });
        draftOdfs.push({ marker });
        draftElements.push({ type: 'odf', marker });
        draftOdfCount = Math.max(draftOdfCount, index + 1);
    });

    (payload.cabinets || []).forEach((point, index) => {
        const latLng = L.latLng(point[0], point[1]);
        const marker = L.marker(latLng, { icon: icon('cabinet', `FTTH-${index + 1}`), draggable: true }).bindTooltip('0/12', { direction: 'top', offset: [0, -10] }).addTo(map);
        marker.on('drag', () => {
            const item = draftCabinets.find(entry => entry.marker === marker);
            if (item) item.odf_index = nearestDraftOdf(marker.getLatLng())?.index ?? null;
            refreshDraftTooltips();
            refreshPlanSummary();
        });
        draftCabinets.push({ marker, odf_index: nearestDraftOdf(latLng)?.index ?? null });
        draftElements.push({ type: 'cabinet', marker });
        draftCabinetCount = Math.max(draftCabinetCount, index + 1);
    });

    refreshDraftTooltips();
    refreshStats();
}

async function saveDraft() {
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
    status.textContent = 'Cuvam nacrt...';

    const response = await fetch('{{ route('map.draft.store') }}', {
        method: 'POST',
        headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
        body,
    });

    if (!response.ok) {
        status.textContent = await response.text();
        return;
    }

    const result = await response.json();
    status.textContent = `${result.message} (${result.updated_at})`;
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
document.getElementById('undo-house').addEventListener('click', () => { const m = houseMarkers.pop(); if(m) map.removeLayer(m); housePoints.pop(); refreshStats(); });
document.getElementById('undo-element').addEventListener('click', () => {
    const item = draftElements.pop();
    if (!item) return;
    map.removeLayer(item.marker);
    if (item.type === 'odf') draftOdfs = draftOdfs.filter(entry => entry.marker !== item.marker);
    if (item.type === 'cabinet') draftCabinets = draftCabinets.filter(entry => entry.marker !== item.marker);
    refreshPlanSummary();
});
document.getElementById('suggest-cabinets').addEventListener('click', suggest);
document.getElementById('clear-suggestions').addEventListener('click', clearSuggestions);
document.getElementById('save-draft').addEventListener('click', saveDraft);
document.getElementById('active-project-id').addEventListener('change', event => {
    const draft = data.drafts.find(item => String(item.project_id) === String(event.target.value));
    if (draft) {
        restoreDraft(draft.payload);
        document.getElementById('bulk-plan-status').textContent = `Ucitani nacrt projekta (${draft.updated_at}).`;
    }
});
document.getElementById('bulk-plan-form').addEventListener('submit', async event => {
    event.preventDefault();
    refreshPlanSummary();
    await saveDraft();
    const form = event.currentTarget;
    const status = document.getElementById('bulk-plan-status');
    status.textContent = 'Snimam plan...';

    try {
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

        const result = await response.json();
        commitTrenchLines();
        clearDraw();
        status.textContent = `${result.message} Crna linija ostaje kao trag rova.`;
    } catch (error) {
        status.textContent = error.message;
    }
});
document.getElementById('expand-map').addEventListener('click', () => {
    expandedMap = !expandedMap;
    const workspace = document.getElementById('map-workspace');
    const mapEl = document.getElementById('network-map');
    const btn = document.getElementById('expand-map');
    if (expandedMap) {
        workspace.className = 'grid min-h-0 flex-1 grid-rows-[auto_minmax(0,1fr)] gap-2';
        mapEl.className = 'min-h-0 flex-1 w-full';
        btn.textContent = 'Manja mapa';
    } else {
        workspace.className = 'grid min-h-0 flex-1 grid-rows-[auto_minmax(0,1fr)] gap-3';
        mapEl.className = 'min-h-0 flex-1 w-full';
        btn.textContent = 'Velika mapa';
    }
    setTimeout(() => map.invalidateSize(), 100);
});

map.on('click', e => {
    const lat = e.latlng.lat.toFixed(7), lng = e.latlng.lng.toFixed(7);
    if (mode === 'draw') { addDrawPoint(e.latlng); return; }
    if (mode === 'house') {
        housePoints.push(e.latlng);
        const index = housePoints.length - 1;
        const marker = L.marker(e.latlng, { icon: icon('house'), draggable: true }).bindPopup(`Kuca ${housePoints.length}`).addTo(map);
        marker.on('drag', event => { housePoints[index] = event.target.getLatLng(); refreshStats(); });
        houseMarkers.push(marker);
        document.getElementById('house-lat').value=lat; document.getElementById('house-lng').value=lng; refreshStats(); return;
    }
    if (mode === 'odf') {
        draftOdfCount++;
        const marker = L.marker(e.latlng, { icon: icon('odf','ODF'), draggable: true })
            .addTo(map)
            .bindTooltip('ODF · 0 FTTH', { direction: 'top', offset: [0, -10] })
            .bindPopup(`ODF draft ${draftOdfCount}`)
            .openPopup();
        marker.on('dragend', event => { const p = event.target.getLatLng(); document.getElementById('odf-lat').value=p.lat.toFixed(7); document.getElementById('odf-lng').value=p.lng.toFixed(7); });
        marker.on('drag', () => {
            const item = draftCabinets.find(entry => entry.marker === marker);
            if (item) item.odf_index = nearestDraftOdf(marker.getLatLng())?.index ?? null;
            refreshDraftTooltips();
            refreshPlanSummary();
        });
        draftElements.push({ type: 'odf', marker });
        draftOdfs.push({ marker });
        refreshDraftTooltips();
        document.getElementById('odf-lat').value=lat; document.getElementById('odf-lng').value=lng; return;
    }
    if (mode === 'cabinet') {
        draftCabinetCount++;
        const marker = L.marker(e.latlng, { icon: icon('cabinet', `FTTH-${draftCabinetCount}`), draggable: true })
            .addTo(map)
            .bindTooltip('0/12', { direction: 'top', offset: [0, -10] })
            .bindPopup(`FTTH draft ${draftCabinetCount}`)
            .openPopup();
        marker.on('dragend', event => { const p = event.target.getLatLng(); document.getElementById('cabinet-lat').value=p.lat.toFixed(7); document.getElementById('cabinet-lng').value=p.lng.toFixed(7); });
        marker.on('drag', refreshPlanSummary);
        draftElements.push({ type: 'cabinet', marker });
        draftCabinets.push({ marker, odf_index: nearestDraftOdf(e.latlng)?.index ?? null });
        refreshDraftTooltips();
        document.getElementById('cabinet-lat').value=lat; document.getElementById('cabinet-lng').value=lng;
    }
});
setMode('pan'); refreshStats();
</script>
@endsection
