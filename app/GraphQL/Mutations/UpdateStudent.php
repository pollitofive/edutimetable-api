<?php

namespace App\GraphQL\Mutations;

use App\Models\Student;
use Illuminate\Support\Facades\DB;

class UpdateStudent
{
    /**
     * @param  array<string, mixed>  $args  id + UpdateStudentInput, already validated (UpdateStudentValidator)
     */
    public function __invoke($_, array $args): Student
    {
        $student = Student::findOrFail($args['id']);

        return DB::transaction(function () use ($student, $args) {
            $student->update(array_intersect_key($args, array_flip($student->getFillable())));

            if (array_key_exists('course_level_ids', $args)) {
                $student->syncCourseLevels($args['course_level_ids']);
            }

            return $student;
        });
    }
}
