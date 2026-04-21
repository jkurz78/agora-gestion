<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | OTP (One-Time Password) settings
    |--------------------------------------------------------------------------
    */

    // Number of digits in the OTP code
    'otp_length' => 8,

    // OTP validity window in minutes
    'otp_ttl_minutes' => 10,

    // Maximum failed verification attempts before cooldown
    'otp_max_attempts' => 3,

    // Cooldown duration in minutes after max_attempts reached
    'otp_cooldown_minutes' => 15,

    // Minimum seconds required between two OTP send requests
    'otp_resend_seconds' => 60,

    /*
    |--------------------------------------------------------------------------
    | Session settings (portail only — do not touch config/session.php)
    |--------------------------------------------------------------------------
    */

    // Portail session lifetime in minutes (sliding)
    'session_lifetime_minutes' => 60,

    /*
    |--------------------------------------------------------------------------
    | OCR / Justificatif analyser (préparation v1 Claude API)
    |--------------------------------------------------------------------------
    */

    'ocr' => [
        'driver' => env('PORTAIL_OCR_DRIVER', 'none'), // none | claude
        'claude_api_key' => env('PORTAIL_CLAUDE_API_KEY'),
        'claude_model' => env('PORTAIL_CLAUDE_MODEL', 'claude-haiku-4-5-20251001'),
    ],

];
