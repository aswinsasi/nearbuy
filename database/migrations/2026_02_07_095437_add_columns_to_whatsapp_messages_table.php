<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Add content_summary, session_id, delivered_at, read_at to whatsapp_messages.
 *
 * Enhances message logging with:
 * - Human-readable content summary for quick viewing
 * - Session reference for conversation tracking
 * - Delivery timestamps for read receipts
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('whatsapp_messages', function (Blueprint $table) {
            // Add content summary after content
            $table->string('content_summary', 150)->nullable()->after('content')
                ->comment('Human-readable message summary');

            // Add session reference
            $table->foreignId('session_id')->nullable()->after('user_id')
                ->constrained('conversation_sessions')
                ->nullOnDelete();

            // Add delivery timestamps
            $table->timestamp('delivered_at')->nullable()->after('updated_at');
            $table->timestamp('read_at')->nullable()->after('delivered_at');

            // Add index for content_summary searches (optional, for debugging)
            $table->index('content_summary');
        });
    }

    public function down(): void
    {
        Schema::table('whatsapp_messages', function (Blueprint $table) {
            $table->dropForeign(['session_id']);
            $table->dropColumn(['content_summary', 'session_id', 'delivered_at', 'read_at']);
        });
    }
};