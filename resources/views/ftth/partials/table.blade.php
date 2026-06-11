<div class="app-table-card overflow-hidden rounded-md border border-slate-200 bg-white shadow-sm">
    <div class="overflow-x-auto">
        <table class="w-full text-left text-sm">
            <thead class="sticky top-0 z-10 border-b border-slate-200 bg-slate-50 text-xs font-semibold uppercase tracking-wide text-slate-500">
                <tr>
                    @foreach ($columns as $label)
                        <th class="whitespace-nowrap px-4 py-3">{{ $label }}</th>
                    @endforeach
                    @if (isset($deleteRoute) || isset($editRoute))
                        <th class="whitespace-nowrap px-4 py-3">Akcije</th>
                    @endif
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                @forelse ($rows as $row)
                    <tr class="transition hover:bg-blue-50/50">
                        @foreach ($columns as $key => $label)
                            <td class="whitespace-nowrap px-4 py-3 text-slate-700">
                                @if (str_contains((string) $key, 'status'))
                                    @include('ftth.partials.badge', ['value' => data_get($row, $key)])
                                @else
                                    {{ data_get($row, $key) ?? '-' }}
                                @endif
                            </td>
                        @endforeach
                        @if (isset($deleteRoute) || isset($editRoute))
                            <td class="whitespace-nowrap px-4 py-3">
                                <details class="inline-block align-middle">
                                    <summary class="cursor-pointer rounded-md bg-blue-50 px-2.5 py-1.5 text-xs font-semibold text-blue-700 transition hover:bg-blue-100">Pregled</summary>
                                    <div class="absolute z-20 mt-2 grid min-w-64 gap-1 rounded-md border border-slate-200 bg-white p-3 text-xs shadow-xl">
                                        @foreach ($columns as $key => $label)
                                            <div class="grid grid-cols-[110px_1fr] gap-2"><b class="text-slate-500">{{ $label }}</b><span>{{ data_get($row, $key) ?? '-' }}</span></div>
                                        @endforeach
                                    </div>
                                </details>
                                @if (isset($editRoute) && isset($editFields))
                                    <details class="inline-block align-middle">
                                        <summary class="cursor-pointer rounded-md bg-amber-50 px-2.5 py-1.5 text-xs font-semibold text-amber-700 transition hover:bg-amber-100">Uredi</summary>
                                        <form method="POST" action="{{ $editRoute($row->id) }}" class="absolute z-20 mt-2 grid min-w-72 gap-2 rounded-md border border-slate-200 bg-white p-3 shadow-xl">
                                            @csrf
                                            @method('PATCH')
                                            @foreach ($editFields as $field => $config)
                                                @php
                                                    $label = is_array($config) ? ($config['label'] ?? $field) : $config;
                                                    $type = is_array($config) ? ($config['type'] ?? 'text') : 'text';
                                                    $options = is_array($config) ? ($config['options'] ?? []) : [];
                                                @endphp
                                                <label class="grid gap-1 text-xs">
                                                    <span class="font-semibold text-slate-600">{{ $label }}</span>
                                                    @if ($type === 'select')
                                                        <select name="{{ $field }}" class="rounded-md border border-slate-300 px-2 py-1.5">
                                                            @foreach ($options as $value => $optionLabel)
                                                                <option value="{{ $value }}" @selected((string) data_get($row, $field) === (string) $value)>{{ $optionLabel }}</option>
                                                            @endforeach
                                                        </select>
                                                    @else
                                                        <input name="{{ $field }}" type="{{ $type }}" value="{{ data_get($row, $field) }}" class="rounded-md border border-slate-300 px-2 py-1.5">
                                                    @endif
                                                </label>
                                            @endforeach
                                            <button class="rounded-md bg-amber-600 px-3 py-2 text-xs font-semibold text-white">Sacuvaj izmjene</button>
                                        </form>
                                    </details>
                                @endif
                                @if (isset($deleteRoute))
                                <form method="POST" action="{{ $deleteRoute($row->id) }}" style="display:inline;" data-confirm-delete="Sigurno obrisati ovaj zapis?">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" class="rounded-md bg-red-50 px-2.5 py-1.5 text-xs font-semibold text-red-700 transition hover:bg-red-100">Obrisi</button>
                                </form>
                                @endif
                            </td>
                        @endif
                    </tr>
                @empty
                    <tr>
                        <td class="px-4 py-6" colspan="{{ count($columns) + (isset($deleteRoute) || isset($editRoute) ? 1 : 0) }}">
                            @include('ftth.partials.empty-state', ['title' => 'Nema zapisa', 'message' => 'Dodaj prvi zapis kroz formu ili mapu.'])
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
    <div class="border-t border-slate-100 bg-slate-50/60 px-4 py-3">{{ $rows->links() }}</div>
</div>
