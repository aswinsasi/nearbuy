<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Create job_applications table - Worker applications for jobs.
 *
 * @srs-ref Section 3.4 - Job Applications
 * @module Njaanum Panikkar (Basic Jobs Marketplace)
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('job_applications', function (Blueprint $table) {
            $table->id();
            
            // References
            $table->foreignId('job_post_id')->constrained()->onDelete('cascade');
            $table->foreignId('worker_id')->constrained('job_workers')->onDelete('cascade');
            
            // Application details
            $table->text('message')->nullable()
                ->comment('Optional message from worker');
            $table->decimal('proposed_amount', 8, 2)->nullable()
                ->comment('Worker can propose different amount');
            
            // Status
            $table->string('status', 20)->default('pending')
                ->comment('pending, accepted, rejected, withdrawn');
            
            // Timestamps
            $table->timestamp('applied_at')->useCurrent();
            $table->timestamp('responded_at')->nullable()
                ->comment('When poster responded');
            
            $table->timestamps();
            
            // Unique constraint - one application per worker per job
            $table->unique(['job_post_id', 'worker_id'], 'job_applications_unique');
            
            // Indexes
            $table->index('job_post_id');
            $table->index('worker_id');
            $table->index('status');
            $table->index('applied_at');
            $table->index(['job_post_id', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('job_applications');
    }
};