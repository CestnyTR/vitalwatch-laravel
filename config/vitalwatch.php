<?php

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Session\TokenMismatchException;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

return [

    /*
    |--------------------------------------------------------------------------
    | On / off
    |--------------------------------------------------------------------------
    |
    | When false, NO hooks are installed — zero overhead. If you suspect a
    | problem, this turns monitoring off without redeploying the application.
    |
    */

    'enabled' => env('VITALWATCH_ENABLED', true),

    /*
    |--------------------------------------------------------------------------
    | Hub connection
    |--------------------------------------------------------------------------
    |
    | The secret lives ONLY on the server; never put it in the browser. Browser
    | errors use the separate, key-based t.js script.
    |
    */

    'url' => env('VITALWATCH_URL'),
    'key' => env('VITALWATCH_KEY'),
    'secret' => env('VITALWATCH_SECRET'),

    // When empty, the application's own environment is used.
    'env' => env('VITALWATCH_ENV'),

    // Deploy tag, to find out which release introduced an error. When empty,
    // it is detected at runtime from a CI variable or from `.git`.
    'release' => env('VITALWATCH_RELEASE'),

    /*
    |--------------------------------------------------------------------------
    | Thresholds (milliseconds)
    |--------------------------------------------------------------------------
    |
    | Not every request is sent, only those above the threshold. This keeps the
    | volume low, and every record that arrives is one worth looking at.
    |
    */

    'slow_request_ms' => (int) env('VITALWATCH_SLOW_REQUEST_MS', 1000),
    'slow_query_ms' => (int) env('VITALWATCH_SLOW_QUERY_MS', 500),

    /*
    |--------------------------------------------------------------------------
    | Personal data
    |--------------------------------------------------------------------------
    |
    | user_id ties a record to an identifiable person. Off by default; turning
    | it on is a deliberate decision and should be reviewed under your data
    | protection rules (GDPR, KVKK).
    |
    | IP address, User-Agent, request body, query string values, session and
    | cookie data cannot be collected even with this setting — the code for it
    | does not exist.
    |
    */

    'capture_user_id' => (bool) env('VITALWATCH_CAPTURE_USER_ID', false),

    /*
    |--------------------------------------------------------------------------
    | Ignored exception classes
    |--------------------------------------------------------------------------
    |
    | Expected control-flow exceptions only create noise in the panel.
    | Subclasses match as well.
    |
    */

    'ignore' => [
        AuthenticationException::class,
        AuthorizationException::class,
        ValidationException::class,
        TokenMismatchException::class,
        NotFoundHttpException::class,
        MethodNotAllowedHttpException::class,
    ],

    /*
    |--------------------------------------------------------------------------
    | Network
    |--------------------------------------------------------------------------
    |
    | Kept short: even when sent during terminate, the report keeps the PHP
    | process busy. If the hub does not answer, the report is silently dropped.
    |
    */

    /*
    | Seconds. Values of 100 and above are read as milliseconds, so
    | `VITALWATCH_TIMEOUT=2000` (the Node package's unit) means 2 seconds.
    | Fractions are allowed (`0.5`). Invalid, zero, negative or infinite
    | values fall back to 2 s; the result is clamped to 0.1–10 s.
    */
    'timeout' => env('VITALWATCH_TIMEOUT', 2),

];
