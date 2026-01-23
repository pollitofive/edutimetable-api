<?php

use App\Models\Business;
use App\Models\Student;
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

it('isolates students list by business scope', function () {
    // Set context to business A
    app(CurrentBusiness::class)->setId($this->businessA->id);

    // Create student in business A
    $studentA = Student::factory()->create([
        'name' => 'Student A',
        'email' => 'student-a@test.com',
        'code' => 'STU-A-001',
    ]);

    // Set context to business B
    app(CurrentBusiness::class)->setId($this->businessB->id);

    // Create student in business B
    $studentB = Student::factory()->create([
        'name' => 'Student B',
        'email' => 'student-b@test.com',
        'code' => 'STU-B-001',
    ]);

    // Query as business A - should only see student A
    app(CurrentBusiness::class)->setId($this->businessA->id);
    $studentsInA = Student::all();

    expect($studentsInA)->toHaveCount(1);
    expect($studentsInA->first()->id)->toBe($studentA->id);
    expect($studentsInA->first()->name)->toBe('Student A');

    // Query as business B - should only see student B
    app(CurrentBusiness::class)->setId($this->businessB->id);
    $studentsInB = Student::all();

    expect($studentsInB)->toHaveCount(1);
    expect($studentsInB->first()->id)->toBe($studentB->id);
    expect($studentsInB->first()->name)->toBe('Student B');
});

it('automatically sets business_id when creating student', function () {
    // Set context to business A
    app(CurrentBusiness::class)->setId($this->businessA->id);

    // Create student without specifying business_id
    $student = Student::factory()->create([
        'name' => 'Auto Student',
        'email' => 'auto@test.com',
        'code' => 'AUTO-001',
    ]);

    // Refresh to get actual DB values
    $student->refresh();

    // Assert business_id was set automatically
    expect($student->business_id)->toBe($this->businessA->id);

    // Verify in database
    $this->assertDatabaseHas('students', [
        'id' => $student->id,
        'business_id' => $this->businessA->id,
        'email' => 'auto@test.com',
        'code' => 'AUTO-001',
    ]);
});

it('prevents updating student from different business (cross-tenant protection)', function () {
    // Create student in business A
    app(CurrentBusiness::class)->setId($this->businessA->id);
    $studentA = Student::factory()->create([
        'name' => 'Student A',
        'email' => 'student-a@test.com',
        'code' => 'STU-A-001',
    ]);

    // Try to update from business B context
    app(CurrentBusiness::class)->setId($this->businessB->id);

    // Should not find the student due to global scope
    $foundStudent = Student::find($studentA->id);
    expect($foundStudent)->toBeNull();

    // Attempting to update via query builder should affect 0 rows
    $affectedRows = Student::where('id', $studentA->id)
        ->update(['name' => 'Hacked Name']);

    expect($affectedRows)->toBe(0);

    // Verify original data is unchanged
    app(CurrentBusiness::class)->setId($this->businessA->id);
    $studentA->refresh();
    expect($studentA->name)->toBe('Student A');
});

it('prevents deleting student from different business (cross-tenant protection)', function () {
    // Create student in business A
    app(CurrentBusiness::class)->setId($this->businessA->id);
    $studentA = Student::factory()->create([
        'name' => 'Student A',
        'email' => 'student-a@test.com',
        'code' => 'STU-A-001',
    ]);

    $studentId = $studentA->id;

    // Try to delete from business B context
    app(CurrentBusiness::class)->setId($this->businessB->id);

    // Should not find the student due to global scope
    $foundStudent = Student::find($studentId);
    expect($foundStudent)->toBeNull();

    // Attempting to delete via query builder should affect 0 rows
    $deletedRows = Student::where('id', $studentId)->delete();
    expect($deletedRows)->toBe(0);

    // Verify student still exists in business A
    app(CurrentBusiness::class)->setId($this->businessA->id);
    $this->assertDatabaseHas('students', [
        'id' => $studentId,
        'name' => 'Student A',
    ]);
});

it('allows same email in different businesses (unique per business)', function () {
    $sameEmail = 'same@test.com';

    // Create student in business A with email
    app(CurrentBusiness::class)->setId($this->businessA->id);
    $studentA = Student::factory()->create([
        'name' => 'Student A',
        'email' => $sameEmail,
        'code' => 'STU-A-001',
    ]);

    // Create student in business B with same email - should succeed
    app(CurrentBusiness::class)->setId($this->businessB->id);
    $studentB = Student::factory()->create([
        'name' => 'Student B',
        'email' => $sameEmail,
        'code' => 'STU-B-001',
    ]);

    // Both should exist with same email but different business_id
    expect($studentA->email)->toBe($sameEmail);
    expect($studentB->email)->toBe($sameEmail);
    expect($studentA->business_id)->toBe($this->businessA->id);
    expect($studentB->business_id)->toBe($this->businessB->id);

    $this->assertDatabaseHas('students', [
        'business_id' => $this->businessA->id,
        'email' => $sameEmail,
    ]);

    $this->assertDatabaseHas('students', [
        'business_id' => $this->businessB->id,
        'email' => $sameEmail,
    ]);
});

it('allows same code in different businesses (unique per business)', function () {
    $sameCode = 'STU-001';

    // Create student in business A with code
    app(CurrentBusiness::class)->setId($this->businessA->id);
    $studentA = Student::factory()->create([
        'name' => 'Student A',
        'email' => 'student-a@test.com',
        'code' => $sameCode,
    ]);

    // Create student in business B with same code - should succeed
    app(CurrentBusiness::class)->setId($this->businessB->id);
    $studentB = Student::factory()->create([
        'name' => 'Student B',
        'email' => 'student-b@test.com',
        'code' => $sameCode,
    ]);

    // Both should exist with same code but different business_id
    expect($studentA->code)->toBe($sameCode);
    expect($studentB->code)->toBe($sameCode);
    expect($studentA->business_id)->toBe($this->businessA->id);
    expect($studentB->business_id)->toBe($this->businessB->id);

    $this->assertDatabaseHas('students', [
        'business_id' => $this->businessA->id,
        'code' => $sameCode,
    ]);

    $this->assertDatabaseHas('students', [
        'business_id' => $this->businessB->id,
        'code' => $sameCode,
    ]);
});

it('prevents duplicate email within same business', function () {
    $sameEmail = 'duplicate@test.com';

    // Set context to business A
    app(CurrentBusiness::class)->setId($this->businessA->id);

    // Create first student with email
    Student::factory()->create([
        'name' => 'First Student',
        'email' => $sameEmail,
        'code' => 'STU-001',
    ]);

    // Try to create second student with same email in same business
    // Should throw database exception due to unique constraint
    expect(fn () => Student::factory()->create([
        'name' => 'Second Student',
        'email' => $sameEmail,
        'code' => 'STU-002',
    ]))->toThrow(\Illuminate\Database\QueryException::class);
});

it('prevents duplicate code within same business', function () {
    $sameCode = 'STU-001';

    // Set context to business A
    app(CurrentBusiness::class)->setId($this->businessA->id);

    // Create first student with code
    Student::factory()->create([
        'name' => 'First Student',
        'email' => 'first@test.com',
        'code' => $sameCode,
    ]);

    // Try to create second student with same code in same business
    // Should throw database exception due to unique constraint
    expect(fn () => Student::factory()->create([
        'name' => 'Second Student',
        'email' => 'second@test.com',
        'code' => $sameCode,
    ]))->toThrow(\Illuminate\Database\QueryException::class);
});

it('prevents changing business_id on update', function () {
    // Create student in business A
    app(CurrentBusiness::class)->setId($this->businessA->id);
    $student = Student::factory()->create([
        'name' => 'Student A',
        'email' => 'student-a@test.com',
        'code' => 'STU-A-001',
    ]);

    $originalBusinessId = $student->business_id;

    // Try to update business_id to business B
    $student->update(['business_id' => $this->businessB->id]);

    // Refresh from database
    $student->refresh();

    // business_id should remain unchanged
    expect($student->business_id)->toBe($originalBusinessId);
    expect($student->business_id)->toBe($this->businessA->id);
    expect($student->business_id)->not->toBe($this->businessB->id);
});

it('business_id is not in fillable array', function () {
    $student = new Student;

    expect($student->getFillable())->not->toContain('business_id');
    expect($student->getFillable())->toContain('name');
    expect($student->getFillable())->toContain('email');
    expect($student->getFillable())->toContain('code');
});
