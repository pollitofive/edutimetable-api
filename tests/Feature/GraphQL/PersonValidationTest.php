<?php

namespace Tests\Feature\GraphQL;

use App\Models\Business;
use App\Models\CourseLevel;
use App\Models\Student;
use App\Models\Teacher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

beforeEach(function () {
    $tenancy = setupTenancy();
    $this->user = $tenancy->user;
    $this->business = $tenancy->business;
    Sanctum::actingAs($this->user);
});

function createStudentMutation(string $email, int|string $levelId): array
{
    return ['query' => "
        mutation {
            createStudent(input: { name: \"Ana\", email: \"{$email}\", course_level_id: \"{$levelId}\" }) { id email }
        }
    "];
}

function updateEmailMutation(string $type, int $id, string $email): array
{
    $field = 'update'.ucfirst($type);

    return ['query' => "
        mutation {
            {$field}(id: {$id}, input: { email: \"{$email}\" }) { id email }
        }
    "];
}

it('rejects an invalid email when creating a student', function () {
    $level = CourseLevel::factory()->create();

    $this->postGraphQL(createStudentMutation('no-es-un-email', $level->id))
        ->assertGraphQLValidationKeys(['input.email']);

    expect(Student::count())->toBe(0);
});

it('rejects an email already used by another student of the business', function () {
    $existing = Student::factory()->create(['email' => 'ana@example.com']);

    $this->postGraphQL(createStudentMutation('ana@example.com', $existing->course_level_id))
        ->assertGraphQLValidationKeys(['input.email']);

    expect(Student::count())->toBe(1);
});

it('allows an email used by a student of another business', function () {
    $other = Business::factory()->create();
    $otherLevel = CourseLevel::factory()->create(['business_id' => $other->id]);
    Student::factory()->create(['email' => 'ana@example.com', 'business_id' => $other->id, 'course_level_id' => $otherLevel->id]);
    $level = CourseLevel::factory()->create();

    $this->postGraphQL(createStudentMutation('ana@example.com', $level->id))
        ->assertGraphQLErrorFree()
        ->assertJsonPath('data.createStudent.email', 'ana@example.com');
});

it('rejects a course level of another business', function () {
    $other = Business::factory()->create();
    $foreignLevel = CourseLevel::factory()->create(['business_id' => $other->id]);

    $this->postGraphQL(createStudentMutation('ana@example.com', $foreignLevel->id))
        ->assertGraphQLValidationKeys(['input.course_level_id']);
});

it('rejects a teacher with a repeated email', function () {
    Teacher::factory()->create(['email' => 'profe@example.com']);

    $this->postGraphQL(['query' => '
        mutation { createTeacher(input: { name: "Otro", email: "profe@example.com" }) { id } }
    '])->assertGraphQLValidationKeys(['input.email']);

    expect(Teacher::count())->toBe(1);
});

it('lets a person keep their own email when updating', function (string $type, string $model) {
    $person = $model::factory()->create(['email' => 'mismo@example.com']);

    $this->postGraphQL(updateEmailMutation($type, $person->id, 'mismo@example.com'))
        ->assertGraphQLErrorFree();
})->with([
    'student' => ['student', Student::class],
    'teacher' => ['teacher', Teacher::class],
]);

it('rejects updating to the email of another person', function (string $type, string $model) {
    $model::factory()->create(['email' => 'tomado@example.com']);
    $person = $model::factory()->create(['email' => 'propio@example.com']);

    $this->postGraphQL(updateEmailMutation($type, $person->id, 'tomado@example.com'))
        ->assertGraphQLValidationKeys(['input.email']);

    expect($person->fresh()->email)->toBe('propio@example.com');
})->with([
    'student' => ['student', Student::class],
    'teacher' => ['teacher', Teacher::class],
]);

it('returns the validation messages in the language of the request', function () {
    $existing = Student::factory()->create(['email' => 'ana@example.com']);

    $this->postGraphQL(createStudentMutation('ana@example.com', $existing->course_level_id), ['Accept-Language' => 'es'])
        ->assertGraphQLValidationError('input.email', 'El email ya lo usa otra persona.');

    $this->postGraphQL(updateEmailMutation('student', $existing->id, 'no-es-un-email'), ['Accept-Language' => 'es'])
        ->assertGraphQLValidationError('input.email', 'El email no es válido.');
});
