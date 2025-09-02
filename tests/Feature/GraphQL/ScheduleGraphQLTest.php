<?php

namespace Tests\Feature\GraphQL;

use App\Models\User;
use App\Models\Teacher;
use App\Models\Course;
use App\Models\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create();
    Sanctum::actingAs($this->user);
    
    $this->teacher = Teacher::factory()->create();
    $this->course = Course::factory()->create(['teacher_id' => $this->teacher->id]);
});

it('can create a schedule via GraphQL', function () {
    $mutation = "
        mutation {
            createSchedule(input: {
                course_id: {$this->course->id}
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
                ]
            ]
        ]
    ]);

    expect(Schedule::count())->toBe(1);
});

it('prevents overlapping schedules for same course and day', function () {
    // Create existing schedule
    Schedule::factory()->create([
        'course_id' => $this->course->id,
        'day_of_week' => 1,
        'starts_at' => '09:00',
        'ends_at' => '10:00',
    ]);

    // Try to create overlapping schedule
    $mutation = "
        mutation {
            createSchedule(input: {
                course_id: {$this->course->id}
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
                'message' => 'Schedule overlaps an existing timeslot for this course and day'
            ]
        ]
    ]);

    expect(Schedule::count())->toBe(1);
});

it('allows non-overlapping schedules for same course and day', function () {
    // Create existing schedule
    Schedule::factory()->create([
        'course_id' => $this->course->id,
        'day_of_week' => 1,
        'starts_at' => '09:00',
        'ends_at' => '10:00',
    ]);

    // Create non-overlapping schedule
    $mutation = "
        mutation {
            createSchedule(input: {
                course_id: {$this->course->id}
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
            ]
        ]
    ]);

    expect(Schedule::count())->toBe(2);
});

it('can query schedules by course via GraphQL', function () {
    $course2 = Course::factory()->create(['teacher_id' => $this->teacher->id]);
    
    Schedule::factory()->count(2)->create(['course_id' => $this->course->id]);
    Schedule::factory()->create(['course_id' => $course2->id]);

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
        'day_of_week' => 1
    ]);
    Schedule::factory()->create([
        'course_id' => $this->course->id,
        'day_of_week' => 2
    ]);

    $query = "
        query {
            schedules(day_of_week: 1) {
                data {
                    id
                    day_of_week
                }
            }
        }
    ";

    $response = $this->postGraphQL(['query' => $query]);

    $response->assertJsonCount(1, 'data.schedules.data');
    expect($response->json('data.schedules.data.0.day_of_week'))->toBe(1);
});

it('can update a schedule via GraphQL', function () {
    $schedule = Schedule::factory()->create(['course_id' => $this->course->id]);

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
                'starts_at' => '10:00',
                'ends_at' => '11:00',
            ]
        ]
    ]);

    $schedule->refresh();
    expect($schedule->starts_at)->toBe('10:00');
    expect($schedule->ends_at)->toBe('11:00');
});

it('can delete a schedule via GraphQL', function () {
    $schedule = Schedule::factory()->create(['course_id' => $this->course->id]);

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
            ]
        ]
    ]);

    expect(Schedule::count())->toBe(0);
});