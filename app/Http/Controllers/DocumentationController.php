<?php

namespace App\Http\Controllers;

use Illuminate\Contracts\View\View;
use Illuminate\Support\Str;

class DocumentationController extends Controller
{
    public function __invoke(?string $document = null): View
    {
        $documents = [
            'aplikacija' => ['title' => 'Korisničko uputstvo', 'subtitle' => 'Kompletna operativna dokumentacija FTTH Manager aplikacije.', 'file' => 'KORISNICKO-UPUTSTVO-FTTH-MANAGER.md'],
            'geodetski-txt' => ['title' => 'Geodetski TXT standard', 'subtitle' => 'Terensko snimanje, oznake, format i kontrola TXT uvoza.', 'file' => 'UPUTSTVO-ZA-GEODETSKI-TXT-FTTH.txt'],
        ];
        $key = array_key_exists((string) $document, $documents) ? (string) $document : 'aplikacija';
        $selected = $documents[$key];
        $contents = file_get_contents(base_path('docs/'.$selected['file'])) ?: '';

        return view('ftth.documentation', [
            'documents' => $documents,
            'activeDocument' => $key,
            'documentTitle' => $selected['title'],
            'documentSubtitle' => $selected['subtitle'],
            'documentHtml' => Str::markdown($contents, ['html_input' => 'strip', 'allow_unsafe_links' => false]),
        ]);
    }
}
