<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reviews', function (Blueprint $table) {
            $table->id();
            $table->foreignId('audiobook_id')->constrained()->cascadeOnDelete();
            $table->string('external_id');
            $table->string('source'); // enum: audible, audiofile
            $table->string('format')->nullable(); // enum: freeform, guided
            $table->string('author_name');
            $table->string('title')->nullable();
            $table->text('body')->nullable();
            $table->json('guided_responses')->nullable();
            $table->tinyInteger('rating_overall')->nullable();
            $table->tinyInteger('rating_story')->nullable();
            $table->tinyInteger('rating_performance')->nullable();
            $table->string('related_url')->nullable();
            $table->timestamp('submitted_at');
            $table->timestamps();

            $table->unique(['source', 'external_id']);
            $table->index(['audiobook_id', 'submitted_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reviews');
    }
};
