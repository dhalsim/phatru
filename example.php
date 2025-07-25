<?php

require_once __DIR__ . '/vendor/autoload.php';

use Phatru\Relay;
use Phatru\MySQLStore;
use Phatru\Policies;

// Load configuration
$config = require __DIR__ . '/config.php';

// Create the relay instance
$relay = new Relay();

// Configure error reporting based on config
$relay->configureErrorReporting($config);

// Set up MySQL connection
$pdo = new PDO(
    "mysql:host={$config['database']['host']};port={$config['database']['port']};dbname={$config['database']['name']};charset={$config['database']['charset']}",
    $config['database']['username'],
    $config['database']['password'],
    [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]
);

// Create and initialize MySQL store
$mysqlStore = new MySQLStore($pdo, 'events');
if (!$mysqlStore->init()) {
    die("Failed to initialize MySQL store\n");
}

// Set up relay info
$relay->setInfo([
    'name' => 'My Custom Relay',
    'description' => 'A custom relay with specific policies',
    'pubkey' => 'YOUR_RELAY_PUBKEY_HERE',
    'contact' => 'admin@myrelay.com'
]);

// Add event validation policies
$relay->addRejectEventHandler(
    Policies::preventKinds([4, 5]) // Block encrypted DMs and event deletions
);

$relay->addRejectEventHandler(
    Policies::preventLargeTags(50) // Prevent events with more than 50 tags
);

$relay->addRejectEventHandler(
    Policies::preventLargeContent(10000) // Prevent events with content > 10KB
);

$relay->addRejectEventHandler(
    Policies::preventOldEvents(86400 * 30) // Prevent events older than 30 days
);

$relay->addRejectEventHandler(
    Policies::validateKind0() // Validate kind 0 metadata events
);

// Add kind-specific policies
$relay->addRejectEventHandlerForKind(1, 
    Policies::preventLargeTags(20) // Text notes can have max 20 tags
);

$relay->addRejectEventHandlerForKind(3, 
    Policies::requireContentForKinds([3]) // Contact lists need content
);

// Add storage handlers
$relay->addStoreEventHandler([$mysqlStore, 'store']);
$relay->addQueryEventsHandler([$mysqlStore, 'query']);
$relay->addCountEventsHandler([$mysqlStore, 'count']);
$relay->addDeleteEventHandler([$mysqlStore, 'delete']);
$relay->addReplaceEventHandler([$mysqlStore, 'replace']);

// Add filter validation policies
$relay->addRejectFilterHandler(function ($context, $filters) {
    // Prevent complex filters with too many conditions
    foreach ($filters as $filter) {
        $conditionCount = 0;
        if (isset($filter['ids'])) $conditionCount += count($filter['ids']);
        if (isset($filter['authors'])) $conditionCount += count($filter['authors']);
        if (isset($filter['kinds'])) $conditionCount += count($filter['kinds']);
        if (isset($filter['since'])) $conditionCount++;
        if (isset($filter['until'])) $conditionCount++;
        
        if ($conditionCount > 10) {
            return [true, "Filter too complex"];
        }
    }
    return [false, ''];
});

echo "Starting Phatru PHP Relay...\n";
echo "MySQL connection: OK\n";
echo "Policies loaded: " . count($relay->onRejectEvent) . " general\n";

// Run the relay
$relay->run($config['server']['host'], $config['server']['port']); 