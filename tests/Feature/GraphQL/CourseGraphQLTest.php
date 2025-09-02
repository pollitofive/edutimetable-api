<?php

namespace Tests\Feature\GraphQL;

use App\Models\User;
use App\Models\Teacher;
use App\Models\Course;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create();
    Sanctum::actingAs($this->user);
    $this->teacher = Teacher::factory()->create();
});

it('can create a course via GraphQL', function () {
    $mutation = "
        mutation {
            createCourse(input: {
                name: \"Mathematics 101\"
                level: \"Beginner\"
                year: 2024
                teacher_id: {$this->teacher->id}
            }) {
                id
                name
                level
                year
                teacher {
                    id
                    name
                }
            }
        }
    ";

    $response = $this->postGraphQL(['query' => $mutation]);

    $response->assertJson([
        'data' => [
            'createCourse' => [
                'name' => 'Mathematics 101',
                'level' => 'Beginner',
                'year' => 2024,
                'teacher' => [
                    'id' => (string) $this->teacher->id,
                    'name' => $this->teacher->name,
                ]
            ]
        ]
    ]);

    expect(Course::count())->toBe(1);
});

it('can query courses by teacher via GraphQL', function () {
    $teacher2 = Teacher::factory()->create();
    
    Course::factory()->count(2)->create(['teacher_id' => $this->teacher->id]);
    Course::factory()->create(['teacher_id' => $teacher2->id]);

    $query = "
        query {
            courses(teacher_id: {$this->teacher->id}) {
                data {
                    id
                    name
                    teacher {
                        id
                        name
                    }
                }
            }
        }
    ";

    $response = $this->postGraphQL(['query' => $query]);

    $response->assertJsonCount(2, 'data.courses.data');
    
    $courseData = $response->json('data.courses.data');
    foreach ($courseData as $course) {
        expect($course['teacher']['id'])->toBe((string) $this->teacher->id);
    }
});

it('can update a course via GraphQL', function () {
    $course = Course::factory()->create(['teacher_id' => $this->teacher->id]);

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
            ]
        ]
    ]);

    $course->refresh();
    expect($course->name)->toBe('Advanced Mathematics');
    expect($course->level)->toBe('Advanced');
});

it('can delete a course via GraphQL', function () {
    $course = Course::factory()->create(['teacher_id' => $this->teacher->id]);

    $mutation = "
        mutation {
            deleteCourse(id: {$course->id}) {
                id
                name
                teacher {
                    id
                    name
                }
            }
        }
    ";

    $response = $this->postGraphQL(['query' => $mutation]);

    $response->assertJson([
        'data' => [
            'deleteCourse' => [
                'id' => (string) $course->id,
                'name' => $course->name,
            ]
        ]
    ]);

    expect(Course::count())->toBe(0);
});