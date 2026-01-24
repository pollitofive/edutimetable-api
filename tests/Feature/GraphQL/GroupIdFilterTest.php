<?php

namespace Tests\Feature\GraphQL;

use App\Models\Course;
use App\Models\Schedule;
use App\Models\Teacher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

it('can filter schedules by group_id', function () {
    $tenancy = setupTenancy();
    Sanctum::actingAs($tenancy->user);

    $teacher = Teacher::factory()->create();
    $course = Course::factory()->create();

    // Create schedules with same group_id
    $groupId = 'test-group-123';
    Schedule::factory()->create([
        'course_id' => $course->id,
        'teacher_id' => $teacher->id,
        'day_of_week' => 1,
        'starts_at' => '09:00',
        'ends_at' => '10:00',
        'description' => 'Test Schedule Group',
        'group_id' => $groupId,
    ]);

    Schedule::factory()->create([
        'course_id' => $course->id,
        'teacher_id' => $teacher->id,
        'day_of_week' => 3,
        'starts_at' => '14:00',
        'ends_at' => '15:00',
        'description' => 'Test Schedule Group',
        'group_id' => $groupId,
    ]);

    // Create a schedule with different group_id
    Schedule::factory()->create([
        'course_id' => $course->id,
        'teacher_id' => $teacher->id,
        'day_of_week' => 5,
        'starts_at' => '11:00',
        'ends_at' => '12:00',
        'description' => 'Other Schedule',
        'group_id' => 'other-group-456',
    ]);

    // Query for schedules with specific group_id
    $query = "
        query {
            schedules(first: 100, page: 1, group_id: \"$groupId\") {
                data {
                    id
                    day_of_week
                    group_id
                }
                paginatorInfo {
                    total
                    count
                }
            }
        }
    ";

    $response = $this->postGraphQL(['query' => $query]);

    $response->assertJson([
        'data' => [
            'schedules' => [
                'paginatorInfo' => [
                    'total' => 2,
                    'count' => 2,
                ],
            ],
        ],
    ]);

    // Verify both schedules have correct group_id
    $schedules = $response->json('data.schedules.data');
    expect(count($schedules))->toBe(2);
    expect($schedules[0]['group_id'])->toBe($groupId);
    expect($schedules[1]['group_id'])->toBe($groupId);
});
