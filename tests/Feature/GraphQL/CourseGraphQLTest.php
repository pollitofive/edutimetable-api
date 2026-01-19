<?php

namespace Tests\Feature\GraphQL;

use App\Models\Course;
use App\Models\Teacher;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create();
    Sanctum::actingAs($this->user);
    $this->teacher = Teacher::factory()->create();
});

it('can create a course via GraphQL', function () {
    $mutation = '
        mutation {
            createCourse(input: {
                name: "Mathematics 101"
                level: "Beginner"
                year: 2024
            }) {
                id
                name
                level
                year
            }
        }
    ';

    $response = $this->postGraphQL(['query' => $mutation]);

    $response->assertJson([
        'data' => [
            'createCourse' => [
                'name' => 'Mathematics 101',
                'level' => 'Beginner',
                'year' => 2024,
            ],
        ],
    ]);

    expect(Course::count())->toBe(1);
});

it('can query courses via GraphQL', function () {
    $teacher2 = Teacher::factory()->create();

    // Create courses (courses don't have teacher_id anymore)
    Course::factory()->count(3)->create();

    $query = '
        query {
            courses {
                data {
                    id
                    name
                    level
                    year
                }
            }
        }
    ';

    $response = $this->postGraphQL(['query' => $query]);

    $response->assertJsonCount(3, 'data.courses.data');
});

it('can update a course via GraphQL', function () {
    $course = Course::factory()->create();

    $mutation = "
        mutation {
            updateCourse(id: {$course->id}, input: {
                name: \"Advanced Mathematics\"
                level: \"Advanced\"
            }) {
                id
                name
                level
                year
            }
        }
    ";

    $response = $this->postGraphQL(['query' => $mutation]);

    $response->assertJson([
        'data' => [
            'updateCourse' => [
                'name' => 'Advanced Mathematics',
                'level' => 'Advanced',
            ],
        ],
    ]);

    $course->refresh();
    expect($course->name)->toBe('Advanced Mathematics');
    expect($course->level)->toBe('Advanced');
});

it('can delete a course via GraphQL', function () {
    $course = Course::factory()->create();

    $mutation = "
        mutation {
            deleteCourse(id: {$course->id}) {
                id
                name
            }
        }
    ";

    $response = $this->postGraphQL(['query' => $mutation]);

    $response->assertJson([
        'data' => [
            'deleteCourse' => [
                'id' => (string) $course->id,
                'name' => $course->name,
            ],
        ],
    ]);

    expect(Course::count())->toBe(0);
});
