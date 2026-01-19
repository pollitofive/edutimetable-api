<?php

namespace Tests\Feature;

use App\Models\Course;
use App\Models\Teacher;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('a teacher can have many schedules through courses', function () {
    $teacher = Teacher::factory()->create();
    $course = Course::factory()->create();

    // Teacher is associated through schedules, not directly with courses
    \App\Models\Schedule::factory()->count(3)->create([
        'teacher_id' => $teacher->id,
        'course_id' => $course->id,
    ]);

    expect($teacher->schedules)->toHaveCount(3);
    expect($teacher->schedules->first()->course->id)->toBe($course->id);
});

it('can create a course without a teacher (teacher assigned via schedules)', function () {
    // Courses no longer require teacher_id - teachers are assigned via schedules
    $course = Course::factory()->create();

    expect($course)->not->toBeNull();
    expect($course->id)->toBeGreaterThan(0);
});
