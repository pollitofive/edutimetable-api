<?php

namespace Tests\Feature;


use App\Models\Course;
use App\Models\Schedule;
use App\Services\ScheduleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

it('creates a non-overlapping schedule for a course', function () {
    $course = Course::factory()->create();

    $service = app(ScheduleService::class);

    $s1 = $service->createSchedule($course->id, [
        'day_of_week' => 1,           // Monday
        'starts_at'   => '15:00',
        'ends_at'     => '16:30',
    ]);

    $s2 = $service->createSchedule($course->id, [
        'day_of_week' => 1,
        'starts_at'   => '16:30',     // touches end — allowed
        'ends_at'     => '18:00',
    ]);

    expect(Schedule::count())->toBe(2);
    expect($s1->course_id)->toBe($course->id);
    expect($s2->course_id)->toBe($course->id);
});

it('rejects overlapping schedules within the same course and day', function () {
    $course = Course::factory()->create();
    $service = app(ScheduleService::class);

    $service->createSchedule($course->id, [
        'day_of_week' => 3,
        'starts_at'   => '14:00',
        'ends_at'     => '15:00',
    ]);

    // Overlaps 14:30-15:30 => should fail
    expect(fn () =>
    $service->createSchedule($course->id, [
        'day_of_week' => 3,
        'starts_at'   => '14:30',
        'ends_at'     => '15:30',
    ])
    )->toThrow(ValidationException::class);
});

it('allows same time range if it is a different course', function () {
    [$c1, $c2] = Course::factory()->count(2)->create();

    $service = app(ScheduleService::class);

    $service->createSchedule($c1->id, [
        'day_of_week' => 2,
        'starts_at'   => '10:00',
        'ends_at'     => '11:00',
    ]);

    // Same time, different course => allowed
    $service->createSchedule($c2->id, [
        'day_of_week' => 2,
        'starts_at'   => '10:00',
        'ends_at'     => '11:00',
    ]);

    expect(Schedule::count())->toBe(2);
});

it('rejects invalid time ranges (start must be before end)', function () {
    $course = Course::factory()->create();
    $service = app(ScheduleService::class);

    expect(fn () =>
    $service->createSchedule($course->id, [
        'day_of_week' => 4,
        'starts_at'   => '18:00',
        'ends_at'     => '17:00',
    ])
    )->toThrow(ValidationException::class);
});
