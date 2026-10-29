@php
    // Human-readable cell values: optional code => label maps per column, and
    // Bosnian number formatting (1.234,5) for numeric values.
    $valueLabels ??= [];
    $displayValue = function (string $key, mixed $value) use ($valueLabels): string {
        if (is_bool($value)) {
            return $value ? 'Da' : 'Ne';
        }
        if ($value === null || $value === '') {
            return '-';
        }
        if (isset($valueLabels[$key]) && array_key_exists((string) $value, $valueLabels[$key])) {
            return $valueLabels[$key][(string) $value];
        }
        if (is_int($value) || is_float($value)) {
            return number_format($value, floor($value) == $value ? 0 : 1, ',', '.');
        }

        return (string) $value;
    };
@endphp
<div class="app-table-card">
    <div class="overflow-x-auto">
        <table class="w-full text-left">
            <thead>
                <tr>
                    @foreach ($columns as $label)
                        <th>{{ $label }}</th>
                    @endforeach
                    @if (isset($deleteRoute) || isset($editRoute))
                        <th class="tbl-actions">Akcije</th>
                    @endif
                </tr>
            </thead>
            <tbody>
                @forelse ($rows as $row)
                    <tr data-id="{{ $row->id }}">
                        @foreach ($columns as $key => $label)
                            <td>
                                @if (str_contains((string) $key, 'status'))
                                    @include('ftth.partials.badge', ['value' => data_get($row, $key)])
                                @else
                                    {{ $displayValue($key, data_get($row, $key)) }}
                                @endif
                            </td>
                        @endforeach
                        @if (isset($deleteRoute) || isset($editRoute))
                            <td class="tbl-actions">
                                <div class="flex items-center gap-1.5 flex-nowrap">
                                    <details class="relative inline-block">
                                        <summary class="tbl-btn tbl-btn-view list-none cursor-pointer">
                                            <svg viewBox="0 0 16 16" fill="currentColor" class="w-3 h-3"><path d="M8 9.5a1.5 1.5 0 100-3 1.5 1.5 0 000 3z"/><path d="M1.38 8a6.985 6.985 0 0113.24 0A6.985 6.985 0 018 13c-2.87 0-5.356-1.74-6.62-4.323-.015-.024-.015-.024 0 0z"/></svg>
                                            Pregled
                                        </summary>
                                        <div class="absolute right-0 z-20 mt-1.5 grid min-w-64 gap-1 rounded-xl border border-slate-200 bg-white p-3 text-xs shadow-2xl">
                                            @foreach ($columns as $key => $label)
                                                @php $value = data_get($row, $key); @endphp
                                                <div class="grid grid-cols-[110px_1fr] gap-2 py-0.5">
                                                    <b class="text-slate-400 font-semibold">{{ $label }}</b>
                                                    <span class="text-slate-800">{{ $displayValue($key, $value) }}</span>
                                                </div>
                                            @endforeach
                                        </div>
                                    </details>
                                    @if (isset($editRoute) && isset($editFields))
                                        <details class="relative inline-block">
                                            <summary class="tbl-btn tbl-btn-edit list-none cursor-pointer">
                                                <svg viewBox="0 0 16 16" fill="currentColor" class="w-3 h-3"><path d="M11.013 1.427a1.75 1.75 0 012.474 0l1.086 1.086a1.75 1.75 0 010 2.474l-8.61 8.61c-.21.21-.47.364-.756.445l-3.251.93a.75.75 0 01-.927-.928l.929-3.25c.081-.286.235-.547.445-.758l8.61-8.61z"/></svg>
                                                Uredi
                                            </summary>
                                            <form method="POST" action="{{ $editRoute($row->id) }}" class="absolute right-0 z-20 mt-1.5 grid min-w-72 gap-2.5 rounded-xl border border-slate-200 bg-white p-4 shadow-2xl">
                                                @csrf
                                                @method('PATCH')
                                                @foreach ($editFields as $field => $config)
                                                    @php
                                                        $label = is_array($config) ? ($config['label'] ?? $field) : $config;
                                                        $type = is_array($config) ? ($config['type'] ?? 'text') : 'text';
                                                        $options = is_array($config) ? ($config['options'] ?? []) : [];
                                                        // A closure lists only what fits this row (e.g. ODO-i of the row's own project).
                                                        if ($options instanceof \Closure) {
                                                            $options = $options($row);
                                                        }
                                                        // Keep the current value selectable; otherwise the browser
                                                        // picks the first option and saving silently overwrites it.
                                                        $current = data_get($row, $field);
                                                        if ($type === 'select' && ! in_array((string) $current, array_map('strval', array_keys($options)), true)) {
                                                            $options = [(string) $current => $current === null || $current === '' ? '—' : $displayValue($field, $current)] + $options;
                                                        }
                                                    @endphp
                                                    <label class="grid gap-1.5 text-xs font-semibold text-slate-500 uppercase tracking-wide">
                                                        {{ $label }}
                                                        @if ($type === 'select')
                                                            <select name="{{ $field }}" class="ftth-input text-[13px] font-normal normal-case tracking-normal">
                                                                {{-- One line: every row repeats these options, indentation alone doubled the page size. --}}
                                                                @php($currentValue = (string) data_get($row, $field))
                                                                @foreach ($options as $value => $optionLabel)<option value="{{ $value }}" @selected($currentValue === (string) $value)>{{ $optionLabel }}</option>@endforeach
                                                            </select>
                                                        @elseif ($type === 'checkbox')
                                                            <input type="hidden" name="{{ $field }}" value="0">
                                                            <label class="flex items-center gap-2 rounded-lg border border-slate-200 px-3 py-2 font-normal normal-case tracking-normal cursor-pointer">
                                                                <input name="{{ $field }}" type="checkbox" value="1" @checked((bool) data_get($row, $field))>
                                                                <span class="text-slate-700">{{ $config['text'] ?? 'Da' }}</span>
                                                            </label>
                                                        @else
                                                            <input name="{{ $field }}" type="{{ $type }}" value="{{ data_get($row, $field) }}" class="ftth-input font-normal normal-case tracking-normal">
                                                        @endif
                                                    </label>
                                                @endforeach
                                                <button class="btn-save mt-1">Sačuvaj izmjene</button>
                                            </form>
                                        </details>
                                    @endif
                                    @if (isset($deleteRoute))
                                        <form method="POST" action="{{ $deleteRoute($row->id) }}" style="display:inline;" data-confirm-delete="Sigurno obrisati ovaj zapis?">
                                            @csrf
                                            @method('DELETE')
                                            <button type="submit" class="tbl-btn tbl-btn-del">
                                                <svg viewBox="0 0 16 16" fill="currentColor" class="w-3 h-3"><path d="M6.5 1.75a.25.25 0 01.25-.25h2.5a.25.25 0 01.25.25V3h-3V1.75zm4.5 0V3h2.25a.75.75 0 010 1.5H2.75a.75.75 0 010-1.5H5V1.75C5 .784 5.784 0 6.75 0h2.5C10.216 0 11 .784 11 1.75zM4.496 6.675a.75.75 0 10-1.492.15l.66 6.6A1.75 1.75 0 005.405 15h5.19a1.75 1.75 0 001.741-1.575l.66-6.6a.75.75 0 00-1.492-.15l-.66 6.6a.25.25 0 01-.249.225H5.405a.25.25 0 01-.249-.225l-.66-6.6z"/></svg>
                                                Obriši
                                            </button>
                                        </form>
                                    @endif
                                </div>
                            </td>
                        @endif
                    </tr>
                @empty
                    <tr>
                        <td colspan="{{ count($columns) + (isset($deleteRoute) || isset($editRoute) ? 1 : 0) }}">
                            @include('ftth.partials.empty-state', ['title' => 'Nema zapisa', 'message' => 'Dodaj prvi zapis kroz formu ili mapu.'])
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
    <div class="pagination-bar border-t border-slate-100 bg-slate-50/60 px-4 py-3">{{ $rows->links() }}</div>
</div>
