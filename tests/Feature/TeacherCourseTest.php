<?php

namespace Tests\Feature;


use App\Models\Course;
use App\Models\Teacher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Database\QueryException;

uses(RefreshDatabase::class);

it('a teacher can have many courses', function () {
    $teacher = Teacher::factory()->create();
    $courses = Course::factory()->count(3)->create(['teacher_id' => $teacher->id]);

    expect($teacher->courses)->toHaveCount(3);
    expect($courses->first()->teacher->id)->toBe($teacher->id);
});

it('fails to create a course without a teacher (FK required)', function () {
    $this->expectException(QueryException::class);

    Course::factory()->create(['teacher_id' => null]); // violates FK
});
