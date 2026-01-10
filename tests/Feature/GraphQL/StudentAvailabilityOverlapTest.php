<?php

use App\Models\User;
use App\Models\Student;
use App\Models\StudentAvailability;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create();
    Sanctum::actingAs($this->user);
    $this->student = Student::factory()->create();

    // Helper function to execute GraphQL queries
    $this->execGraphQL = function (string $query) {
        return $this->postGraphQL(['query' => $query]);
    };
});

describe('Single Create Overlap Validation', function () {
    test('creating overlapping availability should fail', function () {
        // Create an existing availability: Monday 09:00-12:00
        StudentAvailability::factory()->create([
            'student_id' => $this->student->id,
            'day_of_week' => 1,
            'start_time' => '09:00',
            'end_time' => '12:00',
        ]);

        // Try to create overlapping: Monday 11:00-14:00
        $response = ($this->execGraphQL)('
            mutation {
                createStudentAvailability(input: {
                    student_id: ' . $this->student->id . '
                    day_of_week: 1
                    start_time: "11:00"
                    end_time: "14:00"
                }) {
                    id
                }
            }
        ');

        expect($response->json('errors'))->not->toBeNull();
        expect($response->json('errors.0.extensions.validation.time_slot.0'))
            ->toContain('overlaps with an existing slot');
    });

    test('creating adjacent availability should succeed', function () {
        // Create an existing availability: Monday 09:00-12:00
        StudentAvailability::factory()->create([
            'student_id' => $this->student->id,
            'day_of_week' => 1,
            'start_time' => '09:00',
            'end_time' => '12:00',
        ]);

        // Create adjacent slot: Monday 12:00-14:00 (should succeed)
        $response = ($this->execGraphQL)('
            mutation {
                createStudentAvailability(input: {
                    student_id: ' . $this->student->id . '
                    day_of_week: 1
                    start_time: "12:00"
                    end_time: "14:00"
                }) {
                    id
                    start_time
                    end_time
                }
            }
        ');

        $response->assertJson([
            'data' => [
                'createStudentAvailability' => [
                    'start_time' => '12:00',
                    'end_time' => '14:00',
                ],
            ],
        ]);
    });

    test('creating non-overlapping availability on same day should succeed', function () {
        // Create an existing availability: Monday 09:00-12:00
        StudentAvailability::factory()->create([
            'student_id' => $this->student->id,
            'day_of_week' => 1,
            'start_time' => '09:00',
            'end_time' => '12:00',
        ]);

        // Create non-overlapping: Monday 13:00-15:00
        $response = ($this->execGraphQL)('
            mutation {
                createStudentAvailability(input: {
                    student_id: ' . $this->student->id . '
                    day_of_week: 1
                    start_time: "13:00"
                    end_time: "15:00"
                }) {
                    id
                    start_time
                    end_time
                }
            }
        ');

        $response->assertJson([
            'data' => [
                'createStudentAvailability' => [
                    'start_time' => '13:00',
                    'end_time' => '15:00',
                ],
            ],
        ]);
    });

    test('creating availability on different day should succeed', function () {
        // Create an existing availability: Monday 09:00-12:00
        StudentAvailability::factory()->create([
            'student_id' => $this->student->id,
            'day_of_week' => 1,
            'start_time' => '09:00',
            'end_time' => '12:00',
        ]);

        // Create on different day: Tuesday 09:00-12:00
        $response = ($this->execGraphQL)('
            mutation {
                createStudentAvailability(input: {
                    student_id: ' . $this->student->id . '
                    day_of_week: 2
                    start_time: "09:00"
                    end_time: "12:00"
                }) {
                    id
                    day_of_week
                }
            }
        ');

        $response->assertJson([
            'data' => [
                'createStudentAvailability' => [
                    'day_of_week' => 2,
                ],
            ],
        ]);
    });
});

describe('Single Update Overlap Validation', function () {
    test('updating to create overlap should fail', function () {
        // Create two non-overlapping availabilities
        $availability1 = StudentAvailability::factory()->create([
            'student_id' => $this->student->id,
            'day_of_week' => 1,
            'start_time' => '09:00',
            'end_time' => '12:00',
        ]);

        $availability2 = StudentAvailability::factory()->create([
            'student_id' => $this->student->id,
            'day_of_week' => 1,
            'start_time' => '14:00',
            'end_time' => '16:00',
        ]);

        // Try to update availability2 to overlap with availability1
        $response = ($this->execGraphQL)('
            mutation {
                updateStudentAvailability(
                    id: "' . $availability2->id . '"
                    input: {
                        start_time: "11:00"
                        end_time: "15:00"
                    }
                ) {
                    id
                }
            }
        ');

        expect($response->json('errors'))->not->toBeNull();
    });

    test('updating times without creating overlap should succeed', function () {
        $availability = StudentAvailability::factory()->create([
            'student_id' => $this->student->id,
            'day_of_week' => 1,
            'start_time' => '09:00',
            'end_time' => '12:00',
        ]);

        // Update to different time that doesn't overlap
        $response = ($this->execGraphQL)('
            mutation {
                updateStudentAvailability(
                    id: "' . $availability->id . '"
                    input: {
                        start_time: "10:00"
                        end_time: "13:00"
                    }
                ) {
                    id
                    start_time
                    end_time
                }
            }
        ');

        $response->assertJson([
            'data' => [
                'updateStudentAvailability' => [
                    'start_time' => '10:00',
                    'end_time' => '13:00',
                ],
            ],
        ]);
    });
});

describe('Bulk Create Overlap Validation', function () {
    test('bulk create with database overlap should fail', function () {
        // Create existing availability: Monday 09:00-12:00
        StudentAvailability::factory()->create([
            'student_id' => $this->student->id,
            'day_of_week' => 1,
            'start_time' => '09:00',
            'end_time' => '12:00',
        ]);

        // Try to bulk create with overlapping slot
        $response = ($this->execGraphQL)('
            mutation {
                bulkCreateStudentAvailabilities(input: {
                    student_id: ' . $this->student->id . '
                    availabilities: [
                        { day_of_week: 1, start_time: "11:00", end_time: "14:00" }
                        { day_of_week: 2, start_time: "09:00", end_time: "12:00" }
                    ]
                }) {
                    id
                }
            }
        ');

        expect($response->json('errors'))->not->toBeNull();
        expect($response->json('errors.0.message'))->toContain('overlaps with an existing availability');
    });

    test('bulk create with internal overlap should fail', function () {
        $response = ($this->execGraphQL)('
            mutation {
                bulkCreateStudentAvailabilities(input: {
                    student_id: ' . $this->student->id . '
                    availabilities: [
                        { day_of_week: 1, start_time: "09:00", end_time: "12:00" }
                        { day_of_week: 1, start_time: "11:00", end_time: "14:00" }
                    ]
                }) {
                    id
                }
            }
        ');

        expect($response->json('errors'))->not->toBeNull();
        expect($response->json('errors.0.message'))->toContain('overlaps with Slot #1');
    });

    test('bulk create with non-overlapping slots should succeed', function () {
        $response = ($this->execGraphQL)('
            mutation {
                bulkCreateStudentAvailabilities(input: {
                    student_id: ' . $this->student->id . '
                    availabilities: [
                        { day_of_week: 1, start_time: "09:00", end_time: "12:00" }
                        { day_of_week: 1, start_time: "13:00", end_time: "15:00" }
                        { day_of_week: 2, start_time: "09:00", end_time: "12:00" }
                    ]
                }) {
                    id
                    day_of_week
                    start_time
                    end_time
                }
            }
        ');

        $response->assertJsonCount(3, 'data.bulkCreateStudentAvailabilities');
    });

    test('bulk create with adjacent slots should succeed', function () {
        $response = ($this->execGraphQL)('
            mutation {
                bulkCreateStudentAvailabilities(input: {
                    student_id: ' . $this->student->id . '
                    availabilities: [
                        { day_of_week: 1, start_time: "09:00", end_time: "12:00" }
                        { day_of_week: 1, start_time: "12:00", end_time: "15:00" }
                    ]
                }) {
                    id
                }
            }
        ');

        $response->assertJsonCount(2, 'data.bulkCreateStudentAvailabilities');
    });
});

describe('Bulk Update Overlap Validation', function () {
    test('bulk update with internal overlap should fail', function () {
        // Create some existing availabilities
        StudentAvailability::factory()->count(3)->create([
            'student_id' => $this->student->id,
        ]);

        $response = ($this->execGraphQL)('
            mutation {
                bulkUpdateStudentAvailabilities(input: {
                    student_id: ' . $this->student->id . '
                    availabilities: [
                        { day_of_week: 1, start_time: "09:00", end_time: "12:00" }
                        { day_of_week: 1, start_time: "11:00", end_time: "14:00" }
                    ]
                }) {
                    id
                }
            }
        ');

        expect($response->json('errors'))->not->toBeNull();
        expect($response->json('errors.0.message'))->toContain('overlaps with Slot #1');
    });

    test('bulk update with non-overlapping slots should succeed', function () {
        // Create some existing availabilities
        StudentAvailability::factory()->count(3)->create([
            'student_id' => $this->student->id,
        ]);

        $response = ($this->execGraphQL)('
            mutation {
                bulkUpdateStudentAvailabilities(input: {
                    student_id: ' . $this->student->id . '
                    availabilities: [
                        { day_of_week: 1, start_time: "09:00", end_time: "12:00" }
                        { day_of_week: 1, start_time: "13:00", end_time: "15:00" }
                        { day_of_week: 2, start_time: "09:00", end_time: "12:00" }
                    ]
                }) {
                    id
                    day_of_week
                    start_time
                    end_time
                }
            }
        ');

        $response->assertJsonCount(3, 'data.bulkUpdateStudentAvailabilities');

        // Verify old ones were deleted
        expect(StudentAvailability::where('student_id', $this->student->id)->count())->toBe(3);
    });
});

describe('Edge Cases', function () {
    test('exact duplicate slot should be caught as overlap', function () {
        StudentAvailability::factory()->create([
            'student_id' => $this->student->id,
            'day_of_week' => 1,
            'start_time' => '09:00',
            'end_time' => '12:00',
        ]);

        $response = ($this->execGraphQL)('
            mutation {
                createStudentAvailability(input: {
                    student_id: ' . $this->student->id . '
                    day_of_week: 1
                    start_time: "09:00"
                    end_time: "12:00"
                }) {
                    id
                }
            }
        ');

        expect($response->json('errors'))->not->toBeNull();
    });

    test('slot contained within existing slot should fail', function () {
        StudentAvailability::factory()->create([
            'student_id' => $this->student->id,
            'day_of_week' => 1,
            'start_time' => '09:00',
            'end_time' => '15:00',
        ]);

        // Try to create smaller slot inside: 11:00-13:00
        $response = ($this->execGraphQL)('
            mutation {
                createStudentAvailability(input: {
                    student_id: ' . $this->student->id . '
                    day_of_week: 1
                    start_time: "11:00"
                    end_time: "13:00"
                }) {
                    id
                }
            }
        ');

        expect($response->json('errors'))->not->toBeNull();
    });

    test('slot containing existing slot should fail', function () {
        StudentAvailability::factory()->create([
            'student_id' => $this->student->id,
            'day_of_week' => 1,
            'start_time' => '11:00',
            'end_time' => '13:00',
        ]);

        // Try to create larger slot that contains existing: 09:00-15:00
        $response = ($this->execGraphQL)('
            mutation {
                createStudentAvailability(input: {
                    student_id: ' . $this->student->id . '
                    day_of_week: 1
                    start_time: "09:00"
                    end_time: "15:00"
                }) {
                    id
                }
            }
        ');

        expect($response->json('errors'))->not->toBeNull();
    });

    test('different students can have overlapping times', function () {
        $otherStudent = Student::factory()->create();

        // Create availability for first student
        StudentAvailability::factory()->create([
            'student_id' => $this->student->id,
            'day_of_week' => 1,
            'start_time' => '09:00',
            'end_time' => '12:00',
        ]);

        // Create same time for different student (should succeed)
        $response = ($this->execGraphQL)('
            mutation {
                createStudentAvailability(input: {
                    student_id: ' . $otherStudent->id . '
                    day_of_week: 1
                    start_time: "09:00"
                    end_time: "12:00"
                }) {
                    id
                    student_id
                }
            }
        ');

        $response->assertJson([
            'data' => [
                'createStudentAvailability' => [
                    'student_id' => (string) $otherStudent->id,
                ],
            ],
        ]);
    });
});