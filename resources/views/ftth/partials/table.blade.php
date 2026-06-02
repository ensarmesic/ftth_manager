<div class="app-table-card overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm">
    <div class="overflow-x-auto">
        <table class="w-full text-left text-sm">
            <thead class="border-b border-slate-200 bg-slate-50 text-xs font-semibold uppercase tracking-wide text-slate-500">
                <tr>
                    @foreach ($columns as $label)
                        <th class="whitespace-nowrap px-4 py-3">{{ $label }}</th>
                    @endforeach
                    @if (isset($deleteRoute))
                        <th class="whitespace-nowrap px-4 py-3">Akcije</th>
                    @endif
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                @forelse ($rows as $row)
                    <tr class="transition hover:bg-blue-50/50">
                        @foreach ($columns as $key => $label)
                            <td class="whitespace-nowrap px-4 py-3 text-slate-700">{{ data_get($row, $key) ?? '-' }}</td>
                        @endforeach
                        @if (isset($deleteRoute))
                            <td class="whitespace-nowrap px-4 py-3">
                                <form method="POST" action="{{ $deleteRoute($row->id) }}" style="display:inline;" onsubmit="return confirm('Sigurno obrisati?');">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" class="rounded-md bg-red-50 px-2.5 py-1.5 text-xs font-semibold text-red-700 transition hover:bg-red-100">Obrisi</button>
                                </form>
                            </td>
                        @endif
                    </tr>
                @empty
                    <tr>
                        <td class="px-4 py-10 text-center text-slate-500" colspan="{{ count($columns) + (isset($deleteRoute) ? 1 : 0) }}">
                            Nema zapisa. Dodaj prvi zapis kroz formu lijevo.
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
    <div class="border-t border-slate-100 bg-slate-50/60 px-4 py-3">{{ $rows->links() }}</div>
</div>
