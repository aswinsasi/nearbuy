<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Create fish_alerts table - Track alerts sent to customers.
 *
 * @srs-ref Section 2.3.4 - Alert Delivery
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('fish_alerts', function (Blueprint $table) {
            $table->id();
            
            // References
            $table->foreignId('fish_catch_id')->constrained()->onDelete('cascade');
            $table->foreignId('fish_subscription_id')->constrained()->onDelete('cascade');
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            
            // Alert details
            $table->string('alert_type', 30)->default('new_catch')
                ->comment('new_catch, low_stock, price_drop');
            
            // Delivery status
            $table->string('status', 30)->default('pending')
                ->comment('pending, queued, sent, delivered, failed');
            $table->timestamp('queued_at')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->string('failure_reason', 500)->nullable();
            
            // WhatsApp message tracking
            $table->string('whatsapp_message_id', 100)->nullable();
            
            // Batching
            $table->foreignId('batch_id')->nullable()
                ->comment('For batched alerts');
            $table->boolean('is_batched')->default(false);
            
            // User interaction
            $table->boolean('was_clicked')->default(false);
            $table->timestamp('clicked_at')->nullable();
            $table->string('click_action', 50)->nullable()
                ->comment('coming, message, location, dismiss');
            
            // Distance at time of alert
            $table->decimal('distance_km', 6, 2)->nullable();
            
            // Scheduling
            $table->timestamp('scheduled_for')->nullable()
                ->comment('For non-immediate alerts');
            
            $table->timestamps();
            
            // Indexes
            $table->index('fish_catch_id');
            $table->index('fish_subscription_id');
            $table->index('user_id');
            $table->index('status');
            $table->index('scheduled_for');
            $table->index('batch_id');
            $table->index(['status', 'scheduled_for']);
            
            // Unique constraint: one alert per catch per subscription
            $table->unique(['fish_catch_id', 'fish_subscription_id'], 'unique_catch_subscription');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('fish_alerts');
    }
};
