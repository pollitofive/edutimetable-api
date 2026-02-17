<?php

namespace Tests\Feature\GraphQL;

use App\Models\Student;
use App\Models\StudentAvailability;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

beforeEach(function () {
    $tenancy = setupTenancy();
    $this->user = $tenancy->user;
    $this->business = $tenancy->business;
    Sanctum::actingAs($this->user);
});

it('orders student availabilities by student name alphabetically', function () {
    // Create students with names in non-alphabetical order
    $studentCharlie = Student::factory()->create(['name' => 'Charlie Brown', 'email' => 'charlie@test.com']);
    $studentAlice = Student::factory()->create(['name' => 'Alice Smith', 'email' => 'alice@test.com']);
    $studentBob = Student::factory()->create(['name' => 'Bob Johnson', 'email' => 'bob@test.com']);

    // Create availabilities for each student
    StudentAvailability::factory()->create([
        'student_id' => $studentCharlie->id,
        'day_of_week' => 1,
        'start_time' => '09:00',
        'end_time' => '10:00',
    ]);

    StudentAvailability::factory()->create([
        'student_id' => $studentAlice->id,
        'day_of_week' => 2,
        'start_time' => '10:00',
        'end_time' => '11:00',
    ]);

    StudentAvailability::factory()->create([
        'student_id' => $studentBob->id,
        'day_of_week' => 0,
        'start_time' => '11:00',
        'end_time' => '12:00',
    ]);

    $query = '
        query {
            studentAvailabilities(first: 10) {
                data {
                    id
                    student {
                        id
                        name
                    }
                    day_of_week
                    start_time
                    end_time
                }
            }
        }
    ';

    $response = $this->postGraphQL(['query' => $query]);

    $data = $response->json('data.studentAvailabilities.data');

    // Verify we got 3 results
    expect($data)->toHaveCount(3);

    // Verify ordering: Alice, Bob, Charlie (alphabetically by name)
    expect($data[0]['student']['name'])->toBe('Alice Smith');
    expect($data[1]['student']['name'])->toBe('Bob Johnson');
    expect($data[2]['student']['name'])->toBe('Charlie Brown');
});

it('orders by student name then by day_of_week and start_time', function () {
    // Create a student with multiple availabilities
    $studentAlice = Student::factory()->create(['name' => 'Alice Smith', 'email' => 'alice@test.com']);

    // Create availabilities in non-chronological order
    StudentAvailability::factory()->create([
        'student_id' => $studentAlice->id,
        'day_of_week' => 2,
        'start_time' => '14:00',
        'end_time' => '15:00',
    ]);

    StudentAvailability::factory()->create([
        'student_id' => $studentAlice->id,
        'day_of_week' => 1,
        'start_time' => '10:00',
        'end_time' => '11:00',
    ]);

    StudentAvailability::factory()->create([
        'student_id' => $studentAlice->id,
        'day_of_week' => 1,
        'start_time' => '09:00',
        'end_time' => '10:00',
    ]);

    $query = '
        query {
            studentAvailabilities(first: 10) {
                data {
                    student {
                        name
                    }
                    day_of_week
                    start_time
                }
            }
        }
    ';

    $response = $this->postGraphQL(['query' => $query]);

    $data = $response->json('data.studentAvailabilities.data');

    // Verify ordering within same student: by day_of_week, then start_time
    expect($data[0]['day_of_week'])->toBe(1);
    expect($data[0]['start_time'])->toBe('09:00');

    expect($data[1]['day_of_week'])->toBe(1);
    expect($data[1]['start_time'])->toBe('10:00');

    expect($data[2]['day_of_week'])->toBe(2);
    expect($data[2]['start_time'])->toBe('14:00');
});

it('maintains ordering when filtering by student_id', function () {
    // Create students
    $studentAlice = Student::factory()->create(['name' => 'Alice Smith', 'email' => 'alice@test.com']);
    $studentBob = Student::factory()->create(['name' => 'Bob Johnson', 'email' => 'bob@test.com']);

    // Create multiple availabilities for Alice
    StudentAvailability::factory()->create([
        'student_id' => $studentAlice->id,
        'day_of_week' => 2,
        'start_time' => '14:00',
        'end_time' => '15:00',
    ]);

    StudentAvailability::factory()->create([
        'student_id' => $studentAlice->id,
        'day_of_week' => 1,
        'start_time' => '09:00',
        'end_time' => '10:00',
    ]);

    // Create one for Bob
    StudentAvailability::factory()->create([
        'student_id' => $studentBob->id,
        'day_of_week' => 0,
        'start_time' => '10:00',
        'end_time' => '11:00',
    ]);

    $query = '
        query {
            studentAvailabilities(student_id: '.$studentAlice->id.', first: 10) {
                data {
                    student {
                        name
                    }
                    day_of_week
                    start_time
                }
            }
        }
    ';

    $response = $this->postGraphQL(['query' => $query]);

    $data = $response->json('data.studentAvailabilities.data');

    // Should only get Alice's availabilities
    expect($data)->toHaveCount(2);
    expect($data[0]['student']['name'])->toBe('Alice Smith');
    expect($data[1]['student']['name'])->toBe('Alice Smith');

    // Should be ordered by day_of_week, then start_time
    expect($data[0]['day_of_week'])->toBe(1);
    expect($data[1]['day_of_week'])->toBe(2);
});
