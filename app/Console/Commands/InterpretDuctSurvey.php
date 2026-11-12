<?php

namespace App\Console\Commands;

use App\Services\DuctSurveyImportService;
use App\Services\DuctSurveyInterpreter;
use App\Services\GeoTransformService;
use Illuminate\Console\Command;

/**
 * Read-only: shows how the duct-composition survey TXT files are understood
 * (closures per secondary, where every tube colour goes, what is unclear).
 */
class InterpretDuctSurvey extends Command
{
    protected $signature = 'survey:interpret {files* : TXT fajlovi snimanja (redom kojim su snimani)} {--geojson= : upiši rezultat kao GeoJSON}';

    protected $description = 'Prikaži kako program razumije TXT snimak rova (bez upisa u bazu)';

    public function handle(DuctSurveyInterpreter $interpreter, GeoTransformService $geo): int
    {
        $points = [];
        foreach ($this->argument('files') as $file) {
            if (! is_file($file)) {
                $this->error("Nema fajla: {$file}");

                return self::FAILURE;
            }
            $points = [...$points, ...DuctSurveyImportService::readPoints((string) file_get_contents($file), basename($file))];
        }

        $result = $interpreter->interpret($points);
        $nodes = $result['nodes'];
        $name = function (int $id) use ($nodes): string {
            $n = $nodes[$id];

            return match ($n['kind']) {
                'odf' => 'ODF '.$n['odf_no'],
                'zo' => 'ZO '.$n['zo']['label'].($n['zo']['secondary'] ? '-S'.$n['zo']['secondary'] : ''),
                'box' => 'Box #'.$n['points'][0],
                default => $n['kind'].' #'.$n['points'][0],
            };
        };

        $this->info('Tačaka: '.count($points).', čvorova: '.count($nodes).', veza: '.count($result['edges']));

        $this->newLine();
        $this->info('ZO PO SEKUNDARIMA (put od ODF-a kroz cijevi)');
        foreach ($result['secondaries'] as $key => $list) {
            [$odf, $secondary] = explode('|', $key);
            $this->line("  ODF {$odf} / S{$secondary}:");
            foreach ($list as $zo) {
                $this->line(sprintf(
                    '    ZO %-5s #%-5d %s',
                    $zo['label'],
                    $nodes[$zo['node']]['points'][0],
                    $zo['reached']
                        ? 'dostupan, '.$zo['distance_m'].' m od ODF-a'.($zo['via_zo'] ? ' (preko '.implode(', ', array_map($name, $zo['via_zo'])).')' : '')
                        : 'NIJE povezan s ODF-om',
                ));
            }
        }

        $this->newLine();
        $this->info('CIJEVI PO BOJAMA');
        foreach ($result['flows'] as $flow) {
            $this->line(sprintf('  %-8s od %-14s %6.0f m', $flow['color'], $name($flow['root']).' (#'.$nodes[$flow['root']]['points'][0].')', $flow['length_m']));
            if ($flow['objects'] !== []) {
                $this->line('           ide do: '.implode(' → ', array_map(fn ($o) => $name($o['node']).' ('.round($o['distance_m']).' m)', $flow['objects'])));
            }
            if ($flow['ends'] !== []) {
                $this->line('           krajevi: '.implode(', ', array_map(fn ($o) => '#'.$nodes[$o['node']]['points'][0].' '.$nodes[$o['node']]['kind'], $flow['ends'])));
            }
        }

        $this->newLine();
        $this->info('CIJEVI KOJE POČINJU USRED LINIJE (nije nađeno odakle dolaze)');
        foreach ($result['starts'] as $start) {
            $this->line('  '.$start['color'].' od tačke #'.$nodes[$start['node']]['points'][0]);
        }

        $this->newLine();
        $this->info('PROVJERA SNIMKA');
        foreach ($result['checks'] as $check) {
            $line = sprintf('  [%s] %s%s', strtoupper($check['level']), $check['point_no'] !== null ? '#'.$check['point_no'].': ' : '', $check['message']);
            $check['level'] === 'info' ? $this->line($line) : $this->warn($line);
        }
        if ($result['checks'] === []) {
            $this->line('  Sve je u redu.');
        }

        if ($path = $this->option('geojson')) {
            file_put_contents($path, json_encode($this->geojson($result, $geo), JSON_UNESCAPED_UNICODE));
            $this->info("GeoJSON: {$path}");
        }

        return self::SUCCESS;
    }

    private function geojson(array $result, GeoTransformService $geo): array
    {
        $ll = function (array $n) use ($geo): array {
            [$lat, $lng] = $geo->gaussKrugerToWgs84($n['x'], $n['y'], $geo->detectZone($n['x']));

            return [round($lng, 7), round($lat, 7)];
        };
        $features = [];
        foreach ($result['edges'] as $e) {
            $a = $result['nodes'][$e['a']];
            $b = $result['nodes'][$e['b']];
            $features[] = ['type' => 'Feature', 'geometry' => ['type' => 'LineString', 'coordinates' => [$ll($a), $ll($b)]], 'properties' => [
                'kind' => $e['kind'],
                'ducts' => is_array($e['ducts']) ? $e['ducts'] : 'sve',
                'alkaten' => $e['alkaten'],
                'from' => $a['points'][0],
                'to' => $b['points'][0],
            ]];
        }
        foreach ($result['nodes'] as $n) {
            if ($n['kind'] === 'line') {
                continue;
            }
            $features[] = ['type' => 'Feature', 'geometry' => ['type' => 'Point', 'coordinates' => $ll($n)], 'properties' => [
                'kind' => $n['kind'], 'points' => $n['points'], 'code' => $n['code'],
            ]];
        }

        return ['type' => 'FeatureCollection', 'features' => $features];
    }
}
