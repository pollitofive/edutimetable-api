<?php

use App\Models\Business;
use App\Models\Course;
use App\Models\Schedule;
use App\Models\Teacher;
use App\Models\User;
use App\Services\CurrentBusiness;
use App\Services\ScheduleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

beforeEach(function () {
    // Create two separate businesses
    $this->businessA = Business::factory()->create(['name' => 'Business A']);
    $this->businessB = Business::factory()->create(['name' => 'Business B']);

    // Create users for each business
    $this->userA = User::factory()->create([
        'email' => 'user-a@test.com',
        'default_business_id' => $this->businessA->id,
    ]);

    $this->userB = User::factory()->create([
        'email' => 'user-b@test.com',
        'default_business_id' => $this->businessB->id,
    ]);

    // Attach users to their respective businesses with owner role
    $this->businessA->users()->attach($this->userA->id, ['role' => 'owner']);
    $this->businessB->users()->attach($this->userB->id, ['role' => 'owner']);
});

it('isolates schedules list by business scope', function () {
    // Set context to business A
    app(CurrentBusiness::class)->setId($this->businessA->id);

    // Create course and teacher in business A
    $courseA = Course::factory()->create(['name' => 'Course A']);
    $teacherA = Teacher::factory()->create(['name' => 'Teacher A']);

    // Create schedule in business A
    $scheduleA = Schedule::factory()->create([
        'course_id' => $courseA->id,
        'teacher_id' => $teacherA->id,
        'day_of_week' => 1,
        'starts_at' => '08:00:00',
        'ends_at' => '10:00:00',
    ]);

    // Set context to business B
    app(CurrentBusiness::class)->setId($this->businessB->id);

    // Create course and teacher in business B
    $courseB = Course::factory()->create(['name' => 'Course B']);
    $teacherB = Teacher::factory()->create(['name' => 'Teacher B']);

    // Create schedule in business B
    $scheduleB = Schedule::factory()->create([
        'course_id' => $courseB->id,
        'teacher_id' => $teacherB->id,
        'day_of_week' => 2,
        'starts_at' => '10:00:00',
        'ends_at' => '12:00:00',
    ]);

    // Query as business A - should only see schedule A
    app(CurrentBusiness::class)->setId($this->businessA->id);
    $schedulesInA = Schedule::all();

    expect($schedulesInA)->toHaveCount(1);
    expect($schedulesInA->first()->id)->toBe($scheduleA->id);
    expect($schedulesInA->first()->course->name)->toBe('Course A');

    // Query as business B - should only see schedule B
    app(CurrentBusiness::class)->setId($this->businessB->id);
    $schedulesInB = Schedule::all();

    expect($schedulesInB)->toHaveCount(1);
    expect($schedulesInB->first()->id)->toBe($scheduleB->id);
    expect($schedulesInB->first()->course->name)->toBe('Course B');
});

it('automatically sets business_id when creating schedule', function () {
    // Set context to business A
    app(CurrentBusiness::class)->setId($this->businessA->id);

    // Create course and teacher
    $course = Course::factory()->create(['name' => 'Course A']);
    $teacher = Teacher::factory()->create(['name' => 'Teacher A']);

    // Create schedule without specifying business_id
    $schedule = Schedule::factory()->create([
        'course_id' => $course->id,
        'teacher_id' => $teacher->id,
        'day_of_week' => 1,
        'starts_at' => '08:00:00',
        'ends_at' => '10:00:00',
    ]);

    // Refresh to get actual DB values
    $schedule->refresh();

    // Assert business_id was set automatically
    expect($schedule->business_id)->toBe($this->businessA->id);

    // Verify in database
    $this->assertDatabaseHas('schedules', [
        'id' => $schedule->id,
        'business_id' => $this->businessA->id,
        'course_id' => $course->id,
        'teacher_id' => $teacher->id,
    ]);
});

it('prevents updating schedule from different business (cross-tenant protection)', function () {
    // Create schedule in business A
    app(CurrentBusiness::class)->setId($this->businessA->id);
    $course = Course::factory()->create(['name' => 'Course A']);
    $teacher = Teacher::factory()->create(['name' => 'Teacher A']);
    $scheduleA = Schedule::factory()->create([
        'course_id' => $course->id,
        'teacher_id' => $teacher->id,
        'day_of_week' => 1,
        'starts_at' => '08:00:00',
        'ends_at' => '10:00:00',
    ]);

    // Try to update from business B context
    app(CurrentBusiness::class)->setId($this->businessB->id);

    // Should not find the schedule due to global scope
    $foundSchedule = Schedule::find($scheduleA->id);
    expect($foundSchedule)->toBeNull();

    // Attempting to update via query builder should affect 0 rows
    $affectedRows = Schedule::where('id', $scheduleA->id)
        ->update(['day_of_week' => 5]);

    expect($affectedRows)->toBe(0);

    // Verify original data is unchanged
    app(CurrentBusiness::class)->setId($this->businessA->id);
    $scheduleA->refresh();
    expect($scheduleA->day_of_week)->toBe(1);
});

it('prevents deleting schedule from different business (cross-tenant protection)', function () {
    // Create schedule in business A
    app(CurrentBusiness::class)->setId($this->businessA->id);
    $course = Course::factory()->create(['name' => 'Course A']);
    $teacher = Teacher::factory()->create(['name' => 'Teacher A']);
    $scheduleA = Schedule::factory()->create([
        'course_id' => $course->id,
        'teacher_id' => $teacher->id,
        'day_of_week' => 1,
        'starts_at' => '08:00:00',
        'ends_at' => '10:00:00',
    ]);

    $scheduleId = $scheduleA->id;

    // Try to delete from business B context
    app(CurrentBusiness::class)->setId($this->businessB->id);

    // Should not find the schedule due to global scope
    $foundSchedule = Schedule::find($scheduleId);
    expect($foundSchedule)->toBeNull();

    // Attempting to delete via query builder should affect 0 rows
    $deletedRows = Schedule::where('id', $scheduleId)->delete();
    expect($deletedRows)->toBe(0);

    // Verify schedule still exists in business A
    app(CurrentBusiness::class)->setId($this->businessA->id);
    $this->assertDatabaseHas('schedules', [
        'id' => $scheduleId,
    ]);
});

it('prevents creating schedule with course from different business', function () {
    // Set context to business A
    app(CurrentBusiness::class)->setId($this->businessA->id);
    $teacherA = Teacher::factory()->create(['name' => 'Teacher A']);

    // Set context to business B and create course
    app(CurrentBusiness::class)->setId($this->businessB->id);
    $courseB = Course::factory()->create(['name' => 'Course B']);

    // Try to create schedule in business A with course from business B
    app(CurrentBusiness::class)->setId($this->businessA->id);

    $service = app(ScheduleService::class);

    expect(fn () => $service->createSchedule(
        $courseB->id,
        $teacherA->id,
        [
            'day_of_week' => 1,
            'starts_at' => '08:00',
            'ends_at' => '10:00',
            'description' => 'Test',
        ]
    ))->toThrow(ValidationException::class);
});

it('prevents creating schedule with teacher from different business', function () {
    // Set context to business A
    app(CurrentBusiness::class)->setId($this->businessA->id);
    $courseA = Course::factory()->create(['name' => 'Course A']);

    // Set context to business B and create teacher
    app(CurrentBusiness::class)->setId($this->businessB->id);
    $teacherB = Teacher::factory()->create(['name' => 'Teacher B']);

    // Try to create schedule in business A with teacher from business B
    app(CurrentBusiness::class)->setId($this->businessA->id);

    $service = app(ScheduleService::class);

    expect(fn () => $service->createSchedule(
        $courseA->id,
        $teacherB->id,
        [
            'day_of_week' => 1,
            'starts_at' => '08:00',
            'ends_at' => '10:00',
            'description' => 'Test',
        ]
    ))->toThrow(ValidationException::class);
});

it('prevents teacher overlap within same business', function () {
    // Set context to business A
    app(CurrentBusiness::class)->setId($this->businessA->id);

    $courseA = Course::factory()->create(['name' => 'Course A']);
    $courseB = Course::factory()->create(['name' => 'Course B']);
    $teacher = Teacher::factory()->create(['name' => 'Teacher A']);

    $service = app(ScheduleService::class);

    // Create first schedule
    $service->createSchedule(
        $courseA->id,
        $teacher->id,
        [
            'day_of_week' => 1,
            'starts_at' => '08:00',
            'ends_at' => '10:00',
            'description' => 'First schedule',
        ]
    );

    // Try to create overlapping schedule with same teacher
    expect(fn () => $service->createSchedule(
        $courseB->id,
        $teacher->id,
        [
            'day_of_week' => 1,
            'starts_at' => '09:00',
            'ends_at' => '11:00',
            'description' => 'Overlapping schedule',
        ]
    ))->toThrow(ValidationException::class);
});

it('allows adjacent schedules (non-overlapping) for same teacher', function () {
    // Set context to business A
    app(CurrentBusiness::class)->setId($this->businessA->id);

    $courseA = Course::factory()->create(['name' => 'Course A']);
    $courseB = Course::factory()->create(['name' => 'Course B']);
    $teacher = Teacher::factory()->create(['name' => 'Teacher A']);

    $service = app(ScheduleService::class);

    // Create first schedule 08:00 - 10:00
    $schedule1 = $service->createSchedule(
        $courseA->id,
        $teacher->id,
        [
            'day_of_week' => 1,
            'starts_at' => '08:00',
            'ends_at' => '10:00',
            'description' => 'First schedule',
        ]
    );

    // Create adjacent schedule 10:00 - 12:00 (should succeed)
    $schedule2 = $service->createSchedule(
        $courseB->id,
        $teacher->id,
        [
            'day_of_week' => 1,
            'starts_at' => '10:00',
            'ends_at' => '12:00',
            'description' => 'Adjacent schedule',
        ]
    );

    expect($schedule1)->toBeInstanceOf(Schedule::class);
    expect($schedule2)->toBeInstanceOf(Schedule::class);
});

// NOTE: This test is skipped because course overlap validation is currently DISABLED
// to allow multiple teachers teaching the same course at the same time (parallel sections).
// Uncomment this test if you enable course overlap validation in ScheduleService.
it('prevents course overlap within same business', function () {
    // Set context to business A
    app(CurrentBusiness::class)->setId($this->businessA->id);

    $course = Course::factory()->create(['name' => 'Course A']);
    $teacherA = Teacher::factory()->create(['name' => 'Teacher A']);
    $teacherB = Teacher::factory()->create(['name' => 'Teacher B']);

    $service = app(ScheduleService::class);

    // Create first schedule with teacher A
    $schedule1 = $service->createSchedule(
        $course->id,
        $teacherA->id,
        [
            'day_of_week' => 1,
            'starts_at' => '08:00',
            'ends_at' => '10:00',
            'description' => 'First schedule',
        ]
    );

    // Create overlapping schedule with same course but different teacher (ALLOWED now)
    $schedule2 = $service->createSchedule(
        $course->id,
        $teacherB->id,
        [
            'day_of_week' => 1,
            'starts_at' => '09:00',
            'ends_at' => '11:00',
            'description' => 'Overlapping schedule',
        ]
    );

    // Both schedules should exist
    expect($schedule1)->toBeInstanceOf(Schedule::class);
    expect($schedule2)->toBeInstanceOf(Schedule::class);
})->skip('Course overlap validation is currently disabled');

it('allows same course at different times', function () {
    // Set context to business A
    app(CurrentBusiness::class)->setId($this->businessA->id);

    $course = Course::factory()->create(['name' => 'Course A']);
    $teacherA = Teacher::factory()->create(['name' => 'Teacher A']);
    $teacherB = Teacher::factory()->create(['name' => 'Teacher B']);

    $service = app(ScheduleService::class);

    // Create first schedule 08:00 - 10:00
    $schedule1 = $service->createSchedule(
        $course->id,
        $teacherA->id,
        [
            'day_of_week' => 1,
            'starts_at' => '08:00',
            'ends_at' => '10:00',
            'description' => 'First schedule',
        ]
    );

    // Create non-overlapping schedule with same course 10:00 - 12:00 (should succeed)
    $schedule2 = $service->createSchedule(
        $course->id,
        $teacherB->id,
        [
            'day_of_week' => 1,
            'starts_at' => '10:00',
            'ends_at' => '12:00',
            'description' => 'Second schedule',
        ]
    );

    expect($schedule1)->toBeInstanceOf(Schedule::class);
    expect($schedule2)->toBeInstanceOf(Schedule::class);
});

it('allows teacher overlap between different businesses', function () {
    // Create teacher with same name in both businesses
    app(CurrentBusiness::class)->setId($this->businessA->id);
    $courseA = Course::factory()->create(['name' => 'Course A']);
    $teacherA = Teacher::factory()->create(['name' => 'Teacher A']);

    app(CurrentBusiness::class)->setId($this->businessB->id);
    $courseB = Course::factory()->create(['name' => 'Course B']);
    $teacherB = Teacher::factory()->create(['name' => 'Teacher B']);

    $service = app(ScheduleService::class);

    // Create schedule in business A
    app(CurrentBusiness::class)->setId($this->businessA->id);
    $scheduleA = $service->createSchedule(
        $courseA->id,
        $teacherA->id,
        [
            'day_of_week' => 1,
            'starts_at' => '08:00',
            'ends_at' => '10:00',
            'description' => 'Schedule A',
        ]
    );

    // Create overlapping schedule in business B (should succeed - different business)
    app(CurrentBusiness::class)->setId($this->businessB->id);
    $scheduleB = $service->createSchedule(
        $courseB->id,
        $teacherB->id,
        [
            'day_of_week' => 1,
            'starts_at' => '08:00',
            'ends_at' => '10:00',
            'description' => 'Schedule B',
        ]
    );

    expect($scheduleA)->toBeInstanceOf(Schedule::class);
    expect($scheduleB)->toBeInstanceOf(Schedule::class);
    expect($scheduleA->business_id)->toBe($this->businessA->id);
    expect($scheduleB->business_id)->toBe($this->businessB->id);
});

it('allows same schedule data in different businesses (unique per business)', function () {
    // Create identical data in both businesses
    app(CurrentBusiness::class)->setId($this->businessA->id);
    $courseA = Course::factory()->create(['name' => 'Same Course']);
    $teacherA = Teacher::factory()->create(['name' => 'Same Teacher']);

    app(CurrentBusiness::class)->setId($this->businessB->id);
    $courseB = Course::factory()->create(['name' => 'Same Course']);
    $teacherB = Teacher::factory()->create(['name' => 'Same Teacher']);

    $service = app(ScheduleService::class);

    // Create schedule in business A
    app(CurrentBusiness::class)->setId($this->businessA->id);
    $scheduleA = $service->createSchedule(
        $courseA->id,
        $teacherA->id,
        [
            'day_of_week' => 1,
            'starts_at' => '08:00',
            'ends_at' => '10:00',
            'description' => 'Same schedule',
        ]
    );

    // Create identical schedule in business B (should succeed)
    app(CurrentBusiness::class)->setId($this->businessB->id);
    $scheduleB = $service->createSchedule(
        $courseB->id,
        $teacherB->id,
        [
            'day_of_week' => 1,
            'starts_at' => '08:00',
            'ends_at' => '10:00',
            'description' => 'Same schedule',
        ]
    );

    expect($scheduleA->business_id)->toBe($this->businessA->id);
    expect($scheduleB->business_id)->toBe($this->businessB->id);

    $this->assertDatabaseHas('schedules', [
        'business_id' => $this->businessA->id,
        'day_of_week' => 1,
    ]);

    $this->assertDatabaseHas('schedules', [
        'business_id' => $this->businessB->id,
        'day_of_week' => 1,
    ]);
});

it('prevents changing business_id on update', function () {
    // Create schedule in business A
    app(CurrentBusiness::class)->setId($this->businessA->id);
    $course = Course::factory()->create(['name' => 'Course A']);
    $teacher = Teacher::factory()->create(['name' => 'Teacher A']);
    $schedule = Schedule::factory()->create([
        'course_id' => $course->id,
        'teacher_id' => $teacher->id,
        'day_of_week' => 1,
        'starts_at' => '08:00:00',
        'ends_at' => '10:00:00',
    ]);

    $originalBusinessId = $schedule->business_id;

    // Try to update business_id to business B
    $schedule->update(['business_id' => $this->businessB->id]);

    // Refresh from database
    $schedule->refresh();

    // business_id should remain unchanged
    expect($schedule->business_id)->toBe($originalBusinessId);
    expect($schedule->business_id)->toBe($this->businessA->id);
    expect($schedule->business_id)->not->toBe($this->businessB->id);
});

it('business_id is not in fillable array', function () {
    $schedule = new Schedule;

    expect($schedule->getFillable())->not->toContain('business_id');
    expect($schedule->getFillable())->toContain('course_id');
    expect($schedule->getFillable())->toContain('teacher_id');
    expect($schedule->getFillable())->toContain('day_of_week');
});

it('validates starts_at must be before ends_at', function () {
    app(CurrentBusiness::class)->setId($this->businessA->id);
    $course = Course::factory()->create(['name' => 'Course A']);
    $teacher = Teacher::factory()->create(['name' => 'Teacher A']);

    $service = app(ScheduleService::class);

    expect(fn () => $service->createSchedule(
        $course->id,
        $teacher->id,
        [
            'day_of_week' => 1,
            'starts_at' => '10:00',
            'ends_at' => '08:00', // ends before starts
            'description' => 'Invalid time',
        ]
    ))->toThrow(ValidationException::class);
});
