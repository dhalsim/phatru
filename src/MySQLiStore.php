<?php

namespace Phatru;

use mysqli;
use mysqli_stmt;

class MySQLiStore implements EventStore
{
    private mysqli $mysqli;
    private string $tableName;

    public function __construct(mysqli $mysqli, string $tableName = 'events')
    {
        $this->mysqli = $mysqli;
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
            
            return $this->mysqli->query($sql);
        } catch (\Exception $e) {
            error_log("Failed to initialize MySQLi store: " . $e->getMessage());
            return false;
        }
    }

    public function store(Event $event): bool
    {
        try {
            $sql = "INSERT INTO {$this->tableName} (id, pubkey, created_at, kind, content, tags, sig) 
                    VALUES (?, ?, ?, ?, ?, ?, ?)";
            
            $stmt = $this->mysqli->prepare($sql);
            if (!$stmt) {
                throw new \Exception("Prepare failed: " . $this->mysqli->error);
            }
            
            $tags = json_encode($event->tags);
            $stmt->bind_param('ssiiss', 
                $event->id,
                $event->pubkey,
                $event->created_at,
                $event->kind,
                $event->content,
                $tags,
                $event->sig
            );
            
            return $stmt->execute();
        } catch (\Exception $e) {
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
            $types = '';

            foreach ($filters as $filter) {
                $filterConditions = $this->buildFilterConditions($filter, $params, $types);
                if (!empty($filterConditions)) {
                    $conditions[] = '(' . implode(' AND ', $filterConditions) . ')';
                }
            }

            if (!empty($conditions)) {
                $sql .= " WHERE " . implode(' OR ', $conditions);
            }

            $sql .= " ORDER BY created_at DESC LIMIT 1000";

            $stmt = $this->mysqli->prepare($sql);
            if (!$stmt) {
                throw new \Exception("Prepare failed: " . $this->mysqli->error);
            }

            if (!empty($params)) {
                $stmt->bind_param($types, ...$params);
            }

            $stmt->execute();
            $result = $stmt->get_result();

            while ($row = $result->fetch_assoc()) {
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
        } catch (\Exception $e) {
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
            $types = '';

            foreach ($filters as $filter) {
                $filterConditions = $this->buildFilterConditions($filter, $params, $types);
                if (!empty($filterConditions)) {
                    $conditions[] = '(' . implode(' AND ', $filterConditions) . ')';
                }
            }

            if (!empty($conditions)) {
                $sql .= " WHERE " . implode(' OR ', $conditions);
            }

            $stmt = $this->mysqli->prepare($sql);
            if (!$stmt) {
                throw new \Exception("Prepare failed: " . $this->mysqli->error);
            }

            if (!empty($params)) {
                $stmt->bind_param($types, ...$params);
            }

            $stmt->execute();
            $result = $stmt->get_result();
            $row = $result->fetch_row();
            return (int)$row[0];
        } catch (\Exception $e) {
            error_log("Failed to count events: " . $e->getMessage());
            return 0;
        }
    }

    public function delete(string $eventId, string $pubkey): bool
    {
        try {
            $sql = "DELETE FROM {$this->tableName} WHERE id = ? AND pubkey = ?";
            $stmt = $this->mysqli->prepare($sql);
            if (!$stmt) {
                throw new \Exception("Prepare failed: " . $this->mysqli->error);
            }
            
            $stmt->bind_param('ss', $eventId, $pubkey);
            return $stmt->execute();
        } catch (\Exception $e) {
            error_log("Failed to delete event: " . $e->getMessage());
            return false;
        }
    }

    public function replace(Event $event): bool
    {
        try {
            $this->mysqli->begin_transaction();
            
            // Delete existing event
            $this->delete($event->id, $event->pubkey);
            
            // Store new event
            $success = $this->store($event);
            
            if ($success) {
                $this->mysqli->commit();
                return true;
            } else {
                $this->mysqli->rollback();
                return false;
            }
        } catch (\Exception $e) {
            $this->mysqli->rollback();
            error_log("Failed to replace event: " . $e->getMessage());
            return false;
        }
    }

    private function buildFilterConditions(array $filter, array &$params, string &$types): array
    {
        $conditions = [];
        $paramIndex = count($params);

        if (isset($filter['ids']) && !empty($filter['ids'])) {
            $placeholders = [];
            foreach ($filter['ids'] as $id) {
                $placeholders[] = '?';
                $params[] = $id;
                $types .= 's';
            }
            $conditions[] = "id IN (" . implode(',', $placeholders) . ")";
        }

        if (isset($filter['authors']) && !empty($filter['authors'])) {
            $placeholders = [];
            foreach ($filter['authors'] as $author) {
                $placeholders[] = '?';
                $params[] = $author;
                $types .= 's';
            }
            $conditions[] = "pubkey IN (" . implode(',', $placeholders) . ")";
        }

        if (isset($filter['kinds']) && !empty($filter['kinds'])) {
            $placeholders = [];
            foreach ($filter['kinds'] as $kind) {
                $placeholders[] = '?';
                $params[] = $kind;
                $types .= 'i';
            }
            $conditions[] = "kind IN (" . implode(',', $placeholders) . ")";
        }

        if (isset($filter['since'])) {
            $conditions[] = "created_at >= ?";
            $params[] = $filter['since'];
            $types .= 'i';
        }

        if (isset($filter['until'])) {
            $conditions[] = "created_at <= ?";
            $params[] = $filter['until'];
            $types .= 'i';
        }

        return $conditions;
    }
} 