<?php

return [
    'overlap_single' => 'Esta disponibilidad se superpone con un horario existente el :day de :start a :end.',
    'overlap_bulk' => 'El horario #:slot (:day :slot_start-:slot_end) se superpone con una disponibilidad existente de :start a :end.',
    'overlap_slots' => 'El horario #:slot1 (:day1 :start1-:end1) se superpone con el horario #:slot2 (:day2 :start2-:end2).',
    'end_after_start' => 'Horario #:slot (:day :start-:end): La hora de fin debe ser posterior a la hora de inicio.',
    'end_after_start_simple' => 'La hora de fin debe ser posterior a la hora de inicio.',
    'at_least_one' => 'Se requiere al menos un horario de disponibilidad.',
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
