<?php

return [

    /*
    |--------------------------------------------------------------------------
    | System Prompt
    |--------------------------------------------------------------------------
    |
    | Instructions sent to the LLM on every turn. Retrieved RAG context is
    | appended below this text by App\Services\Chat\PromptBuilder.
    |
    */

    'system_prompt' => env('CHAT_SYSTEM_PROMPT', <<<'PROMPT'
คุณคือ Aura ผู้ช่วย AI ของมหาวิทยาลัยราชภัฏยะลา
- ตอบเป็นภาษาไทยที่สุภาพและกระชับ เว้นแต่ผู้ใช้ถามเป็นภาษาอื่น
- ใช้ "ข้อมูลอ้างอิง" ที่ให้มาเป็นแหล่งข้อมูลหลัก และระบุหมายเลขอ้างอิง เช่น [1] เมื่อใช้ข้อมูลนั้น
- หากข้อมูลอ้างอิงไม่เพียงพอที่จะตอบ ให้บอกตรง ๆ ว่าไม่พบข้อมูล ห้ามแต่งข้อมูลขึ้นเอง
PROMPT),

    // Number of previous messages in the session sent to the LLM as conversation history.
    'history_limit' => (int) env('CHAT_HISTORY_LIMIT', 10),

    // Upper bound on characters of RAG context placed in the prompt (keeps it inside num_ctx).
    'max_context_chars' => (int) env('CHAT_MAX_CONTEXT_CHARS', 8000),

    'max_message_length' => (int) env('CHAT_MAX_MESSAGE_LENGTH', 4000),

    'rate_limit' => [
        'per_minute' => (int) env('CHAT_RATE_LIMIT_PER_MINUTE', 10),
        'per_day' => (int) env('CHAT_RATE_LIMIT_PER_DAY', 300),
    ],

    // Lifetime of the Sanctum API token issued after a YRU Passport login.
    'token_ttl_days' => (int) env('API_TOKEN_TTL_DAYS', 7),

    // Where the browser is sent after the OAuth callback; the token is passed in the URL fragment.
    'frontend_callback_url' => env('FRONTEND_CALLBACK_URL', 'http://localhost:3000/auth/callback'),

];
