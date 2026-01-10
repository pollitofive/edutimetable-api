<?php

namespace Tests\Feature\GraphQL;

use App\Models\User;
use App\Models\Student;
use App\Models\StudentAvailability;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create();
    Sanctum::actingAs($this->user);
});

// ============= BULK CREATE TESTS =============

it('can bulk create multiple student availabilities via GraphQL', function () {
    $student = Student::factory()->create();

    $mutation = '
        mutation {
            bulkCreateStudentAvailabilities(input: {
                student_id: ' . $student->id . '
                availabilities: [
                    { day_of_week: 0, start_time: "09:00", end_time: "12:00" }
                    { day_of_week: 0, start_time: "14:00", end_time: "17:00" }
                    { day_of_week: 2, start_time: "10:00", end_time: "13:00" }
                    { day_of_week: 4, start_time: "09:00", end_time: "16:00" }
                ]
            }) {
                id
                student_id
                day_of_week
                start_time
                end_time
            }
        }
    ';

    $response = $this->postGraphQL(['query' => $mutation]);

    $data = $response->json('data.bulkCreateStudentAvailabilities');

    expect($data)->toHaveCount(4);
    expect(StudentAvailability::count())->toBe(4);

    // Verify first availability
    expect($data[0]['student_id'])->toBe((string) $student->id);
    expect($data[0]['day_of_week'])->toBe(0);
    expect($data[0]['start_time'])->toBe('09:00');
    expect($data[0]['end_time'])->toBe('12:00');

    // Verify last availability
    expect($data[3]['day_of_week'])->toBe(4);
    expect($data[3]['start_time'])->toBe('09:00');
    expect($data[3]['end_time'])->toBe('16:00');
});

it('fails bulk create with empty availabilities array', function () {
    $student = Student::factory()->create();

    $mutation = '
        mutation {
            bulkCreateStudentAvailabilities(input: {
                student_id: ' . $student->id . '
                availabilities: []
            }) {
                id
            }
        }
    ';

    $response = $this->postGraphQL(['query' => $mutation]);

    expect($response->json('errors'))->not->toBeNull();
});

it('fails bulk create with invalid student_id', function () {
    $mutation = '
        mutation {
            bulkCreateStudentAvailabilities(input: {
                student_id: 99999
                availabilities: [
                    { day_of_week: 0, start_time: "09:00", end_time: "12:00" }
                ]
            }) {
                id
            }
        }
    ';

    $response = $this->postGraphQL(['query' => $mutation]);

    expect($response->json('errors'))->not->toBeNull();
});

it('fails bulk create with invalid day_of_week', function () {
    $student = Student::factory()->create();

    $mutation = '
        mutation {
            bulkCreateStudentAvailabilities(input: {
                student_id: ' . $student->id . '
                availabilities: [
                    { day_of_week: 7, start_time: "09:00", end_time: "12:00" }
                ]
            }) {
                id
            }
        }
    ';

    $response = $this->postGraphQL(['query' => $mutation]);

    expect($response->json('errors'))->not->toBeNull();
});

it('fails bulk create with invalid time format', function () {
    $student = Student::factory()->create();

    $mutation = '
        mutation {
            bulkCreateStudentAvailabilities(input: {
                student_id: ' . $student->id . '
                availabilities: [
                    { day_of_week: 0, start_time: "9:00", end_time: "12:00" }
                ]
            }) {
                id
            }
        }
    ';

    $response = $this->postGraphQL(['query' => $mutation]);

    expect($response->json('errors'))->not->toBeNull();
});

it('fails bulk create when end_time is before start_time', function () {
    $student = Student::factory()->create();

    $mutation = '
        mutation {
            bulkCreateStudentAvailabilities(input: {
                student_id: ' . $student->id . '
                availabilities: [
                    { day_of_week: 0, start_time: "12:00", end_time: "09:00" }
                ]
            }) {
                id
            }
        }
    ';

    $response = $this->postGraphQL(['query' => $mutation]);

    expect($response->json('errors'))->not->toBeNull();
});

it('fails bulk create when end_time equals start_time', function () {
    $student = Student::factory()->create();

    $mutation = '
        mutation {
            bulkCreateStudentAvailabilities(input: {
                student_id: ' . $student->id . '
                availabilities: [
                    { day_of_week: 0, start_time: "12:00", end_time: "12:00" }
                ]
            }) {
                id
            }
        }
    ';

    $response = $this->postGraphQL(['query' => $mutation]);

    expect($response->json('errors'))->not->toBeNull();
});

it('fails bulk create with duplicate slots in database', function () {
    $student = Student::factory()->create();

    // Create existing availability
    StudentAvailability::create([
        'student_id' => $student->id,
        'day_of_week' => 0,
        'start_time' => '09:00',
        'end_time' => '12:00',
    ]);

    $mutation = '
        mutation {
            bulkCreateStudentAvailabilities(input: {
                student_id: ' . $student->id . '
                availabilities: [
                    { day_of_week: 0, start_time: "09:00", end_time: "12:00" }
                ]
            }) {
                id
            }
        }
    ';

    $response = $this->postGraphQL(['query' => $mutation]);

    expect($response->json('errors'))->not->toBeNull();
});

it('rolls back bulk create on validation failure', function () {
    $student = Student::factory()->create();

    $mutation = '
        mutation {
            bulkCreateStudentAvailabilities(input: {
                student_id: ' . $student->id . '
                availabilities: [
                    { day_of_week: 0, start_time: "09:00", end_time: "12:00" }
                    { day_of_week: 1, start_time: "14:00", end_time: "10:00" }
                ]
            }) {
                id
            }
        }
    ';

    $response = $this->postGraphQL(['query' => $mutation]);

    expect($response->json('errors'))->not->toBeNull();
    expect(StudentAvailability::count())->toBe(0);
});

// ============= BULK UPDATE TESTS =============

it('can bulk update student availabilities replacing all existing ones', function () {
    $student = Student::factory()->create();

    // Create existing availabilities
    StudentAvailability::factory()->count(3)->create([
        'student_id' => $student->id,
    ]);

    expect(StudentAvailability::count())->toBe(3);

    $mutation = '
        mutation {
            bulkUpdateStudentAvailabilities(input: {
                student_id: ' . $student->id . '
                availabilities: [
                    { day_of_week: 1, start_time: "08:00", end_time: "11:00" }
                    { day_of_week: 3, start_time: "13:00", end_time: "18:00" }
                ]
            }) {
                id
                student_id
                day_of_week
                start_time
                end_time
            }
        }
    ';

    $response = $this->postGraphQL(['query' => $mutation]);

    $data = $response->json('data.bulkUpdateStudentAvailabilities');

    expect($data)->toHaveCount(2);
    expect(StudentAvailability::count())->toBe(2);

    // Verify new availabilities
    expect($data[0]['day_of_week'])->toBe(1);
    expect($data[0]['start_time'])->toBe('08:00');
    expect($data[0]['end_time'])->toBe('11:00');

    expect($data[1]['day_of_week'])->toBe(3);
    expect($data[1]['start_time'])->toBe('13:00');
    expect($data[1]['end_time'])->toBe('18:00');
});

it('fails bulk update with non-existent student', function () {
    $mutation = '
        mutation {
            bulkUpdateStudentAvailabilities(input: {
                student_id: 99999
                availabilities: [
                    { day_of_week: 0, start_time: "09:00", end_time: "12:00" }
                ]
            }) {
                id
            }
        }
    ';

    $response = $this->postGraphQL(['query' => $mutation]);

    expect($response->json('errors'))->not->toBeNull();
});

it('fails bulk update with empty availabilities array', function () {
    $student = Student::factory()->create();

    $mutation = '
        mutation {
            bulkUpdateStudentAvailabilities(input: {
                student_id: ' . $student->id . '
                availabilities: []
            }) {
                id
            }
        }
    ';

    $response = $this->postGraphQL(['query' => $mutation]);

    expect($response->json('errors'))->not->toBeNull();
});

it('fails bulk update with invalid day_of_week', function () {
    $student = Student::factory()->create();

    $mutation = '
        mutation {
            bulkUpdateStudentAvailabilities(input: {
                student_id: ' . $student->id . '
                availabilities: [
                    { day_of_week: -1, start_time: "09:00", end_time: "12:00" }
                ]
            }) {
                id
            }
        }
    ';

    $response = $this->postGraphQL(['query' => $mutation]);

    expect($response->json('errors'))->not->toBeNull();
});

it('fails bulk update with invalid time format', function () {
    $student = Student::factory()->create();

    $mutation = '
        mutation {
            bulkUpdateStudentAvailabilities(input: {
                student_id: ' . $student->id . '
                availabilities: [
                    { day_of_week: 0, start_time: "25:00", end_time: "12:00" }
                ]
            }) {
                id
            }
        }
    ';

    $response = $this->postGraphQL(['query' => $mutation]);

    expect($response->json('errors'))->not->toBeNull();
});

it('fails bulk update when end_time is before start_time', function () {
    $student = Student::factory()->create();

    $mutation = '
        mutation {
            bulkUpdateStudentAvailabilities(input: {
                student_id: ' . $student->id . '
                availabilities: [
                    { day_of_week: 0, start_time: "18:00", end_time: "09:00" }
                ]
            }) {
                id
            }
        }
    ';

    $response = $this->postGraphQL(['query' => $mutation]);

    expect($response->json('errors'))->not->toBeNull();
});

it('rolls back bulk update on validation failure', function () {
    $student = Student::factory()->create();

    // Create existing availabilities
    $existing = StudentAvailability::factory()->count(2)->create([
        'student_id' => $student->id,
    ]);

    expect(StudentAvailability::count())->toBe(2);

    $mutation = '
        mutation {
            bulkUpdateStudentAvailabilities(input: {
                student_id: ' . $student->id . '
                availabilities: [
                    { day_of_week: 0, start_time: "09:00", end_time: "12:00" }
                    { day_of_week: 1, start_time: "18:00", end_time: "10:00" }
                ]
            }) {
                id
            }
        }
    ';

    $response = $this->postGraphQL(['query' => $mutation]);

    expect($response->json('errors'))->not->toBeNull();

    // Original availabilities should still exist (transaction rolled back)
    expect(StudentAvailability::count())->toBe(2);
});

it('can bulk update to completely replace availabilities with different schedule', function () {
    $student = Student::factory()->create();

    // Create Monday-Friday morning slots
    for ($day = 0; $day < 5; $day++) {
        StudentAvailability::create([
            'student_id' => $student->id,
            'day_of_week' => $day,
            'start_time' => '09:00',
            'end_time' => '12:00',
        ]);
    }

    expect(StudentAvailability::count())->toBe(5);

    // Replace with weekend slots only
    $mutation = '
        mutation {
            bulkUpdateStudentAvailabilities(input: {
                student_id: ' . $student->id . '
                availabilities: [
                    { day_of_week: 5, start_time: "10:00", end_time: "18:00" }
                    { day_of_week: 6, start_time: "10:00", end_time: "18:00" }
                ]
            }) {
                id
                day_of_week
            }
        }
    ';

    $response = $this->postGraphQL(['query' => $mutation]);

    $data = $response->json('data.bulkUpdateStudentAvailabilities');

    expect($data)->toHaveCount(2);
    expect(StudentAvailability::count())->toBe(2);

    // Verify only weekend slots exist
    $days = collect($data)->pluck('day_of_week')->toArray();
    expect($days)->toEqual([5, 6]);
});

it('validates duplicate slots in bulk update input', function () {
    $student = Student::factory()->create();

    $mutation = '
        mutation {
            bulkUpdateStudentAvailabilities(input: {
                student_id: ' . $student->id . '
                availabilities: [
                    { day_of_week: 0, start_time: "09:00", end_time: "12:00" }
                    { day_of_week: 0, start_time: "09:00", end_time: "12:00" }
                ]
            }) {
                id
            }
        }
    ';

    $response = $this->postGraphQL(['query' => $mutation]);

    expect($response->json('errors'))->not->toBeNull();
});

it('can handle bulk update with single availability', function () {
    $student = Student::factory()->create();

    // Create multiple existing availabilities
    StudentAvailability::factory()->count(5)->create([
        'student_id' => $student->id,
    ]);

    $mutation = '
        mutation {
            bulkUpdateStudentAvailabilities(input: {
                student_id: ' . $student->id . '
                availabilities: [
                    { day_of_week: 0, start_time: "09:00", end_time: "17:00" }
                ]
            }) {
                id
                day_of_week
                start_time
                end_time
            }
        }
    ';

    $response = $this->postGraphQL(['query' => $mutation]);

    $data = $response->json('data.bulkUpdateStudentAvailabilities');

    expect($data)->toHaveCount(1);
    expect(StudentAvailability::count())->toBe(1);
    expect($data[0]['day_of_week'])->toBe(0);
    expect($data[0]['start_time'])->toBe('09:00');
    expect($data[0]['end_time'])->toBe('17:00');
});