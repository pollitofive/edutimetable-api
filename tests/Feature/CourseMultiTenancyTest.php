<?php

use App\Models\Business;
use App\Models\Course;
use App\Models\User;
use App\Services\CurrentBusiness;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    // Create two separate businesses
    $this->businessA = Business::factory()->create(['name' => 'Business A']);
    $this->businessB = Business::factory()->create(['name' => 'Business B']);

    // Create users for each business
    $this->userA = User::factory()->create([
        'email' => 'user-a@test.com',
        'default_business_id' => $this->businessA->id,
    ]);

    $this->userB = User::factory()->create([
        'email' => 'user-b@test.com',
        'default_business_id' => $this->businessB->id,
    ]);

    // Attach users to their respective businesses with owner role
    $this->businessA->users()->attach($this->userA->id, ['role' => 'owner']);
    $this->businessB->users()->attach($this->userB->id, ['role' => 'owner']);
});

it('isolates courses list by business scope', function () {
    // Set context to business A
    app(CurrentBusiness::class)->setId($this->businessA->id);

    // Create course in business A
    $courseA = Course::factory()->create([
        'name' => 'Mathematics A',
    ]);

    // Set context to business B
    app(CurrentBusiness::class)->setId($this->businessB->id);

    // Create course in business B
    $courseB = Course::factory()->create([
        'name' => 'Physics B',
    ]);

    // Query as business A - should only see course A
    app(CurrentBusiness::class)->setId($this->businessA->id);
    $coursesInA = Course::all();

    expect($coursesInA)->toHaveCount(1);
    expect($coursesInA->first()->id)->toBe($courseA->id);
    expect($coursesInA->first()->name)->toBe('Mathematics A');

    // Query as business B - should only see course B
    app(CurrentBusiness::class)->setId($this->businessB->id);
    $coursesInB = Course::all();

    expect($coursesInB)->toHaveCount(1);
    expect($coursesInB->first()->id)->toBe($courseB->id);
    expect($coursesInB->first()->name)->toBe('Physics B');
});

it('automatically sets business_id when creating course', function () {
    // Set context to business A
    app(CurrentBusiness::class)->setId($this->businessA->id);

    // Create course without specifying business_id
    $course = Course::factory()->create([
        'name' => 'Auto Course',
    ]);

    // Refresh to get actual DB values
    $course->refresh();

    // Assert business_id was set automatically
    expect($course->business_id)->toBe($this->businessA->id);

    // Verify in database
    $this->assertDatabaseHas('courses', [
        'id' => $course->id,
        'business_id' => $this->businessA->id,
        'name' => 'Auto Course',
    ]);
});

it('prevents updating course from different business (cross-tenant protection)', function () {
    // Create course in business A
    app(CurrentBusiness::class)->setId($this->businessA->id);
    $courseA = Course::factory()->create([
        'name' => 'Course A',
    ]);

    // Try to update from business B context
    app(CurrentBusiness::class)->setId($this->businessB->id);

    // Should not find the course due to global scope
    $foundCourse = Course::find($courseA->id);
    expect($foundCourse)->toBeNull();

    // Attempting to update via query builder should affect 0 rows
    $affectedRows = Course::where('id', $courseA->id)
        ->update(['name' => 'Hacked Name']);

    expect($affectedRows)->toBe(0);

    // Verify original data is unchanged
    app(CurrentBusiness::class)->setId($this->businessA->id);
    $courseA->refresh();
    expect($courseA->name)->toBe('Course A');
});

it('prevents deleting course from different business (cross-tenant protection)', function () {
    // Create course in business A
    app(CurrentBusiness::class)->setId($this->businessA->id);
    $courseA = Course::factory()->create([
        'name' => 'Course A',
    ]);

    $courseId = $courseA->id;

    // Try to delete from business B context
    app(CurrentBusiness::class)->setId($this->businessB->id);

    // Should not find the course due to global scope
    $foundCourse = Course::find($courseId);
    expect($foundCourse)->toBeNull();

    // Attempting to delete via query builder should affect 0 rows
    $deletedRows = Course::where('id', $courseId)->delete();
    expect($deletedRows)->toBe(0);

    // Verify course still exists in business A
    app(CurrentBusiness::class)->setId($this->businessA->id);
    $this->assertDatabaseHas('courses', [
        'id' => $courseId,
        'name' => 'Course A',
    ]);
});

it('allows same course name in different businesses (unique per business)', function () {
    $sameName = 'Mathematics 101';

    // Create course in business A with name
    app(CurrentBusiness::class)->setId($this->businessA->id);
    $courseA = Course::factory()->create([
        'name' => $sameName,
    ]);

    // Create course in business B with same name - should succeed
    app(CurrentBusiness::class)->setId($this->businessB->id);
    $courseB = Course::factory()->create([
        'name' => $sameName,
    ]);

    // Both should exist with same name but different business_id
    expect($courseA->name)->toBe($sameName);
    expect($courseB->name)->toBe($sameName);
    expect($courseA->business_id)->toBe($this->businessA->id);
    expect($courseB->business_id)->toBe($this->businessB->id);

    $this->assertDatabaseHas('courses', [
        'business_id' => $this->businessA->id,
        'name' => $sameName,
    ]);

    $this->assertDatabaseHas('courses', [
        'business_id' => $this->businessB->id,
        'name' => $sameName,
    ]);
});

it('prevents duplicate course name within same business', function () {
    $sameName = 'Physics 101';

    // Set context to business A
    app(CurrentBusiness::class)->setId($this->businessA->id);

    // Create first course with name
    Course::factory()->create([
        'name' => $sameName,
    ]);

    // Try to create second course with same name in same business
    // Should throw database exception due to unique constraint
    expect(fn () => Course::factory()->create([
        'name' => $sameName,
    ]))->toThrow(\Illuminate\Database\QueryException::class);
});

it('prevents changing business_id on update', function () {
    // Create course in business A
    app(CurrentBusiness::class)->setId($this->businessA->id);
    $course = Course::factory()->create([
        'name' => 'Course A',
    ]);

    $originalBusinessId = $course->business_id;

    // Try to update business_id to business B
    $course->update(['business_id' => $this->businessB->id]);

    // Refresh from database
    $course->refresh();

    // business_id should remain unchanged
    expect($course->business_id)->toBe($originalBusinessId);
    expect($course->business_id)->toBe($this->businessA->id);
    expect($course->business_id)->not->toBe($this->businessB->id);
});

it('business_id is not in fillable array', function () {
    $course = new Course;

    expect($course->getFillable())->not->toContain('business_id');
    expect($course->getFillable())->toContain('name');
});
