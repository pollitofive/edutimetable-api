<?php

namespace App\Services;

use App\Models\Course;
use App\Models\Student;
use App\Models\Teacher;

/**
 * Quick search of the header: students, teachers and courses of the current
 * business (BelongsToBusiness global scope), a few results per type.
 */
class SearchService
{
    public const MIN_LENGTH = 2;

    public const LIMIT_PER_TYPE = 5;

    public function search(string $term): array
    {
        return [
            'students' => $this->students($term),
            'teachers' => $this->teachers($term),
            'courses' => $this->courses($term),
        ];
    }

    private function students(string $term): array
    {
        return Student::with('courseLevel.track')
            ->search($term)
            ->orderBy('name')
            ->limit(self::LIMIT_PER_TYPE)
            ->get()
            ->map(fn (Student $s) => [
                'id' => $s->id,
                'name' => $s->name,
                'email' => $s->email,
                'level' => $s->courseLevel?->name,
                'track' => $s->courseLevel?->track?->name,
            ])
            ->all();
    }

    private function teachers(string $term): array
    {
        return Teacher::query()
            ->where(fn ($q) => $q->where('name', 'like', "%{$term}%")
                ->orWhere('email', 'like', "%{$term}%"))
            ->orderBy('name')
            ->limit(self::LIMIT_PER_TYPE)
            ->get()
            ->map(fn (Teacher $t) => [
                'id' => $t->id,
                'name' => $t->name,
                'email' => $t->email,
            ])
            ->all();
    }

    private function courses(string $term): array
    {
        return Course::with('courseLevel.track')
            ->where('name', 'like', "%{$term}%")
            ->orderBy('name')
            ->limit(self::LIMIT_PER_TYPE)
            ->get()
            ->map(fn (Course $c) => [
                'id' => $c->id,
                'name' => $c->name,
                'level' => $c->courseLevel?->name,
                'track' => $c->courseLevel?->track?->name,
            ])
            ->all();
    }
}
