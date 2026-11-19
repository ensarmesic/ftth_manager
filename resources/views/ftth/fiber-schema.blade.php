@extends('ftth.layout')

@section('title', 'Fiber šema')
@section('subtitle', 'ODN šema: ODF, kablovi, ODO ormarići, dodjela vlakana i optički budžet. Isti crtež ide u DXF (AutoCAD) i PDF.')

@section('content')
@include('ftth.fiber-schema._budget-styles')
@php
    $plan = $drawing['plan'] ?? null;
    $fiberPlan = $plan;
    $schedule = collect($drawing['schedule'] ?? []);
    $issues = collect($plan['issues'] ?? []);
    $errorCount = $issues->where('level', 'error')->count();
    $warningCount = $issues->where('level', 'warning')->count();
    $splicesByCabinet = $project?->fiberSplices->groupBy('cabinet_id') ?? collect();
    $fibersPerTube = (int) ($plan['fibersPerTube'] ?? 24);
    $colorStandard = $project?->fiber_color_standard ?? 'telcordia';
@endphp
<div class="fx-page">
    <section class="fx-toolbar">
        <form method="GET" class="fx-picker">
            <label for="fx-project">Projekat</label>
            <select id="fx-project" name="project" data-auto-submit>
                @if($projectOptions->isEmpty())<option value="">Nema projekata</option>@endif
                @foreach($projectOptions as $option)
                    <option value="{{ $option->id }}" @selected((int) $selectedProjectId === $option->id)>{{ $option->name }} · {{ $option->code }}</option>
                @endforeach
            </select>
        </form>
        @if($project)
        <div class="fx-exports">
            @can('project.export')
                <a href="{{ route('projects.fiber-schema-dxf', $project) }}" class="fx-btn fx-btn-dark" title="DXF R12, mm, ISO list s okvirom i sastavnicom">DXF za AutoCAD</a>
                <a href="{{ route('projects.fiber-schema-pdf', $project) }}" class="fx-btn">PDF</a>
                <a href="{{ route('projects.fiber.csv', $project) }}" class="fx-btn">CSV / Excel</a>
            @endcan
            <a href="{{ route('map.dashboard', ['project' => $project->id]) }}" class="fx-btn">Otvori mapu</a>
        </div>
        @endif
    </section>

@if(! $project)
    <div class="fx-empty">Nema projekata.</div>
@else
    <article class="schema-project fx-project {{ $project->fiber_schema_locked ? 'fiber-locked' : '' }}" data-project-id="{{ $project->id }}" data-plan-signature="{{ $plan['signature'] }}" data-locked="{{ $project->fiber_schema_locked ? '1' : '0' }}">
        <header class="fx-head">
            <div class="fx-title">
                <h2>{{ $project->name }}</h2>
                <p>{{ $project->code }}{{ $project->location ? ' · '.$project->location : '' }} · {{ $plan['profile']['standard'] }} · {{ $plan['profile']['label'] }} · {{ $project->fiber_layout ?? '6x24' }} · {{ $colorStandard === 'din_vde' ? 'DIN VDE 0888' : 'TIA-598-C' }}</p>
            </div>
            <div class="fx-stats">
                <span><b>{{ $drawing['stats']['odfs'] }}</b> ODF</span>
                <span><b>{{ $drawing['stats']['cabinets'] }}</b> ODO</span>
                <span><b>{{ $plan['usedFibers'] }}</b>/{{ $plan['capacity'] }} vlakana</span>
                <span class="{{ $errorCount ? 'is-bad' : ($warningCount ? 'is-warn' : 'is-good') }}">{{ $errorCount ? $errorCount.' grešaka' : ($warningCount ? $warningCount.' upozorenja' : 'kontrola uredna') }}</span>
                <span class="{{ $project->fiber_schema_locked ? 'is-good' : '' }}">{{ $project->fiber_schema_locked ? 'Odobrena' : 'Radna verzija' }}</span>
                <span class="fx-mono" title="Potpis trenutnog fiber proračuna; isti je u DXF-u, PDF-u i CSV-u">{{ $plan['signature'] }}</span>
            </div>
            <div class="fiber-commandbar fx-commands">
                @can('project.edit')<button type="button" data-fiber-version class="primary">Sačuvaj verziju</button>@endcan
                <button type="button" data-fiber-versions>Verzije</button>
                @can('project.edit')<button type="button" data-fiber-lock class="{{ $project->fiber_schema_locked ? 'danger' : '' }}">{{ $project->fiber_schema_locked ? 'Otključaj šemu' : 'Zaključaj / odobri' }}</button>@endcan
            </div>
        </header>

        <nav class="fx-tabs" role="tablist">
            <button type="button" class="is-active" data-schema-view="sheet">Šema</button>
            <button type="button" data-schema-view="fibers">Plan vlakana <i>{{ $schedule->count() }}</i></button>
            <button type="button" data-schema-view="power-budget">Power budget</button>
            <button type="button" data-schema-view="checks">Kontrola @if($errorCount + $warningCount)<i class="{{ $errorCount ? 'is-bad' : 'is-warn' }}">{{ $errorCount + $warningCount }}</i>@endif</button>
        </nav>

        {{-- ŠEMA --}}
        <div data-schema-panel="sheet" class="fx-sheet-panel">
            <div class="fx-viewer" data-fx-viewer tabindex="0" aria-label="Fiber šema, povuci za pomjeranje, točkić za zumiranje">
                <div class="fx-zoom">
                    <button type="button" data-zoom="out" title="Umanji">−</button>
                    <span data-zoom-value>100%</span>
                    <button type="button" data-zoom="in" title="Uvećaj">+</button>
                    <button type="button" data-zoom="fit" title="Prikaži cijeli list (F)">Cijeli list</button>
                </div>
                <div class="fx-stage" data-fx-stage>{!! $sheetSvg !!}</div>
                <aside class="fx-detail hidden" data-fx-detail></aside>
                <div class="fx-hint">Točkić: zoom · povuci: pomjeranje · klik na ODO: detalji · F: cijeli list · List {{ $drawing['sheet']['name'] }} ({{ (int) $drawing['sheet']['width'] }} × {{ (int) $drawing['sheet']['height'] }} mm)</div>
            </div>
        </div>

        {{-- PLAN VLAKANA --}}
        <div class="hidden" data-schema-panel="fibers">
            <div class="fx-plan-tools">
                <input type="search" data-fx-search placeholder="Traži ODO, krak, vlakno (npr. F12) ili kuću…" aria-label="Pretraga plana vlakana">
                <span class="fx-reserve">
                    @foreach($plan['odfs'] as $odfPlan)
                        <b>{{ $odfPlan['name'] }}:</b> zauzeto F1–F{{ $odfPlan['usedTo'] }} od {{ $odfPlan['capacity'] }}F @if($odfPlan['usedTo'] < $odfPlan['capacity']) · rezerva F{{ $odfPlan['reserveFrom'] }}–F{{ $odfPlan['reserveTo'] }}@endif @if(! $loop->last)&nbsp;|&nbsp;@endif
                    @endforeach
                </span>
            </div>
            <div class="fx-table-wrap">
                <table class="fx-table">
                    <thead><tr><th>ODF</th><th>ODO</th><th>Kabel / krak</th><th>Cijev</th><th>Vlakna (ODF port)</th><th>Splitteri</th><th>Kuće</th><th>Varenja</th><th></th></tr></thead>
                    <tbody>
                    @forelse($schedule as $row)
                        @php
                            $houses = $housesByCabinet->get($row['cabinet_id'], collect())->values();
                            $splices = $splicesByCabinet->get($row['cabinet_id'], collect());
                            $ports = max(1, (int) \Illuminate\Support\Str::after($row['splitters'], ':'));
                            $search = mb_strtolower(implode(' ', [$row['odf'], $row['cabinet'], $row['cable'], $row['fibers'], $houses->pluck('label')->implode(' ')]));
                        @endphp
                        <tr class="{{ $row['problem'] ? 'is-problem' : '' }}" data-fx-row data-cabinet-row="{{ $row['cabinet_id'] }}" data-search="{{ $search }}" data-fiber-range="{{ $row['fiber_from'] ? ($row['fiber_from'] === $row['fiber_to'] ? $row['fiber_from'] : $row['fiber_from'].'-'.$row['fiber_to']) : 'nema' }}">
                            <td>{{ $row['odf'] }}</td>
                            <td><b>{{ $row['cabinet'] }}</b></td>
                            <td>{{ $row['cable'] }}</td>
                            <td>@if($row['tube_color'])<i class="fx-dot" style="background:{{ $row['tube_color']['hex'] }}" title="{{ $row['tube_color']['name'] }}"></i>@endif{{ $row['tube'] }}</td>
                            <td class="fx-fibers">
                                @if($row['fiber_from'])
                                    @foreach(range($row['fiber_from'], $row['fiber_to']) as $number)
                                        @php $fc = \App\Support\FiberColorCode::describe($number, $fibersPerTube, $colorStandard); @endphp
                                        <span title="F{{ $number }} · {{ $fc['fiber']['name'] }}{{ $fc['traced'] ? ' s crnim prstenom' : '' }} · cijev T{{ $fc['tube_number'] }}"><i class="fx-dot {{ $fc['traced'] ? 'is-traced' : '' }}" style="background:{{ $fc['fiber']['hex'] }}"></i>F{{ $number }}</span>
                                    @endforeach
                                @else
                                    <em class="fx-bad">nema vlakna</em>
                                @endif
                            </td>
                            <td>{{ $row['splitters'] }}</td>
                            <td class="{{ $row['problem'] && $row['fiber_from'] ? 'fx-bad' : '' }}">{{ $row['houses'] }}</td>
                            <td>{{ $splices->count() ?: '—' }}</td>
                            <td class="fx-actions">
                                @if($houses->isNotEmpty())<button type="button" data-fx-houses="{{ $row['cabinet_id'] }}">Portovi</button>@endif
                                @can('project.edit')@if($row['fiber_from'])<button type="button" data-splice-cabinet="{{ $row['cabinet_id'] }}" data-splice-fiber="{{ $row['fiber_from'] }}">+ Varenje</button>@endif @endcan
                                <button type="button" data-fx-locate="{{ $row['cabinet_id'] }}" title="Prikaži na šemi">Na šemi</button>
                            </td>
                        </tr>
                        @if($houses->isNotEmpty())
                        <tr class="fx-ports hidden" data-fx-ports="{{ $row['cabinet_id'] }}">
                            <td colspan="9">
                                <div class="fx-port-grid">
                                    @foreach($houses as $index => $house)
                                        <span><b>S{{ intdiv($index, $ports) + 1 }}/P{{ ($index % $ports) + 1 }}</b> {{ $house->label }}@if($house->address)<small>{{ $house->address }}</small>@endif</span>
                                    @endforeach
                                </div>
                                @if($splices->isNotEmpty())
                                    <div class="fx-splices"><b>Varenja:</b> @foreach($splices->sortBy('fiber_number') as $splice) F{{ $splice->fiber_number }} · kaseta {{ $splice->tray }}/{{ $splice->position }} · {{ number_format((float) $splice->loss_db, 2, ',', '.') }} dB @if(! $loop->last); @endif @endforeach</div>
                                @endif
                            </td>
                        </tr>
                        @endif
                    @empty
                        <tr><td colspan="9" class="fx-muted">Projekat nema ODO ormarića.</td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
            @if($unassignedHouses->isNotEmpty())
                <details class="fx-unassigned">
                    <summary>Kuće bez ODO ormarića ({{ $unassignedHouses->count() }})</summary>
                    <div class="fx-port-grid">@foreach($unassignedHouses as $house)<span>{{ $house->label }}@if($house->address)<small>{{ $house->address }}</small>@endif</span>@endforeach</div>
                </details>
            @endif
        </div>

        {{-- POWER BUDGET --}}
        @include('ftth.fiber-schema._power-budget')

        {{-- KONTROLA --}}
        <div class="hidden" data-schema-panel="checks">
            <section class="fx-checks">
                @php
                    $allocated = $schedule->whereNotNull('fiber_from')->count();
                    $duplicates = collect($plan['odfs'])->flatMap(fn ($odfPlan) => $odfPlan['duplicates'])->unique();
                    $overCapacity = collect($plan['odfs'])->filter(fn ($odfPlan) => $odfPlan['usedTo'] > $odfPlan['capacity']);
                @endphp
                <div class="fiber-check {{ $allocated === $schedule->count() ? 'ok' : 'warn' }}"><b>{{ $allocated === $schedule->count() ? '✓' : '!' }}</b><span>{{ $allocated }} od {{ $schedule->count() }} ODO ormarića ima dodijeljena vlakna.</span></div>
                <div class="fiber-check {{ $overCapacity->isEmpty() ? 'ok' : 'error' }}"><b>{{ $overCapacity->isEmpty() ? '✓' : '!' }}</b><span>{{ $overCapacity->isEmpty() ? 'Nijedan ODF nije preko kapaciteta.' : $overCapacity->pluck('name')->implode(', ').' je preko kapaciteta vlakana.' }}</span></div>
                <div class="fiber-check {{ $duplicates->isEmpty() ? 'ok' : 'error' }}"><b>{{ $duplicates->isEmpty() ? '✓' : '!' }}</b><span>{{ $duplicates->isEmpty() ? 'Nema dvostruko dodijeljenih vlakana.' : 'Dvostruko dodijeljena vlakna: F'.$duplicates->implode(', F').'.' }}</span></div>
                <div class="fiber-check ok"><b>✓</b><span>Na šemi je {{ $drawing['stats']['drawn_cabinets'] }} od {{ $drawing['stats']['cabinets'] }} ODO ormarića.</span></div>
                @foreach($issues as $issue)
                    <div class="fiber-check {{ $issue['level'] === 'error' ? 'error' : 'warn' }}"><b>!</b><span>{{ $issue['message'] }}</span></div>
                @endforeach
            </section>
        </div>
    </article>
@endif
</div>

@include('ftth.fiber-schema._modals')
<script type="application/json" id="fx-cabinets">@json($drawing['cabinets'] ?? [])</script>
<script nonce="{{ Vite::cspNonce() }}">
@include('ftth.fiber-schema._actions')
(() => {
    const project = document.querySelector('.fx-project');
    if (!project) return;
    const cabinets = JSON.parse(document.getElementById('fx-cabinets').textContent || '{}');

    // Kartice.
    const showPanel = (name) => {
        project.querySelectorAll('[data-schema-view]').forEach((tab) => tab.classList.toggle('is-active', tab.dataset.schemaView === name));
        project.querySelectorAll('[data-schema-panel]').forEach((panel) => panel.classList.toggle('hidden', panel.dataset.schemaPanel !== name));
        if (name === 'sheet') requestAnimationFrame(() => viewer.fitIfFresh());
    };
    project.querySelectorAll('[data-schema-view]').forEach((tab) => tab.addEventListener('click', () => showPanel(tab.dataset.schemaView)));

    // Pregled lista: pan i zoom na SVG-u.
    const viewer = (() => {
        const shell = project.querySelector('[data-fx-viewer]');
        const stage = shell.querySelector('[data-fx-stage]');
        const svg = stage.querySelector('svg');
        const label = shell.querySelector('[data-zoom-value]');
        const MM = 96 / 25.4;
        const sheetW = svg.viewBox.baseVal.width, sheetH = svg.viewBox.baseVal.height;
        let scale = 1, x = 0, y = 0, fresh = true;
        svg.removeAttribute('width');
        svg.removeAttribute('height');
        const apply = () => {
            svg.style.width = `${sheetW * MM * scale}px`;
            svg.style.height = `${sheetH * MM * scale}px`;
            stage.style.transform = `translate(${x}px, ${y}px)`;
            label.textContent = `${Math.round(scale * 100)}%`;
        };
        const fit = () => {
            const box = shell.getBoundingClientRect();
            if (!box.width) return;
            scale = Math.min((box.width - 24) / (sheetW * MM), (box.height - 24) / (sheetH * MM));
            x = (box.width - sheetW * MM * scale) / 2;
            y = (box.height - sheetH * MM * scale) / 2;
            fresh = false;
            apply();
        };
        const zoomAt = (factor, cx, cy) => {
            const next = Math.min(8, Math.max(0.05, scale * factor));
            x = cx - (cx - x) * (next / scale);
            y = cy - (cy - y) * (next / scale);
            scale = next;
            apply();
        };
        const center = () => { const b = shell.getBoundingClientRect(); return [b.width / 2, b.height / 2]; };
        shell.addEventListener('wheel', (event) => {
            event.preventDefault();
            const b = shell.getBoundingClientRect();
            zoomAt(event.deltaY < 0 ? 1.15 : 1 / 1.15, event.clientX - b.left, event.clientY - b.top);
        }, { passive: false });
        let drag = null;
        shell.addEventListener('pointerdown', (event) => {
            if (event.target.closest('.fx-zoom, .fx-detail')) return;
            drag = { sx: event.clientX, sy: event.clientY, x, y, moved: false };
            shell.setPointerCapture(event.pointerId);
        });
        shell.addEventListener('pointermove', (event) => {
            if (!drag) return;
            const dx = event.clientX - drag.sx, dy = event.clientY - drag.sy;
            if (Math.abs(dx) + Math.abs(dy) > 3) { drag.moved = true; shell.classList.add('is-dragging'); }
            x = drag.x + dx; y = drag.y + dy;
            apply();
        });
        shell.addEventListener('pointerup', (event) => {
            const wasClick = drag && !drag.moved;
            drag = null;
            shell.classList.remove('is-dragging');
            if (wasClick) {
                const hit = document.elementsFromPoint(event.clientX, event.clientY).find((el) => el.closest?.('.fiber-odo'));
                const odo = hit?.closest('.fiber-odo');
                if (odo) select(Number(odo.dataset.cabinetId), false);
            }
        });
        shell.querySelector('[data-zoom="in"]').addEventListener('click', () => zoomAt(1.25, ...center()));
        shell.querySelector('[data-zoom="out"]').addEventListener('click', () => zoomAt(0.8, ...center()));
        shell.querySelector('[data-zoom="fit"]').addEventListener('click', fit);
        shell.addEventListener('keydown', (event) => {
            if (event.key === 'f' || event.key === 'F') fit();
            if (event.key === '+') zoomAt(1.25, ...center());
            if (event.key === '-') zoomAt(0.8, ...center());
        });
        new ResizeObserver(() => { if (fresh) fit(); }).observe(shell);
        const focusOn = (mmX, mmY) => {
            const b = shell.getBoundingClientRect();
            scale = Math.max(scale, 1.4);
            x = b.width / 2 - mmX * MM * scale;
            y = b.height / 2 - mmY * MM * scale;
            apply();
        };
        return { fit, focusOn, fitIfFresh: () => { if (fresh) fit(); } };
    })();

    // Odabrani ODO: detalji na šemi i red u planu vlakana.
    const detail = project.querySelector('[data-fx-detail]');
    const escape = (value) => String(value ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' }[c]));
    function select(id, focus) {
        const cabinet = cabinets[id];
        project.querySelectorAll('.fiber-odo.is-selected').forEach((el) => el.classList.remove('is-selected'));
        if (!cabinet) return;
        project.querySelector(`.fiber-odo[data-cabinet-id="${id}"]`)?.classList.add('is-selected');
        detail.innerHTML = `<header><small>ODO ORMARIĆ</small><b>${escape(cabinet.name)}</b><button type="button" data-fx-close aria-label="Zatvori">×</button></header>
            <dl><div><dt>Kabel / krak</dt><dd>${escape(cabinet.cable || '—')}</dd></div><div><dt>Vlakna</dt><dd>${escape(cabinet.fibers)}${cabinet.tube ? ' · cijev T' + cabinet.tube : ''}</dd></div>
            <div><dt>Splitteri</dt><dd>${escape(cabinet.splitters)}</dd></div><div><dt>Kuće / kapacitet</dt><dd>${cabinet.houses} / ${cabinet.capacity}</dd></div></dl>
            ${cabinet.state !== 'ok' ? `<p class="fx-bad">${cabinet.state === 'nofiber' ? 'Nema dodijeljeno vlakno – provjeri ODF vezu.' : 'Više kuća nego portova.'}</p>` : ''}
            <button type="button" class="fx-btn" data-fx-to-plan="${id}">Prikaži u planu vlakana</button>`;
        detail.classList.remove('hidden');
        if (focus) viewer.focusOn(cabinet.x, cabinet.y);
    }
    detail.addEventListener('click', (event) => {
        if (event.target.closest('[data-fx-close]')) { detail.classList.add('hidden'); project.querySelectorAll('.fiber-odo.is-selected').forEach((el) => el.classList.remove('is-selected')); }
        const toPlan = event.target.closest('[data-fx-to-plan]');
        if (toPlan) {
            showPanel('fibers');
            const row = project.querySelector(`[data-cabinet-row="${toPlan.dataset.fxToPlan}"]`);
            row?.scrollIntoView({ block: 'center', behavior: 'smooth' });
            row?.classList.add('is-flash');
            setTimeout(() => row?.classList.remove('is-flash'), 1600);
        }
    });

    // Plan vlakana: pretraga, portovi, "na šemi".
    const search = project.querySelector('[data-fx-search]');
    search?.addEventListener('input', () => {
        const query = search.value.trim().toLocaleLowerCase('bs');
        project.querySelectorAll('[data-fx-row]').forEach((row) => {
            const fiber = /^f(\d+)$/.exec(query);
            const [from, to = from] = row.dataset.fiberRange.split('-').map(Number);
            const match = !query || row.dataset.search.includes(query) || (fiber && from <= Number(fiber[1]) && Number(fiber[1]) <= to);
            row.classList.toggle('hidden', !match);
            project.querySelector(`[data-fx-ports="${row.dataset.cabinetRow}"]`)?.classList.add('hidden');
        });
    });
    project.querySelectorAll('[data-fx-houses]').forEach((button) => button.addEventListener('click', () => {
        project.querySelector(`[data-fx-ports="${button.dataset.fxHouses}"]`)?.classList.toggle('hidden');
    }));
    project.querySelectorAll('[data-fx-locate]').forEach((button) => button.addEventListener('click', () => {
        showPanel('sheet');
        requestAnimationFrame(() => select(Number(button.dataset.fxLocate), true));
    }));

    // Link s mape: ?cabinet=ID otvara taj ODO na šemi.
    const requested = Number(new URLSearchParams(location.search).get('cabinet') || 0);
    if (requested && cabinets[requested]) requestAnimationFrame(() => { viewer.fit(); select(requested, true); });
})();
</script>
@endsection
