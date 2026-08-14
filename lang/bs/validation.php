<?php

return [
    'uploaded' => 'Datoteku nije moguće učitati. Provjeri PHP post_max_size i upload_max_filesize limite servera.',
    'file' => 'Polje :attribute mora sadržavati datoteku.',
    'mimes' => 'Datoteka :attribute mora biti jednog od tipova: :values.',
    'extensions' => 'Datoteka :attribute mora imati jednu od ekstenzija: :values.',
    'max' => [
        'file' => 'Datoteka :attribute ne smije biti veća od :max KiB.',
    ],
    'required' => 'Polje :attribute je obavezno.',
    'attributes' => [
        'backup' => 'backup',
        'points_file' => 'geodetski TXT',
        'photo' => 'fotografija',
        'geojson' => 'GeoJSON',
        'dxf' => 'DXF',
        'file' => 'CAD datoteka',
    ],
];
