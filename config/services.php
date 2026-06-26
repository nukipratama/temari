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

    'strava' => [
        'client_id' => env('STRAVA_CLIENT_ID'),
        'client_secret' => env('STRAVA_CLIENT_SECRET'),
        'redirect' => null,
        // Shared secret echoed back to Strava during the webhook subscription
        // handshake (GET /strava/webhook?hub.verify_token=...).
        'webhook_verify_token' => env('STRAVA_WEBHOOK_VERIFY_TOKEN'),
        // Strava's id for the active push subscription. Set after creating it
        // via `php artisan strava:webhook-subscribe --action=create`.
        'webhook_subscription_id' => env('STRAVA_WEBHOOK_SUBSCRIPTION_ID'),
    ],

    'telegram' => [
        'bot_token' => env('TELEGRAM_BOT_TOKEN'),
        'bot_username' => env('TELEGRAM_BOT_USERNAME'),
        // Random shared secret echoed back by Telegram in the
        // X-Telegram-Bot-Api-Secret-Token header on every webhook delivery; set
        // it when registering the webhook via `php artisan telegram:set-webhook`.
        'webhook_secret' => env('TELEGRAM_WEBHOOK_SECRET'),
    ],

];
