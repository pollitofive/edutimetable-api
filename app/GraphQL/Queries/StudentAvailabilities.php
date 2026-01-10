<?php

namespace App\GraphQL\Queries;

use App\Models\StudentAvailability;
use Illuminate\Database\Eloquent\Builder;

class StudentAvailabilities
{
    /**
     * Order student availabilities by student name
     */
    public function __invoke($_, array $args): Builder
    {
        return StudentAvailability::query()
            ->join('students', 'student_availabilities.student_id', '=', 'students.id')
            ->when(isset($args['student_id']), function ($query) use ($args) {
                return $query->where('student_availabilities.student_id', $args['student_id']);
            })
            ->when(isset($args['day_of_week']), function ($query) use ($args) {
                return $query->where('student_availabilities.day_of_week', $args['day_of_week']);
            })
            ->orderBy('students.name', 'asc')
            ->orderBy('student_availabilities.day_of_week', 'asc')
            ->orderBy('student_availabilities.start_time', 'asc')
            ->select('student_availabilities.*');
    }
}
