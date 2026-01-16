<?php

namespace App\Console\Commands;

use App\Models\Schedule;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

class BackfillScheduleGroupIds extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'schedules:backfill-group-ids';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Backfill group_id for existing schedules by grouping them by course_id and description';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('Starting backfill of schedule group_ids...');

        // Get all schedules without group_id
        $schedulesWithoutGroupId = Schedule::whereNull('group_id')->get();

        if ($schedulesWithoutGroupId->isEmpty()) {
            $this->info('No schedules found without group_id. Nothing to backfill.');
            return 0;
        }

        $this->info("Found {$schedulesWithoutGroupId->count()} schedules without group_id.");

        // Group schedules by course_id and description
        $grouped = $schedulesWithoutGroupId->groupBy(function ($schedule) {
            return $schedule->course_id . '|' . $schedule->description;
        });

        $this->info("Grouped into {$grouped->count()} unique groups.");

        $bar = $this->output->createProgressBar($grouped->count());
        $bar->start();

        $totalUpdated = 0;

        foreach ($grouped as $key => $schedules) {
            // Generate a UUID for this group
            $groupId = (string) Str::uuid();

            // Update all schedules in this group
            foreach ($schedules as $schedule) {
                $schedule->update(['group_id' => $groupId]);
                $totalUpdated++;
            }

            $bar->advance();
        }

        $bar->finish();
        $this->newLine(2);
        $this->info("Successfully backfilled group_id for {$totalUpdated} schedules.");

        return 0;
    }
}
