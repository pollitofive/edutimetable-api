<?php

use App\Models\Course;
use App\Models\CourseLevel;
use App\Models\Schedule;
use App\Models\Student;
use App\Models\StudentAvailability;
use App\Models\Teacher;
use App\Models\Track;
use App\Services\CurrentBusiness;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Nuwave\Lighthouse\Testing\MakesGraphQLRequests;

uses(RefreshDatabase::class);
uses(MakesGraphQLRequests::class);

/*
 * Mutations must reject ids that belong to another business (card #53): the plain
 * exists:<table>,id rules let them through.
 */

beforeEach(function () {
    $tenancy = setupTenancy();
    $this->user = $tenancy->user;
    $this->business = $tenancy->business;
    $this->actingAs($this->user);

    // Own entities (business A)
    $this->course = Course::factory()->create();
    $this->teacher = Teacher::factory()->create();
    $this->student = Student::factory()->create();
    $this->level = CourseLevel::factory()->create();
    $this->track = Track::factory()->create();

    // Another business's entities (business B)
    $otherBusiness = \App\Models\Business::factory()->create();
    app(CurrentBusiness::class)->setId($otherBusiness->id);

    $this->otherCourse = Course::factory()->create();
    $this->otherTeacher = Teacher::factory()->create();
    $this->otherStudent = Student::factory()->create();
    $this->otherLevel = CourseLevel::factory()->create();
    $this->otherTrack = Track::factory()->create();

    app(CurrentBusiness::class)->setId($this->business->id);
});

function expectRejected($response): void
{
    expect($response->json('errors'))->not->toBeNull();
}

describe('schedules', function () {
    it('rejects a course of another business on createSchedule', function () {
        expectRejected($this->postGraphQL(['query' => "
            mutation {
                createSchedule(input: {
                    course_id: {$this->otherCourse->id}
                    teacher_id: {$this->teacher->id}
                    description: \"x\"
                    day_of_week: 1
                    starts_at: \"09:00\"
                    ends_at: \"10:00\"
                }) { id }
            }
        "]));

        expect(Schedule::count())->toBe(0);
    });

    it('rejects a teacher of another business on createSchedule', function () {
        expectRejected($this->postGraphQL(['query' => "
            mutation {
                createSchedule(input: {
                    course_id: {$this->course->id}
                    teacher_id: {$this->otherTeacher->id}
                    description: \"x\"
                    day_of_week: 1
                    starts_at: \"09:00\"
                    ends_at: \"10:00\"
                }) { id }
            }
        "]));

        expect(Schedule::count())->toBe(0);
    });

    it('rejects a course or teacher of another business on updateSchedule', function (string $field) {
        $schedule = Schedule::factory()->create([
            'course_id' => $this->course->id,
            'teacher_id' => $this->teacher->id,
        ]);
        $otherId = $field === 'course_id' ? $this->otherCourse->id : $this->otherTeacher->id;

        expectRejected($this->postGraphQL(['query' => "
            mutation {
                updateSchedule(id: {$schedule->id}, input: { {$field}: {$otherId} }) { id }
            }
        "]));

        expect($schedule->fresh()->course_id)->toBe($this->course->id)
            ->and($schedule->fresh()->teacher_id)->toBe($this->teacher->id);
    })->with(['course_id', 'teacher_id']);

    it('rejects a course of another business on bulkCreateSchedules', function () {
        expectRejected($this->postGraphQL(['query' => "
            mutation {
                bulkCreateSchedules(input: {
                    course_id: {$this->otherCourse->id}
                    description: \"x\"
                    schedules: [{ teacher_id: {$this->teacher->id}, day_of_week: 1, starts_at: \"09:00\", ends_at: \"10:00\" }]
                }) { id }
            }
        "]));

        expect(Schedule::count())->toBe(0);
    });

    it('rejects a slot teacher of another business on bulkCreateSchedules', function () {
        expectRejected($this->postGraphQL(['query' => "
            mutation {
                bulkCreateSchedules(input: {
                    course_id: {$this->course->id}
                    description: \"x\"
                    schedules: [
                        { teacher_id: {$this->teacher->id}, day_of_week: 1, starts_at: \"09:00\", ends_at: \"10:00\" }
                        { teacher_id: {$this->otherTeacher->id}, day_of_week: 3, starts_at: \"09:00\", ends_at: \"10:00\" }
                    ]
                }) { id }
            }
        "]));

        expect(Schedule::count())->toBe(0);
    });

    it('rejects a slot teacher of another business on bulkUpdateSchedules', function () {
        $schedule = Schedule::factory()->create([
            'course_id' => $this->course->id,
            'teacher_id' => $this->teacher->id,
            'day_of_week' => 1,
            'starts_at' => '09:00:00',
            'ends_at' => '10:00:00',
            'group_id' => 'g-1',
        ]);

        expectRejected($this->postGraphQL(['query' => "
            mutation {
                bulkUpdateSchedules(input: {
                    course_id: {$this->course->id}
                    description: \"x\"
                    group_id: \"g-1\"
                    schedules: [{ id: {$schedule->id}, teacher_id: {$this->otherTeacher->id}, day_of_week: 1, starts_at: \"09:00\", ends_at: \"10:00\" }]
                }) { id }
            }
        "]));

        expect($schedule->fresh()->teacher_id)->toBe($this->teacher->id);
    });
});

describe('courses and students', function () {
    it('rejects a course level of another business on createCourse', function () {
        expectRejected($this->postGraphQL(['query' => "
            mutation {
                createCourse(input: { name: \"Nuevo\", course_level_id: {$this->otherLevel->id} }) { id }
            }
        "]));

        expect(Course::where('name', 'Nuevo')->exists())->toBeFalse();
    });

    it('rejects a course level of another business on updateCourse', function () {
        expectRejected($this->postGraphQL(['query' => "
            mutation {
                updateCourse(id: {$this->course->id}, input: { course_level_id: {$this->otherLevel->id} }) { id }
            }
        "]));

        expect($this->course->fresh()->course_level_id)->toBe($this->course->course_level_id);
    });

    it('rejects a course level of another business on createStudent', function () {
        expectRejected($this->postGraphQL(['query' => "
            mutation {
                createStudent(input: { name: \"Nuevo\", email: \"nuevo@test.com\", course_level_id: {$this->otherLevel->id} }) { id }
            }
        "]));

        expect(Student::where('email', 'nuevo@test.com')->exists())->toBeFalse();
    });

    it('rejects a course level of another business on updateStudent', function () {
        expectRejected($this->postGraphQL(['query' => "
            mutation {
                updateStudent(id: {$this->student->id}, input: { course_level_id: {$this->otherLevel->id} }) { id }
            }
        "]));

        expect($this->student->fresh()->course_level_id)->toBe($this->student->course_level_id);
    });

    it('still accepts a course level of the current business', function () {
        $response = $this->postGraphQL(['query' => "
            mutation {
                createCourse(input: { name: \"Propio\", course_level_id: {$this->level->id} }) { id }
            }
        "]);

        expect($response->json('errors'))->toBeNull()
            ->and(Course::where('name', 'Propio')->exists())->toBeTrue();
    });
});

describe('course levels', function () {
    it('rejects a track of another business', function (string $mutation) {
        $call = $mutation === 'create'
            ? "createCourseLevel(input: { track_id: {$this->otherTrack->id}, name: \"N\", slug: \"n\", sort_order: 9 })"
            : "updateCourseLevel(id: {$this->level->id}, input: { track_id: {$this->otherTrack->id} })";

        expectRejected($this->postGraphQL(['query' => "mutation { {$call} { id } }"]));

        expect(CourseLevel::where('track_id', $this->otherTrack->id)->exists())->toBeFalse();
    })->with(['create', 'update']);

    it('rejects a previous level of another business', function (string $mutation) {
        $call = $mutation === 'create'
            ? "createCourseLevel(input: { track_id: {$this->track->id}, name: \"N\", slug: \"n\", sort_order: 9, previous_level_id: {$this->otherLevel->id} })"
            : "updateCourseLevel(id: {$this->level->id}, input: { previous_level_id: {$this->otherLevel->id} })";

        expectRejected($this->postGraphQL(['query' => "mutation { {$call} { id } }"]));

        expect(CourseLevel::where('previous_level_id', $this->otherLevel->id)->exists())->toBeFalse();
    })->with(['create', 'update']);
});

describe('student availabilities', function () {
    it('rejects a student of another business on createStudentAvailability', function () {
        expectRejected($this->postGraphQL(['query' => "
            mutation {
                createStudentAvailability(input: { student_id: {$this->otherStudent->id}, day_of_week: 1, start_time: \"09:00\", end_time: \"10:00\" }) { id }
            }
        "]));

        expect(StudentAvailability::withoutGlobalScopes()->count())->toBe(0);
    });

    it('rejects a student of another business on bulkCreateStudentAvailabilities', function () {
        expectRejected($this->postGraphQL(['query' => "
            mutation {
                bulkCreateStudentAvailabilities(input: {
                    student_id: {$this->otherStudent->id}
                    availabilities: [{ day_of_week: 1, start_time: \"09:00\", end_time: \"10:00\" }]
                }) { id }
            }
        "]));

        expect(StudentAvailability::withoutGlobalScopes()->count())->toBe(0);
    });
});
