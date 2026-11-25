<?php

use App\Models\Business;
use App\Models\Course;
use App\Models\CourseLevel;
use App\Models\Schedule;
use App\Models\Student;
use App\Models\StudentAvailability;
use App\Models\StudentEnrollment;
use App\Models\Teacher;
use App\Models\Track;
use App\Services\CurrentBusiness;
use GraphQL\Language\AST\ObjectTypeDefinitionNode;
use GraphQL\Language\Parser;

/**
 * Creates at least one record of every model with BelongsToBusiness inside $business
 * (enrollment -> student/schedule -> course/teacher -> course level -> track, plus an availability).
 */
function seedTenantModels(Business $business): void
{
    app(CurrentBusiness::class)->setId($business->id);

    StudentEnrollment::factory()->create();
    StudentAvailability::factory()->create();
}

beforeEach(function () {
    $this->businessA = Business::factory()->create(['name' => 'Business A']);
    $this->businessB = Business::factory()->create(['name' => 'Business B']);

    seedTenantModels($this->businessA);
    seedTenantModels($this->businessB);

    app(CurrentBusiness::class)->clear();
});

dataset('tenant models', [
    'Course' => [Course::class],
    'CourseLevel' => [CourseLevel::class],
    'Schedule' => [Schedule::class],
    'Student' => [Student::class],
    'StudentAvailability' => [StudentAvailability::class],
    'StudentEnrollment' => [StudentEnrollment::class],
    'Teacher' => [Teacher::class],
    'Track' => [Track::class],
]);

it('returns nothing without a business context', function (string $model) {
    $anyId = $model::withoutGlobalScope('business')->value('id');

    expect($anyId)->not->toBeNull()
        ->and($model::count())->toBe(0)
        ->and($model::query()->get())->toBeEmpty()
        ->and($model::find($anyId))->toBeNull();
})->with('tenant models');

it('sees every business without context only when the scope is removed explicitly', function (string $model) {
    $countA = $model::withoutGlobalScope('business')->where('business_id', $this->businessA->id)->count();
    $countB = $model::withoutGlobalScope('business')->where('business_id', $this->businessB->id)->count();

    expect($countA)->toBeGreaterThan(0)
        ->and($countB)->toBeGreaterThan(0)
        ->and($model::withoutGlobalScope('business')->count())->toBe($countA + $countB);
})->with('tenant models');

it('only sees the current business records when the context is set', function (string $model) {
    app(CurrentBusiness::class)->setId($this->businessA->id);

    $records = $model::all();
    $countA = $model::withoutGlobalScope('business')->where('business_id', $this->businessA->id)->count();

    expect($records)->toHaveCount($countA)
        ->and($records->pluck('business_id')->unique()->values()->all())->toBe([$this->businessA->id]);
})->with('tenant models');

it('throws when creating without business context nor explicit business_id', function () {
    $before = Teacher::withoutGlobalScope('business')->count();

    expect(fn () => Teacher::create([
        'name' => 'Orphan Teacher',
        'email' => 'orphan@example.com',
    ]))->toThrow(LogicException::class, 'Cannot create Teacher without a business context');

    expect(Teacher::withoutGlobalScope('business')->count())->toBe($before)
        ->and(Teacher::withoutGlobalScope('business')->where('email', 'orphan@example.com')->exists())->toBeFalse();
});

it('keeps an explicit business_id when creating without business context', function () {
    $teacher = Teacher::factory()->create(['business_id' => $this->businessB->id]);

    expect($teacher->business_id)->toBe($this->businessB->id)
        ->and(Teacher::withoutGlobalScope('business')->find($teacher->id)->business_id)->toBe($this->businessB->id);
});

it('backfills group_id for schedules of every business without business context', function () {
    Schedule::withoutGlobalScope('business')->update(['group_id' => null]);

    expect(Schedule::withoutGlobalScope('business')->whereNull('group_id')->distinct()->pluck('business_id')->sort()->values()->all())
        ->toBe([$this->businessA->id, $this->businessB->id]);

    $this->artisan('schedules:backfill-group-ids')->assertExitCode(0);

    expect(Schedule::withoutGlobalScope('business')->whereNull('group_id')->count())->toBe(0);
});

it('guards every root query in the GraphQL schema', function () {
    $document = Parser::parse(file_get_contents(base_path('graphql/schema.graphql')));

    $query = collect($document->definitions)->first(
        fn ($definition) => $definition instanceof ObjectTypeDefinitionNode && $definition->name->value === 'Query'
    );

    $unguarded = collect($query->fields)
        ->filter(fn ($field) => ! collect($field->directives)->contains(fn ($directive) => $directive->name->value === 'guard'))
        ->map(fn ($field) => $field->name->value)
        ->values()
        ->all();

    expect($unguarded)->toBe([]);
});
