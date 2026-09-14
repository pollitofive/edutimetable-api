<?php

namespace Tests\Feature\GraphQL;

use App\Models\Business;
use App\Models\Course;
use App\Models\Schedule;
use App\Models\Student;
use App\Models\StudentEnrollment;
use App\Models\Teacher;
use App\Models\User;
use App\Services\CurrentBusiness;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class TeacherScheduleQueriesTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected Business $business;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->business = Business::factory()->create();
        $this->user->businesses()->attach($this->business->id, ['role' => 'owner']);

        // Factories rely on the current business being resolved, same as the
        // ResolveBusiness middleware does for a real request.
        app(CurrentBusiness::class)->setId($this->business->id);
    }

    public function test_teacher_schedules_expose_capacity_group_id_and_enrollments(): void
    {
        $teacher = Teacher::factory()->create();
        $course = Course::factory()->create(['name' => 'English Beginner A']);
        $groupId = (string) Str::uuid();

        $tuesday = Schedule::factory()->create([
            'teacher_id' => $teacher->id,
            'course_id' => $course->id,
            'group_id' => $groupId,
            'day_of_week' => 2,
            'starts_at' => '19:00:00',
            'ends_at' => '20:00:00',
            'capacity' => 8,
        ]);
        $thursday = Schedule::factory()->create([
            'teacher_id' => $teacher->id,
            'course_id' => $course->id,
            'group_id' => $groupId,
            'day_of_week' => 4,
            'starts_at' => '19:00:00',
            'ends_at' => '20:00:00',
            'capacity' => 8,
        ]);

        $student = Student::factory()->create();
        StudentEnrollment::factory()->active()->create(['schedule_id' => $tuesday->id, 'student_id' => $student->id]);
        StudentEnrollment::factory()->active()->create(['schedule_id' => $thursday->id, 'student_id' => $student->id]);
        StudentEnrollment::factory()->dropped()->create(['schedule_id' => $tuesday->id]);

        $query = <<<'GQL'
        query {
            teachers(first: 10, page: 1) {
                data {
                    id
                    schedules {
                        id
                        day_of_week
                        starts_at
                        ends_at
                        capacity
                        group_id
                        course { id name }
                        enrollments { id status }
                    }
                }
            }
        }
        GQL;

        $response = $this->actingAs($this->user)
            ->withHeader('X-Business-Id', (string) $this->business->id)
            ->postJson('/api/graphql', ['query' => $query]);

        $response->assertStatus(200)->assertJsonMissingPath('errors');

        $schedules = collect($response->json('data.teachers.data'))
            ->firstWhere('id', (string) $teacher->id)['schedules'];

        $this->assertCount(2, $schedules);
        $tuesdaySchedule = collect($schedules)->firstWhere('id', (string) $tuesday->id);
        $this->assertSame($groupId, $tuesdaySchedule['group_id']);
        $this->assertSame(8, $tuesdaySchedule['capacity']);
        $this->assertSame('English Beginner A', $tuesdaySchedule['course']['name']);
        $this->assertCount(2, $tuesdaySchedule['enrollments']);
        $this->assertEqualsCanonicalizing(
            ['ACTIVE', 'DROPPED'],
            collect($tuesdaySchedule['enrollments'])->pluck('status')->all()
        );
    }

    public function test_courses_query_filters_by_exact_id(): void
    {
        $target = Course::factory()->create(['name' => 'English Beginner A']);
        Course::factory()->create(['name' => 'English Beginner A Extra']);

        $query = <<<'GQL'
        query($id: ID) {
            courses(first: 10, page: 1, id: $id) {
                data { id name }
            }
        }
        GQL;

        $response = $this->actingAs($this->user)
            ->withHeader('X-Business-Id', (string) $this->business->id)
            ->postJson('/api/graphql', ['query' => $query, 'variables' => ['id' => $target->id]]);

        $response->assertStatus(200)->assertJsonMissingPath('errors');

        $data = $response->json('data.courses.data');
        $this->assertCount(1, $data);
        $this->assertSame((string) $target->id, $data[0]['id']);
    }
}
