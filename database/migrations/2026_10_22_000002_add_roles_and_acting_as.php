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
        // Role derived from YRU Passport claims at each login (student / instructor / staff).
        // Admin is layered on top from config, so it is not stored here.
        Schema::table('users', function (Blueprint $table) {
            $table->string('role', 16)->nullable()->after('username')->index();
        });

        // A conversation an admin or instructor holds about one student.
        Schema::table('chat_sessions', function (Blueprint $table) {
            $table->string('acting_as_student', 20)->nullable()->after('agent_thread_id')->index();
        });

        Schema::table('ai_requests', function (Blueprint $table) {
            $table->string('acting_as_student', 20)->nullable()->after('user_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn('role'));
        Schema::table('chat_sessions', fn (Blueprint $table) => $table->dropColumn('acting_as_student'));
        Schema::table('ai_requests', fn (Blueprint $table) => $table->dropColumn('acting_as_student'));
    }
};
