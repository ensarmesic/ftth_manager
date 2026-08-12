<?php

use App\Models\Project;
use App\Services\SurveyPointImportService;
use Illuminate\Contracts\Console\Kernel;

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$service = app(SurveyPointImportService::class);
foreach (array_slice($argv, 1) as $file) {
    $contents = file_get_contents($file);
    $points = $service->parse($contents);
    $network = $service->buildNetwork($points);
    $project = new Project([
        'name' => 'Analiza', 'code' => 'ANALIZA', 'location' => 'Analiza', 'status' => 'planning',
    ]);
    $project->id = 0;
    $preview = $service->preview($project, $contents, basename($file));
    echo json_encode([
        'file' => basename($file),
        'points' => count($points),
        'kinds' => collect($points)->countBy('kind')->all(),
        'zo_tags' => collect($points)->pluck('zo_tag')->filter()->countBy()->all(),
        'point_range' => [collect($points)->min('point_no'), collect($points)->max('point_no')],
        'duplicates' => collect($points)->countBy('point_no')->filter(fn ($count) => $count > 1)->all(),
        'trenches' => count($network['trenches']),
        'ducts' => count($network['ducts']),
        'quality' => $preview['quality'],
        'drop_routes' => collect($preview['ducts'])->where('route_type', 'drop')->map(fn ($duct) => [
            'label' => $duct['label'], 'zo' => $duct['zo_tag'], 'length_m' => $duct['length_m'],
            'routing_status' => $duct['routing_status'], 'match' => $duct['matched_cabinet_name'],
        ])->values()->all(),
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE).PHP_EOL;
}

if (count(array_slice($argv, 1)) === 2) {
    [$mainFile, $dropFile] = array_slice($argv, 1);
    $mainPoints = $service->parse(file_get_contents($mainFile));
    $dropPoints = $service->parse(file_get_contents($dropFile));
    $mainNetwork = $service->buildNetwork($mainPoints);
    $cabinetPoints = collect($mainPoints)->where('kind', 'cabinet')->map(fn ($point) => [
        'kind' => 'cabinet', 'code' => $point['code'], 'lat' => $point['lat'], 'lng' => $point['lng'],
    ])->values()->all();
    $network = $service->buildNetwork($dropPoints, $cabinetPoints, array_column($mainNetwork['trenches'], 'path'));
    echo json_encode([
        'combined' => true,
        'main' => basename($mainFile),
        'drops' => basename($dropFile),
        'terminal_routes' => collect($network['ducts'])->filter(fn ($duct) => isset($duct['_terminal_point']) || ($duct['prepared_sling'] ?? false))->map(fn ($duct) => [
            'zo' => $duct['zo_tag'],
            'terminal_point' => $duct['_terminal_point'] ?? null,
            'reached' => (bool) ($duct['cabinet_reached'] ?? false),
            'routed_via_trench' => (bool) ($duct['routed_via_trench'] ?? false),
            'points' => count($duct['path'] ?? []),
            'start' => $duct['path'][0] ?? null,
            'end' => end($duct['path']),
            'tail' => array_slice($duct['path'] ?? [], -6),
        ])->values()->all(),
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE).PHP_EOL;
}
