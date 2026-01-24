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

it('can create a student availability via GraphQL', function () {
    $student = Student::factory()->create();

    $mutation = '
        mutation {
            createStudentAvailability(input: {
                student_id: '.$student->id.'
                day_of_week: 0
                start_time: "09:00"
                end_time: "12:00"
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

    $response->assertJson([
        'data' => [
            'createStudentAvailability' => [
                'student_id' => (string) $student->id,
                'day_of_week' => 0,
                'start_time' => '09:00',
                'end_time' => '12:00',
            ],
        ],
    ]);

    expect(StudentAvailability::count())->toBe(1);
    $availability = StudentAvailability::first();
    expect($availability->student_id)->toBe($student->id);
    expect($availability->day_of_week)->toBe(0);
    expect($availability->start_time)->toBe('09:00');
    expect($availability->end_time)->toBe('12:00');
});

it('can update a student availability via GraphQL', function () {
    $student = Student::factory()->create();
    $availability = StudentAvailability::factory()->create([
        'student_id' => $student->id,
        'day_of_week' => 0,
        'start_time' => '09:00',
        'end_time' => '12:00',
    ]);

    $mutation = '
        mutation {
            updateStudentAvailability(id: '.$availability->id.', input: {
                start_time: "10:00"
                end_time: "14:00"
            }) {
                id
                start_time
                end_time
            }
        }
    ';

    $response = $this->postGraphQL(['query' => $mutation]);

    $response->assertJson([
        'data' => [
            'updateStudentAvailability' => [
                'id' => (string) $availability->id,
                'start_time' => '10:00',
                'end_time' => '14:00',
            ],
        ],
    ]);

    $availability->refresh();
    expect($availability->start_time)->toBe('10:00');
    expect($availability->end_time)->toBe('14:00');
});

it('can delete a student availability via GraphQL', function () {
    $student = Student::factory()->create();
    $availability = StudentAvailability::factory()->create([
        'student_id' => $student->id,
    ]);

    expect(StudentAvailability::count())->toBe(1);

    $mutation = '
        mutation {
            deleteStudentAvailability(id: '.$availability->id.') {
                id
            }
        }
    ';

    $response = $this->postGraphQL(['query' => $mutation]);

    $response->assertJson([
        'data' => [
            'deleteStudentAvailability' => [
                'id' => (string) $availability->id,
            ],
        ],
    ]);

    expect(StudentAvailability::count())->toBe(0);
});

it('can query student availabilities via GraphQL', function () {
    $student = Student::factory()->create();
    StudentAvailability::factory()->count(3)->create([
        'student_id' => $student->id,
    ]);

    $query = '
        query {
            studentAvailabilities(student_id: '.$student->id.') {
                data {
                    id
                    student_id
                    day_of_week
                    start_time
                    end_time
                }
            }
        }
    ';

    $response = $this->postGraphQL(['query' => $query]);

    expect($response->json('data.studentAvailabilities.data'))->toHaveCount(3);
});

it('can query a single student availability via GraphQL', function () {
    $student = Student::factory()->create();
    $availability = StudentAvailability::factory()->create([
        'student_id' => $student->id,
        'day_of_week' => 2,
        'start_time' => '14:00',
        'end_time' => '18:00',
    ]);

    $query = '
        query {
            studentAvailability(id: '.$availability->id.') {
                id
                student_id
                day_of_week
                start_time
                end_time
            }
        }
    ';

    $response = $this->postGraphQL(['query' => $query]);

    $response->assertJson([
        'data' => [
            'studentAvailability' => [
                'id' => (string) $availability->id,
                'student_id' => (string) $student->id,
                'day_of_week' => 2,
                'start_time' => '14:00',
                'end_time' => '18:00',
            ],
        ],
    ]);
});

it('validates that student_id exists', function () {
    $mutation = '
        mutation {
            createStudentAvailability(input: {
                student_id: 99999
                day_of_week: 0
                start_time: "09:00"
                end_time: "12:00"
            }) {
                id
            }
        }
    ';

    $response = $this->postGraphQL(['query' => $mutation]);

    expect($response->json('errors'))->not->toBeNull();
});

it('validates day_of_week is between 0 and 6', function () {
    $student = Student::factory()->create();

    $mutation = '
        mutation {
            createStudentAvailability(input: {
                student_id: '.$student->id.'
                day_of_week: 7
                start_time: "09:00"
                end_time: "12:00"
            }) {
                id
            }
        }
    ';

    $response = $this->postGraphQL(['query' => $mutation]);

    expect($response->json('errors'))->not->toBeNull();
});

it('validates time format is HH:mm', function () {
    $student = Student::factory()->create();

    $mutation = '
        mutation {
            createStudentAvailability(input: {
                student_id: '.$student->id.'
                day_of_week: 0
                start_time: "9:00"
                end_time: "12:00"
            }) {
                id
            }
        }
    ';

    $response = $this->postGraphQL(['query' => $mutation]);

    expect($response->json('errors'))->not->toBeNull();
});

it('validates end_time is after start_time', function () {
    $student = Student::factory()->create();

    $mutation = '
        mutation {
            createStudentAvailability(input: {
                student_id: '.$student->id.'
                day_of_week: 0
                start_time: "12:00"
                end_time: "09:00"
            }) {
                id
            }
        }
    ';

    $response = $this->postGraphQL(['query' => $mutation]);

    expect($response->json('errors'))->not->toBeNull();
});

it('validates end_time equals start_time should fail', function () {
    $student = Student::factory()->create();

    $mutation = '
        mutation {
            createStudentAvailability(input: {
                student_id: '.$student->id.'
                day_of_week: 0
                start_time: "12:00"
                end_time: "12:00"
            }) {
                id
            }
        }
    ';

    $response = $this->postGraphQL(['query' => $mutation]);

    expect($response->json('errors'))->not->toBeNull();
});

it('can load student relationship from availability', function () {
    $student = Student::factory()->create(['name' => 'John Doe']);
    $availability = StudentAvailability::factory()->create([
        'student_id' => $student->id,
    ]);

    $query = '
        query {
            studentAvailability(id: '.$availability->id.') {
                id
                student {
                    id
                    name
                }
            }
        }
    ';

    $response = $this->postGraphQL(['query' => $query]);

    $response->assertJson([
        'data' => [
            'studentAvailability' => [
                'student' => [
                    'id' => (string) $student->id,
                    'name' => 'John Doe',
                ],
            ],
        ],
    ]);
});

it('can load availabilities from student', function () {
    $student = Student::factory()->create();
    StudentAvailability::factory()->count(2)->create([
        'student_id' => $student->id,
    ]);

    $query = '
        query {
            students(first: 1) {
                data {
                    id
                    name
                    availabilities {
                        id
                        day_of_week
                        start_time
                        end_time
                    }
                }
            }
        }
    ';

    $response = $this->postGraphQL(['query' => $query]);

    expect($response->json('data.students.data.0.availabilities'))->toHaveCount(2);
});

it('prevents duplicate availability slots', function () {
    $student = Student::factory()->create();

    StudentAvailability::create([
        'student_id' => $student->id,
        'day_of_week' => 0,
        'start_time' => '09:00',
        'end_time' => '12:00',
    ]);

    $mutation = '
        mutation {
            createStudentAvailability(input: {
                student_id: '.$student->id.'
                day_of_week: 0
                start_time: "09:00"
                end_time: "12:00"
            }) {
                id
            }
        }
    ';

    $response = $this->postGraphQL(['query' => $mutation]);

    expect($response->json('errors'))->not->toBeNull();
});

it('cascades delete when student is deleted', function () {
    $student = Student::factory()->create();
    StudentAvailability::factory()->count(3)->create([
        'student_id' => $student->id,
    ]);

    expect(StudentAvailability::count())->toBe(3);

    $student->delete();

    expect(StudentAvailability::count())->toBe(0);
});
