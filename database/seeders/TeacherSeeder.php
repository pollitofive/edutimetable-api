<?php

namespace Database\Seeders;

use App\Models\Teacher;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class TeacherSeeder extends Seeder
{
    public const NAMES = [
        'Valeria Romero', 'Marcelo Gutiérrez', 'Sofía Pereyra', 'Diego Navarro',
        'Luciana Torres', 'Andrés Cabrera', 'Florencia Medina', 'Pablo Herrera',
        'Natalia Álvarez', 'Sebastián Ruiz',
    ];

    public function run(): void
    {
        foreach (self::NAMES as $name) {
            Teacher::create([
                'name' => $name,
                'email' => Str::slug($name, '.').'@instituto.edu.ar',
                'phone' => null,
            ]);
        }

        $this->command->info('Created '.count(self::NAMES).' teachers.');
    }
}