<?php

use App\Models\Course;
use App\Models\Schedule;
use App\Models\Student;
use App\Models\StudentEnrollment;
use App\Models\Teacher;
use App\Services\CurrentBusiness;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

beforeEach(function () {
    $tenancy = setupTenancy();
    $this->user = $tenancy->user;
    $this->business = $tenancy->business;
    Sanctum::actingAs($this->user);

    $this->teacher = Teacher::factory()->create(['name' => 'Ana']);
    $this->otherTeacher = Teacher::factory()->create(['name' => 'Bruno']);
    $this->course = Course::factory()->create(['name' => 'English 101']);

    // Two-day course with room for 3 students
    $this->monday = Schedule::factory()->create([
        'course_id' => $this->course->id, 'teacher_id' => $this->teacher->id,
        'group_id' => 'g-english', 'day_of_week' => 1, 'starts_at' => '09:00', 'ends_at' => '10:30', 'capacity' => 3,
    ]);
    $this->wednesday = Schedule::factory()->create([
        'course_id' => $this->course->id, 'teacher_id' => $this->teacher->id,
        'group_id' => 'g-english', 'day_of_week' => 3, 'starts_at' => '09:00', 'ends_at' => '10:30', 'capacity' => 3,
    ]);
});

function enrollForDashboard(Student $student, array $schedules, string $status = 'active'): void
{
    foreach ($schedules as $schedule) {
        StudentEnrollment::factory()->create([
            'student_id' => $student->id, 'schedule_id' => $schedule->id, 'status' => $status,
        ]);
    }
}

it('returns the dashboard structure', function () {
    $this->getJson('/api/dashboard')
        ->assertOk()
        ->assertJsonStructure([
            'totals' => ['active_students', 'courses', 'teachers', 'active_enrollments'],
            'courses_with_capacity' => [
                ['group_id', 'schedule_id', 'course' => ['id', 'name'], 'track' => ['id', 'name'], 'level',
                    'teachers', 'slots' => [['day_of_week', 'starts_at', 'ends_at']], 'capacity', 'enrolled', 'available_spots'],
            ],
            'teacher_load' => [['teacher' => ['id', 'name'], 'weekly_hours', 'active_students']],
        ]);
});

it('counts students and enrollments once per course group, ignoring non-active ones', function () {
    enrollForDashboard(Student::factory()->create(), [$this->monday, $this->wednesday]);
    enrollForDashboard(Student::factory()->create(), [$this->monday, $this->wednesday]);
    enrollForDashboard(Student::factory()->create(), [$this->monday, $this->wednesday], 'dropped');

    $response = $this->getJson('/api/dashboard')->assertOk();

    expect($response->json('totals'))->toBe([
        'active_students' => 2,
        'courses' => 1,
        'teachers' => 2,
        'active_enrollments' => 2,
    ]);
    expect($response->json('courses_with_capacity'))->toHaveCount(1);
    expect($response->json('courses_with_capacity.0'))->toMatchArray([
        'group_id' => 'g-english',
        'capacity' => 3,
        'enrolled' => 2,
        'available_spots' => 1,
        'teachers' => ['Ana'],
    ]);
    expect($response->json('courses_with_capacity.0.slots'))->toHaveCount(2);
});

it('leaves full courses out and sorts the rest by occupancy', function () {
    $emptyCourse = Schedule::factory()->create([
        'course_id' => Course::factory()->create()->id, 'teacher_id' => $this->otherTeacher->id,
        'group_id' => 'g-empty', 'day_of_week' => 2, 'starts_at' => '18:00', 'ends_at' => '19:00', 'capacity' => 10,
    ]);
    $fullCourse = Schedule::factory()->create([
        'course_id' => Course::factory()->create()->id, 'teacher_id' => $this->otherTeacher->id,
        'group_id' => 'g-full', 'day_of_week' => 4, 'starts_at' => '18:00', 'ends_at' => '19:00', 'capacity' => 1,
    ]);
    enrollForDashboard(Student::factory()->create(), [$fullCourse]);
    enrollForDashboard(Student::factory()->create(), [$this->monday, $this->wednesday]);

    $groups = collect($this->getJson('/api/dashboard')->json('courses_with_capacity'))->pluck('group_id');

    expect($groups->all())->toBe(['g-english', 'g-empty']);
});

it('sums weekly hours and active students per teacher', function () {
    Schedule::factory()->create([
        'course_id' => Course::factory()->create()->id, 'teacher_id' => $this->otherTeacher->id,
        'group_id' => 'g-bruno', 'day_of_week' => 5, 'starts_at' => '14:00', 'ends_at' => '14:45', 'capacity' => 5,
    ]);
    enrollForDashboard(Student::factory()->create(), [$this->monday, $this->wednesday]);

    $load = collect($this->getJson('/api/dashboard')->json('teacher_load'))->keyBy('teacher.name');

    expect($load['Ana']['weekly_hours'])->toEqual(3)
        ->and($load['Ana']['active_students'])->toBe(1)
        ->and($load['Bruno']['weekly_hours'])->toEqual(0.75)
        ->and($load['Bruno']['active_students'])->toBe(0);
});

it('only includes data from the current business', function () {
    enrollForDashboard(Student::factory()->create(), [$this->monday, $this->wednesday]);

    // Another business with its own course, teacher and enrollment
    setupTenancy();
    $otherSchedule = Schedule::factory()->create([
        'course_id' => Course::factory()->create()->id, 'teacher_id' => Teacher::factory()->create()->id,
        'day_of_week' => 2, 'starts_at' => '10:00', 'ends_at' => '11:00', 'capacity' => 5,
    ]);
    enrollForDashboard(Student::factory()->create(), [$otherSchedule]);
    app(CurrentBusiness::class)->setId($this->business->id);

    $response = $this->getJson('/api/dashboard')->assertOk();

    expect($response->json('totals'))->toBe([
        'active_students' => 1,
        'courses' => 1,
        'teachers' => 2,
        'active_enrollments' => 1,
    ]);
    expect($response->json('courses_with_capacity'))->toHaveCount(1)
        ->and($response->json('teacher_load'))->toHaveCount(2);
});

it('requires authentication', function () {
    app('auth')->forgetGuards();

    $this->getJson('/api/dashboard')->assertUnauthorized();
});
