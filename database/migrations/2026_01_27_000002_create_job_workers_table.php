<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Create job_workers table - Worker profiles for job marketplace.
 *
 * @srs-ref Section 3.2 - Job Workers Registration
 * @module Njaanum Panikkar (Basic Jobs Marketplace)
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('job_workers', function (Blueprint $table) {
            $table->id();
            
            // Link to user
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            
            // Profile details
            $table->string('name', 200);
            $table->string('photo_url', 500)->nullable()
                ->comment('Profile photo URL');
            
            // Location
            $table->decimal('latitude', 10, 8)->nullable();
            $table->decimal('longitude', 11, 8)->nullable();
            $table->string('address', 500)->nullable();
            
            // Vehicle info
            $table->string('vehicle_type', 20)->default('none')
                ->comment('none, two_wheeler, four_wheeler');
            
            // Job preferences
            $table->json('job_types')->nullable()
                ->comment('Array of category IDs worker can do');
            $table->json('availability')->nullable()
                ->comment('Array: morning, afternoon, evening, flexible');
            
            // Stats & ratings
            $table->decimal('rating', 2, 1)->default(0.0)
                ->comment('Average rating out of 5');
            $table->integer('rating_count')->default(0);
            $table->integer('jobs_completed')->default(0);
            $table->decimal('total_earnings', 12, 2)->default(0.00)
                ->comment('Lifetime earnings in INR');
            
            // Status flags
            $table->boolean('is_available')->default(true)
                ->comment('Currently accepting jobs');
            $table->boolean('is_verified')->default(false);
            $table->string('verification_photo_url', 500)->nullable()
                ->comment('ID verification photo');
            $table->timestamp('verified_at')->nullable();
            
            // Activity tracking
            $table->timestamp('last_active_at')->nullable();
            
            $table->timestamps();
            $table->softDeletes();
            
            // Indexes
            $table->unique('user_id');
            $table->index('vehicle_type');
            $table->index('is_available');
            $table->index('is_verified');
            $table->index('rating');
            $table->index('jobs_completed');
            $table->index('last_active_at');
            $table->index(['latitude', 'longitude']);
            
            // Spatial index for location queries
            $table->rawIndex(
                'latitude, longitude',
                'job_workers_location_index'
            );
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('job_workers');
    }
};