<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audiobooks', function (Blueprint $table) {
            $table->id();
            $table->string('asin', 12);
            $table->string('region'); // enum: US, UK
            $table->string('title');
            $table->string('subtitle')->nullable();
            $table->text('description');
            $table->integer('runtime_minutes')->nullable();
            $table->string('cover_image_url')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->timestamp('ratings_synced_at')->nullable();
            $table->boolean('reviews_pending')->default(false);
            $table->string('availability')->default('available'); // enum: available, unavailable
            $table->timestamp('unavailable_since')->nullable();
            $table->integer('unavailable_strikes')->default(0);
            $table->timestamp('availability_checked_at')->nullable();
            $table->integer('rating_zeroed_count')->default(0);
            $table->timestamps();

            $table->unique(['asin', 'region']);
            $table->index('ratings_synced_at');
            $table->index('availability_checked_at');
            $table->index('reviews_pending');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audiobooks');
    }
};
