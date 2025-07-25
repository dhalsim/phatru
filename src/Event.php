<?php

namespace Khatru;

class Event
{
    public string $id;
    public string $pubkey;
    public int $created_at;
    public int $kind;
    public array $tags;
    public string $content;
    public string $sig;

    public function __construct(
        string $id = '',
        string $pubkey = '',
        int $created_at = 0,
        int $kind = 0,
        array $tags = [],
        string $content = '',
        string $sig = ''
    ) {
        $this->id = $id;
        $this->pubkey = $pubkey;
        $this->created_at = $created_at;
        $this->kind = $kind;
        $this->tags = $tags;
        $this->content = $content;
        $this->sig = $sig;
    }

    public static function fromArray(array $data): self
    {
        return new self(
            $data['id'] ?? '',
            $data['pubkey'] ?? '',
            $data['created_at'] ?? 0,
            $data['kind'] ?? 0,
            $data['tags'] ?? [],
            $data['content'] ?? '',
            $data['sig'] ?? ''
        );
    }

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'pubkey' => $this->pubkey,
            'created_at' => $this->created_at,
            'kind' => $this->kind,
            'content' => $this->content,
            'tags' => $this->tags,
            'sig' => $this->sig
        ];
    }

    public function getTag(string $name): ?string
    {
        foreach ($this->tags as $tag) {
            if (is_array($tag) && count($tag) > 1 && $tag[0] === $name) {
                return $tag[1];
            }
        }
        return null;
    }

    public function getTags(string $name): array
    {
        $values = [];
        foreach ($this->tags as $tag) {
            if (is_array($tag) && count($tag) > 1 && $tag[0] === $name) {
                $values[] = $tag[1];
            }
        }
        return $values;
    }

    public function hasTag(string $name): bool
    {
        return $this->getTag($name) !== null;
    }

    /**
     * Check if this event is replaceable
     * Replaceable events include:
     * - Kind 0 (metadata events)
     * - Addressable events (kinds 30000-39999)
     */
    public function isReplaceable(): bool
    {
        return $this->kind === 0 || ($this->kind >= 30000 && $this->kind < 40000);
    }

    /**
     * Get the address for addressable events
     * For addressable events, the address is: kind:pubkey:d_tag
     * For kind 0 events, the address is: 0:pubkey
     */
    public function getAddress(): string
    {
        if ($this->kind === 0) {
            return "0:{$this->pubkey}";
        }
        
        if ($this->kind >= 30000 && $this->kind < 40000) {
            $dTag = $this->getTag('d');
            return "{$this->kind}:{$this->pubkey}:" . ($dTag ?? '');
        }
        
        return '';
    }

    /**
     * Check if this event is newer than another event
     */
    public function isNewerThan(Event $other): bool
    {
        return $this->created_at > $other->created_at;
    }
} 