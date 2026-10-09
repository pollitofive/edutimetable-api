<?php

namespace App\GraphQL\Queries;

use App\Models\Course;
use App\Models\Schedule;
use Illuminate\Database\Eloquent\Builder;

class ScheduleGroups
{
    /**
     * Schedules paginated by group (a course offering: all its weekly slots together)
     * instead of by slot. Filters pick which groups match (e.g. a group with any slot on
     * Monday); every slot of a matching group is returned.
     *
     * @param  null  $_
     */
    public function __invoke($_, array $args): array
    {
        $groups = Schedule::query()
            ->when($args['course_id'] ?? null, fn (Builder $q, $v) => $q->where('course_id', $v))
            ->when($args['teacher_id'] ?? null, fn (Builder $q, $v) => $q->where('teacher_id', $v))
            ->when(isset($args['day_of_week']), fn (Builder $q) => $q->where('day_of_week', $args['day_of_week']))
            ->when($args['description'] ?? null, fn (Builder $q, $v) => $q->where('description', 'like', $v))
            ->when($args['starts_at_from'] ?? null, fn (Builder $q, $v) => $q->startsAtFrom($v))
            ->when($args['starts_at_to'] ?? null, fn (Builder $q, $v) => $q->startsAtTo($v))
            ->select('group_id', 'course_id')
            ->groupBy('group_id', 'course_id')
            ->orderBy(Course::select('name')->whereColumn('courses.id', 'schedules.course_id'))
            ->orderByRaw('MIN(description)')
            ->paginate($args['first'], ['*'], 'page', $args['page'] ?? 1);

        $slots = Schedule::with('course')
            ->withCount('activeEnrollments')
            ->whereIn('group_id', $groups->pluck('group_id'))
            ->orderBy('day_of_week')
            ->orderBy('starts_at')
            ->get()
            ->groupBy('group_id');

        return [
            'data' => $groups->map(function ($group) use ($slots) {
                $schedules = $slots->get($group->group_id);
                $first = $schedules->first();

                return [
                    'group_id' => $group->group_id,
                    'course' => $first->course,
                    'description' => $first->description,
                    'capacity' => $first->capacity,
                    // Each student is enrolled in every slot of the group, so the busiest slot is the group's count
                    'active_enrollments_count' => (int) $schedules->max('active_enrollments_count'),
                    'schedules' => $schedules->all(),
                ];
            })->all(),
            'paginatorInfo' => [
                'total' => $groups->total(),
                'count' => $groups->count(),
                'currentPage' => $groups->currentPage(),
                'lastPage' => $groups->lastPage(),
                'hasMorePages' => $groups->hasMorePages(),
                'perPage' => $groups->perPage(),
            ],
        ];
    }
}
