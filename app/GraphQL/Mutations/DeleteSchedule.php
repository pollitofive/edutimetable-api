<?php

namespace App\GraphQL\Mutations;

use App\Models\Schedule;
use Illuminate\Validation\ValidationException;

class DeleteSchedule
{
    /**
     * Delete a schedule, unless it has active students: student_enrollments cascade on
     * delete, so it would silently drop them
     *
     * @param  null  $_
     *
     * @throws ValidationException
     */
    public function __invoke($_, array $args): Schedule
    {
        $schedule = Schedule::withCount('activeEnrollments')->findOrFail((int) $args['id']);

        if ($schedule->active_enrollments_count > 0) {
            throw ValidationException::withMessages([
                'id' => [__('schedule.delete_with_students', ['count' => $schedule->active_enrollments_count])],
            ]);
        }

        $schedule->delete();

        return $schedule;
    }
}
