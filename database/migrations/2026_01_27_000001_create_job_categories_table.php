<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Create job_categories table - Master data for job types.
 *
 * @srs-ref Section 3.1 - Job Categories Master Data
 * @module Njaanum Panikkar (Basic Jobs Marketplace)
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('job_categories', function (Blueprint $table) {
            $table->id();
            
            // Names
            $table->string('name_en', 100)->comment('English name');
            $table->string('name_ml', 100)->comment('Malayalam name');
            $table->string('slug', 100)->unique()->comment('URL-friendly identifier');
            
            // Classification
            $table->unsignedTinyInteger('tier')->default(1)
                ->comment('1=zero_skills, 2=basic_skills');
            $table->string('icon', 10)->default('💼')
                ->comment('Emoji icon for display');
            
            // Pricing guide
            $table->decimal('typical_pay_min', 8, 2)->nullable()
                ->comment('Typical minimum pay in INR');
            $table->decimal('typical_pay_max', 8, 2)->nullable()
                ->comment('Typical maximum pay in INR');
            $table->decimal('typical_duration_hours', 4, 1)->nullable()
                ->comment('Typical duration in hours');
            
            // Requirements
            $table->boolean('requires_vehicle')->default(false)
                ->comment('Whether job requires vehicle');
            
            // Display
            $table->string('description', 500)->nullable()
                ->comment('Brief description of the job type');
            $table->integer('sort_order')->default(0);
            
            // Status
            $table->boolean('is_popular')->default(false)
                ->comment('Show in quick selection');
            $table->boolean('is_active')->default(true);
            
            $table->timestamps();
            
            // Indexes
            $table->index('tier');
            $table->index('is_popular');
            $table->index('is_active');
            $table->index('sort_order');
            $table->index('requires_vehicle');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('job_categories');
    }
};