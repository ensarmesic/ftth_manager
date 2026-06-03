@extends('ftth.layout')
@section('title', 'Pregled mreže')
@section('subtitle', $activeProject ? $activeProject->name : 'FTTH Kalesija Centar')
@section('wide', '1')
@section('content')
<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css">
<link rel="stylesheet" href="{{ asset('css/ftth-dashboard.css') }}">
@php($selectedCabinet = $cabinets->first())
<div class="dashboard-root grid min-h-0 flex-1 gap-2 lg:grid-cols-[minmax(0,1fr)_250px] xl:grid-cols-[minmax(0,1fr)_270px]">
 <div class="dashboard-grid grid min-h-0 gap-2">
  <nav class="panel flex overflow-x-auto">
   <a class="tool active text-blue-700" data-tool="select" href="#">Odaberi</a>
   <a class="tool" data-tool="add_odf" href="#">Dodaj ODF</a>
   <a class="tool" data-tool="add_odo" href="#">Dodaj ODO</a>
   <a class="tool" data-tool="add_customer" href="#">Dodaj korisnika</a>
   <a class="tool" data-tool="draw_route" href="#">Nacrtaj trasu</a>
   <a class="tool" data-tool="measure" href="#">Mjerenje</a>
   <a class="tool" data-tool="edit_route" href="#">Uredi trasu</a>
   <a class="tool" data-tool="delete_node" href="#">Obriši tačku</a>
   <a class="tool text-red-600" data-tool="delete_element" href="#">Obriši element</a>
   <a class="tool text-violet-700" data-action="suggest-cabinets" href="#">Predlozi ODO</a>
  </nav>
  <section class="panel relative min-h-0 overflow-hidden"><div id="dashboard-map"></div>
   <div id="map-status" class="map-status">Alat: Odaberi | Odaberi element na mapi.</div>
   <div id="map-edit-actions" class="map-edit-actions hidden"><button type="button" data-action="save-edit">Sačuvaj izmjene</button><button type="button" data-action="cancel-edit">Poništi</button></div>
   <div id="map-move-actions" class="map-edit-actions hidden"><button type="button" data-action="save-position">Sačuvaj poziciju</button><button type="button" data-action="cancel-position">Poništi</button></div>
   <div id="map-draw-actions" class="map-edit-actions hidden"><button type="button" data-action="finish-route" disabled>Završi trasu</button><button type="button" data-action="undo-route-point">Undo tačka</button><button type="button" data-action="cancel-route">Odustani</button></div>
   <div id="map-toast" class="map-toast hidden"></div>
   <div class="map-legend"><h6>LEGENDA</h6><div><span class="legend-symbol text-blue-600">ODF</span></div><div><span class="legend-symbol text-green-600">ODO</span></div><div><span class="legend-symbol text-orange-500">K</span></div><div><span class="legend-line main"></span>Glavna trasa</div><div><span class="legend-line secondary"></span>Sekundarna trasa</div><div><span class="legend-line drop"></span>Drop trasa</div></div>
  </section>
  <div class="bottom-grid grid min-h-0 gap-2 md:grid-cols-2 lg:grid-cols-[1.16fr_.82fr_150px] xl:grid-cols-[1.16fr_.82fr_160px]">
   <section class="panel bottom-card overflow-hidden"><div class="border-b px-4 py-3 text-xs font-bold">TRASE</div><div class="table-scroll overflow-auto"><table class="data-table w-full text-left tiny"><thead><tr class="text-slate-600"><th>#</th><th>Od</th><th>Do</th><th>Tip</th><th>Dužina</th><th>MC</th><th>Kabl</th></tr></thead><tbody id="dashboard-routes-body">@forelse($routes->take(5) as $route)<tr class="border-t"><td>{{ $loop->iteration }}</td><td>{{ $route->odf->name ?? '-' }}</td><td>{{ $route->cabinet->name ?? '-' }}</td><td>{{ $route->route_type }}</td><td>{{ number_format($route->duct_length_m) }} m</td><td>{{ $route->microduct_type }}</td><td>{{ $route->fiber_count }} niti</td></tr>@empty<tr data-empty-row class="border-t"><td colspan="7" class="px-4 py-6 text-center text-slate-400">Nema trasa.</td></tr>@endforelse</tbody></table></div><div class="p-3 text-center"><a href="{{ route('routes.index') }}" class="inline-block rounded border px-10 py-2 tiny font-semibold text-blue-600">Prikaži sve trase</a></div></section>
   <section class="panel bottom-card overflow-hidden"><div class="border-b px-4 py-3 text-xs font-bold">KORISNICI (ZADNJI DODANI)</div><div class="table-scroll overflow-auto"><table class="user-table w-full text-left tiny"><thead><tr><th class="px-4 py-2">#</th><th>Naziv</th><th>ODO</th><th>Adresa</th><th>Status</th></tr></thead><tbody>@forelse($subscribers as $subscriber)<tr class="border-t"><td class="px-4 py-2">{{ $loop->iteration }}</td><td>{{ $subscriber->name }}</td><td>{{ $subscriber->cabinet->name ?? '-' }}</td><td>{{ $subscriber->address }}</td><td class="text-green-600">{{ $subscriber->service_status }}</td></tr>@empty<tr class="border-t"><td colspan="5" class="px-4 py-6 text-center text-slate-400">Nema korisnika.</td></tr>@endforelse</tbody></table></div><div class="p-3 text-center"><a href="{{ route('subscribers.index') }}" class="inline-block rounded border px-10 py-2 tiny font-semibold text-blue-600">Svi korisnici</a></div></section>
   <section class="panel p-3 md:col-span-2 lg:col-span-1"><h2 class="mb-3 text-[11px] font-bold">BRZA AKCIJA</h2><div class="grid gap-2 text-[11px] font-semibold sm:grid-cols-2 lg:grid-cols-1"><a href="{{ route('map.dashboard') }}" class="rounded bg-blue-600 px-3 py-2.5 text-white">Otvori mapu</a><a href="#" data-quick-tool="add_odf" class="rounded border px-3 py-2.5 text-blue-700">Dodaj ODF</a><a href="#" data-quick-tool="add_odo" class="rounded bg-green-600 px-3 py-2.5 text-white">Dodaj ODO ormarić</a><a href="#" data-quick-tool="add_customer" class="rounded bg-orange-500 px-3 py-2.5 text-white">Dodaj kuću</a><a href="#" data-quick-tool="draw_route" class="rounded bg-violet-600 px-3 py-2.5 text-white">Nacrtaj trasu</a><a href="#" data-action="suggest-cabinets" class="rounded border px-3 py-2.5 text-violet-700">Predlozi ODO</a></div></section>
  </div>
 </div>
 <aside class="detail-column grid min-h-0 gap-2 overflow-auto md:grid-cols-2 lg:grid-cols-1 lg:overflow-auto">
  <section id="detail-panel" class="panel overflow-hidden"><div id="detail-heading" class="flex items-center justify-between p-3"><div class="flex items-center gap-3"><span class="rounded bg-green-100 p-2 text-sm font-bold text-green-700">ODO</span><b>{{ $selectedCabinet->name ?? 'Nema ODO ormarića' }}</b></div>@if($selectedCabinet)<span class="rounded bg-green-100 px-2 py-1 text-[10px] font-bold text-green-700">Aktivan</span>@endif</div>
   <div id="detail-body">
            @if($selectedCabinet)<dl class="grid grid-cols-[90px_1fr] gap-y-1.5 px-3 tiny"><dt>Naziv:</dt><dd>{{ $selectedCabinet->name }}</dd><dt>Kod:</dt><dd>{{ $selectedCabinet->name }}</dd><dt>Povezan na:</dt><dd>{{ $selectedCabinet->odf->name ?? '-' }}</dd><dt>Lokacija:</dt><dd>{{ $activeProject->location ?? '-' }}</dd><dt>Korisnici:</dt><dd>{{ $selectedCabinet->houses_count }} / 12</dd><dt>Korištenje:</dt><dd class="flex items-center gap-2"><div class="h-2 flex-1 rounded bg-slate-100"><div class="h-2 rounded bg-green-500" style="width:{{ min($selectedCabinet->houses_count / 12 * 100,100) }}%"></div></div><b>{{ round($selectedCabinet->houses_count / 12 * 100) }}%</b></dd><dt>Splitteri:</dt><dd>{{ $selectedCabinet->splitter_count }} (1:4)</dd></dl>@else<p class="px-3 tiny text-slate-400">Dodaj ODO ormarić na mapu da se ovdje prikažu detalji.</p>@endif
   @if($selectedCabinet)<div class="mt-3 flex border-y tiny font-bold text-blue-600"><span class="border-b-2 border-blue-600 px-7 py-2">SPLITTERI</span><span class="px-5 py-2 text-slate-500">KORISNICI</span></div>@endif</div>
  </section>
   <section class="panel project-stats overflow-hidden"><h2>STATISTIKA PROJEKTA</h2><div class="project-stats-list">@foreach([['routes_m','Ukupna dužina trasa',$stats['routes_m']],['microduct_14_10','Mikrocijev 14/10',$stats['microduct_14_10']],['microduct_10_8','Mikrocijev 10/8',$stats['microduct_10_8']],['fiber_4','Optički kabal 4 niti',$stats['fiber_4']],['fiber_12','Optički kabal 12 niti',$stats['fiber_12']]] as $item)<div><span>{{ $item[1] }}</span><b data-stat="{{ $item[0] }}" data-value="{{ $item[2] }}">{{ number_format($item[2]/1000, 2) }} km</b></div>@endforeach @foreach([['splitters','Splitteri (1:4)',$stats['splitters']],['cabinets','ODO ormarići',$cabinets->count()],['houses','Kuće',$houses->count()],['subscribers','Korisnici',$stats['subscribers']]] as $item)<div><span>{{ $item[1] }}</span><b data-count-stat="{{ $item[0] }}">{{ $item[2] }}</b></div>@endforeach</div><a href="{{ route('reports.index') }}">Detaljan izvještaj</a></section>
 </aside>
</div>
<div id="suggestion-modal" class="route-modal hidden">
 <div class="route-modal-card">
  <div class="flex items-center justify-between"><h3 class="text-sm font-bold">Predloženi ODO ormarići</h3><button type="button" data-action="close-suggestions">X</button></div>
  <div id="suggestion-summary" class="col-span-full tiny"></div>
  <div class="col-span-full flex justify-end gap-2"><button type="button" class="secondary" data-action="close-suggestions">Odustani</button><button type="button" data-action="save-suggestions">Sačuvaj prijedlog</button></div>
 </div>
</div>
<div id="route-modal" class="route-modal hidden">
 <form id="route-form" class="route-modal-card">
  <div class="flex items-center justify-between"><h3 class="text-sm font-bold">Podaci trase</h3><button type="button" data-action="close-route-modal">X</button></div>
  <input type="hidden" name="route_id">
  <label>Naziv trase<input required name="name"></label>
  <label>Tip trase<select required name="route_type"><option value="feeder">Glavna</option><option value="distribution">Distribuciona</option><option value="drop">Drop</option></select></label>
  <label>Od<select name="odf_id"><option value="">Nije odabrano</option>@foreach($odfs as $odf)<option value="{{ $odf->id }}">{{ $odf->name }}</option>@endforeach</select></label>
  <label>Do<select name="cabinet_id"><option value="">Nije odabrano</option>@foreach($cabinets as $cabinet)<option value="{{ $cabinet->id }}">{{ $cabinet->name }}</option>@endforeach</select></label>
  <label>Mikrocijev<select required name="microduct_type"><option value="14/10">14/10</option><option value="10/8">10/8</option></select></label>
  <label>Kabal<select required name="fiber_count"><option value="4">4 niti</option><option value="12">12 niti</option><option value="24">24 niti</option><option value="48">48 niti</option></select></label>
  <label>Napomena<textarea name="note" rows="3"></textarea></label>
  <div class="route-modal-summary"><span id="route-modal-points">Tačke: 0</span><span id="route-modal-length">Dužina: 0 m</span></div>
  <div class="flex justify-end gap-2"><button type="button" class="secondary" data-action="close-route-modal">Odustani</button><button type="submit">Sačuvaj trasu</button></div>
 </form>
</div>
<div id="delete-confirm-modal" class="route-modal hidden">
 <div class="route-modal-card">
  <h3 class="text-sm font-bold">Potvrda brisanja</h3>
  <p id="delete-confirm-message" class="tiny"></p>
  <div class="flex justify-end gap-2"><button type="button" class="secondary" data-action="cancel-delete">Odustani</button><button type="button" class="danger" data-action="confirm-delete">Obriši</button></div>
 </div>
</div>
<script>
window.ftthData = @json($mapData);
window.ftthConfig = {
    projectId: {{ $activeProject?->id ?? 'null' }},
    csrf: @json(csrf_token()),
    suggestionUrl: @json(route('map.suggestions.store')),
    odfUrl: @json(route('odfs.store')),
    cabinetUrl: @json(route('cabinets.store')),
    houseUrl: @json(route('houses.store')),
    routeUrl: @json(route('routes.store')),
    routeGeometryUrl: @json(url('/trase/__ID__/geometrija')),
    routeUpdateUrl: @json(url('/trase/__ID__')),
    routeDeleteUrl: @json(url('/trase/__ID__')),
    deleteUrls: { odf: @json(url('/odf/__ID__')), odo: @json(url('/ormarici/__ID__')), house: @json(url('/kuce/__ID__')) },
    positionUrls: { odf: @json(url('/odf/__ID__/pozicija')), odo: @json(url('/ormarici/__ID__/pozicija')), house: @json(url('/kuce/__ID__/pozicija')) },
};
</script>
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
<script src="{{ asset('js/ftth-map.js') }}"></script>
@endsection
