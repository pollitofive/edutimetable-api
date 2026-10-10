<?php

return [
    // Bulk operations
    'at_least_one' => 'Se requiere al menos un horario.',
    'teacher_required' => 'Horario #:slot (:day :start-:end): Se requiere el ID del profesor.',
    'end_after_start' => 'Horario #:slot (:day :start-:end): La hora de fin debe ser posterior a la hora de inicio.',
    'teacher_overlap_db' => 'Horario #:slot (:day :start-:end): El profesor ya tiene un horario de :existing_start a :existing_end.',
    'teacher_overlap_db_other_course' => 'Horario #:slot (:day :start-:end): El profesor ya tiene un horario de :existing_start a :existing_end para otro curso.',
    'teacher_conflict_slots' => 'Horario #:slot1 (:day1 :start1-:end1): Conflicto de profesor con el horario #:slot2 (:day2 :start2-:end2).',
    'slot_not_in_group' => 'Horario #:slot: no pertenece al curso que se está editando.',
    'locked_slots' => 'El curso tiene :count alumnos inscriptos: no se pueden agregar ni quitar horarios. Para cambiar el horario usá el simulador en Inscripciones.',
    'locked_time' => 'Horario #:slot (:day :start-:end): el curso tiene :count alumnos inscriptos, no se puede cambiar el día ni el horario. Usá el simulador en Inscripciones.',
    'capacity_below_enrolled' => 'La capacidad (:capacity) no puede ser menor a la cantidad de alumnos inscriptos (:enrolled).',

    // Single operations (ScheduleService)
    'course_not_exists' => 'El curso no existe o no pertenece al negocio actual.',
    'teacher_not_exists' => 'El profesor no existe o no pertenece al negocio actual.',
    'starts_before_ends' => 'La hora de inicio debe ser anterior a la hora de fin.',
    'teacher_overlap' => 'El profesor ya tiene un horario en este horario en este día.',
    'course_overlap' => 'El curso ya tiene un horario en este horario en este día.',
    'delete_with_students' => 'Este horario tiene :count alumnos inscriptos, no se puede eliminar. Primero quitalos del curso desde Inscripciones.',
    'delete_group_with_students' => 'El curso tiene :count alumnos inscriptos, no se puede eliminar. Primero quitalos del curso desde Inscripciones.',
    'locked_time_single' => 'Este horario tiene :count alumnos inscriptos, no se puede cambiar el día ni el horario. Usá el simulador en Inscripciones.',

    // Schedule change simulator (ScheduleChangeService)
    'change' => [
        'slots_mismatch' => 'Los horarios enviados tienen que ser todos los días de un mismo curso (el simulador no agrega ni quita días).',
        'no_changes' => 'El horario propuesto es igual al actual.',
        'has_conflicts' => 'El cambio tiene conflictos que impiden aplicarlo. Volvé a simularlo.',
        'drop_not_affected' => 'Solo se puede dar de baja a alumnos afectados por el cambio.',
    ],

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

    // Short day names
    'days_short' => [
        0 => 'Dom',
        1 => 'Lun',
        2 => 'Mar',
        3 => 'Mié',
        4 => 'Jue',
        5 => 'Vie',
        6 => 'Sáb',
    ],
];
