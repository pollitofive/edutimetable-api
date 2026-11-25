<?php

use App\Models\Course;
use App\Models\CourseLevel;
use App\Models\Schedule;
use App\Models\Student;
use App\Models\StudentAvailability;
use App\Models\Teacher;
use App\Models\Track;
use App\Services\EnrollmentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

// A student can study several tracks, at one level per track (card #28).

beforeEach(function () {
    $tenancy = setupTenancy();
    Sanctum::actingAs($tenancy->user);

    $this->english = Track::factory()->create(['name' => 'Inglés']);
    $this->portuguese = Track::factory()->create(['name' => 'Portugués']);
    $this->englishB1 = CourseLevel::factory()->create(['track_id' => $this->english->id, 'name' => 'B1']);
    $this->englishB2 = CourseLevel::factory()->create(['track_id' => $this->english->id, 'name' => 'B2']);
    $this->portugueseA2 = CourseLevel::factory()->create(['track_id' => $this->portuguese->id, 'name' => 'A2']);
});

function createStudentWithLevels(array $levelIds): array
{
    return [
        'query' => '
            mutation ($input: CreateStudentInput!) {
                createStudent(input: $input) { id courseLevels { id name track { name } } }
            }
        ',
        'variables' => ['input' => [
            'name' => 'Ana',
            'email' => 'ana@example.com',
            'course_level_ids' => array_map('strval', $levelIds),
        ]],
    ];
}

describe('createStudent / updateStudent', function () {
    it('creates a student with a level in each of several tracks', function () {
        $response = $this->postGraphQL(createStudentWithLevels([$this->englishB1->id, $this->portugueseA2->id]))
            ->assertGraphQLErrorFree();

        $student = Student::firstWhere('email', 'ana@example.com');

        expect($student->courseLevels->pluck('id')->sort()->values()->all())
            ->toBe([$this->englishB1->id, $this->portugueseA2->id])
            ->and(DB::table('course_level_student')->distinct()->pluck('business_id')->all())->toBe([$student->business_id])
            ->and($response->json('data.createStudent.courseLevels'))->toHaveCount(2);
    });

    it('rejects two levels of the same track', function () {
        $this->postGraphQL(createStudentWithLevels([$this->englishB1->id, $this->englishB2->id]), ['Accept-Language' => 'es'])
            ->assertGraphQLValidationError('input.course_level_ids', 'Elegí un solo nivel por pista.');

        expect(Student::count())->toBe(0);
    });

    it('requires at least one level', function () {
        $this->postGraphQL(createStudentWithLevels([]))
            ->assertGraphQLValidationKeys(['input.course_level_ids']);

        expect(Student::count())->toBe(0);
    });

    it('replaces the levels when updating, and keeps them when the input has none', function () {
        $student = Student::factory()->withLevels([$this->englishB1])->create();

        $this->postGraphQL(['query' => "
            mutation { updateStudent(id: {$student->id}, input: { course_level_ids: [{$this->englishB2->id}, {$this->portugueseA2->id}] }) { id } }
        "])->assertGraphQLErrorFree();

        expect($student->courseLevels()->pluck('course_levels.id')->sort()->values()->all())
            ->toBe([$this->englishB2->id, $this->portugueseA2->id]);

        $this->postGraphQL(['query' => "
            mutation { updateStudent(id: {$student->id}, input: { name: \"Otro nombre\" }) { id } }
        "])->assertGraphQLErrorFree();

        expect($student->fresh()->name)->toBe('Otro nombre')
            ->and($student->courseLevels()->count())->toBe(2);
    });

    it('rejects emptying the levels when updating', function () {
        $student = Student::factory()->withLevels([$this->englishB1])->create();

        $this->postGraphQL(['query' => "
            mutation { updateStudent(id: {$student->id}, input: { course_level_ids: [] }) { id } }
        "])->assertGraphQLValidationKeys(['input.course_level_ids']);

        expect($student->courseLevels()->count())->toBe(1);
    });
});

describe('students query filters', function () {
    it('finds a student with several tracks by any of them', function () {
        $student = Student::factory()->withLevels([$this->englishB1, $this->portugueseA2])->create();
        Student::factory()->withLevels([$this->englishB2])->create();

        $query = fn (string $args) => $this->postGraphQL(['query' => "{ students(first: 10, {$args}) { data { id } } }"])
            ->assertGraphQLErrorFree()
            ->json('data.students.data.*.id');

        expect($query("track_id: {$this->portuguese->id}"))->toBe([(string) $student->id])
            ->and($query("course_level_id: {$this->englishB1->id}"))->toBe([(string) $student->id])
            ->and($query("track_id: {$this->english->id}"))->toHaveCount(2);
    });
});

describe('enrollments of a student with several tracks', function () {
    beforeEach(function () {
        $teacher = Teacher::factory()->create();
        $makeSchedule = fn (CourseLevel $level, int $day) => Schedule::factory()->create([
            'course_id' => Course::factory()->create(['course_level_id' => $level->id])->id,
            'teacher_id' => $teacher->id,
            'day_of_week' => $day,
            'starts_at' => '09:00:00',
            'ends_at' => '10:00:00',
            'group_id' => null,
        ]);

        $this->englishSchedule = $makeSchedule($this->englishB1, 1);
        $this->portugueseSchedule = $makeSchedule($this->portugueseA2, 2);
        $this->otherLevelSchedule = $makeSchedule($this->englishB2, 3);

        $this->student = Student::factory()->withLevels([$this->englishB1, $this->portugueseA2])->create();
        StudentAvailability::factory()->create([
            'student_id' => $this->student->id, 'day_of_week' => 1, 'start_time' => '08:00:00', 'end_time' => '20:00:00',
        ]);

        $this->service = app(EnrollmentService::class);
    });

    it('offers the schedules of every level of the student, each with its level', function () {
        $groups = collect($this->service->getCompatibleSchedules($this->student->id)['schedule_groups']);

        expect($groups->pluck('schedule_ids')->flatten()->sort()->values()->all())
            ->toBe([$this->englishSchedule->id, $this->portugueseSchedule->id])
            ->and($groups->pluck('course_level.track.name')->sort()->values()->all())->toBe(['Inglés', 'Portugués']);
    });

    it('lists the student for a schedule of each track, with the level of that track', function () {
        $forEnglish = collect($this->service->getEligibleStudents($this->englishSchedule->id))->firstWhere('id', $this->student->id);
        $forPortuguese = collect($this->service->getEligibleStudents($this->portugueseSchedule->id))->firstWhere('id', $this->student->id);

        expect($forEnglish['course_level']['name'])->toBe('B1')
            ->and($forPortuguese['course_level']['name'])->toBe('A2');
    });

    it('shows the level of the schedule track in its active enrollments', function () {
        $this->service->createEnrollments($this->student->id, [$this->englishSchedule->id]);

        $schedule = $this->service->getSchedules(['course_ids' => [$this->englishSchedule->course_id]])->first();

        expect($schedule['active_enrollments'][0]['student']['course_level']['name'])->toBe('B1');
    });
});
