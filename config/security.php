<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Threat detection
    |--------------------------------------------------------------------------
    |
    | The ThreatDetector middleware inspects every request (web + API) before
    | it reaches the application. Findings at or above the block threshold
    | severity (1-10) block the request and blocklist the IP/device. Findings
    | below the threshold are recorded and feed the Fraud Engine.
    |
    */

    'enabled' => env('SECURITY_THREAT_DETECTION', true),

    'block_threshold' => 8,

    // Paths (wildcards allowed) skipped by payload detectors. The IP/device
    // blocklist is always enforced, even on exempt paths.
    'exempt_paths' => [
        'up',
    ],

    // Name of the cookie holding the FingerprintJS visitor id. Must stay in
    // sync with resources/js/fingerprint.js and the encryptCookies exception
    // in bootstrap/app.php.
    'fingerprint_cookie' => 'device_fp',

    // Hard limits so the detectors themselves cannot be used for DoS.
    'max_inspect_bytes' => 65536,
    'max_inputs' => 200,

    /*
    |--------------------------------------------------------------------------
    | Fraud engine
    |--------------------------------------------------------------------------
    */

    'fraud' => [
        // Re-evaluate a user's fraud score at most once per this many minutes
        // (also runs immediately when a new device/IP association appears).
        'evaluation_throttle_minutes' => 60,

        'levels' => [
            'low' => ['min' => 0, 'max' => 29],
            'medium' => ['min' => 30, 'max' => 59],
            'high' => ['min' => 60, 'max' => 79],
            'critical' => ['min' => 80, 'max' => 100],
        ],
    ],

];
