<?php

/**
 * Example configuration file for Phatru PHP Relay
 * Copy this file to config.php and modify the values
 */

return [
    // Database configuration
    'database' => [
        'host' => 'YOUR_DB_HOST',
        'port' => 3306,
        'name' => 'YOUR_DB_NAME',
        'username' => 'YOUR_DB_USERNAME',
        'password' => 'YOUR_DB_PASSWORD',
        'charset' => 'utf8mb4',
        'table_name' => 'events',
    ],

    // Relay server configuration
    'server' => [
        'host' => '0.0.0.0',
        'port' => 8080,
        'ssl' => false,
        'ssl_options' => [
            'local_cert' => '/path/to/cert.pem',
            'local_pk' => '/path/to/key.pem',
            'passphrase' => '',
        ],
    ],

    // Relay information (NIP-11)
    'info' => [
        'name' => 'My Custom Relay',
        'description' => 'A custom Nostr relay built with Phatru PHP',
        'pubkey' => 'YOUR_RELAY_PUBKEY_HERE',
        'contact' => 'admin@myrelay.com',
        'supported_nips' => [1, 2, 9, 11, 15, 16, 20, 22, 33, 40],
        'software' => 'phatru',
        'version' => '0.1.0',
    ],

    // Event validation policies
    'policies' => [
        // General policies
        'general' => [
            'prevent_kinds' => [4, 5], // Block encrypted DMs and deletions
            'max_tags' => 50,
            'max_content_length' => 10000,
            'max_future_seconds' => 300,
            'max_age_seconds' => 86400 * 30, // 30 days
        ],

        // Kind-specific policies
        'kind_specific' => [
            1 => [ // Text notes
                'max_tags' => 20,
                'max_content_length' => 1000,
                'require_content' => true,
            ],
            3 => [ // Contact lists
                'require_content' => true,
                'require_tags' => ['p'],
            ],
            7 => [ // Reaction events
                'max_tags' => 10,
                'require_tags' => ['e', 'p'],
            ],
        ],

        // Authentication requirements
        'auth_required_kinds' => [3, 4, 5],

        // Blocked pubkeys (optional)
        'blocked_pubkeys' => [],

        // Allowed pubkeys only (optional, if empty allows all)
        'allowed_pubkeys' => [],

        // Filter validation
        'max_filter_complexity' => 10,
    ],

    // Logging configuration
    'logging' => [
        'enabled' => true,
        'level' => 'info', // debug, info, warning, error
        'file' => '/var/log/phatru-relay.log',
    ],

    // Rate limiting (optional)
    'rate_limiting' => [
        'enabled' => false,
        'max_events_per_minute' => 60,
        'max_connections_per_ip' => 10,
    ],
]; 
