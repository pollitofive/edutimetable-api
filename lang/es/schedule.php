<?php

return [
    // Bulk operations
    'at_least_one' => 'Se requiere al menos un horario.',
    'teacher_required' => 'Horario #:slot (:day :start-:end): Se requiere el ID del profesor.',
    'end_after_start' => 'Horario #:slot (:day :start-:end): La hora de fin debe ser posterior a la hora de inicio.',
    'teacher_overlap_db' => 'Horario #:slot (:day :start-:end): El profesor ya tiene un horario de :existing_start a :existing_end.',
    'teacher_overlap_db_other_course' => 'Horario #:slot (:day :start-:end): El profesor ya tiene un horario de :existing_start a :existing_end para otro curso.',
    'teacher_conflict_slots' => 'Horario #:slot1 (:day1 :start1-:end1): Conflicto de profesor con el horario #:slot2 (:day2 :start2-:end2).',

    // Single operations (ScheduleService)
    'course_not_exists' => 'El curso no existe o no pertenece al negocio actual.',
    'teacher_not_exists' => 'El profesor no existe o no pertenece al negocio actual.',
    'starts_before_ends' => 'La hora de inicio debe ser anterior a la hora de fin.',
    'teacher_overlap' => 'El profesor ya tiene un horario en este horario en este día.',
    'course_overlap' => 'El curso ya tiene un horario en este horario en este día.',

    // Days of week
    'days' => [
        0 => 'Domingo',
        1 => 'Lunes',
        2 => 'Martes',
        3 => 'Miércoles',
        4 => 'Jueves',
        5 => 'Viernes',
        6 => 'Sábado',
    ],
];
