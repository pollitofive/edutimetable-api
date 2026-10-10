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
    Sanctum::actingAs($this->user);

    $this->teacher = Teacher::factory()->create(['name' => 'Ana']);
    $this->course = Course::factory()->create(['name' => 'English 101']);

    // Course on Tuesday and Thursday 19-20
    $groupId = 'group-english-101';
    $this->tue = Schedule::factory()->create([
        'course_id' => $this->course->id, 'teacher_id' => $this->teacher->id, 'group_id' => $groupId,
        'day_of_week' => 2, 'starts_at' => '19:00:00', 'ends_at' => '20:00:00', 'capacity' => 10,
    ]);
    $this->thu = Schedule::factory()->create([
        'course_id' => $this->course->id, 'teacher_id' => $this->teacher->id, 'group_id' => $groupId,
        'day_of_week' => 4, 'starts_at' => '19:00:00', 'ends_at' => '20:00:00', 'capacity' => 10,
    ]);

    // Student enrolled in both days, available Tue/Thu 18-21
    $this->student = Student::factory()->create(['name' => 'Bruno']);
    foreach ([2, 4] as $day) {
        StudentAvailability::factory()->create([
            'student_id' => $this->student->id, 'day_of_week' => $day,
            'start_time' => '18:00:00', 'end_time' => '21:00:00',
        ]);
    }
    foreach ([$this->tue, $this->thu] as $schedule) {
        StudentEnrollment::factory()->active()->create([
            'student_id' => $this->student->id, 'schedule_id' => $schedule->id,
        ]);
    }
});

function slots(array $tue, array $thu): array
{
    return [
        ['schedule_id' => test()->tue->id] + $tue,
        ['schedule_id' => test()->thu->id] + $thu,
    ];
}

function slot(int $day, string $start, string $end): array
{
    return ['day_of_week' => $day, 'starts_at' => $start, 'ends_at' => $end];
}

describe('GET /enrollments/schedules group_slots', function () {
    it('lists every day of the group even when the day filter hides some', function () {
        $this->getJson('/api/enrollments/schedules?days_of_week[]=2')
            ->assertOk()
            ->assertJsonCount(1)
            ->assertJsonCount(2, '0.group_slots')
            ->assertJsonPath('0.group_slots.1.id', $this->thu->id)
            ->assertJsonPath('0.group_slots.1.day_of_week', 4)
            ->assertJsonPath('0.group_slots.1.starts_at', '19:00');
    });
});

describe('POST /schedule-changes/preview', function () {
    it('previews a change without conflicts and saves nothing', function () {
        $response = $this->postJson('/api/schedule-changes/preview', [
            'slots' => slots(slot(2, '19:30', '20:30'), slot(4, '19:30', '20:30')),
        ]);

        $response->assertOk()
            ->assertJsonPath('can_apply', true)
            ->assertJsonCount(2, 'changes')
            ->assertJsonPath('changes.0.from.starts_at', '19:00')
            ->assertJsonPath('changes.0.to.starts_at', '19:30')
            ->assertJsonCount(0, 'teacher_conflicts')
            ->assertJsonCount(0, 'internal_conflicts')
            ->assertJsonCount(0, 'affected_students');

        expect($this->tue->fresh()->starts_at)->toBe('19:00:00');
    });

    it('only reports the slots that change', function () {
        $this->postJson('/api/schedule-changes/preview', [
            'slots' => slots(slot(2, '19:00', '20:00'), slot(5, '19:00', '20:00')),
        ])->assertOk()
            ->assertJsonCount(1, 'changes')
            ->assertJsonPath('changes.0.schedule_id', $this->thu->id);
    });

    it('blocks when the teacher has another course at the new time', function () {
        $other = Schedule::factory()->create([
            'teacher_id' => $this->teacher->id, 'day_of_week' => 2,
            'starts_at' => '17:00:00', 'ends_at' => '18:30:00',
        ]);

        $this->postJson('/api/schedule-changes/preview', [
            'slots' => slots(slot(2, '18:00', '19:00'), slot(4, '18:00', '19:00')),
        ])->assertOk()
            ->assertJsonPath('can_apply', false)
            ->assertJsonCount(1, 'teacher_conflicts')
            ->assertJsonPath('teacher_conflicts.0.schedule_id', $this->tue->id)
            ->assertJsonPath('teacher_conflicts.0.teacher.name', 'Ana')
            ->assertJsonPath('teacher_conflicts.0.conflict.schedule_id', $other->id);
    });

    it('blocks when two slots of the course end up overlapping each other', function () {
        $this->postJson('/api/schedule-changes/preview', [
            'slots' => slots(slot(2, '19:00', '20:00'), slot(2, '19:30', '20:30')),
        ])->assertOk()
            ->assertJsonPath('can_apply', false)
            ->assertJsonCount(1, 'internal_conflicts');
    });

    it('reports a student left without availability', function () {
        $this->postJson('/api/schedule-changes/preview', [
            'slots' => slots(slot(3, '19:00', '20:00'), slot(4, '19:00', '20:00')),
        ])->assertOk()
            ->assertJsonPath('can_apply', true)
            ->assertJsonCount(1, 'affected_students')
            ->assertJsonPath('affected_students.0.student.id', $this->student->id)
            ->assertJsonPath('affected_students.0.issues.0.type', 'unavailable')
            ->assertJsonPath('affected_students.0.issues.0.day_of_week', 3)
            ->assertJsonCount(2, 'affected_students.0.availabilities')
            ->assertJsonPath('affected_students.0.availabilities.0', slot(2, '18:00', '21:00'));
    });

    it('reports a student overlapping another course they attend', function () {
        $otherCourse = Course::factory()->create(['name' => 'French 101']);
        $other = Schedule::factory()->create([
            'course_id' => $otherCourse->id, 'day_of_week' => 4,
            'starts_at' => '18:00:00', 'ends_at' => '19:00:00',
        ]);
        StudentEnrollment::factory()->active()->create([
            'student_id' => $this->student->id, 'schedule_id' => $other->id,
        ]);

        $this->postJson('/api/schedule-changes/preview', [
            'slots' => slots(slot(2, '18:30', '19:30'), slot(4, '18:30', '19:30')),
        ])->assertOk()
            ->assertJsonPath('can_apply', true)
            ->assertJsonCount(1, 'affected_students')
            ->assertJsonCount(1, 'affected_students.0.issues')
            ->assertJsonPath('affected_students.0.issues.0.type', 'overlap')
            ->assertJsonPath('affected_students.0.issues.0.day_of_week', 4)
            ->assertJsonPath('affected_students.0.issues.0.other.course_name', 'French 101');
    });

    it('ignores dropped students', function () {
        StudentEnrollment::query()->update(['status' => 'dropped']);

        $this->postJson('/api/schedule-changes/preview', [
            'slots' => slots(slot(3, '19:00', '20:00'), slot(4, '19:00', '20:00')),
        ])->assertOk()->assertJsonCount(0, 'affected_students');
    });

    it('rejects slots that are not the whole group', function () {
        $this->postJson('/api/schedule-changes/preview', [
            'slots' => [['schedule_id' => $this->tue->id] + slot(3, '19:00', '20:00')],
        ])->assertStatus(422);
    });

    it('rejects slots from different groups', function () {
        $other = Schedule::factory()->create();

        $this->postJson('/api/schedule-changes/preview', [
            'slots' => [
                ['schedule_id' => $this->tue->id] + slot(3, '19:00', '20:00'),
                ['schedule_id' => $other->id] + slot(4, '19:00', '20:00'),
            ],
        ])->assertStatus(422);
    });

    it('rejects a proposal identical to the current schedule', function () {
        $this->postJson('/api/schedule-changes/preview', [
            'slots' => slots(slot(2, '19:00', '20:00'), slot(4, '19:00', '20:00')),
        ])->assertStatus(422);
    });

    it('rejects an end time before the start time', function () {
        $this->postJson('/api/schedule-changes/preview', [
            'slots' => slots(slot(2, '20:00', '19:00'), slot(4, '19:00', '20:00')),
        ])->assertStatus(422);
    });

    it('rejects schedules of another business', function () {
        Sanctum::actingAs(setupTenancy()->user);

        $this->postJson('/api/schedule-changes/preview', [
            'slots' => slots(slot(2, '19:30', '20:30'), slot(4, '19:30', '20:30')),
        ])->assertStatus(422);
    });
});

describe('POST /schedule-changes/apply', function () {
    it('moves the schedules in place keeping the enrollments', function () {
        $this->postJson('/api/schedule-changes/apply', [
            'slots' => slots(slot(1, '18:00', '19:30'), slot(3, '18:00', '19:30')),
        ])->assertOk()
            ->assertJsonCount(2, 'changes')
            ->assertJsonPath('dropped_students', 0);

        expect($this->tue->fresh())
            ->day_of_week->toBe(1)
            ->starts_at->toBe('18:00:00')
            ->ends_at->toBe('19:30:00');
        expect($this->thu->fresh()->day_of_week)->toBe(3);

        expect(StudentEnrollment::where('student_id', $this->student->id)
            ->whereIn('schedule_id', [$this->tue->id, $this->thu->id])
            ->where('status', 'active')
            ->count())->toBe(2);
    });

    it('drops the selected affected students from the whole group', function () {
        $this->postJson('/api/schedule-changes/apply', [
            'slots' => slots(slot(3, '19:00', '20:00'), slot(4, '19:00', '20:00')),
            'drop_student_ids' => [$this->student->id],
        ])->assertOk()->assertJsonPath('dropped_students', 1);

        expect(StudentEnrollment::where('student_id', $this->student->id)->pluck('status')->unique()->all())
            ->toBe(['dropped']);
        expect($this->tue->fresh()->day_of_week)->toBe(3);
    });

    it('rejects dropping a student that is not affected', function () {
        $this->postJson('/api/schedule-changes/apply', [
            'slots' => slots(slot(2, '19:30', '20:30'), slot(4, '19:30', '20:30')),
            'drop_student_ids' => [$this->student->id],
        ])->assertStatus(422);

        expect($this->tue->fresh()->starts_at)->toBe('19:00:00');
        expect(StudentEnrollment::where('status', 'active')->count())->toBe(2);
    });

    it('refuses to apply a change with a teacher conflict', function () {
        Schedule::factory()->create([
            'teacher_id' => $this->teacher->id, 'day_of_week' => 2,
            'starts_at' => '17:00:00', 'ends_at' => '18:30:00',
        ]);

        $this->postJson('/api/schedule-changes/apply', [
            'slots' => slots(slot(2, '18:00', '19:00'), slot(4, '18:00', '19:00')),
        ])->assertStatus(422);

        expect($this->tue->fresh()->starts_at)->toBe('19:00:00');
        expect($this->thu->fresh()->starts_at)->toBe('19:00:00');
    });

    it('moves a single-day schedule without group', function () {
        $solo = Schedule::factory()->create([
            'group_id' => null, 'day_of_week' => 5, 'starts_at' => '10:00:00', 'ends_at' => '11:00:00',
        ]);

        $this->postJson('/api/schedule-changes/apply', [
            'slots' => [['schedule_id' => $solo->id] + slot(6, '10:00', '12:00')],
        ])->assertOk();

        expect($solo->fresh())->day_of_week->toBe(6)->ends_at->toBe('12:00:00');
    });
});
