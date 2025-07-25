<?php

namespace Phatru;

use ParagonIE\EasyECC\EasyECC;
use ParagonIE\EasyECC\Exception\InvalidPublicKeyException;
use ParagonIE\EasyECC\Exception\InvalidSignatureException;

class Event
{
    public string $id;
    public string $pubkey;
    public int $created_at;
    public int $kind;
    public array $tags;
    public string $content;
    public string $sig;

    private static ?EasyECC $ecc = null;

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

    /**
     * Get the ECC instance for secp256k1 (Nostr standard)
     */
    private static function getEcc(): EasyECC
    {
        if (self::$ecc === null) {
            // Use secp256k1 curve as specified in NIP-01
            self::$ecc = new EasyECC('K256');
        }
        return self::$ecc;
    }

    /**
     * Generate the event ID according to NIP-01 specification
     * The event ID is the SHA256 hash of the serialized event data
     */
    public function generateId(): string
    {
        $data = [
            0,
            $this->pubkey,
            $this->created_at,
            $this->kind,
            $this->tags,
            $this->content
        ];
        
        $serialized = json_encode($data, JSON_UNESCAPED_SLASHES);
        return hash('sha256', $serialized);
    }

    /**
     * Verify the event signature using Schnorr signature verification
     * @return bool True if signature is valid, false otherwise
     */
    public function verifySignature(): bool
    {
        try {
            // Generate the expected event ID
            $expectedId = $this->generateId();
            
            // If the event ID doesn't match, signature is invalid
            if ($this->id !== $expectedId) {
                return false;
            }

            // Use SchnorrSigner directly from the ECC library (like nostr-php does)
            $schnorrSigner = new \Mdanter\Ecc\Crypto\Signature\SchnorrSigner();
            
            // Verify the signature using the Nostr public key format (hex string)
            return $schnorrSigner->verify($this->pubkey, $this->sig, $expectedId);
            
        } catch (\Exception $e) {
            // Log any errors
            error_log("Signature verification failed: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Sign the event with a private key using Schnorr signature
     * @param string $privateKeyHex The private key in hexadecimal format
     * @return bool True if signing was successful, false otherwise
     */
    public function sign(string $privateKeyHex): bool
    {
        try {
            // Use SchnorrSignature directly from the ECC library (like nostr-php does)
            $schnorrSignature = new \Mdanter\Ecc\Crypto\Signature\SchnorrSignature();
            
            // Set the public key from the private key first
            // For Schnorr, we need to derive the public key from the private key
            $adapter = \Mdanter\Ecc\EccFactory::getAdapter();
            $generator = \Mdanter\Ecc\EccFactory::getSecgCurves()->generator256k1();
            $privateKey = new \Mdanter\Ecc\Crypto\Key\PrivateKey($adapter, $generator, gmp_init($privateKeyHex, 16));
            $publicKeyPoint = $privateKey->getPublicKey()->getPoint();
            $this->pubkey = gmp_strval($publicKeyPoint->getX(), 16);
            
            // Generate the event ID after setting the pubkey
            $this->id = $this->generateId();
            
            // Sign the event ID using Schnorr signature
            $signatureResult = $schnorrSignature->sign($privateKeyHex, $this->id);
            $this->sig = $signatureResult['signature'];
            
            return true;
            
        } catch (\Exception $e) {
            error_log("Event signing failed: " . $e->getMessage());
            return false;
        }
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