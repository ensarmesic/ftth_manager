@extends('ftth.layout')
@section('title', 'Postavke')
@section('subtitle', 'Upravljanje prikazom, sigurnošću, GIS podacima i održavanjem sistema.')
@section('content')

<section class="settings-hub">
    <header class="settings-overview">
        <div class="settings-overview-copy">
            <span class="settings-eyebrow">Kontrolni centar sistema</span>
            <h2>Sve postavke na jednom mjestu</h2>
            <p>Prilagodi radno okruženje, zaštiti račun i upravljaj tehničkim podacima aplikacije.</p>
        </div>
        <div class="settings-overview-stats">
            <div><b>{{ $projects->count() }}</b><span>projekata</span></div>
            <div><b>{{ $activityLogs->count() }}</b><span>zadnjih izmjena</span></div>
            <div><b class="text-emerald-600">Aktivan</b><span>{{ app()->environment() }} sistem</span></div>
        </div>
    </header>

    <div class="settings-layout">
        <aside class="settings-nav" aria-label="Sekcije postavki">
            <div class="settings-nav-title">Postavke</div>
            @foreach ([
                ['display', 'Prikaz aplikacije', 'Izgled i ponašanje'],
                ['security', 'Sigurnost računa', 'Promjena lozinke'],
                ['users', 'Korisnici', 'Računi i uloge'],
                ['sessions', 'Aktivne sesije', 'Prijavljeni uređaji'],
                ['gis', 'GIS slojevi', 'Uvoz prostornih podataka'],
                ['maintenance', 'Sistem i backup', 'Status i sigurnosna kopija'],
                ['audit', 'Audit promjena', 'Evidencija aktivnosti'],
            ] as [$anchor, $label, $description])
                <a href="#settings-{{ $anchor }}" class="settings-nav-link {{ $loop->first ? 'is-active' : '' }}">
                    <span class="settings-nav-index">{{ str_pad((string) $loop->iteration, 2, '0', STR_PAD_LEFT) }}</span>
                    <span><b>{{ $label }}</b><small>{{ $description }}</small></span>
                </a>
            @endforeach
            <div class="settings-nav-note"><b>Lokalne postavke</b><span>Opcije prikaza vrijede samo u ovom browseru.</span></div>
        </aside>

        <div class="settings-content">
            <form id="settings-display" class="settings-panel" data-settings-section>
                <x-settings-heading title="Prikaz aplikacije" description="Odaberi kako će podaci i mrežni elementi biti prikazani." icon="display" />
                <div class="settings-panel-body">
                    <div class="settings-toggle-grid">
                        @foreach([
                            ['compactTables', 'Kompaktne tabele', 'Više podataka na ekranu uz smanjenu visinu redova.', false],
                            ['smallMarkers', 'Manje oznake na mapi', 'Kompaktniji ODF, ODO i korisnički markeri.', true],
                            ['notifications', 'Brojač obavještenja', 'Prikaži upozorenja na zvonu u gornjoj traci.', true],
                            ['showOccupancyColors', 'Boje zauzetosti ODO-a', 'Zelena, žuta i crvena prema popunjenosti.', true],
                        ] as [$name, $label, $desc, $default])
                            <label class="settings-toggle-card">
                                <span><b>{{ $label }}</b><small>{{ $desc }}</small></span>
                                <input type="checkbox" name="{{ $name }}" {{ $default ? 'checked' : '' }}>
                                <i aria-hidden="true"></i>
                            </label>
                        @endforeach
                    </div>
                    <div class="settings-actions">
                        <button class="btn-save" type="submit">Sačuvaj prikaz</button>
                        <span id="settings-status" class="settings-save-status" role="status"></span>
                    </div>
                </div>
            </form>

            <article class="settings-panel" data-settings-section id="settings-security">
                <x-settings-heading title="Sigurnost računa" description="Ažuriraj administratorsku lozinku i zaštiti pristup podacima." icon="security" />
                <form method="POST" action="{{ route('password.update') }}" class="settings-panel-body">
                    @csrf @method('PUT')
                    <div class="settings-security-banner"><div><b>Preporučena jaka lozinka</b><span>Najmanje 12 znakova, velika i mala slova te broj.</span></div><span>12+ znakova</span></div>
                    <div class="settings-fields-grid">
                        <label><span>Trenutna lozinka</span><input type="password" name="current_password" autocomplete="current-password" class="field-input" required></label>
                        <label><span>Nova lozinka</span><input type="password" name="password" autocomplete="new-password" class="field-input" required></label>
                        <label><span>Ponovi novu lozinku</span><input type="password" name="password_confirmation" autocomplete="new-password" class="field-input" required></label>
                    </div>
                    <div class="settings-actions"><button class="btn-save">Promijeni lozinku</button></div>
                </form>
                <div class="settings-panel-body" style="border-top:1px solid #e2e8f0">
                    <div class="settings-security-banner">
                        <div><b>Dvofaktorska autentifikacija (2FA)</b><span>Dodatni šestocifreni kod štiti administratorski račun i ako lozinka procuri.</span></div>
                        <span>{{ auth()->user()->two_factor_confirmed_at ? 'Uključena' : 'Isključena' }}</span>
                    </div>
                    @if (auth()->user()->two_factor_confirmed_at)
                        <form method="POST" action="{{ route('two-factor.destroy') }}" class="settings-fields-grid">
                            @csrf @method('DELETE')
                            <label><span>Trenutna lozinka za isključivanje</span><input type="password" name="current_password" autocomplete="current-password" class="field-input" required></label>
                            <div class="settings-actions"><button class="btn-save" style="background:#b91c1c">Isključi 2FA</button></div>
                        </form>
                    @else
                        <div class="settings-actions"><a href="{{ route('two-factor.setup') }}" class="btn-save">Postavi authenticator</a></div>
                    @endif
                </div>
            </article>

            <article class="settings-panel" data-settings-section id="settings-users">
                <x-settings-heading title="Korisnici" description="Kreiraj račune, dodijeli uloge i upravljaj pristupom aplikaciji." icon="users" />
                <div class="settings-panel-body">
                    @if($errors->has('user'))<div class="rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm font-semibold text-red-700">{{ $errors->first('user') }}</div>@endif
                    <details class="rounded-xl border border-slate-200 bg-slate-50 p-4" {{ $errors->hasAny(['name', 'username', 'email', 'role', 'password']) ? 'open' : '' }}>
                        <summary class="cursor-pointer font-bold text-slate-800">Dodaj novog korisnika</summary>
                        <form method="POST" action="{{ route('settings.users.store') }}" class="mt-4 grid gap-4">
                            @csrf
                            <div class="settings-fields-grid">
                                <label><span>Ime i prezime</span><input name="name" value="{{ old('name') }}" class="field-input" required maxlength="120"></label>
                                <label><span>Korisničko ime</span><input name="username" value="{{ old('username') }}" class="field-input" required maxlength="80" autocomplete="off"></label>
                                <label><span>Email</span><input type="email" name="email" value="{{ old('email') }}" class="field-input" required></label>
                                <label><span>Uloga</span><select name="role" class="field-input">@foreach(['administrator' => 'Administrator', 'designer' => 'Projektant', 'field' => 'Teren', 'viewer' => 'Pregled'] as $value => $label)<option value="{{ $value }}" @selected(old('role') === $value)>{{ $label }}</option>@endforeach</select></label>
                                <label><span>Početna lozinka</span><input type="password" name="password" class="field-input" required autocomplete="new-password"></label>
                                <label><span>Ponovi lozinku</span><input type="password" name="password_confirmation" class="field-input" required autocomplete="new-password"></label>
                            </div>
                            @if($errors->any() && !$errors->has('user'))<div class="text-sm font-semibold text-red-700">{{ $errors->first() }}</div>@endif
                            <div class="settings-actions"><button class="btn-save">Kreiraj korisnika</button><small>Najmanje 12 znakova, velika i mala slova te broj.</small></div>
                        </form>
                    </details>
                    <div class="mt-5 grid gap-3">
                        @foreach($users as $managedUser)
                            <details class="rounded-xl border border-slate-200 bg-white p-4">
                                <summary class="flex cursor-pointer list-none items-center justify-between gap-3">
                                    <span><b class="block text-slate-900">{{ $managedUser->name }}</b><small class="text-slate-500">{{ '@'.$managedUser->username }} · {{ $managedUser->email }}</small></span>
                                    <span class="rounded-full px-2.5 py-1 text-xs font-bold {{ $managedUser->is_active ? 'bg-emerald-100 text-emerald-700' : 'bg-slate-200 text-slate-600' }}">{{ $managedUser->is_active ? 'Aktivan' : 'Neaktivan' }}</span>
                                </summary>
                                <form method="POST" action="{{ route('settings.users.update', $managedUser) }}" class="mt-4 grid gap-4 border-t border-slate-100 pt-4">
                                    @csrf @method('PUT')
                                    <div class="settings-fields-grid">
                                        <label><span>Ime i prezime</span><input name="name" value="{{ $managedUser->name }}" class="field-input" required maxlength="120"></label>
                                        <label><span>Korisničko ime</span><input name="username" value="{{ $managedUser->username }}" class="field-input" required maxlength="80"></label>
                                        <label><span>Email</span><input type="email" name="email" value="{{ $managedUser->email }}" class="field-input" required></label>
                                        <label><span>Uloga</span><select name="role" class="field-input">@foreach(['administrator' => 'Administrator', 'designer' => 'Projektant', 'field' => 'Teren', 'viewer' => 'Pregled'] as $value => $label)<option value="{{ $value }}" @selected($managedUser->role === $value)>{{ $label }}</option>@endforeach</select></label>
                                        <label><span>Nova lozinka (opcionalno)</span><input type="password" name="password" class="field-input" autocomplete="new-password"></label>
                                        <label><span>Ponovi novu lozinku</span><input type="password" name="password_confirmation" class="field-input" autocomplete="new-password"></label>
                                    </div>
                                    <label class="settings-check"><input type="hidden" name="is_active" value="0"><input type="checkbox" name="is_active" value="1" @checked($managedUser->is_active) @disabled(auth()->user()->is($managedUser))><span><b>Aktivan račun</b><small>Deaktivacija odmah prekida postojeće sesije.</small></span></label>
                                    @if(auth()->user()->is($managedUser))<input type="hidden" name="is_active" value="1">@endif
                                    <div class="settings-actions"><button class="btn-save">Sačuvaj korisnika</button><small>Zadnja prijava: {{ $managedUser->last_login_at?->format('d.m.Y. H:i') ?? 'nije zabilježena' }}</small></div>
                                </form>
                            </details>
                        @endforeach
                    </div>
                </div>
            </article>

            <article class="settings-panel" data-settings-section id="settings-sessions">
                <x-settings-heading title="Aktivne sesije" description="Pregledaj prijavljene uređaje i prekini pristup koji više nije potreban." icon="security" />
                <div class="settings-audit-wrap"><table class="settings-audit-table"><thead><tr><th>Korisnik</th><th>IP adresa</th><th>Uređaj</th><th>Zadnja aktivnost</th><th></th></tr></thead><tbody>
                    @forelse($sessions as $loginSession)
                        <tr><td><b>{{ $loginSession->user_name ?? 'Nepoznat korisnik' }}</b>@if($loginSession->id === $currentSessionId) <span class="text-emerald-700">· trenutna</span>@endif</td><td>{{ $loginSession->ip_address ?: '—' }}</td><td title="{{ $loginSession->user_agent }}">{{ \Illuminate\Support\Str::limit($loginSession->user_agent ?: 'Nepoznat uređaj', 65) }}</td><td>{{ \Carbon\Carbon::createFromTimestamp($loginSession->last_activity)->diffForHumans() }}</td><td>@if($loginSession->id !== $currentSessionId)<form method="POST" action="{{ route('settings.sessions.destroy', $loginSession->id) }}">@csrf @method('DELETE')<button class="text-xs font-bold text-red-700">Prekini</button></form>@endif</td></tr>
                    @empty<tr><td colspan="5" class="settings-empty">Nema aktivnih sesija.</td></tr>@endforelse
                </tbody></table></div>
            </article>

            <article class="settings-panel" data-settings-section id="settings-gis">
                <x-settings-heading title="GIS slojevi" description="Uvezi cestovne koridore i ograničenja koja vode automatsko trasiranje." icon="gis" />
                <form method="POST" action="{{ route('gis.import') }}" enctype="multipart/form-data" class="settings-panel-body">
                    @csrf
                    <div class="settings-fields-grid settings-fields-grid-gis">
                        <label><span>Projekat</span><select name="project_id" id="gis-project-select" class="field-input" required>@forelse($projects as $project)<option value="{{ $project->id }}">{{ $project->name }}</option>@empty<option value="">Nema dostupnih projekata</option>@endforelse</select></label>
                        <label><span>Tip sloja</span><select name="segment_type" class="field-input"><option value="road">Ceste / putevi</option><option value="corridor">Dozvoljeni koridor</option><option value="sidewalk">Trotoar / ivica puta</option><option value="restricted">Zabranjena zona</option></select></label>
                        <label class="settings-file-field"><span>GeoJSON fajl</span><input type="file" name="geojson" accept=".geojson,.json,application/geo+json,application/json" class="field-input" required></label>
                    </div>
                    <label class="settings-check"><input type="checkbox" name="replace_existing" value="1"><span><b>Zamijeni postojeći sloj</b><small>Prethodni podaci istog tipa bit će uklonjeni.</small></span></label>
                    <div class="settings-actions"><button class="btn-save" {{ $projects->isEmpty() ? 'disabled' : '' }}>Učitaj GIS sloj</button><small>GeoJSON · LineString, MultiLineString i Polygon</small></div>
                </form>
                <div class="settings-layers"><div><b>Uvezeni slojevi</b><span>Za trenutno odabrani projekat</span></div><div id="gis-layers-list" class="settings-layer-list"></div></div>
            </article>

            <div class="settings-maintenance-grid" data-settings-section id="settings-maintenance">
                <article class="settings-panel">
                    <x-settings-heading title="Status sistema" description="Tehničke informacije trenutne instalacije." icon="system" />
                    <div class="settings-panel-body"><dl class="settings-facts">
                        @foreach ([['Aplikacija', 'FTTH Manager'], ['Verzija', '1.8.0'], ['Mapa', 'Leaflet + Esri / OSM'], ['Baza', 'SQLite'], ['Okruženje', app()->environment()], ['PHP', PHP_VERSION], ['Laravel', app()->version()]] as [$key, $value])
                            <div><dt>{{ $key }}</dt><dd>{{ $value }}</dd></div>
                        @endforeach
                    </dl></div>
                </article>
                <article class="settings-panel settings-backup-panel">
                    <x-settings-heading title="Sigurnosna kopija" description="Preuzmi kompletnu kopiju baze podataka." icon="backup" />
                    <div class="settings-panel-body">
                        <div class="settings-backup-visual"><span>DB</span><div><b>database.sqlite</b><small>{{ $databaseInfo['exists'] ? number_format($databaseInfo['size'] / 1024, 0, ',', '.') . ' KB' : 'Baza nije pronađena' }}</small></div></div>
                        @if($databaseInfo['modifiedAt'])<p>Zadnja izmjena: <b>{{ date('d.m.Y. H:i', $databaseInfo['modifiedAt']) }}</b></p>@endif
                        <a href="{{ route('settings.backup') }}" class="btn-save {{ $databaseInfo['exists'] ? '' : 'pointer-events-none opacity-50' }}">Preuzmi backup</a>
                    </div>
                </article>
            </div>

            <article class="settings-panel" data-settings-section id="settings-audit">
                <x-settings-heading title="Audit promjena" description="Pretraživa evidencija uspješnih izmjena u aplikaciji." icon="audit" />
                <form method="GET" action="{{ route('settings.index') }}" class="settings-panel-body">
                    <div class="settings-fields-grid">
                        <label><span>Korisnik</span><select name="audit_user" class="field-input"><option value="">Svi korisnici</option>@foreach($users as $auditUser)<option value="{{ $auditUser->id }}" @selected(request('audit_user') == $auditUser->id)>{{ $auditUser->name }}</option>@endforeach</select></label>
                        <label><span>Projekat</span><select name="audit_project" class="field-input"><option value="">Svi projekti</option>@foreach($projects as $project)<option value="{{ $project->id }}" @selected(request('audit_project') == $project->id)>{{ $project->name }}</option>@endforeach</select></label>
                        <label><span>Metoda</span><select name="audit_method" class="field-input"><option value="">Sve metode</option>@foreach(['POST','PUT','PATCH','DELETE'] as $method)<option @selected(request('audit_method') === $method)>{{ $method }}</option>@endforeach</select></label>
                        <label><span>Od datuma</span><input type="date" name="audit_from" value="{{ request('audit_from') }}" class="field-input"></label>
                        <label><span>Do datuma</span><input type="date" name="audit_to" value="{{ request('audit_to') }}" class="field-input"></label>
                    </div>
                    <div class="settings-actions"><button class="btn-save">Primijeni filtere</button><a href="{{ route('settings.index') }}#settings-audit" class="text-sm font-bold text-slate-600">Očisti</a><a href="{{ route('settings.audit.export', request()->only(['audit_user','audit_project','audit_method','audit_from','audit_to'])) }}" class="text-sm font-bold text-sky-700">Izvezi CSV</a></div>
                </form>
                <div class="settings-audit-wrap"><table class="settings-audit-table"><thead><tr><th>Vrijeme</th><th>Korisnik</th><th>Akcija</th><th>Projekat / objekat</th><th>Ruta</th><th>Status</th></tr></thead><tbody>
                    @forelse($activityLogs as $log)<tr><td>{{ $log->created_at->format('d.m.Y H:i:s') }}</td><td><b>{{ $log->user?->name ?? 'Sistem' }}</b></td><td><span class="settings-method">{{ $log->method }}</span></td><td>{{ $log->project?->name ?? '—' }}@if($log->subject_type)<small class="block">{{ $log->subject_type }} #{{ $log->subject_id }}</small>@endif</td><td>{{ $log->route_name ?? $log->path }}</td><td><span class="settings-status-dot"></span>{{ $log->status_code }}</td></tr>@empty<tr><td colspan="6" class="settings-empty">Nema zapisa za odabrane filtere.</td></tr>@endforelse
                </tbody></table></div>
                <div class="settings-panel-body">{{ $activityLogs->links() }}</div>
            </article>
        </div>
    </div>
</section>

@push('scripts')
<script nonce="{{ Vite::cspNonce() }}">
(function () {
    const form = document.getElementById('settings-display');
    const fields = ['compactTables', 'smallMarkers', 'notifications', 'showOccupancyColors'];
    let saved = {};
    try { saved = JSON.parse(localStorage.getItem('ftthSettings') || '{}'); } catch (_) {}
    fields.forEach(name => { if (saved[name] !== undefined) form.elements[name].checked = Boolean(saved[name]); });
    form.addEventListener('submit', event => {
        event.preventDefault();
        const next = Object.fromEntries(fields.map(name => [name, form.elements[name]?.checked ?? false]));
        localStorage.setItem('ftthSettings', JSON.stringify(next));
        document.documentElement.classList.toggle('compact-tables', next.compactTables);
        document.documentElement.classList.toggle('small-markers', next.smallMarkers);
        document.documentElement.classList.toggle('hide-header-notifications', !next.notifications);
        const status = document.getElementById('settings-status');
        status.textContent = '✓ Postavke su sačuvane';
        setTimeout(() => { status.textContent = ''; }, 3000);
    });
})();

(function () {
    const select = document.getElementById('gis-project-select');
    const list = document.getElementById('gis-layers-list');
    if (!select || !list || !select.value) { if (list) list.innerHTML = '<div class="settings-empty-state">Nema dostupnih projekata.</div>'; return; }
    const labels = {road:'Ceste / putevi', corridor:'Dozvoljeni koridor', sidewalk:'Trotoar / ivica puta', restricted:'Zabranjena zona (linije)', restricted_areas:'Zabranjena zona (poligoni)'};
    const base = @json(url('/postavke/gis/__ID__/slojevi'));
    async function load() {
        list.innerHTML = '<div class="settings-empty-state">Učitavanje slojeva...</div>';
        try { const response = await fetch(base.replace('__ID__', select.value), {headers:{Accept:'application/json'}}); if (!response.ok) throw new Error(); const data = await response.json(); render(data.layers || []); }
        catch (_) { list.innerHTML = '<div class="settings-empty-state is-error">Slojevi trenutno nisu dostupni.</div>'; }
    }
    function render(layers) {
        if (!layers.length) { list.innerHTML = '<div class="settings-empty-state">Nema uvezenih slojeva za ovaj projekat.</div>'; return; }
        list.innerHTML = layers.map(layer => `<div class="settings-layer"><span><b>${labels[layer.type] || layer.type}</b><small>${layer.count} objekata${layer.length_m !== null ? ' · '+layer.length_m+' m' : ''}</small></span><button type="button" data-delete-layer="${layer.type}">Obriši</button></div>`).join('');
    }
    list.addEventListener('click', async event => {
        const button = event.target.closest('[data-delete-layer]'); if (!button || !await window.ftthConfirm(`Obrisati sloj "${labels[button.dataset.deleteLayer] || button.dataset.deleteLayer}"?`, {title:'Brisanje GIS sloja',detail:'Automatsko GIS planiranje više neće koristiti podatke tog sloja.',confirmLabel:'Obriši sloj',danger:true})) return;
        button.disabled = true;
        try { const response = await fetch(`${base.replace('__ID__', select.value)}/${button.dataset.deleteLayer}`, {method:'DELETE', headers:{Accept:'application/json','X-CSRF-TOKEN':document.querySelector('meta[name="csrf-token"]')?.content || '','X-Requested-With':'XMLHttpRequest'}}); if (!response.ok) throw new Error('Brisanje nije uspjelo.'); await load(); }
        catch (error) { alert(error.message); button.disabled = false; }
    });
    select.addEventListener('change', load); load();
})();

(function () {
    const links = [...document.querySelectorAll('.settings-nav-link')];
    const sections = links.map(link => document.querySelector(link.hash)).filter(Boolean);
    if (!('IntersectionObserver' in window)) return;
    const observer = new IntersectionObserver(entries => entries.forEach(entry => { if (entry.isIntersecting) { links.forEach(link => link.classList.toggle('is-active', link.hash === `#${entry.target.id}`)); } }), {root:document.querySelector('.app-content'), rootMargin:'-15% 0px -70% 0px'});
    sections.forEach(section => observer.observe(section));
})();
</script>
@endpush
@endsection
