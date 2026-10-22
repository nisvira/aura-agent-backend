<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Answers now come only from AURA-AGENT, which does its own data lookup,
     * so there is no RAG step left to measure.
     */
    public function up(): void
    {
        Schema::table('ai_requests', function (Blueprint $table) {
            $table->dropIndex(['rag_hits', 'created_at']);
            $table->dropColumn(['rag_status', 'rag_ms', 'rag_hits', 'rag_top_score']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('ai_requests', function (Blueprint $table) {
            $table->string('rag_status', 16)->nullable()->after('question_chars');
            $table->unsignedInteger('rag_ms')->nullable()->after('rag_status');
            $table->unsignedSmallInteger('rag_hits')->nullable()->after('rag_ms');
            $table->float('rag_top_score')->nullable()->after('rag_hits');
            $table->index(['rag_hits', 'created_at']);
        });
    }
};
