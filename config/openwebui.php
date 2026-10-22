<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Service API Key
    |--------------------------------------------------------------------------
    |
    | Open WebUI sends this as "Authorization: Bearer <key>" on every call to
    | /api/v1/*. Only requests carrying it may identify the end user through
    | the forwarded X-OpenWebUI-User-* headers. Leave empty to disable.
    |
    */

    'api_key' => env('OPENWEBUI_API_KEY'),

    // Model shown in Open WebUI's model picker; its answers come from AURA-AGENT.
    'model_id' => env('OPENWEBUI_MODEL_ID', 'aura-agent'),

    // Comma-separated; Open WebUI users with other email domains are rejected. Empty = allow all.
    'allowed_email_domains' => array_filter(array_map('trim', explode(',', (string) env('OPENWEBUI_ALLOWED_EMAIL_DOMAINS', 'yru.ac.th')))),

];
