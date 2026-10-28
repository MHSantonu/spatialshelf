<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Meta Model API
    |--------------------------------------------------------------------------
    |
    | Credentials and base URL for the Meta Model API, which is
    | OpenAI-compatible. Models never write to the DB; they return JSON
    | that PHP validates before anything is priced or saved.
    |
    */

    'model_api_key' => env('MODEL_API_KEY'),

    'meta_base_url' => env('META_BASE_URL', 'https://api.meta.ai/v1'),

    /*
    |--------------------------------------------------------------------------
    | Python worker
    |--------------------------------------------------------------------------
    */

    'worker_url' => env('WORKER_URL', 'http://127.0.0.1:8080'),

    /*
    |--------------------------------------------------------------------------
    | Shared storage
    |--------------------------------------------------------------------------
    |
    | Absolute path to storage-shared/ at the repo root, read and written
    | by both Laravel (via the "shared" filesystem disk) and the worker.
    |
    */

    'shared_storage_path' => env('SHARED_STORAGE_PATH', realpath(base_path('../storage-shared')) ?: base_path('../storage-shared')),

    /*
    |--------------------------------------------------------------------------
    | Scan grouping thresholds
    |--------------------------------------------------------------------------
    */

    'group_threshold' => (float) env('GROUP_THRESHOLD', 0.90),

    'match_distance' => (float) env('MATCH_DISTANCE', 0.15),

    /*
    |--------------------------------------------------------------------------
    | WhatsApp Cloud API
    |--------------------------------------------------------------------------
    */

    'wa_phone_number_id' => env('WA_PHONE_NUMBER_ID'),

    'wa_business_account_id' => env('WA_BUSINESS_ACCOUNT_ID'),

    'wa_access_token' => env('WA_ACCESS_TOKEN'),

    'wa_app_secret' => env('WA_APP_SECRET'),

    'wa_verify_token' => env('WA_VERIFY_TOKEN'),

];
