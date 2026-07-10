<?php

return [

    'spss' => [
        'python' => env('SPSS_PYTHON_BINARY', 'python3'),
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

    'openai' => [
        'key' => env('OPENAI_API_KEY'),
        'base_url' => env('OPENAI_BASE_URL', 'https://api.openai.com/v1'),
        'transcription_model' => env('OPENAI_TRANSCRIPTION_MODEL', 'gpt-4o-mini-transcribe'),
        'transcription_language' => env('OPENAI_TRANSCRIPTION_LANGUAGE', 'es'),
        'soap_model' => env('OPENAI_SOAP_MODEL', 'gpt-5.4-nano'),
        'soap_effort' => env('OPENAI_SOAP_EFFORT', 'low'),
        'timeout' => env('OPENAI_TIMEOUT', 120),
        'pricing' => [
            'soap_input_cost_per_1m' => (float) env('OPENAI_SOAP_INPUT_COST_PER_1M', 0),
            'soap_output_cost_per_1m' => (float) env('OPENAI_SOAP_OUTPUT_COST_PER_1M', 0),
            'transcription_cost_per_minute' => (float) env('OPENAI_TRANSCRIPTION_COST_PER_MINUTE', 0),
        ],
    ],

];
