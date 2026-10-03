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

    // The one Jira site, read with an API token as in jira-outbox (CONCEPT.md §13).
    'jira' => [
        'base' => rtrim((string) env('JIRA_BASE', ''), '/'),
        'email' => env('JIRA_EMAIL'),
        'token' => env('JIRA_TOKEN'),
        // dummy: nothing reaches Jira. Anything but "real" counts as dummy.
        'write' => env('JIRA_WRITE') === 'real' ? 'real' : 'dummy',
        // Where a ticket opens when you click it: Jira itself by default, or another app
        // that takes the key, e.g. https://jira.techfactory.dev/{key} for jira-outbox.
        'open_url' => env('JIRA_OPEN_URL'),
        'open_label' => env('JIRA_OPEN_LABEL', 'Jira'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

];
