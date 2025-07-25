<?php

namespace Phatru;

use Ratchet\MessageComponentInterface;
use Ratchet\ConnectionInterface;
use Ratchet\Server\IoServer;
use Ratchet\Http\HttpServer;
use Ratchet\WebSocket\WsServer;
use React\EventLoop\Loop;
use React\Socket\SecureServer;
use React\Socket\Server;

class Relay implements MessageComponentInterface
{
    // Handler pipelines
    public array $onRejectEvent = [];
    public array $onStoreEvent = [];
    public array $onQueryEvents = [];
    public array $onCountEvents = [];
    public array $onDeleteEvent = [];
    public array $onReplaceEvent = [];
    public array $onRejectFilter = [];

    // Kind-specific handlers
    private array $kindHandlers = [];

    // Relay info
    public array $info = [
        'name' => 'Khatru PHP Relay',
        'description' => 'A PHP relay with Khatru-style event handlers',
        'pubkey' => '',
        'contact' => '',
        'supported_nips' => [1, 2, 9, 11, 15, 16, 20, 22, 33, 40],
        'software' => 'khatru-php',
        'version' => '0.1.0'
    ];

    // Connections storage
    private array $connections = [];

    public function __construct()
    {
        // Initialize default handlers
        $this->onRejectEvent[] = Policies::validateSignature();
        $this->onRejectEvent[] = Policies::preventFutureEvents();
    }

    /**
     * Configure error reporting based on server configuration
     */
    public function configureErrorReporting(array $config): void
    {
        if (isset($config['server']['error_reporting'])) {
            $errorConfig = $config['server']['error_reporting'];
            if ($errorConfig['suppress_deprecations']) {
                error_reporting($errorConfig['level']);
            } else {
                error_reporting(E_ALL);
            }
        }
    }

    /**
     * Add a general event rejection handler
     */
    public function addRejectEventHandler(callable $handler): void
    {
        $this->onRejectEvent[] = $handler;
    }

    /**
     * Add a kind-specific event rejection handler
     */
    public function addRejectEventHandlerForKind(int $kind, callable $handler): void
    {
        if (!isset($this->kindHandlers[$kind])) {
            $this->kindHandlers[$kind] = [];
        }
        $this->kindHandlers[$kind][] = $handler;
    }

    /**
     * Add an event storage handler
     */
    public function addStoreEventHandler(callable $handler): void
    {
        $this->onStoreEvent[] = $handler;
    }

    /**
     * Add an event query handler
     */
    public function addQueryEventsHandler(callable $handler): void
    {
        $this->onQueryEvents[] = $handler;
    }

    /**
     * Add an event count handler
     */
    public function addCountEventsHandler(callable $handler): void
    {
        $this->onCountEvents[] = $handler;
    }

    /**
     * Add an event deletion handler
     */
    public function addDeleteEventHandler(callable $handler): void
    {
        $this->onDeleteEvent[] = $handler;
    }

    /**
     * Add an event replacement handler
     */
    public function addReplaceEventHandler(callable $handler): void
    {
        $this->onReplaceEvent[] = $handler;
    }

    /**
     * Add a filter rejection handler
     */
    public function addRejectFilterHandler(callable $handler): void
    {
        $this->onRejectFilter[] = $handler;
    }

    /**
     * Set relay information
     */
    public function setInfo(array $info): void
    {
        $this->info = array_merge($this->info, $info);
    }

    /**
     * Run the relay server
     */
    public function run(string $host = '0.0.0.0', int $port = 8080, bool $ssl = false, array $sslOptions = []): void
    {
        $server = IoServer::factory(
            new HttpServer(
                new WsServer($this)
            ),
            $port,
            $host
        );

        if ($ssl) {
            $server = new IoServer(
                new HttpServer(
                    new WsServer($this)
                ),
                new SecureServer(
                    new Server("{$host}:{$port}"),
                    $sslOptions
                )
            );
        }

        echo "Khatru PHP Relay running on {$host}:{$port}" . ($ssl ? ' (SSL)' : '') . PHP_EOL;
        $server->run();
    }

    /**
     * Handle new WebSocket connections
     */
    public function onOpen(ConnectionInterface $conn): void
    {
        $this->connections[$conn->resourceId] = new Context($conn);
        echo "New connection! ({$conn->resourceId})" . PHP_EOL;
    }

    /**
     * Handle WebSocket messages
     */
    public function onMessage(ConnectionInterface $conn, $msg): void
    {
        $context = $this->connections[$conn->resourceId] ?? new Context($conn);
        
        try {
            $data = json_decode($msg, true);
            if (!is_array($data) || !isset($data[0])) {
                $this->sendNotice($conn, "Invalid message format");
                return;
            }

            $command = $data[0];
            $params = array_slice($data, 1);

            switch ($command) {
                case 'EVENT':
                    $this->handleEvent($context, $params);
                    break;
                case 'REQ':
                    $this->handleRequest($context, $params);
                    break;
                case 'CLOSE':
                    $this->handleClose($context, $params);
                    break;
                case 'AUTH':
                    $this->handleAuth($context, $params);
                    break;
                default:
                    $this->sendNotice($conn, "Unknown command: {$command}");
            }
        } catch (\Exception $e) {
            $this->sendNotice($conn, "Error processing message: " . $e->getMessage());
        }
    }

    /**
     * Handle WebSocket connection close
     */
    public function onClose(ConnectionInterface $conn): void
    {
        unset($this->connections[$conn->resourceId]);
        echo "Connection {$conn->resourceId} has disconnected" . PHP_EOL;
    }

    /**
     * Handle WebSocket errors
     */
    public function onError(ConnectionInterface $conn, \Exception $e): void
    {
        echo "An error has occurred: {$e->getMessage()}" . PHP_EOL;
        $conn->close();
    }

    /**
     * Handle EVENT messages
     */
    private function handleEvent(Context $context, array $params): void
    {
        if (count($params) < 1) {
            $this->sendNotice($context->connection, "EVENT requires event data");
            return;
        }

        $eventData = $params[0];
        $event = Event::fromArray($eventData);

        // Run rejection handlers
        foreach ($this->onRejectEvent as $handler) {
            [$rejected, $reason] = $handler($context, $event);
            if ($rejected) {
                $this->sendNotice($context->connection, $reason);
                return;
            }
        }

        // Run kind-specific handlers
        if (isset($this->kindHandlers[$event->kind])) {
            foreach ($this->kindHandlers[$event->kind] as $handler) {
                [$rejected, $reason] = $handler($context, $event);
                if ($rejected) {
                    $this->sendNotice($context->connection, $reason);
                    return;
                }
            }
        }

        // Handle replaceable events
        if ($event->isReplaceable()) {
            $stored = $this->handleReplaceableEvent($event);
        } else {
            // Handle regular events
            $stored = false;
            foreach ($this->onStoreEvent as $handler) {
                if ($handler($event)) {
                    $stored = true;
                    break;
                }
            }
        }

        if ($stored) {
            $this->sendOk($context->connection, $event->id, true, "Event stored");
            $this->broadcastEvent($event);
        } else {
            $this->sendOk($context->connection, $event->id, false, "Failed to store event");
        }
    }

    /**
     * Handle replaceable events (kind 0 and addressable events)
     */
    private function handleReplaceableEvent(Event $event): bool
    {
        // Use replace handlers if available
        if (!empty($this->onReplaceEvent)) {
            foreach ($this->onReplaceEvent as $handler) {
                if ($handler($event)) {
                    return true;
                }
            }
            return false;
        }

        // Manual replacement logic
        $address = $event->getAddress();
        if (empty($address)) {
            return false;
        }

        // Build filter to find existing events
        $filter = [
            'kinds' => [$event->kind],
            'authors' => [$event->pubkey],
            'limit' => 1
        ];

        // For addressable events, add d tag filter
        if ($event->kind >= 30000 && $event->kind < 40000) {
            $dTag = $event->getTag('d');
            if ($dTag) {
                $filter['#d'] = [$dTag];
            }
        }

        // Find existing events
        $existingEvents = [];
        foreach ($this->onQueryEvents as $handler) {
            $result = $handler([$filter]);
            if (is_iterable($result)) {
                foreach ($result as $existingEvent) {
                    $existingEvents[] = $existingEvent;
                }
            }
        }

        // Check if we should replace or skip
        $shouldStore = true;
        foreach ($existingEvents as $existingEvent) {
            if ($event->isNewerThan($existingEvent)) {
                // Delete older event
                foreach ($this->onDeleteEvent as $deleteHandler) {
                    $deleteHandler($existingEvent->id, $existingEvent->pubkey);
                }
            } else {
                // Found a newer event, don't store this one
                $shouldStore = false;
                break;
            }
        }

        // Store the event if it's the newest
        if ($shouldStore) {
            foreach ($this->onStoreEvent as $handler) {
                if ($handler($event)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Handle REQ messages
     */
    private function handleRequest(Context $context, array $params): void
    {
        if (count($params) < 2) {
            $this->sendNotice($context->connection, "REQ requires subscription ID and filters");
            return;
        }

        $subscriptionId = $params[0];
        $filters = array_slice($params, 1);

        // Run filter rejection handlers
        foreach ($this->onRejectFilter as $handler) {
            [$rejected, $reason] = $handler($context, $filters);
            if ($rejected) {
                $this->sendNotice($context->connection, $reason);
                return;
            }
        }

        // Store subscription
        $context->addSubscription($subscriptionId, $filters);

        // Query events
        $events = [];
        foreach ($this->onQueryEvents as $handler) {
            $result = $handler($filters);
            if (is_iterable($result)) {
                foreach ($result as $event) {
                    $events[] = $event;
                }
            }
        }

        // Send events
        foreach ($events as $event) {
            $this->sendEvent($context->connection, $subscriptionId, $event);
        }

        // Send EOSE
        $this->sendEose($context->connection, $subscriptionId);
    }

    /**
     * Handle CLOSE messages
     */
    private function handleClose(Context $context, array $params): void
    {
        if (count($params) < 1) {
            $this->sendNotice($context->connection, "CLOSE requires subscription ID");
            return;
        }

        $subscriptionId = $params[0];
        $context->removeSubscription($subscriptionId);
    }

    /**
     * Handle AUTH messages
     */
    private function handleAuth(Context $context, array $params): void
    {
        if (count($params) < 1) {
            $this->sendNotice($context->connection, "AUTH requires challenge");
            return;
        }

        // For now, we'll just accept any AUTH
        // In a real implementation, you'd verify the challenge
        $context->setAuthenticatedPubkey('authenticated');
        $this->sendAuth($context->connection, true, "Authentication successful");
    }

    /**
     * Broadcast event to all connected clients
     */
    private function broadcastEvent(Event $event): void
    {
        foreach ($this->connections as $context) {
            foreach ($context->getSubscriptions() as $subscriptionId => $filters) {
                if ($this->eventMatchesFilters($event, $filters)) {
                    $this->sendEvent($context->connection, $subscriptionId, $event);
                }
            }
        }
    }

    /**
     * Check if event matches filters
     */
    private function eventMatchesFilters(Event $event, array $filters): bool
    {
        foreach ($filters as $filter) {
            if ($this->eventMatchesFilter($event, $filter)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Check if event matches a single filter
     */
    private function eventMatchesFilter(Event $event, array $filter): bool
    {
        if (isset($filter['ids']) && !in_array($event->id, $filter['ids'])) {
            return false;
        }

        if (isset($filter['authors']) && !in_array($event->pubkey, $filter['authors'])) {
            return false;
        }

        if (isset($filter['kinds']) && !in_array($event->kind, $filter['kinds'])) {
            return false;
        }

        if (isset($filter['since']) && $event->created_at < $filter['since']) {
            return false;
        }

        if (isset($filter['until']) && $event->created_at > $filter['until']) {
            return false;
        }

        return true;
    }

    /**
     * Send NOTICE message
     */
    private function sendNotice(ConnectionInterface $conn, string $message): void
    {
        $conn->send(json_encode(['NOTICE', $message]));
    }

    /**
     * Send OK message
     */
    private function sendOk(ConnectionInterface $conn, string $eventId, bool $success, string $message): void
    {
        $conn->send(json_encode(['OK', $eventId, $success, $message]));
    }

    /**
     * Send EVENT message
     */
    private function sendEvent(ConnectionInterface $conn, string $subscriptionId, Event $event): void
    {
        $conn->send(json_encode(['EVENT', $subscriptionId, $event->toArray()]));
    }

    /**
     * Send EOSE message
     */
    private function sendEose(ConnectionInterface $conn, string $subscriptionId): void
    {
        $conn->send(json_encode(['EOSE', $subscriptionId]));
    }

    /**
     * Send AUTH message
     */
    private function sendAuth(ConnectionInterface $conn, bool $success, string $message): void
    {
        $conn->send(json_encode(['AUTH', $success, $message]));
    }

    /**
     * Get router for HTTP integration
     */
    public function router(): callable
    {
        return function ($request, $response) {
            $path = $request->getUri()->getPath();
            
            if ($path === '/nostr.json') {
                $response->getBody()->write(json_encode($this->info));
                return $response->withHeader('Content-Type', 'application/json');
            }
            
            return $response->withStatus(404);
        };
    }
} 