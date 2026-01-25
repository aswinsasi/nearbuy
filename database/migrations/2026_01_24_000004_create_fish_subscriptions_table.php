<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Create fish_subscriptions table - Customer alert subscriptions.
 *
 * @srs-ref Section 2.3.3 - Customer Subscription
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('fish_subscriptions', function (Blueprint $table) {
            $table->id();
            
            // Link to user
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            
            // Subscription name (optional, for multiple subscriptions)
            $table->string('name', 100)->nullable()
                ->comment('E.g., "Home", "Office"');
            
            // Location for alerts
            $table->decimal('latitude', 10, 8);
            $table->decimal('longitude', 11, 8);
            $table->string('address', 500)->nullable();
            $table->string('location_label', 100)->nullable()
                ->comment('User-friendly location name');
            
            // Alert radius
            $table->integer('radius_km')->default(5)
                ->comment('Alert radius in kilometers');
            
            // Fish type preferences
            $table->json('fish_type_ids')->nullable()
                ->comment('Array of fish_type IDs, null = all types');
            $table->boolean('all_fish_types')->default(true)
                ->comment('Subscribe to all fish types');
            
            // Seller preferences
            $table->json('preferred_seller_ids')->nullable()
                ->comment('Array of preferred seller IDs');
            $table->json('blocked_seller_ids')->nullable()
                ->comment('Array of blocked seller IDs');
            
            // Alert frequency
            $table->string('alert_frequency', 30)->default('immediate')
                ->comment('immediate, morning_only, twice_daily, weekly_digest');
            
            // Time preferences
            $table->time('quiet_hours_start')->nullable()
                ->comment('Don\'t send alerts after this time');
            $table->time('quiet_hours_end')->nullable()
                ->comment('Resume alerts after this time');
            $table->json('active_days')->nullable()
                ->comment('Days to receive alerts [0-6], null = all days');
            
            // Status
            $table->boolean('is_active')->default(true);
            $table->boolean('is_paused')->default(false)
                ->comment('Temporarily paused by user');
            $table->timestamp('paused_until')->nullable();
            
            // Stats
            $table->integer('alerts_received')->default(0);
            $table->integer('alerts_clicked')->default(0);
            $table->timestamp('last_alert_at')->nullable();
            
            $table->timestamps();
            $table->softDeletes();
            
            // Indexes
            $table->index('user_id');
            $table->index('is_active');
            $table->index('alert_frequency');
            $table->index(['latitude', 'longitude']);
            $table->index(['is_active', 'is_paused']);
            
            // Composite for matching queries
            $table->index(['is_active', 'is_paused', 'alert_frequency']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('fish_subscriptions');
    }
};
