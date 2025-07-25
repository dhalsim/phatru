<?php

namespace Khatru;

interface EventStore
{
    /**
     * Store an event in the database
     */
    public function store(Event $event): bool;

    /**
     * Query events based on filters
     * 
     * @param array $filters Array of filter objects
     * @return iterable<Event>
     */
    public function query(array $filters): iterable;

    /**
     * Count events based on filters
     */
    public function count(array $filters): int;

    /**
     * Delete an event by ID and pubkey
     */
    public function delete(string $eventId, string $pubkey): bool;

    /**
     * Replace an event (delete old, store new)
     */
    public function replace(Event $event): bool;

    /**
     * Initialize the storage backend (create tables, etc.)
     */
    public function init(): bool;
} 