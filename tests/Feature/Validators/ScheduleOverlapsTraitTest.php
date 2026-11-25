<?php

use App\GraphQL\Validators\Concerns\ValidatesScheduleOverlaps;
use App\Models\Course;
use App\Models\Schedule;
use App\Models\Teacher;
use App\Services\CurrentBusiness;

beforeEach(function () {
    $this->business = setupTenancy()->business;

    // The trait methods are protected: expose them through public wrappers
    $this->validator = new class
    {
        use ValidatesScheduleOverlaps;

        public function overlapsDb(int $teacherId, int $day, string $startsAt, string $endsAt, ?int $excludeId = null): bool
        {
            return $this->hasOverlapWithDatabase($teacherId, $day, $startsAt, $endsAt, $excludeId);
        }

        public function overlaps(int $day1, string $start1, string $end1, int $day2, string $start2, string $end2): bool
        {
            return $this->slotsOverlap($day1, $start1, $end1, $day2, $start2, $end2);
        }

        public function dayName(int $day): string
        {
            return $this->getDayName($day);
        }

        public function find(int $teacherId, int $day, string $startsAt, string $endsAt, ?int $excludeId = null): ?Schedule
        {
            return $this->findOverlappingSchedule($teacherId, $day, $startsAt, $endsAt, $excludeId);
        }
    };

    $this->teacher = Teacher::factory()->create();
    $this->existing = Schedule::factory()->create([
        'course_id' => Course::factory()->create()->id,
        'teacher_id' => $this->teacher->id,
        'day_of_week' => 1,
        'starts_at' => '10:00:00',
        'ends_at' => '12:00:00',
    ]);
});

describe('slotsOverlap', function () {
    it('detects overlaps between two slots', function (int $day2, string $start2, string $end2, bool $expected) {
        expect($this->validator->overlaps(1, '10:00:00', '12:00:00', $day2, $start2, $end2))->toBe($expected);
    })->with([
        'different days' => [2, '10:00:00', '12:00:00', false],
        'contiguous before' => [1, '08:00:00', '10:00:00', false],
        'contiguous after' => [1, '12:00:00', '14:00:00', false],
        'crossing the start' => [1, '09:00:00', '11:00:00', true],
        'crossing the end' => [1, '11:00:00', '13:00:00', true],
        'contained' => [1, '10:30:00', '11:30:00', true],
        'containing' => [1, '09:00:00', '13:00:00', true],
        'identical' => [1, '10:00:00', '12:00:00', true],
    ]);

    it('is symmetric for a contained slot', function () {
        expect($this->validator->overlaps(1, '10:30:00', '11:30:00', 1, '10:00:00', '12:00:00'))->toBeTrue();
    });
});

describe('hasOverlapWithDatabase', function () {
    it('detects overlaps with the teacher schedules', function (int $day, string $start, string $end, bool $expected) {
        expect($this->validator->overlapsDb($this->teacher->id, $day, $start, $end))->toBe($expected);
    })->with([
        'different day' => [2, '10:00:00', '12:00:00', false],
        'contiguous before' => [1, '08:00:00', '10:00:00', false],
        'contiguous after' => [1, '12:00:00', '14:00:00', false],
        'crossing' => [1, '11:00:00', '13:00:00', true],
        'contained' => [1, '10:30:00', '11:30:00', true],
        'containing' => [1, '09:00:00', '13:00:00', true],
        'identical' => [1, '10:00:00', '12:00:00', true],
    ]);

    it('only checks the given teacher', function () {
        $otherTeacher = Teacher::factory()->create();

        expect($this->validator->overlapsDb($otherTeacher->id, 1, '10:00:00', '12:00:00'))->toBeFalse();
    });

    it('excludes the given schedule id', function () {
        expect($this->validator->overlapsDb($this->teacher->id, 1, '10:00:00', '12:00:00', $this->existing->id))->toBeFalse();
    });

    it('does not filter when the exclude id is null or 0', function (?int $excludeId) {
        expect($this->validator->overlapsDb($this->teacher->id, 1, '10:00:00', '12:00:00', $excludeId))->toBeTrue();
    })->with(['null' => [null], 'zero' => [0]]);

    it('ignores schedules of another business', function () {
        setupTenancy();
        $foreignTeacher = Teacher::factory()->create();
        Schedule::factory()->create([
            'teacher_id' => $foreignTeacher->id,
            'day_of_week' => 3,
            'starts_at' => '10:00:00',
            'ends_at' => '12:00:00',
        ]);
        app(CurrentBusiness::class)->setId($this->business->id);

        expect($this->validator->overlapsDb($foreignTeacher->id, 3, '10:00:00', '12:00:00'))->toBeFalse()
            ->and($this->validator->find($foreignTeacher->id, 3, '10:00:00', '12:00:00'))->toBeNull();
    });
});

describe('findOverlappingSchedule', function () {
    it('returns the overlapping schedule', function () {
        $found = $this->validator->find($this->teacher->id, 1, '11:00:00', '13:00:00');

        expect($found)->toBeInstanceOf(Schedule::class)
            ->and($found->id)->toBe($this->existing->id);
    });

    it('returns null for contiguous slots or another teacher', function () {
        expect($this->validator->find($this->teacher->id, 1, '12:00:00', '14:00:00'))->toBeNull()
            ->and($this->validator->find(Teacher::factory()->create()->id, 1, '10:00:00', '12:00:00'))->toBeNull();
    });

    it('excludes the given schedule id', function () {
        expect($this->validator->find($this->teacher->id, 1, '10:00:00', '12:00:00', $this->existing->id))->toBeNull();
    });
});

describe('getDayName', function () {
    it('returns the translated day name', function () {
        expect($this->validator->dayName(1))->toBe(__('schedule.days.1'));
    });

    it('falls back to "Day N" for an unknown day', function () {
        expect($this->validator->dayName(9))->toBe('Day 9');
    });
});
