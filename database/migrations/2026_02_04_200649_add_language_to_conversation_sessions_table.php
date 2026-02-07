<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('conversation_sessions', function (Blueprint $table) {
            $table->string('language', 5)->default('en')->after('temp_data');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('conversation_sessions', function (Blueprint $table) {
            $table->dropColumn('language');
        });
    }
};
