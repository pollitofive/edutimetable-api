<?php

namespace Tests\Feature\GraphQL;

use App\Models\Teacher;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create();
    Sanctum::actingAs($this->user);
});

it('can create a teacher via GraphQL', function () {
    $mutation = '
        mutation {
            createTeacher(input: {
                name: "John Doe"
                email: "john@example.com"
            }) {
                id
                name
                email
            }
        }
    ';

    $response = $this->postGraphQL(['query' => $mutation]);

    $response->assertJson([
        'data' => [
            'createTeacher' => [
                'name' => 'John Doe',
                'email' => 'john@example.com',
            ],
        ],
    ]);

    expect(Teacher::count())->toBe(1);
    expect(Teacher::first()->name)->toBe('John Doe');
});

it('can update a teacher via GraphQL', function () {
    $teacher = Teacher::factory()->create([
        'name' => 'Jane Doe',
        'email' => 'jane@example.com',
    ]);

    $mutation = "
        mutation {
            updateTeacher(id: {$teacher->id}, input: {
                name: \"Jane Smith\"
                email: \"jane.smith@example.com\"
            }) {
                id
                name
                email
            }
        }
    ";

    $response = $this->postGraphQL(['query' => $mutation]);

    $response->assertJson([
        'data' => [
            'updateTeacher' => [
                'id' => (string) $teacher->id,
                'name' => 'Jane Smith',
                'email' => 'jane.smith@example.com',
            ],
        ],
    ]);

    $teacher->refresh();
    expect($teacher->name)->toBe('Jane Smith');
});

it('can delete a teacher via GraphQL', function () {
    $teacher = Teacher::factory()->create();

    $mutation = "
        mutation {
            deleteTeacher(id: {$teacher->id}) {
                id
                name
                email
            }
        }
    ";

    $response = $this->postGraphQL(['query' => $mutation]);

    $response->assertJson([
        'data' => [
            'deleteTeacher' => [
                'id' => (string) $teacher->id,
                'name' => $teacher->name,
                'email' => $teacher->email,
            ],
        ],
    ]);

    expect(Teacher::count())->toBe(0);
});

it('can query teachers via GraphQL', function () {
    $teachers = Teacher::factory()->count(3)->create();

    $query = '
        query {
            teachers {
                data {
                    id
                    name
                    email
                }
            }
        }
    ';

    $response = $this->postGraphQL(['query' => $query]);

    $response->assertJsonCount(3, 'data.teachers.data');
});

it('requires authentication for teacher mutations', function () {
    $mutation = '
        mutation {
            createTeacher(input: {
                name: "John Doe"
                email: "john@example.com"
            }) {
                id
                name
                email
            }
        }
    ';

    $response = $this->postGraphQL(['query' => $mutation]);

    $response->assertJson([
        'errors' => [
            [
                'message' => 'Unauthenticated.',
            ],
        ],
    ]);
})->skip('Authentication required');
