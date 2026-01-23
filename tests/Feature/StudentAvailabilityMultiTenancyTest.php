<?php

use App\Models\Business;
use App\Models\Student;
use App\Models\StudentAvailability;
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

it('isolates student availabilities by business scope', function () {
    // Set context to business A
    app(CurrentBusiness::class)->setId($this->businessA->id);

    // Create student in business A
    $studentA = Student::factory()->create([
        'name' => 'Student A',
        'email' => 'student-a@test.com',
        'code' => 'STU-A-001',
    ]);

    // Create availability for student A
    $availabilityA = StudentAvailability::create([
        'student_id' => $studentA->id,
        'day_of_week' => 1,
        'start_time' => '08:00:00',
        'end_time' => '10:00:00',
    ]);

    // Set context to business B
    app(CurrentBusiness::class)->setId($this->businessB->id);

    // Create student in business B
    $studentB = Student::factory()->create([
        'name' => 'Student B',
        'email' => 'student-b@test.com',
        'code' => 'STU-B-001',
    ]);

    // Create availability for student B
    $availabilityB = StudentAvailability::create([
        'student_id' => $studentB->id,
        'day_of_week' => 1,
        'start_time' => '08:00:00',
        'end_time' => '10:00:00',
    ]);

    // Query as business A - should only see availability A
    app(CurrentBusiness::class)->setId($this->businessA->id);
    $availabilitiesInA = StudentAvailability::all();

    expect($availabilitiesInA)->toHaveCount(1);
    expect($availabilitiesInA->first()->id)->toBe($availabilityA->id);
    expect($availabilitiesInA->first()->student_id)->toBe($studentA->id);

    // Query as business B - should only see availability B
    app(CurrentBusiness::class)->setId($this->businessB->id);
    $availabilitiesInB = StudentAvailability::all();

    expect($availabilitiesInB)->toHaveCount(1);
    expect($availabilitiesInB->first()->id)->toBe($availabilityB->id);
    expect($availabilitiesInB->first()->student_id)->toBe($studentB->id);
});

it('automatically sets business_id when creating student availability', function () {
    // Set context to business A
    app(CurrentBusiness::class)->setId($this->businessA->id);

    // Create student
    $student = Student::factory()->create([
        'name' => 'Test Student',
        'email' => 'test@test.com',
        'code' => 'TEST-001',
    ]);

    // Create availability without specifying business_id
    $availability = StudentAvailability::create([
        'student_id' => $student->id,
        'day_of_week' => 1,
        'start_time' => '09:00:00',
        'end_time' => '11:00:00',
    ]);

    // Refresh to get actual DB values
    $availability->refresh();

    // Assert business_id was set automatically
    expect($availability->business_id)->toBe($this->businessA->id);

    // Verify in database
    $this->assertDatabaseHas('student_availabilities', [
        'id' => $availability->id,
        'business_id' => $this->businessA->id,
        'student_id' => $student->id,
    ]);
});

it('prevents updating availability from different business (cross-tenant protection)', function () {
    // Create availability in business A
    app(CurrentBusiness::class)->setId($this->businessA->id);

    $student = Student::factory()->create([
        'name' => 'Student A',
        'email' => 'student-a@test.com',
        'code' => 'STU-A-001',
    ]);

    $availability = StudentAvailability::create([
        'student_id' => $student->id,
        'day_of_week' => 1,
        'start_time' => '08:00:00',
        'end_time' => '10:00:00',
    ]);

    // Try to update from business B context
    app(CurrentBusiness::class)->setId($this->businessB->id);

    // Should not find the availability due to global scope
    $foundAvailability = StudentAvailability::find($availability->id);
    expect($foundAvailability)->toBeNull();

    // Attempting to update via query builder should affect 0 rows
    $affectedRows = StudentAvailability::where('id', $availability->id)
        ->update(['start_time' => '07:00:00']);

    expect($affectedRows)->toBe(0);

    // Verify original data is unchanged
    app(CurrentBusiness::class)->setId($this->businessA->id);
    $availability->refresh();
    expect($availability->start_time)->toBe('08:00:00');
});

it('prevents deleting availability from different business (cross-tenant protection)', function () {
    // Create availability in business A
    app(CurrentBusiness::class)->setId($this->businessA->id);

    $student = Student::factory()->create([
        'name' => 'Student A',
        'email' => 'student-a@test.com',
        'code' => 'STU-A-001',
    ]);

    $availability = StudentAvailability::create([
        'student_id' => $student->id,
        'day_of_week' => 1,
        'start_time' => '08:00:00',
        'end_time' => '10:00:00',
    ]);

    $availabilityId = $availability->id;

    // Try to delete from business B context
    app(CurrentBusiness::class)->setId($this->businessB->id);

    // Should not find the availability due to global scope
    $foundAvailability = StudentAvailability::find($availabilityId);
    expect($foundAvailability)->toBeNull();

    // Attempting to delete via query builder should affect 0 rows
    $deletedRows = StudentAvailability::where('id', $availabilityId)->delete();
    expect($deletedRows)->toBe(0);

    // Verify availability still exists in business A
    app(CurrentBusiness::class)->setId($this->businessA->id);
    $this->assertDatabaseHas('student_availabilities', [
        'id' => $availabilityId,
    ]);
});

it('allows same time slot for different students in different businesses', function () {
    // Create availability in business A
    app(CurrentBusiness::class)->setId($this->businessA->id);

    $studentA = Student::factory()->create([
        'name' => 'Student A',
        'email' => 'student-a@test.com',
        'code' => 'STU-A-001',
    ]);

    $availabilityA = StudentAvailability::create([
        'student_id' => $studentA->id,
        'day_of_week' => 1,
        'start_time' => '08:00:00',
        'end_time' => '10:00:00',
    ]);

    // Create availability in business B with same time slot - should succeed
    app(CurrentBusiness::class)->setId($this->businessB->id);

    $studentB = Student::factory()->create([
        'name' => 'Student B',
        'email' => 'student-b@test.com',
        'code' => 'STU-B-001',
    ]);

    $availabilityB = StudentAvailability::create([
        'student_id' => $studentB->id,
        'day_of_week' => 1,
        'start_time' => '08:00:00',
        'end_time' => '10:00:00',
    ]);

    // Both should exist with same time slot but different business_id
    expect($availabilityA->day_of_week)->toBe(1);
    expect($availabilityB->day_of_week)->toBe(1);
    expect($availabilityA->start_time)->toBe('08:00:00');
    expect($availabilityB->start_time)->toBe('08:00:00');
    expect($availabilityA->business_id)->toBe($this->businessA->id);
    expect($availabilityB->business_id)->toBe($this->businessB->id);
});

it('prevents duplicate availability within same business', function () {
    // Set context to business A
    app(CurrentBusiness::class)->setId($this->businessA->id);

    $student = Student::factory()->create([
        'name' => 'Test Student',
        'email' => 'test@test.com',
        'code' => 'TEST-001',
    ]);

    // Create first availability
    StudentAvailability::create([
        'student_id' => $student->id,
        'day_of_week' => 1,
        'start_time' => '08:00:00',
        'end_time' => '10:00:00',
    ]);

    // Try to create duplicate availability in same business
    // Should throw database exception due to unique constraint
    expect(fn () => StudentAvailability::create([
        'student_id' => $student->id,
        'day_of_week' => 1,
        'start_time' => '08:00:00',
        'end_time' => '10:00:00',
    ]))->toThrow(\Illuminate\Database\QueryException::class);
});

it('prevents changing business_id on update', function () {
    // Create availability in business A
    app(CurrentBusiness::class)->setId($this->businessA->id);

    $student = Student::factory()->create([
        'name' => 'Test Student',
        'email' => 'test@test.com',
        'code' => 'TEST-001',
    ]);

    $availability = StudentAvailability::create([
        'student_id' => $student->id,
        'day_of_week' => 1,
        'start_time' => '08:00:00',
        'end_time' => '10:00:00',
    ]);

    $originalBusinessId = $availability->business_id;

    // Try to update business_id to business B
    $availability->update(['business_id' => $this->businessB->id]);

    // Refresh from database
    $availability->refresh();

    // business_id should remain unchanged
    expect($availability->business_id)->toBe($originalBusinessId);
    expect($availability->business_id)->toBe($this->businessA->id);
    expect($availability->business_id)->not->toBe($this->businessB->id);
});

it('business_id is not in fillable array', function () {
    $availability = new StudentAvailability;

    expect($availability->getFillable())->not->toContain('business_id');
    expect($availability->getFillable())->toContain('student_id');
    expect($availability->getFillable())->toContain('day_of_week');
    expect($availability->getFillable())->toContain('start_time');
    expect($availability->getFillable())->toContain('end_time');
});

it('validates that start_time is before end_time', function () {
    app(CurrentBusiness::class)->setId($this->businessA->id);

    $student = Student::factory()->create([
        'name' => 'Test Student',
        'email' => 'test@test.com',
        'code' => 'TEST-001',
    ]);

    // Create valid availability
    $validAvailability = StudentAvailability::create([
        'student_id' => $student->id,
        'day_of_week' => 1,
        'start_time' => '08:00:00',
        'end_time' => '10:00:00',
    ]);

    expect($validAvailability->validateTimeRange())->toBeTrue();

    // Create invalid availability (start >= end)
    $invalidAvailability = new StudentAvailability([
        'student_id' => $student->id,
        'day_of_week' => 2,
        'start_time' => '10:00:00',
        'end_time' => '08:00:00',
    ]);

    expect($invalidAvailability->validateTimeRange())->toBeFalse();
});

it('detects overlapping time ranges for same student and day', function () {
    app(CurrentBusiness::class)->setId($this->businessA->id);

    $student = Student::factory()->create([
        'name' => 'Test Student',
        'email' => 'test@test.com',
        'code' => 'TEST-001',
    ]);

    // Create first availability: 08:00 - 10:00
    $availability1 = StudentAvailability::create([
        'student_id' => $student->id,
        'day_of_week' => 1,
        'start_time' => '08:00:00',
        'end_time' => '10:00:00',
    ]);

    // Test overlap: 09:00 - 11:00 (overlaps with 08:00-10:00)
    $testAvailability = new StudentAvailability([
        'student_id' => $student->id,
        'day_of_week' => 1,
        'business_id' => $this->businessA->id,
    ]);

    expect($testAvailability->overlaps('09:00:00', '11:00:00'))->toBeTrue();

    // Test overlap: 07:00 - 09:00 (overlaps with 08:00-10:00)
    expect($testAvailability->overlaps('07:00:00', '09:00:00'))->toBeTrue();

    // Test overlap: 07:00 - 12:00 (completely contains 08:00-10:00)
    expect($testAvailability->overlaps('07:00:00', '12:00:00'))->toBeTrue();

    // Test overlap: 08:30 - 09:30 (inside 08:00-10:00)
    expect($testAvailability->overlaps('08:30:00', '09:30:00'))->toBeTrue();
});

it('allows adjacent time ranges for same student and day', function () {
    app(CurrentBusiness::class)->setId($this->businessA->id);

    $student = Student::factory()->create([
        'name' => 'Test Student',
        'email' => 'test@test.com',
        'code' => 'TEST-001',
    ]);

    // Create first availability: 08:00 - 10:00
    StudentAvailability::create([
        'student_id' => $student->id,
        'day_of_week' => 1,
        'start_time' => '08:00:00',
        'end_time' => '10:00:00',
    ]);

    // Test adjacent (no overlap): 10:00 - 12:00
    $testAvailability = new StudentAvailability([
        'student_id' => $student->id,
        'day_of_week' => 1,
        'business_id' => $this->businessA->id,
    ]);

    expect($testAvailability->overlaps('10:00:00', '12:00:00'))->toBeFalse();

    // Should allow creating adjacent availability
    $adjacentAvailability = StudentAvailability::create([
        'student_id' => $student->id,
        'day_of_week' => 1,
        'start_time' => '10:00:00',
        'end_time' => '12:00:00',
    ]);

    expect($adjacentAvailability)->not->toBeNull();
    expect($adjacentAvailability->id)->not->toBeNull();
});

it('allows same time range for different days for same student', function () {
    app(CurrentBusiness::class)->setId($this->businessA->id);

    $student = Student::factory()->create([
        'name' => 'Test Student',
        'email' => 'test@test.com',
        'code' => 'TEST-001',
    ]);

    // Create availability for Monday
    $mondayAvailability = StudentAvailability::create([
        'student_id' => $student->id,
        'day_of_week' => 1,
        'start_time' => '08:00:00',
        'end_time' => '10:00:00',
    ]);

    // Create same time range for Tuesday - should succeed
    $tuesdayAvailability = StudentAvailability::create([
        'student_id' => $student->id,
        'day_of_week' => 2,
        'start_time' => '08:00:00',
        'end_time' => '10:00:00',
    ]);

    expect($mondayAvailability->id)->not->toBe($tuesdayAvailability->id);
    expect($mondayAvailability->start_time)->toBe($tuesdayAvailability->start_time);
    expect($mondayAvailability->day_of_week)->not->toBe($tuesdayAvailability->day_of_week);
});

it('excludes self when checking overlaps during update', function () {
    app(CurrentBusiness::class)->setId($this->businessA->id);

    $student = Student::factory()->create([
        'name' => 'Test Student',
        'email' => 'test@test.com',
        'code' => 'TEST-001',
    ]);

    // Create availability
    $availability = StudentAvailability::create([
        'student_id' => $student->id,
        'day_of_week' => 1,
        'start_time' => '08:00:00',
        'end_time' => '10:00:00',
    ]);

    // Should not overlap with itself when excluding self
    expect($availability->overlaps('08:00:00', '10:00:00', $availability->id))->toBeFalse();

    // But should overlap without exclusion
    expect($availability->overlaps('08:00:00', '10:00:00'))->toBeTrue();
});

it('isolates overlap detection by business', function () {
    app(CurrentBusiness::class)->setId($this->businessA->id);

    $studentA = Student::factory()->create([
        'name' => 'Student A',
        'email' => 'student-a@test.com',
        'code' => 'STU-A-001',
    ]);

    // Create availability in business A
    StudentAvailability::create([
        'student_id' => $studentA->id,
        'day_of_week' => 1,
        'start_time' => '08:00:00',
        'end_time' => '10:00:00',
    ]);

    // Switch to business B
    app(CurrentBusiness::class)->setId($this->businessB->id);

    $studentB = Student::factory()->create([
        'name' => 'Student B',
        'email' => 'student-b@test.com',
        'code' => 'STU-B-001',
    ]);

    // Test availability in business B should not overlap with business A's data
    $testAvailability = new StudentAvailability([
        'student_id' => $studentB->id,
        'day_of_week' => 1,
        'business_id' => $this->businessB->id,
    ]);

    // Should not find overlap because business A's availability is isolated
    expect($testAvailability->overlaps('08:00:00', '10:00:00'))->toBeFalse();

    // Should be able to create same time slot in business B
    $availabilityB = StudentAvailability::create([
        'student_id' => $studentB->id,
        'day_of_week' => 1,
        'start_time' => '08:00:00',
        'end_time' => '10:00:00',
    ]);

    expect($availabilityB->id)->not->toBeNull();
});
