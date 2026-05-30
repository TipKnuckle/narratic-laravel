<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audiobook_contributor', function (Blueprint $table) {
            $table->id();
            $table->foreignId('audiobook_id')->constrained()->cascadeOnDelete();
            $table->foreignId('contributor_id')->constrained()->cascadeOnDelete();
            $table->string('role'); // enum: author, narrator
            $table->unique(['audiobook_id', 'contributor_id', 'role']);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audiobook_contributor');
    }
};
