<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rating_snapshots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('audiobook_id')->constrained()->cascadeOnDelete();
            $table->timestamp('recorded_at');
            $table->integer('num_reviews');
            $table->timestamps();

            $table->index(['audiobook_id', 'recorded_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rating_snapshots');
    }
};
