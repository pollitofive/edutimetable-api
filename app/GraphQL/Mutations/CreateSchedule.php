<?php

namespace App\GraphQL\Mutations;

use App\Models\Schedule;
use App\Services\ScheduleService;

class CreateSchedule
{
    public function __construct(private ScheduleService $service) {}

    public function __invoke($_, array $args): Schedule
    {
        $data = $args['input'];
        return $this->service->createSchedule(
            (int) $data['course_id'],
            [
                'day_of_week' => (int) $data['day_of_week'],
                'starts_at'   => $data['starts_at'],
                'ends_at'     => $data['ends_at'],
            ]
        );
    }
}
