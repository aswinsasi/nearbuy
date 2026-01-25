<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Create fish_catch_responses table - Customer responses to catches.
 *
 * Tracks when customers click "I'm Coming", "Message Seller", etc.
 *
 * @srs-ref Section 2.5.2 - Customer Alert Message Format (action buttons)
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('fish_catch_responses', function (Blueprint $table) {
            $table->id();
            
            // References
            $table->foreignId('fish_catch_id')->constrained()->onDelete('cascade');
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->foreignId('fish_alert_id')->nullable()
                ->constrained()->onDelete('set null')
                ->comment('Alert that triggered this response');
            
            // Response type
            $table->string('response_type', 30)
                ->comment('coming, message, not_today, location_request');
            
            // For "I'm Coming" responses
            $table->integer('estimated_arrival_mins')->nullable()
                ->comment('Customer\'s estimated arrival time');
            $table->timestamp('arrived_at')->nullable()
                ->comment('When customer confirmed arrival');
            $table->boolean('did_purchase')->nullable()
                ->comment('Did customer actually buy?');
            
            // For "Message Seller" responses
            $table->text('message_content')->nullable();
            $table->timestamp('seller_replied_at')->nullable();
            
            // Location at response time
            $table->decimal('latitude', 10, 8)->nullable();
            $table->decimal('longitude', 11, 8)->nullable();
            $table->decimal('distance_km', 6, 2)->nullable();
            
            // Rating (after purchase)
            $table->tinyInteger('rating')->nullable()
                ->comment('1-5 star rating');
            $table->text('review')->nullable();
            $table->timestamp('rated_at')->nullable();
            
            // Status
            $table->string('status', 30)->default('active')
                ->comment('active, cancelled, completed');
            $table->timestamp('cancelled_at')->nullable();
            
            $table->timestamps();
            
            // Indexes
            $table->index('fish_catch_id');
            $table->index('user_id');
            $table->index('response_type');
            $table->index('status');
            $table->index(['fish_catch_id', 'response_type']);
            
            // Unique: one response per type per user per catch
            $table->unique(
                ['fish_catch_id', 'user_id', 'response_type'],
                'unique_catch_user_response'
            );
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('fish_catch_responses');
    }
};
