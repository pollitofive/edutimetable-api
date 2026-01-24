<?php

namespace Tests\Feature\GraphQL;

use App\Models\Course;
use App\Models\Schedule;
use App\Models\Teacher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

beforeEach(function () {
    $tenancy = setupTenancy();
    $this->user = $tenancy->user;
    $this->business = $tenancy->business;
    Sanctum::actingAs($this->user);

    $this->teacher = Teacher::factory()->create();
    $this->course = Course::factory()->create();
});

it('can create a schedule via GraphQL', function () {
    $mutation = "
        mutation {
            createSchedule(input: {
                course_id: {$this->course->id}
                teacher_id: {$this->teacher->id}
                description: \"Test Schedule\"
                day_of_week: 1
                starts_at: \"09:00\"
                ends_at: \"10:00\"
            }) {
                id
                day_of_week
                starts_at
                ends_at
                course {
                    id
                    name
                }
            }
        }
    ";

    $response = $this->postGraphQL(['query' => $mutation]);

    $response->assertJson([
        'data' => [
            'createSchedule' => [
                'day_of_week' => 1,
                'starts_at' => '09:00:00',
                'ends_at' => '10:00:00',
                'course' => [
                    'id' => (string) $this->course->id,
                    'name' => $this->course->name,
                ],
            ],
        ],
    ]);

    expect(Schedule::count())->toBe(1);
});

it('prevents overlapping schedules for same course and day', function () {
    // Create existing schedule
    Schedule::factory()->create([
        'course_id' => $this->course->id,
        'teacher_id' => $this->teacher->id,
        'day_of_week' => 1,
        'starts_at' => '09:00',
        'ends_at' => '10:00',
    ]);

    // Try to create overlapping schedule
    $mutation = "
        mutation {
            createSchedule(input: {
                course_id: {$this->course->id}
                teacher_id: {$this->teacher->id}
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
                'message' => 'Teacher already has a schedule at this time on this day',
            ],
        ],
    ]);

    expect(Schedule::count())->toBe(1);
});

it('allows non-overlapping schedules for same course and day', function () {
    // Create existing schedule
    Schedule::factory()->create([
        'course_id' => $this->course->id,
        'teacher_id' => $this->teacher->id,
        'day_of_week' => 1,
        'starts_at' => '09:00',
        'ends_at' => '10:00',
    ]);

    // Create non-overlapping schedule
    $mutation = "
        mutation {
            createSchedule(input: {
                course_id: {$this->course->id}
                teacher_id: {$this->teacher->id}
                description: \"Test Schedule\"
                day_of_week: 1
                starts_at: \"11:00\"
                ends_at: \"12:00\"
            }) {
                id
                starts_at
                ends_at
            }
        }
    ";

    $response = $this->postGraphQL(['query' => $mutation]);

    $response->assertJson([
        'data' => [
            'createSchedule' => [
                'starts_at' => '11:00:00',
                'ends_at' => '12:00:00',
            ],
        ],
    ]);

    expect(Schedule::count())->toBe(2);
});

it('can query schedules by course via GraphQL', function () {
    $course2 = Course::factory()->create();

    Schedule::factory()->count(2)->create([
        'course_id' => $this->course->id,
        'teacher_id' => $this->teacher->id,
    ]);
    Schedule::factory()->create([
        'course_id' => $course2->id,
        'teacher_id' => $this->teacher->id,
    ]);

    $query = "
        query {
            schedules(course_id: {$this->course->id}) {
                data {
                    id
                    day_of_week
                    starts_at
                    ends_at
                    course {
                        id
                    }
                }
            }
        }
    ";

    $response = $this->postGraphQL(['query' => $query]);

    $response->assertJsonCount(2, 'data.schedules.data');

    $scheduleData = $response->json('data.schedules.data');
    foreach ($scheduleData as $schedule) {
        expect($schedule['course']['id'])->toBe((string) $this->course->id);
    }
});

it('can query schedules by day of week via GraphQL', function () {
    Schedule::factory()->create([
        'course_id' => $this->course->id,
        'teacher_id' => $this->teacher->id,
        'day_of_week' => 1,
    ]);
    Schedule::factory()->create([
        'course_id' => $this->course->id,
        'teacher_id' => $this->teacher->id,
        'day_of_week' => 2,
    ]);

    $query = '
        query {
            schedules(day_of_week: 1) {
                data {
                    id
                    day_of_week
                }
            }
        }
    ';

    $response = $this->postGraphQL(['query' => $query]);

    $response->assertJsonCount(1, 'data.schedules.data');
    expect($response->json('data.schedules.data.0.day_of_week'))->toBe(1);
});

it('can update a schedule via GraphQL', function () {
    $schedule = Schedule::factory()->create([
        'course_id' => $this->course->id,
        'teacher_id' => $this->teacher->id,
    ]);

    $mutation = "
        mutation {
            updateSchedule(id: {$schedule->id}, input: {
                starts_at: \"10:00\"
                ends_at: \"11:00\"
            }) {
                id
                starts_at
                ends_at
            }
        }
    ";

    $response = $this->postGraphQL(['query' => $mutation]);

    $response->assertJson([
        'data' => [
            'updateSchedule' => [
                'starts_at' => '10:00:00',
                'ends_at' => '11:00:00',
            ],
        ],
    ]);

    $schedule->refresh();
    expect($schedule->starts_at)->toBe('10:00:00');
    expect($schedule->ends_at)->toBe('11:00:00');
});

it('can delete a schedule via GraphQL', function () {
    $schedule = Schedule::factory()->create([
        'course_id' => $this->course->id,
        'teacher_id' => $this->teacher->id,
    ]);

    $mutation = "
        mutation {
            deleteSchedule(id: {$schedule->id}) {
                id
                day_of_week
                starts_at
                ends_at
            }
        }
    ";

    $response = $this->postGraphQL(['query' => $mutation]);

    $response->assertJson([
        'data' => [
            'deleteSchedule' => [
                'id' => (string) $schedule->id,
                'day_of_week' => $schedule->day_of_week,
            ],
        ],
    ]);

    expect(Schedule::count())->toBe(0);
});

// @TODO: Fix overlap validation on update - currently allowing overlaps
// it('prevents updating schedule to overlap with another schedule', function () {
//     // Create two non-overlapping schedules
//     $schedule1 = Schedule::factory()->create([
//         'course_id' => $this->course->id,
//         'day_of_week' => 1,
//         'starts_at' => '09:00:00',
//         'ends_at' => '10:00:00',
//     ]);

//     $schedule2 = Schedule::factory()->create([
//         'course_id' => $this->course->id,
//         'day_of_week' => 1,
//         'starts_at' => '11:00:00',
//         'ends_at' => '12:00:00',
//     ]);

//     // Try to update schedule2 to overlap with schedule1
//     $mutation = "
//         mutation {
//             updateSchedule(id: {$schedule2->id}, input: {
//                 starts_at: \"09:30\"
//                 ends_at: \"10:30\"
//             }) {
//                 id
//             }
//         }
//     ";

//     $response = $this->postGraphQL(['query' => $mutation]);

//     expect($response->json('errors'))->not->toBeNull();
//     expect($response->json('errors.0.message'))->toContain('overlap');

//     // Verify schedule2 was not updated
//     $schedule2->refresh();
//     expect($schedule2->starts_at)->toBe('11:00:00');
// });

it('allows updating schedule to non-overlapping time', function () {
    $schedule1 = Schedule::factory()->create([
        'course_id' => $this->course->id,
        'teacher_id' => $this->teacher->id,
        'day_of_week' => 1,
        'starts_at' => '09:00:00',
        'ends_at' => '10:00:00',
    ]);

    $schedule2 = Schedule::factory()->create([
        'course_id' => $this->course->id,
        'teacher_id' => $this->teacher->id,
        'day_of_week' => 1,
        'starts_at' => '11:00:00',
        'ends_at' => '12:00:00',
    ]);

    // Update schedule2 to a different non-overlapping time
    $mutation = "
        mutation {
            updateSchedule(id: {$schedule2->id}, input: {
                starts_at: \"13:00\"
                ends_at: \"14:00\"
            }) {
                id
                starts_at
                ends_at
            }
        }
    ";

    $response = $this->postGraphQL(['query' => $mutation]);

    $response->assertJson([
        'data' => [
            'updateSchedule' => [
                'starts_at' => '13:00:00',
                'ends_at' => '14:00:00',
            ],
        ],
    ]);
});

it('allows schedule to overlap with itself when updating', function () {
    $schedule = Schedule::factory()->create([
        'course_id' => $this->course->id,
        'teacher_id' => $this->teacher->id,
        'day_of_week' => 1,
        'starts_at' => '09:00:00',
        'ends_at' => '10:00:00',
    ]);

    // Update to slightly different time (should not consider itself as overlap)
    $mutation = "
        mutation {
            updateSchedule(id: {$schedule->id}, input: {
                starts_at: \"09:15\"
                ends_at: \"10:15\"
            }) {
                id
                starts_at
                ends_at
            }
        }
    ";

    $response = $this->postGraphQL(['query' => $mutation]);

    $response->assertJson([
        'data' => [
            'updateSchedule' => [
                'starts_at' => '09:15:00',
                'ends_at' => '10:15:00',
            ],
        ],
    ]);
});

it('validates invalid time format on create', function () {
    $mutation = "
        mutation {
            createSchedule(input: {
                course_id: {$this->course->id}
                teacher_id: {$this->teacher->id}
                description: \"Test Schedule\"
                day_of_week: 1
                starts_at: \"25:00\"
                ends_at: \"26:00\"
            }) {
                id
            }
        }
    ";

    $response = $this->postGraphQL(['query' => $mutation]);

    expect($response->json('errors'))->not->toBeNull();
});

it('validates start time before end time on create', function () {
    $mutation = "
        mutation {
            createSchedule(input: {
                course_id: {$this->course->id}
                teacher_id: {$this->teacher->id}
                description: \"Test Schedule\"
                day_of_week: 1
                starts_at: \"15:00\"
                ends_at: \"14:00\"
            }) {
                id
            }
        }
    ";

    $response = $this->postGraphQL(['query' => $mutation]);

    $response->assertJson([
        'errors' => [
            [
                'message' => 'starts_at must be before ends_at',
            ],
        ],
    ]);
});

it('validates day of week range on create', function () {
    $mutation = "
        mutation {
            createSchedule(input: {
                course_id: {$this->course->id}
                teacher_id: {$this->teacher->id}
                description: \"Test Schedule\"
                day_of_week: 7
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

it('validates course exists on create', function () {
    $mutation = "
        mutation {
            createSchedule(input: {
                course_id: 99999
                teacher_id: {$this->teacher->id}
                description: \"Test Schedule\"
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

it('allows updating to different day of week', function () {
    $schedule = Schedule::factory()->create([
        'course_id' => $this->course->id,
        'teacher_id' => $this->teacher->id,
        'day_of_week' => 1,
        'starts_at' => '09:00:00',
        'ends_at' => '10:00:00',
    ]);

    $mutation = "
        mutation {
            updateSchedule(id: {$schedule->id}, input: {
                day_of_week: 2
            }) {
                id
                day_of_week
            }
        }
    ";

    $response = $this->postGraphQL(['query' => $mutation]);

    $response->assertJson([
        'data' => [
            'updateSchedule' => [
                'day_of_week' => 2,
            ],
        ],
    ]);
});

it('allows updating to different course', function () {
    $course2 = Course::factory()->create();

    $schedule = Schedule::factory()->create([
        'course_id' => $this->course->id,
        'teacher_id' => $this->teacher->id,
        'day_of_week' => 1,
        'starts_at' => '09:00:00',
        'ends_at' => '10:00:00',
    ]);

    $mutation = "
        mutation {
            updateSchedule(id: {$schedule->id}, input: {
                course_id: {$course2->id}
            }) {
                id
                course_id
                course {
                    id
                }
            }
        }
    ";

    $response = $this->postGraphQL(['query' => $mutation]);

    $response->assertJson([
        'data' => [
            'updateSchedule' => [
                'course_id' => (string) $course2->id,
                'course' => [
                    'id' => (string) $course2->id,
                ],
            ],
        ],
    ]);
});
