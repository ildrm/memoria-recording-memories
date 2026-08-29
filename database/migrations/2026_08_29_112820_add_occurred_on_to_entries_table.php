<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('entries', function (Blueprint $table) {
            $table->date('occurred_on')->nullable()->after('occurred_at');
            $table->index(['user_id', 'occurred_on'], 'entries_owner_occurred_on_idx');
            $table->index(['user_id', 'archived_at', 'occurred_on'], 'entries_owner_archive_on_idx');
        });

        DB::table('entries')
            ->whereNotNull('occurred_at')
            ->orderBy('id')
            ->chunkById(500, function ($entries): void {
                foreach ($entries as $entry) {
                    $timezone = is_string($entry->timezone)
                        && in_array($entry->timezone, timezone_identifiers_list(), true)
                            ? $entry->timezone
                            : 'UTC';

                    DB::table('entries')
                        ->where('id', $entry->id)
                        ->update([
                            'occurred_on' => Carbon::parse($entry->occurred_at, config('app.timezone', 'UTC'))
                                ->setTimezone($timezone)
                                ->toDateString(),
                        ]);
                }
            });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('entries', function (Blueprint $table) {
            $table->dropIndex('entries_owner_occurred_on_idx');
            $table->dropIndex('entries_owner_archive_on_idx');
            $table->dropColumn('occurred_on');
        });
    }
};
