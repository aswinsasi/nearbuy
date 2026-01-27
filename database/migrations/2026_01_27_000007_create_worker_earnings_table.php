<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Create worker_earnings table - Weekly earnings tracking.
 *
 * @srs-ref Section 3.7 - Worker Earnings Analytics
 * @module Njaanum Panikkar (Basic Jobs Marketplace)
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('worker_earnings', function (Blueprint $table) {
            $table->id();
            
            // Reference
            $table->foreignId('worker_id')->constrained('job_workers')->onDelete('cascade');
            
            // Time period
            $table->date('week_start')
                ->comment('Start of the week (Monday)');
            $table->date('week_end')
                ->comment('End of the week (Sunday)');
            
            // Job stats
            $table->integer('total_jobs')->default(0)
                ->comment('Jobs completed this week');
            $table->integer('total_applications')->default(0)
                ->comment('Applications sent this week');
            $table->integer('accepted_applications')->default(0)
                ->comment('Applications accepted this week');
            
            // Earnings
            $table->decimal('total_earnings', 12, 2)->default(0.00)
                ->comment('Total earnings in INR');
            $table->decimal('average_per_job', 8, 2)->default(0.00)
                ->comment('Average earning per job');
            
            // Time tracking
            $table->decimal('total_hours_worked', 6, 2)->default(0.00)
                ->comment('Total hours worked this week');
            
            // Category breakdown (JSON for flexibility)
            $table->json('earnings_by_category')->nullable()
                ->comment('{"category_id": {"jobs": N, "earnings": X}}');
            
            // Performance metrics
            $table->decimal('average_rating', 2, 1)->nullable()
                ->comment('Average rating received this week');
            $table->integer('on_time_count')->default(0)
                ->comment('Jobs completed on time');
            $table->integer('late_count')->default(0)
                ->comment('Jobs completed late');
            
            $table->timestamps();
            
            // Unique constraint - one record per worker per week
            $table->unique(['worker_id', 'week_start'], 'worker_earnings_unique');
            
            // Indexes
            $table->index('worker_id');
            $table->index('week_start');
            $table->index('total_earnings');
            $table->index(['worker_id', 'week_start']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('worker_earnings');
    }
};