<?php

return [

    // AURA-AGENT accepts 1–1000 characters per message.
    'max_message_length' => (int) env('CHAT_MAX_MESSAGE_LENGTH', 1000),

    'rate_limit' => [
        'per_minute' => (int) env('CHAT_RATE_LIMIT_PER_MINUTE', 10),
        'per_day' => (int) env('CHAT_RATE_LIMIT_PER_DAY', 300),
    ],

    // Lifetime of the Sanctum API token issued after a YRU Passport login.
    'token_ttl_days' => (int) env('API_TOKEN_TTL_DAYS', 7),

    // Where the browser is sent after the OAuth callback; the token is passed in the URL fragment.
    'frontend_callback_url' => env('FRONTEND_CALLBACK_URL', 'http://localhost:3000/auth/callback'),

];
