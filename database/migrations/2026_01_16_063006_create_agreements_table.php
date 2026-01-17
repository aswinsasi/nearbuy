<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('agreements', function (Blueprint $table) {
            $table->id();

            // Unique agreement identifier (format: NB-AG-YYYY-XXXX)
            $table->string('agreement_number', 30)->unique();

            // Creator/From party (always a registered user)
            $table->foreignId('from_user_id')
                ->constrained('users')
                ->onDelete('restrict');
            $table->string('from_name');
            $table->string('from_phone', 20);

            // Recipient/To party (may or may not be registered)
            $table->foreignId('to_user_id')
                ->nullable()
                ->constrained('users')
                ->onDelete('set null');
            $table->string('to_name');
            $table->string('to_phone', 20);

            // Agreement direction (from creator's perspective)
            $table->enum('direction', ['giving', 'receiving']);

            // Financial details
            $table->decimal('amount', 12, 2);
            $table->string('amount_in_words', 500)->nullable();

            // Purpose
            $table->enum('purpose_type', [
                'loan',
                'advance',
                'deposit',
                'business',
                'other'
            ]);
            $table->text('description')->nullable();

            // Due date
            $table->date('due_date')->nullable();

            // Status
            $table->string('status', 20)->default('pending');

            // Confirmation timestamps
            $table->timestamp('from_confirmed_at')->nullable();
            $table->timestamp('to_confirmed_at')->nullable();

            // PDF and verification
            $table->string('pdf_url')->nullable();
            $table->string('verification_token', 64)->unique()->nullable();

            // Completion tracking
            $table->timestamp('completed_at')->nullable();
            $table->text('completion_notes')->nullable();

            $table->timestamps();

            // Indexes
            $table->index('from_user_id');
            $table->index('to_user_id');
            $table->index('to_phone');
            $table->index('status');
            $table->index(['from_user_id', 'status']);
            $table->index(['to_user_id', 'status']);
            $table->index('due_date');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('agreements');
    }
};