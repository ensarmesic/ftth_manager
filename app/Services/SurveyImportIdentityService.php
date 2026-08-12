<?php

namespace App\Services;

use App\Models\House;
use InvalidArgumentException;

class SurveyImportIdentityService
{
    public function __construct(
        private readonly GeometryService $geometry,
        private readonly SurveyPointCodeNormalizer $codeNormalizer,
    ) {}

    public function cabinetTag(string $name): ?string
    {
        $normalized = strtr(mb_strtolower($name), ['š' => 's']);
        if (preg_match('/z(?:\s*[o0](?:rmar)?)?[\s\-_.]*([0-9]+(?:[.\-][0-9]+)*)/', $normalized, $match)) {
            return $this->codeNormalizer->cabinetTag($match[1]);
        }

        return null;
    }

    public function cabinetLabel(string $code): string
    {
        $normalized = trim(preg_replace('/\s+/', ' ', $code) ?? '');
        $searchable = strtr(mb_strtolower($normalized), ['š' => 's']);
        if (preg_match('/(?:zeleni\s*ormar|z\s*(?:[o0]\s*)?ormar|z\s*[o0])[\s\-_.]*([\d.]+)?/iu', $searchable, $match)) {
            return 'ZO'.(isset($match[1]) && $match[1] !== '' ? '-'.rtrim($match[1], '.') : '');
        }

        return $normalized !== '' ? $normalized : 'ZO';
    }

    public function mergeOdfPoints(array $points, float $toleranceMeters): array
    {
        $merged = [];
        foreach (collect($points)->where('kind', 'odf') as $point) {
            foreach ($merged as $existing) {
                if ($this->geometry->distanceMeters($existing['lat'], $existing['lng'], $point['lat'], $point['lng']) <= $toleranceMeters) {
                    continue 2;
                }
            }
            $merged[] = ['code' => $point['code'], 'lat' => $point['lat'], 'lng' => $point['lng']];
        }

        return $merged;
    }

    public function odfLabel(?string $code): string
    {
        $normalized = mb_strtoupper(trim((string) $code));
        if (! preg_match('/ODF[\s\-_.]*(.*)$/u', $normalized, $match)) {
            return 'ODF';
        }

        $suffix = trim((string) preg_replace('/[_\s]+/u', ' ', $match[1]));
        if ($suffix === '') {
            return 'ODF';
        }

        if (preg_match('/^[0-9]+(?:[.\-][0-9]+)*$/', $suffix)) {
            return 'ODF-'.str_replace('.', '-', $suffix);
        }

        return 'ODF '.$suffix;
    }

    public function odfIdentity(?string $name): string
    {
        return mb_strtolower((string) preg_replace('/[^\pL\pN]+/u', '', $this->odfLabel($name)));
    }

    public function existsNearby(
        string $model,
        int $projectId,
        float $lat,
        float $lng,
        float $toleranceMeters,
    ): bool {
        return $model::query()
            ->where('project_id', $projectId)
            ->whereNotNull('latitude')
            ->whereNotNull('longitude')
            ->get(['latitude', 'longitude'])
            ->contains(fn ($row) => $this->geometry->distanceMeters(
                (float) $row->latitude,
                (float) $row->longitude,
                $lat,
                $lng,
            ) <= $toleranceMeters);
    }

    public function uniqueName(string $model, int $projectId, string $base): string
    {
        $base = trim($base) !== '' ? trim($base) : 'Element';
        if (! $model::query()->where('project_id', $projectId)->where('name', $base)->exists()) {
            return $base;
        }

        for ($suffix = 2; $suffix < 1000; $suffix++) {
            $candidate = "{$base}-{$suffix}";
            if (! $model::query()->where('project_id', $projectId)->where('name', $candidate)->exists()) {
                return $candidate;
            }
        }

        throw new InvalidArgumentException('Nije moguce generisati jedinstven naziv.');
    }

    public function uniqueHouseLabel(int $projectId, string $base): string
    {
        if (! House::where('project_id', $projectId)->where('label', $base)->exists()) {
            return $base;
        }

        for ($suffix = 2; $suffix < 1000; $suffix++) {
            $candidate = "{$base}-{$suffix}";
            if (! House::where('project_id', $projectId)->where('label', $candidate)->exists()) {
                return $candidate;
            }
        }

        throw new InvalidArgumentException('Nije moguce generisati jedinstvenu oznaku kuce.');
    }
}
