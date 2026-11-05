<?php
// config/school.php — centralized school identity for all formal documents.
// Empty region/division are skipped (correct for private schools).
return [
    'country'    => 'Republic of the Philippines',
    'department' => 'Department of Education',
    'region'     => '',
    'division'   => '',
    'school'     => 'USAT College Sagay City, Inc.',
    'address'    => 'Sagay City, Negros Occidental',
    'logo_path'  => null,
    'signatories' => [
        'registrar' => ['name' => '', 'position' => 'University Registrar'],
        'principal' => ['name' => '', 'position' => 'School President'],
    ],
];