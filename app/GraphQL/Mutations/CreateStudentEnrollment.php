<?php

namespace App\GraphQL\Mutations;

use App\Models\Schedule;
use App\Models\Student;
use App\Models\StudentEnrollment;
use Illuminate\Validation\ValidationException;

class CreateStudentEnrollment
{
    public function __invoke($_, array $args): StudentEnrollment
    {
        // When using @spread, the input fields are spread into $args directly
        $data = $args;

        // Validate student exists
        $student = Student::find($data['student_id']);
        if (! $student) {
            throw ValidationException::withMessages([
                'student_id' => ['Student not found'],
            ]);
        }

        // Validate schedule exists
        $schedule = Schedule::with(['course', 'teacher'])->find($data['schedule_id']);
        if (! $schedule) {
            throw ValidationException::withMessages([
                'schedule_id' => ['Schedule not found'],
            ]);
        }

        // Check if already enrolled in this schedule
        $existingEnrollment = StudentEnrollment::where('student_id', $data['student_id'])
            ->where('schedule_id', $data['schedule_id'])
            ->first();

        if ($existingEnrollment) {
            throw ValidationException::withMessages([
                'schedule_id' => ['Student is already enrolled in this schedule'],
            ]);
        }

        // Check for time conflicts with student's other active enrollments
        $this->checkTimeConflicts($student, $schedule);

        // Check if schedule time fits within student's availability
        $this->checkStudentAvailability($student, $schedule);

        // Create enrollment
        return StudentEnrollment::create([
            'student_id' => $data['student_id'],
            'schedule_id' => $data['schedule_id'],
            'enrolled_at' => $data['enrolled_at'] ?? now(),
            'status' => $data['status'] ?? 'pending',
            'notes' => $data['notes'] ?? null,
        ]);
    }

    /**
     * Check if the schedule conflicts with student's other active enrollments
     */
    private function checkTimeConflicts(Student $student, Schedule $schedule): void
    {
        $conflicts = StudentEnrollment::where('student_id', $student->id)
            ->where('status', 'active')
            ->whereHas('schedule', function ($query) use ($schedule) {
                $query->where('day_of_week', $schedule->day_of_week)
                    ->where(function ($q) use ($schedule) {
                        // Check for overlapping time ranges
                        $q->whereBetween('starts_at', [$schedule->starts_at, $schedule->ends_at])
                            ->orWhereBetween('ends_at', [$schedule->starts_at, $schedule->ends_at])
                            ->orWhere(function ($subQ) use ($schedule) {
                                $subQ->where('starts_at', '<=', $schedule->starts_at)
                                    ->where('ends_at', '>=', $schedule->ends_at);
                            });
                    });
            })
            ->with('schedule.course')
            ->get();

        if ($conflicts->isNotEmpty()) {
            $conflictingSchedule = $conflicts->first()->schedule;
            throw ValidationException::withMessages([
                'schedule_id' => [
                    'Student has a conflicting enrollment on this day and time. '.
                    "Conflict with: {$conflictingSchedule->course->name} ".
                    "({$conflictingSchedule->starts_at} - {$conflictingSchedule->ends_at})",
                ],
            ]);
        }
    }

    /**
     * Check if the schedule fits within student's availability
     */
    private function checkStudentAvailability(Student $student, Schedule $schedule): void
    {
        $availabilities = $student->availabilities()
            ->where('day_of_week', $schedule->day_of_week)
            ->get();

        if ($availabilities->isEmpty()) {
            throw ValidationException::withMessages([
                'schedule_id' => [
                    'Student has no availability on this day of the week',
                ],
            ]);
        }

        // Check if schedule time fits within any availability slot
        $fitsInAvailability = false;
        foreach ($availabilities as $availability) {
            if ($schedule->starts_at >= $availability->start_time &&
                $schedule->ends_at <= $availability->end_time) {
                $fitsInAvailability = true;
                break;
            }
        }

        if (! $fitsInAvailability) {
            throw ValidationException::withMessages([
                'schedule_id' => [
                    'Schedule time does not fit within student\'s availability. '.
                    'Student is available on this day from: '.
                    $availabilities->map(fn ($a) => "{$a->start_time} - {$a->end_time}")->join(', '),
                ],
            ]);
        }
    }
}
