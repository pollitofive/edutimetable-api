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
    $this->course = Course::factory()->create();
});

it('returns description field when creating schedule', function () {
    $mutation = "
        mutation {
            createSchedule(input: {
                course_id: {$this->course->id}
                teacher_id: {$this->teacher->id}
                day_of_week: 1
                starts_at: \"09:00\"
                ends_at: \"10:00\"
                description: \"Introduction to Laravel\"
            }) {
                id
                day_of_week
                starts_at
                ends_at
                description
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
                'description' => 'Introduction to Laravel',
            ]
        ]
    ]);
});

it('requires description field', function () {
    $mutation = "
        mutation {
            createSchedule(input: {
                course_id: {$this->course->id}
                teacher_id: {$this->teacher->id}
                day_of_week: 1
                starts_at: \"09:00\"
                ends_at: \"10:00\"
            }) {
                id
                description
            }
        }
    ";

    $response = $this->postGraphQL(['query' => $mutation]);

    // Since description is required, this should fail
    $response->assertJson([
        'errors' => [
            [
                'message' => 'Field CreateScheduleInput.description of required type String! was not provided.',
            ]
        ]
    ]);
});

it('returns description field when updating schedule', function () {
    $schedule = Schedule::factory()->create([
        'course_id' => $this->course->id,
        'teacher_id' => $this->teacher->id,
    ]);

    $mutation = "
        mutation {
            updateSchedule(id: {$schedule->id}, input: {
                description: \"Updated description\"
            }) {
                id
                description
            }
        }
    ";

    $response = $this->postGraphQL(['query' => $mutation]);

    $response->assertJson([
        'data' => [
            'updateSchedule' => [
                'description' => 'Updated description',
            ]
        ]
    ]);

    $schedule->refresh();
    expect($schedule->description)->toBe('Updated description');
});

it('returns description field when querying schedules', function () {
    Schedule::factory()->create([
        'course_id' => $this->course->id,
        'teacher_id' => $this->teacher->id,
        'description' => 'Test description',
    ]);

    $query = "
        query {
            schedules(course_id: {$this->course->id}) {
                data {
                    id
                    description
                }
            }
        }
    ";

    $response = $this->postGraphQL(['query' => $query]);

    $response->assertJson([
        'data' => [
            'schedules' => [
                'data' => [
                    [
                        'description' => 'Test description',
                    ]
                ]
            ]
        ]
    ]);
});

it('returns description field when deleting schedule', function () {
    $schedule = Schedule::factory()->create([
        'course_id' => $this->course->id,
        'teacher_id' => $this->teacher->id,
        'description' => 'To be deleted',
    ]);

    $mutation = "
        mutation {
            deleteSchedule(id: {$schedule->id}) {
                id
                description
            }
        }
    ";

    $response = $this->postGraphQL(['query' => $mutation]);

    $response->assertJson([
        'data' => [
            'deleteSchedule' => [
                'description' => 'To be deleted',
            ]
        ]
    ]);
});
