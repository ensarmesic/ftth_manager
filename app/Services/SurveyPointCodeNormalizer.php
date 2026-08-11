<?php

namespace App\Services;

class SurveyPointCodeNormalizer
{
    public function isHousePoint(string $normalizedDescription): bool
    {
        return (bool) preg_match('/\b(?:kuc[aeiou]*|kuci|kucu|kuce|za kuc[aeiou]*|do kuc[aeiou]*|na kuci|kuci)\b/u', $normalizedDescription);
    }

    public function cabinetTag(string $raw): string
    {
        $tag = str_replace('-', '.', trim($raw, '.-_ '));
        $parts = explode('.', $tag);
        while (count($parts) > 1 && (int) end($parts) === 0) {
            array_pop($parts);
        }

        return ltrim(implode('.', $parts), '0') ?: '0';
    }
}
