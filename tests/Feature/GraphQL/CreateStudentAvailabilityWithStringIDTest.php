<?php

namespace Tests\Feature\GraphQL;

use App\Models\Student;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

beforeEach(function () {
    $tenancy = setupTenancy();
    $this->user = $tenancy->user;
    $this->business = $tenancy->business;
    Sanctum::actingAs($this->user);
});

it('can create student availability with string student_id like frontend sends', function () {
    $student = Student::factory()->create();

    // Frontend sends student_id as string "6", not integer 6
    $mutation = '
        mutation {
            createStudentAvailability(input: {
                student_id: "'.$student->id.'"
                day_of_week: 0
                start_time: "00:00"
                end_time: "02:00"
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
                'start_time' => '00:00',
                'end_time' => '02:00',
            ],
        ],
    ]);
});
