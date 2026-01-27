<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Create job_posts table - Posted jobs and tasks.
 *
 * @srs-ref Section 3.3 - Job Posting
 * @module Njaanum Panikkar (Basic Jobs Marketplace)
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('job_posts', function (Blueprint $table) {
            $table->id();
            
            // References
            $table->foreignId('poster_user_id')->constrained('users')->onDelete('cascade');
            $table->foreignId('job_category_id')->constrained()->onDelete('cascade');
            
            // Job identifier
            $table->string('job_number', 20)->unique()
                ->comment('Format: JP-YYYYMMDD-XXXX');
            
            // Job details
            $table->string('title', 200);
            $table->text('description')->nullable();
            $table->text('special_instructions')->nullable()
                ->comment('Additional instructions for worker');
            
            // Location
            $table->string('location_name', 200)->nullable()
                ->comment('Human readable location');
            $table->decimal('latitude', 10, 8)->nullable();
            $table->decimal('longitude', 11, 8)->nullable();
            
            // Timing
            $table->date('job_date');
            $table->time('job_time')->nullable();
            $table->decimal('duration_hours', 4, 1)->nullable()
                ->comment('Estimated duration in hours');
            
            // Payment
            $table->decimal('pay_amount', 8, 2)
                ->comment('Payment amount in INR');
            
            // Status
            $table->string('status', 20)->default('draft')
                ->comment('draft, open, assigned, in_progress, completed, cancelled, expired');
            
            // Assignment
            $table->foreignId('assigned_worker_id')
                ->nullable()
                ->constrained('job_workers')
                ->onDelete('set null');
            $table->integer('applications_count')->default(0);
            
            // Timestamps for status tracking
            $table->timestamp('posted_at')->nullable();
            $table->timestamp('assigned_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('expires_at')->nullable()
                ->comment('Auto-expire if not assigned');
            
            $table->timestamps();
            $table->softDeletes();
            
            // Indexes
            $table->index('poster_user_id');
            $table->index('job_category_id');
            $table->index('assigned_worker_id');
            $table->index('status');
            $table->index('job_date');
            $table->index('expires_at');
            $table->index('posted_at');
            $table->index(['latitude', 'longitude']);
            
            // Composite indexes for common queries
            $table->index(['status', 'job_date']);
            $table->index(['status', 'expires_at']);
            $table->index(['status', 'job_category_id', 'job_date']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('job_posts');
    }
};