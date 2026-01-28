<?php

return [
    'overlap_single' => 'This availability overlaps with an existing slot on :day from :start to :end.',
    'overlap_bulk' => 'Slot #:slot (:day :slot_start-:slot_end) overlaps with an existing availability from :start to :end.',
    'overlap_slots' => 'Slot #:slot1 (:day1 :start1-:end1) overlaps with Slot #:slot2 (:day2 :start2-:end2).',
    'end_after_start' => 'Slot #:slot (:day :start-:end): End time must be after start time.',
    'end_after_start_simple' => 'End time must be after start time.',
    'at_least_one' => 'At least one availability slot is required.',
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
