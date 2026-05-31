<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reviews', function (Blueprint $table) {
            $table->dropUnique(['source', 'external_id']);
            $table->unique(['audiobook_id', 'source', 'external_id'], 'reviews_audiobook_source_external_unique');
        });
    }

    public function down(): void
    {
        Schema::table('reviews', function (Blueprint $table) {
            $table->dropUnique('reviews_audiobook_source_external_unique');
            $table->unique(['source', 'external_id']);
        });
    }
};
