<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
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

    'fcm' => [
        'project_id' => env('FCM_PROJECT_ID', 'lizto-app'),
        'credentials_file' => env('FCM_CREDENTIALS_FILE'),
        'credentials_json' => env('FCM_CREDENTIALS_JSON'),
    ],

    'didit' => [
        'base_url' => env('DIDIT_BASE_URL', 'https://verification.didit.me/v3'),
        'api_key' => env('DIDIT_API_KEY', ''),
        'workflow_id' => env('DIDIT_WORKFLOW_ID', '1a3cf8eb-1e92-4554-bb91-2017577cf811'),
        'webhook_secret' => env('DIDIT_WEBHOOK_SECRET', ''),
        'timeout' => (int) env('DIDIT_TIMEOUT', 15),
    ],

];
