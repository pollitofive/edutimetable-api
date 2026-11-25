<?php

use App\Models\Course;
use App\Models\CourseLevel;
use App\Models\Schedule;
use App\Models\Student;
use App\Models\StudentAvailability;
use App\Models\StudentEnrollment;
use App\Models\Teacher;
use App\Models\Track;
use App\Services\CurrentBusiness;
use App\Services\EnrollmentService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    $this->business = setupTenancy()->business;
    $this->service = app(EnrollmentService::class);

    $this->track = Track::factory()->create(['name' => 'Kids']);
    $this->level = CourseLevel::factory()->create(['track_id' => $this->track->id, 'name' => 'Beginner']);
    $this->course = Course::factory()->create(['name' => 'English 101', 'course_level_id' => $this->level->id]);
    $this->teacher = Teacher::factory()->create(['name' => 'Ana Teacher']);

    $this->makeSchedule = fn (array $attributes = []) => Schedule::factory()->create([
        'course_id' => $this->course->id,
        'teacher_id' => $this->teacher->id,
        'day_of_week' => 1,
        'starts_at' => '09:00:00',
        'ends_at' => '11:00:00',
        'group_id' => null,
        'capacity' => 5,
        ...$attributes,
    ]);

    $this->schedule = ($this->makeSchedule)();

    // Same level as the course, available all day Monday and Wednesday
    $this->student = Student::factory()->create([
        'name' => 'Bruno Student',
        'email' => 'bruno@example.com',
        'course_level_id' => $this->level->id,
    ]);
    $this->makeAvailable = function (Student $student, array $days = [1, 3]) {
        foreach ($days as $day) {
            StudentAvailability::factory()->create([
                'student_id' => $student->id,
                'day_of_week' => $day,
                'start_time' => '08:00:00',
                'end_time' => '20:00:00',
            ]);
        }
    };
    ($this->makeAvailable)($this->student);

    // Another course the student may attend, to produce overlaps
    $this->otherCourse = Course::factory()->create(['name' => 'French 201', 'course_level_id' => $this->level->id]);
    $this->enrollInOther = function (int $day, string $startsAt, string $endsAt, ?Student $student = null) {
        $schedule = ($this->makeSchedule)([
            'course_id' => $this->otherCourse->id,
            'teacher_id' => Teacher::factory()->create()->id,
            'day_of_week' => $day,
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
        ]);
        StudentEnrollment::factory()->active()->create([
            'student_id' => ($student ?? $this->student)->id,
            'schedule_id' => $schedule->id,
        ]);

        return $schedule;
    };

    // Errors of the ValidationException thrown by the callback
    $this->validationErrors = function (callable $callback): array {
        try {
            $callback();
        } catch (ValidationException $e) {
            return $e->errors();
        }

        throw new RuntimeException('Expected a ValidationException');
    };

    // Creates the given data in a new business and goes back to the original one
    $this->inOtherBusiness = function (callable $callback) {
        setupTenancy();
        $result = $callback();
        app(CurrentBusiness::class)->setId($this->business->id);

        return $result;
    };
});

describe('getSchedules', function () {
    it('returns every schedule formatted, counting only active enrollments', function () {
        StudentEnrollment::factory()->active()->create(['student_id' => $this->student->id, 'schedule_id' => $this->schedule->id]);
        StudentEnrollment::factory()->dropped()->create(['schedule_id' => $this->schedule->id]);

        $result = $this->service->getSchedules();

        expect($result)->toHaveCount(1);
        $schedule = $result->first();

        expect($schedule)->toMatchArray([
            'id' => $this->schedule->id,
            'starts_at' => '09:00',
            'ends_at' => '11:00',
            'group_id' => null,
            'capacity' => 5,
            'enrolled_count' => 1,
            'available_spots' => 4,
        ])
            ->and($schedule['course'])->toBe([
                'id' => $this->course->id,
                'name' => 'English 101',
                'course_level' => [
                    'id' => $this->level->id,
                    'track' => ['id' => $this->track->id, 'name' => 'Kids'],
                    'name' => 'Beginner',
                ],
            ])
            ->and($schedule['teacher'])->toBe(['id' => $this->teacher->id, 'name' => 'Ana Teacher'])
            ->and($schedule['active_enrollments'])->toHaveCount(1)
            ->and($schedule['active_enrollments'][0]['student']['id'])->toBe($this->student->id)
            ->and($schedule['active_enrollments'][0]['student']['course_level'])->toBe([
                'name' => 'Beginner',
                'track' => ['id' => $this->track->id, 'name' => 'Kids'],
            ])
            ->and($schedule['active_enrollments'][0]['status'])->toBe('active');
    });

    it('returns a single-slot group for a schedule without group_id', function () {
        $slots = $this->service->getSchedules()->first()['group_slots'];

        expect($slots)->toHaveCount(1)
            ->and($slots[0]['id'])->toBe($this->schedule->id)
            ->and($slots[0]['starts_at'])->toBe('09:00')
            ->and($slots[0]['ends_at'])->toBe('11:00');
    });

    it('filters by course, teacher, day (as strings) and track', function (string $filter) {
        $otherTrack = Track::factory()->create();
        $otherLevel = CourseLevel::factory()->create(['track_id' => $otherTrack->id]);
        $otherTeacher = Teacher::factory()->create();
        $other = ($this->makeSchedule)([
            'course_id' => Course::factory()->create(['course_level_id' => $otherLevel->id])->id,
            'teacher_id' => $otherTeacher->id,
            'day_of_week' => 2,
        ]);

        $filters = [
            'course_ids' => ['course_ids' => [$this->course->id]],
            'teacher_ids' => ['teacher_ids' => [$this->teacher->id]],
            'days_of_week' => ['days_of_week' => ['1']],
            'track_ids' => ['track_ids' => [$this->track->id]],
        ][$filter];

        expect($this->service->getSchedules()->pluck('id')->sort()->values()->all())->toBe([$this->schedule->id, $other->id])
            ->and($this->service->getSchedules($filters)->pluck('id')->all())->toBe([$this->schedule->id]);
    })->with(['course_ids', 'teacher_ids', 'days_of_week', 'track_ids']);

    it('filters by student_id with only the schedules the student actively attends', function () {
        $dropped = ($this->makeSchedule)(['day_of_week' => 2]);
        ($this->makeSchedule)(['day_of_week' => 3]);
        StudentEnrollment::factory()->active()->create(['student_id' => $this->student->id, 'schedule_id' => $this->schedule->id]);
        StudentEnrollment::factory()->dropped()->create(['student_id' => $this->student->id, 'schedule_id' => $dropped->id]);

        expect($this->service->getSchedules(['student_id' => $this->student->id])->pluck('id')->all())
            ->toBe([$this->schedule->id]);
    });

    it('returns the whole group in group_slots even when the day filter hides some days', function () {
        $this->schedule->update(['group_id' => 'group-1']);
        $wednesday = ($this->makeSchedule)(['day_of_week' => 3, 'group_id' => 'group-1']);

        $result = $this->service->getSchedules(['days_of_week' => ['1']]);

        expect($result)->toHaveCount(1)
            ->and(collect($result->first()['group_slots'])->pluck('id')->all())->toBe([$this->schedule->id, $wednesday->id]);
    });
});

describe('getEligibleStudents', function () {
    it('lists only the students of the schedule track', function () {
        $sameTrack = Student::factory()->create([
            'name' => 'Carla Student',
            'course_level_id' => CourseLevel::factory()->create(['track_id' => $this->track->id])->id,
        ]);
        Student::factory()->create(['name' => 'Other Track']);

        $ids = collect($this->service->getEligibleStudents($this->schedule->id))->pluck('id')->all();

        expect($ids)->toBe([$this->student->id, $sameTrack->id]);
    });

    it('filters by search', function () {
        Student::factory()->create(['name' => 'Carla Student', 'course_level_id' => $this->level->id]);

        $ids = collect($this->service->getEligibleStudents($this->schedule->id, 'bruno@'))->pluck('id')->all();

        expect($ids)->toBe([$this->student->id]);
    });

    it('reports a compatible student as available, with the course level', function () {
        $student = $this->service->getEligibleStudents($this->schedule->id)[0];

        expect($student)->toBe([
            'id' => $this->student->id,
            'name' => 'Bruno Student',
            'email' => 'bruno@example.com',
            'already_enrolled' => false,
            'is_available' => true,
            'blocked_reason' => null,
            'conflict' => null,
            'course_level' => ['name' => 'Beginner', 'track' => ['id' => $this->track->id, 'name' => 'Kids']],
        ]);
    });

    it('marks a student already enrolled in the schedule', function () {
        StudentEnrollment::factory()->active()->create(['student_id' => $this->student->id, 'schedule_id' => $this->schedule->id]);

        $student = $this->service->getEligibleStudents($this->schedule->id)[0];

        expect($student['already_enrolled'])->toBeTrue()
            ->and($student['is_available'])->toBeFalse()
            ->and($student['blocked_reason'])->toBe('enrolled')
            ->and($student['conflict'])->toBeNull();
    });

    it('marks a student without availability as unavailable', function () {
        $this->student->availabilities()->delete();

        $student = $this->service->getEligibleStudents($this->schedule->id)[0];

        expect($student['is_available'])->toBeFalse()
            ->and($student['blocked_reason'])->toBe('unavailable');
    });

    it('marks an overlap with the conflicting course, day and HH:MM times', function () {
        ($this->enrollInOther)(1, '10:00:00', '12:00:00');

        $student = $this->service->getEligibleStudents($this->schedule->id)[0];

        expect($student['blocked_reason'])->toBe('overlap')
            ->and($student['conflict'])->toBe([
                'course_name' => 'French 201',
                'day_of_week' => 1,
                'starts_at' => '10:00',
                'ends_at' => '12:00',
            ]);
    });

    it('fails for a schedule of another business', function () {
        $foreign = ($this->inOtherBusiness)(fn () => Schedule::factory()->create());

        $this->service->getEligibleStudents($foreign->id);
    })->throws(ModelNotFoundException::class);
});

describe('getCompatibleSchedules', function () {
    it('returns the student with availabilities sorted by day and translated', function () {
        StudentAvailability::factory()->create([
            'student_id' => $this->student->id,
            'day_of_week' => 0,
            'start_time' => '10:00:00',
            'end_time' => '12:00:00',
        ]);

        $student = $this->service->getCompatibleSchedules($this->student->id)['student'];

        expect($student['id'])->toBe($this->student->id)
            ->and($student['course_level'])->toBe(['name' => 'Beginner', 'track' => ['id' => $this->track->id, 'name' => 'Kids']])
            ->and(array_column($student['availabilities'], 'day_of_week'))->toBe([0, 1, 3])
            ->and($student['availabilities'][0])->toBe([
                'day_of_week' => 0,
                'day_name' => __('schedule.days.0'),
                'start_time' => '10:00',
                'end_time' => '12:00',
            ]);
    });

    it('groups the schedules of the student level, with the days label', function () {
        $this->schedule->update(['group_id' => 'group-1']);
        $wednesday = ($this->makeSchedule)(['day_of_week' => 3, 'group_id' => 'group-1']);
        // Another level: not offered
        ($this->makeSchedule)(['course_id' => Course::factory()->create()->id, 'day_of_week' => 2]);

        $groups = $this->service->getCompatibleSchedules($this->student->id)['schedule_groups'];

        expect($groups)->toHaveCount(1)
            ->and($groups[0])->toMatchArray([
                'group_id' => 'group-1',
                'schedule_ids' => [$this->schedule->id, $wednesday->id],
                'course_name' => 'English 101',
                'teacher_name' => 'Ana Teacher',
                'days_label' => __('schedule.days_short.1').' y '.__('schedule.days_short.3'),
                'starts_at' => '09:00',
                'ends_at' => '11:00',
                'enrolled_count' => 0,
                'capacity' => 5,
                'available_spots' => 5,
                'is_compatible' => true,
                'already_enrolled' => false,
                'blocked_reason' => null,
                'conflict' => null,
            ]);
    });

    it('marks a full group', function () {
        $this->schedule->update(['capacity' => 1]);
        StudentEnrollment::factory()->active()->create(['schedule_id' => $this->schedule->id]);

        $group = $this->service->getCompatibleSchedules($this->student->id)['schedule_groups'][0];

        expect($group['is_compatible'])->toBeFalse()
            ->and($group['blocked_reason'])->toBe('full')
            ->and($group['available_spots'])->toBe(0);
    });

    it('marks a group the student is enrolled in', function () {
        StudentEnrollment::factory()->active()->create(['student_id' => $this->student->id, 'schedule_id' => $this->schedule->id]);

        $group = $this->service->getCompatibleSchedules($this->student->id)['schedule_groups'][0];

        expect($group['already_enrolled'])->toBeTrue()
            ->and($group['is_compatible'])->toBeFalse()
            ->and($group['blocked_reason'])->toBe('enrolled');
    });

    it('fails for a student of another business', function () {
        $foreign = ($this->inOtherBusiness)(fn () => Student::factory()->create());

        $this->service->getCompatibleSchedules($foreign->id);
    })->throws(ModelNotFoundException::class);
});

describe('searchStudents', function () {
    it('searches by text and formats the student', function () {
        $this->student->update(['phone' => '1122334455']);
        Student::factory()->create(['name' => 'Carla Student']);

        $result = $this->service->searchStudents('Bruno');

        expect($result->all())->toBe([[
            'id' => $this->student->id,
            'name' => 'Bruno Student',
            'email' => 'bruno@example.com',
            'phone' => '1122334455',
            'course_level' => ['name' => 'Beginner', 'track' => ['id' => $this->track->id, 'name' => 'Kids']],
        ]]);
    });

    it('returns every student sorted by name without search', function () {
        Student::factory()->create(['name' => 'Aaron Student']);

        expect($this->service->searchStudents()->pluck('name')->all())->toBe(['Aaron Student', 'Bruno Student']);
    });

    it('returns at most 20 students', function () {
        Student::factory()->count(25)->create();

        expect($this->service->searchStudents())->toHaveCount(20);
    });
});

describe('getEnrollments', function () {
    it('combines filters and paginates', function () {
        $other = ($this->makeSchedule)(['day_of_week' => 2]);
        StudentEnrollment::factory()->active()->create(['student_id' => $this->student->id, 'schedule_id' => $this->schedule->id]);
        StudentEnrollment::factory()->dropped()->create(['student_id' => $this->student->id, 'schedule_id' => $other->id]);
        StudentEnrollment::factory()->active()->create(['schedule_id' => $this->schedule->id]);

        $page = $this->service->getEnrollments([
            'student_id' => $this->student->id,
            'schedule_id' => $this->schedule->id,
            'status' => 'active',
        ], 5);

        expect($page->total())->toBe(1)
            ->and($page->perPage())->toBe(5)
            ->and($page->items()[0]->relationLoaded('student'))->toBeTrue()
            ->and($page->items()[0]->schedule->relationLoaded('course'))->toBeTrue();
    });
});

describe('createEnrollments', function () {
    it('creates an active enrollment', function () {
        Carbon::setTestNow('2026-03-01 10:00:00');

        $result = $this->service->createEnrollments($this->student->id, [$this->schedule->id]);

        expect($result)->toHaveCount(1)
            ->and($result[0])->toBeInstanceOf(StudentEnrollment::class)
            ->and($result[0]->status)->toBe('active')
            ->and($result[0]->enrolled_at->toDateTimeString())->toBe('2026-03-01 10:00:00')
            ->and($result[0]->business_id)->toBe($this->business->id);

        Carbon::setTestNow();
    });

    it('accepts schedule ids as strings', function () {
        $this->service->createEnrollments($this->student->id, [(string) $this->schedule->id]);

        expect(StudentEnrollment::where('schedule_id', $this->schedule->id)->value('status'))->toBe('active');
    });

    it('rejects a student already actively enrolled', function () {
        StudentEnrollment::factory()->active()->create(['student_id' => $this->student->id, 'schedule_id' => $this->schedule->id]);

        $errors = ($this->validationErrors)(fn () => $this->service->createEnrollments($this->student->id, [$this->schedule->id]));

        expect($errors)->toBe(['student_id' => [__('enrollment.already_enrolled')]]);
    });

    it('rejects a schedule without spots', function () {
        $this->schedule->update(['capacity' => 1]);
        StudentEnrollment::factory()->active()->create(['schedule_id' => $this->schedule->id]);
        // Dropped enrollments don't take a spot
        StudentEnrollment::factory()->dropped()->create(['schedule_id' => $this->schedule->id]);

        $errors = ($this->validationErrors)(fn () => $this->service->createEnrollments($this->student->id, [$this->schedule->id]));

        expect($errors)->toBe(['schedule_id' => [__('enrollment.no_spots')]]);
    });

    it('rejects a schedule outside the availability, with the translated day', function () {
        $this->schedule->update(['day_of_week' => 2]);

        $errors = ($this->validationErrors)(fn () => $this->service->createEnrollments($this->student->id, [$this->schedule->id]));

        expect($errors)->toBe(['schedule_id' => [__('enrollment.unavailable', [
            'day' => __('schedule.days.2'),
            'starts_at' => '09:00',
            'ends_at' => '11:00',
        ])]]);
    });

    it('rejects an overlap naming the conflicting course', function () {
        ($this->enrollInOther)(1, '10:00:00', '12:00:00');

        $errors = ($this->validationErrors)(fn () => $this->service->createEnrollments($this->student->id, [$this->schedule->id]));

        expect($errors)->toBe(['schedule_id' => [__('enrollment.schedule_conflict', [
            'course' => 'French 201',
            'day' => __('schedule.days.1'),
            'starts_at' => '10:00',
            'ends_at' => '12:00',
        ])]]);
    });

    it('allows back-to-back schedules', function () {
        ($this->enrollInOther)(1, '11:00:00', '13:00:00');
        ($this->enrollInOther)(1, '07:00:00', '09:00:00');

        expect($this->service->createEnrollments($this->student->id, [$this->schedule->id]))->toHaveCount(1);
    });

    it('reactivates a dropped enrollment reusing the row', function () {
        $dropped = StudentEnrollment::factory()->dropped()->create([
            'student_id' => $this->student->id,
            'schedule_id' => $this->schedule->id,
            'enrolled_at' => '2025-01-01 00:00:00',
        ]);
        Carbon::setTestNow('2026-03-01 10:00:00');

        $result = $this->service->createEnrollments($this->student->id, [$this->schedule->id]);

        $fresh = $dropped->fresh();
        expect($result[0]->id)->toBe($dropped->id)
            ->and($fresh->status)->toBe('active')
            ->and($fresh->enrolled_at->toDateTimeString())->toBe('2026-03-01 10:00:00')
            ->and(StudentEnrollment::where('student_id', $this->student->id)->count())->toBe(1);

        Carbon::setTestNow();
    });

    it('enrolls every day of a group', function () {
        $this->schedule->update(['group_id' => 'group-1']);
        $wednesday = ($this->makeSchedule)(['day_of_week' => 3, 'group_id' => 'group-1']);

        $this->service->createEnrollments($this->student->id, [$this->schedule->id, $wednesday->id]);

        expect(StudentEnrollment::where('student_id', $this->student->id)->where('status', 'active')->pluck('schedule_id')->sort()->values()->all())
            ->toBe([$this->schedule->id, $wednesday->id]);
    });

    it('is atomic: a failing slot rolls back the previous ones', function () {
        $full = ($this->makeSchedule)(['day_of_week' => 3, 'capacity' => 1]);
        StudentEnrollment::factory()->active()->create(['schedule_id' => $full->id]);

        expect(fn () => $this->service->createEnrollments($this->student->id, [$this->schedule->id, $full->id]))
            ->toThrow(ValidationException::class);

        expect(StudentEnrollment::where('student_id', $this->student->id)->exists())->toBeFalse();
    });

    it('fails for a student of another business', function () {
        $foreign = ($this->inOtherBusiness)(fn () => Student::factory()->create());

        $this->service->createEnrollments($foreign->id, [$this->schedule->id]);
    })->throws(ModelNotFoundException::class);

    it('fails for a schedule of another business without enrolling', function () {
        $foreign = ($this->inOtherBusiness)(fn () => Schedule::factory()->create(['day_of_week' => 1, 'starts_at' => '09:00:00', 'ends_at' => '10:00:00']));

        expect(fn () => $this->service->createEnrollments($this->student->id, [$foreign->id]))
            ->toThrow(ModelNotFoundException::class);

        expect(StudentEnrollment::withoutGlobalScopes()->where('schedule_id', $foreign->id)->exists())->toBeFalse();
    });
});

describe('updateEnrollment', function () {
    beforeEach(function () {
        $this->schedule->update(['group_id' => 'group-1']);
        $this->wednesday = ($this->makeSchedule)(['day_of_week' => 3, 'group_id' => 'group-1']);

        // Both days of the group with the given status; returns the Monday enrollment
        $this->enrollInGroup = function (string $status) {
            StudentEnrollment::factory()->create(['student_id' => $this->student->id, 'schedule_id' => $this->wednesday->id, 'status' => $status, 'notes' => null]);

            return StudentEnrollment::factory()->create(['student_id' => $this->student->id, 'schedule_id' => $this->schedule->id, 'status' => $status, 'notes' => null]);
        };
        $this->groupStatuses = fn () => StudentEnrollment::where('student_id', $this->student->id)
            ->whereIn('schedule_id', [$this->schedule->id, $this->wednesday->id])
            ->orderBy('schedule_id')
            ->pluck('status')
            ->all();
    });

    it('applies completed or dropped to every day of the group', function (string $status) {
        $enrollment = ($this->enrollInGroup)('active');

        $result = $this->service->updateEnrollment($enrollment->id, ['status' => $status]);

        expect($result->status)->toBe($status)
            ->and(($this->groupStatuses)())->toBe([$status, $status])
            ->and($result->relationLoaded('student'))->toBeTrue()
            ->and($result->schedule->relationLoaded('teacher'))->toBeTrue();
    })->with(['completed', 'dropped']);

    it('does not revalidate when the status does not change', function () {
        $enrollment = ($this->enrollInGroup)('active');
        // The group is now full and outside the availability: no check runs
        $this->schedule->update(['capacity' => 1]);
        $this->student->availabilities()->delete();

        $result = $this->service->updateEnrollment($enrollment->id, ['status' => 'active']);

        expect($result->status)->toBe('active')
            ->and(($this->groupStatuses)())->toBe(['active', 'active']);
    });

    it('updates the notes of that row only', function () {
        $enrollment = ($this->enrollInGroup)('active');

        $this->service->updateEnrollment($enrollment->id, ['notes' => 'Only here']);

        expect($enrollment->fresh()->notes)->toBe('Only here')
            ->and(StudentEnrollment::where('schedule_id', $this->wednesday->id)->value('notes'))->toBeNull()
            ->and(($this->groupStatuses)())->toBe(['active', 'active']);
    });

    it('reactivates every day of the group, skipping the ones already active', function () {
        $enrollment = ($this->enrollInGroup)('dropped');
        StudentEnrollment::where('schedule_id', $this->wednesday->id)->update(['status' => 'active']);

        $this->service->updateEnrollment($enrollment->id, ['status' => 'active']);

        expect(($this->groupStatuses)())->toBe(['active', 'active'])
            ->and(StudentEnrollment::where('student_id', $this->student->id)->count())->toBe(2);
    });

    it('reactivates a schedule without group_id', function () {
        $single = ($this->makeSchedule)(['day_of_week' => 1, 'starts_at' => '14:00:00', 'ends_at' => '15:00:00']);
        $enrollment = StudentEnrollment::factory()->dropped()->create(['student_id' => $this->student->id, 'schedule_id' => $single->id]);

        $result = $this->service->updateEnrollment($enrollment->id, ['status' => 'active']);

        expect($result->status)->toBe('active');
    });

    it('rolls back the status when reactivating fails', function () {
        $enrollment = ($this->enrollInGroup)('dropped');
        ($this->enrollInOther)(3, '10:00:00', '12:00:00');

        expect(fn () => $this->service->updateEnrollment($enrollment->id, ['status' => 'active', 'notes' => 'x']))
            ->toThrow(ValidationException::class);

        expect(($this->groupStatuses)())->toBe(['dropped', 'dropped'])
            ->and($enrollment->fresh()->notes)->toBeNull();
    });

    it('fails for an enrollment of another business', function () {
        $foreign = ($this->inOtherBusiness)(fn () => StudentEnrollment::factory()->active()->create());

        $this->service->updateEnrollment($foreign->id, ['status' => 'dropped']);
    })->throws(ModelNotFoundException::class);
});

describe('dropEnrollment', function () {
    it('drops every day of the group and returns the count', function () {
        $this->schedule->update(['group_id' => 'group-1']);
        $wednesday = ($this->makeSchedule)(['day_of_week' => 3, 'group_id' => 'group-1']);
        $enrollment = StudentEnrollment::factory()->active()->create(['student_id' => $this->student->id, 'schedule_id' => $this->schedule->id]);
        StudentEnrollment::factory()->active()->create(['student_id' => $this->student->id, 'schedule_id' => $wednesday->id]);

        expect($this->service->dropEnrollment($enrollment->id))->toBe(2)
            ->and(StudentEnrollment::where('student_id', $this->student->id)->pluck('status')->unique()->all())->toBe(['dropped']);
    });

    it('drops only that schedule when it has no group_id', function () {
        $enrollment = StudentEnrollment::factory()->active()->create(['student_id' => $this->student->id, 'schedule_id' => $this->schedule->id]);
        $other = ($this->enrollInOther)(2, '09:00:00', '11:00:00');

        expect($this->service->dropEnrollment($enrollment->id))->toBe(1)
            ->and($enrollment->fresh()->status)->toBe('dropped')
            ->and(StudentEnrollment::where('schedule_id', $other->id)->value('status'))->toBe('active');
    });

    it('rejects an enrollment that is not active', function (string $status) {
        $enrollment = StudentEnrollment::factory()->create(['student_id' => $this->student->id, 'schedule_id' => $this->schedule->id, 'status' => $status]);

        $errors = ($this->validationErrors)(fn () => $this->service->dropEnrollment($enrollment->id));

        expect($errors)->toBe(['status' => [__('enrollment.not_active')]])
            ->and($enrollment->fresh()->status)->toBe($status);
    })->with(['dropped', 'completed', 'pending']);

    it('fails for an enrollment of another business', function () {
        $foreign = ($this->inOtherBusiness)(fn () => StudentEnrollment::factory()->active()->create());

        $this->service->dropEnrollment($foreign->id);
    })->throws(ModelNotFoundException::class);
});

describe('dropStudentFromSchedules', function () {
    beforeEach(function () {
        $this->tuesday = ($this->makeSchedule)(['day_of_week' => 2]);
        StudentEnrollment::factory()->active()->create(['student_id' => $this->student->id, 'schedule_id' => $this->schedule->id]);
        StudentEnrollment::factory()->completed()->create(['student_id' => $this->student->id, 'schedule_id' => $this->tuesday->id]);
        // Another student in the same schedule
        StudentEnrollment::factory()->active()->create(['schedule_id' => $this->schedule->id]);
    });

    it('drops only the active enrollments of the student', function (bool $asCollection) {
        $ids = [$this->schedule->id, $this->tuesday->id];

        $dropped = $this->service->dropStudentFromSchedules($this->student->id, $asCollection ? collect($ids) : $ids);

        expect($dropped)->toBe(1)
            ->and(StudentEnrollment::where('student_id', $this->student->id)->orderBy('schedule_id')->pluck('status')->all())->toBe(['dropped', 'completed'])
            ->and(StudentEnrollment::where('schedule_id', $this->schedule->id)->where('status', 'active')->count())->toBe(1);
    })->with(['array' => [false], 'collection' => [true]]);
});

describe('multi-tenancy', function () {
    beforeEach(function () {
        StudentEnrollment::factory()->active()->create(['student_id' => $this->student->id, 'schedule_id' => $this->schedule->id]);

        $this->foreign = ($this->inOtherBusiness)(function () {
            $student = Student::factory()->create(['name' => 'Bruno Foreign']);
            $schedule = Schedule::factory()->create();
            StudentEnrollment::factory()->active()->create(['student_id' => $student->id, 'schedule_id' => $schedule->id]);

            return (object) ['student' => $student, 'schedule' => $schedule];
        });
    });

    it('getSchedules does not see schedules of another business', function () {
        expect($this->service->getSchedules()->pluck('id')->all())->toBe([$this->schedule->id]);
    });

    it('searchStudents does not see students of another business', function () {
        expect($this->service->searchStudents('Bruno')->pluck('id')->all())->toBe([$this->student->id]);
    });

    it('getEnrollments does not see enrollments of another business', function () {
        $page = $this->service->getEnrollments([]);

        expect($page->total())->toBe(1)
            ->and($page->items()[0]->student_id)->toBe($this->student->id)
            ->and($this->service->getEnrollments(['student_id' => $this->foreign->student->id])->total())->toBe(0);
    });

    it('dropStudentFromSchedules does not touch enrollments of another business', function () {
        expect($this->service->dropStudentFromSchedules($this->foreign->student->id, [$this->foreign->schedule->id]))->toBe(0)
            ->and(StudentEnrollment::withoutGlobalScopes()->where('student_id', $this->foreign->student->id)->value('status'))->toBe('active');
    });
});
