<?php

use App\Models\Business;
use App\Models\Course;
use App\Models\CourseLevel;
use App\Models\Student;
use App\Models\Teacher;
use App\Models\Track;
use App\Services\CurrentBusiness;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

beforeEach(function () {
    $tenancy = setupTenancy();
    $this->user = $tenancy->user;
    $this->business = $tenancy->business;
    Sanctum::actingAs($this->user);
});

it('returns the results grouped by type', function () {
    $english = CourseLevel::factory()->create(['name' => 'B1', 'track_id' => Track::factory()->create(['name' => 'Inglés'])->id]);
    $portuguese = CourseLevel::factory()->create(['name' => 'A2', 'track_id' => Track::factory()->create(['name' => 'Portugués'])->id]);
    $student = Student::factory()->withLevels([$english, $portuguese])->create(['name' => 'Lucía Pérez', 'email' => 'lucia@example.com']);
    Teacher::factory()->create(['name' => 'Lucas Gómez', 'email' => 'lucas@example.com']);
    Course::factory()->create(['name' => 'Inglés Lunes']);

    $response = $this->getJson('/api/search?q=Luc')->assertOk();

    expect($response->json('students'))->toHaveCount(1)
        ->and($response->json('students.0'))->toMatchArray([
            'id' => $student->id,
            'name' => 'Lucía Pérez',
            'email' => 'lucia@example.com',
        ])
        ->and(collect($response->json('students.0.levels'))->sortBy('track')->values()->all())->toBe([
            ['level' => 'B1', 'track' => 'Inglés'],
            ['level' => 'A2', 'track' => 'Portugués'],
        ])
        ->and($response->json('teachers.0.name'))->toBe('Lucas Gómez')
        ->and($response->json('courses'))->toBe([]);
});

it('finds students by name, email and phone', function (string $q) {
    Student::factory()->create(['name' => 'Martina Sosa', 'email' => 'msosa@example.com', 'phone' => '1155667788']);
    Student::factory()->create(['name' => 'Otro Alumno', 'email' => 'otro@example.com', 'phone' => '1100000000']);

    $this->getJson('/api/search?q='.urlencode($q))
        ->assertOk()
        ->assertJsonCount(1, 'students')
        ->assertJsonPath('students.0.name', 'Martina Sosa');
})->with(['name' => 'Martina', 'email' => 'msosa@', 'phone' => '556677']);

it('finds teachers by name and email', function (string $q) {
    Teacher::factory()->create(['name' => 'Carla Ruiz', 'email' => 'cruiz@example.com']);
    Teacher::factory()->create(['name' => 'Otro Profe', 'email' => 'otro@example.com']);

    $this->getJson('/api/search?q='.urlencode($q))
        ->assertOk()
        ->assertJsonCount(1, 'teachers')
        ->assertJsonPath('teachers.0.name', 'Carla Ruiz');
})->with(['name' => 'Carla', 'email' => 'cruiz@']);

it('finds courses by name, with their level and track', function () {
    $course = Course::factory()->create(['name' => 'Francés Intensivo']);

    $this->getJson('/api/search?q=Intensivo')
        ->assertOk()
        ->assertJsonCount(1, 'courses')
        ->assertJsonPath('courses.0.id', $course->id)
        ->assertJsonPath('courses.0.level', $course->courseLevel->name)
        ->assertJsonPath('courses.0.track', $course->courseLevel->track->name);
});

it('returns at most 5 results per type', function () {
    Student::factory()->count(7)->sequence(fn ($s) => ['name' => "Repetido {$s->index}"])->create();
    Teacher::factory()->count(6)->sequence(fn ($s) => ['name' => "Repetido {$s->index}"])->create();

    $this->getJson('/api/search?q=Repetido')
        ->assertOk()
        ->assertJsonCount(5, 'students')
        ->assertJsonCount(5, 'teachers');
});

it('requires at least 2 characters', function (string $q) {
    $this->getJson('/api/search?q='.urlencode($q))
        ->assertStatus(422)
        ->assertJsonValidationErrors(['q']);
})->with(['empty' => '', 'one char' => 'a']);

it('does not return records from another business', function () {
    Student::factory()->create(['name' => 'Propio Alumno']);

    $otherBusiness = Business::factory()->create();
    app(CurrentBusiness::class)->setId($otherBusiness->id);
    Student::factory()->create(['name' => 'Ajeno Alumno']);
    Teacher::factory()->create(['name' => 'Ajeno Profe']);
    Course::factory()->create(['name' => 'Ajeno Curso']);
    app(CurrentBusiness::class)->setId($this->business->id);

    $this->getJson('/api/search?q=Ajeno')
        ->assertOk()
        ->assertExactJson(['students' => [], 'teachers' => [], 'courses' => []]);

    $this->getJson('/api/search?q=Alumno')
        ->assertOk()
        ->assertJsonCount(1, 'students')
        ->assertJsonPath('students.0.name', 'Propio Alumno');
});
