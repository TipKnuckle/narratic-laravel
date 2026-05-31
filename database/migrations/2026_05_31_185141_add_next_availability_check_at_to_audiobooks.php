<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('audiobooks', function (Blueprint $table) {
            $table->timestamp('next_availability_check_at')
                ->nullable()
                ->after('next_check_at');

            $table->index('next_availability_check_at');
        });
    }

    public function down(): void
    {
        Schema::table('audiobooks', function (Blueprint $table) {
            $table->dropIndex(['next_availability_check_at']);
            $table->dropColumn('next_availability_check_at');
        });
    }
};
