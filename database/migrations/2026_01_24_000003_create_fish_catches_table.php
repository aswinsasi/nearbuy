<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Create fish_catches table - Individual catch postings.
 *
 * @srs-ref Section 5.1.3 - fish_catches table
 * @srs-ref Section 2.3.2 - Catch Posting
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('fish_catches', function (Blueprint $table) {
            $table->id();
            
            // References
            $table->foreignId('fish_seller_id')->constrained()->onDelete('cascade');
            $table->foreignId('fish_type_id')->constrained()->onDelete('cascade');
            
            // Catch identifier
            $table->string('catch_number', 20)->unique()
                ->comment('Format: FC-YYYYMMDD-XXXX');
            
            // Quantity and pricing
            $table->string('quantity_range', 20)
                ->comment('5_10, 10_25, 25_50, 50_plus');
            $table->decimal('quantity_kg', 8, 2)->nullable()
                ->comment('Exact quantity if known');
            $table->decimal('price_per_kg', 8, 2);
            
            // Media
            $table->string('photo_url', 500)->nullable();
            $table->string('photo_media_id', 100)->nullable()
                ->comment('WhatsApp media ID');
            
            // Location (can differ from seller location for fishermen)
            $table->decimal('latitude', 10, 8)->nullable();
            $table->decimal('longitude', 11, 8)->nullable();
            $table->string('location_name', 200)->nullable()
                ->comment('E.g., "Fort Kochi Harbour"');
            
            // Status
            $table->string('status', 20)->default('available')
                ->comment('available, low_stock, sold_out, expired');
            $table->timestamp('sold_out_at')->nullable();
            
            // Timing
            $table->timestamp('arrived_at')->nullable()
                ->comment('When fish actually arrived');
            $table->timestamp('expires_at')
                ->comment('Auto-expire after ~6 hours');
            
            // Stats
            $table->integer('view_count')->default(0);
            $table->integer('alerts_sent')->default(0);
            $table->integer('coming_count')->default(0)
                ->comment('Customers who clicked "I\'m Coming"');
            $table->integer('message_count')->default(0)
                ->comment('Customers who messaged seller');
            
            // Quality indicators
            $table->string('freshness_tag', 50)->nullable()
                ->comment('today_catch, morning_catch, etc.');
            $table->text('notes')->nullable()
                ->comment('Seller notes about this catch');
            
            $table->timestamps();
            $table->softDeletes();
            
            // Indexes
            $table->index('fish_seller_id');
            $table->index('fish_type_id');
            $table->index('status');
            $table->index('expires_at');
            $table->index('arrived_at');
            $table->index(['latitude', 'longitude']);
            $table->index(['status', 'expires_at']);
            
            // Composite index for browse queries
            $table->index(['status', 'fish_type_id', 'expires_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('fish_catches');
    }
};
