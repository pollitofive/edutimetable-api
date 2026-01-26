<?php

use App\Models\Business;
use App\Models\CourseLevel;
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

it('isolates course levels list by business scope', function () {
    // Set context to business A
    app(CurrentBusiness::class)->setId($this->businessA->id);

    // Create course level in business A
    $levelA = CourseLevel::factory()->create([
        'track' => 'English',
        'name' => 'Beginner A',
        'slug' => 'beginner-a',
        'sort_order' => 10,
    ]);

    // Set context to business B
    app(CurrentBusiness::class)->setId($this->businessB->id);

    // Create course level in business B
    $levelB = CourseLevel::factory()->create([
        'track' => 'English',
        'name' => 'Beginner B',
        'slug' => 'beginner-b',
        'sort_order' => 10,
    ]);

    // Query as business A - should only see level A
    app(CurrentBusiness::class)->setId($this->businessA->id);
    $levelsInA = CourseLevel::all();

    expect($levelsInA)->toHaveCount(1);
    expect($levelsInA->first()->id)->toBe($levelA->id);
    expect($levelsInA->first()->name)->toBe('Beginner A');

    // Query as business B - should only see level B
    app(CurrentBusiness::class)->setId($this->businessB->id);
    $levelsInB = CourseLevel::all();

    expect($levelsInB)->toHaveCount(1);
    expect($levelsInB->first()->id)->toBe($levelB->id);
    expect($levelsInB->first()->name)->toBe('Beginner B');
});

it('automatically sets business_id when creating course level', function () {
    // Set context to business A
    app(CurrentBusiness::class)->setId($this->businessA->id);

    // Create course level without specifying business_id
    $level = CourseLevel::factory()->create([
        'track' => 'English',
        'name' => 'Intermediate',
        'slug' => 'intermediate',
        'sort_order' => 20,
    ]);

    // Refresh to get actual DB values
    $level->refresh();

    // Assert business_id was set automatically
    expect($level->business_id)->toBe($this->businessA->id);

    // Verify in database
    $this->assertDatabaseHas('course_levels', [
        'id' => $level->id,
        'business_id' => $this->businessA->id,
        'track' => 'English',
        'name' => 'Intermediate',
    ]);
});

it('prevents updating course level from different business (cross-tenant protection)', function () {
    // Create course level in business A
    app(CurrentBusiness::class)->setId($this->businessA->id);
    $levelA = CourseLevel::factory()->create([
        'track' => 'English',
        'name' => 'Advanced A',
        'slug' => 'advanced-a',
        'sort_order' => 30,
    ]);

    // Try to update from business B context
    app(CurrentBusiness::class)->setId($this->businessB->id);

    // Should not find the course level due to global scope
    $foundLevel = CourseLevel::find($levelA->id);
    expect($foundLevel)->toBeNull();

    // Attempting to update via query builder should affect 0 rows
    $affectedRows = CourseLevel::where('id', $levelA->id)
        ->update(['name' => 'Hacked Name']);

    expect($affectedRows)->toBe(0);

    // Verify original data is unchanged
    app(CurrentBusiness::class)->setId($this->businessA->id);
    $levelA->refresh();
    expect($levelA->name)->toBe('Advanced A');
});

it('prevents deleting course level from different business (cross-tenant protection)', function () {
    // Create course level in business A
    app(CurrentBusiness::class)->setId($this->businessA->id);
    $levelA = CourseLevel::factory()->create([
        'track' => 'Portuguese',
        'name' => 'Upper A',
        'slug' => 'upper-a',
        'sort_order' => 40,
    ]);

    $levelId = $levelA->id;

    // Try to delete from business B context
    app(CurrentBusiness::class)->setId($this->businessB->id);

    // Should not find the course level due to global scope
    $foundLevel = CourseLevel::find($levelId);
    expect($foundLevel)->toBeNull();

    // Attempting to delete via query builder should affect 0 rows
    $deletedRows = CourseLevel::where('id', $levelId)->delete();
    expect($deletedRows)->toBe(0);

    // Verify course level still exists in business A
    app(CurrentBusiness::class)->setId($this->businessA->id);
    $this->assertDatabaseHas('course_levels', [
        'id' => $levelId,
        'name' => 'Upper A',
    ]);
});

it('allows same slug in different businesses (unique per business and track)', function () {
    $sameSlug = 'beginner';

    // Create course level in business A with slug
    app(CurrentBusiness::class)->setId($this->businessA->id);
    $levelA = CourseLevel::factory()->create([
        'track' => 'English',
        'name' => 'Beginner A',
        'slug' => $sameSlug,
        'sort_order' => 10,
    ]);

    // Create course level in business B with same slug - should succeed
    app(CurrentBusiness::class)->setId($this->businessB->id);
    $levelB = CourseLevel::factory()->create([
        'track' => 'English',
        'name' => 'Beginner B',
        'slug' => $sameSlug,
        'sort_order' => 10,
    ]);

    // Both should exist with same slug but different business_id
    expect($levelA->slug)->toBe($sameSlug);
    expect($levelB->slug)->toBe($sameSlug);
    expect($levelA->business_id)->toBe($this->businessA->id);
    expect($levelB->business_id)->toBe($this->businessB->id);

    $this->assertDatabaseHas('course_levels', [
        'business_id' => $this->businessA->id,
        'slug' => $sameSlug,
    ]);

    $this->assertDatabaseHas('course_levels', [
        'business_id' => $this->businessB->id,
        'slug' => $sameSlug,
    ]);
});

it('prevents duplicate slug within same business and track', function () {
    $sameSlug = 'intermediate';

    // Set context to business A
    app(CurrentBusiness::class)->setId($this->businessA->id);

    // Create first course level with slug
    CourseLevel::factory()->create([
        'track' => 'English',
        'name' => 'Intermediate',
        'slug' => $sameSlug,
        'sort_order' => 20,
    ]);

    // Try to create second course level with same slug in same business and track
    // Should throw database exception due to unique constraint
    expect(fn () => CourseLevel::factory()->create([
        'track' => 'English',
        'name' => 'Intermediate 2',
        'slug' => $sameSlug,
        'sort_order' => 21,
    ]))->toThrow(\Illuminate\Database\QueryException::class);
});

it('prevents changing business_id on update', function () {
    // Create course level in business A
    app(CurrentBusiness::class)->setId($this->businessA->id);
    $level = CourseLevel::factory()->create([
        'track' => 'English',
        'name' => 'Pre-Intermediate',
        'slug' => 'pre-intermediate',
        'sort_order' => 15,
    ]);

    $originalBusinessId = $level->business_id;

    // Try to update business_id to business B
    $level->update(['business_id' => $this->businessB->id]);

    // Refresh from database
    $level->refresh();

    // business_id should remain unchanged
    expect($level->business_id)->toBe($originalBusinessId);
    expect($level->business_id)->toBe($this->businessA->id);
    expect($level->business_id)->not->toBe($this->businessB->id);
});

it('business_id is not in fillable array', function () {
    $level = new CourseLevel;

    expect($level->getFillable())->not->toContain('business_id');
    expect($level->getFillable())->toContain('track');
    expect($level->getFillable())->toContain('name');
    expect($level->getFillable())->toContain('slug');
    expect($level->getFillable())->toContain('sort_order');
    expect($level->getFillable())->toContain('next_level_id');
});

// NOTE: Cross-tenant validation for next_level_id should be done at application level
// Database-level composite FK constraints are not reliable across MySQL and SQLite
it('allows setting next_level_id but should validate at app level', function () {
    // Create two levels in business A
    app(CurrentBusiness::class)->setId($this->businessA->id);
    $levelA1 = CourseLevel::factory()->create([
        'track' => 'English',
        'name' => 'Beginner',
        'slug' => 'beginner',
        'sort_order' => 10,
    ]);

    $levelA2 = CourseLevel::factory()->create([
        'track' => 'English',
        'name' => 'Intermediate',
        'slug' => 'intermediate',
        'sort_order' => 20,
    ]);

    // Setting next_level_id within same business should work
    $levelA1->update(['next_level_id' => $levelA2->id]);
    expect($levelA1->fresh()->next_level_id)->toBe($levelA2->id);

    // Note: Validation to prevent cross-tenant next_level_id assignment
    // should be implemented in the application layer (controller/service)
});
