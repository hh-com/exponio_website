<?php

namespace App\Ai;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Verschlüsselt Geheimnisse (den Gemini-API-Key) für die Datenbank mit libsodium.
 *
 * Der Schlüssel wird aus APP_SECRET abgeleitet. Wird APP_SECRET geändert, lässt sich
 * der gespeicherte Wert nicht mehr lesen – dann muss der Key im Backend neu eingegeben werden.
 */
final class SecretBox
{
    private const CONTEXT = 'exponio/gemini-api-key';

    public function __construct(
        #[Autowire('%kernel.secret%')]
        private readonly string $appSecret,
    ) {
    }

    public function encrypt(string $plain): string
    {
        $nonce = random_bytes(\SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);

        return base64_encode($nonce.sodium_crypto_secretbox($plain, $nonce, $this->key()));
    }

    /** null, wenn der Wert beschädigt ist oder mit einem anderen APP_SECRET verschlüsselt wurde */
    public function decrypt(string $encoded): ?string
    {
        $raw = base64_decode($encoded, true);
        if (false === $raw || \strlen($raw) <= \SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
            return null;
        }
        $plain = sodium_crypto_secretbox_open(
            substr($raw, \SODIUM_CRYPTO_SECRETBOX_NONCEBYTES),
            substr($raw, 0, \SODIUM_CRYPTO_SECRETBOX_NONCEBYTES),
            $this->key(),
        );

        return false === $plain ? null : $plain;
    }

    private function key(): string
    {
        return sodium_crypto_generichash(self::CONTEXT.'|'.$this->appSecret, '', \SODIUM_CRYPTO_SECRETBOX_KEYBYTES);
    }
}
