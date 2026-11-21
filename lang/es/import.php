<?php

return [
    'errors' => [
        'level_unmapped' => 'El nivel ":value" no tiene un nivel asignado.',
        'duplicate_in_file' => 'El email está repetido en el archivo (fila :row).',
    ],
    'file' => [
        'unreadable' => 'No se pudo leer el archivo. Verificá que sea un xlsx o csv válido.',
        'empty' => 'El archivo no tiene filas para importar.',
        'too_many_rows' => 'El archivo tiene más de :max filas. Dividilo en varios archivos.',
    ],
    'template' => [
        'filename_students' => 'plantilla-alumnos.xlsx',
        'filename_teachers' => 'plantilla-profesores.xlsx',
        'headers' => [
            'name' => 'Nombre',
            'email' => 'Email',
            'phone' => 'Teléfono',
            'level' => 'Nivel',
        ],
        'example' => [
            'name' => 'Ana García',
            'email' => 'ana.garcia@ejemplo.com',
            'phone' => '11 5555-5555',
            'level' => 'Inglés - Inicial',
        ],
    ],
];
