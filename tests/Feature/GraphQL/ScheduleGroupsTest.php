<?php

use App\Models\Course;
use App\Models\Schedule;
use App\Models\StudentEnrollment;
use App\Models\Teacher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Nuwave\Lighthouse\Testing\MakesGraphQLRequests;

uses(RefreshDatabase::class);
uses(MakesGraphQLRequests::class);

beforeEach(function () {
    $tenancy = setupTenancy();
    $this->actingAs($tenancy->user);

    $this->teacher = Teacher::factory()->create();

    // Creates a group with one slot per given day, 10-12, for a new course with the given name
    $this->makeGroup = function (string $courseName, array $days, int $capacity = 5): string {
        $course = Course::factory()->create(['name' => $courseName]);
        $groupId = (string) Str::uuid();

        foreach ($days as $day) {
            Schedule::factory()->create([
                'course_id' => $course->id,
                'teacher_id' => $this->teacher->id,
                'day_of_week' => $day,
                'starts_at' => '10:00:00',
                'ends_at' => '12:00:00',
                'group_id' => $groupId,
                'capacity' => $capacity,
            ]);
        }

        return $groupId;
    };
});

const SCHEDULE_GROUPS_QUERY = '
    query ($first: Int!, $page: Int, $day_of_week: Int) {
        scheduleGroups(first: $first, page: $page, day_of_week: $day_of_week) {
            data { group_id course { name } capacity active_enrollments_count schedules { id day_of_week } }
            paginatorInfo { total count currentPage lastPage hasMorePages perPage }
        }
    }
';

it('paginates by group, ordered by course name, with every slot of each group', function () {
    ($this->makeGroup)('B course', [1, 3, 5]);
    ($this->makeGroup)('A course', [2, 4]);
    ($this->makeGroup)('C course', [1]);

    $response = $this->graphQL(SCHEDULE_GROUPS_QUERY, ['first' => 2, 'page' => 1]);

    $response->assertJsonMissingPath('errors');
    expect($response->json('data.scheduleGroups.data.*.course.name'))->toBe(['A course', 'B course']);
    expect($response->json('data.scheduleGroups.data.1.schedules.*.day_of_week'))->toBe([1, 3, 5]);
    expect($response->json('data.scheduleGroups.paginatorInfo'))->toMatchArray([
        'total' => 3, 'count' => 2, 'currentPage' => 1, 'lastPage' => 2, 'hasMorePages' => true,
    ]);
});

it('returns the whole group when any of its slots matches a filter', function () {
    $monday = ($this->makeGroup)('Mon & Wed', [1, 3]);
    ($this->makeGroup)('Tue & Thu', [2, 4]);

    $response = $this->graphQL(SCHEDULE_GROUPS_QUERY, ['first' => 10, 'day_of_week' => 1]);

    expect($response->json('data.scheduleGroups.data.*.group_id'))->toBe([$monday]);
    expect($response->json('data.scheduleGroups.data.0.schedules'))->toHaveCount(2);
});

it('exposes the group capacity and its active students', function () {
    $groupId = ($this->makeGroup)('Course', [1, 3], 12);
    $schedules = Schedule::where('group_id', $groupId)->get();

    foreach ($schedules as $schedule) {
        StudentEnrollment::factory()->create(['schedule_id' => $schedule->id, 'status' => 'active']);
        StudentEnrollment::factory()->create(['schedule_id' => $schedule->id, 'status' => 'dropped']);
    }

    $response = $this->graphQL(SCHEDULE_GROUPS_QUERY, ['first' => 10]);

    expect($response->json('data.scheduleGroups.data.0'))->toMatchArray([
        'capacity' => 12,
        'active_enrollments_count' => 1,
    ]);
});
