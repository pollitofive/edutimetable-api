<?php

namespace App\GraphQL\Queries;

use Illuminate\Database\Eloquent\Builder;

class ScheduleQueries
{
    public function byCourseAndDay(Builder $builder, ?array $args = null): Builder
    {
        return $builder
            ->when(isset($args['course_id']), fn (Builder $q) => $q->where('course_id', $args['course_id']))
            ->when(isset($args['day_of_week']), fn (Builder $q) => $q->where('day_of_week', $args['day_of_week']));
    }
}
