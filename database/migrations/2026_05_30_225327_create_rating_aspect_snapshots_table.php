<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rating_aspect_snapshots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('rating_snapshot_id')->constrained()->cascadeOnDelete();
            $table->string('aspect'); // enum: overall, story, performance
            $table->decimal('average', 4, 3);
            $table->integer('count');
            $table->integer('star_1');
            $table->integer('star_2');
            $table->integer('star_3');
            $table->integer('star_4');
            $table->integer('star_5');
            $table->unique(['rating_snapshot_id', 'aspect']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rating_aspect_snapshots');
    }
};
