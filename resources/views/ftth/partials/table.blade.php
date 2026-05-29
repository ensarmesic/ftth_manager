<div class="overflow-hidden rounded-md border border-zinc-200 bg-white shadow-sm">
    <div class="overflow-x-auto">
        <table class="w-full text-left text-sm">
            <thead class="border-b border-zinc-200 bg-zinc-50 text-xs font-semibold uppercase text-zinc-500">
                <tr>
                    @foreach ($columns as $label)
                        <th class="whitespace-nowrap px-4 py-3">{{ $label }}</th>
                    @endforeach
                </tr>
            </thead>
            <tbody class="divide-y divide-zinc-100">
                @forelse ($rows as $row)
                    <tr class="hover:bg-zinc-50">
                        @foreach ($columns as $key => $label)
                            <td class="whitespace-nowrap px-4 py-3 text-zinc-700">{{ data_get($row, $key) ?? '-' }}</td>
                        @endforeach
                    </tr>
                @empty
                    <tr>
                        <td class="px-4 py-8 text-center text-zinc-500" colspan="{{ count($columns) }}">
                            Nema zapisa. Dodaj prvi zapis kroz formu lijevo.
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
    <div class="border-t border-zinc-100 px-4 py-3">{{ $rows->links() }}</div>
</div>
