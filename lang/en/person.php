<?php

// Validation messages of students and teachers (App\Validation\PersonRules),
// shared by the GraphQL create/update mutations and the spreadsheet import.
return [
    'fields' => [
        'name' => 'name',
        'email' => 'email',
        'phone' => 'phone',
        'level' => 'level',
    ],
    'errors' => [
        'required' => 'The :field is missing.',
        'email' => 'The email is not valid.',
        'max' => 'The :field is longer than :max characters.',
        'taken' => 'The email is already used by someone else.',
        'invalid' => 'The :field is not valid.',
    ],
];
