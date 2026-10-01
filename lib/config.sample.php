<?php
// Copy this file to config.php DIRECTLY ON THE SERVER (never in git) and fill in real values.
// config.php is gitignored and must never be committed.

return [
    'db' => [
        'host' => '10.35.249.85',       // Netcup internal MySQL host, see WCP
        'name' => 'k430430_lagohm',
        'user' => 'k430430_lagohm',
        'pass' => 'CHANGE_ME',
    ],

    // Random long string used to encrypt the Google refresh token at rest.
    // Generate once with: php -r "echo bin2hex(random_bytes(32));"
    'encryption_key_hex' => 'CHANGE_ME_64_HEX_CHARS',

    // Uses the helena@lagohm.de mailbox already set up. Create a dedicated
    // address (e.g. buchung@lagohm.de) later if you prefer to separate it.
    'smtp' => [
        'host' => 'mxf956.netcup.net',
        'port' => 587,
        'user' => 'helena@lagohm.de',
        'pass' => 'CHANGE_ME',
        'from_email' => 'helena@lagohm.de',
        'from_name' => 'LagOHM',
    ],

    'google' => [
        'client_id' => 'CHANGE_ME',
        'client_secret' => 'CHANGE_ME',
        'redirect_uri' => 'https://lagohm.de/admin/oauth-callback.php',
    ],

    'business' => [
        'address' => "Lindenstr. 11a\n81545 München",
    ],

    'app' => [
        'base_url' => 'https://lagohm.de',
        'timezone' => 'Europe/Berlin',
    ],
];
