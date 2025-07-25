<?php

/**
 * Example configuration file for Phatru PHP Relay
 * Copy this file to config.php and modify the values
 */

 return [
    'database' => [
        'host' => '127.0.0.1',
        'port' => 3306,
        'name' => 'nostr_relay',
        'username' => 'YOUR_DB_USERNAME',
        'password' => 'YOUR_DB_PASSWORD',
        'charset' => 'utf8mb4',
        'table_name' => 'events',
    ],

    // Relay server configuration
    'server' => [
        'host' => '127.0.0.1',
        'port' => 8090,
        'ssl' => false,
        'ssl_options' => [],
        'error_reporting' => [
            'suppress_deprecations' => true, // Set to false to show deprecation warnings from Ratchet
            'level' => E_ALL & ~E_DEPRECATED & ~E_USER_DEPRECATED, // Error level when deprecations are suppressed
        ],
    ],

    // Relay information (NIP-11)
    'info' => [
        'name' => 'Phatru Test Relay',
        'description' => 'A test Nostr relay built with Phatru PHP',
        'pubkey' => 'test_pubkey_here',
        'contact' => 'test@example.com',
        'supported_nips' => [1, 2, 9, 11, 15, 16, 20, 22, 33, 40],
        'software' => 'phatru',
        'version' => '0.1.0',
    ],
];
