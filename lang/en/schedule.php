<?php

return [
    // Bulk operations
    'at_least_one' => 'At least one schedule slot is required.',
    'teacher_required' => 'Schedule slot #:slot (:day :start-:end): teacher_id is required.',
    'end_after_start' => 'Schedule slot #:slot (:day :start-:end): End time must be after start time.',
    'teacher_overlap_db' => 'Schedule slot #:slot (:day :start-:end): Teacher already has a schedule from :existing_start to :existing_end.',
    'teacher_overlap_db_other_course' => 'Schedule slot #:slot (:day :start-:end): Teacher already has a schedule from :existing_start to :existing_end for another course.',
    'teacher_conflict_slots' => 'Schedule slot #:slot1 (:day1 :start1-:end1): Teacher conflict with slot #:slot2 (:day2 :start2-:end2).',

    // Single operations (ScheduleService)
    'course_not_exists' => 'Course does not exist or does not belong to current business.',
    'teacher_not_exists' => 'Teacher does not exist or does not belong to current business.',
    'starts_before_ends' => 'starts_at must be before ends_at.',
    'teacher_overlap' => 'Teacher already has a schedule at this time on this day.',
    'course_overlap' => 'Course already has a schedule at this time on this day.',

    // Days of week
    'days' => [
        0 => 'Sunday',
        1 => 'Monday',
        2 => 'Tuesday',
        3 => 'Wednesday',
        4 => 'Thursday',
        5 => 'Friday',
        6 => 'Saturday',
    ],
];
