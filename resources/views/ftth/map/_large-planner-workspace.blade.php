<details id="large-planner-workspace" class="sidebar-card" open data-project-id="{{ $project->id }}">
    <summary class="sidebar-hd">
        <span class="sdot sdot-violet"></span>
        Veliki planer
        <span class="ml-auto rounded bg-violet-100 px-2 py-0.5 text-[9px] font-black uppercase tracking-wide text-violet-700">Large auto</span>
        <svg class="chev h-3.5 w-3.5" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M7.293 14.707a1 1 0 010-1.414L10.586 10 7.293 6.707a1 1 0 011.414-1.414l4 4a1 1 0 010 1.414l-4 4a1 1 0 01-1.414 0z" clip-rule="evenodd"/></svg>
    </summary>
    <div class="sidebar-bd grid gap-3">
        <div class="rounded-lg border border-violet-200 bg-violet-50 px-3 py-2.5 text-[11px] leading-5 text-violet-950">
            <b class="block">{{ $project->name }}</b>
            <span>Poseban workspace za planiranje velikog broja kuća. Postojeći Auto ODO ostaje odvojen.</span>
        </div>
        <nav aria-label="Koraci velikog planera" class="grid grid-cols-2 gap-1.5">
            <button type="button" class="step-btn step-violet" data-large-planner-step="inputs"><b>1</b> Ulazni podaci</button>
            <button type="button" class="step-btn step-violet" data-large-planner-step="rules"><b>2</b> Pravila</button>
            <button type="button" class="step-btn step-violet" data-large-planner-step="zones"><b>3</b> Zone</button>
            <button type="button" class="step-btn step-violet" data-large-planner-step="preview"><b>4</b> Proračun</button>
        </nav>
        <div class="grid gap-2 rounded-lg border border-amber-200 bg-amber-50 p-3">
            <div class="flex items-center justify-between gap-2">
                <span class="sb-kicker text-amber-900">Spremnost za planiranje</span>
                <span id="large-planner-readiness-badge" class="rounded bg-slate-200 px-2 py-0.5 text-[9px] font-black text-slate-700">PROVJERA</span>
            </div>
            <div id="large-planner-readiness" class="grid gap-1 text-[10px] leading-4 text-slate-700">Učitavam checklistu…</div>
            <button id="large-planner-validate-input" type="button" class="sb-btn sb-btn-outline">Provjeri ulazne podatke</button>
            <div id="large-planner-validation-result" class="text-[10px] leading-4 text-amber-900">Provjera još nije pokrenuta.</div>
        </div>
        @can('project.edit')
            <form method="POST" action="{{ route('projects.large-planner.input-snapshots.store', $project) }}" class="grid gap-2 rounded-lg border border-sky-200 bg-sky-50 p-3">
                @csrf
                <div class="sb-kicker text-sky-800">Ulazna revizija</div>
                <input name="label" maxlength="120" class="sb-inp" placeholder="Naziv revizije (opcionalno)">
                <button class="sb-btn sb-btn-outline">Sačuvaj trenutni ulaz</button>
                <p class="text-[10px] leading-4 text-sky-800">Čuva pravila, koridore, ograničenja i kuće za ponovljiv proračun.</p>
            </form>
        @endcan
        @can('project.edit')
            <div class="grid gap-2 rounded-lg border border-indigo-200 bg-indigo-50 p-3">
                <div class="sb-kicker text-indigo-800">Zone projekta</div>
                <input id="large-planner-zone-name" maxlength="120" class="sb-inp" placeholder="Naziv nove zone">
                <div class="grid grid-cols-2 gap-1.5">
                    <button id="large-planner-zone-draw" type="button" class="sb-btn sb-btn-outline">Crtaj zonu</button>
                    <button id="large-planner-zone-finish" type="button" class="sb-btn sb-btn-primary hidden">Završi zonu</button>
                </div>
                <div class="grid grid-cols-[1fr_auto] gap-1.5 border-t border-indigo-200 pt-2">
                    <input id="large-planner-zone-target" type="number" min="25" max="500" value="200" class="sb-inp" title="Ciljani broj kuća po zoni">
                    <button id="large-planner-zone-auto" type="button" class="sb-btn sb-btn-outline">Auto podjela</button>
                </div>
                <button id="large-planner-zone-cancel" type="button" class="sb-btn sb-btn-outline hidden">Otkaži crtanje</button>
                <p id="large-planner-zone-status" class="text-[10px] leading-4 text-indigo-800">Nacrtaj zonu ili pokreni početnu prostornu podjelu.</p>
                <div id="large-planner-zone-list" class="grid gap-1"></div>
            </div>
            <div class="grid gap-2 rounded-lg border border-violet-200 bg-violet-50 p-3">
                <div class="sb-kicker text-violet-800">Ograničenja trase</div>
                <div class="grid grid-cols-2 gap-1.5">
                    <button id="large-planner-waypoint" type="button" class="sb-btn sb-btn-outline">Obavezna tačka</button>
                    <button id="large-planner-restricted-area" type="button" class="sb-btn sb-btn-outline">Zabranjeno područje</button>
                </div>
                <div id="large-planner-draw-actions" class="hidden grid grid-cols-2 gap-1.5">
                    <button id="large-planner-finish-area" type="button" class="sb-btn sb-btn-primary">Završi područje</button>
                    <button id="large-planner-cancel-draw" type="button" class="sb-btn sb-btn-outline">Otkaži</button>
                </div>
                <p id="large-planner-constraint-status" class="text-[10px] leading-4 text-violet-800">Odaberi alat za označavanje na mapi.</p>
            </div>
            <details class="rounded-lg border border-slate-200 bg-white">
                <summary class="cursor-pointer px-3 py-2 text-[11px] font-bold text-slate-700">Kuće za planiranje</summary>
                <div class="grid gap-3 border-t border-slate-100 p-3">
                    <form method="POST" action="{{ route('projects.large-planner.houses.store', $project) }}" class="grid gap-2">
                        @csrf
                        <div class="sb-kicker">Ručni unos</div>
                        <input name="label" value="{{ old('label') }}" class="sb-inp" placeholder="Oznaka kuće" required>
                        <input name="address" value="{{ old('address') }}" class="sb-inp" placeholder="Adresa">
                        <div class="grid grid-cols-2 gap-2">
                            <input name="latitude" type="number" step="0.0000001" min="-90" max="90" value="{{ old('latitude') }}" class="sb-inp" placeholder="Latitude" required>
                            <input name="longitude" type="number" step="0.0000001" min="-180" max="180" value="{{ old('longitude') }}" class="sb-inp" placeholder="Longitude" required>
                        </div>
                        @error('label')<span class="text-[10px] text-red-600">{{ $message }}</span>@enderror
                        @error('latitude')<span class="text-[10px] text-red-600">{{ $message }}</span>@enderror
                        @error('longitude')<span class="text-[10px] text-red-600">{{ $message }}</span>@enderror
                        <button class="sb-btn sb-btn-primary">Dodaj kuću</button>
                    </form>
                    <form method="POST" action="{{ route('projects.large-planner.houses.import', $project) }}" enctype="multipart/form-data" class="grid gap-2 border-t border-slate-100 pt-3">
                        @csrf
                        <div class="sb-kicker">Masovni CSV import</div>
                        <input type="file" name="houses_file" accept=".csv,.txt,text/csv" class="text-[10px] text-slate-600" required>
                        <p class="text-[10px] leading-4 text-slate-500">Kolone: <code>label,address,latitude,longitude</code>. Podržani su zarez, tačka-zarez i tabulator.</p>
                        @error('houses_file')<span class="text-[10px] text-red-600">{{ $message }}</span>@enderror
                        <button class="sb-btn sb-btn-outline">Uvezi kuće</button>
                    </form>
                </div>
            </details>
        @endcan
        <form method="POST" action="{{ route('projects.large-planner.settings.update', $project) }}" class="grid gap-2 rounded-lg border border-slate-200 bg-slate-50 p-3">
            @csrf
            @method('PATCH')
            <div class="sb-kicker">Tehnička pravila</div>
            <div class="grid grid-cols-2 gap-2">
                <label class="grid gap-1 text-[10px] font-bold text-slate-600">ODO kapacitet
                    <input name="odo_capacity" type="number" min="1" max="1152" value="{{ old('odo_capacity', $project->largePlannerSetting?->odo_capacity) }}" class="sb-inp" placeholder="Nije određeno">
                    @error('odo_capacity')<span class="text-red-600">{{ $message }}</span>@enderror
                </label>
                <label class="grid gap-1 text-[10px] font-bold text-slate-600">Maks. drop (m)
                    <input name="max_drop_length_m" type="number" min="1" max="1000000" value="{{ old('max_drop_length_m', $project->largePlannerSetting?->max_drop_length_m) }}" class="sb-inp" placeholder="Nije određeno">
                    @error('max_drop_length_m')<span class="text-red-600">{{ $message }}</span>@enderror
                </label>
            </div>
            <label class="grid gap-1 text-[10px] font-bold text-slate-600">Rezerva vlakana (%)
                <input name="fiber_reserve_percent" type="number" min="0" max="100" step="0.01" value="{{ old('fiber_reserve_percent', $project->largePlannerSetting?->fiber_reserve_percent) }}" class="sb-inp" placeholder="Nije određeno">
                @error('fiber_reserve_percent')<span class="text-red-600">{{ $message }}</span>@enderror
            </label>
            <label class="grid gap-1 text-[10px] font-bold text-slate-600">Cilj optimizacije
                <select name="optimization_goal" class="sb-sel">
                    <option value="">Nije određeno</option>
                    @foreach(['min_trench' => 'Najmanje rova', 'min_cable' => 'Najmanje kabla', 'min_odo' => 'Najmanje ODO-a', 'weighted' => 'Ponderisana kombinacija'] as $value => $label)
                        <option value="{{ $value }}" @selected(old('optimization_goal', $project->largePlannerSetting?->optimization_goal) === $value)>{{ $label }}</option>
                    @endforeach
                </select>
                @error('optimization_goal')<span class="text-red-600">{{ $message }}</span>@enderror
            </label>
            <label class="flex items-center gap-2 text-[10px] font-bold text-slate-700">
                <input type="hidden" name="propose_odfs" value="0">
                <input type="checkbox" name="propose_odfs" value="1" @checked(old('propose_odfs', $project->largePlannerSetting?->propose_odfs))>
                Sistem smije predlagati nove ODF-ove
            </label>
            <label class="grid gap-1 text-[10px] font-bold text-slate-600">Maks. ODO-a po predloženom ODF-u
                <input name="odf_capacity" type="number" min="1" max="1152" value="{{ old('odf_capacity', $project->largePlannerSetting?->odf_capacity) }}" class="sb-inp" placeholder="Obavezno kada je opcija uključena">
                @error('odf_capacity')<span class="text-red-600">{{ $message }}</span>@enderror
            </label>
            @can('project.edit')
                <button class="sb-btn sb-btn-primary">Sačuvaj pravila</button>
            @endcan
        </form>
        <section class="grid gap-2 rounded-lg border border-cyan-200 bg-cyan-50 p-3" aria-labelledby="large-planner-preview-title">
            <div class="flex items-center justify-between gap-2"><div id="large-planner-preview-title" class="sb-kicker text-cyan-900">Proračun i pregled</div><span id="large-planner-task-progress" class="rounded bg-slate-200 px-2 py-0.5 text-[9px] font-black text-slate-700">NIJE POKRENUT</span></div>
            @can('project.edit')<button id="large-planner-run" type="button" class="sb-btn sb-btn-primary">Pokreni proračun</button>@endcan
            <select id="large-planner-variant" class="sb-sel" aria-label="Varijanta plana"><option value="">Odaberi završenu varijantu</option></select>
            <div class="h-1.5 overflow-hidden rounded bg-cyan-100"><div id="large-planner-progress-bar" class="h-full bg-cyan-600 transition-all" style="width:0%"></div></div>
            <p id="large-planner-task-message" class="text-[10px] leading-4 text-cyan-900">Pokreni proračun kada su ulazi spremni.</p>
            <div class="grid grid-cols-3 gap-1 text-center text-[9px] font-bold"><span class="rounded bg-red-100 px-1 py-1 text-red-800">Primarna</span><span class="rounded bg-blue-100 px-1 py-1 text-blue-800">Sekundarna</span><span class="rounded bg-violet-100 px-1 py-1 text-violet-800">Drop</span></div>
            <div id="large-planner-preview-summary" class="grid grid-cols-2 gap-1 text-[10px]"></div>
            <div id="large-planner-preview-warnings" class="max-h-36 overflow-y-auto text-[10px]"></div>
            @can('project.edit')<div class="grid grid-cols-2 gap-1.5"><button id="large-planner-final-validate" type="button" class="sb-btn sb-btn-outline">Završna provjera</button><button id="large-planner-confirm" type="button" class="sb-btn sb-btn-primary">Potvrdi plan</button></div>@endcan
            <div id="large-planner-final-status" class="text-[10px] leading-4 text-slate-600"></div>
            <details class="rounded border border-cyan-200 bg-white p-2"><summary class="cursor-pointer text-[10px] font-bold text-cyan-900">Prebaci kuću na drugi ODO</summary><div class="mt-2 grid gap-1.5"><input id="large-planner-house-id" type="number" min="1" class="sb-inp" placeholder="ID kuće"><select id="large-planner-house-odo" class="sb-sel"><option value="">Odaberi ODO</option></select>@can('project.edit')<button id="large-planner-assign-house" type="button" class="sb-btn sb-btn-outline">Provjeri i prebaci</button>@endcan</div></details>
            <details class="rounded border border-cyan-200 bg-white p-2"><summary class="cursor-pointer text-[10px] font-bold text-cyan-900">Poredi dvije varijante</summary><div class="mt-2 grid gap-1.5"><select id="large-planner-compare-first" class="sb-sel"><option value="">Prva varijanta</option></select><select id="large-planner-compare-second" class="sb-sel"><option value="">Druga varijanta</option></select><button id="large-planner-compare" type="button" class="sb-btn sb-btn-outline">Uporedi</button><div id="large-planner-comparison" class="text-[10px]"></div></div></details>
            <p class="text-[9px] leading-4 text-cyan-800">Povuci predloženi ODF/ODO marker za pomjeranje. Klik na marker otvara detalje i zaključavanje.</p>
        </section>
        <p class="text-[10px] leading-4 text-slate-500">Nepotvrđena pravila ostaju prazna; sistem ne pretpostavlja tehničke vrijednosti.</p>
    </div>
</details>
