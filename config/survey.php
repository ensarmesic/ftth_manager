<?php

// Survey object catalogue. "cad_prefix" is the pole label prefix used on the
// map and in DXF (e.g. SR12); "size" is the map marker size in pixels.
return [
    'duplicate_distance_m' => (float) env('SURVEY_DUPLICATE_DISTANCE_M', 0.50),
    'categories' => [
        'electric_pole' => ['label' => 'Elektro stub', 'color' => '#2563eb', 'symbol' => 'E', 'is_pole' => true, 'cad_prefix' => 'SE', 'size' => 12],
        'lighting_pole' => ['label' => 'Stub javne rasvjete', 'color' => '#eab308', 'symbol' => 'R', 'is_pole' => true, 'cad_prefix' => 'SR', 'size' => 12],
        'electric_lighting_pole' => ['label' => 'Elektro stub sa rasvjetom', 'color' => '#f97316', 'symbol' => 'ER', 'is_pole' => true, 'cad_prefix' => 'SER', 'size' => 12],
        'high_voltage_pole' => ['label' => 'Stub visokog napona', 'color' => '#dc2626', 'symbol' => 'VN', 'is_pole' => true, 'cad_prefix' => 'SEVN', 'size' => 12],
        'high_voltage_lighting_pole' => ['label' => 'Stub visokog napona sa rasvjetom', 'color' => '#991b1b', 'symbol' => 'VR', 'is_pole' => true, 'cad_prefix' => 'SEVNR', 'size' => 12],
        'wooden_electric_lighting_pole' => ['label' => 'Drveni elektro stub sa rasvjetom', 'color' => '#92400e', 'symbol' => 'DR', 'is_pole' => true, 'cad_prefix' => 'SERD', 'size' => 12],
        'wooden_lighting_pole' => ['label' => 'Drveni stub javne rasvjete', 'color' => '#a16207', 'symbol' => 'D', 'is_pole' => true, 'cad_prefix' => 'SRD', 'size' => 12],
        'fiber_pole' => ['label' => 'Stub sa optikom', 'color' => '#0d9488', 'symbol' => 'O', 'is_pole' => true, 'cad_prefix' => 'SO', 'size' => 12],
        'telemach_wooden_pole' => ['label' => 'Drveni stub Telemacha', 'color' => '#7c3aed', 'symbol' => 'T', 'is_pole' => true, 'cad_prefix' => 'STD', 'size' => 12],
        'empty_pole' => ['label' => 'Novougrađeni prazan stub', 'color' => '#64748b', 'symbol' => 'P', 'is_pole' => true, 'cad_prefix' => 'SP', 'size' => 12],
        'odf' => ['label' => 'Geodetska ODF tačka', 'color' => '#059669', 'symbol' => 'ODF', 'is_pole' => false, 'size' => 18],
        'green_area_point' => ['label' => 'Zelena površina', 'color' => '#86efac', 'symbol' => 'ZP', 'is_pole' => false, 'size' => 6],
        'unknown_survey_point' => ['label' => 'Nepoznata geodetska tačka', 'color' => '#6b7280', 'symbol' => '?', 'is_pole' => false, 'size' => 8],
    ],
];
