<?php

namespace Tests\Feature\GraphQL;

use App\Models\Course;
use App\Models\Schedule;
use App\Models\Student;
use App\Models\StudentAvailability;
use App\Models\StudentEnrollment;
use App\Models\Teacher;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create();
    Sanctum::actingAs($this->user);

    $this->student = Student::factory()->create();
    $this->teacher = Teacher::factory()->create();
    $this->course = Course::factory()->create(['name' => 'English 101']);
    $this->schedule = Schedule::factory()->create([
        'course_id' => $this->course->id,
        'teacher_id' => $this->teacher->id,
        'day_of_week' => 1, // Tuesday
        'starts_at' => '09:00',
        'ends_at' => '11:00',
        'description' => 'English 101 Morning Class',
    ]);

    // Create student availability that matches the schedule
    StudentAvailability::factory()->create([
        'student_id' => $this->student->id,
        'day_of_week' => 1, // Tuesday
        'start_time' => '08:00',
        'end_time' => '12:00',
    ]);
});

describe('Student Enrollment CRUD', function () {

    it('can create a student enrollment via GraphQL', function () {
        $mutation = "
            mutation {
                createStudentEnrollment(input: {
                    student_id: {$this->student->id}
                    schedule_id: {$this->schedule->id}
                    status: ACTIVE
                    notes: \"First enrollment\"
                }) {
                    id
                    student_id
                    schedule_id
                    status
                    notes
                    student {
                        id
                        name
                    }
                    schedule {
                        id
                        description
                    }
                }
            }
        ";

        $response = $this->postGraphQL(['query' => $mutation]);

        $response->assertJson([
            'data' => [
                'createStudentEnrollment' => [
                    'student_id' => (string) $this->student->id,
                    'schedule_id' => (string) $this->schedule->id,
                    'status' => 'ACTIVE', // GraphQL returns enum values in uppercase
                    'notes' => 'First enrollment',
                    'student' => [
                        'id' => (string) $this->student->id,
                        'name' => $this->student->name,
                    ],
                    'schedule' => [
                        'id' => (string) $this->schedule->id,
                        'description' => $this->schedule->description,
                    ],
                ],
            ],
        ]);

        expect(StudentEnrollment::count())->toBe(1);
    });

    it('prevents duplicate enrollment in same schedule', function () {
        // Create existing enrollment
        StudentEnrollment::factory()->create([
            'student_id' => $this->student->id,
            'schedule_id' => $this->schedule->id,
        ]);

        $mutation = "
            mutation {
                createStudentEnrollment(input: {
                    student_id: {$this->student->id}
                    schedule_id: {$this->schedule->id}
                }) {
                    id
                }
            }
        ";

        $response = $this->postGraphQL(['query' => $mutation]);

        // Check that we get an error response
        expect($response->json('errors'))->not->toBeNull();
        expect($response->json('errors.0.message'))->toContain('already enrolled');

        // Verify no duplicate was created
        expect(StudentEnrollment::count())->toBe(1);
    });

    it('can update a student enrollment', function () {
        $enrollment = StudentEnrollment::factory()->create([
            'student_id' => $this->student->id,
            'schedule_id' => $this->schedule->id,
            'status' => 'pending',
        ]);

        $mutation = "
            mutation {
                updateStudentEnrollment(
                    id: {$enrollment->id}
                    input: {
                        status: ACTIVE
                        notes: \"Updated enrollment\"
                    }
                ) {
                    id
                    status
                    notes
                }
            }
        ";

        $response = $this->postGraphQL(['query' => $mutation]);

        $response->assertJson([
            'data' => [
                'updateStudentEnrollment' => [
                    'id' => (string) $enrollment->id,
                    'status' => 'ACTIVE', // GraphQL returns enum values in uppercase
                    'notes' => 'Updated enrollment',
                ],
            ],
        ]);
    });

    it('can delete a student enrollment', function () {
        $enrollment = StudentEnrollment::factory()->create([
            'student_id' => $this->student->id,
            'schedule_id' => $this->schedule->id,
        ]);

        $mutation = "
            mutation {
                deleteStudentEnrollment(id: {$enrollment->id}) {
                    id
                }
            }
        ";

        $response = $this->postGraphQL(['query' => $mutation]);

        $response->assertJson([
            'data' => [
                'deleteStudentEnrollment' => [
                    'id' => (string) $enrollment->id,
                ],
            ],
        ]);

        expect(StudentEnrollment::count())->toBe(0);
    });

    it('can query student enrollments', function () {
        StudentEnrollment::factory()->count(3)->create([
            'student_id' => $this->student->id,
        ]);

        $query = "
            query {
                studentEnrollments(student_id: {$this->student->id}) {
                    data {
                        id
                        student_id
                    }
                }
            }
        ";

        $response = $this->postGraphQL(['query' => $query]);

        $response->assertJsonCount(3, 'data.studentEnrollments.data');
    });

    it('can query a single student enrollment', function () {
        $enrollment = StudentEnrollment::factory()->create([
            'student_id' => $this->student->id,
            'schedule_id' => $this->schedule->id,
        ]);

        $query = "
            query {
                studentEnrollment(id: {$enrollment->id}) {
                    id
                    student_id
                    schedule_id
                }
            }
        ";

        $response = $this->postGraphQL(['query' => $query]);

        $response->assertJson([
            'data' => [
                'studentEnrollment' => [
                    'id' => (string) $enrollment->id,
                    'student_id' => (string) $this->student->id,
                    'schedule_id' => (string) $this->schedule->id,
                ],
            ],
        ]);
    });

    it('can filter enrollments by status', function () {
        StudentEnrollment::factory()->active()->count(2)->create();
        StudentEnrollment::factory()->completed()->count(3)->create();

        $query = '
            query {
                studentEnrollments(status: COMPLETED) {
                    data {
                        id
                        status
                    }
                }
            }
        ';

        $response = $this->postGraphQL(['query' => $query]);

        $response->assertJsonCount(3, 'data.studentEnrollments.data');
        $enrollments = $response->json('data.studentEnrollments.data');

        foreach ($enrollments as $enrollment) {
            expect($enrollment['status'])->toBe('COMPLETED'); // GraphQL returns enum values in uppercase
        }
    });
});

describe('Time Conflict Validation', function () {

    it('prevents enrollment when student has conflicting schedule', function () {
        // Use the schedule created in beforeEach (09:00-11:00 on Tuesday)
        StudentEnrollment::factory()->active()->create([
            'student_id' => $this->student->id,
            'schedule_id' => $this->schedule->id,
        ]);

        // Create a second schedule with different teacher on same day/time that overlaps
        $secondTeacher = Teacher::factory()->create();
        $secondCourse = Course::factory()->create();
        $secondSchedule = Schedule::factory()->create([
            'course_id' => $secondCourse->id,
            'teacher_id' => $secondTeacher->id,
            'day_of_week' => 1, // Tuesday
            'starts_at' => '10:00', // Overlaps with 09:00-11:00
            'ends_at' => '12:00',
        ]);

        $mutation = "
            mutation {
                createStudentEnrollment(input: {
                    student_id: {$this->student->id}
                    schedule_id: {$secondSchedule->id}
                    status: ACTIVE
                }) {
                    id
                }
            }
        ";

        $response = $this->postGraphQL(['query' => $mutation]);

        // Check that we get an error response
        expect($response->json('errors'))->not->toBeNull();
        expect($response->json('errors.0.message'))->toContain('conflicting enrollment');
    });

    it('allows enrollment in non-overlapping schedules on same day', function () {
        // Create first enrollment (09:00-11:00)
        StudentEnrollment::factory()->active()->create([
            'student_id' => $this->student->id,
            'schedule_id' => $this->schedule->id,
        ]);

        // Create availability for afternoon
        StudentAvailability::factory()->create([
            'student_id' => $this->student->id,
            'day_of_week' => 1, // Tuesday
            'start_time' => '14:00',
            'end_time' => '18:00',
        ]);

        // Create second schedule (14:00-16:00) - no overlap
        $secondSchedule = Schedule::factory()->create([
            'course_id' => Course::factory()->create()->id,
            'teacher_id' => $this->teacher->id,
            'day_of_week' => 1, // Tuesday
            'starts_at' => '14:00',
            'ends_at' => '16:00',
        ]);

        $mutation = "
            mutation {
                createStudentEnrollment(input: {
                    student_id: {$this->student->id}
                    schedule_id: {$secondSchedule->id}
                    status: ACTIVE
                }) {
                    id
                }
            }
        ";

        $response = $this->postGraphQL(['query' => $mutation]);

        $response->assertJsonPath('data.createStudentEnrollment.id', fn ($id) => $id !== null);
        expect(StudentEnrollment::count())->toBe(2);
    });
});

describe('Availability Validation', function () {

    it('prevents enrollment when student has no availability on that day', function () {
        // Create schedule on Wednesday (day_of_week = 2)
        $schedule = Schedule::factory()->create([
            'course_id' => $this->course->id,
            'teacher_id' => $this->teacher->id,
            'day_of_week' => 2, // Wednesday
            'starts_at' => '09:00',
            'ends_at' => '11:00',
        ]);

        // Student only has availability on Tuesday (day_of_week = 1)
        // Already created in beforeEach

        $mutation = "
            mutation {
                createStudentEnrollment(input: {
                    student_id: {$this->student->id}
                    schedule_id: {$schedule->id}
                }) {
                    id
                }
            }
        ";

        $response = $this->postGraphQL(['query' => $mutation]);

        // Check that we get an error response
        expect($response->json('errors'))->not->toBeNull();
        expect($response->json('errors.0.message'))->toContain('no availability on this day');
    });

    it('prevents enrollment when schedule is outside student availability hours', function () {
        // Create schedule outside student's availability (13:00-15:00)
        // Student availability is 08:00-12:00
        $schedule = Schedule::factory()->create([
            'course_id' => $this->course->id,
            'teacher_id' => $this->teacher->id,
            'day_of_week' => 1, // Tuesday
            'starts_at' => '13:00',
            'ends_at' => '15:00',
        ]);

        $mutation = "
            mutation {
                createStudentEnrollment(input: {
                    student_id: {$this->student->id}
                    schedule_id: {$schedule->id}
                }) {
                    id
                }
            }
        ";

        $response = $this->postGraphQL(['query' => $mutation]);

        // Check that we get an error response
        expect($response->json('errors'))->not->toBeNull();
        expect($response->json('errors.0.message'))->toContain('does not fit within student\'s availability');
    });

    it('allows enrollment when schedule fits within student availability', function () {
        // Schedule (09:00-11:00) fits within availability (08:00-12:00)
        $mutation = "
            mutation {
                createStudentEnrollment(input: {
                    student_id: {$this->student->id}
                    schedule_id: {$this->schedule->id}
                    status: ACTIVE
                }) {
                    id
                }
            }
        ";

        $response = $this->postGraphQL(['query' => $mutation]);

        $response->assertJsonPath('data.createStudentEnrollment.id', fn ($id) => $id !== null);
        expect(StudentEnrollment::count())->toBe(1);
    });
});

describe('Relationships', function () {

    it('student has many enrollments', function () {
        StudentEnrollment::factory()->count(3)->create([
            'student_id' => $this->student->id,
        ]);

        $enrollments = $this->student->fresh()->enrollments;

        expect($enrollments)->toHaveCount(3);
    });

    it('schedule has many enrollments', function () {
        StudentEnrollment::factory()->count(4)->create([
            'schedule_id' => $this->schedule->id,
        ]);

        $enrollments = $this->schedule->fresh()->enrollments;

        expect($enrollments)->toHaveCount(4);
    });

    it('student has many schedules through enrollments', function () {
        $schedules = Schedule::factory()->count(3)->create([
            'course_id' => $this->course->id,
            'teacher_id' => $this->teacher->id,
        ]);

        foreach ($schedules as $schedule) {
            StudentEnrollment::factory()->create([
                'student_id' => $this->student->id,
                'schedule_id' => $schedule->id,
            ]);
        }

        $studentSchedules = $this->student->fresh()->schedules;

        expect($studentSchedules)->toHaveCount(3);
    });

    it('schedule has many students through enrollments', function () {
        $students = Student::factory()->count(5)->create();

        foreach ($students as $student) {
            StudentEnrollment::factory()->create([
                'student_id' => $student->id,
                'schedule_id' => $this->schedule->id,
            ]);
        }

        $scheduleStudents = $this->schedule->fresh()->students;

        expect($scheduleStudents)->toHaveCount(5);
    });
});

describe('Cascade Deletes', function () {

    it('deletes enrollments when student is deleted', function () {
        StudentEnrollment::factory()->count(2)->create([
            'student_id' => $this->student->id,
        ]);

        expect(StudentEnrollment::count())->toBe(2);

        $this->student->delete();

        expect(StudentEnrollment::count())->toBe(0);
    });

    it('deletes enrollments when schedule is deleted', function () {
        StudentEnrollment::factory()->count(3)->create([
            'schedule_id' => $this->schedule->id,
        ]);

        expect(StudentEnrollment::count())->toBe(3);

        $this->schedule->delete();

        expect(StudentEnrollment::count())->toBe(0);
    });
});

describe('Status Scopes', function () {

    it('can filter active enrollments using scope', function () {
        StudentEnrollment::factory()->active()->count(2)->create([
            'student_id' => $this->student->id,
        ]);
        StudentEnrollment::factory()->completed()->count(3)->create([
            'student_id' => $this->student->id,
        ]);

        $activeEnrollments = $this->student->fresh()->enrollments()->active()->get();

        expect($activeEnrollments)->toHaveCount(2);
    });

    it('can filter completed enrollments using scope', function () {
        StudentEnrollment::factory()->active()->count(2)->create([
            'student_id' => $this->student->id,
        ]);
        StudentEnrollment::factory()->completed()->count(3)->create([
            'student_id' => $this->student->id,
        ]);

        $completedEnrollments = $this->student->fresh()->enrollments()->completed()->get();

        expect($completedEnrollments)->toHaveCount(3);
    });
});
