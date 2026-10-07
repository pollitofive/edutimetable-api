<?php

use App\Models\Course;
use App\Models\Schedule;
use App\Models\Student;
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

describe('POST /enrollments/{id}/drop', function () {
    beforeEach(function () {
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
