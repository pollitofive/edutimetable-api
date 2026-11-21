<?php

// Validation messages of students and teachers (App\Validation\PersonRules),
// shared by the GraphQL create/update mutations and the spreadsheet import.
return [
    'fields' => [
        'name' => 'nombre',
        'email' => 'email',
        'phone' => 'teléfono',
        'level' => 'nivel',
    ],
    'errors' => [
        'required' => 'Falta el :field.',
        'email' => 'El email no es válido.',
        'max' => 'El :field supera los :max caracteres.',
        'taken' => 'El email ya lo usa otra persona.',
        'invalid' => 'El :field no es válido.',
    ],
];
