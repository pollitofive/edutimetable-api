<?php

return [
    // Bulk operations
    'at_least_one' => 'At least one schedule slot is required.',
    'teacher_required' => 'Schedule slot #:slot (:day :start-:end): teacher_id is required.',
    'end_after_start' => 'Schedule slot #:slot (:day :start-:end): End time must be after start time.',
    'teacher_overlap_db' => 'Schedule slot #:slot (:day :start-:end): Teacher already has a schedule from :existing_start to :existing_end.',
    'teacher_overlap_db_other_course' => 'Schedule slot #:slot (:day :start-:end): Teacher already has a schedule from :existing_start to :existing_end for another course.',
    'teacher_conflict_slots' => 'Schedule slot #:slot1 (:day1 :start1-:end1): Teacher conflict with slot #:slot2 (:day2 :start2-:end2).',
    'slot_not_in_group' => 'Schedule slot #:slot: does not belong to the course being edited.',
    'locked_slots' => 'The course has :count enrolled students: schedule slots can\'t be added or removed. Use the simulator in Enrollments to change the schedule.',
    'locked_time' => 'Schedule slot #:slot (:day :start-:end): the course has :count enrolled students, its day and time can\'t change. Use the simulator in Enrollments.',
    'capacity_below_enrolled' => 'Capacity (:capacity) can\'t be lower than the number of enrolled students (:enrolled).',

    // Single operations (ScheduleService)
    'course_not_exists' => 'Course does not exist or does not belong to current business.',
    'teacher_not_exists' => 'Teacher does not exist or does not belong to current business.',
    'starts_before_ends' => 'starts_at must be before ends_at.',
    'teacher_overlap' => 'Teacher already has a schedule at this time on this day.',
    'course_overlap' => 'Course already has a schedule at this time on this day.',
    'delete_with_students' => 'This schedule has :count enrolled students and can\'t be deleted. Drop them from the course in Enrollments first.',
    'delete_group_with_students' => 'The course has :count enrolled students and can\'t be deleted. Drop them from the course in Enrollments first.',
    'locked_time_single' => 'This schedule has :count enrolled students, its day and time can\'t change. Use the simulator in Enrollments.',

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

    // Short day names
    'days_short' => [
        0 => 'Sun',
        1 => 'Mon',
        2 => 'Tue',
        3 => 'Wed',
        4 => 'Thu',
        5 => 'Fri',
        6 => 'Sat',
    ],
];
