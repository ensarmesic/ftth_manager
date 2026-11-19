@extends('ftth.layout')
@section('title', 'Postavke')
@section('subtitle', 'Račun, korisnici, GIS podaci, backup i evidencija promjena.')
@section('content')
@php
    $roleLabels = ['administrator' => 'Administrator', 'designer' => 'Projektant', 'field' => 'Teren', 'viewer' => 'Pregled'];
    $me = auth()->user();
    $activeUsers = $users->where('is_active', true)->count();
    $adminsWithout2fa = $users->where('is_active', true)->where('role', 'administrator')->whereNull('two_factor_confirmed_at')->count();
    $device = function (?string $agent): string {
        $agent = (string) $agent;
        $browser = match (true) {
            str_contains($agent, 'Edg/') => 'Edge',
            str_contains($agent, 'OPR/') => 'Opera',
            str_contains($agent, 'Firefox/') => 'Firefox',
            str_contains($agent, 'Chrome/') => 'Chrome',
            str_contains($agent, 'Safari/') => 'Safari',
            default => null,
        };
        $os = match (true) {
            str_contains($agent, 'Windows') => 'Windows',
            str_contains($agent, 'Android') => 'Android',
            str_contains($agent, 'iPhone') || str_contains($agent, 'iPad') => 'iOS',
            str_contains($agent, 'Mac OS') => 'macOS',
            str_contains($agent, 'Linux') => 'Linux',
            default => null,
        };

        return $browser || $os ? trim(($browser ?? 'Browser').' · '.($os ?? '')) : \Illuminate\Support\Str::limit($agent ?: 'Nepoznat uređaj', 40);
    };
    // Which section to open first: the one a submitted form or filter came back to.
    $initialTab = match (true) {
        request()->hasAny(['audit_user', 'audit_project', 'audit_method', 'audit_from', 'audit_to', 'page']) => 'audit',
        $errors->hasAny(['name', 'username', 'email', 'role', 'user']) || old('name') !== null => 'users',
        $errors->hasAny(['current_password', 'password']) => 'security',
        default => 'overview',
    };
    $nav = [
        'Račun' => [
            ['overview', 'Pregled', 'Stanje sistema'],
            ['display', 'Prikaz', 'Izgled aplikacije'],
            ['security', 'Sigurnost', 'Lozinka i 2FA'],
        ],
        'Tim' => [
            ['users', 'Korisnici', $users->count().' računa'],
            ['sessions', 'Sesije', $sessions->count().' prijavljeno'],
        ],
        'Podaci' => [
            ['gis', 'GIS slojevi', 'Ceste i zabrane'],
            ['system', 'Backup i sistem', 'Kopija baze'],
        ],
        'Evidencija' => [
            ['audit', 'Audit promjena', $activityLogs->total().' zapisa'],
        ],
    ];
    $icons = [
        'overview' => '<path d="M3 13h8V3H3v10zm0 8h8v-6H3v6zm10 0h8V11h-8v10zm0-18v6h8V3h-8z"/>',
        'display' => '<rect x="3" y="4" width="18" height="12" rx="2"/><path d="M8 20h8M12 16v4"/>',
        'security' => '<rect x="5" y="10" width="14" height="10" rx="2"/><path d="M8 10V7a4 4 0 018 0v3"/>',
        'users' => '<path d="M16 21v-2a4 4 0 00-4-4H6a4 4 0 00-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 00-3-3.87M16 3.13a4 4 0 010 7.75"/>',
        'sessions' => '<rect x="2" y="4" width="20" height="13" rx="2"/><path d="M8 21h8"/>',
        'gis' => '<path d="M9 18l-6 3V6l6-3 6 3 6-3v15l-6 3-6-3zM9 3v15M15 6v15"/>',
        'system' => '<ellipse cx="12" cy="5" rx="8" ry="3"/><path d="M4 5v6c0 1.66 3.58 3 8 3s8-1.34 8-3V5M4 11v6c0 1.66 3.58 3 8 3s8-1.34 8-3v-6"/>',
        'audit' => '<path d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2M9 14l2 2 4-4"/>',
    ];
@endphp

<div class="st-shell" data-initial-tab="{{ $initialTab }}">
    <nav class="st-nav" aria-label="Sekcije postavki">
        @foreach($nav as $group => $items)
            <div class="st-nav-group">{{ $group }}</div>
            @foreach($items as [$key, $label, $hint])
                <a href="#{{ $key }}" class="st-nav-link" data-st-tab="{{ $key }}">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">{!! $icons[$key] !!}</svg>
                    <span><b>{{ $label }}</b><small>{{ $hint }}</small></span>
                    @if($key === 'security' && ! $me->two_factor_confirmed_at)<i class="st-dot" title="2FA nije uključen"></i>@endif
                </a>
            @endforeach
        @endforeach
    </nav>

    <div class="st-content">

        {{-- PREGLED --}}
        <section class="st-panel" id="overview" data-st-panel>
            <div class="st-panel-hd"><div><h2>Pregled</h2><p>Stanje sistema i stvari koje traže pažnju.</p></div></div>
            <div class="st-cards">
                <a href="#users" data-st-go="users" class="st-card"><small>Korisnici</small><b>{{ $activeUsers }}</b><span>aktivnih od {{ $users->count() }}</span></a>
                <a href="#sessions" data-st-go="sessions" class="st-card"><small>Prijavljeni uređaji</small><b>{{ $sessions->count() }}</b><span>aktivne sesije</span></a>
                <a href="#security" data-st-go="security" class="st-card {{ $me->two_factor_confirmed_at ? 'is-good' : 'is-warn' }}"><small>Tvoj 2FA</small><b>{{ $me->two_factor_confirmed_at ? 'Uključen' : 'Isključen' }}</b><span>{{ $me->two_factor_confirmed_at ? 'račun je dodatno zaštićen' : 'preporučeno uključiti' }}</span></a>
                <a href="#system" data-st-go="system" class="st-card"><small>Baza podataka</small><b>{{ $databaseInfo['exists'] ? number_format($databaseInfo['size'] / 1048576, 1, ',', '.').' MB' : '—' }}</b><span>{{ $databaseInfo['engine'] }} · {{ $projects->count() }} projekata</span></a>
            </div>
            @if($adminsWithout2fa)
                <div class="st-alert is-warn"><b>{{ $adminsWithout2fa }} {{ $adminsWithout2fa === 1 ? 'administrator nema' : 'administratora nema' }} uključen 2FA.</b> Administrator može mijenjati sve podatke, pa je dodatni kod preporučen.</div>
            @endif
            <div class="st-block">
                <div class="st-block-hd"><h3>Zadnje izmjene</h3><a href="#audit" data-st-go="audit">Sve izmjene →</a></div>
                <ul class="st-feed">
                    @forelse($activityLogs->take(6) as $log)
                        <li><span class="st-method st-method-{{ strtolower($log->method) }}">{{ $log->method }}</span><span><b>{{ $log->user?->name ?? 'Sistem' }}</b> · {{ $log->route_name ?? $log->path }}@if($log->project) · {{ $log->project->name }}@endif</span><time>{{ $log->created_at->diffForHumans() }}</time></li>
                    @empty
                        <li class="st-empty">Još nema zabilježenih izmjena.</li>
                    @endforelse
                </ul>
            </div>
        </section>

        {{-- PRIKAZ --}}
        <section class="st-panel" id="display" data-st-panel>
            <div class="st-panel-hd"><div><h2>Prikaz</h2><p>Vrijedi samo u ovom browseru i čuva se odmah.</p></div><span id="settings-status" class="st-saved" role="status"></span></div>
            <form id="settings-display" class="st-toggles">
                @foreach([
                    ['compactTables', 'Kompaktne tabele', 'Više redova na ekranu uz manju visinu reda.', false],
                    ['smallMarkers', 'Manje oznake na mapi', 'Kompaktniji ODF, ODO i markeri kuća.', true],
                    ['notifications', 'Brojač obavještenja', 'Upozorenja na zvonu u gornjoj traci.', true],
                    ['showOccupancyColors', 'Boje zauzetosti ODO-a', 'Zelena, žuta i crvena prema popunjenosti.', true],
                ] as [$name, $label, $desc, $default])
                    <label class="st-toggle">
                        <span><b>{{ $label }}</b><small>{{ $desc }}</small></span>
                        <input type="checkbox" name="{{ $name }}" {{ $default ? 'checked' : '' }}>
                        <i aria-hidden="true"></i>
                    </label>
                @endforeach
            </form>
        </section>

        {{-- SIGURNOST --}}
        <section class="st-panel" id="security" data-st-panel>
            <div class="st-panel-hd"><div><h2>Sigurnost</h2><p>Lozinka i dvofaktorska prijava za tvoj račun ({{ $me->name }}).</p></div></div>
            <div class="st-split">
                <form method="POST" action="{{ route('password.update') }}" class="st-block">
                    @csrf @method('PUT')
                    <div class="st-block-hd"><h3>Promjena lozinke</h3></div>
                    <div class="st-fields">
                        <label><span>Trenutna lozinka</span><input type="password" name="current_password" autocomplete="current-password" class="field-input" required></label>
                        <label><span>Nova lozinka</span><input type="password" name="password" autocomplete="new-password" class="field-input" required></label>
                        <label><span>Ponovi novu lozinku</span><input type="password" name="password_confirmation" autocomplete="new-password" class="field-input" required></label>
                    </div>
                    <div class="st-actions"><button class="btn-save">Promijeni lozinku</button><small>Najmanje 12 znakova, velika i mala slova i broj.</small></div>
                </form>
                <div class="st-block">
                    <div class="st-block-hd"><h3>Dvofaktorska prijava (2FA)</h3><span class="st-badge {{ $me->two_factor_confirmed_at ? 'is-good' : 'is-warn' }}">{{ $me->two_factor_confirmed_at ? 'Uključena' : 'Isključena' }}</span></div>
                    <p class="st-text">Uz lozinku se traži i šestocifreni kod iz aplikacije na telefonu (Google Authenticator, Microsoft Authenticator…). Račun ostaje zaštićen i ako lozinka procuri.</p>
                    @if ($me->two_factor_confirmed_at)
                        <form method="POST" action="{{ route('two-factor.destroy') }}" class="st-fields">
                            @csrf @method('DELETE')
                            <label><span>Trenutna lozinka za isključivanje</span><input type="password" name="current_password" autocomplete="current-password" class="field-input" required></label>
                            <div class="st-actions"><button class="btn-save st-btn-danger">Isključi 2FA</button></div>
                        </form>
                    @else
                        <div class="st-actions"><a href="{{ route('two-factor.setup') }}" class="btn-save">Uključi 2FA</a></div>
                    @endif
                </div>
            </div>
        </section>

        {{-- KORISNICI --}}
        <section class="st-panel" id="users" data-st-panel>
            <div class="st-panel-hd">
                <div><h2>Korisnici</h2><p>{{ $activeUsers }} aktivnih od {{ $users->count() }}. Uloga određuje šta korisnik smije.</p></div>
                <button type="button" class="btn-save" data-st-toggle="st-new-user">+ Novi korisnik</button>
            </div>
            @if($errors->has('user'))<div class="st-alert is-bad">{{ $errors->first('user') }}</div>@endif

            <form method="POST" action="{{ route('settings.users.store') }}" id="st-new-user" class="st-block st-new-user" @unless($errors->hasAny(['name', 'username', 'email', 'role', 'password']) && old('name') !== null) hidden @endunless>
                @csrf
                <div class="st-block-hd"><h3>Novi korisnik</h3></div>
                <div class="st-fields st-fields-3">
                    <label><span>Ime i prezime</span><input name="name" value="{{ old('name') }}" class="field-input" required maxlength="120"></label>
                    <label><span>Korisničko ime</span><input name="username" value="{{ old('username') }}" class="field-input" required maxlength="80" autocomplete="off"></label>
                    <label><span>Email</span><input type="email" name="email" value="{{ old('email') }}" class="field-input" required></label>
                    <label><span>Uloga</span><select name="role" class="field-input">@foreach($roleLabels as $value => $label)<option value="{{ $value }}" @selected(old('role', 'designer') === $value)>{{ $label }}</option>@endforeach</select></label>
                    <label><span>Početna lozinka</span><input type="password" name="password" class="field-input" required autocomplete="new-password"></label>
                    <label><span>Ponovi lozinku</span><input type="password" name="password_confirmation" class="field-input" required autocomplete="new-password"></label>
                </div>
                @if($errors->any() && ! $errors->has('user') && old('name') !== null)<div class="st-alert is-bad">{{ $errors->first() }}</div>@endif
                <div class="st-actions"><button class="btn-save">Kreiraj korisnika</button><button type="button" class="st-link-btn" data-st-toggle="st-new-user">Odustani</button></div>
            </form>

            <div class="st-roles">
                <span><b>Administrator</b> sve, uključujući postavke i brisanje</span>
                <span><b>Projektant</b> projektuje, izvozi i pravi backup projekta</span>
                <span><b>Teren</b> pregled i unos terenskih tačaka</span>
                <span><b>Pregled</b> samo gleda i piše napomene na mapi</span>
            </div>

            <div class="st-table-wrap">
                <table class="st-table">
                    <thead><tr><th>Korisnik</th><th>Uloga</th><th>Status</th><th>2FA</th><th>Zadnja prijava</th><th></th></tr></thead>
                    <tbody>
                    @foreach($users as $managedUser)
                        <tr class="{{ $managedUser->is_active ? '' : 'is-off' }}">
                            <td><span class="st-avatar">{{ mb_strtoupper(mb_substr($managedUser->name, 0, 1)) }}</span><span class="st-user"><b>{{ $managedUser->name }}@if($me->is($managedUser)) <em>(ti)</em>@endif</b><small>{{ '@'.$managedUser->username }} · {{ $managedUser->email }}</small></span></td>
                            <td><span class="st-role st-role-{{ $managedUser->role }}">{{ $roleLabels[$managedUser->role] ?? $managedUser->role }}</span></td>
                            <td><span class="st-badge {{ $managedUser->is_active ? 'is-good' : '' }}">{{ $managedUser->is_active ? 'Aktivan' : 'Neaktivan' }}</span></td>
                            <td>{{ $managedUser->two_factor_confirmed_at ? 'Da' : '—' }}</td>
                            <td>{{ $managedUser->last_login_at?->format('d.m.Y. H:i') ?? 'nikad' }}</td>
                            <td class="st-right"><button type="button" class="st-link-btn" data-st-toggle="st-edit-{{ $managedUser->id }}">Uredi</button></td>
                        </tr>
                        <tr id="st-edit-{{ $managedUser->id }}" class="st-edit-row" hidden>
                            <td colspan="6">
                                <form method="POST" action="{{ route('settings.users.update', $managedUser) }}">
                                    @csrf @method('PUT')
                                    <div class="st-fields st-fields-3">
                                        <label><span>Ime i prezime</span><input name="name" value="{{ $managedUser->name }}" class="field-input" required maxlength="120"></label>
                                        <label><span>Korisničko ime</span><input name="username" value="{{ $managedUser->username }}" class="field-input" required maxlength="80"></label>
                                        <label><span>Email</span><input type="email" name="email" value="{{ $managedUser->email }}" class="field-input" required></label>
                                        <label><span>Uloga</span><select name="role" class="field-input">@foreach($roleLabels as $value => $label)<option value="{{ $value }}" @selected($managedUser->role === $value)>{{ $label }}</option>@endforeach</select></label>
                                        <label><span>Nova lozinka (opcionalno)</span><input type="password" name="password" class="field-input" autocomplete="new-password"></label>
                                        <label><span>Ponovi novu lozinku</span><input type="password" name="password_confirmation" class="field-input" autocomplete="new-password"></label>
                                    </div>
                                    <label class="st-check"><input type="hidden" name="is_active" value="0"><input type="checkbox" name="is_active" value="1" @checked($managedUser->is_active) @disabled($me->is($managedUser))><span><b>Aktivan račun</b><small>Deaktivacija odmah odjavljuje korisnika sa svih uređaja.</small></span></label>
                                    @if($me->is($managedUser))<input type="hidden" name="is_active" value="1">@endif
                                    <div class="st-actions"><button class="btn-save">Sačuvaj</button><button type="button" class="st-link-btn" data-st-toggle="st-edit-{{ $managedUser->id }}">Zatvori</button></div>
                                </form>
                            </td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        </section>

        {{-- SESIJE --}}
        <section class="st-panel" id="sessions" data-st-panel>
            <div class="st-panel-hd"><div><h2>Sesije</h2><p>Uređaji na kojima je neko prijavljen. Prekini sesiju koju ne prepoznaješ.</p></div></div>
            <div class="st-table-wrap">
                <table class="st-table">
                    <thead><tr><th>Korisnik</th><th>Uređaj</th><th>IP adresa</th><th>Zadnja aktivnost</th><th></th></tr></thead>
                    <tbody>
                    @forelse($sessions as $loginSession)
                        <tr>
                            <td><b>{{ $loginSession->user_name ?? 'Nepoznat korisnik' }}</b>@if($loginSession->id === $currentSessionId) <span class="st-badge is-good">ovaj uređaj</span>@endif</td>
                            <td title="{{ $loginSession->user_agent }}">{{ $device($loginSession->user_agent) }}</td>
                            <td>{{ $loginSession->ip_address ?: '—' }}</td>
                            <td>{{ \Carbon\Carbon::createFromTimestamp($loginSession->last_activity)->diffForHumans() }}</td>
                            <td class="st-right">@if($loginSession->id !== $currentSessionId)<form method="POST" action="{{ route('settings.sessions.destroy', $loginSession->id) }}">@csrf @method('DELETE')<button class="st-link-btn is-danger">Prekini</button></form>@endif</td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="st-empty">Nema aktivnih sesija.</td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
        </section>

        {{-- GIS --}}
        <section class="st-panel" id="gis" data-st-panel>
            <div class="st-panel-hd"><div><h2>GIS slojevi</h2><p>Ceste, koridori i zabranjene zone koje automatsko trasiranje prati.</p></div></div>
            <div class="st-split">
                <form method="POST" action="{{ route('gis.import') }}" enctype="multipart/form-data" class="st-block">
                    @csrf
                    <div class="st-block-hd"><h3>Uvoz GeoJSON sloja</h3></div>
                    <div class="st-fields">
                        <label><span>Projekat</span><select name="project_id" id="gis-project-select" class="field-input" required>@forelse($projects as $project)<option value="{{ $project->id }}">{{ $project->name }}</option>@empty<option value="">Nema dostupnih projekata</option>@endforelse</select></label>
                        <label><span>Tip sloja</span><select name="segment_type" class="field-input"><option value="road">Ceste / putevi</option><option value="corridor">Dozvoljeni koridor</option><option value="sidewalk">Trotoar / ivica puta</option><option value="restricted">Zabranjena zona</option></select></label>
                        <label><span>GeoJSON fajl</span><input type="file" name="geojson" accept=".geojson,.json,application/geo+json,application/json" class="field-input" required></label>
                    </div>
                    <label class="st-check"><input type="checkbox" name="replace_existing" value="1"><span><b>Zamijeni postojeći sloj</b><small>Prethodni podaci istog tipa se brišu.</small></span></label>
                    <div class="st-actions"><button class="btn-save" {{ $projects->isEmpty() ? 'disabled' : '' }}>Učitaj sloj</button><small>LineString, MultiLineString i Polygon</small></div>
                </form>
                <div class="st-block">
                    <div class="st-block-hd"><h3>Uvezeni slojevi</h3><small>za projekat odabran lijevo</small></div>
                    <div id="gis-layers-list" class="st-layers"></div>
                </div>
            </div>
        </section>

        {{-- BACKUP I SISTEM --}}
        <section class="st-panel" id="system" data-st-panel>
            <div class="st-panel-hd"><div><h2>Backup i sistem</h2><p>Kopija baze i podaci o instalaciji.</p></div></div>
            <div class="st-split">
                <div class="st-block st-backup">
                    <div class="st-block-hd"><h3>Sigurnosna kopija baze</h3></div>
                    <div class="st-db"><span>DB</span><div><b>{{ $databaseInfo['label'] }}</b><small>{{ $databaseInfo['exists'] ? number_format($databaseInfo['size'] / 1048576, 1, ',', '.').' MB' : 'Baza nije pronađena' }}@if($databaseInfo['modifiedAt']) · izmijenjena {{ date('d.m.Y. H:i', $databaseInfo['modifiedAt']) }}@endif</small></div></div>
                    <p class="st-text">Preuzima kompletnu kopiju svih projekata i korisnika u jedan fajl. Čuvaj ga na sigurnom mjestu.</p>
                    <div class="st-actions"><a href="{{ route('settings.backup') }}" class="btn-save {{ $databaseInfo['exists'] ? '' : 'pointer-events-none opacity-50' }}">Preuzmi backup</a></div>
                </div>
                <div class="st-block">
                    <div class="st-block-hd"><h3>Instalacija</h3></div>
                    <dl class="st-facts">
                        @foreach ([['Aplikacija', 'FTTH Manager '.config('app.version')], ['Baza', $databaseInfo['engine']], ['Okruženje', app()->environment()], ['Mapa', 'Leaflet + Esri / OSM'], ['PHP', PHP_VERSION], ['Laravel', app()->version()]] as [$key, $value])
                            <div><dt>{{ $key }}</dt><dd>{{ $value }}</dd></div>
                        @endforeach
                    </dl>
                </div>
            </div>
        </section>

        {{-- AUDIT --}}
        <section class="st-panel" id="audit" data-st-panel>
            <div class="st-panel-hd">
                <div><h2>Audit promjena</h2><p>Ko je, kada i šta mijenjao. {{ $activityLogs->total() }} zapisa za odabrani filter.</p></div>
                <a href="{{ route('settings.audit.export', request()->only(['audit_user','audit_project','audit_method','audit_from','audit_to'])) }}" class="st-link-btn">Izvezi CSV</a>
            </div>
            <form method="GET" action="{{ route('settings.index') }}#audit" class="st-filter">
                <select name="audit_user" class="field-input" aria-label="Korisnik"><option value="">Svi korisnici</option>@foreach($users as $auditUser)<option value="{{ $auditUser->id }}" @selected(request('audit_user') == $auditUser->id)>{{ $auditUser->name }}</option>@endforeach</select>
                <select name="audit_project" class="field-input" aria-label="Projekat"><option value="">Svi projekti</option>@foreach($projects as $project)<option value="{{ $project->id }}" @selected(request('audit_project') == $project->id)>{{ $project->name }}</option>@endforeach</select>
                <select name="audit_method" class="field-input" aria-label="Vrsta"><option value="">Sve vrste</option>@foreach(['POST' => 'Dodavanje', 'PUT' => 'Izmjena', 'PATCH' => 'Djelimična izmjena', 'DELETE' => 'Brisanje'] as $method => $methodLabel)<option value="{{ $method }}" @selected(request('audit_method') === $method)>{{ $methodLabel }}</option>@endforeach</select>
                <input type="date" name="audit_from" value="{{ request('audit_from') }}" class="field-input" aria-label="Od datuma">
                <input type="date" name="audit_to" value="{{ request('audit_to') }}" class="field-input" aria-label="Do datuma">
                <button class="btn-save">Filtriraj</button>
                @if(request()->hasAny(['audit_user','audit_project','audit_method','audit_from','audit_to']))<a href="{{ route('settings.index') }}#audit" class="st-link-btn">Očisti</a>@endif
            </form>
            <div class="st-table-wrap">
                <table class="st-table">
                    <thead><tr><th>Vrijeme</th><th>Korisnik</th><th>Vrsta</th><th>Projekat / objekat</th><th>Ruta</th><th>Status</th></tr></thead>
                    <tbody>
                    @forelse($activityLogs as $log)
                        <tr>
                            <td class="st-nowrap">{{ $log->created_at->format('d.m.Y. H:i') }}</td>
                            <td><b>{{ $log->user?->name ?? 'Sistem' }}</b></td>
                            <td><span class="st-method st-method-{{ strtolower($log->method) }}">{{ $log->method }}</span></td>
                            <td>{{ $log->project?->name ?? '—' }}@if($log->subject_type)<small>{{ $log->subject_type }} #{{ $log->subject_id }}</small>@endif</td>
                            <td class="st-mono">{{ $log->route_name ?? $log->path }}</td>
                            <td><span class="st-status {{ $log->status_code >= 400 ? 'is-bad' : '' }}">{{ $log->status_code }}</span></td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="st-empty">Nema zapisa za odabrani filter.</td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
            @if($activityLogs->hasPages())<div class="st-pages">{{ $activityLogs->fragment('audit')->links() }}</div>@endif
        </section>
    </div>
</div>

@push('scripts')
<script nonce="{{ Vite::cspNonce() }}">
(function () {
    // Sekcije: prikazuje se jedna, izbor ostaje u adresi (#korisnici) pa radi i nazad/naprijed.
    const shell = document.querySelector('.st-shell');
    const panels = [...document.querySelectorAll('[data-st-panel]')];
    const links = [...document.querySelectorAll('[data-st-tab]')];
    const keys = panels.map(panel => panel.id);
    shell.classList.add('st-js');
    const show = (key, scroll) => {
        if (!keys.includes(key)) key = shell.dataset.initialTab;
        panels.forEach(panel => panel.classList.toggle('is-active', panel.id === key));
        links.forEach(link => link.classList.toggle('is-active', link.dataset.stTab === key));
        if (scroll) document.querySelector('.app-content')?.scrollTo({ top: 0 });
    };
    window.addEventListener('hashchange', () => show(location.hash.slice(1), true));
    show(location.hash.slice(1) || shell.dataset.initialTab, false);
    document.querySelectorAll('[data-st-go]').forEach(link => link.addEventListener('click', event => {
        event.preventDefault();
        history.pushState(null, '', `#${link.dataset.stGo}`);
        show(link.dataset.stGo, true);
    }));
    // Otvaranje/zatvaranje forme za novog korisnika i uređivanje u redu.
    document.addEventListener('click', event => {
        const button = event.target.closest('[data-st-toggle]');
        if (!button) return;
        const target = document.getElementById(button.dataset.stToggle);
        if (!target) return;
        target.hidden = !target.hidden;
        if (!target.hidden) target.querySelector('input:not([type=hidden]), select')?.focus();
    });
})();

(function () {
    // Prikaz se čuva čim se prekidač promijeni.
    const form = document.getElementById('settings-display');
    const fields = ['compactTables', 'smallMarkers', 'notifications', 'showOccupancyColors'];
    let saved = {};
    try { saved = JSON.parse(localStorage.getItem('ftthSettings') || '{}'); } catch (_) {}
    fields.forEach(name => { if (saved[name] !== undefined) form.elements[name].checked = Boolean(saved[name]); });
    let timer;
    form.addEventListener('change', () => {
        const next = Object.fromEntries(fields.map(name => [name, form.elements[name]?.checked ?? false]));
        try { localStorage.setItem('ftthSettings', JSON.stringify(next)); } catch (_) {}
        document.documentElement.classList.toggle('compact-tables', next.compactTables);
        document.documentElement.classList.toggle('small-markers', next.smallMarkers);
        document.documentElement.classList.toggle('hide-header-notifications', !next.notifications);
        const status = document.getElementById('settings-status');
        status.textContent = '✓ Sačuvano';
        clearTimeout(timer);
        timer = setTimeout(() => { status.textContent = ''; }, 2500);
    });
})();

(function () {
    const select = document.getElementById('gis-project-select');
    const list = document.getElementById('gis-layers-list');
    if (!select || !list || !select.value) { if (list) list.innerHTML = '<div class="st-empty">Nema dostupnih projekata.</div>'; return; }
    const labels = {road:'Ceste / putevi', corridor:'Dozvoljeni koridor', sidewalk:'Trotoar / ivica puta', restricted:'Zabranjena zona (linije)', restricted_areas:'Zabranjena zona (poligoni)'};
    const base = @json(url('/postavke/gis/__ID__/slojevi'));
    async function load() {
        list.innerHTML = '<div class="st-empty">Učitavanje slojeva…</div>';
        try { const response = await fetch(base.replace('__ID__', select.value), {headers:{Accept:'application/json'}}); if (!response.ok) throw new Error(); const data = await response.json(); render(data.layers || []); }
        catch (_) { list.innerHTML = '<div class="st-empty is-bad">Slojevi trenutno nisu dostupni.</div>'; }
    }
    function render(layers) {
        if (!layers.length) { list.innerHTML = '<div class="st-empty">Ovaj projekat nema uvezenih slojeva.</div>'; return; }
        list.innerHTML = layers.map(layer => `<div class="st-layer"><span><b>${labels[layer.type] || layer.type}</b><small>${layer.count} objekata${layer.length_m !== null ? ' · '+layer.length_m+' m' : ''}</small></span><button type="button" class="st-link-btn is-danger" data-delete-layer="${layer.type}">Obriši</button></div>`).join('');
    }
    list.addEventListener('click', async event => {
        const button = event.target.closest('[data-delete-layer]'); if (!button || !await window.ftthConfirm(`Obrisati sloj "${labels[button.dataset.deleteLayer] || button.dataset.deleteLayer}"?`, {title:'Brisanje GIS sloja',detail:'Automatsko GIS planiranje više neće koristiti podatke tog sloja.',confirmLabel:'Obriši sloj',danger:true})) return;
        button.disabled = true;
        try { const response = await fetch(`${base.replace('__ID__', select.value)}/${button.dataset.deleteLayer}`, {method:'DELETE', headers:{Accept:'application/json','X-CSRF-TOKEN':document.querySelector('meta[name="csrf-token"]')?.content || '','X-Requested-With':'XMLHttpRequest'}}); if (!response.ok) throw new Error('Brisanje nije uspjelo.'); await load(); }
        catch (error) { window.ftthToast ? window.ftthToast(error.message, 'error') : alert(error.message); button.disabled = false; }
    });
    select.addEventListener('change', load); load();
})();
</script>
@endpush
@endsection
