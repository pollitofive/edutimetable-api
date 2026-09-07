<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('course_levels', function (Blueprint $table) {
            $table->unsignedBigInteger('track_id')->nullable()->after('track');
        });

        $this->backfillTrackIds();

        Schema::table('course_levels', function (Blueprint $table) {
            $table->unsignedBigInteger('track_id')->nullable(false)->change();

            $table->dropUnique('course_levels_business_track_slug_unique');
            $table->dropIndex('course_levels_business_track_sort_index');

            $table->unique(['business_id', 'track_id', 'slug'], 'course_levels_business_track_id_slug_unique');
            $table->index(['business_id', 'track_id', 'sort_order'], 'course_levels_business_track_id_sort_index');

            // Simple FK, same pattern as next_level_id — cross-tenant check done at app level.
            $table->foreign('track_id')
                ->references('id')->on('tracks')
                ->onDelete('restrict')->onUpdate('restrict');

            $table->dropColumn('track');
        });
    }

    /**
     * For every distinct (business_id, normalized track name) pair still using the old
     * free-text `track` column, find-or-create the matching `tracks` row (keeping the
     * casing of the first occurrence) and point `course_levels.track_id` at it.
     */
    private function backfillTrackIds(): void
    {
        $resolved = []; // "{business_id}|{normalized name}" => track id

        DB::table('course_levels')->select('id', 'business_id', 'track')->orderBy('id')
            ->each(function ($row) use (&$resolved) {
                $normalized = mb_strtolower(trim($row->track));
                $key = $row->business_id.'|'.$normalized;

                if (! isset($resolved[$key])) {
                    $existing = DB::table('tracks')
                        ->where('business_id', $row->business_id)
                        ->whereRaw('LOWER(TRIM(name)) = ?', [$normalized])
                        ->first();

                    $resolved[$key] = $existing?->id ?? DB::table('tracks')->insertGetId([
                        'business_id' => $row->business_id,
                        'name' => trim($row->track),
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }

                DB::table('course_levels')->where('id', $row->id)->update(['track_id' => $resolved[$key]]);
            });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('course_levels', function (Blueprint $table) {
            $table->string('track')->nullable()->after('business_id');
        });

        DB::table('course_levels')->select('id', 'track_id')->orderBy('id')
            ->each(function ($row) {
                $track = DB::table('tracks')->find($row->track_id);
                DB::table('course_levels')->where('id', $row->id)->update(['track' => $track->name ?? '']);
            });

        Schema::table('course_levels', function (Blueprint $table) {
            $table->string('track')->nullable(false)->change();

            $table->dropForeign(['track_id']);
            $table->dropUnique('course_levels_business_track_id_slug_unique');
            $table->dropIndex('course_levels_business_track_id_sort_index');

            $table->unique(['business_id', 'track', 'slug'], 'course_levels_business_track_slug_unique');
            $table->index(['business_id', 'track', 'sort_order'], 'course_levels_business_track_sort_index');

            $table->dropColumn('track_id');
        });
    }
};