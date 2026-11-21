<?php

return [
    'errors' => [
        'level_unmapped' => 'The level ":value" has no level assigned.',
        'duplicate_in_file' => 'The email is repeated in the file (row :row).',
    ],
    'file' => [
        'unreadable' => 'The file could not be read. Check that it is a valid xlsx or csv.',
        'empty' => 'The file has no rows to import.',
        'too_many_rows' => 'The file has more than :max rows. Split it into several files.',
    ],
    'template' => [
        'filename_students' => 'students-template.xlsx',
        'filename_teachers' => 'teachers-template.xlsx',
        'headers' => [
            'name' => 'Name',
            'email' => 'Email',
            'phone' => 'Phone',
            'level' => 'Level',
        ],
        'example' => [
            'name' => 'Ana García',
            'email' => 'ana.garcia@example.com',
            'phone' => '11 5555-5555',
            'level' => 'English - Beginner',
        ],
    ],
];
