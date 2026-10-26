<?php

namespace App\Support;

/** Read helpers for the survey object catalogue in config/survey.php. */
final class SurveyCategories
{
    public static function all(): array
    {
        return config('survey.categories', []);
    }

    public static function get(?string $category): array
    {
        return $category === null ? [] : (self::all()[$category] ?? []);
    }

    /** @return list<string> */
    public static function poleKeys(): array
    {
        return array_keys(array_filter(self::all(), fn (array $category) => $category['is_pole'] ?? false));
    }

    public static function isPole(?string $category): bool
    {
        return (bool) (self::get($category)['is_pole'] ?? false);
    }

    public static function duplicateDistance(): float
    {
        return (float) config('survey.duplicate_distance_m', 0.50);
    }

    /** Compact CAD label used on the map and in DXF, e.g. "SR12". */
    public static function cadLabel(?string $category, int $number): string
    {
        return (self::get($category)['cad_prefix'] ?? 'ST').$number;
    }

    /** Fields shared by the map payload and the import preview. */
    public static function presentation(?string $category): array
    {
        $definition = self::get($category);

        return [
            'category_label' => $definition['label'] ?? $category,
            'color' => $definition['color'] ?? '#6b7280',
            'symbol' => $definition['symbol'] ?? '?',
            'size' => $definition['size'] ?? 12,
            'is_pole' => (bool) ($definition['is_pole'] ?? false),
        ];
    }
}
