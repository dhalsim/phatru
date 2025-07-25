<?php

require_once __DIR__ . '/vendor/autoload.php';

use Phatru\Relay;
use Phatru\MySQLStore;
use Phatru\Policies;
use Phatru\Event;
use Phatru\Context;

/**
 * NIP-29 Relay-based Groups Implementation
 * 
 * This implementation provides full support for NIP-29 relay-based groups,
 * including group management, moderation, and all required event types.
 */

class NIP29Relay extends \Phatru\Relay
{
    private string $relayPubkey;
    private string $relayPrivateKey;
    private PDO $pdo;
    private array $groupCache = [];
    private array $memberCache = [];
    private array $adminCache = [];

    public function __construct(PDO $pdo, string $relayPrivateKey)
    {
        parent::__construct();
        $this->pdo = $pdo;
        $this->relayPrivateKey = $relayPrivateKey;
        $this->relayPubkey = $this->derivePublicKey($relayPrivateKey);
        
        // Initialize NIP-29 database tables
        $this->initNIP29Tables();
        
        // Set up NIP-29 specific handlers
        $this->setupNIP29Handlers();
        
        // Update relay info to include NIP-29 support
        $this->info['supported_nips'][] = 29;
    }

    /**
     * Initialize database tables for NIP-29 groups
     */
    private function initNIP29Tables(): void
    {
        $tables = [
            'groups' => "
                CREATE TABLE IF NOT EXISTS groups (
                    id VARCHAR(255) PRIMARY KEY,
                    name VARCHAR(255),
                    picture TEXT,
                    about TEXT,
                    public BOOLEAN DEFAULT TRUE,
                    open BOOLEAN DEFAULT TRUE,
                    created_at INT NOT NULL,
                    updated_at INT NOT NULL,
                    INDEX idx_public (public),
                    INDEX idx_open (open)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ",
            'group_members' => "
                CREATE TABLE IF NOT EXISTS group_members (
                    group_id VARCHAR(255) NOT NULL,
                    pubkey VARCHAR(64) NOT NULL,
                    joined_at INT NOT NULL,
                    PRIMARY KEY (group_id, pubkey),
                    FOREIGN KEY (group_id) REFERENCES groups(id) ON DELETE CASCADE,
                    INDEX idx_pubkey (pubkey)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ",
            'group_admins' => "
                CREATE TABLE IF NOT EXISTS group_admins (
                    group_id VARCHAR(255) NOT NULL,
                    pubkey VARCHAR(64) NOT NULL,
                    roles JSON NOT NULL,
                    added_at INT NOT NULL,
                    PRIMARY KEY (group_id, pubkey),
                    FOREIGN KEY (group_id) REFERENCES groups(id) ON DELETE CASCADE,
                    INDEX idx_pubkey (pubkey)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ",
            'group_roles' => "
                CREATE TABLE IF NOT EXISTS group_roles (
                    group_id VARCHAR(255) NOT NULL,
                    role_name VARCHAR(100) NOT NULL,
                    description TEXT,
                    permissions JSON,
                    PRIMARY KEY (group_id, role_name),
                    FOREIGN KEY (group_id) REFERENCES groups(id) ON DELETE CASCADE
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ",
            'group_invites' => "
                CREATE TABLE IF NOT EXISTS group_invites (
                    group_id VARCHAR(255) NOT NULL,
                    code VARCHAR(100) NOT NULL,
                    created_by VARCHAR(64) NOT NULL,
                    created_at INT NOT NULL,
                    expires_at INT,
                    max_uses INT DEFAULT 1,
                    used_count INT DEFAULT 0,
                    PRIMARY KEY (group_id, code),
                    FOREIGN KEY (group_id) REFERENCES groups(id) ON DELETE CASCADE,
                    INDEX idx_code (code)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ",
            'group_timeline_refs' => "
                CREATE TABLE IF NOT EXISTS group_timeline_refs (
                    group_id VARCHAR(255) NOT NULL,
                    event_id VARCHAR(64) NOT NULL,
                    ref_hash VARCHAR(8) NOT NULL,
                    created_at INT NOT NULL,
                    PRIMARY KEY (group_id, ref_hash),
                    FOREIGN KEY (group_id) REFERENCES groups(id) ON DELETE CASCADE,
                    INDEX idx_event_id (event_id),
                    INDEX idx_created_at (created_at)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            "
        ];

        foreach ($tables as $tableName => $sql) {
            try {
                $this->pdo->exec($sql);
                echo "Created table: {$tableName}\n";
            } catch (PDOException $e) {
                echo "Error creating table {$tableName}: " . $e->getMessage() . "\n";
            }
        }
    }

    /**
     * Set up NIP-29 specific event handlers
     */
    private function setupNIP29Handlers(): void
    {
        // Add group event validation
        $this->addRejectEventHandler([$this, 'validateGroupEvent']);
        
        // Add handlers for group-specific event kinds
        $this->addRejectEventHandlerForKind(9021, [$this, 'handleJoinRequest']);
        $this->addRejectEventHandlerForKind(9022, [$this, 'handleLeaveRequest']);
        
        // Add moderation event handlers
        for ($kind = 9000; $kind <= 9020; $kind++) {
            $this->addRejectEventHandlerForKind($kind, [$this, 'handleModerationEvent']);
        }
        
        // Add group metadata event handlers
        $this->addRejectEventHandlerForKind(39000, [$this, 'handleGroupMetadata']);
        $this->addRejectEventHandlerForKind(39001, [$this, 'handleGroupAdmins']);
        $this->addRejectEventHandlerForKind(39002, [$this, 'handleGroupMembers']);
        $this->addRejectEventHandlerForKind(39003, [$this, 'handleGroupRoles']);
        
        // Add timeline reference validation for all events
        $this->addRejectEventHandler([$this, 'validateTimelineReferences']);
    }

    /**
     * Validate that events sent to groups have proper 'h' tag
     */
    public function validateGroupEvent(Context $context, Event $event): array
    {
        // Check if this is a group event (has 'h' tag)
        $groupId = $event->getTag('h');
        if ($groupId) {
            // Validate group exists
            if (!$this->groupExists($groupId)) {
                return [true, "Group does not exist"];
            }
            
            // Check if user is member (for non-public groups)
            if (!$this->isGroupPublic($groupId) && !$this->isUserMember($groupId, $event->pubkey)) {
                return [true, "User not a member of private group"];
            }
        }
        
        return [false, ''];
    }

    /**
     * Handle join requests (kind 9021)
     */
    public function handleJoinRequest(Context $context, Event $event): array
    {
        $groupId = $event->getTag('h');
        if (!$groupId) {
            return [true, "Missing group ID"];
        }

        if (!$this->groupExists($groupId)) {
            return [true, "Group does not exist"];
        }

        // Check if user is already a member
        if ($this->isUserMember($groupId, $event->pubkey)) {
            return [true, "duplicate: User already a member"];
        }

        // Check if group is open or user has invite code
        $inviteCode = $event->getTag('code');
        if (!$this->isGroupOpen($groupId)) {
            if (!$inviteCode || !$this->validateInviteCode($groupId, $inviteCode)) {
                return [true, "Group is closed and no valid invite code provided"];
            }
        }

        // Add user to group
        $this->addUserToGroup($groupId, $event->pubkey);
        
        // Use invite code if provided
        if ($inviteCode) {
            $this->useInviteCode($groupId, $inviteCode);
        }

        // Generate confirmation event (kind 9000)
        $this->generateModerationEvent($groupId, 9000, [
            ['p', $event->pubkey]
        ], "User joined group");

        return [false, ''];
    }

    /**
     * Handle leave requests (kind 9022)
     */
    public function handleLeaveRequest(Context $context, Event $event): array
    {
        $groupId = $event->getTag('h');
        if (!$groupId) {
            return [true, "Missing group ID"];
        }

        if (!$this->groupExists($groupId)) {
            return [true, "Group does not exist"];
        }

        // Remove user from group
        $this->removeUserFromGroup($groupId, $event->pubkey);

        // Generate removal event (kind 9001)
        $this->generateModerationEvent($groupId, 9001, [
            ['p', $event->pubkey]
        ], "User left group");

        return [false, ''];
    }

    /**
     * Handle moderation events (kinds 9000-9020)
     */
    public function handleModerationEvent(Context $context, Event $event): array
    {
        $groupId = $event->getTag('h');
        if (!$groupId) {
            return [true, "Missing group ID"];
        }

        if (!$this->groupExists($groupId)) {
            return [true, "Group does not exist"];
        }

        // Check if user has permission to perform moderation action
        if (!$this->canUserModerate($groupId, $event->pubkey, $event->kind)) {
            return [true, "Insufficient permissions for moderation action"];
        }

        // Handle specific moderation actions
        switch ($event->kind) {
            case 9000: // put-user
                return $this->handlePutUser($groupId, $event);
            case 9001: // remove-user
                return $this->handleRemoveUser($groupId, $event);
            case 9002: // edit-metadata
                return $this->handleEditMetadata($groupId, $event);
            case 9005: // delete-event
                return $this->handleDeleteEvent($groupId, $event);
            case 9007: // create-group
                return $this->handleCreateGroup($groupId, $event);
            case 9008: // delete-group
                return $this->handleDeleteGroup($groupId, $event);
            case 9009: // create-invite
                return $this->handleCreateInvite($groupId, $event);
            default:
                return [true, "Unknown moderation action"];
        }
    }

    /**
     * Handle group metadata events (kind 39000)
     */
    public function handleGroupMetadata(Context $context, Event $event): array
    {
        // Only relay can create group metadata events
        if ($event->pubkey !== $this->relayPubkey) {
            return [true, "Only relay can create group metadata events"];
        }

        $groupId = $event->getTag('d');
        if (!$groupId) {
            return [true, "Missing group ID"];
        }

        // Update group metadata
        $this->updateGroupMetadata($groupId, $event);

        return [false, ''];
    }

    /**
     * Handle group admins events (kind 39001)
     */
    public function handleGroupAdmins(Context $context, Event $event): array
    {
        // Only relay can create group admin events
        if ($event->pubkey !== $this->relayPubkey) {
            return [true, "Only relay can create group admin events"];
        }

        $groupId = $event->getTag('d');
        if (!$groupId) {
            return [true, "Missing group ID"];
        }

        // Update group admins
        $this->updateGroupAdmins($groupId, $event);

        return [false, ''];
    }

    /**
     * Handle group members events (kind 39002)
     */
    public function handleGroupMembers(Context $context, Event $event): array
    {
        // Only relay can create group member events
        if ($event->pubkey !== $this->relayPubkey) {
            return [true, "Only relay can create group member events"];
        }

        $groupId = $event->getTag('d');
        if (!$groupId) {
            return [true, "Missing group ID"];
        }

        // Update group members
        $this->updateGroupMembers($groupId, $event);

        return [false, ''];
    }

    /**
     * Handle group roles events (kind 39003)
     */
    public function handleGroupRoles(Context $context, Event $event): array
    {
        // Only relay can create group role events
        if ($event->pubkey !== $this->relayPubkey) {
            return [true, "Only relay can create group role events"];
        }

        $groupId = $event->getTag('d');
        if (!$groupId) {
            return [true, "Missing group ID"];
        }

        // Update group roles
        $this->updateGroupRoles($groupId, $event);

        return [false, ''];
    }

    /**
     * Validate timeline references for group events
     */
    public function validateTimelineReferences(Context $context, Event $event): array
    {
        $groupId = $event->getTag('h');
        if (!$groupId) {
            return [false, '']; // Not a group event
        }

        $previousRefs = $event->getTags('previous');
        if (empty($previousRefs)) {
            return [false, '']; // No references to validate
        }

        // Validate each reference
        foreach ($previousRefs as $ref) {
            if (!$this->validateTimelineReference($groupId, $ref)) {
                return [true, "Invalid timeline reference: {$ref}"];
            }
        }

        return [false, ''];
    }

    /**
     * Database helper methods
     */
    private function groupExists(string $groupId): bool
    {
        if (isset($this->groupCache[$groupId])) {
            return $this->groupCache[$groupId];
        }

        $stmt = $this->pdo->prepare("SELECT COUNT(*) FROM groups WHERE id = ?");
        $stmt->execute([$groupId]);
        $exists = $stmt->fetchColumn() > 0;
        $this->groupCache[$groupId] = $exists;
        return $exists;
    }

    private function isGroupPublic(string $groupId): bool
    {
        $stmt = $this->pdo->prepare("SELECT public FROM groups WHERE id = ?");
        $stmt->execute([$groupId]);
        return (bool)$stmt->fetchColumn();
    }

    private function isGroupOpen(string $groupId): bool
    {
        $stmt = $this->pdo->prepare("SELECT open FROM groups WHERE id = ?");
        $stmt->execute([$groupId]);
        return (bool)$stmt->fetchColumn();
    }

    private function isUserMember(string $groupId, string $pubkey): bool
    {
        $cacheKey = "{$groupId}:{$pubkey}";
        if (isset($this->memberCache[$cacheKey])) {
            return $this->memberCache[$cacheKey];
        }

        $stmt = $this->pdo->prepare("SELECT COUNT(*) FROM group_members WHERE group_id = ? AND pubkey = ?");
        $stmt->execute([$groupId, $pubkey]);
        $isMember = $stmt->fetchColumn() > 0;
        $this->memberCache[$cacheKey] = $isMember;
        return $isMember;
    }

    private function canUserModerate(string $groupId, string $pubkey, int $actionKind): bool
    {
        // Relay can always moderate
        if ($pubkey === $this->relayPubkey) {
            return true;
        }

        // Check if user is admin
        $stmt = $this->pdo->prepare("SELECT roles FROM group_admins WHERE group_id = ? AND pubkey = ?");
        $stmt->execute([$groupId, $pubkey]);
        $roles = $stmt->fetchColumn();
        
        if (!$roles) {
            return false;
        }

        $roles = json_decode($roles, true);
        
        // Check if user has appropriate role for action
        $requiredRole = $this->getRequiredRoleForAction($actionKind);
        return in_array($requiredRole, $roles);
    }

    private function getRequiredRoleForAction(int $actionKind): string
    {
        $roleMap = [
            9000 => 'admin', // put-user
            9001 => 'admin', // remove-user
            9002 => 'admin', // edit-metadata
            9005 => 'moderator', // delete-event
            9007 => 'admin', // create-group
            9008 => 'admin', // delete-group
            9009 => 'admin', // create-invite
        ];

        return $roleMap[$actionKind] ?? 'admin';
    }

    private function addUserToGroup(string $groupId, string $pubkey): void
    {
        $stmt = $this->pdo->prepare("INSERT IGNORE INTO group_members (group_id, pubkey, joined_at) VALUES (?, ?, ?)");
        $stmt->execute([$groupId, $pubkey, time()]);
        
        // Clear cache
        $cacheKey = "{$groupId}:{$pubkey}";
        unset($this->memberCache[$cacheKey]);
    }

    private function removeUserFromGroup(string $groupId, string $pubkey): void
    {
        $stmt = $this->pdo->prepare("DELETE FROM group_members WHERE group_id = ? AND pubkey = ?");
        $stmt->execute([$groupId, $pubkey]);
        
        // Clear cache
        $cacheKey = "{$groupId}:{$pubkey}";
        unset($this->memberCache[$cacheKey]);
    }

    private function validateInviteCode(string $groupId, string $code): bool
    {
        $stmt = $this->pdo->prepare("SELECT max_uses, used_count, expires_at FROM group_invites WHERE group_id = ? AND code = ?");
        $stmt->execute([$groupId, $code]);
        $invite = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$invite) {
            return false;
        }

        // Check if expired
        if ($invite['expires_at'] && time() > $invite['expires_at']) {
            return false;
        }

        // Check if max uses reached
        if ($invite['used_count'] >= $invite['max_uses']) {
            return false;
        }

        return true;
    }

    private function useInviteCode(string $groupId, string $code): void
    {
        $stmt = $this->pdo->prepare("UPDATE group_invites SET used_count = used_count + 1 WHERE group_id = ? AND code = ?");
        $stmt->execute([$groupId, $code]);
    }

    private function validateTimelineReference(string $groupId, string $ref): bool
    {
        $stmt = $this->pdo->prepare("SELECT COUNT(*) FROM group_timeline_refs WHERE group_id = ? AND ref_hash = ?");
        $stmt->execute([$groupId, $ref]);
        return $stmt->fetchColumn() > 0;
    }

    private function generateModerationEvent(string $groupId, int $kind, array $tags, string $content = ''): void
    {
        $event = new Event(
            $this->generateEventId(),
            $this->relayPubkey,
            time(),
            $kind,
            array_merge([['h', $groupId]], $tags),
            $content,
            $this->signEvent($this->relayPrivateKey, $this->generateEventId())
        );

        // Store the event
        foreach ($this->onStoreEvent as $handler) {
            $handler($event);
        }

        // Broadcast to subscribers
        $this->broadcastEvent($event);
    }

    /**
     * Utility methods
     */
    private function derivePublicKey(string $privateKey): string
    {
        // This is a simplified implementation
        // In production, use proper secp256k1 key derivation
        return hash('sha256', $privateKey);
    }

    private function generateEventId(): string
    {
        return bin2hex(random_bytes(32));
    }

    private function signEvent(string $privateKey, string $eventId): string
    {
        // This is a simplified implementation
        // In production, use proper secp256k1 signing
        return hash_hmac('sha256', $eventId, $privateKey);
    }

    /**
     * Moderation action handlers
     */
    private function handlePutUser(string $groupId, Event $event): array
    {
        $pubkey = $event->getTag('p');
        if (!$pubkey) {
            return [true, "Missing pubkey for put-user action"];
        }

        $roles = $event->getTags('role');
        $this->addUserToGroup($groupId, $pubkey);
        
        if (!empty($roles)) {
            $stmt = $this->pdo->prepare("INSERT INTO group_admins (group_id, pubkey, roles, added_at) VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE roles = ?");
            $rolesJson = json_encode($roles);
            $stmt->execute([$groupId, $pubkey, $rolesJson, time(), $rolesJson]);
        }

        return [false, ''];
    }

    private function handleRemoveUser(string $groupId, Event $event): array
    {
        $pubkey = $event->getTag('p');
        if (!$pubkey) {
            return [true, "Missing pubkey for remove-user action"];
        }

        $this->removeUserFromGroup($groupId, $pubkey);
        
        // Remove from admins if they were an admin
        $stmt = $this->pdo->prepare("DELETE FROM group_admins WHERE group_id = ? AND pubkey = ?");
        $stmt->execute([$groupId, $pubkey]);

        return [false, ''];
    }

    private function handleEditMetadata(string $groupId, Event $event): array
    {
        // Update group metadata based on tags
        $updates = [];
        $params = [$groupId];

        if ($event->getTag('name')) {
            $updates[] = "name = ?";
            $params[] = $event->getTag('name');
        }
        if ($event->getTag('picture')) {
            $updates[] = "picture = ?";
            $params[] = $event->getTag('picture');
        }
        if ($event->getTag('about')) {
            $updates[] = "about = ?";
            $params[] = $event->getTag('about');
        }

        if (!empty($updates)) {
            $params[] = time();
            $sql = "UPDATE groups SET " . implode(', ', $updates) . ", updated_at = ? WHERE id = ?";
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($params);
        }

        return [false, ''];
    }

    private function handleDeleteEvent(string $groupId, Event $event): array
    {
        $eventId = $event->getTag('e');
        if (!$eventId) {
            return [true, "Missing event ID for delete-event action"];
        }

        // Delete the event from storage
        foreach ($this->onDeleteEvent as $handler) {
            $handler($eventId, $event->pubkey);
        }

        return [false, ''];
    }

    private function handleCreateGroup(string $groupId, Event $event): array
    {
        // Create new group
        $stmt = $this->pdo->prepare("INSERT INTO groups (id, name, picture, about, public, open, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([
            $groupId,
            $event->getTag('name') ?? '',
            $event->getTag('picture') ?? '',
            $event->getTag('about') ?? '',
            $event->hasTag('public') ? 1 : 0,
            $event->hasTag('open') ? 1 : 0,
            time(),
            time()
        ]);

        return [false, ''];
    }

    private function handleDeleteGroup(string $groupId, Event $event): array
    {
        // Delete group and all related data
        $stmt = $this->pdo->prepare("DELETE FROM groups WHERE id = ?");
        $stmt->execute([$groupId]);

        return [false, ''];
    }

    private function handleCreateInvite(string $groupId, Event $event): array
    {
        $code = $event->getTag('code') ?? $this->generateInviteCode();
        $maxUses = (int)($event->getTag('max_uses') ?? 1);
        $expiresAt = $event->getTag('expires_at') ? (int)$event->getTag('expires_at') : null;

        $stmt = $this->pdo->prepare("INSERT INTO group_invites (group_id, code, created_by, created_at, expires_at, max_uses) VALUES (?, ?, ?, ?, ?, ?)");
        $stmt->execute([$groupId, $code, $event->pubkey, time(), $expiresAt, $maxUses]);

        return [false, ''];
    }

    private function generateInviteCode(): string
    {
        return bin2hex(random_bytes(8));
    }

    private function updateGroupMetadata(string $groupId, Event $event): void
    {
        $stmt = $this->pdo->prepare("UPDATE groups SET name = ?, picture = ?, about = ?, public = ?, open = ?, updated_at = ? WHERE id = ?");
        $stmt->execute([
            $event->getTag('name') ?? '',
            $event->getTag('picture') ?? '',
            $event->getTag('about') ?? '',
            $event->hasTag('public') ? 1 : 0,
            $event->hasTag('open') ? 1 : 0,
            time(),
            $groupId
        ]);
    }

    private function updateGroupAdmins(string $groupId, Event $event): void
    {
        // Clear existing admins
        $stmt = $this->pdo->prepare("DELETE FROM group_admins WHERE group_id = ?");
        $stmt->execute([$groupId]);

        // Add new admins
        $pTags = $event->getTags('p');
        foreach ($pTags as $index => $pubkey) {
            $roles = [];
            // Get roles for this pubkey (they follow the pubkey in tags)
            for ($i = $index + 1; $i < count($event->tags); $i++) {
                if ($event->tags[$i][0] === 'p' && $event->tags[$i][1] === $pubkey) {
                    // Get roles that follow this pubkey
                    for ($j = $i + 1; $j < count($event->tags) && $event->tags[$j][0] !== 'p'; $j++) {
                        if ($event->tags[$j][0] === 'role') {
                            $roles[] = $event->tags[$j][1];
                        }
                    }
                    break;
                }
            }

            if (!empty($roles)) {
                $stmt = $this->pdo->prepare("INSERT INTO group_admins (group_id, pubkey, roles, added_at) VALUES (?, ?, ?, ?)");
                $stmt->execute([$groupId, $pubkey, json_encode($roles), time()]);
            }
        }
    }

    private function updateGroupMembers(string $groupId, Event $event): void
    {
        // Clear existing members
        $stmt = $this->pdo->prepare("DELETE FROM group_members WHERE group_id = ?");
        $stmt->execute([$groupId]);

        // Add new members
        $pTags = $event->getTags('p');
        foreach ($pTags as $pubkey) {
            $stmt = $this->pdo->prepare("INSERT INTO group_members (group_id, pubkey, joined_at) VALUES (?, ?, ?)");
            $stmt->execute([$groupId, $pubkey, time()]);
        }
    }

    private function updateGroupRoles(string $groupId, Event $event): void
    {
        // Clear existing roles
        $stmt = $this->pdo->prepare("DELETE FROM group_roles WHERE group_id = ?");
        $stmt->execute([$groupId]);

        // Add new roles
        $roleTags = $event->getTags('role');
        foreach ($roleTags as $index => $roleName) {
            $description = $event->tags[$index][2] ?? '';
            $stmt = $this->pdo->prepare("INSERT INTO group_roles (group_id, role_name, description) VALUES (?, ?, ?)");
            $stmt->execute([$groupId, $roleName, $description]);
        }
    }
}

// Example usage
if (php_sapi_name() === 'cli') {
    echo "NIP-29 Relay Implementation\n";
    echo "===========================\n\n";
    
    // Load configuration
    $config = require __DIR__ . '/config.php';
    
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

    // Create NIP-29 relay instance
    $relay = new NIP29Relay($pdo, 'your-relay-private-key-here');
    
    // Configure error reporting based on config
    $relay->configureErrorReporting($config);
    
    // Set up relay info
    $relay->setInfo([
        'name' => 'NIP-29 Groups Relay',
        'description' => 'A relay with full NIP-29 group support',
        'pubkey' => $relay->relayPubkey,
        'contact' => 'admin@groupsrelay.com'
    ]);

    // Add storage handlers
    $mysqlStore = new MySQLStore($pdo, 'events');
    if (!$mysqlStore->init()) {
        die("Failed to initialize MySQL store\n");
    }

    $relay->addStoreEventHandler([$mysqlStore, 'store']);
    $relay->addQueryEventsHandler([$mysqlStore, 'query']);
    $relay->addCountEventsHandler([$mysqlStore, 'count']);
    $relay->addDeleteEventHandler([$mysqlStore, 'delete']);
    $relay->addReplaceEventHandler([$mysqlStore, 'replace']);

    echo "Starting NIP-29 Relay...\n";
    echo "MySQL connection: OK\n";
    echo "NIP-29 tables: Initialized\n";
    echo "Group support: Enabled\n\n";

    // Run the relay
    $relay->run('0.0.0.0', 8080);
} 