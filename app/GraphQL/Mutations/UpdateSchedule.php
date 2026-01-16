<?php

namespace App\GraphQL\Mutations;

use App\Models\Schedule;
use App\Services\ScheduleService;

class UpdateSchedule
{
    public function __construct(private ScheduleService $service) {}

    public function __invoke($_, array $args): Schedule
    {
        $scheduleId = (int) $args['id'];
        $data = $args['input'];

        // Prepare data for service (only include provided fields)
        $updateData = [];

        if (isset($data['course_id'])) {
            $updateData['course_id'] = (int) $data['course_id'];
        }

        if (isset($data['teacher_id'])) {
            $updateData['teacher_id'] = (int) $data['teacher_id'];
        }

        if (isset($data['day_of_week'])) {
            $updateData['day_of_week'] = (int) $data['day_of_week'];
        }

        if (isset($data['starts_at'])) {
            $updateData['starts_at'] = $data['starts_at'];
        }

        if (isset($data['ends_at'])) {
            $updateData['ends_at'] = $data['ends_at'];
        }

        if (isset($data['description'])) {
            $updateData['description'] = $data['description'];
        }

        return $this->service->updateSchedule($scheduleId, $updateData);
    }
}
