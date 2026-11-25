<?php

namespace App\GraphQL\Mutations;

use App\Models\Student;
use Illuminate\Support\Facades\DB;

class CreateStudent
{
    /**
     * @param  array<string, mixed>  $args  CreateStudentInput, already validated (CreateStudentInputValidator)
     */
    public function __invoke($_, array $args): Student
    {
        return DB::transaction(function () use ($args) {
            $student = Student::create($args);
            $student->syncCourseLevels($args['course_level_ids']);

            return $student;
        });
    }
}
