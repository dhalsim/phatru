<?php

namespace Khatru;

use PDO;
use PDOException;

class MySQLStore implements EventStore
{
    private PDO $pdo;
    private string $tableName;

    public function __construct(PDO $pdo, string $tableName = 'events')
    {
        $this->pdo = $pdo;
        $this->tableName = $tableName;
    }

    public function init(): bool
    {
        try {
            $sql = "CREATE TABLE IF NOT EXISTS {$this->tableName} (
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
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
            
            $this->pdo->exec($sql);
            return true;
        } catch (PDOException $e) {
            error_log("Failed to initialize MySQL store: " . $e->getMessage());
            return false;
        }
    }

    public function store(Event $event): bool
    {
        try {
            $sql = "INSERT INTO {$this->tableName} (id, pubkey, created_at, kind, content, tags, sig) 
                    VALUES (:id, :pubkey, :created_at, :kind, :content, :tags, :sig)";
            
            $stmt = $this->pdo->prepare($sql);
            return $stmt->execute([
                ':id' => $event->id,
                ':pubkey' => $event->pubkey,
                ':created_at' => $event->created_at,
                ':kind' => $event->kind,
                ':content' => $event->content,
                ':tags' => json_encode($event->tags),
                ':sig' => $event->sig
            ]);
        } catch (PDOException $e) {
            error_log("Failed to store event: " . $e->getMessage());
            return false;
        }
    }

    public function query(array $filters): iterable
    {
        try {
            $sql = "SELECT id, pubkey, created_at, kind, content, tags, sig FROM {$this->tableName}";
            $conditions = [];
            $params = [];

            foreach ($filters as $filter) {
                $filterConditions = $this->buildFilterConditions($filter, $params);
                if (!empty($filterConditions)) {
                    $conditions[] = '(' . implode(' AND ', $filterConditions) . ')';
                }
            }

            if (!empty($conditions)) {
                $sql .= " WHERE " . implode(' OR ', $conditions);
            }

            $sql .= " ORDER BY created_at DESC LIMIT 1000";

            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($params);

            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $event = new Event(
                    $row['id'],
                    $row['pubkey'],
                    (int)$row['created_at'],
                    (int)$row['kind'],
                    json_decode($row['tags'], true) ?: [],
                    $row['content'],
                    $row['sig']
                );
                yield $event;
            }
        } catch (PDOException $e) {
            error_log("Failed to query events: " . $e->getMessage());
            yield from [];
        }
    }

    public function count(array $filters): int
    {
        try {
            $sql = "SELECT COUNT(*) FROM {$this->tableName}";
            $conditions = [];
            $params = [];

            foreach ($filters as $filter) {
                $filterConditions = $this->buildFilterConditions($filter, $params);
                if (!empty($filterConditions)) {
                    $conditions[] = '(' . implode(' AND ', $filterConditions) . ')';
                }
            }

            if (!empty($conditions)) {
                $sql .= " WHERE " . implode(' OR ', $conditions);
            }

            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($params);
            return (int)$stmt->fetchColumn();
        } catch (PDOException $e) {
            error_log("Failed to count events: " . $e->getMessage());
            return 0;
        }
    }

    public function delete(string $eventId, string $pubkey): bool
    {
        try {
            $sql = "DELETE FROM {$this->tableName} WHERE id = :id AND pubkey = :pubkey";
            $stmt = $this->pdo->prepare($sql);
            return $stmt->execute([':id' => $eventId, ':pubkey' => $pubkey]);
        } catch (PDOException $e) {
            error_log("Failed to delete event: " . $e->getMessage());
            return false;
        }
    }

    public function replace(Event $event): bool
    {
        try {
            $this->pdo->beginTransaction();
            
            // For replaceable events, check if we should replace
            if ($event->isReplaceable()) {
                if (!$this->shouldReplaceEvent($event)) {
                    $this->pdo->rollBack();
                    return false; // Don't replace, newer event exists
                }
                $this->deleteReplaceableEvents($event);
            } else {
                // For non-replaceable events, just delete the specific event
                $this->delete($event->id, $event->pubkey);
            }
            
            // Store new event
            $success = $this->store($event);
            
            if ($success) {
                $this->pdo->commit();
                return true;
            } else {
                $this->pdo->rollBack();
                return false;
            }
        } catch (PDOException $e) {
            $this->pdo->rollBack();
            error_log("Failed to replace event: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Check if we should replace existing events with this new event
     */
    private function shouldReplaceEvent(Event $event): bool
    {
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
        $existingEvents = iterator_to_array($this->query([$filter]));
        
        // If no existing events, we can store
        if (empty($existingEvents)) {
            return true;
        }

        // Check if our event is newer than all existing events
        foreach ($existingEvents as $existingEvent) {
            if (!$event->isNewerThan($existingEvent)) {
                return false; // Found a newer or equal event, don't replace
            }
        }

        return true; // Our event is newer than all existing events
    }

    /**
     * Delete all existing replaceable events with the same address
     */
    private function deleteReplaceableEvents(Event $event): void
    {
        // For kind 0 events, delete all events with same pubkey and kind
        if ($event->kind === 0) {
            $sql = "DELETE FROM {$this->tableName} WHERE pubkey = :pubkey AND kind = 0";
            $params = [':pubkey' => $event->pubkey];
        }
        // For addressable events, we need to check the d tag
        elseif ($event->kind >= 30000 && $event->kind < 40000) {
            $dTag = $event->getTag('d');
            if ($dTag) {
                // Use JSON_CONTAINS to check if the tags array contains the d tag
                $sql = "DELETE FROM {$this->tableName} WHERE pubkey = :pubkey AND kind = :kind AND JSON_CONTAINS(tags, :d_tag_array)";
                $params = [
                    ':pubkey' => $event->pubkey, 
                    ':kind' => $event->kind,
                    ':d_tag_array' => json_encode(['d', $dTag])
                ];
            } else {
                // No d tag, just delete by pubkey and kind
                $sql = "DELETE FROM {$this->tableName} WHERE pubkey = :pubkey AND kind = :kind";
                $params = [':pubkey' => $event->pubkey, ':kind' => $event->kind];
            }
        }
        // For other replaceable events (shouldn't happen with current logic)
        else {
            $sql = "DELETE FROM {$this->tableName} WHERE pubkey = :pubkey AND kind = :kind";
            $params = [':pubkey' => $event->pubkey, ':kind' => $event->kind];
        }
        
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
    }

    private function buildFilterConditions(array $filter, array &$params): array
    {
        $conditions = [];
        $paramIndex = count($params);

        if (isset($filter['ids']) && !empty($filter['ids'])) {
            $placeholders = [];
            foreach ($filter['ids'] as $i => $id) {
                $placeholders[] = ":id_{$paramIndex}_{$i}";
                $params[":id_{$paramIndex}_{$i}"] = $id;
            }
            $conditions[] = "id IN (" . implode(',', $placeholders) . ")";
            $paramIndex++;
        }

        if (isset($filter['authors']) && !empty($filter['authors'])) {
            $placeholders = [];
            foreach ($filter['authors'] as $i => $author) {
                $placeholders[] = ":author_{$paramIndex}_{$i}";
                $params[":author_{$paramIndex}_{$i}"] = $author;
            }
            $conditions[] = "pubkey IN (" . implode(',', $placeholders) . ")";
            $paramIndex++;
        }

        if (isset($filter['kinds']) && !empty($filter['kinds'])) {
            $placeholders = [];
            foreach ($filter['kinds'] as $i => $kind) {
                $placeholders[] = ":kind_{$paramIndex}_{$i}";
                $params[":kind_{$paramIndex}_{$i}"] = $kind;
            }
            $conditions[] = "kind IN (" . implode(',', $placeholders) . ")";
            $paramIndex++;
        }

        if (isset($filter['since'])) {
            $conditions[] = "created_at >= :since_{$paramIndex}";
            $params[":since_{$paramIndex}"] = $filter['since'];
            $paramIndex++;
        }

        if (isset($filter['until'])) {
            $conditions[] = "created_at <= :until_{$paramIndex}";
            $params[":until_{$paramIndex}"] = $filter['until'];
            $paramIndex++;
        }

        if (isset($filter['limit'])) {
            // Note: LIMIT is handled in the main query
        }

        return $conditions;
    }
} 