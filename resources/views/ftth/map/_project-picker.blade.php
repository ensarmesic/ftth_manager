<div id="project-picker-overlay" @if($activeProjectId) class="hidden" @endif role="dialog" aria-modal="true" aria-labelledby="project-picker-title" aria-describedby="project-picker-description">
    <div id="project-picker-card" tabindex="-1">
        <div class="pp-hd">
            <div id="project-picker-title" class="pp-title">Odaberi projekat</div>
            <div id="project-picker-description" class="pp-sub">Svaki projekat ima svoju zasebnu mapu i nacrt.</div>
        </div>
        @if($projects->count() > 1)
            <div class="pp-search-wrap">
                <input id="pp-project-search" class="pp-search" type="search" placeholder="Pretraži naziv ili lokaciju..." autocomplete="off" aria-label="Pretraži projekte">
            </div>
        @endif
        <div class="pp-list">
            @forelse($projects as $project)
                <div class="pp-row" data-project-search="{{ mb_strtolower($project->name.' '.($project->location ?? '')) }}">
                    <div>
                        <div class="pp-row-name">{{ $project->name }}</div>
                        @if($project->location)
                            <div class="pp-row-meta">{{ $project->location }}</div>
                        @endif
                    </div>
                    <button type="button" class="pp-btn" data-project-id="{{ $project->id }}" aria-label="Odaberi projekat {{ $project->name }}">Odaberi</button>
                </div>
            @empty
                <div class="pp-empty">Nema projekata. Kreiraj prvi projekat ispod.</div>
            @endforelse
        </div>
        <div class="pp-new">
            <div class="pp-new-title">Novi projekat</div>
            <div class="pp-new-row">
                <input id="pp-new-name" class="pp-new-inp" placeholder="Naziv projekta" required>
                <button type="button" id="pp-new-submit" class="pp-new-submit">Kreiraj</button>
            </div>
            <div id="pp-new-status" role="status" aria-live="polite"></div>
        </div>
    </div>
</div>
