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
        Schema::create('conversation_sessions', function (Blueprint $table) {
            $table->id();

            // Phone number (unique session per phone)
            $table->string('phone', 20)->unique();

            // Linked user (null if not registered yet)
            $table->foreignId('user_id')
                ->nullable()
                ->constrained()
                ->onDelete('cascade');

            // Flow state
            $table->string('current_flow', 50)->default('main_menu');
            $table->string('current_step', 50)->default('idle');

            // Temporary data storage (for in-progress operations)
            $table->json('temp_data')->nullable();

            // Activity tracking
            $table->timestamp('last_activity_at')->useCurrent();

            // Message context
            $table->string('last_message_id', 100)->nullable();
            $table->string('last_message_type', 20)->nullable();

            $table->timestamps();

            // Indexes
            $table->index('user_id');
            $table->index('last_activity_at');
            $table->index(['current_flow', 'current_step']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('conversation_sessions');
    }
};