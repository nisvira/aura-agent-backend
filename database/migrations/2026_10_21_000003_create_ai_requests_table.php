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
        Schema::create('ai_requests', function (Blueprint $table) {
            $table->id();
            $table->string('request_id', 26)->nullable()->index();
            $table->unsignedBigInteger('user_id')->nullable();
            // Links to the chat history instead of copying the question/answer text (PDPA).
            $table->unsignedBigInteger('session_id')->nullable();
            $table->unsignedBigInteger('question_message_id')->nullable();
            $table->unsignedBigInteger('answer_message_id')->nullable();
            $table->string('channel', 16)->comment('api, api_stream, console, openwebui');
            $table->boolean('is_task')->default(false)->comment('Open WebUI background job: title, tags, follow-ups');
            $table->string('status', 16)->comment('success, error, aborted');
            $table->unsignedInteger('question_chars')->default(0);
            $table->string('rag_status', 16)->nullable()->comment('ok, error; null when RAG was not called');
            $table->unsignedInteger('rag_ms')->nullable();
            $table->unsignedSmallInteger('rag_hits')->nullable();
            $table->float('rag_top_score')->nullable();
            $table->string('model', 100)->nullable();
            $table->unsignedInteger('prompt_tokens')->nullable();
            $table->unsignedInteger('completion_tokens')->nullable();
            $table->unsignedInteger('ttft_ms')->nullable()->comment('Time to first token (streaming only)');
            $table->unsignedInteger('llm_ms')->nullable();
            $table->unsignedInteger('total_ms')->nullable();
            $table->string('error', 500)->nullable();
            $table->timestamp('created_at')->useCurrent()->index();

            $table->index(['status', 'created_at']);
            $table->index(['rag_hits', 'created_at']);
            $table->index(['user_id', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ai_requests');
    }
};
