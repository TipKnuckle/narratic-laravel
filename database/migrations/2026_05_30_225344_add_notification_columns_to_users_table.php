<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('review_notifications_enabled')->default(true);
            $table->boolean('ratings_notifications_enabled')->default(true);
            $table->tinyInteger('min_overall')->default(0);
            $table->tinyInteger('min_story')->default(0);
            $table->tinyInteger('min_performance')->default(0);
            $table->string('digest_frequency')->default('daily'); // enum: daily, weekly, off
            $table->timestamp('last_digest_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn([
                'review_notifications_enabled',
                'ratings_notifications_enabled',
                'min_overall',
                'min_story',
                'min_performance',
                'digest_frequency',
                'last_digest_at',
            ]);
        });
    }
};
