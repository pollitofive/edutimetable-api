<?php

use App\Models\Business;
use App\Models\Teacher;
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

it('isolates teachers list by business scope', function () {
    // Set context to business A
    app(CurrentBusiness::class)->setId($this->businessA->id);

    // Create teacher in business A
    $teacherA = Teacher::factory()->create([
        'name' => 'Teacher A',
        'email' => 'teacher-a@test.com',
    ]);

    // Set context to business B
    app(CurrentBusiness::class)->setId($this->businessB->id);

    // Create teacher in business B
    $teacherB = Teacher::factory()->create([
        'name' => 'Teacher B',
        'email' => 'teacher-b@test.com',
    ]);

    // Query as business A - should only see teacher A
    app(CurrentBusiness::class)->setId($this->businessA->id);
    $teachersInA = Teacher::all();

    expect($teachersInA)->toHaveCount(1);
    expect($teachersInA->first()->id)->toBe($teacherA->id);
    expect($teachersInA->first()->name)->toBe('Teacher A');

    // Query as business B - should only see teacher B
    app(CurrentBusiness::class)->setId($this->businessB->id);
    $teachersInB = Teacher::all();

    expect($teachersInB)->toHaveCount(1);
    expect($teachersInB->first()->id)->toBe($teacherB->id);
    expect($teachersInB->first()->name)->toBe('Teacher B');
});

it('automatically sets business_id when creating teacher', function () {
    // Set context to business A
    app(CurrentBusiness::class)->setId($this->businessA->id);

    // Create teacher without specifying business_id
    $teacher = Teacher::factory()->create([
        'name' => 'Auto Teacher',
        'email' => 'auto@test.com',
    ]);

    // Refresh to get actual DB values
    $teacher->refresh();

    // Assert business_id was set automatically
    expect($teacher->business_id)->toBe($this->businessA->id);

    // Verify in database
    $this->assertDatabaseHas('teachers', [
        'id' => $teacher->id,
        'business_id' => $this->businessA->id,
        'email' => 'auto@test.com',
    ]);
});

it('prevents updating teacher from different business (cross-tenant protection)', function () {
    // Create teacher in business A
    app(CurrentBusiness::class)->setId($this->businessA->id);
    $teacherA = Teacher::factory()->create([
        'name' => 'Teacher A',
        'email' => 'teacher-a@test.com',
    ]);

    // Try to update from business B context
    app(CurrentBusiness::class)->setId($this->businessB->id);

    // Should not find the teacher due to global scope
    $foundTeacher = Teacher::find($teacherA->id);
    expect($foundTeacher)->toBeNull();

    // Attempting to update via query builder should affect 0 rows
    $affectedRows = Teacher::where('id', $teacherA->id)
        ->update(['name' => 'Hacked Name']);

    expect($affectedRows)->toBe(0);

    // Verify original data is unchanged
    app(CurrentBusiness::class)->setId($this->businessA->id);
    $teacherA->refresh();
    expect($teacherA->name)->toBe('Teacher A');
});

it('prevents deleting teacher from different business (cross-tenant protection)', function () {
    // Create teacher in business A
    app(CurrentBusiness::class)->setId($this->businessA->id);
    $teacherA = Teacher::factory()->create([
        'name' => 'Teacher A',
        'email' => 'teacher-a@test.com',
    ]);

    $teacherId = $teacherA->id;

    // Try to delete from business B context
    app(CurrentBusiness::class)->setId($this->businessB->id);

    // Should not find the teacher due to global scope
    $foundTeacher = Teacher::find($teacherId);
    expect($foundTeacher)->toBeNull();

    // Attempting to delete via query builder should affect 0 rows
    $deletedRows = Teacher::where('id', $teacherId)->delete();
    expect($deletedRows)->toBe(0);

    // Verify teacher still exists in business A
    app(CurrentBusiness::class)->setId($this->businessA->id);
    $this->assertDatabaseHas('teachers', [
        'id' => $teacherId,
        'name' => 'Teacher A',
    ]);
});

it('allows same email in different businesses (unique per business)', function () {
    $sameEmail = 'same@test.com';

    // Create teacher in business A with email
    app(CurrentBusiness::class)->setId($this->businessA->id);
    $teacherA = Teacher::factory()->create([
        'name' => 'Teacher A',
        'email' => $sameEmail,
    ]);

    // Create teacher in business B with same email - should succeed
    app(CurrentBusiness::class)->setId($this->businessB->id);
    $teacherB = Teacher::factory()->create([
        'name' => 'Teacher B',
        'email' => $sameEmail,
    ]);

    // Both should exist with same email but different business_id
    expect($teacherA->email)->toBe($sameEmail);
    expect($teacherB->email)->toBe($sameEmail);
    expect($teacherA->business_id)->toBe($this->businessA->id);
    expect($teacherB->business_id)->toBe($this->businessB->id);

    $this->assertDatabaseHas('teachers', [
        'business_id' => $this->businessA->id,
        'email' => $sameEmail,
    ]);

    $this->assertDatabaseHas('teachers', [
        'business_id' => $this->businessB->id,
        'email' => $sameEmail,
    ]);
});

it('prevents duplicate email within same business', function () {
    $sameEmail = 'duplicate@test.com';

    // Set context to business A
    app(CurrentBusiness::class)->setId($this->businessA->id);

    // Create first teacher with email
    Teacher::factory()->create([
        'name' => 'First Teacher',
        'email' => $sameEmail,
    ]);

    // Try to create second teacher with same email in same business
    // Should throw database exception due to unique constraint
    expect(fn () => Teacher::factory()->create([
        'name' => 'Second Teacher',
        'email' => $sameEmail,
    ]))->toThrow(\Illuminate\Database\QueryException::class);
});

it('prevents changing business_id on update', function () {
    // Create teacher in business A
    app(CurrentBusiness::class)->setId($this->businessA->id);
    $teacher = Teacher::factory()->create([
        'name' => 'Teacher A',
        'email' => 'teacher-a@test.com',
    ]);

    $originalBusinessId = $teacher->business_id;

    // Try to update business_id to business B
    $teacher->update(['business_id' => $this->businessB->id]);

    // Refresh from database
    $teacher->refresh();

    // business_id should remain unchanged
    expect($teacher->business_id)->toBe($originalBusinessId);
    expect($teacher->business_id)->toBe($this->businessA->id);
    expect($teacher->business_id)->not->toBe($this->businessB->id);
});

it('business_id is not in fillable array', function () {
    $teacher = new Teacher;

    expect($teacher->getFillable())->not->toContain('business_id');
    expect($teacher->getFillable())->toContain('name');
    expect($teacher->getFillable())->toContain('email');
});
