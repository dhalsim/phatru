<?php

namespace Khatru;

use Ratchet\ConnectionInterface;

class Context
{
    public ConnectionInterface $connection;
    public array $subscriptions = [];
    public array $metadata = [];
    public ?string $authenticatedPubkey = null;

    public function __construct(ConnectionInterface $connection)
    {
        $this->connection = $connection;
    }

    public function isAuthenticated(): bool
    {
        return $this->authenticatedPubkey !== null;
    }

    public function getAuthenticatedPubkey(): ?string
    {
        return $this->authenticatedPubkey;
    }

    public function setAuthenticatedPubkey(string $pubkey): void
    {
        $this->authenticatedPubkey = $pubkey;
    }

    public function addSubscription(string $subscriptionId, array $filters): void
    {
        $this->subscriptions[$subscriptionId] = $filters;
    }

    public function removeSubscription(string $subscriptionId): void
    {
        unset($this->subscriptions[$subscriptionId]);
    }

    public function getSubscriptions(): array
    {
        return $this->subscriptions;
    }

    public function setMetadata(string $key, $value): void
    {
        $this->metadata[$key] = $value;
    }

    public function getMetadata(string $key, $default = null)
    {
        return $this->metadata[$key] ?? $default;
    }

    public function getConnectionId(): string
    {
        return $this->connection->resourceId;
    }
} 