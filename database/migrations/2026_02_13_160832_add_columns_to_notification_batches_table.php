<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Add tracking columns to notification_batches table.
 *
 * Adds:
 * - batch_type: Type of notifications in batch
 * - total_items, sent_count, failed_count: Statistics
 * - message_id: WhatsApp message ID
 * - duration_ms: Processing duration
 *
 * @srs-ref NFR-R-02 - Track retry attempts and failures
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('notification_batches', function (Blueprint $table) {
            // Batch type
            $table->string('batch_type', 30)->nullable()->after('frequency')
                ->comment('Type of notifications: product_request, offer, etc.');

            // Statistics
            $table->unsignedInteger('total_items')->default(0)->after('items')
                ->comment('Total items in batch');
            $table->unsignedInteger('sent_count')->default(0)->after('total_items')
                ->comment('Successfully sent count');
            $table->unsignedInteger('failed_count')->default(0)->after('sent_count')
                ->comment('Failed send count');

            // Delivery tracking
            $table->string('message_id', 100)->nullable()->after('sent_at')
                ->comment('WhatsApp message ID');
            $table->decimal('duration_ms', 10, 2)->nullable()->after('message_id')
                ->comment('Processing duration in milliseconds');

            // Additional index
            $table->index('batch_type');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('notification_batches', function (Blueprint $table) {
            $table->dropIndex(['batch_type']);
            $table->dropColumn([
                'batch_type',
                'total_items',
                'sent_count',
                'failed_count',
                'message_id',
                'duration_ms',
            ]);
        });
    }
};