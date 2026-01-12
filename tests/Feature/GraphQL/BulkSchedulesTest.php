<?php

use App\Models\Course;
use App\Models\Schedule;
use App\Models\Teacher;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Nuwave\Lighthouse\Testing\MakesGraphQLRequests;

uses(RefreshDatabase::class);
uses(MakesGraphQLRequests::class);

beforeEach(function () {
    // Create and authenticate a user
    $this->user = User::factory()->create();
    $this->actingAs($this->user);

    // Create a teacher and a course
    $this->teacher = Teacher::factory()->create();
    $this->course = Course::factory()->create([
        'teacher_id' => $this->teacher->id,
    ]);
});

describe('Bulk Create Schedules', function () {
    test('can create multiple schedules successfully', function () {
        $response = $this->graphQL('
            mutation {
                bulkCreateSchedules(input: {
                    course_id: ' . $this->course->id . '
                    schedules: [
                        { day_of_week: 1, starts_at: "09:00", ends_at: "11:00" }
                        { day_of_week: 3, starts_at: "09:00", ends_at: "11:00" }
                        { day_of_week: 5, starts_at: "14:00", ends_at: "16:00" }
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
                ]
            ]
        ]);

        // Verify schedules were created in database
        expect(Schedule::where('course_id', $this->course->id)->count())->toBe(3);
    });

    test('fails with empty schedules array', function () {
        $response = $this->graphQL('
            mutation {
                bulkCreateSchedules(input: {
                    course_id: ' . $this->course->id . '
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
                    course_id: 99999
                    schedules: [
                        { day_of_week: 1, starts_at: "09:00", ends_at: "11:00" }
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
                    course_id: ' . $this->course->id . '
                    schedules: [
                        { day_of_week: 7, starts_at: "09:00", ends_at: "11:00" }
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
                    course_id: ' . $this->course->id . '
                    schedules: [
                        { day_of_week: -1, starts_at: "09:00", ends_at: "11:00" }
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
                    course_id: ' . $this->course->id . '
                    schedules: [
                        { day_of_week: 1, starts_at: "9:00", ends_at: "11:00" }
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
                    course_id: ' . $this->course->id . '
                    schedules: [
                        { day_of_week: 1, starts_at: "09:00 AM", ends_at: "11:00" }
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
                    course_id: ' . $this->course->id . '
                    schedules: [
                        { day_of_week: 1, starts_at: "18:00", ends_at: "09:00" }
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
                    course_id: ' . $this->course->id . '
                    schedules: [
                        { day_of_week: 1, starts_at: "09:00", ends_at: "09:00" }
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
                    course_id: ' . $this->course->id . '
                    schedules: [
                        { day_of_week: 1, starts_at: "09:00", ends_at: "11:00" }
                        { day_of_week: 1, starts_at: "09:00", ends_at: "11:00" }
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
                    course_id: ' . $this->course->id . '
                    schedules: [
                        { day_of_week: 1, starts_at: "09:00", ends_at: "11:00" }
                        { day_of_week: 1, starts_at: "10:00", ends_at: "12:00" }
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
                    course_id: ' . $this->course->id . '
                    schedules: [
                        { day_of_week: 1, starts_at: "09:00", ends_at: "11:00" }
                        { day_of_week: 2, starts_at: "09:00", ends_at: "11:00" }
                        { day_of_week: 3, starts_at: "09:00", ends_at: "11:00" }
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
            'day_of_week' => 1,
            'starts_at' => '09:00:00',
            'ends_at' => '11:00:00',
        ]);

        $response = $this->graphQL('
            mutation {
                bulkCreateSchedules(input: {
                    course_id: ' . $this->course->id . '
                    schedules: [
                        { day_of_week: 1, starts_at: "10:00", ends_at: "12:00" }
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
                    course_id: ' . $this->course->id . '
                    schedules: [
                        { day_of_week: 1, starts_at: "09:00", ends_at: "11:00" }
                        { day_of_week: 1, starts_at: "11:00", ends_at: "13:00" }
                        { day_of_week: 1, starts_at: "13:00", ends_at: "15:00" }
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
                    course_id: ' . $this->course->id . '
                    schedules: [
                        { day_of_week: 1, starts_at: "09:00", ends_at: "11:00" }
                        { day_of_week: 1, starts_at: "10:00", ends_at: "12:00" }
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
        ]);

        expect(Schedule::where('course_id', $this->course->id)->count())->toBe(3);

        $response = $this->graphQL('
            mutation {
                bulkUpdateSchedules(input: {
                    course_id: ' . $this->course->id . '
                    schedules: [
                        { day_of_week: 2, starts_at: "10:00", ends_at: "12:00" }
                        { day_of_week: 4, starts_at: "13:00", ends_at: "15:00" }
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
                ]
            ]
        ]);

        // Verify only 2 schedules exist now (old ones were deleted)
        expect(Schedule::where('course_id', $this->course->id)->count())->toBe(2);
    });

    test('can update to a single schedule', function () {
        // Create some existing schedules
        Schedule::factory()->count(5)->create([
            'course_id' => $this->course->id,
        ]);

        $response = $this->graphQL('
            mutation {
                bulkUpdateSchedules(input: {
                    course_id: ' . $this->course->id . '
                    schedules: [
                        { day_of_week: 3, starts_at: "09:00", ends_at: "17:00" }
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
                    course_id: ' . $this->course->id . '
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
                    course_id: 99999
                    schedules: [
                        { day_of_week: 1, starts_at: "09:00", ends_at: "11:00" }
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
                    course_id: ' . $this->course->id . '
                    schedules: [
                        { day_of_week: 1, starts_at: "09:00", ends_at: "11:00" }
                        { day_of_week: 1, starts_at: "10:30", ends_at: "12:00" }
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
                    course_id: ' . $this->course->id . '
                    schedules: [
                        { day_of_week: 1, starts_at: "15:00", ends_at: "10:00" }
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
            'day_of_week' => 1,
            'starts_at' => '09:00:00',
            'ends_at' => '11:00:00',
        ]);

        // This should succeed because bulk update deletes all existing schedules first
        $response = $this->graphQL('
            mutation {
                bulkUpdateSchedules(input: {
                    course_id: ' . $this->course->id . '
                    schedules: [
                        { day_of_week: 1, starts_at: "09:00", ends_at: "11:00" }
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
                ]
            ]
        ]);
    });

    test('transaction rolls back on validation failure', function () {
        // Create existing schedules
        Schedule::factory()->count(3)->create([
            'course_id' => $this->course->id,
        ]);

        $originalCount = Schedule::where('course_id', $this->course->id)->count();

        $response = $this->graphQL('
            mutation {
                bulkUpdateSchedules(input: {
                    course_id: ' . $this->course->id . '
                    schedules: [
                        { day_of_week: 1, starts_at: "09:00", ends_at: "11:00" }
                        { day_of_week: 1, starts_at: "10:00", ends_at: "12:00" }
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
        // Create another course with schedules
        $otherCourse = Course::factory()->create([
            'teacher_id' => $this->teacher->id,
        ]);
        Schedule::factory()->count(2)->create([
            'course_id' => $otherCourse->id,
        ]);

        // Create schedules for our test course
        Schedule::factory()->count(3)->create([
            'course_id' => $this->course->id,
        ]);

        // Update only our test course schedules
        $response = $this->graphQL('
            mutation {
                bulkUpdateSchedules(input: {
                    course_id: ' . $this->course->id . '
                    schedules: [
                        { day_of_week: 1, starts_at: "09:00", ends_at: "11:00" }
                    ]
                }) {
                    id
                }
            }
        ');

        // Our course should have 1 schedule
        expect(Schedule::where('course_id', $this->course->id)->count())->toBe(1);

        // Other course should still have 2 schedules (unchanged)
        expect(Schedule::where('course_id', $otherCourse->id)->count())->toBe(2);
    });
});