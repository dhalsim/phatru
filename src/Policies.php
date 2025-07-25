<?php

namespace Khatru;

class Policies
{
    /**
     * Prevent events with specific kinds
     */
    public static function preventKinds(array $disallowedKinds): callable
    {
        return function (Context $ctx, Event $event) use ($disallowedKinds): array {
            if (in_array($event->kind, $disallowedKinds)) {
                return [true, "Kind {$event->kind} is not allowed"];
            }
            return [false, ''];
        };
    }

    /**
     * Prevent events with too many tags
     */
    public static function preventLargeTags(int $maxTags): callable
    {
        return function (Context $ctx, Event $event) use ($maxTags): array {
            if (count($event->tags) > $maxTags) {
                return [true, "Too many tags: " . count($event->tags) . " (max: {$maxTags})"];
            }
            return [false, ''];
        };
    }

    /**
     * Require authentication for specific kinds
     */
    public static function requireAuthForKind(array $kinds): callable
    {
        return function (Context $ctx, Event $event) use ($kinds): array {
            if (in_array($event->kind, $kinds) && !$ctx->isAuthenticated()) {
                return [true, "Authentication required for kind {$event->kind}"];
            }
            return [false, ''];
        };
    }

    /**
     * Prevent events from specific pubkeys
     */
    public static function blockPubkeys(array $blockedPubkeys): callable
    {
        return function (Context $ctx, Event $event) use ($blockedPubkeys): array {
            if (in_array($event->pubkey, $blockedPubkeys)) {
                return [true, "Pubkey {$event->pubkey} is blocked"];
            }
            return [false, ''];
        };
    }

    /**
     * Only allow events from specific pubkeys
     */
    public static function allowOnlyPubkeys(array $allowedPubkeys): callable
    {
        return function (Context $ctx, Event $event) use ($allowedPubkeys): array {
            if (!in_array($event->pubkey, $allowedPubkeys)) {
                return [true, "Pubkey {$event->pubkey} is not allowed"];
            }
            return [false, ''];
        };
    }

    /**
     * Prevent events with content longer than specified length
     */
    public static function preventLargeContent(int $maxLength): callable
    {
        return function (Context $ctx, Event $event) use ($maxLength): array {
            if (strlen($event->content) > $maxLength) {
                return [true, "Content too long: " . strlen($event->content) . " chars (max: {$maxLength})"];
            }
            return [false, ''];
        };
    }

    /**
     * Prevent events created too far in the future
     */
    public static function preventFutureEvents(int $maxFutureSeconds = 300): callable
    {
        return function (Context $ctx, Event $event) use ($maxFutureSeconds): array {
            $now = time();
            if ($event->created_at > $now + $maxFutureSeconds) {
                return [true, "Event created too far in the future"];
            }
            return [false, ''];
        };
    }

    /**
     * Prevent events created too far in the past
     */
    public static function preventOldEvents(int $maxAgeSeconds = 86400): callable
    {
        return function (Context $ctx, Event $event) use ($maxAgeSeconds): array {
            $now = time();
            if ($event->created_at < $now - $maxAgeSeconds) {
                return [true, "Event too old"];
            }
            return [false, ''];
        };
    }

    /**
     * Require specific tags for certain kinds
     */
    public static function requireTagsForKind(array $kindTagRequirements): callable
    {
        return function (Context $ctx, Event $event) use ($kindTagRequirements): array {
            if (!isset($kindTagRequirements[$event->kind])) {
                return [false, ''];
            }

            $requiredTags = $kindTagRequirements[$event->kind];
            foreach ($requiredTags as $tagName) {
                if (!$event->hasTag($tagName)) {
                    return [true, "Kind {$event->kind} requires tag '{$tagName}'"];
                }
            }
            return [false, ''];
        };
    }

    /**
     * Prevent duplicate events (same id) within a time window
     */
    public static function preventDuplicateEvents(int $windowSeconds = 60): callable
    {
        return function (Context $ctx, Event $event) use ($windowSeconds): array {
            // This would need to be implemented with a cache or database check
            // For now, we'll just return false (no rejection)
            return [false, ''];
        };
    }

    /**
     * Rate limiting based on pubkey
     */
    public static function rateLimitByPubkey(int $maxEventsPerMinute = 60): callable
    {
        return function (Context $ctx, Event $event) use ($maxEventsPerMinute): array {
            // This would need to be implemented with a cache
            // For now, we'll just return false (no rejection)
            return [false, ''];
        };
    }

    /**
     * Validate event signature (basic check)
     */
    public static function validateSignature(): callable
    {
        return function (Context $ctx, Event $event): array {
            // Basic validation - in a real implementation, you'd verify the signature
            if (empty($event->sig) || strlen($event->sig) !== 128) {
                return [true, "Invalid signature"];
            }
            return [false, ''];
        };
    }

    /**
     * Prevent events with empty content for certain kinds
     */
    public static function requireContentForKinds(array $kinds): callable
    {
        return function (Context $ctx, Event $event) use ($kinds): array {
            if (in_array($event->kind, $kinds) && empty(trim($event->content))) {
                return [true, "Kind {$event->kind} requires non-empty content"];
            }
            return [false, ''];
        };
    }

    /**
     * Prevent events with specific tag values
     */
    public static function blockTagValues(string $tagName, array $blockedValues): callable
    {
        return function (Context $ctx, Event $event) use ($tagName, $blockedValues): array {
            $tagValue = $event->getTag($tagName);
            if ($tagValue !== null && in_array($tagValue, $blockedValues)) {
                return [true, "Tag '{$tagName}' value '{$tagValue}' is blocked"];
            }
            return [false, ''];
        };
    }

    /**
     * Validate kind 0 (metadata) events
     */
    public static function validateKind0(): callable
    {
        return function (Context $ctx, Event $event): array {
            if ($event->kind !== 0) {
                return [false, ''];
            }

            // Kind 0 events should have valid JSON content with a name field
            $content = trim($event->content);
            if (empty($content)) {
                return [true, "Kind 0 events must have content"];
            }

            $metadata = json_decode($content, true);
            if ($metadata === null) {
                return [true, "Kind 0 content must be valid JSON"];
            }

            if (!isset($metadata['name']) || empty($metadata['name'])) {
                return [true, "Kind 0 events must have a 'name' field"];
            }

            return [false, ''];
        };
    }
} 