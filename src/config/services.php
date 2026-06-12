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

    // P-G6 — pos_api's Reverb server (pusher-protocol), reached over the
    // shared charity_net docker network so this app can nudge POS devices
    // ('message.created') without its own broadcasting stack. SAME app
    // id/key/secret as pos_api. Unset = ReverbPublisher no-ops silently.
    'reverb' => [
        'app_id' => env('REVERB_APP_ID'),
        'key' => env('REVERB_APP_KEY'),
        'secret' => env('REVERB_APP_SECRET'),
        'host' => env('REVERB_HOST'),
        'port' => env('REVERB_PORT', 8080),
        'scheme' => env('REVERB_SCHEME', 'http'),
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

    // P-G9 — the merchant's restricted device Live (MDM) surface.
    // SAME token as pos_admin (one scalefusion account fleet-wide);
    // unset ⇒ every Live call degrades to a clean "not configured"
    // failure instead of erroring. Only the keys the slim
    // App\Support\ScalefusionClient reads — the admin-side caching
    // knobs have no counterpart here.
    'scalefusion' => [
        'token' => env('SCALEFUSION_TOKEN'),
        'base_v3' => env('SCALEFUSION_BASE_V3', 'https://api.scalefusion.com/api/v3'),
        'base_v1' => env('SCALEFUSION_BASE_V1', 'https://api.scalefusion.com/api/v1'),
        'http_timeout_seconds' => (int) env('SCALEFUSION_TIMEOUT_SECONDS', 12),
    ],

];
