<div class="overflow-hidden rounded-md border border-zinc-200 bg-white shadow-sm">
    <div class="overflow-x-auto">
        <table class="w-full text-left text-sm">
            <thead class="border-b border-zinc-200 bg-zinc-50 text-xs font-semibold uppercase text-zinc-500">
                <tr>
                    @foreach ($columns as $label)
                        <th class="whitespace-nowrap px-4 py-3">{{ $label }}</th>
                    @endforeach
                    @if (isset($deleteRoute))
                        <th class="whitespace-nowrap px-4 py-3">Akcije</th>
                    @endif
                </tr>
            </thead>
            <tbody class="divide-y divide-zinc-100">
                @forelse ($rows as $row)
                    <tr class="hover:bg-zinc-50">
                        @foreach ($columns as $key => $label)
                            <td class="whitespace-nowrap px-4 py-3 text-zinc-700">{{ data_get($row, $key) ?? '-' }}</td>
                        @endforeach
                        @if (isset($deleteRoute))
                            <td class="whitespace-nowrap px-4 py-3">
                                <form method="POST" action="{{ $deleteRoute($row->id) }}" style="display:inline;" onsubmit="return confirm('Sigurno obrisati?');">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" class="text-xs font-semibold text-red-600 hover:text-red-800 hover:underline">Obriši</button>
                                </form>
                            </td>
                        @endif
                    </tr>
                @empty
                    <tr>
                        <td class="px-4 py-8 text-center text-zinc-500" colspan="{{ count($columns) + (isset($deleteRoute) ? 1 : 0) }}">
                            Nema zapisa. Dodaj prvi zapis kroz formu lijevo.
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
    <div class="border-t border-zinc-100 px-4 py-3">{{ $rows->links() }}</div>
</div>
