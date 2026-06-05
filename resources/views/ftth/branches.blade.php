@extends('ftth.layout')
@section('title', 'Krakovi mreže')
@section('subtitle', 'Hijerarhija primarnih, sekundarnih i izvedenih krakova.')
@section('content')
<div class="grid gap-4 lg:grid-cols-[340px_1fr]">
<form method="POST" action="{{ route('branches.store') }}" class="grid content-start gap-3 rounded-lg border bg-white p-4 shadow-sm">@csrf
<h2 class="font-bold">Novi krak</h2>
<select name="project_id" class="rounded border px-3 py-2" required><option value="">Projekat</option>@foreach($projects as $project)<option value="{{ $project->id }}">{{ $project->name }}</option>@endforeach</select>
<select name="odf_id" class="rounded border px-3 py-2"><option value="">ODF nije odabran</option>@foreach($odfs as $odf)<option value="{{ $odf->id }}">{{ $odf->project->name }} / {{ $odf->name }}</option>@endforeach</select>
<select name="parent_branch_id" class="rounded border px-3 py-2"><option value="">Glavni krak</option>@foreach($parentBranches as $branch)<option value="{{ $branch->id }}">{{ $branch->project->name }} / {{ $branch->name }}</option>@endforeach</select>
<select name="route_id" class="rounded border px-3 py-2"><option value="">Bez vezane trase</option>@foreach($routes as $route)<option value="{{ $route->id }}">{{ $route->project->name }} / {{ $route->name }}</option>@endforeach</select>
<input name="name" class="rounded border px-3 py-2" placeholder="Sekundarni krak I" required><input name="code" class="rounded border px-3 py-2" placeholder="I.2">
<div class="grid grid-cols-2 gap-2"><select name="type" class="rounded border px-3 py-2"><option value="secondary">Sekundarni</option><option value="primary">Primarni</option></select><input name="sort_order" type="number" value="0" min="0" class="rounded border px-3 py-2"></div>
<button class="rounded bg-blue-600 px-4 py-2 font-bold text-white">Sačuvaj krak</button></form>
<div class="overflow-x-auto rounded-lg border bg-white shadow-sm"><table class="w-full text-sm"><thead class="bg-slate-50"><tr><th class="p-3 text-left">Krak</th><th class="p-3 text-left">ODF</th><th class="p-3 text-left">Roditelj</th><th class="p-3 text-left">Trasa</th><th class="p-3 text-left">ODO</th><th></th></tr></thead><tbody>
@foreach($branches as $branch)<tr class="border-t"><td class="p-3"><b>{{ $branch->name }}</b><br><small>{{ $branch->code }} · {{ $branch->type }} · #{{ $branch->sort_order }}</small></td><td class="p-3">{{ $branch->odf?->name ?? '-' }}</td><td class="p-3">{{ $branch->parentBranch?->name ?? '-' }}</td><td class="p-3">{{ $branch->route?->name ?? '-' }}</td><td class="p-3">{{ $branch->cabinets_count }}</td><td class="p-3"><form method="POST" action="{{ route('branches.delete', $branch) }}" data-confirm-delete="Obrisati krak?">@csrf @method('DELETE')<button class="text-red-600">Obriši</button></form></td></tr>@endforeach
</tbody></table><div class="p-3">{{ $branches->links() }}</div></div></div>
@endsection
