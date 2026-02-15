<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Create notification_logs table.
 *
 * Tracks ALL WhatsApp send attempts for debugging and analytics.
 *
 * @srs-ref NFR-R-02 - Track retry attempts
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('notification_logs', function (Blueprint $table) {
            $table->id();

            // Message details
            $table->string('phone', 20)
                ->comment('Recipient phone number');
            $table->string('type', 20)
                ->comment('Message type: text, buttons, list, image, etc.');
            $table->string('notification_type', 30)->nullable()
                ->comment('Business type: flash_deal, product_request, etc.');

            // Status
            $table->string('status', 20)
                ->comment('sent, failed, failed_permanently');
            $table->string('message_id', 100)->nullable()
                ->comment('WhatsApp message ID if sent');

            // Error tracking
            $table->text('error')->nullable()
                ->comment('Error message if failed');

            // Performance
            $table->decimal('duration_ms', 10, 2)->nullable()
                ->comment('Send duration in milliseconds');

            // Retry tracking
            $table->unsignedTinyInteger('attempt')->default(1)
                ->comment('Attempt number (1-3)');
            $table->string('queue', 30)->nullable()
                ->comment('Queue used: flash-deals, fish-alerts, etc.');

            // Additional context
            $table->json('context')->nullable()
                ->comment('Additional context data for debugging');

            // Only created_at (logs are immutable)
            $table->timestamp('created_at')->useCurrent();

            // Indexes
            $table->index('phone');
            $table->index('notification_type');
            $table->index('status');
            $table->index('queue');
            $table->index('created_at');
            $table->index(['notification_type', 'status']);
            $table->index(['created_at', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('notification_logs');
    }
};