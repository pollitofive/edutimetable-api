<?php

namespace Tests\Feature\GraphQL;

use App\Models\User;
use App\Models\Teacher;
use App\Models\Course;
use App\Models\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Nuwave\Lighthouse\Testing\MakesGraphQLRequests;

uses(RefreshDatabase::class);
uses(MakesGraphQLRequests::class);

beforeEach(function () {
    $this->user = User::factory()->create();
    Sanctum::actingAs($this->user);

    $this->teacher1 = Teacher::factory()->create(['name' => 'Teacher One']);
    $this->teacher2 = Teacher::factory()->create(['name' => 'Teacher Two']);
    $this->course1 = Course::factory()->create(['name' => 'English A1']);
    $this->course2 = Course::factory()->create(['name' => 'English B1']);
});

describe('Teacher-Based Validation (New Architecture)', function () {

    it('prevents same teacher from teaching at overlapping times', function () {
        // Create existing schedule for teacher1
        Schedule::factory()->create([
            'course_id' => $this->course1->id,
            'teacher_id' => $this->teacher1->id,
            'day_of_week' => 1,
            'starts_at' => '09:00:00',
            'ends_at' => '10:00:00',
        ]);

        // Try to create overlapping schedule for same teacher (different course)
        $mutation = "
            mutation {
                createSchedule(input: {
                    course_id: {$this->course2->id}
                    teacher_id: {$this->teacher1->id}
                    description: \"Test Schedule\"
                    day_of_week: 1
                    starts_at: \"09:30\"
                    ends_at: \"10:30\"
                }) {
                    id
                }
            }
        ";

        $response = $this->postGraphQL(['query' => $mutation]);

        $response->assertJson([
            'errors' => [
                [
                    'message' => 'Teacher already has a schedule at this time on this day'
                ]
            ]
        ]);

        // Verify only one schedule exists
        expect(Schedule::count())->toBe(1);
    });

    it('allows same course to be taught by different teachers at the same time', function () {
        // Create schedule for teacher1 teaching course1
        Schedule::factory()->create([
            'course_id' => $this->course1->id,
            'teacher_id' => $this->teacher1->id,
            'day_of_week' => 1,
            'starts_at' => '09:00:00',
            'ends_at' => '10:00:00',
        ]);

        // Create schedule for teacher2 teaching the SAME course at the SAME time
        $mutation = "
            mutation {
                createSchedule(input: {
                    course_id: {$this->course1->id}
                    teacher_id: {$this->teacher2->id}
                    description: \"Test Schedule\"
                    day_of_week: 1
                    starts_at: \"09:00\"
                    ends_at: \"10:00\"
                }) {
                    id
                    course_id
                    teacher_id
                }
            }
        ";

        $response = $this->postGraphQL(['query' => $mutation]);

        $response->assertJson([
            'data' => [
                'createSchedule' => [
                    'course_id' => (string) $this->course1->id,
                    'teacher_id' => (string) $this->teacher2->id,
                ]
            ]
        ]);

        // Verify both schedules exist
        expect(Schedule::count())->toBe(2);
        expect(Schedule::where('course_id', $this->course1->id)->count())->toBe(2);
    });

    it('allows same teacher to teach non-overlapping times on same day', function () {
        // Create schedule for teacher1
        Schedule::factory()->create([
            'course_id' => $this->course1->id,
            'teacher_id' => $this->teacher1->id,
            'day_of_week' => 1,
            'starts_at' => '09:00:00',
            'ends_at' => '10:00:00',
        ]);

        // Create non-overlapping schedule for same teacher
        $mutation = "
            mutation {
                createSchedule(input: {
                    course_id: {$this->course2->id}
                    teacher_id: {$this->teacher1->id}
                    description: \"Test Schedule\"
                    day_of_week: 1
                    starts_at: \"11:00\"
                    ends_at: \"12:00\"
                }) {
                    id
                    teacher_id
                }
            }
        ";

        $response = $this->postGraphQL(['query' => $mutation]);

        $response->assertJson([
            'data' => [
                'createSchedule' => [
                    'teacher_id' => (string) $this->teacher1->id,
                ]
            ]
        ]);

        expect(Schedule::count())->toBe(2);
    });

    it('validates teacher_id is required on schedule creation', function () {
        $mutation = "
            mutation {
                createSchedule(input: {
                    course_id: {$this->course1->id}
                    day_of_week: 1
                    starts_at: \"09:00\"
                    ends_at: \"10:00\"
                }) {
                    id
                }
            }
        ";

        $response = $this->postGraphQL(['query' => $mutation]);

        expect($response->json('errors'))->not->toBeNull();
    });

    it('validates teacher exists when creating schedule', function () {
        $mutation = "
            mutation {
                createSchedule(input: {
                    course_id: {$this->course1->id}
                    teacher_id: 99999
                    day_of_week: 1
                    starts_at: \"09:00\"
                    ends_at: \"10:00\"
                }) {
                    id
                }
            }
        ";

        $response = $this->postGraphQL(['query' => $mutation]);

        expect($response->json('errors'))->not->toBeNull();
    });
});

describe('Bulk Operations with Teacher Validation', function () {

    it('bulk create prevents same teacher from overlapping in input array', function () {
        $response = $this->graphQL('
            mutation {
                bulkCreateSchedules(input: {
                    description: "Test Schedule"
                    course_id: ' . $this->course1->id . '
                    schedules: [
                        { teacher_id: ' . $this->teacher1->id . ', day_of_week: 1, starts_at: "09:00", ends_at: "11:00" }
                        { teacher_id: ' . $this->teacher1->id . ', day_of_week: 1, starts_at: "10:00", ends_at: "12:00" }
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

    it('bulk create allows different teachers at same time', function () {
        $response = $this->graphQL('
            mutation {
                bulkCreateSchedules(input: {
                    description: "Test Schedule"
                    course_id: ' . $this->course1->id . '
                    schedules: [
                        { teacher_id: ' . $this->teacher1->id . ', day_of_week: 1, starts_at: "09:00", ends_at: "11:00" }
                        { teacher_id: ' . $this->teacher2->id . ', day_of_week: 1, starts_at: "09:00", ends_at: "11:00" }
                    ]
                }) {
                    id
                    teacher_id
                }
            }
        ');

        $response->assertJsonCount(2, 'data.bulkCreateSchedules');
        expect(Schedule::count())->toBe(2);
    });

    it('bulk create requires teacher_id in all slots', function () {
        $response = $this->graphQL('
            mutation {
                bulkCreateSchedules(input: {
                    description: "Test Schedule"
                    course_id: ' . $this->course1->id . '
                    schedules: [
                        { day_of_week: 1, starts_at: "09:00", ends_at: "11:00" }
                    ]
                }) {
                    id
                }
            }
        ');

        // Since teacher_id is required in the GraphQL schema, this will return a GraphQL error
        // rather than a validation error
        expect($response->json('errors'))->not->toBeNull();
        $error = $response->json('errors.0');
        expect($error['message'])->toContain('teacher_id');
    });

    it('bulk create prevents same teacher overlapping with existing schedule', function () {
        // Create existing schedule
        Schedule::factory()->create([
            'course_id' => $this->course1->id,
            'teacher_id' => $this->teacher1->id,
            'day_of_week' => 1,
            'starts_at' => '09:00:00',
            'ends_at' => '11:00:00',
        ]);

        $response = $this->graphQL('
            mutation {
                bulkCreateSchedules(input: {
                    description: "Test Schedule"
                    course_id: ' . $this->course2->id . '
                    schedules: [
                        { teacher_id: ' . $this->teacher1->id . ', day_of_week: 1, starts_at: "10:00", ends_at: "12:00" }
                    ]
                }) {
                    id
                }
            }
        ');

        $response->assertGraphQLValidationKeys(['schedules']);
    });
});

describe('Update Operations with Teacher Validation', function () {

    it('allows updating schedule teacher without conflict', function () {
        $schedule = Schedule::factory()->create([
            'course_id' => $this->course1->id,
            'teacher_id' => $this->teacher1->id,
            'day_of_week' => 1,
            'starts_at' => '09:00:00',
            'ends_at' => '10:00:00',
        ]);

        $mutation = "
            mutation {
                updateSchedule(id: {$schedule->id}, input: {
                    teacher_id: {$this->teacher2->id}
                }) {
                    id
                    teacher_id
                }
            }
        ";

        $response = $this->postGraphQL(['query' => $mutation]);

        $response->assertJson([
            'data' => [
                'updateSchedule' => [
                    'teacher_id' => (string) $this->teacher2->id,
                ]
            ]
        ]);
    });

    it('prevents updating schedule to create teacher conflict', function () {
        // Create two schedules for same teacher
        $schedule1 = Schedule::factory()->create([
            'course_id' => $this->course1->id,
            'teacher_id' => $this->teacher1->id,
            'day_of_week' => 1,
            'starts_at' => '09:00:00',
            'ends_at' => '10:00:00',
        ]);

        $schedule2 = Schedule::factory()->create([
            'course_id' => $this->course2->id,
            'teacher_id' => $this->teacher1->id,
            'day_of_week' => 1,
            'starts_at' => '11:00:00',
            'ends_at' => '12:00:00',
        ]);

        // Try to update schedule2 to overlap with schedule1
        $mutation = "
            mutation {
                updateSchedule(id: {$schedule2->id}, input: {
                    starts_at: \"09:30\"
                    ends_at: \"10:30\"
                }) {
                    id
                }
            }
        ";

        $response = $this->postGraphQL(['query' => $mutation]);

        $response->assertJson([
            'errors' => [
                [
                    'message' => 'Teacher already has a schedule at this time on this day'
                ]
            ]
        ]);
    });
});

describe('Bulk Update Operations with Teacher Validation', function () {

    it('bulk update prevents teacher conflict with other course schedules', function () {
        // Create schedule for teacher1 teaching course1 on Tuesday 10:00-12:00
        Schedule::factory()->create([
            'course_id' => $this->course1->id,
            'teacher_id' => $this->teacher1->id,
            'day_of_week' => 2, // Tuesday
            'starts_at' => '10:00:00',
            'ends_at' => '12:00:00',
            'description' => 'Existing course schedule',
        ]);

        // Try to bulk update course2 schedules with overlapping time for same teacher
        $response = $this->graphQL('
            mutation {
                bulkUpdateSchedules(input: {
                    course_id: ' . $this->course2->id . '
                    description: "New course schedule"
                    schedules: [
                        { teacher_id: ' . $this->teacher1->id . ', day_of_week: 2, starts_at: "10:00", ends_at: "12:00" }
                    ]
                }) {
                    id
                }
            }
        ');

        $response->assertGraphQLValidationKeys(['schedules']);
        $errors = $response->json('errors.0.extensions.validation.schedules');
        expect($errors[0])->toContain('Teacher already has a schedule');
        expect($errors[0])->toContain('for another course');
    });

    it('bulk update allows updating same course schedules with same time slot', function () {
        // Create existing schedules for course1
        Schedule::factory()->create([
            'course_id' => $this->course1->id,
            'teacher_id' => $this->teacher1->id,
            'day_of_week' => 2, // Tuesday
            'starts_at' => '10:00:00',
            'ends_at' => '12:00:00',
            'description' => 'Old description',
        ]);

        // This should succeed because we're replacing the same course's schedules
        $response = $this->graphQL('
            mutation {
                bulkUpdateSchedules(input: {
                    course_id: ' . $this->course1->id . '
                    description: "Updated description"
                    schedules: [
                        { teacher_id: ' . $this->teacher1->id . ', day_of_week: 2, starts_at: "10:00", ends_at: "12:00" }
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
                ]
            ]
        ]);
    });

    it('bulk update allows non-overlapping times with other courses', function () {
        // Create schedule for teacher1 teaching course1 on Tuesday 10:00-12:00
        Schedule::factory()->create([
            'course_id' => $this->course1->id,
            'teacher_id' => $this->teacher1->id,
            'day_of_week' => 2, // Tuesday
            'starts_at' => '10:00:00',
            'ends_at' => '12:00:00',
            'description' => 'Course 1 schedule',
        ]);

        // Update course2 with non-overlapping time - should succeed
        $response = $this->graphQL('
            mutation {
                bulkUpdateSchedules(input: {
                    course_id: ' . $this->course2->id . '
                    description: "Course 2 schedule"
                    schedules: [
                        { teacher_id: ' . $this->teacher1->id . ', day_of_week: 2, starts_at: "14:00", ends_at: "16:00" }
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
                        'day_of_week' => 2,
                        'starts_at' => '14:00:00',
                        'ends_at' => '16:00:00',
                    ],
                ]
            ]
        ]);
    });
});

describe('Many-to-Many Relationships', function () {

    it('course has many teachers through schedules', function () {
        // Create multiple schedules for same course with different teachers
        Schedule::factory()->create([
            'course_id' => $this->course1->id,
            'teacher_id' => $this->teacher1->id,
            'day_of_week' => 1,
            'starts_at' => '09:00:00',
            'ends_at' => '10:00:00',
        ]);

        Schedule::factory()->create([
            'course_id' => $this->course1->id,
            'teacher_id' => $this->teacher2->id,
            'day_of_week' => 2,
            'starts_at' => '09:00:00',
            'ends_at' => '10:00:00',
        ]);

        $teachers = Course::find($this->course1->id)->teachers;

        expect($teachers)->toHaveCount(2);
        expect($teachers->pluck('id'))->toContain($this->teacher1->id, $this->teacher2->id);
    });

    it('teacher has many courses through schedules', function () {
        // Create multiple schedules for same teacher teaching different courses
        Schedule::factory()->create([
            'course_id' => $this->course1->id,
            'teacher_id' => $this->teacher1->id,
            'day_of_week' => 1,
            'starts_at' => '09:00:00',
            'ends_at' => '10:00:00',
        ]);

        Schedule::factory()->create([
            'course_id' => $this->course2->id,
            'teacher_id' => $this->teacher1->id,
            'day_of_week' => 2,
            'starts_at' => '09:00:00',
            'ends_at' => '10:00:00',
        ]);

        $courses = Teacher::find($this->teacher1->id)->courses;

        expect($courses)->toHaveCount(2);
        expect($courses->pluck('id'))->toContain($this->course1->id, $this->course2->id);
    });

    it('course has many teachers through schedules relationship', function () {
        Schedule::factory()->create([
            'course_id' => $this->course1->id,
            'teacher_id' => $this->teacher1->id,
            'day_of_week' => 1,
            'starts_at' => '09:00:00',
            'ends_at' => '10:00:00',
        ]);

        Schedule::factory()->create([
            'course_id' => $this->course1->id,
            'teacher_id' => $this->teacher2->id,
            'day_of_week' => 2,
            'starts_at' => '09:00:00',
            'ends_at' => '10:00:00',
        ]);

        $teachers = $this->course1->fresh()->teachers;

        expect($teachers)->toHaveCount(2);
        expect($teachers->pluck('id'))->toContain($this->teacher1->id, $this->teacher2->id);
    });

    it('schedule belongs to teacher relationship', function () {
        $schedule = Schedule::factory()->create([
            'course_id' => $this->course1->id,
            'teacher_id' => $this->teacher1->id,
            'day_of_week' => 1,
            'starts_at' => '09:00:00',
            'ends_at' => '10:00:00',
        ]);

        $schedule = $schedule->fresh();

        expect($schedule->teacher)->not->toBeNull();
        expect($schedule->teacher->id)->toBe($this->teacher1->id);
        expect($schedule->teacher->name)->toBe('Teacher One');
    });
});