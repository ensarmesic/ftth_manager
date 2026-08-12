@props(['title', 'description', 'icon'])
<header class="settings-panel-heading">
    <span class="settings-panel-icon">@switch($icon)
        @case('display')<svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><rect x="3" y="4" width="18" height="12" rx="2"/><path d="M8 20h8M12 16v4"/></svg>@break
        @case('security')<svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><rect x="5" y="10" width="14" height="10" rx="2"/><path d="M8 10V7a4 4 0 018 0v3"/></svg>@break
        @case('gis')<svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M9 18l-6 3V6l6-3 6 3 6-3v15l-6 3-6-3zM9 3v15M15 6v15"/></svg>@break
        @case('system')<svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.7 1.7 0 00.34 1.88l.06.06-2.83 2.83-.06-.06A1.7 1.7 0 0015 19.4a1.7 1.7 0 00-1 .6v.08H10V20a1.7 1.7 0 00-1-.6 1.7 1.7 0 00-1.88.34l-.06.06-2.83-2.83.06-.06A1.7 1.7 0 004.6 15a1.7 1.7 0 00-.6-1H4v-4h.08a1.7 1.7 0 00.6-1 1.7 1.7 0 00-.34-1.88l-.06-.06 2.83-2.83.06.06A1.7 1.7 0 009 4.6a1.7 1.7 0 001-.6V4h4v.08a1.7 1.7 0 001 .6 1.7 1.7 0 001.88-.34l.06-.06 2.83 2.83-.06.06A1.7 1.7 0 0019.4 9c.08.4.3.76.6 1h.08v4H20a1.7 1.7 0 00-.6 1z"/></svg>@break
        @case('backup')<svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M12 3v12m0 0l-4-4m4 4l4-4M5 19h14"/></svg>@break
        @default<svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M4 19V9m5 10V5m5 14v-7m5 7V3"/></svg>
    @endswitch</span>
    <span><h2>{{ $title }}</h2><p>{{ $description }}</p></span>
</header>
