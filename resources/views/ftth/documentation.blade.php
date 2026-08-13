@extends('ftth.layout')
@section('title', $documentTitle)
@section('subtitle', $documentSubtitle)

@section('content')
<div class="docs-workspace">
    <aside class="docs-index" aria-label="Dokumentacija">
        <div class="docs-index-kicker">CENTAR ZA POMOĆ</div>
        <h2>Dokumentacija</h2>
        <p>Odaberi uputstvo i koristi sadržaj dokumenta za brzi skok.</p>
        <nav>
            @foreach($documents as $key => $document)
                <a href="{{ route('documentation', ['document' => $key]) }}" @class(['is-active' => $activeDocument === $key])>
                    <b>{{ $document['title'] }}</b><span>{{ $document['subtitle'] }}</span>
                </a>
            @endforeach
        </nav>
        <div class="docs-index-note"><b>Prije trajne izmjene</b><span>Napravi snapshot i pregledaj relevantno poglavlje.</span></div>
    </aside>
    <article class="docs-article" id="docs-article">{!! $documentHtml !!}</article>
</div>
@endsection
