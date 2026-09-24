<?php

namespace App\Services;

use App\Models\House;
use App\Models\Project;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class LargePlannerHouseImportService
{
    public function import(Project $project, UploadedFile $file): int
    {
        $handle = fopen($file->getRealPath(), 'rb');
        abort_if($handle === false, 422, 'CSV fajl nije moguće otvoriti.');

        try {
            $firstLine = fgets($handle);
            if ($firstLine === false) {
                throw ValidationException::withMessages(['houses_file' => 'CSV fajl je prazan.']);
            }
            $delimiter = $this->delimiter($firstLine);
            rewind($handle);
            $header = array_map($this->normalizeHeader(...), fgetcsv($handle, 0, $delimiter, '"', '\\') ?: []);
            $indexes = $this->columnIndexes($header);
            $rows = [];
            $labels = [];
            $line = 1;

            while (($values = fgetcsv($handle, 0, $delimiter, '"', '\\')) !== false) {
                $line++;
                if ($values === [] || collect($values)->every(fn ($value) => trim((string) $value) === '')) {
                    continue;
                }
                if (count($rows) >= 20000) {
                    throw ValidationException::withMessages(['houses_file' => 'CSV može sadržavati najviše 20.000 kuća.']);
                }

                $row = [
                    'label' => trim((string) ($values[$indexes['label']] ?? '')),
                    'address' => $indexes['address'] === null ? null : (trim((string) ($values[$indexes['address']] ?? '')) ?: null),
                    'latitude' => $values[$indexes['latitude']] ?? null,
                    'longitude' => $values[$indexes['longitude']] ?? null,
                ];
                $validator = Validator::make($row, [
                    'label' => ['required', 'string', 'max:255'],
                    'address' => ['nullable', 'string', 'max:255'],
                    'latitude' => ['required', 'numeric', 'between:-90,90'],
                    'longitude' => ['required', 'numeric', 'between:-180,180'],
                ]);
                if ($validator->fails()) {
                    throw ValidationException::withMessages(['houses_file' => "Red {$line}: ".$validator->errors()->first()]);
                }

                $labelKey = Str::lower($row['label']);
                if (isset($labels[$labelKey])) {
                    throw ValidationException::withMessages(['houses_file' => "Red {$line}: oznaka kuće se ponavlja u fajlu."]);
                }
                $labels[$labelKey] = true;
                $rows[] = $row;
            }
        } finally {
            fclose($handle);
        }

        if ($rows === []) {
            throw ValidationException::withMessages(['houses_file' => 'CSV ne sadrži nijednu kuću.']);
        }
        if (House::where('project_id', $project->id)->whereIn('label', array_column($rows, 'label'))->exists()) {
            throw ValidationException::withMessages(['houses_file' => 'Jedna ili više oznaka kuća već postoji u projektu.']);
        }

        $now = now();
        $batch = (string) Str::uuid();
        DB::transaction(function () use ($project, $rows, $now, $batch): void {
            foreach (array_chunk($rows, 500) as $chunk) {
                House::insert(array_map(fn (array $row) => $row + [
                    'project_id' => $project->id,
                    'status' => 'planned',
                    'import_batch' => $batch,
                    'created_at' => $now,
                    'updated_at' => $now,
                ], $chunk));
            }
        });

        return count($rows);
    }

    private function delimiter(string $line): string
    {
        $counts = [',' => substr_count($line, ','), ';' => substr_count($line, ';'), "\t" => substr_count($line, "\t")];
        arsort($counts);

        return (string) array_key_first($counts);
    }

    private function normalizeHeader(string $header): string
    {
        return Str::lower(trim($header, "\xEF\xBB\xBF \t\n\r\0\x0B"));
    }

    private function columnIndexes(array $header): array
    {
        $aliases = [
            'label' => ['label', 'oznaka'],
            'address' => ['address', 'adresa'],
            'latitude' => ['latitude', 'lat', 'sirina'],
            'longitude' => ['longitude', 'lng', 'lon', 'duzina'],
        ];
        $indexes = [];
        foreach ($aliases as $column => $names) {
            $indexes[$column] = collect($names)->map(fn ($name) => array_search($name, $header, true))->first(fn ($index) => $index !== false);
            if ($column !== 'address' && $indexes[$column] === null) {
                throw ValidationException::withMessages(['houses_file' => "CSV nema obaveznu kolonu {$column}."]);
            }
        }

        return $indexes;
    }
}
