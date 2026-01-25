<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Create fish_alert_batches table - Batched alert management.
 *
 * For users who prefer morning_only, twice_daily, or weekly_digest alerts.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('fish_alert_batches', function (Blueprint $table) {
            $table->id();
            
            // Link to subscription
            $table->foreignId('fish_subscription_id')->constrained()->onDelete('cascade');
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            
            // Batch timing
            $table->string('frequency', 30)
                ->comment('morning_only, twice_daily, weekly_digest');
            $table->timestamp('scheduled_for');
            
            // Batch content
            $table->json('catch_ids')
                ->comment('Array of fish_catch IDs in this batch');
            $table->integer('catch_count')->default(0);
            
            // Status
            $table->string('status', 30)->default('pending')
                ->comment('pending, sent, failed');
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->string('failure_reason', 500)->nullable();
            
            // WhatsApp tracking
            $table->string('whatsapp_message_id', 100)->nullable();
            
            // Engagement
            $table->boolean('was_opened')->default(false);
            $table->timestamp('opened_at')->nullable();
            $table->integer('clicks_count')->default(0);
            
            $table->timestamps();
            
            // Indexes
            $table->index('fish_subscription_id');
            $table->index('user_id');
            $table->index('status');
            $table->index('scheduled_for');
            $table->index(['status', 'scheduled_for']);
            $table->index(['user_id', 'status', 'scheduled_for']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('fish_alert_batches');
    }
};
