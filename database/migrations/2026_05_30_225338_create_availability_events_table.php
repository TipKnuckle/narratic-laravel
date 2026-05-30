<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('availability_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('audiobook_id')->constrained()->cascadeOnDelete();
            $table->string('from_state'); // enum: available, unavailable
            $table->string('to_state');   // enum: available, unavailable
            $table->timestamp('occurred_at');
            $table->index(['audiobook_id', 'occurred_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('availability_events');
    }
};
