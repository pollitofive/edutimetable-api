<?php

use App\Models\Course;
use App\Models\Schedule;
use App\Models\Student;
use App\Models\StudentEnrollment;
use App\Models\Teacher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Nuwave\Lighthouse\Testing\MakesGraphQLRequests;

uses(RefreshDatabase::class);
uses(MakesGraphQLRequests::class);

beforeEach(function () {
    // Setup multi-tenancy
    $tenancy = setupTenancy();
    $this->user = $tenancy->user;
    $this->business = $tenancy->business;
    $this->actingAs($this->user);

    // Create a teacher and a course
    $this->teacher = Teacher::factory()->create();
    $this->course = Course::factory()->create();
});

/**
 * Two-slot group (Mon & Wed 10-12) for the given course and teacher
 *
 * @return array<Schedule>
 */
function makeGroup(Course $course, Teacher $teacher, string $groupId): array
{
    return collect([1, 3])->map(fn ($day) => Schedule::factory()->create([
        'course_id' => $course->id,
        'teacher_id' => $teacher->id,
        'day_of_week' => $day,
        'starts_at' => '10:00:00',
        'ends_at' => '12:00:00',
        'group_id' => $groupId,
        'capacity' => 5,
    ]))->all();
}

/**
 * Enroll a new student in every slot of a group, as EnrollmentService::createEnrollments() does
 *
 * @param  array<Schedule>  $schedules
 * @return array<StudentEnrollment>
 */
function enrollInGroup(array $schedules, string $status = 'active'): array
{
    $student = Student::factory()->create();

    return collect($schedules)->map(fn ($schedule) => StudentEnrollment::factory()->create([
        'student_id' => $student->id,
        'schedule_id' => $schedule->id,
        'status' => $status,
    ]))->all();
}

describe('Bulk Create Schedules', function () {
    test('can create multiple schedules successfully', function () {
        $response = $this->graphQL('
            mutation {
                bulkCreateSchedules(input: {
                    description: "Test Schedule"
                    course_id: '.$this->course->id.'
                    schedules: [
                        { teacher_id: '.$this->teacher->id.', day_of_week: 1, starts_at: "09:00", ends_at: "11:00" }
                        { teacher_id: '.$this->teacher->id.', day_of_week: 3, starts_at: "09:00", ends_at: "11:00" }
                        { teacher_id: '.$this->teacher->id.', day_of_week: 5, starts_at: "14:00", ends_at: "16:00" }
                    ]
                }) {
                    id
                    course_id
                    day_of_week
                    starts_at
                    ends_at
                }
            }
        ');

        $response->assertJson([
            'data' => [
                'bulkCreateSchedules' => [
                    [
                        'course_id' => (string) $this->course->id,
                        'day_of_week' => 1,
                        'starts_at' => '09:00:00',
                        'ends_at' => '11:00:00',
                    ],
                    [
                        'course_id' => (string) $this->course->id,
                        'day_of_week' => 3,
                        'starts_at' => '09:00:00',
                        'ends_at' => '11:00:00',
                    ],
                    [
                        'course_id' => (string) $this->course->id,
                        'day_of_week' => 5,
                        'starts_at' => '14:00:00',
                        'ends_at' => '16:00:00',
                    ],
                ],
            ],
        ]);

        // Verify schedules were created in database
        expect(Schedule::where('course_id', $this->course->id)->count())->toBe(3);
    });

    test('stores the given capacity on every slot', function () {
        $response = $this->graphQL('
            mutation {
                bulkCreateSchedules(input: {
                    description: "Test Schedule"
                    course_id: '.$this->course->id.'
                    capacity: 12
                    schedules: [
                        { teacher_id: '.$this->teacher->id.', day_of_week: 2, starts_at: "18:45", ends_at: "20:15" }
                        { teacher_id: '.$this->teacher->id.', day_of_week: 4, starts_at: "18:45", ends_at: "20:15" }
                    ]
                }) {
                    capacity
                }
            }
        ');

        $response->assertJsonMissingPath('errors');
        expect($response->json('data.bulkCreateSchedules.*.capacity'))->toBe([12, 12]);
    });

    test('fails with empty schedules array', function () {
        $response = $this->graphQL('
            mutation {
                bulkCreateSchedules(input: {
                    description: "Test Schedule"
                    course_id: '.$this->course->id.'
                    schedules: []
                }) {
                    id
                }
            }
        ');

        $response->assertGraphQLValidationError('schedules', 'At least one schedule slot is required.');
    });

    test('fails with invalid course_id', function () {
        $response = $this->graphQL('
            mutation {
                bulkCreateSchedules(input: {
                    description: "Test Schedule"
                    course_id: 99999
                    schedules: [
                        { teacher_id: '.$this->teacher->id.', day_of_week: 1, starts_at: "09:00", ends_at: "11:00" }
                    ]
                }) {
                    id
                }
            }
        ');

        $response->assertGraphQLValidationKeys(['input.course_id']);
    });

    test('fails with invalid day_of_week (too high)', function () {
        $response = $this->graphQL('
            mutation {
                bulkCreateSchedules(input: {
                    description: "Test Schedule"
                    course_id: '.$this->course->id.'
                    schedules: [
                        { teacher_id: '.$this->teacher->id.', day_of_week: 7, starts_at: "09:00", ends_at: "11:00" }
                    ]
                }) {
                    id
                }
            }
        ');

        $response->assertGraphQLValidationKeys(['input.schedules.0.day_of_week']);
    });

    test('fails with invalid day_of_week (negative)', function () {
        $response = $this->graphQL('
            mutation {
                bulkCreateSchedules(input: {
                    description: "Test Schedule"
                    course_id: '.$this->course->id.'
                    schedules: [
                        { teacher_id: '.$this->teacher->id.', day_of_week: -1, starts_at: "09:00", ends_at: "11:00" }
                    ]
                }) {
                    id
                }
            }
        ');

        $response->assertGraphQLValidationKeys(['input.schedules.0.day_of_week']);
    });

    test('fails with invalid time format (missing leading zero)', function () {
        $response = $this->graphQL('
            mutation {
                bulkCreateSchedules(input: {
                    description: "Test Schedule"
                    course_id: '.$this->course->id.'
                    schedules: [
                        { teacher_id: '.$this->teacher->id.', day_of_week: 1, starts_at: "9:00", ends_at: "11:00" }
                    ]
                }) {
                    id
                }
            }
        ');

        $response->assertGraphQLValidationKeys(['input.schedules.0.starts_at']);
    });

    test('fails with invalid time format (wrong format)', function () {
        $response = $this->graphQL('
            mutation {
                bulkCreateSchedules(input: {
                    description: "Test Schedule"
                    course_id: '.$this->course->id.'
                    schedules: [
                        { teacher_id: '.$this->teacher->id.', day_of_week: 1, starts_at: "09:00 AM", ends_at: "11:00" }
                    ]
                }) {
                    id
                }
            }
        ');

        $response->assertGraphQLValidationKeys(['input.schedules.0.starts_at']);
    });

    test('fails when end time is before start time', function () {
        $response = $this->graphQL('
            mutation {
                bulkCreateSchedules(input: {
                    description: "Test Schedule"
                    course_id: '.$this->course->id.'
                    schedules: [
                        { teacher_id: '.$this->teacher->id.', day_of_week: 1, starts_at: "18:00", ends_at: "09:00" }
                    ]
                }) {
                    id
                }
            }
        ');

        $response->assertGraphQLValidationKeys(['schedules']);
        // The error message should mention end time must be after start time
        $errors = $response->json('errors.0.extensions.validation.schedules');
        expect($errors[0])->toContain('End time must be after start time');
    });

    test('fails when end time equals start time', function () {
        $response = $this->graphQL('
            mutation {
                bulkCreateSchedules(input: {
                    description: "Test Schedule"
                    course_id: '.$this->course->id.'
                    schedules: [
                        { teacher_id: '.$this->teacher->id.', day_of_week: 1, starts_at: "09:00", ends_at: "09:00" }
                    ]
                }) {
                    id
                }
            }
        ');

        $response->assertGraphQLValidationKeys(['schedules']);
    });

    test('fails with overlapping slots in input array (same time)', function () {
        $response = $this->graphQL('
            mutation {
                bulkCreateSchedules(input: {
                    description: "Test Schedule"
                    course_id: '.$this->course->id.'
                    schedules: [
                        { teacher_id: '.$this->teacher->id.', day_of_week: 1, starts_at: "09:00", ends_at: "11:00" }
                        { teacher_id: '.$this->teacher->id.', day_of_week: 1, starts_at: "09:00", ends_at: "11:00" }
                    ]
                }) {
                    id
                }
            }
        ');

        $response->assertGraphQLValidationKeys(['schedules']);
    });

    test('fails with overlapping slots in input array (partial overlap)', function () {
        $response = $this->graphQL('
            mutation {
                bulkCreateSchedules(input: {
                    description: "Test Schedule"
                    course_id: '.$this->course->id.'
                    schedules: [
                        { teacher_id: '.$this->teacher->id.', day_of_week: 1, starts_at: "09:00", ends_at: "11:00" }
                        { teacher_id: '.$this->teacher->id.', day_of_week: 1, starts_at: "10:00", ends_at: "12:00" }
                    ]
                }) {
                    id
                }
            }
        ');

        $response->assertGraphQLValidationKeys(['schedules']);
    });

    test('allows same times on different days', function () {
        $response = $this->graphQL('
            mutation {
                bulkCreateSchedules(input: {
                    description: "Test Schedule"
                    course_id: '.$this->course->id.'
                    schedules: [
                        { teacher_id: '.$this->teacher->id.', day_of_week: 1, starts_at: "09:00", ends_at: "11:00" }
                        { teacher_id: '.$this->teacher->id.', day_of_week: 2, starts_at: "09:00", ends_at: "11:00" }
                        { teacher_id: '.$this->teacher->id.', day_of_week: 3, starts_at: "09:00", ends_at: "11:00" }
                    ]
                }) {
                    id
                    day_of_week
                }
            }
        ');

        $response->assertJsonCount(3, 'data.bulkCreateSchedules');
        expect(Schedule::where('course_id', $this->course->id)->count())->toBe(3);
    });

    test('fails with overlap against existing database schedule', function () {
        // Create an existing schedule
        Schedule::factory()->create([
            'course_id' => $this->course->id,
            'teacher_id' => $this->teacher->id,
            'day_of_week' => 1,
            'starts_at' => '09:00:00',
            'ends_at' => '11:00:00',
        ]);

        $response = $this->graphQL('
            mutation {
                bulkCreateSchedules(input: {
                    description: "Test Schedule"
                    course_id: '.$this->course->id.'
                    schedules: [
                        { teacher_id: '.$this->teacher->id.', day_of_week: 1, starts_at: "10:00", ends_at: "12:00" }
                    ]
                }) {
                    id
                }
            }
        ');

        $response->assertGraphQLValidationKeys(['schedules']);
    });

    test('allows adjacent time slots without overlap', function () {
        $response = $this->graphQL('
            mutation {
                bulkCreateSchedules(input: {
                    description: "Test Schedule"
                    course_id: '.$this->course->id.'
                    schedules: [
                        { teacher_id: '.$this->teacher->id.', day_of_week: 1, starts_at: "09:00", ends_at: "11:00" }
                        { teacher_id: '.$this->teacher->id.', day_of_week: 1, starts_at: "11:00", ends_at: "13:00" }
                        { teacher_id: '.$this->teacher->id.', day_of_week: 1, starts_at: "13:00", ends_at: "15:00" }
                    ]
                }) {
                    id
                    starts_at
                    ends_at
                }
            }
        ');

        $response->assertJsonCount(3, 'data.bulkCreateSchedules');
        expect(Schedule::where('course_id', $this->course->id)->count())->toBe(3);
    });

    test('transaction rolls back on validation failure', function () {
        $response = $this->graphQL('
            mutation {
                bulkCreateSchedules(input: {
                    description: "Test Schedule"
                    course_id: '.$this->course->id.'
                    schedules: [
                        { teacher_id: '.$this->teacher->id.', day_of_week: 1, starts_at: "09:00", ends_at: "11:00" }
                        { teacher_id: '.$this->teacher->id.', day_of_week: 1, starts_at: "10:00", ends_at: "12:00" }
                    ]
                }) {
                    id
                }
            }
        ');

        // Should have validation error
        $response->assertGraphQLValidationKeys(['schedules']);

        // Verify no schedules were created (transaction rolled back)
        expect(Schedule::where('course_id', $this->course->id)->count())->toBe(0);
    });
});

describe('Bulk Update Schedules', function () {
    test('can replace all schedules for a course', function () {
        // Create some existing schedules
        Schedule::factory()->count(3)->create([
            'course_id' => $this->course->id,
            'teacher_id' => $this->teacher->id,
        ]);

        expect(Schedule::where('course_id', $this->course->id)->count())->toBe(3);

        $response = $this->graphQL('
            mutation {
                bulkUpdateSchedules(input: {
                    description: "Test Schedule"
                    course_id: '.$this->course->id.'
                    schedules: [
                        { teacher_id: '.$this->teacher->id.', day_of_week: 2, starts_at: "10:00", ends_at: "12:00" }
                        { teacher_id: '.$this->teacher->id.', day_of_week: 4, starts_at: "13:00", ends_at: "15:00" }
                    ]
                }) {
                    id
                    course_id
                    day_of_week
                    starts_at
                    ends_at
                }
            }
        ');

        $response->assertJson([
            'data' => [
                'bulkUpdateSchedules' => [
                    [
                        'course_id' => (string) $this->course->id,
                        'day_of_week' => 2,
                        'starts_at' => '10:00:00',
                        'ends_at' => '12:00:00',
                    ],
                    [
                        'course_id' => (string) $this->course->id,
                        'day_of_week' => 4,
                        'starts_at' => '13:00:00',
                        'ends_at' => '15:00:00',
                    ],
                ],
            ],
        ]);

        // Verify only 2 schedules exist now (old ones were deleted)
        expect(Schedule::where('course_id', $this->course->id)->count())->toBe(2);
    });

    test('can update to a single schedule', function () {
        // Create some existing schedules
        Schedule::factory()->count(5)->create([
            'course_id' => $this->course->id,
            'teacher_id' => $this->teacher->id,
        ]);

        $response = $this->graphQL('
            mutation {
                bulkUpdateSchedules(input: {
                    description: "Test Schedule"
                    course_id: '.$this->course->id.'
                    schedules: [
                        { teacher_id: '.$this->teacher->id.', day_of_week: 3, starts_at: "09:00", ends_at: "17:00" }
                    ]
                }) {
                    id
                    day_of_week
                }
            }
        ');

        $response->assertJsonCount(1, 'data.bulkUpdateSchedules');
        expect(Schedule::where('course_id', $this->course->id)->count())->toBe(1);
    });

    test('fails with empty schedules array', function () {
        $response = $this->graphQL('
            mutation {
                bulkUpdateSchedules(input: {
                    description: "Test Schedule"
                    course_id: '.$this->course->id.'
                    schedules: []
                }) {
                    id
                }
            }
        ');

        $response->assertGraphQLValidationError('schedules', 'At least one schedule slot is required.');
    });

    test('fails with invalid course_id', function () {
        $response = $this->graphQL('
            mutation {
                bulkUpdateSchedules(input: {
                    description: "Test Schedule"
                    course_id: 99999
                    schedules: [
                        { teacher_id: '.$this->teacher->id.', day_of_week: 1, starts_at: "09:00", ends_at: "11:00" }
                    ]
                }) {
                    id
                }
            }
        ');

        $response->assertGraphQLValidationKeys(['input.course_id']);
    });

    test('fails with overlapping slots in input array', function () {
        $response = $this->graphQL('
            mutation {
                bulkUpdateSchedules(input: {
                    description: "Test Schedule"
                    course_id: '.$this->course->id.'
                    schedules: [
                        { teacher_id: '.$this->teacher->id.', day_of_week: 1, starts_at: "09:00", ends_at: "11:00" }
                        { teacher_id: '.$this->teacher->id.', day_of_week: 1, starts_at: "10:30", ends_at: "12:00" }
                    ]
                }) {
                    id
                }
            }
        ');

        $response->assertGraphQLValidationKeys(['schedules']);
    });

    test('fails when end time is before start time', function () {
        $response = $this->graphQL('
            mutation {
                bulkUpdateSchedules(input: {
                    description: "Test Schedule"
                    course_id: '.$this->course->id.'
                    schedules: [
                        { teacher_id: '.$this->teacher->id.', day_of_week: 1, starts_at: "15:00", ends_at: "10:00" }
                    ]
                }) {
                    id
                }
            }
        ');

        $response->assertGraphQLValidationKeys(['schedules']);
    });

    test('does not check overlaps with existing database schedules (since replacing all)', function () {
        // Create an existing schedule that would "overlap" with new one
        Schedule::factory()->create([
            'course_id' => $this->course->id,
            'teacher_id' => $this->teacher->id,
            'day_of_week' => 1,
            'starts_at' => '09:00:00',
            'ends_at' => '11:00:00',
        ]);

        // This should succeed because bulk update deletes all existing schedules first
        $response = $this->graphQL('
            mutation {
                bulkUpdateSchedules(input: {
                    description: "Test Schedule"
                    course_id: '.$this->course->id.'
                    schedules: [
                        { teacher_id: '.$this->teacher->id.', day_of_week: 1, starts_at: "09:00", ends_at: "11:00" }
                    ]
                }) {
                    id
                    day_of_week
                    starts_at
                    ends_at
                }
            }
        ');

        $response->assertJson([
            'data' => [
                'bulkUpdateSchedules' => [
                    [
                        'day_of_week' => 1,
                        'starts_at' => '09:00:00',
                        'ends_at' => '11:00:00',
                    ],
                ],
            ],
        ]);
    });

    test('transaction rolls back on validation failure', function () {
        // Create existing schedules
        Schedule::factory()->count(3)->create([
            'course_id' => $this->course->id,
            'teacher_id' => $this->teacher->id,
        ]);

        $originalCount = Schedule::where('course_id', $this->course->id)->count();

        $response = $this->graphQL('
            mutation {
                bulkUpdateSchedules(input: {
                    description: "Test Schedule"
                    course_id: '.$this->course->id.'
                    schedules: [
                        { teacher_id: '.$this->teacher->id.', day_of_week: 1, starts_at: "09:00", ends_at: "11:00" }
                        { teacher_id: '.$this->teacher->id.', day_of_week: 1, starts_at: "10:00", ends_at: "12:00" }
                    ]
                }) {
                    id
                }
            }
        ');

        // Should have validation error
        $response->assertGraphQLValidationKeys(['schedules']);

        // Verify original schedules still exist (transaction rolled back)
        expect(Schedule::where('course_id', $this->course->id)->count())->toBe($originalCount);
    });

    test('does not affect schedules of other courses', function () {
        // Create separate teachers to avoid overlap validation issues
        $teacher1 = Teacher::factory()->create();
        $teacher2 = Teacher::factory()->create();

        // Create another course with schedules
        $otherCourse = Course::factory()->create();
        Schedule::factory()->count(2)->create([
            'course_id' => $otherCourse->id,
            'teacher_id' => $teacher2->id,
        ]);

        // Create schedules for our test course with specific times to avoid overlaps
        Schedule::create([
            'course_id' => $this->course->id,
            'teacher_id' => $teacher1->id,
            'day_of_week' => 2,
            'starts_at' => '10:00:00',
            'ends_at' => '12:00:00',
            'description' => 'Old schedule 1',
        ]);
        Schedule::create([
            'course_id' => $this->course->id,
            'teacher_id' => $teacher1->id,
            'day_of_week' => 3,
            'starts_at' => '14:00:00',
            'ends_at' => '16:00:00',
            'description' => 'Old schedule 2',
        ]);
        Schedule::create([
            'course_id' => $this->course->id,
            'teacher_id' => $teacher1->id,
            'day_of_week' => 4,
            'starts_at' => '08:00:00',
            'ends_at' => '10:00:00',
            'description' => 'Old schedule 3',
        ]);

        // Update only our test course schedules with a new time that doesn't overlap
        $response = $this->graphQL('
            mutation {
                bulkUpdateSchedules(input: {
                    description: "Test Schedule"
                    course_id: '.$this->course->id.'
                    schedules: [
                        { teacher_id: '.$teacher1->id.', day_of_week: 1, starts_at: "09:00", ends_at: "11:00" }
                    ]
                }) {
                    id
                }
            }
        ');

        // Check for errors
        $response->assertJsonMissingPath('errors');

        // Our course should have 1 schedule
        expect(Schedule::where('course_id', $this->course->id)->count())->toBe(1);

        // Other course should still have 2 schedules (unchanged)
        expect(Schedule::where('course_id', $otherCourse->id)->count())->toBe(2);
    });

    test('updates slots with an id in place, creates new ones and deletes missing ones', function () {
        $groupId = (string) \Illuminate\Support\Str::uuid();
        [$kept, $removed] = makeGroup($this->course, $this->teacher, $groupId);

        $response = $this->graphQL('
            mutation {
                bulkUpdateSchedules(input: {
                    description: "Moved"
                    course_id: '.$this->course->id.'
                    group_id: "'.$groupId.'"
                    capacity: 8
                    schedules: [
                        { id: '.$kept->id.', teacher_id: '.$this->teacher->id.', day_of_week: 2, starts_at: "14:00", ends_at: "16:00" }
                        { teacher_id: '.$this->teacher->id.', day_of_week: 5, starts_at: "10:00", ends_at: "12:00" }
                    ]
                }) {
                    id
                    capacity
                }
            }
        ');

        $response->assertJsonMissingPath('errors');
        expect($response->json('data.bulkUpdateSchedules.0.id'))->toBe((string) $kept->id);
        expect($response->json('data.bulkUpdateSchedules.*.capacity'))->toBe([8, 8]);

        expect(Schedule::find($removed->id))->toBeNull();
        $kept->refresh();
        expect($kept->day_of_week)->toBe(2);
        expect($kept->starts_at)->toBe('14:00:00');
        expect(Schedule::where('group_id', $groupId)->count())->toBe(2);
    });

    test('keeps enrollments when editing teacher, description and capacity of a group with students', function () {
        $groupId = (string) \Illuminate\Support\Str::uuid();
        [$schedule1, $schedule2] = makeGroup($this->course, $this->teacher, $groupId);
        $enrollments = enrollInGroup([$schedule1, $schedule2]);
        $otherTeacher = Teacher::factory()->create();

        $response = $this->graphQL('
            mutation {
                bulkUpdateSchedules(input: {
                    description: "Renamed"
                    course_id: '.$this->course->id.'
                    group_id: "'.$groupId.'"
                    capacity: 10
                    schedules: [
                        { id: '.$schedule1->id.', teacher_id: '.$otherTeacher->id.', day_of_week: 1, starts_at: "10:00", ends_at: "12:00" }
                        { id: '.$schedule2->id.', teacher_id: '.$this->teacher->id.', day_of_week: 3, starts_at: "10:00", ends_at: "12:00" }
                    ]
                }) {
                    id
                }
            }
        ');

        $response->assertJsonMissingPath('errors');

        expect($schedule1->fresh()->teacher_id)->toBe($otherTeacher->id);
        expect($schedule1->fresh()->description)->toBe('Renamed');
        expect($schedule2->fresh()->capacity)->toBe(10);

        // Same rows, same schedules: nothing was deleted and recreated
        foreach ($enrollments as $enrollment) {
            expect($enrollment->fresh())->not->toBeNull();
            expect($enrollment->fresh()->status)->toBe('active');
        }
    });

    test('rejects changing day or time of a group with active students', function () {
        $groupId = (string) \Illuminate\Support\Str::uuid();
        [$schedule1, $schedule2] = makeGroup($this->course, $this->teacher, $groupId);
        enrollInGroup([$schedule1, $schedule2]);

        $response = $this->graphQL('
            mutation {
                bulkUpdateSchedules(input: {
                    description: "Test Schedule"
                    course_id: '.$this->course->id.'
                    group_id: "'.$groupId.'"
                    schedules: [
                        { id: '.$schedule1->id.', teacher_id: '.$this->teacher->id.', day_of_week: 1, starts_at: "14:00", ends_at: "16:00" }
                        { id: '.$schedule2->id.', teacher_id: '.$this->teacher->id.', day_of_week: 3, starts_at: "10:00", ends_at: "12:00" }
                    ]
                }) {
                    id
                }
            }
        ');

        $response->assertGraphQLValidationKeys(['schedules']);
        expect($schedule1->fresh()->starts_at)->toBe('10:00:00');
        expect(StudentEnrollment::active()->count())->toBe(2);
    });

    test('rejects adding a slot to a group with active students', function () {
        $groupId = (string) \Illuminate\Support\Str::uuid();
        [$schedule1, $schedule2] = makeGroup($this->course, $this->teacher, $groupId);
        enrollInGroup([$schedule1, $schedule2]);

        $response = $this->graphQL('
            mutation {
                bulkUpdateSchedules(input: {
                    description: "Test Schedule"
                    course_id: '.$this->course->id.'
                    group_id: "'.$groupId.'"
                    schedules: [
                        { id: '.$schedule1->id.', teacher_id: '.$this->teacher->id.', day_of_week: 1, starts_at: "10:00", ends_at: "12:00" }
                        { id: '.$schedule2->id.', teacher_id: '.$this->teacher->id.', day_of_week: 3, starts_at: "10:00", ends_at: "12:00" }
                        { teacher_id: '.$this->teacher->id.', day_of_week: 5, starts_at: "10:00", ends_at: "12:00" }
                    ]
                }) {
                    id
                }
            }
        ');

        $response->assertGraphQLValidationKeys(['schedules']);
        expect(Schedule::where('group_id', $groupId)->count())->toBe(2);
    });

    test('rejects removing a slot from a group with active students', function () {
        $groupId = (string) \Illuminate\Support\Str::uuid();
        [$schedule1, $schedule2] = makeGroup($this->course, $this->teacher, $groupId);
        enrollInGroup([$schedule1, $schedule2]);

        $response = $this->graphQL('
            mutation {
                bulkUpdateSchedules(input: {
                    description: "Test Schedule"
                    course_id: '.$this->course->id.'
                    group_id: "'.$groupId.'"
                    schedules: [
                        { id: '.$schedule1->id.', teacher_id: '.$this->teacher->id.', day_of_week: 1, starts_at: "10:00", ends_at: "12:00" }
                    ]
                }) {
                    id
                }
            }
        ');

        $response->assertGraphQLValidationKeys(['schedules']);
        expect(Schedule::find($schedule2->id))->not->toBeNull();
        expect(StudentEnrollment::active()->count())->toBe(2);
    });

    test('rejects a capacity below the active students of the group', function () {
        $groupId = (string) \Illuminate\Support\Str::uuid();
        [$schedule1, $schedule2] = makeGroup($this->course, $this->teacher, $groupId);
        enrollInGroup([$schedule1, $schedule2]);
        enrollInGroup([$schedule1, $schedule2]);

        $response = $this->graphQL('
            mutation {
                bulkUpdateSchedules(input: {
                    description: "Test Schedule"
                    course_id: '.$this->course->id.'
                    group_id: "'.$groupId.'"
                    capacity: 1
                    schedules: [
                        { id: '.$schedule1->id.', teacher_id: '.$this->teacher->id.', day_of_week: 1, starts_at: "10:00", ends_at: "12:00" }
                        { id: '.$schedule2->id.', teacher_id: '.$this->teacher->id.', day_of_week: 3, starts_at: "10:00", ends_at: "12:00" }
                    ]
                }) {
                    id
                }
            }
        ');

        $response->assertGraphQLValidationKeys(['capacity']);
    });

    test('allows changing day and time when the group only has dropped students, keeping their history', function () {
        $groupId = (string) \Illuminate\Support\Str::uuid();
        [$schedule1, $schedule2] = makeGroup($this->course, $this->teacher, $groupId);
        $enrollments = enrollInGroup([$schedule1, $schedule2], 'dropped');

        $response = $this->graphQL('
            mutation {
                bulkUpdateSchedules(input: {
                    description: "Test Schedule"
                    course_id: '.$this->course->id.'
                    group_id: "'.$groupId.'"
                    schedules: [
                        { id: '.$schedule1->id.', teacher_id: '.$this->teacher->id.', day_of_week: 2, starts_at: "14:00", ends_at: "16:00" }
                        { id: '.$schedule2->id.', teacher_id: '.$this->teacher->id.', day_of_week: 4, starts_at: "14:00", ends_at: "16:00" }
                    ]
                }) {
                    id
                }
            }
        ');

        $response->assertJsonMissingPath('errors');
        expect($schedule1->fresh()->day_of_week)->toBe(2);
        foreach ($enrollments as $enrollment) {
            expect($enrollment->fresh())->not->toBeNull();
        }
    });

    test('rejects a slot id from another group', function () {
        $groupId = (string) \Illuminate\Support\Str::uuid();
        makeGroup($this->course, $this->teacher, $groupId);
        $foreign = Schedule::factory()->create(['teacher_id' => Teacher::factory()->create()->id]);

        $response = $this->graphQL('
            mutation {
                bulkUpdateSchedules(input: {
                    description: "Test Schedule"
                    course_id: '.$this->course->id.'
                    group_id: "'.$groupId.'"
                    schedules: [
                        { id: '.$foreign->id.', teacher_id: '.$this->teacher->id.', day_of_week: 1, starts_at: "10:00", ends_at: "12:00" }
                    ]
                }) {
                    id
                }
            }
        ');

        $response->assertGraphQLValidationKeys(['schedules']);
        expect($foreign->fresh()->course_id)->not->toBe($this->course->id);
    });

    test('exposes the active enrollments count of each schedule', function () {
        $groupId = (string) \Illuminate\Support\Str::uuid();
        [$schedule1, $schedule2] = makeGroup($this->course, $this->teacher, $groupId);
        enrollInGroup([$schedule1, $schedule2]);
        enrollInGroup([$schedule1, $schedule2], 'dropped');

        $response = $this->graphQL('{ schedules(group_id: "'.$groupId.'") { data { id active_enrollments_count } } }');

        expect($response->json('data.schedules.data.*.active_enrollments_count'))->toBe([1, 1]);
    });

    test('can set description on bulk create', function () {
        $response = $this->graphQL('
            mutation {
                bulkCreateSchedules(input: {
                    course_id: '.$this->course->id.'
                    description: "Advanced Programming Course"
                    schedules: [
                        { teacher_id: '.$this->teacher->id.', day_of_week: 1, starts_at: "09:00", ends_at: "11:00" }
                        { teacher_id: '.$this->teacher->id.', day_of_week: 3, starts_at: "14:00", ends_at: "16:00" }
                    ]
                }) {
                    id
                    description
                }
            }
        ');

        $response->assertJson([
            'data' => [
                'bulkCreateSchedules' => [
                    ['description' => 'Advanced Programming Course'],
                    ['description' => 'Advanced Programming Course'],
                ],
            ],
        ]);

        // Verify all schedules have the description
        $schedules = Schedule::where('course_id', $this->course->id)->get();
        foreach ($schedules as $schedule) {
            expect($schedule->description)->toBe('Advanced Programming Course');
        }
    });

    test('can update description on bulk update', function () {
        // Create existing schedules
        Schedule::factory()->count(2)->create([
            'course_id' => $this->course->id,
            'teacher_id' => $this->teacher->id,
            'description' => 'Old description',
        ]);

        $response = $this->graphQL('
            mutation {
                bulkUpdateSchedules(input: {
                    course_id: '.$this->course->id.'
                    description: "Updated description"
                    schedules: [
                        { teacher_id: '.$this->teacher->id.', day_of_week: 2, starts_at: "10:00", ends_at: "12:00" }
                    ]
                }) {
                    id
                    description
                }
            }
        ');

        $response->assertJson([
            'data' => [
                'bulkUpdateSchedules' => [
                    ['description' => 'Updated description'],
                ],
            ],
        ]);

        // Verify schedule has the new description
        $schedule = Schedule::where('course_id', $this->course->id)->first();
        expect($schedule->description)->toBe('Updated description');
    });
});

describe('Delete Schedule Group', function () {
    test('deletes every slot of the group and nothing else', function () {
        $groupId = (string) \Illuminate\Support\Str::uuid();
        makeGroup($this->course, $this->teacher, $groupId);
        enrollInGroup(Schedule::where('group_id', $groupId)->get()->all(), 'dropped');
        $other = Schedule::factory()->create(['teacher_id' => Teacher::factory()->create()->id]);

        $response = $this->graphQL('mutation { deleteScheduleGroup(group_id: "'.$groupId.'") { id } }');

        $response->assertJsonMissingPath('errors');
        $response->assertJsonCount(2, 'data.deleteScheduleGroup');
        expect(Schedule::where('group_id', $groupId)->count())->toBe(0);
        expect(Schedule::find($other->id))->not->toBeNull();
    });

    test('rejects deleting a group with active students', function () {
        $groupId = (string) \Illuminate\Support\Str::uuid();
        $schedules = makeGroup($this->course, $this->teacher, $groupId);
        enrollInGroup($schedules);

        $response = $this->graphQL('mutation { deleteScheduleGroup(group_id: "'.$groupId.'") { id } }');

        $response->assertGraphQLValidationKeys(['group_id']);
        expect(Schedule::where('group_id', $groupId)->count())->toBe(2);
        expect(StudentEnrollment::active()->count())->toBe(2);
    });
});

describe('Option B: Different Teachers Per Slot', function () {
    test('can create schedules with different teachers for each slot in same group', function () {
        // Create a second teacher
        $teacherB = Teacher::factory()->create(['name' => 'Teacher B']);

        // Create schedules with different teachers (Theory + Lab scenario)
        $response = $this->graphQL('
            mutation {
                bulkCreateSchedules(input: {
                    course_id: '.$this->course->id.'
                    description: "Introduction to Programming - Fall 2026"
                    schedules: [
                        { teacher_id: '.$this->teacher->id.', day_of_week: 1, starts_at: "10:00", ends_at: "12:00" }
                        { teacher_id: '.$teacherB->id.', day_of_week: 3, starts_at: "14:00", ends_at: "16:00" }
                    ]
                }) {
                    id
                    course_id
                    teacher_id
                    day_of_week
                    starts_at
                    ends_at
                    description
                    group_id
                }
            }
        ');

        $response->assertJson([
            'data' => [
                'bulkCreateSchedules' => [
                    [
                        'course_id' => (string) $this->course->id,
                        'teacher_id' => (string) $this->teacher->id,
                        'day_of_week' => 1,
                        'starts_at' => '10:00:00',
                        'ends_at' => '12:00:00',
                        'description' => 'Introduction to Programming - Fall 2026',
                    ],
                    [
                        'course_id' => (string) $this->course->id,
                        'teacher_id' => (string) $teacherB->id,
                        'day_of_week' => 3,
                        'starts_at' => '14:00:00',
                        'ends_at' => '16:00:00',
                        'description' => 'Introduction to Programming - Fall 2026',
                    ],
                ],
            ],
        ]);

        // Verify both schedules were created with the same group_id
        $schedules = Schedule::where('course_id', $this->course->id)->get();
        expect($schedules)->toHaveCount(2);

        $groupId = $schedules->first()->group_id;
        expect($groupId)->not->toBeNull();

        // Both schedules should share the same group_id
        foreach ($schedules as $schedule) {
            expect($schedule->group_id)->toBe($groupId);
        }
    });

    test('can update schedules with different teachers per slot', function () {
        // Create a second teacher
        $teacherB = Teacher::factory()->create(['name' => 'Teacher B']);

        // Create initial schedules with same teacher
        $groupId = (string) \Illuminate\Support\Str::uuid();
        Schedule::factory()->create([
            'course_id' => $this->course->id,
            'teacher_id' => $this->teacher->id,
            'day_of_week' => 1,
            'starts_at' => '10:00:00',
            'ends_at' => '12:00:00',
            'description' => 'Old description',
            'group_id' => $groupId,
        ]);
        Schedule::factory()->create([
            'course_id' => $this->course->id,
            'teacher_id' => $this->teacher->id,
            'day_of_week' => 3,
            'starts_at' => '14:00:00',
            'ends_at' => '16:00:00',
            'description' => 'Old description',
            'group_id' => $groupId,
        ]);

        // Update to have different teachers
        $response = $this->graphQL('
            mutation {
                bulkUpdateSchedules(input: {
                    course_id: '.$this->course->id.'
                    description: "Updated - Split Teaching"
                    group_id: "'.$groupId.'"
                    schedules: [
                        { teacher_id: '.$this->teacher->id.', day_of_week: 1, starts_at: "10:00", ends_at: "12:00" }
                        { teacher_id: '.$teacherB->id.', day_of_week: 3, starts_at: "14:00", ends_at: "16:00" }
                    ]
                }) {
                    id
                    teacher_id
                    day_of_week
                    description
                    group_id
                }
            }
        ');

        $response->assertJson([
            'data' => [
                'bulkUpdateSchedules' => [
                    [
                        'teacher_id' => (string) $this->teacher->id,
                        'day_of_week' => 1,
                        'description' => 'Updated - Split Teaching',
                        'group_id' => $groupId,
                    ],
                    [
                        'teacher_id' => (string) $teacherB->id,
                        'day_of_week' => 3,
                        'description' => 'Updated - Split Teaching',
                        'group_id' => $groupId,
                    ],
                ],
            ],
        ]);

        // Verify the update
        $schedules = Schedule::where('group_id', $groupId)->get();
        expect($schedules)->toHaveCount(2);
        expect($schedules->pluck('teacher_id')->unique())->toHaveCount(2);
    });

    test('allows same time slots with different teachers in same group', function () {
        // Create two teachers
        $teacherA = Teacher::factory()->create(['name' => 'Teacher A']);
        $teacherB = Teacher::factory()->create(['name' => 'Teacher B']);

        // Create schedules with different teachers at the same time (e.g., two sections)
        $response = $this->graphQL('
            mutation {
                bulkCreateSchedules(input: {
                    course_id: '.$this->course->id.'
                    description: "Introduction to Programming - Sections A & B"
                    schedules: [
                        { teacher_id: '.$teacherA->id.', day_of_week: 1, starts_at: "10:00", ends_at: "12:00" }
                        { teacher_id: '.$teacherB->id.', day_of_week: 1, starts_at: "10:00", ends_at: "12:00" }
                    ]
                }) {
                    id
                    teacher_id
                    day_of_week
                    starts_at
                }
            }
        ');

        // This should succeed because different teachers can teach at the same time
        $response->assertJsonCount(2, 'data.bulkCreateSchedules');
        expect(Schedule::where('course_id', $this->course->id)->count())->toBe(2);
    });

    test('prevents same teacher from having overlapping times in same group', function () {
        // Try to create overlapping schedules for the same teacher
        $response = $this->graphQL('
            mutation {
                bulkCreateSchedules(input: {
                    course_id: '.$this->course->id.'
                    description: "Test Schedule"
                    schedules: [
                        { teacher_id: '.$this->teacher->id.', day_of_week: 1, starts_at: "10:00", ends_at: "12:00" }
                        { teacher_id: '.$this->teacher->id.', day_of_week: 1, starts_at: "11:00", ends_at: "13:00" }
                    ]
                }) {
                    id
                }
            }
        ');

        $response->assertGraphQLValidationKeys(['schedules']);
        $errors = $response->json('errors.0.extensions.validation.schedules');
        expect($errors[0])->toContain('Teacher conflict');
    });

    test('validates each slot teacher against existing schedules', function () {
        // Create existing schedule for teacher A
        $teacherA = Teacher::factory()->create(['name' => 'Teacher A']);
        $teacherB = Teacher::factory()->create(['name' => 'Teacher B']);

        Schedule::factory()->create([
            'course_id' => $this->course->id,
            'teacher_id' => $teacherA->id,
            'day_of_week' => 1,
            'starts_at' => '10:00:00',
            'ends_at' => '12:00:00',
        ]);

        // Try to create new group where one slot conflicts with teacher A
        $response = $this->graphQL('
            mutation {
                bulkCreateSchedules(input: {
                    course_id: '.$this->course->id.'
                    description: "New Group"
                    schedules: [
                        { teacher_id: '.$teacherB->id.', day_of_week: 2, starts_at: "10:00", ends_at: "12:00" }
                        { teacher_id: '.$teacherA->id.', day_of_week: 1, starts_at: "11:00", ends_at: "13:00" }
                    ]
                }) {
                    id
                }
            }
        ');

        // Should fail because teacher A already has a schedule at that time
        $response->assertGraphQLValidationKeys(['schedules']);
        $errors = $response->json('errors.0.extensions.validation.schedules');
        expect($errors[0])->toContain('Teacher already has a schedule');
    });

    test('real world scenario - theory and lab with different teachers', function () {
        // Simulate real-world scenario from architecture document
        $teacherTheory = Teacher::factory()->create(['name' => 'Prof. Smith']);
        $teacherLab = Teacher::factory()->create(['name' => 'Dr. Johnson']);

        // Create "Introduction to Programming" with theory (Monday) and lab (Wednesday)
        $response = $this->graphQL('
            mutation {
                bulkCreateSchedules(input: {
                    course_id: '.$this->course->id.'
                    description: "Introduction to Programming - Fall 2026 Section A"
                    schedules: [
                        { teacher_id: '.$teacherTheory->id.', day_of_week: 1, starts_at: "10:00", ends_at: "12:00" }
                        { teacher_id: '.$teacherLab->id.', day_of_week: 3, starts_at: "14:00", ends_at: "16:00" }
                    ]
                }) {
                    id
                    course_id
                    teacher {
                        id
                        name
                    }
                    day_of_week
                    starts_at
                    ends_at
                    description
                    group_id
                }
            }
        ');

        $response->assertJson([
            'data' => [
                'bulkCreateSchedules' => [
                    [
                        'teacher' => [
                            'id' => (string) $teacherTheory->id,
                            'name' => 'Prof. Smith',
                        ],
                        'day_of_week' => 1,
                        'starts_at' => '10:00:00',
                        'ends_at' => '12:00:00',
                    ],
                    [
                        'teacher' => [
                            'id' => (string) $teacherLab->id,
                            'name' => 'Dr. Johnson',
                        ],
                        'day_of_week' => 3,
                        'starts_at' => '14:00:00',
                        'ends_at' => '16:00:00',
                    ],
                ],
            ],
        ]);

        // Verify schedules were created correctly
        $schedules = Schedule::where('course_id', $this->course->id)->get();
        expect($schedules)->toHaveCount(2);

        // Both should have the same group_id and description
        $groupId = $schedules->first()->group_id;
        foreach ($schedules as $schedule) {
            expect($schedule->group_id)->toBe($groupId);
            expect($schedule->description)->toBe('Introduction to Programming - Fall 2026 Section A');
        }

        // But different teachers
        expect($schedules->pluck('teacher_id')->toArray())->toContain($teacherTheory->id, $teacherLab->id);
    });
});
