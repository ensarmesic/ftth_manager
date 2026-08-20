<?php

namespace App\Services;

class SurveyTargetZoParser
{
    public function __construct(private readonly SurveyPointCodeNormalizer $normalizer) {}

    /**
     * Read an explicitly written ZO destination without using geographic context.
     *
     * @return array{raw_description:string,target_zo:?string,matched_text:?string,explicit:bool}
     */
    public function parse(string $description): array
    {
        $result = [
            'raw_description' => $description,
            'target_zo' => null,
            'matched_text' => null,
            'explicit' => false,
        ];

        if (! preg_match_all('/(?<![\pL\pN])ZO\s*-?\s*(\d+(?:[.-]\d+)*)(?![\pL\pN])/iu', $description, $matches, PREG_SET_ORDER)) {
            return $result;
        }

        $match = $matches[array_key_last($matches)];

        return [
            'raw_description' => $description,
            'target_zo' => 'ZO-'.$this->normalizer->cabinetTag($match[1]),
            'matched_text' => $match[0],
            'explicit' => true,
        ];
    }
}
