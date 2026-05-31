<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('audiobooks', function (Blueprint $table) {
            // When this title is next due for a ratings sync. Null = due now
            // (never scheduled). A derived cache of the cadence in
            // docs/spec/05-adr-ratings-sync-cadence.md — recomputable from
            // published_at + the latest rating snapshot's recorded_at.
            $table->timestamp('next_check_at')->nullable()->after('ratings_synced_at');
            $table->index('next_check_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('audiobooks', function (Blueprint $table) {
            $table->dropIndex(['next_check_at']);
            $table->dropColumn('next_check_at');
        });
    }
};
