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
        // AURA-AGENT identifies the student by their own YRU Passport access token,
        // so the backend keeps it (encrypted) and refreshes it when it expires.
        Schema::table('users', function (Blueprint $table) {
            $table->text('passport_access_token')->nullable()->after('passport_profile');
            $table->text('passport_refresh_token')->nullable()->after('passport_access_token');
            $table->timestamp('passport_token_expires_at')->nullable()->after('passport_refresh_token');
        });

        // The agent keeps its own conversation memory per thread_id.
        Schema::table('chat_sessions', function (Blueprint $table) {
            $table->uuid('agent_thread_id')->nullable()->after('external_id');
        });

        Schema::table('ai_requests', function (Blueprint $table) {
            $table->string('engine', 16)->default('ollama')->after('is_task')->comment('aura_agent, ollama');
            $table->json('tools_used')->nullable()->after('completion_tokens');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['passport_access_token', 'passport_refresh_token', 'passport_token_expires_at']);
        });

        Schema::table('chat_sessions', function (Blueprint $table) {
            $table->dropColumn('agent_thread_id');
        });

        Schema::table('ai_requests', function (Blueprint $table) {
            $table->dropColumn(['engine', 'tools_used']);
        });
    }
};
