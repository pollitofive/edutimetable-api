<?php

namespace App\GraphQL\Mutations;

use App\Models\Schedule;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class DeleteScheduleGroup
{
    /**
     * Delete every schedule of a group (the whole course offering), unless it has active
     * students: student_enrollments cascade on delete, so it would silently drop them
     *
     * @param  null  $_
     * @return array<Schedule>
     *
     * @throws ValidationException
     */
    public function __invoke($_, array $args): array
    {
        $schedules = Schedule::where('group_id', $args['group_id'])
            ->withCount('activeEnrollments')
            ->get();

        if ($schedules->isEmpty()) {
            throw (new ModelNotFoundException)->setModel(Schedule::class);
        }

        $enrolled = (int) $schedules->max('active_enrollments_count');

        if ($enrolled > 0) {
            throw ValidationException::withMessages([
                'group_id' => [__('schedule.delete_group_with_students', ['count' => $enrolled])],
            ]);
        }

        DB::transaction(fn () => Schedule::whereIn('id', $schedules->pluck('id'))->delete());

        return $schedules->all();
    }
}
