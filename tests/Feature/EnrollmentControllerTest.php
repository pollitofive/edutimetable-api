<?php

use App\Models\Course;
use App\Models\Schedule;
use App\Models\Student;
use App\Models\StudentAvailability;
use App\Models\StudentEnrollment;
use App\Models\Teacher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

beforeEach(function () {
    $tenancy = setupTenancy();
    $this->user = $tenancy->user;
    $this->business = $tenancy->business;
    Sanctum::actingAs($this->user);

    $this->student = Student::factory()->create();
    $this->teacher = Teacher::factory()->create();
    $this->course = Course::factory()->create(['name' => 'English 101']);
    $this->schedule = Schedule::factory()->create([
        'course_id' => $this->course->id,
        'teacher_id' => $this->teacher->id,
        'day_of_week' => 1,
        'starts_at' => '09:00',
        'ends_at' => '11:00',
    ]);
});

describe('GET /enrollments', function () {
    it('lists enrollments paginated with relationships loaded', function () {
        StudentEnrollment::factory()->count(3)->create([
            'student_id' => $this->student->id,
        ]);

        $response = $this->getJson('/api/enrollments');

        $response->assertOk()
            ->assertJsonCount(3, 'data')
            ->assertJsonStructure([
                'data' => [
                    ['id', 'student_id', 'schedule_id', 'status', 'notes',
                        'student' => ['id', 'name'],
                        'schedule' => ['id', 'course' => ['id', 'name'], 'teacher' => ['id', 'name']],
                    ],
                ],
                'current_page', 'last_page', 'total', 'per_page',
            ]);
    });

    it('filters by student_id', function () {
        StudentEnrollment::factory()->create(['student_id' => $this->student->id, 'schedule_id' => $this->schedule->id]);
        $otherStudent = Student::factory()->create();
        StudentEnrollment::factory()->create(['student_id' => $otherStudent->id, 'schedule_id' => $this->schedule->id]);

        $response = $this->getJson("/api/enrollments?student_id={$this->student->id}");

        $response->assertOk()->assertJsonCount(1, 'data');
        expect($response->json('data.0.student_id'))->toBe($this->student->id);
    });

    it('filters by schedule_id', function () {
        $otherSchedule = Schedule::factory()->create([
            'course_id' => $this->course->id,
            'teacher_id' => $this->teacher->id,
            'day_of_week' => 2,
            'starts_at' => '12:00',
            'ends_at' => '14:00',
        ]);
        StudentEnrollment::factory()->create(['student_id' => $this->student->id, 'schedule_id' => $this->schedule->id]);
        StudentEnrollment::factory()->create(['student_id' => $this->student->id, 'schedule_id' => $otherSchedule->id]);

        $response = $this->getJson("/api/enrollments?schedule_id={$this->schedule->id}");

        $response->assertOk()->assertJsonCount(1, 'data');
        expect($response->json('data.0.schedule_id'))->toBe($this->schedule->id);
    });

    it('filters by status', function () {
        StudentEnrollment::factory()->active()->count(2)->create(['student_id' => $this->student->id]);
        StudentEnrollment::factory()->completed()->count(3)->create(['student_id' => $this->student->id]);

        $response = $this->getJson('/api/enrollments?status=completed');

        $response->assertOk()->assertJsonCount(3, 'data');
        foreach ($response->json('data') as $enrollment) {
            expect($enrollment['status'])->toBe('completed');
        }
    });

    it('rejects an invalid status filter', function () {
        $response = $this->getJson('/api/enrollments?status=bogus');

        $response->assertStatus(422)->assertJsonValidationErrors(['status']);
    });

    it('respects per_page and paginates results', function () {
        StudentEnrollment::factory()->count(5)->create(['student_id' => $this->student->id]);

        $response = $this->getJson('/api/enrollments?per_page=2');

        $response->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('per_page', 2)
            ->assertJsonPath('total', 5)
            ->assertJsonPath('last_page', 3);
    });

    it('does not return enrollments belonging to another business', function () {
        StudentEnrollment::factory()->create(['student_id' => $this->student->id, 'schedule_id' => $this->schedule->id]);

        $otherTenancy = setupTenancy();
        Sanctum::actingAs($otherTenancy->user);

        $response = $this->getJson('/api/enrollments');

        $response->assertOk()->assertJsonCount(0, 'data');
    });
});

describe('PATCH /enrollments/{id}', function () {
    beforeEach(function () {
        foreach ([1, 3] as $day) {
            StudentAvailability::factory()->create([
                'student_id' => $this->student->id,
                'day_of_week' => $day,
                'start_time' => '08:00',
                'end_time' => '20:00',
            ]);
        }

        $this->schedule->update(['group_id' => 'group-1', 'capacity' => 5]);
        $this->sibling = Schedule::factory()->create([
            'course_id' => $this->course->id,
            'teacher_id' => $this->teacher->id,
            'day_of_week' => 3,
            'starts_at' => '09:00',
            'ends_at' => '11:00',
            'group_id' => 'group-1',
            'capacity' => 5,
        ]);

        // Both days of the group with the given status; returns the first day's enrollment
        $this->enrollInGroup = function (string $status) {
            StudentEnrollment::factory()->create([
                'student_id' => $this->student->id,
                'schedule_id' => $this->sibling->id,
                'status' => $status,
            ]);

            return StudentEnrollment::factory()->create([
                'student_id' => $this->student->id,
                'schedule_id' => $this->schedule->id,
                'status' => $status,
            ]);
        };

        $this->groupStatuses = fn () => StudentEnrollment::where('student_id', $this->student->id)
            ->whereIn('schedule_id', [$this->schedule->id, $this->sibling->id])
            ->pluck('status', 'schedule_id')
            ->all();
    });

    it('updates status and notes', function () {
        $enrollment = StudentEnrollment::factory()->create([
            'student_id' => $this->student->id,
            'schedule_id' => $this->schedule->id,
            'status' => 'pending',
        ]);

        $response = $this->patchJson("/api/enrollments/{$enrollment->id}", [
            'status' => 'active',
            'notes' => 'Updated via REST',
        ]);

        $response->assertOk()
            ->assertJson([
                'id' => $enrollment->id,
                'status' => 'active',
                'notes' => 'Updated via REST',
            ]);

        expect($enrollment->fresh()->status)->toBe('active');
    });

    it('reactivates every day of the group', function () {
        $enrollment = ($this->enrollInGroup)('dropped');

        $this->patchJson("/api/enrollments/{$enrollment->id}", ['status' => 'active'])->assertOk();

        expect(($this->groupStatuses)())->toBe([
            $this->schedule->id => 'active',
            $this->sibling->id => 'active',
        ]);
    });

    it('reactivates only the days of the group that were not active', function () {
        $enrollment = ($this->enrollInGroup)('dropped');
        StudentEnrollment::where('schedule_id', $this->sibling->id)->update(['status' => 'active']);

        $this->patchJson("/api/enrollments/{$enrollment->id}", ['status' => 'active'])->assertOk();

        expect(array_values(($this->groupStatuses)()))->toBe(['active', 'active']);
    });

    it('rejects reactivating when a day of the group has no spots left', function () {
        $enrollment = ($this->enrollInGroup)('dropped');
        $this->sibling->update(['capacity' => 1]);
        StudentEnrollment::factory()->active()->create([
            'student_id' => Student::factory()->create()->id,
            'schedule_id' => $this->sibling->id,
        ]);

        $this->patchJson("/api/enrollments/{$enrollment->id}", ['status' => 'active'])
            ->assertStatus(422)
            ->assertJson(['message' => __('enrollment.no_spots')]);

        expect(array_values(($this->groupStatuses)()))->toBe(['dropped', 'dropped']);
    });

    it('rejects reactivating when the group overlaps another course of the student', function () {
        $enrollment = ($this->enrollInGroup)('dropped');
        $other = Schedule::factory()->create([
            'course_id' => Course::factory()->create(['name' => 'French 201'])->id,
            'teacher_id' => $this->teacher->id,
            'day_of_week' => 3,
            'starts_at' => '10:00',
            'ends_at' => '12:00',
        ]);
        StudentEnrollment::factory()->active()->create([
            'student_id' => $this->student->id,
            'schedule_id' => $other->id,
        ]);

        $response = $this->patchJson("/api/enrollments/{$enrollment->id}", ['status' => 'active']);

        $response->assertStatus(422);
        expect($response->json('message'))->toContain('French 201')
            ->and(array_values(($this->groupStatuses)()))->toBe(['dropped', 'dropped']);
    });

    it('rejects reactivating outside the student availability', function () {
        $enrollment = ($this->enrollInGroup)('completed');
        $this->sibling->update(['starts_at' => '19:00', 'ends_at' => '21:00']);

        $this->patchJson("/api/enrollments/{$enrollment->id}", ['status' => 'active'])->assertStatus(422);

        expect(array_values(($this->groupStatuses)()))->toBe(['completed', 'completed']);
    });

    it('applies a non-active status to every day of the group', function () {
        $enrollment = ($this->enrollInGroup)('active');

        $this->patchJson("/api/enrollments/{$enrollment->id}", ['status' => 'dropped'])->assertOk();

        expect(array_values(($this->groupStatuses)()))->toBe(['dropped', 'dropped']);
    });

    it('updates only the notes without revalidating or touching the group', function () {
        $enrollment = ($this->enrollInGroup)('active');
        $this->schedule->update(['capacity' => 1]);
        StudentEnrollment::factory()->active()->create([
            'student_id' => Student::factory()->create()->id,
            'schedule_id' => $this->schedule->id,
        ]);

        $this->patchJson("/api/enrollments/{$enrollment->id}", ['status' => 'active', 'notes' => 'Only notes'])
            ->assertOk()
            ->assertJson(['notes' => 'Only notes']);

        expect(StudentEnrollment::where('schedule_id', $this->sibling->id)->value('notes'))->not->toBe('Only notes');
    });

    it('rejects an invalid status', function () {
        $enrollment = StudentEnrollment::factory()->create([
            'student_id' => $this->student->id,
            'schedule_id' => $this->schedule->id,
        ]);

        $response = $this->patchJson("/api/enrollments/{$enrollment->id}", ['status' => 'bogus']);

        $response->assertStatus(422)->assertJsonValidationErrors(['status']);
    });

    it('returns 404 for an enrollment that does not exist', function () {
        $response = $this->patchJson('/api/enrollments/999999', ['status' => 'active']);

        $response->assertStatus(404);
    });

    it('returns 404 for an enrollment belonging to another business', function () {
        $enrollment = StudentEnrollment::factory()->create([
            'student_id' => $this->student->id,
            'schedule_id' => $this->schedule->id,
        ]);

        $otherTenancy = setupTenancy();
        Sanctum::actingAs($otherTenancy->user);

        $response = $this->patchJson("/api/enrollments/{$enrollment->id}", ['status' => 'active']);

        $response->assertStatus(404);
    });
});

describe('POST /enrollments', function () {
    beforeEach(function () {
        // Available all day Monday and Wednesday
        foreach ([1, 3] as $day) {
            StudentAvailability::factory()->create([
                'student_id' => $this->student->id,
                'day_of_week' => $day,
                'start_time' => '08:00',
                'end_time' => '20:00',
            ]);
        }

        $this->schedule->update(['capacity' => 5]);
        $this->otherCourse = Course::factory()->create(['name' => 'French 201']);
        $this->enrollInOtherCourse = function (int $day, string $startsAt, string $endsAt, string $status = 'active') {
            $schedule = Schedule::factory()->create([
                'course_id' => $this->otherCourse->id,
                'teacher_id' => $this->teacher->id,
                'day_of_week' => $day,
                'starts_at' => $startsAt,
                'ends_at' => $endsAt,
            ]);
            StudentEnrollment::factory()->create([
                'student_id' => $this->student->id,
                'schedule_id' => $schedule->id,
                'status' => $status,
            ]);

            return $schedule;
        };
    });

    it('enrolls the student when the schedule fits', function () {
        $response = $this->postJson('/api/enrollments', [
            'student_id' => $this->student->id,
            'schedule_ids' => [$this->schedule->id],
        ]);

        $response->assertCreated();
        expect(StudentEnrollment::where('schedule_id', $this->schedule->id)->value('status'))->toBe('active');
    });

    it('rejects a schedule overlapping another course of the student, naming it', function () {
        ($this->enrollInOtherCourse)(1, '10:00', '12:00');

        $response = $this->postJson('/api/enrollments', [
            'student_id' => $this->student->id,
            'schedule_ids' => [$this->schedule->id],
        ]);

        $response->assertStatus(422);
        expect($response->json('message'))->toContain('French 201')
            ->and(StudentEnrollment::where('schedule_id', $this->schedule->id)->exists())->toBeFalse();
    });

    it('allows back-to-back schedules', function () {
        ($this->enrollInOtherCourse)(1, '11:00', '13:00');
        ($this->enrollInOtherCourse)(1, '07:00', '09:00');

        $this->postJson('/api/enrollments', [
            'student_id' => $this->student->id,
            'schedule_ids' => [$this->schedule->id],
        ])->assertCreated();
    });

    it('ignores enrollments that are not active', function () {
        ($this->enrollInOtherCourse)(1, '10:00', '12:00', 'dropped');

        $this->postJson('/api/enrollments', [
            'student_id' => $this->student->id,
            'schedule_ids' => [$this->schedule->id],
        ])->assertCreated();
    });

    it('rejects a schedule outside the student availability', function () {
        $this->schedule->update(['day_of_week' => 2]);

        $response = $this->postJson('/api/enrollments', [
            'student_id' => $this->student->id,
            'schedule_ids' => [$this->schedule->id],
        ]);

        $response->assertStatus(422);
        expect(StudentEnrollment::where('schedule_id', $this->schedule->id)->exists())->toBeFalse();
    });

    it('enrolls every day of a group without counting the group as an overlap', function () {
        $this->schedule->update(['group_id' => 'group-1']);
        $sibling = Schedule::factory()->create([
            'course_id' => $this->course->id,
            'teacher_id' => $this->teacher->id,
            'day_of_week' => 3,
            'starts_at' => '09:00',
            'ends_at' => '11:00',
            'group_id' => 'group-1',
            'capacity' => 5,
        ]);

        $this->postJson('/api/enrollments', [
            'student_id' => $this->student->id,
            'schedule_ids' => [$this->schedule->id, $sibling->id],
        ])->assertCreated();

        expect(StudentEnrollment::where('student_id', $this->student->id)->where('status', 'active')->count())->toBe(2);
    });

    it('rejects the whole group when one of its days overlaps', function () {
        $this->schedule->update(['group_id' => 'group-1']);
        $sibling = Schedule::factory()->create([
            'course_id' => $this->course->id,
            'teacher_id' => $this->teacher->id,
            'day_of_week' => 3,
            'starts_at' => '09:00',
            'ends_at' => '11:00',
            'group_id' => 'group-1',
            'capacity' => 5,
        ]);
        ($this->enrollInOtherCourse)(3, '10:00', '12:00');

        $this->postJson('/api/enrollments', [
            'student_id' => $this->student->id,
            'schedule_ids' => [$this->schedule->id, $sibling->id],
        ])->assertStatus(422);

        expect(StudentEnrollment::whereIn('schedule_id', [$this->schedule->id, $sibling->id])->exists())->toBeFalse();
    });

    it('rejects two overlapping schedules of different courses in the same request', function () {
        $other = Schedule::factory()->create([
            'course_id' => $this->otherCourse->id,
            'teacher_id' => $this->teacher->id,
            'day_of_week' => 1,
            'starts_at' => '10:00',
            'ends_at' => '12:00',
            'capacity' => 5,
        ]);

        $this->postJson('/api/enrollments', [
            'student_id' => $this->student->id,
            'schedule_ids' => [$this->schedule->id, $other->id],
        ])->assertStatus(422);

        expect(StudentEnrollment::where('student_id', $this->student->id)->exists())->toBeFalse();
    });
});

describe('enrollment modals', function () {
    beforeEach(function () {
        // Same level, so the student is offered the course and listed for it
        $this->student->forceFill(['course_level_id' => $this->course->course_level_id])->save();
        $this->schedule->update(['capacity' => 5]);
        StudentAvailability::factory()->create([
            'student_id' => $this->student->id,
            'day_of_week' => 1,
            'start_time' => '08:00',
            'end_time' => '20:00',
        ]);

        $otherCourse = Course::factory()->create(['name' => 'French 201']);
        $this->otherSchedule = Schedule::factory()->create([
            'course_id' => $otherCourse->id,
            'teacher_id' => $this->teacher->id,
            'day_of_week' => 1,
            'starts_at' => '10:00',
            'ends_at' => '12:00',
        ]);
        $this->enrollInOther = fn () => StudentEnrollment::factory()->active()->create([
            'student_id' => $this->student->id,
            'schedule_id' => $this->otherSchedule->id,
        ]);
    });

    it('compatible-schedules marks an overlapping group as blocked, with the course', function () {
        ($this->enrollInOther)();

        $group = collect($this->getJson("/api/students/{$this->student->id}/compatible-schedules")->assertOk()->json('schedule_groups'))
            ->firstWhere('schedule_ids', [$this->schedule->id]);

        expect($group['is_compatible'])->toBeFalse()
            ->and($group['blocked_reason'])->toBe('overlap')
            ->and($group['conflict'])->toBe(['course_name' => 'French 201', 'day_of_week' => 1, 'starts_at' => '10:00', 'ends_at' => '12:00']);
    });

    it('compatible-schedules reports a fitting group as compatible', function () {
        $group = collect($this->getJson("/api/students/{$this->student->id}/compatible-schedules")->assertOk()->json('schedule_groups'))
            ->firstWhere('schedule_ids', [$this->schedule->id]);

        expect($group['is_compatible'])->toBeTrue()
            ->and($group['blocked_reason'])->toBeNull()
            ->and($group['conflict'])->toBeNull();
    });

    it('compatible-schedules marks a group outside the availability as unavailable', function () {
        $this->schedule->update(['day_of_week' => 2]);

        $group = collect($this->getJson("/api/students/{$this->student->id}/compatible-schedules")->assertOk()->json('schedule_groups'))
            ->firstWhere('schedule_ids', [$this->schedule->id]);

        expect($group['is_compatible'])->toBeFalse()
            ->and($group['blocked_reason'])->toBe('unavailable');
    });

    it('eligible-students marks a student with an overlapping course as blocked', function () {
        ($this->enrollInOther)();

        $student = collect($this->getJson("/api/schedules/{$this->schedule->id}/eligible-students")->assertOk()->json())
            ->firstWhere('id', $this->student->id);

        expect($student['is_available'])->toBeFalse()
            ->and($student['blocked_reason'])->toBe('overlap')
            ->and($student['conflict']['course_name'])->toBe('French 201');
    });

    it('eligible-students checks every day of the group, not only the clicked one', function () {
        $this->schedule->update(['group_id' => 'group-1']);
        Schedule::factory()->create([
            'course_id' => $this->course->id,
            'teacher_id' => $this->teacher->id,
            'day_of_week' => 3, // the student is only available on Monday
            'starts_at' => '09:00',
            'ends_at' => '11:00',
            'group_id' => 'group-1',
        ]);

        $student = collect($this->getJson("/api/schedules/{$this->schedule->id}/eligible-students")->assertOk()->json())
            ->firstWhere('id', $this->student->id);

        expect($student['is_available'])->toBeFalse()
            ->and($student['blocked_reason'])->toBe('unavailable');
    });
});

describe('POST /enrollments/{id}/drop', function () {
    beforeEach(function () {
        foreach ([1, 3] as $day) {
            StudentAvailability::factory()->create([
                'student_id' => $this->student->id,
                'day_of_week' => $day,
                'start_time' => '08:00',
                'end_time' => '20:00',
            ]);
        }

        $this->schedule->update(['group_id' => 'group-1', 'capacity' => 5]);
        $this->sibling = Schedule::factory()->create([
            'course_id' => $this->course->id,
            'teacher_id' => $this->teacher->id,
            'day_of_week' => 3,
            'starts_at' => '09:00',
            'ends_at' => '11:00',
            'group_id' => 'group-1',
            'capacity' => 5,
        ]);
    });

    it('drops the student from every schedule of the group', function () {
        $enrollment = StudentEnrollment::factory()->active()->create([
            'student_id' => $this->student->id,
            'schedule_id' => $this->schedule->id,
        ]);
        $siblingEnrollment = StudentEnrollment::factory()->active()->create([
            'student_id' => $this->student->id,
            'schedule_id' => $this->sibling->id,
        ]);
        $otherStudent = Student::factory()->create();
        $otherEnrollment = StudentEnrollment::factory()->active()->create([
            'student_id' => $otherStudent->id,
            'schedule_id' => $this->schedule->id,
        ]);

        $response = $this->postJson("/api/enrollments/{$enrollment->id}/drop");

        $response->assertOk()->assertJson(['dropped' => 2]);
        expect($enrollment->fresh()->status)->toBe('dropped')
            ->and($siblingEnrollment->fresh()->status)->toBe('dropped')
            ->and($otherEnrollment->fresh()->status)->toBe('active');
    });

    it('frees the spot and hides the student from the schedule', function () {
        $enrollment = StudentEnrollment::factory()->active()->create([
            'student_id' => $this->student->id,
            'schedule_id' => $this->schedule->id,
        ]);

        $this->postJson("/api/enrollments/{$enrollment->id}/drop")->assertOk();

        $schedule = collect($this->getJson('/api/enrollments/schedules')->json())
            ->firstWhere('id', $this->schedule->id);

        expect($schedule['enrolled_count'])->toBe(0)
            ->and($schedule['available_spots'])->toBe(5)
            ->and($schedule['active_enrollments'])->toBeEmpty();
    });

    it('allows re-enrolling a dropped student in the same group', function () {
        $enrollment = StudentEnrollment::factory()->active()->create([
            'student_id' => $this->student->id,
            'schedule_id' => $this->schedule->id,
        ]);
        StudentEnrollment::factory()->active()->create([
            'student_id' => $this->student->id,
            'schedule_id' => $this->sibling->id,
        ]);
        $this->postJson("/api/enrollments/{$enrollment->id}/drop")->assertOk();

        $response = $this->postJson('/api/enrollments', [
            'student_id' => $this->student->id,
            'schedule_ids' => [$this->schedule->id, $this->sibling->id],
        ]);

        $response->assertCreated();
        expect(StudentEnrollment::where('student_id', $this->student->id)->count())->toBe(2)
            ->and(StudentEnrollment::where('student_id', $this->student->id)->pluck('status')->unique()->all())->toBe(['active']);
    });

    it('rejects dropping an enrollment that is not active', function () {
        $enrollment = StudentEnrollment::factory()->create([
            'student_id' => $this->student->id,
            'schedule_id' => $this->schedule->id,
            'status' => 'dropped',
        ]);

        $this->postJson("/api/enrollments/{$enrollment->id}/drop")->assertStatus(422);
    });

    it('returns 404 for an enrollment belonging to another business', function () {
        $enrollment = StudentEnrollment::factory()->active()->create([
            'student_id' => $this->student->id,
            'schedule_id' => $this->schedule->id,
        ]);

        Sanctum::actingAs(setupTenancy()->user);

        $this->postJson("/api/enrollments/{$enrollment->id}/drop")->assertStatus(404);
    });
});
