<?php

return [

    'openai' => [
        'api_key' => env('OPENAI_API_KEY'),
        // Opt in explicitly. Without this, the planner remains fully deterministic.
        'planner_enabled' => env('OPENAI_PLANNER_ENABLED', false),
    ],

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
        'token' => env('POSTMARK_TOKEN'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'resend' => [
        'key' => env('RESEND_KEY'),
    ],

    'google' => [
        'client_id' => env('GOOGLE_CLIENT_ID'),
        'client_secret' => env('GOOGLE_CLIENT_SECRET'),
        'redirect' => env('GOOGLE_REDIRECT_URI'),
    ],

    // Kept separate from Google sign-in so Calendar consent never expands the
    // scopes granted to the authentication flow.
    'google_calendar' => [
        'client_id' => env('GOOGLE_CALENDAR_CLIENT_ID'),
        'client_secret' => env('GOOGLE_CALENDAR_CLIENT_SECRET'),
        'redirect' => env('GOOGLE_CALENDAR_REDIRECT_URI'),
    ],

    'youtube' => [
        'data_api_key' => env('YOUTUBE_DATA_API_KEY'),
        'base_url' => 'https://www.googleapis.com/youtube/v3',
    ],

    'gemini' => [
        'api_key' => env('GEMINI_API_KEY'),
        'model' => env('GEMINI_MODEL', 'gemini-3.5-flash'),
        'base_url' => 'https://generativelanguage.googleapis.com',
    ],

    'catalog_ai' => [
        // Separate from the shared Gemini model and OpenAI Smart Planner.
        'model' => env('CATALOG_AI_MODEL', 'gemini-3.5-flash-lite'),
        // The key inherits its project's billing tier; a model name cannot prove it.
        'free_tier_confirmed' => env('CATALOG_AI_FREE_TIER_CONFIRMED', false),
    ],

    'catalog_search' => [
        'tavily_key' => env('TAVILY_API_KEY'),
        'tavily_free_confirmed' => env('CATALOG_SEARCH_TAVILY_FREE_CONFIRMED', false),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

];
