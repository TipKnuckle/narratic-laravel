<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('autotrack_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('search_type'); // enum: author, narrator, title
            $table->string('term');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('autotrack_rules');
    }
};
