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
        'scopes' => env('YRU_PASSPORT_SCOPES', ''),
        'authorize_path' => env('YRU_PASSPORT_AUTHORIZE_PATH', '/oauth/authorize'),
        'token_path' => env('YRU_PASSPORT_TOKEN_PATH', '/oauth/token'),
        'profile_path' => env('YRU_PASSPORT_PROFILE_PATH', '/apis/v1/profile'),
        'timeout' => (int) env('YRU_PASSPORT_TIMEOUT', 10),
    ],

    'rag' => [
        'base_url' => env('RAG_API_URL', 'http://127.0.0.1:8001'),
        'search_path' => env('RAG_SEARCH_PATH', '/api/rag/search'),
        'api_key' => env('RAG_API_KEY'),
        'top_k' => (int) env('RAG_TOP_K', 5),
        'min_score' => (float) env('RAG_MIN_SCORE', 0),
        'timeout' => (int) env('RAG_TIMEOUT', 15),
    ],

    'ollama' => [
        'base_url' => env('OLLAMA_URL', 'http://127.0.0.1:11434'),
        'model' => env('OLLAMA_MODEL', 'llama3.1:8b'),
        'temperature' => (float) env('OLLAMA_TEMPERATURE', 0.3),
        'num_ctx' => (int) env('OLLAMA_NUM_CTX', 8192),
        'keep_alive' => env('OLLAMA_KEEP_ALIVE', '10m'),
        'timeout' => (int) env('OLLAMA_TIMEOUT', 180),
    ],

];
