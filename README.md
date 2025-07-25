# Phatru PHP Relay

A PHP backend library that models **[Khatru](https://github.com/fiatjaf/khatru)'s architecture**—with modular, callback-driven event pipelines—for building custom Nostr relays.

## Features

- **Modular Event Handlers**: Attach multiple handlers for event validation, storage, and querying
- **Kind-Specific Policies**: Define validation rules for specific event kinds
- **MySQL/InnoDB Storage**: Robust event storage with proper indexing
- **WebSocket Server**: Full Nostr protocol support using Ratchet
- **Policy Plugins**: Ready-to-use validation policies
- **Extensible Architecture**: Easy to extend with custom handlers and policies

## Installation

```bash
# Clone or download the library
git clone <repository-url>
cd php/

# Check requirements first
php check-requirements.php

# Install dependencies
composer install

# Configure the relay
cp config.example.php config.php
# Edit config.php with your settings

# Run setup
php setup-database.php
```

## Quick Start

```php
<?php

require_once 'vendor/autoload.php';

use Khatru\Relay;
use Khatru\MySQLStore;
use Khatru\Policies;
use PDO;

// Create relay instance
$relay = new Relay();

// Set up MySQL storage
$pdo = new PDO('mysql:host=localhost;dbname=nostr_relay', 'user', 'pass');
$mysqlStore = new MySQLStore($pdo);
$mysqlStore->init();

// Add policies
$relay->addRejectEventHandler(Policies::preventKinds([4, 5]));
$relay->addRejectEventHandlerForKind(1, Policies::preventLargeTags(20));

// Add storage handlers
$relay->addStoreEventHandler([$mysqlStore, 'store']);
$relay->addQueryEventsHandler([$mysqlStore, 'query']);

// Run the relay
$relay->run('0.0.0.0', 8080);
```

## API Reference

### Relay Class

The main relay class that handles WebSocket connections and event processing.

#### Constructor
```php
$relay = new Relay();
```

#### Handler Registration Methods

##### Event Rejection Handlers
```php
// General rejection handler
$relay->addRejectEventHandler(callable $handler);

// Kind-specific rejection handler
$relay->addRejectEventHandlerForKind(int $kind, callable $handler);
```

##### Storage Handlers
```php
$relay->addStoreEventHandler(callable $handler);
$relay->addQueryEventsHandler(callable $handler);
$relay->addCountEventsHandler(callable $handler);
$relay->addDeleteEventHandler(callable $handler);
$relay->addReplaceEventHandler(callable $handler);
```

##### Filter Rejection Handlers
```php
$relay->addRejectFilterHandler(callable $handler);
```

#### Configuration Methods
```php
// Set relay information
$relay->setInfo([
    'name' => 'My Relay',
    'description' => 'A custom relay',
    'pubkey' => 'YOUR_PUBKEY',
    'contact' => 'admin@relay.com'
]);

// Run the server
$relay->run(string $host = '0.0.0.0', int $port = 8080, bool $ssl = false);
```

### Handler Signatures

#### Event Rejection Handlers
```php
function(Context $ctx, Event $event): array
// Returns: [bool $rejected, string $reason]
```

#### Storage Handlers
```php
// Store handler
function(Event $event): bool

// Query handler
function(array $filters): iterable<Event>

// Count handler
function(array $filters): int

// Delete handler
function(string $eventId, string $pubkey): bool

// Replace handler
function(Event $event): bool
```

#### Filter Rejection Handlers
```php
function(Context $ctx, array $filters): array
// Returns: [bool $rejected, string $reason]
```

### Event Class

Represents a Nostr event with helper methods.

```php
$event = new Event(
    string $id,
    string $pubkey,
    int $created_at,
    int $kind,
    array $tags,
    string $content,
    string $sig
);

// Create from array
$event = Event::fromArray($data);

// Convert to array
$data = $event->toArray();

// Tag helpers
$value = $event->getTag(string $name);
$values = $event->getTags(string $name);
$hasTag = $event->hasTag(string $name);
```

### Context Class

Provides context for event handlers.

```php
$context = new Context(ConnectionInterface $connection);

// Authentication
$context->isAuthenticated(): bool
$context->getAuthenticatedPubkey(): ?string
$context->setAuthenticatedPubkey(string $pubkey): void

// Subscriptions
$context->addSubscription(string $id, array $filters): void
$context->removeSubscription(string $id): void
$context->getSubscriptions(): array

// Metadata
$context->setMetadata(string $key, $value): void
$context->getMetadata(string $key, $default = null)
```

### MySQLStore Class

MySQL/InnoDB implementation of the EventStore interface.

```php
$store = new MySQLStore(PDO $pdo, string $tableName = 'events');

// Initialize database
$store->init(): bool

// Store operations
$store->store(Event $event): bool
$store->query(array $filters): iterable<Event>
$store->count(array $filters): int
$store->delete(string $eventId, string $pubkey): bool
$store->replace(Event $event): bool
```

## Built-in Policies

The `Policies` class provides ready-to-use validation policies.

### General Policies

```php
// Prevent specific event kinds
Policies::preventKinds([4, 5])

// Prevent events with too many tags
Policies::preventLargeTags(50)

// Prevent events with large content
Policies::preventLargeContent(10000)

// Prevent future events
Policies::preventFutureEvents(300)

// Prevent old events
Policies::preventOldEvents(86400)

// Block specific pubkeys
Policies::blockPubkeys(['pubkey1', 'pubkey2'])

// Allow only specific pubkeys
Policies::allowOnlyPubkeys(['pubkey1', 'pubkey2'])
```

### Authentication Policies

```php
// Require authentication for specific kinds
Policies::requireAuthForKind([3, 4])
```

### Content Policies

```php
// Require content for specific kinds
Policies::requireContentForKinds([1, 3])

// Require specific tags for kinds
Policies::requireTagsForKind([
    1 => ['t'],
    3 => ['p']
])

// Block specific tag values
Policies::blockTagValues('t', ['spam', 'blocked'])
```

### Validation Policies

```php
// Basic signature validation
Policies::validateSignature()
```

## Custom Policies

Create custom policies by implementing callable functions:

```php
// Custom policy example
$customPolicy = function(Context $ctx, Event $event): array {
    // Your validation logic here
    if ($someCondition) {
        return [true, "Event rejected: reason"];
    }
    return [false, ''];
};

$relay->addRejectEventHandler($customPolicy);
```

## Database Schema

The MySQL store creates the following table:

```sql
CREATE TABLE events (
    id VARCHAR(64) PRIMARY KEY,
    pubkey VARCHAR(64) NOT NULL,
    created_at INT NOT NULL,
    kind INT NOT NULL,
    content TEXT,
    tags JSON,
    sig VARCHAR(128) NOT NULL,
    INDEX idx_pubkey (pubkey),
    INDEX idx_created_at (created_at),
    INDEX idx_kind (kind),
    INDEX idx_pubkey_kind (pubkey, kind),
    INDEX idx_created_at_kind (created_at, kind)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
```

## HTTP Integration

The relay can be integrated into HTTP applications:

```php
// Get router for HTTP integration
$router = $relay->router();

// Use with your HTTP framework
$app->get('/nostr.json', $router);
```

## Advanced Usage

### Multiple Storage Backends

```php
// Primary storage
$relay->addStoreEventHandler([$mysqlStore, 'store']);

// Cache storage for recent events
$relay->addStoreEventHandler([$redisStore, 'store']);

// Archive storage for old events
$relay->addStoreEventHandler([$archiveStore, 'store']);
```

### Complex Filter Validation

```php
$relay->addRejectFilterHandler(function($context, $filters) {
    foreach ($filters as $filter) {
        // Check filter complexity
        $complexity = 0;
        if (isset($filter['ids'])) $complexity += count($filter['ids']);
        if (isset($filter['authors'])) $complexity += count($filter['authors']);
        
        if ($complexity > 100) {
            return [true, "Filter too complex"];
        }
    }
    return [false, ''];
});
```

### Rate Limiting

```php
$relay->addRejectEventHandler(function($context, $event) {
    $pubkey = $event->pubkey;
    $now = time();
    
    // Implement rate limiting logic here
    // This is a simplified example
    
    return [false, ''];
});
```

## Error Handling

The library provides comprehensive error handling:

- Database connection errors are logged
- Invalid events are rejected with appropriate messages
- WebSocket errors are handled gracefully
- All exceptions are caught and logged

## Performance Considerations

- Use proper database indexing for query performance
- Implement caching for frequently accessed data
- Consider connection pooling for high-traffic relays
- Monitor memory usage with large event volumes

## Security

- Always validate event signatures in production
- Implement proper authentication for sensitive operations
- Use HTTPS/WSS in production environments
- Regularly update dependencies

## Contributing

1. Fork the repository
2. Create a feature branch
3. Make your changes
4. Add tests
5. Submit a pull request

## License

MIT License - see LICENSE file for details.

## Troubleshooting

### Common Issues

#### Composer Installation Problems
If you encounter dependency resolution issues:

```bash
# Clear composer cache
composer clear-cache

# Update composer
composer self-update

# Try with minimum stability
composer install --prefer-stable
```

#### PHP Version Issues
Ensure you have PHP 8.1+ installed:

```bash
php --version
```

#### Missing Extensions
Install required PHP extensions:

```bash
# Ubuntu/Debian
sudo apt-get install php8.1-pdo php8.1-mysql php8.1-json php8.1-openssl

# macOS with Homebrew
brew install php@8.1

# Windows with XAMPP
# Download XAMPP with PHP 8.1+
```

#### MySQL Connection Issues
- Verify MySQL server is running
- Check database credentials in `config.php`
- Ensure database exists: `CREATE DATABASE nostr_relay;`
- Grant permissions: `GRANT ALL ON nostr_relay.* TO 'nostr_user'@'localhost';`

### Requirements Check
Run the requirements check script:

```bash
php check-requirements.php
```

This will verify your environment and provide specific guidance for any issues.

## Support

- Documentation: [khatru.nostr.technology](https://khatru.nostr.technology)
- Issues: [GitHub Issues](https://github.com/fiatjaf/khatru/issues)
- Community: [Nostr Community](https://github.com/nostr-protocol/nostr) 
