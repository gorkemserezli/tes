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

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'paytr' => [
        'merchant_id' => env('PAYTR_MERCHANT_ID'),
        'merchant_key' => env('PAYTR_MERCHANT_KEY'),
        'merchant_salt' => env('PAYTR_MERCHANT_SALT'),
        'success_url' => env('PAYTR_SUCCESS_URL'),
        'fail_url' => env('PAYTR_FAIL_URL'),
        'base_url' => env('PAYTR_BASE_URL', 'https://www.paytr.com/odeme/api/get-token'),
        'test_mode' => env('PAYTR_TEST_MODE', false),
    ],

    'aras' => [
        'username' => env('ARAS_USERNAME'),
        'password' => env('ARAS_PASSWORD'),
        'customer_code' => env('ARAS_CUSTOMER_CODE'),
        'api_url' => env('ARAS_API_URL', 'https://customerservices.araskargo.com.tr'),
        'webhook_secret' => env('ARAS_WEBHOOK_SECRET'),
    ],

];
