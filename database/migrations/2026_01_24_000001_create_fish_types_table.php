<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Create fish_types table - Master data for fish varieties.
 *
 * @srs-ref Section 2.4 - Fish Types Master Data
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('fish_types', function (Blueprint $table) {
            $table->id();
            
            // Names
            $table->string('name_en', 100)->comment('English name');
            $table->string('name_ml', 100)->comment('Malayalam name');
            $table->string('slug', 100)->unique()->comment('URL-friendly identifier');
            
            // Classification
            $table->string('category', 50)->default('sea_fish')
                ->comment('sea_fish, freshwater, shellfish, crustacean');
            $table->json('aliases')->nullable()
                ->comment('Alternative names/spellings');
            
            // Seasonal info
            $table->json('peak_seasons')->nullable()
                ->comment('Months when fish is abundant [1-12]');
            $table->boolean('is_seasonal')->default(false);
            
            // Pricing guide
            $table->decimal('typical_price_min', 8, 2)->nullable()
                ->comment('Typical minimum price per kg');
            $table->decimal('typical_price_max', 8, 2)->nullable()
                ->comment('Typical maximum price per kg');
            
            // Display
            $table->string('emoji', 10)->default('🐟');
            $table->string('image_url', 500)->nullable();
            $table->integer('sort_order')->default(0);
            
            // Status
            $table->boolean('is_popular')->default(false)
                ->comment('Show in quick selection');
            $table->boolean('is_active')->default(true);
            
            $table->timestamps();
            
            // Indexes
            $table->index('category');
            $table->index('is_popular');
            $table->index('is_active');
            $table->index('sort_order');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('fish_types');
    }
};
