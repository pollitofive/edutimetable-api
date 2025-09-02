<?php

namespace App\GraphQL\Queries;

use Illuminate\Database\Eloquent\Builder;

class CourseQueries
{
    public function byTeacher(Builder $builder, ?array $args = null): Builder
    {
        return $builder->when(
            isset($args['teacher_id']),
            fn (Builder $q) => $q->where('teacher_id', $args['teacher_id'])
        );
    }
}
