<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Resend, Postmark, AWS, and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'yru_passport' => [
        'base_url' => env('YRU_PASSPORT_URL', 'https://passport.yru.ac.th'),
        'client_id' => env('YRU_PASSPORT_CLIENT_ID'),
        'client_secret' => env('YRU_PASSPORT_CLIENT_SECRET'),
        'redirect_uri' => env('YRU_PASSPORT_REDIRECT_URI'),
        'scopes' => env('YRU_PASSPORT_SCOPES', 'openid profile email'),
        'authorize_path' => env('YRU_PASSPORT_AUTHORIZE_PATH', '/oauth/authorize'),
        'token_path' => env('YRU_PASSPORT_TOKEN_PATH', '/oauth/token'),
        'profile_path' => env('YRU_PASSPORT_PROFILE_PATH', '/oidc/userinfo'),
        // Passport has no OIDC end_session/revocation endpoint; this is its own web logout
        // page. It does not redirect back, so the user ends on the Passport login page.
        'logout_url' => env('YRU_PASSPORT_LOGOUT_URL', 'https://passport.yru.ac.th/auth/logout'),
        'timeout' => (int) env('YRU_PASSPORT_TIMEOUT', 10),
    ],

    // AURA-AGENT: answers students' activity questions (does its own retrieval + LLM).
    // Disabled until both the URL and the service key are set.
    'aura_agent' => [
        'base_url' => env('AURA_AGENT_URL'),
        'service_key' => env('AURA_AGENT_SERVICE_KEY'),
        // Second key for asking on behalf of a student (admins / instructors). Empty until
        // AURA-AGENT implements the support mode proposal; acting as a student is off until then.
        'support_key' => env('AURA_AGENT_SUPPORT_KEY'),
        'timeout' => (int) env('AURA_AGENT_TIMEOUT', 180),
    ],

    // Only used for Open WebUI background jobs (chat titles, tags); answers come from AURA-AGENT.
    'ollama' => [
        'base_url' => env('OLLAMA_URL', 'http://127.0.0.1:11434'),
        'model' => env('OLLAMA_MODEL', 'llama3.1:8b'),
        'temperature' => (float) env('OLLAMA_TEMPERATURE', 0.3),
        'num_ctx' => (int) env('OLLAMA_NUM_CTX', 8192),
        'keep_alive' => env('OLLAMA_KEEP_ALIVE', '10m'),
        // Reasoning models (qwen3, deepseek-r1...) think before answering; off = much faster replies.
        'think' => (bool) env('OLLAMA_THINK', false),
        'timeout' => (int) env('OLLAMA_TIMEOUT', 180),
    ],

];
